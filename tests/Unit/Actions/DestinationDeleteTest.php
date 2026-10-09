<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

/**
 * destination-delete deletes what 5.6.5's
 * destinations::delete() deletes: the destination, its dialplan and
 * the dialplan's details.
 */
#[RunTestsInSeparateProcesses]
class DestinationDeleteTest extends ActionTestCase
{
	private const DESTINATION = 'bbbbbbbb-0000-4000-8000-000000000001';
	private const DIALPLAN = 'cccccccc-0000-4000-8000-000000000001';
	private const OTHER_DIALPLAN = 'cccccccc-0000-4000-8000-000000000003';
	private const OTHER_DOMAIN_UUID = 'aaaaaaaa-0000-4000-8000-000000000002';

	protected function action(): string
	{
		return 'destination-delete';
	}

	protected function tables(): array
	{
		$d1 = self::DOMAIN_UUID;
		$d2 = self::OTHER_DOMAIN_UUID;
		return parent::tables() + array(
			'v_destinations' => array(
				array('destination_uuid' => self::DESTINATION, 'dialplan_uuid' => self::DIALPLAN, 'domain_uuid' => $d1, 'destination_type' => 'inbound', 'destination_number' => '5551234', 'destination_context' => 'public'),
				array('destination_uuid' => 'bbbbbbbb-0000-4000-8000-000000000003', 'dialplan_uuid' => self::OTHER_DIALPLAN, 'domain_uuid' => $d1, 'destination_type' => 'inbound', 'destination_number' => '5551235', 'destination_context' => 'public'),
				array('destination_uuid' => 'bbbbbbbb-0000-4000-8000-000000000002', 'dialplan_uuid' => 'cccccccc-0000-4000-8000-000000000002', 'domain_uuid' => $d1, 'destination_type' => 'outbound', 'destination_number' => '5559999', 'destination_context' => 'tenant1.example.com'),
				array('destination_uuid' => 'bbbbbbbb-0000-4000-8000-000000000020', 'dialplan_uuid' => 'cccccccc-0000-4000-8000-000000000020', 'domain_uuid' => $d2, 'destination_type' => 'inbound', 'destination_number' => '5551234', 'destination_context' => 'public'),
			),
			'v_dialplans' => array(
				array('dialplan_uuid' => self::DIALPLAN, 'domain_uuid' => $d1),
				array('dialplan_uuid' => self::OTHER_DIALPLAN, 'domain_uuid' => $d1),
				array('dialplan_uuid' => 'cccccccc-0000-4000-8000-000000000020', 'domain_uuid' => $d2),
			),
			'v_dialplan_details' => array(
				array('dialplan_detail_uuid' => 'dd000000-0000-4000-8000-000000000001', 'dialplan_uuid' => self::DIALPLAN, 'dialplan_detail_tag' => 'condition'),
				array('dialplan_detail_uuid' => 'dd000000-0000-4000-8000-000000000002', 'dialplan_uuid' => self::DIALPLAN, 'dialplan_detail_tag' => 'action'),
				array('dialplan_detail_uuid' => 'dd000000-0000-4000-8000-000000000003', 'dialplan_uuid' => self::OTHER_DIALPLAN, 'dialplan_detail_tag' => 'action'),
			),
		);
	}

	private function delete(string $number = '5551234'): array
	{
		return $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'number' => $number));
	}

	private function ids(string $table, string $column): array
	{
		return array_column($this->state()['tables'][$table], $column);
	}

	public function testAnswersNoContent(): void
	{
		$this->assertSame(array('code' => 204), $this->delete());
	}

	public function testDeletesTheDestinationItsDialplanAndItsDetails(): void
	{
		$this->delete();

		$this->assertSame(array('bbbbbbbb-0000-4000-8000-000000000003', 'bbbbbbbb-0000-4000-8000-000000000002', 'bbbbbbbb-0000-4000-8000-000000000020'), $this->ids('v_destinations', 'destination_uuid'));
		$this->assertSame(array(self::OTHER_DIALPLAN, 'cccccccc-0000-4000-8000-000000000020'), $this->ids('v_dialplans', 'dialplan_uuid'));
		$this->assertSame(array('dd000000-0000-4000-8000-000000000003'), $this->ids('v_dialplan_details', 'dialplan_detail_uuid'));
	}

	// a destination without a dialplan is still deleted
	public function testDeletesADestinationWithoutADialplan(): void
	{
		\FakeStore::update(function (&$state) {
			$state['tables']['v_destinations'][0]['dialplan_uuid'] = null;
		});

		$this->assertSame(array('code' => 204), $this->delete());
		$this->assertNotContains(self::DESTINATION, $this->ids('v_destinations', 'destination_uuid'));
		$this->assertContains(self::DIALPLAN, $this->ids('v_dialplans', 'dialplan_uuid'));
	}

	// as destinations::delete() does
	public function testClearsTheDialplanCacheOfTheContext(): void
	{
		$this->delete();

		$this->assertSame(array('dialplan:public'), $this->state()['cache_deleted']);
	}

	public function testAnswersNotFoundForANumberThatIsNotAnInboundDestinationOfTheDomain(): void
	{
		foreach (array('5559999', '5550000') as $number) {
			$this->assertSame(array('error' => 'destination not found', 'code' => 404), $this->delete($number), $number);
		}
		$this->assertCount(4, $this->state()['tables']['v_destinations']);
	}

	// the other domain's 5551234 stays
	public function testOnlyDeletesTheDestinationOfTheRequestedDomain(): void
	{
		$this->delete();

		$this->assertContains('bbbbbbbb-0000-4000-8000-000000000020', $this->ids('v_destinations', 'destination_uuid'));
		$this->assertContains('cccccccc-0000-4000-8000-000000000020', $this->ids('v_dialplans', 'dialplan_uuid'));
	}

	public function testASecondDeleteAnswersNotFound(): void
	{
		$this->delete();

		$this->assertSame(array('error' => 'destination not found', 'code' => 404), $this->delete());
	}

	public function testRejectsAnInvalidNumber(): void
	{
		foreach (array(array('5551234'), '555 1234', '') as $number) {
			$this->assertSame(array('error' => 'invalid number', 'code' => 400), $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'number' => $number)), json_encode($number));
		}
	}

	public function testAnswers500WhenTheDatabaseFails(): void
	{
		$this->failSelects();

		$this->assertSame(array('error' => 'database error', 'code' => 500), $this->delete());
	}

	public function testAnswers500WhenTheDeleteFails(): void
	{
		\FakeStore::update(function (&$state) {
			$state['delete_fails'] = true;
		});

		$this->assertSame(array('error' => 'error deleting destination', 'code' => 500), $this->delete());
		$this->assertSame(array(), $this->state()['cache_deleted']);
	}
}
