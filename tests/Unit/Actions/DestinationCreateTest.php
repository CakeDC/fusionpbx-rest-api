<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

#[RunTestsInSeparateProcesses]
class DestinationCreateTest extends ActionTestCase
{
	protected function action(): string
	{
		return 'destination-create';
	}

	protected function tables(): array
	{
		return parent::tables() + array(
			'v_destinations' => array(
				array('destination_uuid' => 'bbbbbbbb-0000-4000-8000-000000000001', 'domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000002', 'destination_number' => '5559999'),
			),
		);
	}

	private function body(array $overrides = array()): array
	{
		return array_merge(array(
			'domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001',
			'number' => '5551234',
			'extension' => '100',
		), $overrides);
	}

	public function testRoutesTheNumberToTheExtensionInItsDomain(): void
	{
		$result = $this->runAction($this->body());

		$tables = $this->state()['tables'];
		$destination = $tables['v_destinations'][1];
		$this->assertSame($destination, $result);
		$this->assertSame('5551234', $destination['destination_number']);
		$this->assertSame('^(5551234)$', $destination['destination_number_regex']);
		$this->assertSame('public', $destination['destination_context']);
		$this->assertSame('[{"destination_app":"transfer","destination_data":"100 XML tenant1.example.com"}]', $destination['destination_actions']);

		$dialplan = $tables['v_dialplans'][0];
		$this->assertSame('public', $dialplan['dialplan_context']);
		$this->assertStringContainsString('<condition field="destination_number" expression="^\+?1?(5551234)$">', $dialplan['dialplan_xml']);
		$this->assertStringContainsString('<action application="transfer" data="100 XML tenant1.example.com"/>', $dialplan['dialplan_xml']);
		$this->assertSame(
			array('^(5551234)$', '100 XML tenant1.example.com'),
			array_column($tables['v_dialplan_details'], 'dialplan_detail_data')
		);
		$this->assertSame(array(), $this->state()['skipped'], 'the declared permissions must cover every saved table');
	}

	public function testRejectsANumberThatIsAlreadyRouted(): void
	{
		$result = $this->runAction($this->body(array('number' => '5559999')));

		$this->assertSame(400, $result['code']);
		$this->assertSame(array(), $this->state()['saved']);
	}

	public function testRejectsAnUnknownDomain(): void
	{
		$result = $this->runAction($this->body(array('domain_uuid' => 'aaaaaaaa-0000-4000-8000-00000000ffff')));

		$this->assertSame(400, $result['code']);
		$this->assertSame(array(), $this->state()['saved']);
	}

	public static function invalidInput(): array
	{
		return array(
			'number matching every call' => array('number', '.*'),
			'number widening the regex' => array('number', '5551234)|(.*'),
			'number breaking the XML' => array('number', '5551234" continue="true'),
			'number with space' => array('number', '555 1234'),
			'extension injecting an action' => array('extension', '100"/><action application="system" data="id'),
			'extension in another domain' => array('extension', '100 XML tenant2.example.com'),
			'array number' => array('number', array('5551234')),
		);
	}

	#[DataProvider('invalidInput')]
	public function testRejectsInputThatCouldAlterTheDialplan(string $field, $value): void
	{
		$result = $this->runAction($this->body(array($field => $value)));

		$this->assertSame(400, $result['code']);
		$this->assertSame(array(), $this->state()['saved']);
	}

	public function testReportsAFailedSave(): void
	{
		$this->failSaves();

		$this->assertSame(array('error' => 'error adding destination'), $this->runAction($this->body()));
		$this->assertCount(1, $this->state()['tables']['v_destinations']);
	}
}
