<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;
use RestApi\Test\Support\CdrSample as S;

#[RunTestsInSeparateProcesses]
class CdrSearchTest extends ActionTestCase
{
	protected function action(): string
	{
		return 'cdr-search';
	}

	protected function tables(): array
	{
		return parent::tables() + array('v_xml_cdr' => S::rows());
	}

	private function search(array $body = array()): array
	{
		return $this->runAction($body + array('domain_uuid' => self::DOMAIN_UUID));
	}

	private function uuids(array $body = array()): array
	{
		return array_column($this->search($body)['data'], 'xml_cdr_uuid');
	}

	public function testReturnsOneRowPerCallNewestFirst(): void
	{
		$result = $this->search();

		$this->assertSame(array(S::A7, S::B6, S::B5B, S::A4, S::A3, S::A2, S::A1), array_column($result['data'], 'xml_cdr_uuid'));
		$this->assertSame(array('page' => 1, 'per_page' => 25, 'total' => 7), $result['pagination']);
	}

	public function testReturnsTheContractFieldsWithTheirTypes(): void
	{
		$a1 = $this->search()['data'][6];

		$this->assertSame(array(
			'xml_cdr_uuid' => S::A1,
			'direction' => 'inbound',
			'caller_id_name' => 'ACME',
			'caller_id_number' => '+15550001111',
			'destination_number' => '5000',
			'start_stamp' => '2026-09-01 09:00:00+00',
			'end_stamp' => '2026-09-01 09:01:00+00',
			'duration' => 95,
			'hangup_cause' => 'NORMAL_CLEARING',
			'hangup_cause_q850' => 16,
			'missed_call' => false,
			'leg' => 'a',
			'bridge_uuid' => S::B1A,
			'originating_leg_uuid' => null,
			'extension_uuid' => null,
			'record_name' => 'c1.wav',
			'record_path' => S::RECORD_PATH,
			'call_center_queue_uuid' => null,
			'cc_queue' => null,
		), $a1);
	}

	public function testRecordingIsNullWhenTheCallWasNotRecorded(): void
	{
		$a3 = $this->search(array('direction' => 'outbound'))['data'][0];

		$this->assertSame(array(S::A3, null, null), array($a3['xml_cdr_uuid'], $a3['record_name'], $a3['record_path']));
	}

	public function testAMissedCallIsTrue(): void
	{
		$this->assertTrue($this->search(array('missed' => true))['data'][0]['missed_call']);
	}

	public function testRingGroupCallIsOneCall(): void
	{
		$result = $this->search(array('start_date' => '2026-09-01', 'end_date' => '2026-09-01'));

		$this->assertSame(array(S::A1), array_column($result['data'], 'xml_cdr_uuid'));
		$this->assertSame(1, $result['pagination']['total']);
	}

	public function testReturnsEveryLegWithoutCallsOnly(): void
	{
		$result = $this->search(array('calls_only' => false, 'sort' => 'start_stamp'));

		$this->assertSame(
			array(S::A1, S::B1A, S::B1B, S::B1C, S::A2, S::B2, S::A3, S::A4, S::B4, S::B5B, S::B5A, S::B6, S::A7, S::B7),
			array_column($result['data'], 'xml_cdr_uuid')
		);
		$this->assertSame(14, $result['pagination']['total']);
		$this->assertSame(S::A1, $result['data'][1]['originating_leg_uuid']);
	}

	public function testSortsOldestFirst(): void
	{
		$this->assertSame(array(S::A1, S::A2, S::A3, S::A4, S::B5B, S::B6, S::A7), $this->uuids(array('sort' => 'start_stamp')));
	}

	public static function filters(): array
	{
		return array(
			'start date' => array(array('start_date' => '2026-09-05'), array(S::A7, S::B6, S::B5B)),
			'end date as a whole day' => array(array('end_date' => '2026-09-02'), array(S::A2, S::A1)),
			'end date-time is inclusive' => array(array('end_date' => '2026-09-04T12:00:00Z'), array(S::A4, S::A3, S::A2, S::A1)),
			'start date-time with an offset' => array(array('start_date' => '2026-09-02T12:00:00+02:00', 'end_date' => '2026-09-03T10:59:59Z'), array(S::A2)),
			'direction inbound' => array(array('direction' => 'inbound'), array(S::A7, S::B5B, S::A4, S::A1)),
			'direction local' => array(array('direction' => 'local'), array(S::B6, S::A2)),
			'direction outbound' => array(array('direction' => 'outbound'), array(S::A3)),
			'missed' => array(array('missed' => true), array(S::A4)),
			'not missed' => array(array('missed' => 'false'), array(S::A7, S::B6, S::B5B, S::A3, S::A2, S::A1)),
			'extension of the a leg' => array(array('extension_uuid' => S::EXT_1002), array(S::A7, S::A2)),
			'extension of a b leg only' => array(array('extension_uuid' => S::EXT_1001), array(S::B6, S::A3, S::A2)),
			'ring group member' => array(array('extension_uuid' => S::EXT_1004), array(S::B5B, S::A1)),
			'several extensions as text' => array(array('extension_uuid' => S::EXT_1001.','.S::EXT_1002), array(S::A7, S::B6, S::A3, S::A2)),
			'several extensions as a list' => array(array('extension_uuid' => array(S::EXT_1003, S::EXT_1005)), array(S::B5B, S::A4, S::A1)),
			'counterparty' => array(array('counterparty' => '1002'), array(S::A7, S::A2)),
			'counterparty is literal' => array(array('counterparty' => '1_0'), array()),
			'percent is literal' => array(array('counterparty' => '%'), array()),
			'escape character is literal' => array(array('counterparty' => '!'), array()),
			'backslash is literal' => array(array('counterparty' => '\\'), array()),
			'own number is not a counterparty' => array(array('counterparty' => '1002', 'own_number' => '1002'), array()),
			'counterparty of the viewer' => array(array('counterparty' => '4443', 'own_number' => '1002'), array(S::A7)),
			'several own numbers' => array(array('counterparty' => '1003', 'own_number' => array('1001', '1003')), array()),
			'other party of several own numbers' => array(array('counterparty' => '9998', 'own_number' => '1001,1003'), array(S::A3)),
			'own number alone filters nothing' => array(array('own_number' => '1002'), array(S::A7, S::B6, S::B5B, S::A4, S::A3, S::A2, S::A1)),
			'direction and extension' => array(array('direction' => 'inbound', 'extension_uuid' => S::EXT_1003), array(S::A4, S::A1)),
			'missed and extension' => array(array('missed' => false, 'extension_uuid' => S::EXT_1003), array(S::A1)),
			'dates, direction and counterparty' => array(array('start_date' => '2026-09-02', 'end_date' => '2026-09-07', 'direction' => 'inbound', 'counterparty' => '+1555'), array(S::A7, S::B5B, S::A4)),
		);
	}

	#[DataProvider('filters')]
	public function testFilters(array $filter, array $expected): void
	{
		$result = $this->search($filter);

		$this->assertSame($expected, array_column($result['data'], 'xml_cdr_uuid'));
		$this->assertSame(count($expected), $result['pagination']['total']);
	}

	public function testFiltersLegsWithoutCallsOnly(): void
	{
		$this->assertSame(array(S::B5B, S::B1C), $this->uuids(array('calls_only' => 'false', 'extension_uuid' => S::EXT_1005, 'counterparty' => '1005')));
	}

	public function testPaginates(): void
	{
		$second = $this->search(array('page' => 2, 'per_page' => 3));
		$last = $this->search(array('page' => '3', 'per_page' => '3'));
		$beyond = $this->search(array('page' => 4, 'per_page' => 3));

		$this->assertSame(array(S::A4, S::A3, S::A2), array_column($second['data'], 'xml_cdr_uuid'));
		$this->assertSame(array('page' => 2, 'per_page' => 3, 'total' => 7), $second['pagination']);
		$this->assertSame(array(S::A1), array_column($last['data'], 'xml_cdr_uuid'));
		$this->assertSame(array(), $beyond['data']);
		$this->assertSame(array('page' => 4, 'per_page' => 3, 'total' => 7), $beyond['pagination']);
	}

	// past the last page the count is enough: no need to sort and skip every row
	public function testOnlyCountsPastTheLastPage(): void
	{
		$this->search(array('page' => 1000000, 'per_page' => 200));

		$queries = $this->state()['queries'];
		$this->assertCount(1, $queries);
		$this->assertStringStartsWith('SELECT COUNT(*)', $queries[0]['sql']);
	}

	// missed_call is boolean on Postgres but text on sqlite and mysql. a bound
	// 'true'/'false' matches both, a boolean literal only the first
	public function testBindsMissedAsText(): void
	{
		$this->search(array('missed' => true));
		$this->search(array('missed' => 0));

		$queries = $this->state()['queries'];
		$this->assertCount(4, $queries);
		$this->assertStringNotContainsString('missed_call = true', $queries[0]['sql']);
		$this->assertStringNotContainsString('missed_call = false', $queries[2]['sql']);
		$this->assertContains('true', $queries[0]['parameters']);
		$this->assertContains('false', $queries[2]['parameters']);
	}

	public function testOnlyReturnsCallsOfTheRequestedDomain(): void
	{
		$other = $this->search(array('domain_uuid' => S::OTHER_DOMAIN_UUID, 'calls_only' => false));

		$this->assertSame(array(S::X2, S::X1), array_column($other['data'], 'xml_cdr_uuid'));
		$this->assertSame(array(S::A3, S::A2), $this->uuids(array('extension_uuid' => S::EXT_1001, 'start_date' => '2026-09-02', 'end_date' => '2026-09-03')));
	}

	public static function invalidParameters(): array
	{
		return array(
			array('start_date', '2026-13-01'),
			array('start_date', 'yesterday'),
			array('start_date', 20260901),
			array('start_date', '2026-09-01T10:00:00+99:99'),
			array('end_date', '2026-09-31'),
			array('end_date', '2026-09-01T25:00:00Z'),
			array('direction', 'sideways'),
			array('extension_uuid', 'not-a-uuid'),
			array('extension_uuid', array()),
			array('extension_uuid', ''),
			array('counterparty', ''),
			array('counterparty', array('1001')),
			array('counterparty', str_repeat('1', 65)),
			array('own_number', array()),
			array('own_number', array(array('1001'))),
			array('own_number', range(1001, 1101)),
			array('extension_uuid', array_map(function ($n) { return sprintf('00000000-0000-4000-8000-%012d', $n); }, range(1, 101))),
			array('missed', 'maybe'),
			array('calls_only', 'yes'),
			array('sort', 'duration'),
			array('sort', array('-start_stamp')),
			array('page', 0),
			array('page', 2.0),
			array('page', '1.5'),
			array('page', 1000001),
			array('per_page', 0),
			array('per_page', 201),
			array('per_page', 'all'),
		);
	}

	#[DataProvider('invalidParameters')]
	public function testRejectsAnInvalidParameter(string $name, $value): void
	{
		$this->assertSame(array('error' => 'invalid '.$name, 'code' => 400), $this->search(array($name => $value)));
	}

	public function testRejectsAnEndBeforeTheStart(): void
	{
		$this->assertSame(array('error' => 'invalid end_date', 'code' => 400), $this->search(array('start_date' => '2026-09-03', 'end_date' => '2026-09-02T23:59:59Z')));
		$this->assertSame(array('error' => 'invalid end_date', 'code' => 400), $this->search(array('start_date' => '2026-09-03T00:00:00Z', 'end_date' => '2026-09-02')));
	}

	// FusionPBX's database class uses PDO prepared statements, which reject a
	// named placeholder used twice unless prepares are emulated
	public function testUsesEveryPlaceholderOnce(): void
	{
		$this->search(array('start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'direction' => 'inbound', 'missed' => false, 'counterparty' => '1', 'own_number' => '1001,1002', 'extension_uuid' => S::EXT_1001.','.S::EXT_1002));

		$queries = $this->state()['queries'];
		$this->assertCount(2, $queries);
		foreach ($queries as $query) {
			preg_match_all('/:\w+/', $query['sql'], $placeholders);
			$this->assertSame(array_unique($placeholders[0]), $placeholders[0], $query['sql']);
		}
	}

	public function testOwnNumberStillMatchesWhenAPartyHasNoNumber(): void
	{
		\FakeStore::update(function (&$state) {
			foreach ($state['tables']['v_xml_cdr'] as $i => $row) {
				if ($row['xml_cdr_uuid'] === S::A7) {
					$state['tables']['v_xml_cdr'][$i]['caller_id_number'] = null;
				}
			}
		});

		$this->assertSame(array(S::A7, S::A2), $this->uuids(array('counterparty' => '1002', 'own_number' => '1001')));
	}

	// FusionPBX's select() returns false on a database error. an empty page
	// would look like a tenant without calls
	public function testAnswers500WhenTheDatabaseFails(): void
	{
		$this->failSelects();

		$this->assertSame(array('error' => 'database error', 'code' => 500), $this->search());
	}

	public static function legsOfOtherCalls(): array
	{
		return array(
			// an "a" leg that names another call's leg as its origin (originate, loopback)
			'origin of an a leg' => array(S::A7, 'originating_leg_uuid', S::A4, S::EXT_1003, array(S::A4, S::A1)),
			// another call's "a" leg bridged to this "a" leg
			'a leg bridged to an a leg' => array(S::A3, 'bridge_uuid', S::A7, S::EXT_1001, array(S::B6, S::A3, S::A2)),
		);
	}

	// with calls_only, a call matches extension_uuid only through the legs
	// cdr-details returns for it, or ZuluCall would list a call whose legs
	// don't include the viewer's extension
	#[DataProvider('legsOfOtherCalls')]
	public function testMatchesExtensionsOnlyThroughTheLegsOfTheCall(string $uuid, string $column, string $value, string $extension, array $expected): void
	{
		\FakeStore::update(function (&$state) use ($uuid, $column, $value) {
			foreach ($state['tables']['v_xml_cdr'] as $i => $row) {
				if ($row['xml_cdr_uuid'] === $uuid) {
					$state['tables']['v_xml_cdr'][$i][$column] = $value;
				}
			}
		});

		$this->assertSame($expected, $this->uuids(array('extension_uuid' => $extension)));
	}
}
