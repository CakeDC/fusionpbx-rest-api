<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

/**
 * ringgroup-details: one ring group, as ringgroup-list returns it.
 */
#[RunTestsInSeparateProcesses]
class RingGroupDetailsTest extends ActionTestCase
{
	private const SALES = '99999999-0000-4000-8000-000000000001';
	private const EMPTY = '99999999-0000-4000-8000-000000000003';
	private const OTHER = '99999999-0000-4000-8000-000000000020';
	private const OTHER_DOMAIN_UUID = 'aaaaaaaa-0000-4000-8000-000000000002';

	protected function action(): string
	{
		return 'ringgroup-details';
	}

	private static function destination(string $uuid, string $ring_group, string $number, string $delay): array
	{
		return array('ring_group_destination_uuid' => $uuid, 'ring_group_uuid' => $ring_group, 'destination_number' => $number, 'destination_delay' => $delay);
	}

	protected function tables(): array
	{
		return parent::tables() + array(
			'v_ring_groups' => array(
				array('ring_group_uuid' => self::SALES, 'domain_uuid' => self::DOMAIN_UUID, 'ring_group_name' => 'Sales', 'ring_group_extension' => '600', 'ring_group_strategy' => 'sequence', 'ring_group_enabled' => 'false', 'ring_group_description' => 'secret notes'),
				array('ring_group_uuid' => self::EMPTY, 'domain_uuid' => self::DOMAIN_UUID, 'ring_group_name' => 'New', 'ring_group_extension' => '602', 'ring_group_strategy' => 'enterprise', 'ring_group_enabled' => 'true'),
				array('ring_group_uuid' => self::OTHER, 'domain_uuid' => self::OTHER_DOMAIN_UUID, 'ring_group_name' => 'Sales', 'ring_group_extension' => '600', 'ring_group_strategy' => 'simultaneous', 'ring_group_enabled' => 'true'),
			),
			'v_ring_group_destinations' => array(
				self::destination('d0000000-0000-4000-8000-000000000001', self::SALES, '103', '10'),
				self::destination('d0000000-0000-4000-8000-000000000002', self::SALES, '102', '5'),
				self::destination('d0000000-0000-4000-8000-000000000003', self::SALES, '101', '5'),
				self::destination('d0000000-0000-4000-8000-000000000020', self::OTHER, '200', '0'),
			),
		);
	}

	private function details($ring_group_uuid): array
	{
		return $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'ring_group_uuid' => $ring_group_uuid));
	}

	// a disabled ring group is found too, with its destinations in FusionPBX's order
	public function testReturnsTheRingGroupWithItsDestinations(): void
	{
		$this->assertSame(
			array('ring_group_uuid' => self::SALES, 'domain_uuid' => self::DOMAIN_UUID, 'name' => 'Sales', 'extension' => '600', 'strategy' => 'sequence', 'destinations' => array(array('number' => '101'), array('number' => '102'), array('number' => '103'))),
			$this->details(self::SALES)
		);
	}

	public function testReturnsARingGroupWithoutDestinations(): void
	{
		$this->assertSame(array(), $this->details(self::EMPTY)['destinations']);
	}

	public function testAnswersNotFoundForARingGroupOfAnotherDomainOrAnUnknownOne(): void
	{
		foreach (array(self::OTHER, '99999999-0000-4000-8000-00000000ffff') as $ring_group_uuid) {
			$this->assertSame(array('error' => 'ring group not found', 'code' => 404), $this->details($ring_group_uuid), $ring_group_uuid);
		}
	}

	public function testRejectsAMalformedRingGroupUuid(): void
	{
		foreach (array('600', array(self::SALES), 600) as $ring_group_uuid) {
			$this->assertSame(array('error' => 'invalid ring_group_uuid', 'code' => 400), $this->details($ring_group_uuid), json_encode($ring_group_uuid));
		}
	}

	// FusionPBX's select() returns false on a database error, which must not
	// look like a missing ring group
	public function testAnswers500WhenTheDatabaseFails(): void
	{
		$this->failSelects();

		$this->assertSame(array('error' => 'database error', 'code' => 500), $this->details(self::SALES));
	}
}
