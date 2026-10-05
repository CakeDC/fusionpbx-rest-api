<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

#[RunTestsInSeparateProcesses]
class RingGroupCreateTest extends ActionTestCase
{
	protected function action(): string
	{
		return 'ringgroup-create';
	}

	protected function tables(): array
	{
		return parent::tables() + array(
			'v_ring_groups' => array(
				array('ring_group_uuid' => 'cccccccc-0000-4000-8000-000000000001', 'domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'ring_group_extension' => '300'),
			),
		);
	}

	private function body(array $overrides = array()): array
	{
		return array_merge(array(
			'domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001',
			'name' => 'Sales',
			'extension' => '200',
			'destinations' => '[{"number": "100"}, {"number": "101"}]',
			'strategy' => 'simultaneous',
		), $overrides);
	}

	public function testCreatesTheRingGroupWithItsDestinationsAndDialplan(): void
	{
		$result = $this->runAction($this->body());

		$tables = $this->state()['tables'];
		$ring_group = $tables['v_ring_groups'][1];
		$this->assertSame('200', $ring_group['ring_group_extension']);
		$this->assertSame('simultaneous', $ring_group['ring_group_strategy']);
		$this->assertSame('tenant1.example.com', $ring_group['ring_group_context']);
		$this->assertSame(array('100', '101'), array_column($tables['v_ring_group_destinations'], 'destination_number'));
		$this->assertSame('tenant1.example.com', $tables['v_dialplans'][0]['dialplan_context']);
		$this->assertStringContainsString('<condition field="destination_number" expression="^200$">', $tables['v_dialplans'][0]['dialplan_xml']);
		$this->assertSame(array_merge(REST_API_RING_GROUP_FIELDS, array('ring_group_destinations')), array_keys($result));
		$this->assertSame(array('100', '101'), array_column($result['ring_group_destinations'], 'destination_number'));
		$this->assertSame(REST_API_RING_GROUP_DESTINATION_FIELDS, array_keys($result['ring_group_destinations'][0]));
		$this->assertSame(array(), $this->state()['skipped'], 'the declared permissions must cover every saved table');
	}

	public function testEscapesTheNameInTheDialplanXml(): void
	{
		$this->runAction($this->body(array('name' => 'Sales & "Support" <1>')));

		$this->assertStringStartsWith(
			'<extension name="Sales &amp; &quot;Support&quot; &lt;1&gt;" continue=""',
			$this->state()['tables']['v_dialplans'][0]['dialplan_xml']
		);
	}

	public function testMatchesFeatureCodeExtensionsLiterally(): void
	{
		$this->runAction($this->body(array('extension' => '*200')));

		$this->assertStringContainsString('expression="^\*200$"', $this->state()['tables']['v_dialplans'][0]['dialplan_xml']);
	}

	public function testRejectsAnExtensionThatAlreadyHasARingGroup(): void
	{
		$this->runAction($this->body(array('extension' => '300')));

		$this->assertSame(array(), $this->state()['saved']);
	}

	public static function invalidInput(): array
	{
		return array(
			'unknown strategy' => array('strategy', 'all-at-once'),
			'extension injecting XML' => array('extension', '200$"><action application="system" data="id'),
			'extension regex' => array('extension', '.*'),
			'destinations not JSON' => array('destinations', 'not json'),
			'destinations not a list' => array('destinations', '{"number": "100"}'),
			'empty destinations' => array('destinations', '[]'),
			'destination with unsafe number' => array('destinations', '[{"number": "100;id"}]'),
			'destination without number' => array('destinations', '[{"extension": "100"}]'),
			'name not a string' => array('name', array('Sales')),
		);
	}

	#[DataProvider('invalidInput')]
	public function testRejectsInvalidInputWithoutSaving(string $field, $value): void
	{
		$result = $this->runAction($this->body(array($field => $value)));

		$this->assertSame(400, $result['code']);
		$this->assertSame(array(), $this->state()['saved']);
	}

	public function testReportsAFailedSave(): void
	{
		$this->failSaves();

		$this->assertSame(array('error' => 'error adding ring group'), $this->runAction($this->body()));
		$this->assertCount(1, $this->state()['tables']['v_ring_groups']);
	}

	public function testRejectsAnUnknownDomain(): void
	{
		$result = $this->runAction($this->body(array('domain_uuid' => 'aaaaaaaa-0000-4000-8000-00000000ffff')));

		$this->assertSame(array('error' => 'domain not found'), $result);
		$this->assertSame(array(), $this->state()['saved']);
	}
}
