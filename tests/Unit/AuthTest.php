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

	private const NOW = 1791028800; // 2026-10-03 12:00:00 UTC

	private static function key(array $overrides = array()): array
	{
		return array_merge(array(
			'key_secret' => password_hash('s3cret', PASSWORD_DEFAULT, array('cost' => 4)),
			'key_enabled' => 'true',
			'expires' => null,
			'user_uuid' => 'dddddddd-0000-4000-8000-000000000001',
			'username' => 'api_billing',
			'user_enabled' => 'true',
			'domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001',
			'domain_name' => 'tenant1.example.com',
			'domain_enabled' => 'true',
		), $overrides);
	}

	public static function usableKeys(): array
	{
		return array(
			'never expires' => array(array()),
			'expires later (pgsql timestamptz)' => array(array('expires' => '2026-10-03 12:00:01+00')),
			'pgsql booleans' => array(array('key_enabled' => true, 'user_enabled' => 't', 'domain_enabled' => true)),
		);
	}

	#[DataProvider('usableKeys')]
	public function testAcceptsAnEnabledKeyOfAnEnabledUser(array $overrides): void
	{
		$this->assertNull(rest_api_key_rejection(self::key($overrides), 's3cret', self::NOW));
	}

	public static function unusableKeys(): array
	{
		return array(
			'wrong secret' => array(array(), 'wrong', 'invalid secret'),
			'key disabled' => array(array('key_enabled' => 'false'), 's3cret', 'key disabled'),
			'key disabled (pgsql)' => array(array('key_enabled' => 'f'), 's3cret', 'key disabled'),
			'key from before the upgrade' => array(array('key_enabled' => null, 'user_uuid' => null), 's3cret', 'key disabled'),
			'expired' => array(array('expires' => '2026-10-03 11:59:59+00'), 's3cret', 'key expired'),
			'expires right now' => array(array('expires' => '2026-10-03 12:00:00+00'), 's3cret', 'key expired'),
			'unreadable expiry' => array(array('expires' => 'soon'), 's3cret', 'key expired'),
			'no user, or user deleted' => array(array('user_uuid' => null, 'username' => null, 'user_enabled' => null, 'domain_uuid' => null, 'domain_name' => null, 'domain_enabled' => null), 's3cret', 'key has no user'),
			'user disabled' => array(array('user_enabled' => false), 's3cret', 'user disabled'),
			'domain disabled' => array(array('domain_enabled' => 'false'), 's3cret', 'domain disabled'),
			'domain deleted' => array(array('domain_uuid' => null, 'domain_name' => null, 'domain_enabled' => null), 's3cret', 'domain disabled'),
		);
	}

	#[DataProvider('unusableKeys')]
	public function testRejectsKeysThatCannotActAsAnEnabledUser(array $overrides, string $secret, string $reason): void
	{
		$this->assertSame($reason, rest_api_key_rejection(self::key($overrides), $secret, self::NOW));
	}

	public function testRejectsAnUnknownKey(): void
	{
		$this->assertSame('unknown key', rest_api_key_rejection(false, 's3cret', self::NOW));
	}
}
