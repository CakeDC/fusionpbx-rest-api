<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

/**
 * ringgroup-list.
 */
#[RunTestsInSeparateProcesses]
class RingGroupListTest extends ActionTestCase
{
	private const SALES = '99999999-0000-4000-8000-000000000001';
	private const SUPPORT = '99999999-0000-4000-8000-000000000002';
	private const EMPTY = '99999999-0000-4000-8000-000000000003';
	private const OTHER = '99999999-0000-4000-8000-000000000020';
	private const OTHER_DOMAIN_UUID = 'aaaaaaaa-0000-4000-8000-000000000002';

	protected function action(): string
	{
		return 'ringgroup-list';
	}

	private static function destination(string $uuid, string $ring_group, string $number, string $delay): array
	{
		return array('ring_group_destination_uuid' => $uuid, 'ring_group_uuid' => $ring_group, 'destination_number' => $number, 'destination_delay' => $delay, 'destination_timeout' => '30', 'destination_enabled' => 'true');
	}

	protected function tables(): array
	{
		$d1 = self::DOMAIN_UUID;
		return parent::tables() + array(
			'v_ring_groups' => array(
				array('ring_group_uuid' => self::SUPPORT, 'domain_uuid' => $d1, 'ring_group_name' => 'Support', 'ring_group_extension' => '601', 'ring_group_strategy' => 'sequence', 'ring_group_enabled' => 'false', 'ring_group_context' => 'tenant1.example.com', 'dialplan_uuid' => 'cccccccc-0000-4000-8000-000000000601'),
				array('ring_group_uuid' => self::SALES, 'domain_uuid' => $d1, 'ring_group_name' => 'Sales', 'ring_group_extension' => '600', 'ring_group_strategy' => 'simultaneous', 'ring_group_enabled' => 'true', 'ring_group_context' => 'tenant1.example.com', 'dialplan_uuid' => 'cccccccc-0000-4000-8000-000000000600'),
				array('ring_group_uuid' => self::EMPTY, 'domain_uuid' => $d1, 'ring_group_name' => 'New', 'ring_group_extension' => '602', 'ring_group_strategy' => 'enterprise', 'ring_group_enabled' => 'true', 'ring_group_context' => 'tenant1.example.com', 'dialplan_uuid' => null),
				array('ring_group_uuid' => self::OTHER, 'domain_uuid' => self::OTHER_DOMAIN_UUID, 'ring_group_name' => 'Sales', 'ring_group_extension' => '600', 'ring_group_strategy' => 'simultaneous', 'ring_group_enabled' => 'true', 'ring_group_context' => 'tenant2.example.com', 'dialplan_uuid' => null),
			),
			'v_ring_group_destinations' => array(
				// rung in FusionPBX's order: by delay (a number), then by number
				self::destination('d0000000-0000-4000-8000-000000000001', self::SALES, '103', '10'),
				self::destination('d0000000-0000-4000-8000-000000000002', self::SALES, '102', '5'),
				self::destination('d0000000-0000-4000-8000-000000000003', self::SALES, '101', '5'),
				self::destination('d0000000-0000-4000-8000-000000000004', self::SUPPORT, '5551234', '0'),
				self::destination('d0000000-0000-4000-8000-000000000020', self::OTHER, '200', '0'),
			),
		);
	}

	private function list(array $body = array(), string $domain_uuid = self::DOMAIN_UUID): array
	{
		return $this->runAction($body + array('domain_uuid' => $domain_uuid));
	}

	public function testListsTheRingGroupsOfTheDomainByExtension(): void
	{
		$this->assertSame(array(
			'data' => array(
				array('ring_group_uuid' => self::SALES, 'domain_uuid' => self::DOMAIN_UUID, 'name' => 'Sales', 'extension' => '600', 'strategy' => 'simultaneous', 'destinations' => array(array('number' => '101'), array('number' => '102'), array('number' => '103'))),
				array('ring_group_uuid' => self::SUPPORT, 'domain_uuid' => self::DOMAIN_UUID, 'name' => 'Support', 'extension' => '601', 'strategy' => 'sequence', 'destinations' => array(array('number' => '5551234'))),
				array('ring_group_uuid' => self::EMPTY, 'domain_uuid' => self::DOMAIN_UUID, 'name' => 'New', 'extension' => '602', 'strategy' => 'enterprise', 'destinations' => array()),
			),
			'pagination' => array('page' => 1, 'per_page' => 25, 'total' => 3),
		), $this->list());
	}

	// FusionPBX's ring group script only rings enabled destinations
	public function testLeavesDisabledDestinationsOut(): void
	{
		\FakeStore::update(function (&$state) {
			$state['tables']['v_ring_group_destinations'][0]['destination_enabled'] = 'false';
		});

		$this->assertSame(array(array('number' => '101'), array('number' => '102')), $this->list()['data'][0]['destinations']);
	}

	public function testOnlyListsRingGroupsOfTheRequestedDomain(): void
	{
		$data = $this->list(array(), self::OTHER_DOMAIN_UUID)['data'];

		$this->assertSame(array(self::OTHER), array_column($data, 'ring_group_uuid'));
		$this->assertSame(array(array('number' => '200')), $data[0]['destinations']);
	}

	// one query for the destinations of a page, not one per ring group
	public function testFetchesTheDestinationsOfAPageTogether(): void
	{
		$this->list();

		$this->assertCount(3, $this->state()['queries']);
	}

	public function testPaginates(): void
	{
		$second = $this->list(array('page' => 2, 'per_page' => 2));
		$beyond = $this->list(array('page' => '3', 'per_page' => '2'));

		$this->assertSame(array(self::EMPTY), array_column($second['data'], 'ring_group_uuid'));
		$this->assertSame(array('page' => 2, 'per_page' => 2, 'total' => 3), $second['pagination']);
		$this->assertSame(array(), $beyond['data']);
		$this->assertSame(array('page' => 3, 'per_page' => 2, 'total' => 3), $beyond['pagination']);
	}

	// past the last page the count is enough
	public function testOnlyCountsPastTheLastPage(): void
	{
		$this->list(array('page' => 1000000, 'per_page' => 200));

		$queries = $this->state()['queries'];
		$this->assertCount(1, $queries);
		$this->assertStringStartsWith('SELECT COUNT(*)', $queries[0]['sql']);
	}

	public static function invalidParameters(): array
	{
		return array(
			array('page', 0),
			array('page', 1000001),
			array('per_page', 201),
			array('per_page', 'all'),
		);
	}

	#[DataProvider('invalidParameters')]
	public function testRejectsAnInvalidParameter(string $name, $value): void
	{
		$this->assertSame(array('error' => 'invalid '.$name, 'code' => 400), $this->list(array($name => $value)));
	}

	// FusionPBX's select() returns false on a database error. an empty list
	// would look like a domain without ring groups
	public function testAnswers500WhenTheDatabaseFails(): void
	{
		$this->failSelects();

		$this->assertSame(array('error' => 'database error', 'code' => 500), $this->list());
	}
}
