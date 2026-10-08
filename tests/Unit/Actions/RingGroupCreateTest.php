<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

/**
 * ringgroup-create: 201 with the ring group as ringgroup-details returns it,
 * 409 when the extension already has one.
 */
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
			'destinations' => array(array('number' => '100'), array('number' => '101')),
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
		$this->assertSame(array(), $this->state()['skipped'], 'the declared permissions must cover every saved table');
	}

	public function testAnswersCreatedWithTheRingGroupAsRingGroupDetailsReturnsIt(): void
	{
		$result = $this->runAction($this->body());

		$this->assertSame(array(
			'ring_group_uuid' => $this->state()['tables']['v_ring_groups'][1]['ring_group_uuid'],
			'domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001',
			'name' => 'Sales',
			'extension' => '200',
			'strategy' => 'simultaneous',
			'destinations' => array(array('number' => '100'), array('number' => '101')),
			'code' => 201,
		), $result);
	}

	// JSON objects reach the action as objects
	public function testAcceptsDestinationsAsObjects(): void
	{
		$result = $this->runAction($this->body(array('destinations' => array((object)array('number' => '100')))));

		$this->assertSame(array(array('number' => '100')), $result['destinations']);
	}

	// older clients send the list as a JSON-encoded string
	public function testStillAcceptsDestinationsAsAJsonString(): void
	{
		$result = $this->runAction($this->body(array('destinations' => '[{"number": "100"}, {"number": "101"}]')));

		$this->assertSame(201, $result['code']);
		$this->assertSame(array(array('number' => '100'), array('number' => '101')), $result['destinations']);
	}

	public function testANumberGivenTwiceRingsOnce(): void
	{
		$this->runAction($this->body(array('destinations' => array(array('number' => '100'), array('number' => '100')))));

		$this->assertSame(array('100'), array_column($this->state()['tables']['v_ring_group_destinations'], 'destination_number'));
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

	public function testAnswersConflictForAnExtensionThatAlreadyHasARingGroup(): void
	{
		$this->assertSame(array('error' => 'ring group already exists', 'code' => 409), $this->runAction($this->body(array('extension' => '300'))));
		$this->assertSame(array(), $this->state()['saved']);
	}

	public static function invalidInput(): array
	{
		return array(
			'unknown strategy' => array('strategy', 'all-at-once', 'strategy'),
			'extension injecting XML' => array('extension', '200$"><action application="system" data="id', 'extension'),
			'extension regex' => array('extension', '.*', 'extension'),
			'destinations not JSON' => array('destinations', 'not json', 'destinations'),
			'destinations not a list' => array('destinations', '{"number": "100"}', 'destinations'),
			'empty destinations' => array('destinations', array(), 'destinations'),
			'empty destinations string' => array('destinations', '[]', 'destinations'),
			'destination with unsafe number' => array('destinations', array(array('number' => '100;id')), 'destinations'),
			'destination without number' => array('destinations', array(array('extension' => '100')), 'destinations'),
			'name not a string' => array('name', array('Sales'), 'name'),
			'name with a newline' => array('name', "Sales\nTeam", 'name'),
		);
	}

	#[DataProvider('invalidInput')]
	public function testRejectsInvalidInputWithoutSaving(string $field, $value, string $name): void
	{
		$this->assertSame(array('error' => 'invalid '.$name, 'code' => 400), $this->runAction($this->body(array($field => $value))));
		$this->assertSame(array(), $this->state()['saved']);
	}

	// select() returns false on a database error, which must not pass for
	// "no such ring group" and create a duplicate
	public function testAnswers500WhenTheDatabaseFails(): void
	{
		$this->failSelects();

		$this->assertSame(array('error' => 'database error', 'code' => 500), $this->runAction($this->body()));
		$this->assertSame(array(), $this->state()['saved']);
	}

	public function testReportsAFailedSave(): void
	{
		$this->failSaves();

		$this->assertSame(array('error' => 'error adding ring group', 'code' => 500), $this->runAction($this->body()));
		$this->assertCount(1, $this->state()['tables']['v_ring_groups']);
	}

	public function testAnswersNotFoundForAnUnknownDomain(): void
	{
		$result = $this->runAction($this->body(array('domain_uuid' => 'aaaaaaaa-0000-4000-8000-00000000ffff')));

		$this->assertSame(array('error' => 'domain not found', 'code' => 404), $result);
		$this->assertSame(array(), $this->state()['saved']);
	}
}
