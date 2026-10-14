<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

/**
 * ringgroup-delete deletes what 5.6.5's ring_groups::delete() deletes: the
 * ring group, its users, its destinations, its dialplan and the dialplan's
 * details.
 */
#[RunTestsInSeparateProcesses]
class RingGroupDeleteTest extends ActionTestCase
{
	private const SALES = '99999999-0000-4000-8000-000000000001';
	private const SUPPORT = '99999999-0000-4000-8000-000000000002';
	private const OTHER = '99999999-0000-4000-8000-000000000020';
	private const DIALPLAN = 'cccccccc-0000-4000-8000-000000000600';
	private const SUPPORT_DIALPLAN = 'cccccccc-0000-4000-8000-000000000601';
	private const OTHER_DOMAIN_UUID = 'aaaaaaaa-0000-4000-8000-000000000002';

	protected function action(): string
	{
		return 'ringgroup-delete';
	}

	protected function tables(): array
	{
		$d1 = self::DOMAIN_UUID;
		$d2 = self::OTHER_DOMAIN_UUID;
		return parent::tables() + array(
			'v_ring_groups' => array(
				array('ring_group_uuid' => self::SALES, 'domain_uuid' => $d1, 'ring_group_extension' => '600', 'ring_group_context' => 'tenant1.example.com', 'dialplan_uuid' => self::DIALPLAN),
				array('ring_group_uuid' => self::SUPPORT, 'domain_uuid' => $d1, 'ring_group_extension' => '601', 'ring_group_context' => 'tenant1.example.com', 'dialplan_uuid' => self::SUPPORT_DIALPLAN),
				array('ring_group_uuid' => self::OTHER, 'domain_uuid' => $d2, 'ring_group_extension' => '600', 'ring_group_context' => 'tenant2.example.com', 'dialplan_uuid' => null),
			),
			'v_ring_group_users' => array(
				array('ring_group_user_uuid' => 'a0000000-0000-4000-8000-000000000001', 'domain_uuid' => $d1, 'ring_group_uuid' => self::SALES, 'user_uuid' => 'dddddddd-0000-4000-8000-000000000010'),
				array('ring_group_user_uuid' => 'a0000000-0000-4000-8000-000000000002', 'domain_uuid' => $d1, 'ring_group_uuid' => self::SUPPORT, 'user_uuid' => 'dddddddd-0000-4000-8000-000000000010'),
			),
			'v_ring_group_destinations' => array(
				array('ring_group_destination_uuid' => 'd0000000-0000-4000-8000-000000000001', 'domain_uuid' => $d1, 'ring_group_uuid' => self::SALES, 'destination_number' => '101'),
				array('ring_group_destination_uuid' => 'd0000000-0000-4000-8000-000000000002', 'domain_uuid' => $d1, 'ring_group_uuid' => self::SALES, 'destination_number' => '102'),
				array('ring_group_destination_uuid' => 'd0000000-0000-4000-8000-000000000003', 'domain_uuid' => $d1, 'ring_group_uuid' => self::SUPPORT, 'destination_number' => '103'),
				array('ring_group_destination_uuid' => 'd0000000-0000-4000-8000-000000000020', 'domain_uuid' => $d2, 'ring_group_uuid' => self::OTHER, 'destination_number' => '200'),
			),
			'v_dialplans' => array(
				array('dialplan_uuid' => self::DIALPLAN, 'domain_uuid' => $d1),
				array('dialplan_uuid' => self::SUPPORT_DIALPLAN, 'domain_uuid' => $d1),
			),
			'v_dialplan_details' => array(
				array('dialplan_detail_uuid' => 'dd000000-0000-4000-8000-000000000001', 'dialplan_uuid' => self::DIALPLAN),
				array('dialplan_detail_uuid' => 'dd000000-0000-4000-8000-000000000002', 'dialplan_uuid' => self::SUPPORT_DIALPLAN),
			),
		);
	}

	private function delete($ring_group_uuid = self::SALES): array
	{
		return $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'ring_group_uuid' => $ring_group_uuid));
	}

	private function ids(string $table, string $column): array
	{
		return array_column($this->state()['tables'][$table], $column);
	}

	public function testAnswersNoContent(): void
	{
		$this->assertSame(array('code' => 204), $this->delete());
	}

	public function testDeletesTheRingGroupAndWhatBelongsToIt(): void
	{
		$this->delete();

		$this->assertSame(array(self::SUPPORT, self::OTHER), $this->ids('v_ring_groups', 'ring_group_uuid'));
		$this->assertSame(array('a0000000-0000-4000-8000-000000000002'), $this->ids('v_ring_group_users', 'ring_group_user_uuid'));
		$this->assertSame(array('d0000000-0000-4000-8000-000000000003', 'd0000000-0000-4000-8000-000000000020'), $this->ids('v_ring_group_destinations', 'ring_group_destination_uuid'));
		$this->assertSame(array(self::SUPPORT_DIALPLAN), $this->ids('v_dialplans', 'dialplan_uuid'));
		$this->assertSame(array('dd000000-0000-4000-8000-000000000002'), $this->ids('v_dialplan_details', 'dialplan_detail_uuid'));
		$this->assertSame(array(), $this->state()['skipped'], 'the declared permissions must cover every table deleted from');
	}

	public function testDeletesARingGroupWithoutADialplan(): void
	{
		\FakeStore::update(function (&$state) {
			$state['tables']['v_ring_groups'][0]['dialplan_uuid'] = null;
		});

		$this->assertSame(array('code' => 204), $this->delete());
		$this->assertNotContains(self::SALES, $this->ids('v_ring_groups', 'ring_group_uuid'));
		$this->assertSame(array(self::DIALPLAN, self::SUPPORT_DIALPLAN), $this->ids('v_dialplans', 'dialplan_uuid'));
	}

	// as ring_groups::delete() does
	public function testClearsTheDialplanCacheOfTheContext(): void
	{
		$this->delete();

		$this->assertSame(array('dialplan:tenant1.example.com'), $this->state()['cache_deleted']);
	}

	public function testAnswersNotFoundForARingGroupOfAnotherDomainOrAnUnknownOne(): void
	{
		foreach (array(self::OTHER, '99999999-0000-4000-8000-00000000ffff') as $ring_group_uuid) {
			$this->assertSame(array('error' => 'ring group not found', 'code' => 404), $this->delete($ring_group_uuid), $ring_group_uuid);
		}
		$this->assertCount(3, $this->state()['tables']['v_ring_groups']);
		$this->assertCount(4, $this->state()['tables']['v_ring_group_destinations']);
	}

	public function testASecondDeleteAnswersNotFound(): void
	{
		$this->delete();

		$this->assertSame(array('error' => 'ring group not found', 'code' => 404), $this->delete());
	}

	public function testRejectsAMalformedRingGroupUuid(): void
	{
		foreach (array('600', '', array(self::SALES), 600) as $ring_group_uuid) {
			$this->assertSame(array('error' => 'invalid ring_group_uuid', 'code' => 400), $this->delete($ring_group_uuid), json_encode($ring_group_uuid));
		}
	}

	// FusionPBX stores uuids in lower case
	public function testFindsTheRingGroupByAnUpperCaseUuid(): void
	{
		$this->assertSame(array('code' => 204), $this->delete(strtoupper(self::SALES)));
		$this->assertNotContains(self::SALES, $this->ids('v_ring_groups', 'ring_group_uuid'));
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

		$this->assertSame(array('error' => 'error deleting ring group', 'code' => 500), $this->delete());
		$this->assertSame(array(), $this->state()['cache_deleted']);
	}
}
