<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

/**
 * destination-list. FusionPBX stores a route as transfer actions
 * ("<number> XML <context>"); the response's
 * destination_type and target come from what that number is in the domain.
 */
#[RunTestsInSeparateProcesses]
class DestinationListTest extends ActionTestCase
{
	private const RING_GROUP = '99999999-0000-4000-8000-000000000001';
	private const IVR = '88888888-0000-4000-8000-000000000001';
	private const OTHER_DOMAIN_UUID = 'aaaaaaaa-0000-4000-8000-000000000002';

	protected function action(): string
	{
		return 'destination-list';
	}

	private static function route(string $uuid, string $number, $actions, array $extra = array()): array
	{
		return $extra + array(
			'destination_uuid' => $uuid,
			'domain_uuid' => self::DOMAIN_UUID,
			'destination_type' => 'inbound',
			'destination_number' => $number,
			'destination_actions' => is_array($actions) ? json_encode($actions) : $actions,
			'destination_enabled' => 'true',
			'destination_context' => 'public',
		);
	}

	private static function transfer(string $number, string $context = 'tenant1.example.com'): array
	{
		return array(array('destination_app' => 'transfer', 'destination_data' => $number.' XML '.$context));
	}

	protected function tables(): array
	{
		$d1 = self::DOMAIN_UUID;
		$d2 = self::OTHER_DOMAIN_UUID;
		return parent::tables() + array(
			'v_destinations' => array(
				self::route('bbbbbbbb-0000-4000-8000-000000000006', '5550006', self::transfer('1100')),
				self::route('bbbbbbbb-0000-4000-8000-000000000001', '5550001', self::transfer('100')),
				self::route('bbbbbbbb-0000-4000-8000-000000000002', '5550002', self::transfer('200')),
				self::route('bbbbbbbb-0000-4000-8000-000000000003', '5550003', self::transfer('300')),
				self::route('bbbbbbbb-0000-4000-8000-000000000004', '5550004', self::transfer('*99100'), array('destination_enabled' => 'false')),
				// a time condition (not one of the four types), a number only another
				// domain owns, two actions, no action
				self::route('bbbbbbbb-0000-4000-8000-000000000005', '5550005', array(array('destination_app' => 'transfer', 'destination_data' => '400 XML tenant1.example.com'))),
				self::route('bbbbbbbb-0000-4000-8000-000000000007', '5550007', self::transfer('999')),
				self::route('bbbbbbbb-0000-4000-8000-000000000008', '5550008', array_merge(self::transfer('100'), self::transfer('200'))),
				self::route('bbbbbbbb-0000-4000-8000-000000000009', '5550009', null),
				// outbound routes and other domains are not inbound destinations of this domain
				self::route('bbbbbbbb-0000-4000-8000-000000000010', '5550010', self::transfer('100'), array('destination_type' => 'outbound')),
				self::route('bbbbbbbb-0000-4000-8000-000000000020', '5550020', self::transfer('100', 'tenant2.example.com'), array('domain_uuid' => $d2)),
			),
			'v_extensions' => array(
				array('extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000100', 'domain_uuid' => $d1, 'extension' => '100', 'number_alias' => '1100'),
				// the other domain's 200 is not this domain's
				array('extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000200', 'domain_uuid' => $d2, 'extension' => '999', 'number_alias' => ''),
			),
			'v_ring_groups' => array(
				array('ring_group_uuid' => self::RING_GROUP, 'domain_uuid' => $d1, 'ring_group_extension' => '200'),
				array('ring_group_uuid' => '99999999-0000-4000-8000-000000000002', 'domain_uuid' => $d2, 'ring_group_extension' => '999'),
			),
			'v_ivr_menus' => array(
				array('ivr_menu_uuid' => self::IVR, 'domain_uuid' => $d1, 'ivr_menu_extension' => '300'),
			),
			'v_voicemails' => array(
				array('voicemail_uuid' => 'c0000000-0000-4000-8000-000000000100', 'domain_uuid' => $d1, 'voicemail_id' => '100'),
			),
		);
	}

	private function list(array $body = array(), string $domain_uuid = self::DOMAIN_UUID): array
	{
		return $this->runAction($body + array('domain_uuid' => $domain_uuid));
	}

	private static function destination(string $number, $type, $target, bool $enabled = true): array
	{
		return array('domain_uuid' => self::DOMAIN_UUID, 'number' => $number, 'destination_type' => $type, 'target' => $target, 'enabled' => $enabled);
	}

	public function testListsTheInboundDestinationsOfTheDomainByNumber(): void
	{
		$this->assertSame(array(
			'data' => array(
				self::destination('5550001', 'extension', '100'),
				self::destination('5550002', 'ring_group', self::RING_GROUP),
				self::destination('5550003', 'ivr', self::IVR),
				self::destination('5550004', 'voicemail', '100', false),
				self::destination('5550005', null, null),
				self::destination('5550006', 'extension', '1100'),
				self::destination('5550007', null, null),
				self::destination('5550008', null, null),
				self::destination('5550009', null, null),
			),
			'pagination' => array('page' => 1, 'per_page' => 25, 'total' => 9),
		), $this->list());
	}

	// a number is only looked up among the records of the destination's domain
	public function testDoesNotResolveNumbersOfAnotherDomain(): void
	{
		$data = $this->list(array(), self::OTHER_DOMAIN_UUID)['data'];

		$this->assertSame(array('5550020'), array_column($data, 'number'));
		$this->assertSame(array(null), array_column($data, 'destination_type'));
	}

	// Postgres returns destination_enabled as a PHP boolean
	public function testReadsABooleanEnabled(): void
	{
		\FakeStore::update(function (&$state) {
			$state['tables']['v_destinations'][1]['destination_enabled'] = true;
			$state['tables']['v_destinations'][4]['destination_enabled'] = false;
		});

		$this->assertSame(array(true, true, true, false), array_slice(array_column($this->list()['data'], 'enabled'), 0, 4));
	}

	public function testPaginates(): void
	{
		$second = $this->list(array('page' => 2, 'per_page' => 2));
		$beyond = $this->list(array('page' => '6', 'per_page' => '2'));

		$this->assertSame(array(self::destination('5550003', 'ivr', self::IVR), self::destination('5550004', 'voicemail', '100', false)), $second['data']);
		$this->assertSame(array('page' => 2, 'per_page' => 2, 'total' => 9), $second['pagination']);
		$this->assertSame(array(), $beyond['data']);
		$this->assertSame(array('page' => 6, 'per_page' => 2, 'total' => 9), $beyond['pagination']);
	}

	// past the last page the count is enough
	public function testOnlyCountsPastTheLastPage(): void
	{
		$this->list(array('page' => 1000000, 'per_page' => 200));

		$queries = $this->state()['queries'];
		$this->assertCount(1, $queries);
		$this->assertStringStartsWith('SELECT COUNT(*)', $queries[0]['sql']);
	}

	// the numbers of a page are looked up together, not one query per row
	public function testLooksTheNumbersOfAPageUpTogether(): void
	{
		$this->list();

		$this->assertLessThanOrEqual(6, count($this->state()['queries']));
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
	// would look like a domain without numbers
	public function testAnswers500WhenTheDatabaseFails(): void
	{
		$this->failSelects();

		$this->assertSame(array('error' => 'database error', 'code' => 500), $this->list());
	}
}
