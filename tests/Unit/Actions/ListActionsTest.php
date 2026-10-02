<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

/**
 * Read-only lookups: extension-list, cdr-list, domain-details, destination-details.
 */
#[RunTestsInSeparateProcesses]
class ListActionsTest extends ActionTestCase
{
	private string $action = 'extension-list';

	protected function action(): string
	{
		return $this->action;
	}

	protected function setUp(): void
	{
		// the action is chosen per test, so load it in load() instead
	}

	private function load(string $action): void
	{
		$this->action = $action;
		parent::setUp();
	}

	protected function tables(): array
	{
		return parent::tables() + array(
			'v_extensions' => array(
				array('extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000100', 'domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'extension' => '100', 'emergency_caller_id_number' => '5551000', 'password' => 'sip-secret'),
				array('extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000200', 'domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000002', 'extension' => '200', 'emergency_caller_id_number' => '5552000', 'password' => 'sip-secret'),
			),
			'v_xml_cdr' => array(
				array('xml_cdr_uuid' => 'cdr-1', 'domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'end_stamp' => '2026-10-01 10:00:00', 'destination_number' => '100'),
				array('xml_cdr_uuid' => 'cdr-2', 'domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'end_stamp' => '2026-10-02 10:00:00', 'destination_number' => '101'),
				array('xml_cdr_uuid' => 'cdr-3', 'domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000002', 'end_stamp' => '2026-10-02 11:00:00', 'destination_number' => '200'),
			),
			'v_destinations' => array(
				array('destination_uuid' => 'bbbbbbbb-0000-4000-8000-000000000001', 'destination_number' => '5551234', 'destination_actions' => '[{"destination_app":"transfer","destination_data":"100 XML tenant1.example.com"}]'),
			),
		);
	}

	public function testExtensionListOnlyReturnsTheDomainsExtensions(): void
	{
		$this->load('extension-list');

		$this->assertSame(
			array(array('extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000100', 'extension' => '100', 'emergency_caller_id_number' => '5551000')),
			$this->runAction(array('domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001'))
		);
	}

	public function testCdrListReturnsTheDomainsRecordsNewestFirst(): void
	{
		$this->load('cdr-list');

		$result = $this->runAction(array('domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001'));

		$this->assertSame(array('cdr-2', 'cdr-1'), array_column($result, 'xml_cdr_uuid'));
	}

	public function testDomainDetailsFindsADomainByName(): void
	{
		$this->load('domain-details');

		$result = $this->runAction(array('domain_name' => 'tenant2.example.com'));

		$this->assertSame('aaaaaaaa-0000-4000-8000-000000000002', $result['domain_uuid']);
	}

	public function testDomainDetailsFindsADomainByUuid(): void
	{
		$this->load('domain-details');

		$result = $this->runAction(array('domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001'));

		$this->assertSame('tenant1.example.com', $result['domain_name']);
	}

	public function testDomainDetailsNeedsAUuidOrAName(): void
	{
		$this->load('domain-details');

		$this->assertSame(400, $this->runAction(array())['code']);
	}

	public function testDomainDetailsReportsAnUnknownDomain(): void
	{
		$this->load('domain-details');

		$this->assertSame(404, $this->runAction(array('domain_name' => 'unknown.example.com'))['code']);
	}

	public function testDestinationDetailsDecodesTheDestinationActions(): void
	{
		$this->load('destination-details');

		$result = $this->runAction(array('number' => '5551234'));

		$this->assertEquals(array((object)array('destination_app' => 'transfer', 'destination_data' => '100 XML tenant1.example.com')), $result['destination_actions']);
	}

	public function testDestinationDetailsReportsAnUnknownNumber(): void
	{
		$this->load('destination-details');

		$this->assertSame(404, $this->runAction(array('number' => '5550000'))['code']);
	}
}
