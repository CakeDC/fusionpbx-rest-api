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

	// 5.6.5's save() updates a record whose <singular>_uuid exists, only with
	// <singular>_edit, and changes only the fields it is given
	public function testSaveUpdatesAnExistingRecordWithTheEditPermission(): void
	{
		FakeStore::update(function (&$state) {
			$state['tables']['v_extensions'] = array(
				array('extension_uuid' => 'e1', 'extension' => '100', 'enabled' => 'true'),
				array('extension_uuid' => 'e2', 'extension' => '200', 'enabled' => 'true'),
			);
		});
		$_SESSION['permissions'] = array('extension_edit' => true);
		$database = \database::new(array('user_uuid' => self::USER));

		$saved = $database->save(array('extensions' => array(array('extension_uuid' => 'e1', 'enabled' => 'false'))));

		$this->assertTrue($saved);
		$this->assertSame(array(
			array('extension_uuid' => 'e1', 'extension' => '100', 'enabled' => 'false', 'update_user' => self::USER),
			array('extension_uuid' => 'e2', 'extension' => '200', 'enabled' => 'true'),
		), FakeStore::read()['tables']['v_extensions']);
	}

	public function testSaveSkipsAnUpdateWithoutTheEditPermission(): void
	{
		FakeStore::update(function (&$state) {
			$state['tables']['v_extensions'] = array(array('extension_uuid' => 'e1', 'enabled' => 'true'));
		});
		$_SESSION['permissions'] = array('extension_add' => true);

		$this->assertTrue((new \database)->save(array('extensions' => array(array('extension_uuid' => 'e1', 'enabled' => 'false')))));

		$state = FakeStore::read();
		$this->assertSame(array(array('extension_uuid' => 'e1', 'enabled' => 'true')), $state['tables']['v_extensions']);
		$this->assertSame(array('extensions'), $state['skipped']);
	}

	// 5.6.5's delete() removes the rows matching every given field, only with
	// <singular>_delete; without it nothing happens and it still returns true
	public function testDeleteRemovesMatchingRowsWithTheDeletePermission(): void
	{
		$links = array(
			array('extension_user_uuid' => 'l1', 'extension_uuid' => 'e1', 'user_uuid' => 'u1'),
			array('extension_user_uuid' => 'l2', 'extension_uuid' => 'e1', 'user_uuid' => 'u2'),
			array('extension_user_uuid' => 'l3', 'extension_uuid' => 'e2', 'user_uuid' => 'u1'),
		);
		FakeStore::update(function (&$state) use ($links) {
			$state['tables']['v_extension_users'] = $links;
		});
		$_SESSION['permissions'] = array('extension_user_delete' => true);

		$this->assertTrue((new \database)->delete(array('extension_users' => array(array('extension_uuid' => 'e1')))));

		$this->assertSame(array($links[2]), FakeStore::read()['tables']['v_extension_users']);
	}

	public function testDeleteSkipsTablesWithoutTheDeletePermission(): void
	{
		$links = array(array('extension_user_uuid' => 'l1', 'extension_uuid' => 'e1'));
		FakeStore::update(function (&$state) use ($links) {
			$state['tables']['v_extension_users'] = $links;
		});
		$_SESSION['permissions'] = array('extension_user_add' => true);

		$this->assertTrue((new \database)->delete(array('extension_users' => array(array('extension_uuid' => 'e1')))));

		$state = FakeStore::read();
		$this->assertSame($links, $state['tables']['v_extension_users']);
		$this->assertSame(array('extension_users'), $state['skipped']);
	}

	// FusionPBX's settings: default settings by category and subcategory
	public function testSettingsReturnTheStoredValueOrTheDefault(): void
	{
		FakeStore::update(function (&$state) {
			$state['settings']['switch']['voicemail'] = '/var/lib/freeswitch/storage/voicemail';
		});
		$settings = new \settings(array('domain_uuid' => self::DOMAIN));

		$this->assertSame('/var/lib/freeswitch/storage/voicemail', $settings->get('switch', 'voicemail'));
		$this->assertSame('file', $settings->get('cache', 'method', 'file'));
	}

	// FusionPBX caches the directory entry of each extension (directory:<ext>@<context>)
	public function testCacheDeleteIsRecorded(): void
	{
		(new \cache)->delete('directory:100@tenant1.example.com');

		$this->assertSame(array('directory:100@tenant1.example.com'), FakeStore::read()['cache_deleted']);
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

	// DISTINCT compares the selected columns and runs before LIMIT/OFFSET
	public function testSelectDistinctDropsDuplicateRowsBeforePaging(): void
	{
		FakeStore::update(function (&$state) {
			$state['tables']['v_users'] = array(
				array('username' => 'a', 'domain_uuid' => '1'),
				array('username' => 'a', 'domain_uuid' => '2'),
				array('username' => 'b', 'domain_uuid' => '1'),
			);
		});

		$rows = fake_sql("SELECT DISTINCT u.username FROM v_users u ORDER BY u.username LIMIT 1 OFFSET 1", null);

		$this->assertSame(array('b'), array_column($rows, 'username'));
	}

	// Postgres: "for SELECT DISTINCT, ORDER BY expressions must appear in select list"
	public function testSelectDistinctRejectsOrderingByAnUnselectedColumn(): void
	{
		$this->expectException(\RuntimeException::class);

		fake_sql("SELECT DISTINCT u.username FROM v_users u ORDER BY u.domain_uuid", null);
	}
	private function seedCalls(): void
	{
		FakeStore::update(function (&$state) {
			$state['tables']['v_xml_cdr'] = array(
				array('xml_cdr_uuid' => 'a1', 'leg' => 'a', 'number' => '100%', 'start_stamp' => '2026-09-01 10:00:00+00', 'bridge_uuid' => 'b1', 'originating_leg_uuid' => null),
				array('xml_cdr_uuid' => 'b1', 'leg' => 'b', 'number' => '1001', 'start_stamp' => '2026-09-01 12:00:00+02', 'bridge_uuid' => null, 'originating_leg_uuid' => 'a1'),
				array('xml_cdr_uuid' => 'b2', 'leg' => 'b', 'number' => null, 'start_stamp' => '2026-09-02 10:00:00+00', 'originating_leg_uuid' => null),
			);
		});
	}

	private function select(string $where, array $parameters = array()): array
	{
		return array_column(fake_sql("SELECT c.xml_cdr_uuid FROM v_xml_cdr c WHERE ".$where." ORDER BY c.xml_cdr_uuid", $parameters), 'xml_cdr_uuid');
	}

	public function testWhereCombinesAndOrNotWithParentheses(): void
	{
		$this->seedCalls();

		$this->assertSame(array('a1', 'b2'), $this->select("c.leg = 'a' OR (c.leg = 'b' AND NOT c.xml_cdr_uuid = :uuid)", array('uuid' => 'b1')));
	}

	// like SQL, a comparison with NULL is unknown, and so is its negation
	public function testWhereTreatsNullAsUnknown(): void
	{
		$this->seedCalls();

		$this->assertSame(array('a1', 'b1'), $this->select("NOT c.number = 'x'"));
		$this->assertSame(array('b2'), $this->select("c.number IS NULL"));
		$this->assertSame(array('a1', 'b1'), $this->select("c.number IS NOT NULL"));
		$this->assertSame(array('a1', 'b1', 'b2'), $this->select("c.missing_column IS NULL"));
	}

	public function testWhereSupportsInAndNotIn(): void
	{
		$this->seedCalls();

		$this->assertSame(array('a1', 'b1'), $this->select("c.xml_cdr_uuid IN (:x, :y)", array('x' => 'a1', 'y' => 'b1')));
		$this->assertSame(array('b1'), $this->select("c.number NOT IN ('100%')"));
	}

	public function testWhereLikeHonoursWildcardsAndEscapes(): void
	{
		$this->seedCalls();

		$this->assertSame(array('a1', 'b1'), $this->select("c.number LIKE '10_%'"));
		$this->assertSame(array('a1'), $this->select("c.number LIKE :pattern ESCAPE '!'", array('pattern' => '%0!%')));
		$this->assertSame(array(), $this->select("c.number LIKE '%!_%' ESCAPE '!'"));
	}

	// PDO before PHP 8.4 reads '\' as an escaped quote, so the literal goes on
	// and swallows the placeholders after it: SQLSTATE[HY093]
	public function testPlaceholdersAfterABackslashLiteralAreNotSeen(): void
	{
		$this->seedCalls();

		$this->expectExceptionMessage('SQL parameter mismatch (missing: ; unused: b)');

		$this->select("(c.number LIKE :a ESCAPE '\\' OR c.number LIKE :b ESCAPE '\\')", array('a' => '1%', 'b' => '1%'));
	}

	public function testWhereComparesTimestampsAsInstants(): void
	{
		$this->seedCalls();

		$this->assertSame(array('a1', 'b1'), $this->select("c.start_stamp = :t", array('t' => '2026-09-01 10:00:00+00:00')));
		$this->assertSame(array('b2'), $this->select("c.start_stamp > :t", array('t' => '2026-09-01T10:00:00Z')));
	}

	public function testWhereRunsCorrelatedExistsSubqueries(): void
	{
		$this->seedCalls();

		$this->assertSame(array('a1'), $this->select("EXISTS (SELECT 1 FROM v_xml_cdr l WHERE l.originating_leg_uuid = c.xml_cdr_uuid)"));
		$this->assertSame(array('a1', 'b2'), $this->select("NOT EXISTS (SELECT 1 FROM v_xml_cdr p WHERE p.leg = 'a' AND p.bridge_uuid = CAST(c.xml_cdr_uuid AS text))"));
	}

	public function testSelectCountsAndPages(): void
	{
		$this->seedCalls();

		$this->assertSame(3, fake_sql("SELECT COUNT(*) FROM v_xml_cdr c WHERE c.leg IN ('a', 'b')", null, 'column'));
		$this->assertSame(array('b1'), array_column(fake_sql("SELECT c.xml_cdr_uuid FROM v_xml_cdr c ORDER BY c.xml_cdr_uuid LIMIT 1 OFFSET 1", null), 'xml_cdr_uuid'));
		$this->assertSame(array(), fake_sql("SELECT c.xml_cdr_uuid FROM v_xml_cdr c ORDER BY c.xml_cdr_uuid LIMIT 2 OFFSET 3", null));
	}

	public function testWhereRejectsUnsupportedSql(): void
	{
		$this->expectException(\RuntimeException::class);

		fake_sql("SELECT c.xml_cdr_uuid FROM v_xml_cdr c WHERE c.number ~ '1'", null);
	}

	// FusionPBX 5.6.5 stores v_xml_cdr.bridge_uuid as text and the other leg
	// ids as uuid. Postgres has no text = uuid operator, so a query comparing
	// them fails on a real server unless one side is cast
	public function testWhereRejectsComparingTextWithUuidColumnsLikePostgres(): void
	{
		$this->seedCalls();

		$this->assertSame(array('b1'), $this->select("EXISTS (SELECT 1 FROM v_xml_cdr p WHERE p.bridge_uuid = CAST(c.xml_cdr_uuid AS text))"));
		$this->assertSame(array('b1'), $this->select("c.xml_cdr_uuid = :uuid", array('uuid' => 'b1')));

		$this->expectExceptionMessage('operator does not exist: text = uuid');
		$this->select("EXISTS (SELECT 1 FROM v_xml_cdr p WHERE p.bridge_uuid = c.xml_cdr_uuid)");
	}

	// FusionPBX 5.6.5's select() catches the PDOException and returns false
	public function testSelectReturnsFalseWhenTheDatabaseFails(): void
	{
		FakeStore::update(function (&$state) {
			$state['select_fails'] = true;
		});

		$this->assertFalse((new \database)->select("SELECT u.username FROM v_users u", null, 'all'));
	}

	// uncorrelated IN (SELECT ...): Postgres hashes the set once per query,
	// where a correlated EXISTS on an unindexed column scans the table per row
	public function testWhereSupportsInSubqueries(): void
	{
		$this->seedCalls();

		$this->assertSame(array('a1'), $this->select("c.xml_cdr_uuid IN (SELECT l.originating_leg_uuid FROM v_xml_cdr l WHERE l.leg = 'b')"));
		$this->assertSame(array('b1'), $this->select("CAST(c.xml_cdr_uuid AS text) IN (SELECT p.bridge_uuid FROM v_xml_cdr p WHERE p.leg = :leg)", array('leg' => 'a')));
		$this->assertSame(array('a1', 'b2'), $this->select("CAST(c.xml_cdr_uuid AS text) NOT IN (SELECT p.bridge_uuid FROM v_xml_cdr p WHERE p.bridge_uuid IS NOT NULL)"));
	}

	// like SQL, NOT IN is unknown when the set holds a NULL and nothing matches
	public function testWhereNotInWithANullInTheSetIsUnknown(): void
	{
		$this->seedCalls();

		$this->assertSame(array('b1'), $this->select("CAST(c.xml_cdr_uuid AS text) IN (SELECT p.bridge_uuid FROM v_xml_cdr p)"));
		$this->assertSame(array(), $this->select("CAST(c.xml_cdr_uuid AS text) NOT IN (SELECT p.bridge_uuid FROM v_xml_cdr p)"));
		$this->assertSame(array(), $this->select("c.number NOT IN ('x', NULL)"));
	}

	public function testWhereRejectsAnInSubqueryOfAnotherType(): void
	{
		$this->seedCalls();

		$this->expectExceptionMessage('operator does not exist: uuid = text');
		$this->select("c.xml_cdr_uuid IN (SELECT p.bridge_uuid FROM v_xml_cdr p)");
	}
}
