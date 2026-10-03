<?php
namespace RestApi\Test\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AuthTest extends TestCase
{
	protected function setUp(): void
	{
		require_once PLUGIN_DIR.'/lib/auth.php';
	}

	public static function booleans(): array
	{
		// PostgreSQL booleans reach PHP as true/false or 't'/'f'; FusionPBX writes 'true'/'false'
		return array(
			'php true' => array(true, true),
			'text true' => array('true', true),
			'pgsql t' => array('t', true),
			'one' => array(1, true),
			'text one' => array('1', true),
			'php false' => array(false, false),
			'text false' => array('false', false),
			'pgsql f' => array('f', false),
			'zero' => array(0, false),
			'null' => array(null, false),
			'empty' => array('', false),
			'yes' => array('yes', false),
		);
	}

	#[DataProvider('booleans')]
	public function testReadsDatabaseBooleans($value, bool $expected): void
	{
		$this->assertSame($expected, rest_api_is_true($value));
	}
}
