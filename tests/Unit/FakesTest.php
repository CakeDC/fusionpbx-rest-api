<?php
namespace RestApi\Test\Unit;

use FakeStore;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The FusionPBX stand-ins must behave like FusionPBX 5.6.5 where the plugin
 * depends on it, or the tests can't catch the bugs that matter on a real server.
 */
#[RunTestsInSeparateProcesses]
class FakesTest extends TestCase
{
	private const DOMAIN = 'aaaaaaaa-0000-4000-8000-000000000001';
	private const USER = 'dddddddd-0000-4000-8000-000000000001';

	protected function setUp(): void
	{
		require_once FUSIONPBX_FAKES_DIR.'/resources/fakes.php';
		$_SESSION = array();
		FakeStore::reset(array(
			'v_user_groups' => array(
				array('domain_uuid' => self::DOMAIN, 'user_uuid' => self::USER, 'group_name' => 'api'),
			),
			'v_group_permissions' => array(
				array('domain_uuid' => null, 'group_name' => 'api', 'permission_name' => 'extension_add', 'permission_assigned' => 'true'),
				array('domain_uuid' => null, 'group_name' => 'api', 'permission_name' => 'voicemail_add', 'permission_assigned' => 'false'),
				array('domain_uuid' => null, 'group_name' => 'other', 'permission_name' => 'domain_select', 'permission_assigned' => 'true'),
			),
		));
	}

	public function testPermissionsComeFromTheUsersGroups(): void
	{
		$permissions = \permissions::new(null, self::DOMAIN, self::USER);

		$this->assertTrue($permissions->exists('extension_add'));
		$this->assertFalse($permissions->exists('voicemail_add'));
		$this->assertFalse($permissions->exists('domain_select'));
	}

	public function testPermissionsAreFixedByTheFirstCheck(): void
	{
		$this->assertFalse(permission_exists('extension_add'));

		$_SESSION['permissions']['extension_add'] = true;

		$this->assertFalse(permission_exists('extension_add'));
		$this->assertFalse(\permissions::new(null, self::DOMAIN, self::USER)->exists('extension_add'));
	}

	public function testSessionPermissionsWinOverGroups(): void
	{
		$_SESSION['permissions'] = array('xml_cdr_view' => true);

		$this->assertTrue(permission_exists('xml_cdr_view'));
		$this->assertFalse(permission_exists('extension_add'));
	}

	public function testPermissionsSessionCopiesThemIntoTheSession(): void
	{
		\permissions::new(null, self::DOMAIN, self::USER)->session();

		$this->assertSame(array('extension_add' => true), $_SESSION['permissions']);
	}

	public function testGroupsSessionFeedsIfGroup(): void
	{
		(new \groups(null, self::DOMAIN, self::USER))->session();

		$this->assertTrue(if_group('api'));
		$this->assertFalse(if_group('superadmin'));
	}

	public function testSaveSkipsTablesWithoutPermissionAndRecordsTheUser(): void
	{
		$_SESSION['permissions'] = array('extension_add' => true);
		$database = \database::new(array('user_uuid' => self::USER));

		$saved = $database->save(array(
			'extensions' => array(array('extension' => '100')),
			'voicemails' => array(array('voicemail_id' => '100')),
		));

		$state = FakeStore::read();
		$this->assertTrue($saved);
		$this->assertSame(array(array('extension' => '100', 'insert_user' => self::USER)), $state['tables']['v_extensions']);
		$this->assertArrayNotHasKey('v_voicemails', $state['tables']);
		$this->assertSame(array('voicemails'), $state['skipped']);
	}

	public function testNewDatabaseTakesTheUserFromTheSession(): void
	{
		$_SESSION['user_uuid'] = self::USER;

		$this->assertSame(self::USER, (new \database)->user_uuid);
	}

	public function testDatabaseNewSharesOneInstanceAndUpdatesItsUser(): void
	{
		$first = \database::new();
		$second = \database::new(array('user_uuid' => self::USER, 'domain_uuid' => self::DOMAIN));

		$this->assertSame($first, $second);
		$this->assertSame(array(self::USER, self::DOMAIN), array($first->user_uuid, $first->domain_uuid));
	}

	public function testSelectJoinsTablesByAlias(): void
	{
		FakeStore::update(function (&$state) {
			$state['tables']['rest_api_keys'] = array(
				array('key_uuid' => 'k1', 'user_uuid' => self::USER),
				array('key_uuid' => 'k2', 'user_uuid' => 'dddddddd-0000-4000-8000-00000000dead'),
			);
			$state['tables']['v_users'] = array(array('user_uuid' => self::USER, 'username' => 'api', 'domain_uuid' => self::DOMAIN));
			$state['tables']['v_domains'] = array(array('domain_uuid' => self::DOMAIN, 'domain_name' => 'tenant1.example.com'));
		});
		$sql = "SELECT k.key_uuid, u.user_uuid, u.username, d.domain_name FROM rest_api_keys k"
			." LEFT JOIN v_users u ON u.user_uuid = k.user_uuid LEFT JOIN v_domains d ON d.domain_uuid = u.domain_uuid"
			." WHERE k.key_uuid = :key_uuid";

		$this->assertSame(
			array('key_uuid' => 'k1', 'user_uuid' => self::USER, 'username' => 'api', 'domain_name' => 'tenant1.example.com'),
			fake_sql($sql, array('key_uuid' => 'k1'), 'row')
		);
		$this->assertSame(
			array('key_uuid' => 'k2', 'user_uuid' => null, 'username' => null, 'domain_name' => null),
			fake_sql($sql, array('key_uuid' => 'k2'), 'row')
		);
	}

	public function testSelectOrdersByEveryListedColumn(): void
	{
		FakeStore::update(function (&$state) {
			$state['tables']['v_users'] = array(
				array('username' => 'b', 'domain_uuid' => '2'),
				array('username' => 'a', 'domain_uuid' => '2'),
				array('username' => 'c', 'domain_uuid' => '1'),
			);
		});

		$rows = fake_sql("SELECT u.username FROM v_users u ORDER BY u.domain_uuid, u.username DESC", null);

		$this->assertSame(array('c', 'b', 'a'), array_column($rows, 'username'));
	}
}
