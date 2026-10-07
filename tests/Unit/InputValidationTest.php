<?php
namespace RestApi\Test\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InputValidationTest extends TestCase
{
	public static function setUpBeforeClass(): void
	{
		require_once PLUGIN_DIR.'/lib/input_validation.php';
	}

	public function testEnsureParametersAcceptsCompleteBody(): void
	{
		$this->assertFalse(ensure_parameters((object)array('a' => '1', 'b' => '2'), array('a', 'b')));
	}

	public function testEnsureParametersListsEveryMissingParameter(): void
	{
		$result = ensure_parameters((object)array('b' => ''), array('a', 'b', 'c'));

		$this->assertSame(array('error' => 'missing required parameter(s)', 'missing_parameters' => array('a', 'b', 'c')), $result);
	}

	public static function validDialNumbers(): array
	{
		return array(
			'extension' => array('100'),
			'feature code' => array('*9664'),
			'e164' => array('+15551234567'),
			'hash' => array('#21'),
			'integer' => array(1000),
		);
	}

	#[DataProvider('validDialNumbers')]
	public function testIsDialNumberAcceptsDialableValues($value): void
	{
		$this->assertTrue(is_dial_number($value));
	}

	public static function invalidDialNumbers(): array
	{
		return array(
			'empty' => array(''),
			'plus only' => array('+'),
			'space' => array('555 1234'),
			'trailing newline' => array("100\n"),
			'event socket command' => array("101\n\napi system id"),
			'channel variable' => array('100,origination_caller_id_number=1'),
			'braces' => array('{x}'),
			'quote' => array("100'"),
			'domain suffix' => array('100/other.example.com'),
			'letters' => array('abc'),
			'regex' => array('.*'),
			'null' => array(null),
			'array' => array(array('100')),
			'float' => array(1.5),
		);
	}

	#[DataProvider('invalidDialNumbers')]
	public function testIsDialNumberRejectsUnsafeValues($value): void
	{
		$this->assertFalse(is_dial_number($value));
	}
	public function testParseListAcceptsTextAndArrays(): void
	{
		$this->assertSame(array('1001', '1002'), rest_api_parse_list(' 1001, 1002,,1001 '));
		$this->assertSame(array('1001', '1002'), rest_api_parse_list(array('1001', 1002)));
	}

	public function testParseListRejectsEmptyAndNestedValues(): void
	{
		foreach (array('', ' , ', array(), array(array('1001')), 1001, null, true) as $value) {
			$this->assertFalse(rest_api_parse_list($value), json_encode($value));
		}
	}

	// each item becomes a placeholder, several times over, and Postgres
	// takes at most 65535 per query
	public function testParseListRejectsMoreThanAHundredItems(): void
	{
		$this->assertCount(100, rest_api_parse_list(range(1, 100)));
		$this->assertFalse(rest_api_parse_list(implode(',', range(1, 101))));
	}

	public function testParseBool(): void
	{
		foreach (array(true, 1, '1', 'true') as $value) {
			$this->assertTrue(rest_api_parse_bool($value), json_encode($value));
		}
		foreach (array(false, 0, '0', 'false') as $value) {
			$this->assertFalse(rest_api_parse_bool($value), json_encode($value));
		}
		foreach (array('yes', 'TRUE', 2, '', null, array()) as $value) {
			$this->assertNull(rest_api_parse_bool($value), json_encode($value));
		}
	}

	public function testParseInt(): void
	{
		$this->assertSame(3, rest_api_parse_int('3', 1, 200));
		$this->assertSame(200, rest_api_parse_int(200, 1, 200));
		foreach (array(0, 201, '1.5', '-1', ' 3', 2.0, '', null, '9999999999999999999') as $value) {
			$this->assertFalse(rest_api_parse_int($value, 1, 200), json_encode($value));
		}
	}

	// page and per_page of every paginated action (cdr-search, domain-list, user-list)
	public function testParsePaginationDefaultsAndAcceptsDigitStrings(): void
	{
		$this->assertSame(array(1, 25), rest_api_parse_pagination((object)array()));
		$this->assertSame(array(1000000, 200), rest_api_parse_pagination((object)array('page' => '1000000', 'per_page' => 200)));
	}

	public function testParsePaginationRejectsInvalidValues(): void
	{
		foreach (array(0, '1.5', 1000001, 2.0) as $page) {
			$this->assertSame(array('error' => 'invalid page', 'code' => 400), rest_api_parse_pagination((object)array('page' => $page)), json_encode($page));
		}
		foreach (array(0, 201, 'all') as $per_page) {
			$this->assertSame(array('error' => 'invalid per_page', 'code' => 400), rest_api_parse_pagination((object)array('per_page' => $per_page)), json_encode($per_page));
		}
	}

	public static function timestamps(): array
	{
		return array(
			'date' => array('2026-09-01', '2026-09-01 00:00:00+00:00', true),
			'utc' => array('2026-09-01T10:00:00Z', '2026-09-01 10:00:00+00:00', false),
			'no offset is utc' => array('2026-09-01 10:00', '2026-09-01 10:00:00+00:00', false),
			'offset' => array('2026-09-01T12:00:00+02:00', '2026-09-01 10:00:00+00:00', false),
			'fraction' => array('2026-09-01T10:00:00.5-0100', '2026-09-01 11:00:00+00:00', false),
		);
	}

	#[DataProvider('timestamps')]
	public function testParseTimestampReturnsUtc(string $value, string $utc, bool $date_only): void
	{
		$parsed = rest_api_parse_timestamp($value);

		$this->assertSame(array($utc, $date_only), array($parsed[0]->format('Y-m-d H:i:sP'), $parsed[1]));
	}

	public function testParseTimestampRejectsInvalidDates(): void
	{
		foreach (array('2026-10-03T10:00:00+99:99', '2026-10-03T10:00:00-2400', '2026-02-30', '2026-13-01', '2026-09-01T24:00:00Z', '2026-09-01T10:60Z', 'yesterday', '1 September 2026', '', 20260901, null) as $value) {
			$this->assertFalse(rest_api_parse_timestamp($value), json_encode($value));
		}
	}

	public function testLikeEscapeMakesWildcardsLiteral(): void
	{
		$this->assertSame('100!%!_!!\\', rest_api_like_escape('100%_!\\'));
	}
}
