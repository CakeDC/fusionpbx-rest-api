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
}
