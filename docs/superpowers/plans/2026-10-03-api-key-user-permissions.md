# User-bound API keys and permissions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every REST API request runs as the FusionPBX user its key is bound to. The request gets that user's group permissions, is scoped to that user's domain, and records it creates carry that user as `insert_user`.

**Architecture:** `rest.php` looks up the key together with its user and domain, rejects unusable keys with 401, then loads that user's session, groups and permissions the way FusionPBX 5.6.5 does. This happens before anything checks a permission. Each action declares `$required_permissions`. `rest.php` resolves the domain the request acts on (403/404), checks the permissions (403), then calls `do_action($body, $context)`. The helpers live in `lib/auth.php`. The returned columns live in `lib/fields.php`.

**Tech Stack:** PHP (plain scripts, FusionPBX 5.6.5 app), PHPUnit 12, PHP built-in web server for HTTP tests, in-memory FusionPBX stand-ins in `tests/Support/fusionpbx/`.

**Spec:** `docs/superpowers/specs/2026-10-03-api-key-user-permissions-design.md` (read it before starting). Redmine #43940.

## Global Constraints

- Target FusionPBX **5.6.5** (tag `5.6.5`, commit `ac4cd29`).
- The plugin never reads or writes `v_users.api_key`.
- Every 401 response body is exactly `{"error":"unauthorized"}`. The reason only goes to `error_log`, never the secret.
- Permission names are exactly as in spec Section 3. Cross-domain access uses `domain_select`.
- Key management permissions are exactly `rest_api_key_view`, `rest_api_key_add`, `rest_api_key_edit` and `rest_api_key_delete`, for `superadmin`.
- Code style: `array()` syntax. Match each file's indentation: tabs in `rest.php`, `lib/auth.php` and `tests/`, 4 spaces in `actions/*.php` and `key_edit.php`. Short comments that explain *why*.
- Tests: PHP ≥ 8.3. `phpunit.xml.dist` fails on any warning, notice or deprecation, so a PHP warning anywhere breaks the suite. `composer test` must be green at the end of every task.
- Commit messages: `#43940 <Summary in imperative>`, ending with the line `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Run single tests with `vendor/bin/phpunit --filter <TestName>` and the whole suite with `composer test`.

## Review Focus

1. **PostgreSQL booleans:** `key_enabled`, `user_enabled` and `domain_enabled` may arrive as PHP `true`/`false`, `'t'`/`'f'` or `'true'`/`'false'`. Each must give the same result (AuthTest in Task 2 and Task 3).
2. **`expires` as returned by PostgreSQL** (`2026-10-03 12:00:00+00`) must compare correctly. An `expires` value that can't be parsed must reject the key, not let it through (AuthTest, Task 3).
3. **The user's own `domain_uuid` in uppercase** must be treated as their own domain, not get 403 (RestDomainScopeTest, Task 5).
4. **Non-string JSON values for `domain_uuid` or `domain_name`** (arrays, numbers) must return 400 or 404, never a PHP warning or a 500 (RestDomainScopeTest and ListActionsTest, Task 5).
5. **An admin form posted with array values** (`name[]=x`) must not raise a PHP warning or save garbage (AdminKeysTest, Task 2).

---

## File Structure

| File | Responsibility | Tasks |
|---|---|---|
| `tests/Support/fusionpbx/resources/fakes.php` | 5.6.5-like stand-ins: `permissions` (singleton), `groups`, `database::new()`, `save()` that skips tables without permission, `fake_sql` with joins | 1 |
| `tests/Support/fusionpbx/resources/require.php` | stand-in `require.php`: shared `$database`, `domain_change` permission check | 1 |
| `tests/Support/fusionpbx/login.php` | test login with FusionPBX-style group rows | 1 |
| `app_config.php` | new key columns and `rest_api_key_*` permissions | 2 |
| `lib/auth.php` | key lookup, rejection reasons, request-as-user setup, domain resolution, permission check | 2, 3, 4, 5 |
| `index.php`, `key_edit.php` | admin list and edit pages | 2 |
| `rest.php` | request flow | 3, 4, 5, 6 |
| `actions/*.php` | `$required_permissions`, domain scoping, explicit columns | 4, 5, 6 |
| `lib/fields.php` | the columns each action returns | 6 |
| `tests/Support/RestApiTestCase.php` | HTTP fixtures: key, user, groups, permissions | 2 |
| `tests/Support/ActionTestCase.php` | grants the declared permissions, passes `$context` | 4, 5, 6 |
| `README.md` | user docs | 7 |

---

### Task 1: Make the FusionPBX stand-ins behave like 5.6.5

The current stand-ins grant any permission written to `$_SESSION` at any time, and `save()` fails when a permission is missing. Real 5.6.5 behaves differently. It caches permissions in a singleton on the first check. It silently skips tables the user can't add to. It shares one `database` object whose `user_uuid` becomes `insert_user`. Tests built on the old stand-ins could not catch the bugs this ticket is about.

**Files:**
- Modify: `tests/Support/fusionpbx/resources/fakes.php`
- Modify: `tests/Support/fusionpbx/resources/require.php`
- Modify: `tests/Support/fusionpbx/login.php`
- Create: `tests/Unit/FakesTest.php`

**Interfaces:**
- Produces:
  - `permissions::new($database = null, $domain_uuid = null, $user_uuid = null): permissions` (singleton), `->exists(string): bool`, `->session(): void`
  - `permission_exists(string): bool`, which reads the globals `$database`, `$domain_uuid` and `$user_uuid`, like 5.6.5
  - `groups::__construct($database = null, $domain_uuid = null, $user_uuid = null)`, `->assigned(): array` (rows with `group_name`), `->session(): void`, which sets `$_SESSION['groups']`
  - `if_group(string): bool`, which reads `$_SESSION['groups']` rows
  - `database::new(array $params = array()): database` (singleton, updates `user_uuid` and `domain_uuid`). `new database(array $params = array())` reads `$_SESSION['user_uuid']` and `$_SESSION['domain_uuid']`. Public `$user_uuid` and `$domain_uuid`.
  - `database->save()` skips a record whose `<singular>_add` permission is missing, appends its table name to `FakeStore::read()['skipped']`, sets `insert_user` on saved records, and returns `true` (`false` only when `save_fails`)
  - `fake_sql` supports `FROM table alias LEFT JOIN table alias ON a.col = b.col`, qualified columns in `SELECT` / `WHERE` / `ORDER BY`, and ordering by several columns
  - Fixture tables read by the stand-ins:
    - `v_user_groups`: `domain_uuid`, `user_uuid`, `group_name`
    - `v_group_permissions`: `domain_uuid` (null for every domain), `group_name`, `permission_name`, `permission_assigned`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/FakesTest.php`:

```php
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
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter FakesTest`
Expected: FAIL / ERROR. For example `Class "permissions" not found`, `Call to undefined method database::new()` and `unsupported SQL`.

- [ ] **Step 3: Rewrite `fake_sql`'s SELECT support**

In `tests/Support/fusionpbx/resources/fakes.php`, inside `fake_sql()`:

(a) Replace the `$where` closure:

```php
	$where = function ($clause) use ($value) {
		$conditions = array();
		foreach (preg_split('/ and /i', $clause) as $condition) {
			if (!preg_match('/^([\w.]+) = (.+)$/', trim($condition), $m)) {
				throw new RuntimeException("unsupported SQL condition: ".$condition);
			}
			$conditions[$m[1]] = $value($m[2]);
		}
		return function ($row) use ($conditions) {
			foreach ($conditions as $column => $expected) {
				if (!isset($row[$column]) || (string)$row[$column] !== (string)$expected) {
					return false;
				}
			}
			return true;
		};
	};

	// FROM table [alias] [LEFT JOIN table alias ON a.column = b.column]...
	// each row holds every column as "alias.column". the first table's columns
	// are also there without alias, as in a single-table query
	$from = function ($clause, array $tables) {
		if (!preg_match('/^(\w+)(?: (?!left\b)(\w+))?((?: left join \w+ \w+ on \w+\.\w+ = \w+\.\w+)*)$/i', $clause, $m)) {
			throw new RuntimeException("unsupported SQL from: ".$clause);
		}
		$alias = !empty($m[2]) ? $m[2] : $m[1];
		$rows = array();
		foreach ($tables[$m[1]] ?? array() as $row) {
			foreach ($row as $column => $v) {
				$row[$alias.'.'.$column] = $v;
			}
			$rows[] = $row;
		}
		preg_match_all('/ left join (\w+) (\w+) on (\w+\.\w+) = (\w+\.\w+)/i', $m[3] ?? '', $joins, PREG_SET_ORDER);
		foreach ($joins as $join) {
			list(, $table, $join_alias, $left, $right) = $join;
			if (strpos($left, $join_alias.'.') !== 0) {
				list($left, $right) = array($right, $left);
			}
			$column = substr($left, strlen($join_alias) + 1);
			foreach ($rows as $i => $row) {
				foreach ($tables[$table] ?? array() as $candidate) {
					if (isset($row[$right], $candidate[$column]) && (string)$candidate[$column] === (string)$row[$right]) {
						foreach ($candidate as $c => $v) {
							$rows[$i][$join_alias.'.'.$c] = $v;
						}
						break;
					}
				}
			}
		}
		return $rows;
	};
```

(b) Change the `FakeStore::update(...)` closure's `use` list to `use ($sql, $parameters, $return_type, $value, $where, $from)`. Then replace the whole `if (preg_match('/^select ...` block with:

```php
		if (preg_match('/^select (.+?) from (.+?)(?: where (.+?))?(?: order by (.+?))?(?: limit (\d+))?$/i', $sql, $m)) {
			$rows = $from($m[2], $state['tables']);
			if (!empty($m[3])) {
				$rows = array_values(array_filter($rows, $where($m[3])));
			}
			if (!empty($m[4])) {
				$order = array();
				foreach (explode(',', $m[4]) as $part) {
					if (!preg_match('/^([\w.]+)(?: (asc|desc))?$/i', trim($part), $o)) {
						throw new RuntimeException("unsupported SQL order: ".$part);
					}
					$order[] = array($o[1], strcasecmp($o[2] ?? '', 'desc') === 0 ? -1 : 1);
				}
				usort($rows, function ($a, $b) use ($order) {
					foreach ($order as list($column, $direction)) {
						$cmp = strcmp((string)($a[$column] ?? ''), (string)($b[$column] ?? ''));
						if ($cmp !== 0) {
							return $cmp * $direction;
						}
					}
					return 0;
				});
			}
			if (!empty($m[5])) {
				$rows = array_slice($rows, 0, (int)$m[5]);
			}
			if (trim($m[1]) === '*') {
				$rows = array_map(function ($row) {
					return array_filter($row, function ($column) { return strpos($column, '.') === false; }, ARRAY_FILTER_USE_KEY);
				}, $rows);
			} else {
				$columns = array_map('trim', explode(',', $m[1]));
				$rows = array_map(function ($row) use ($columns) {
					$out = array();
					foreach ($columns as $c) {
						$out[preg_replace('/^\w+\./', '', $c)] = $row[$c] ?? null;
					}
					return $out;
				}, $rows);
			}
			switch ($return_type) {
				case 'row':
					return $rows[0] ?? false;
				case 'column':
					return $rows ? reset($rows[0]) : false;
				default:
					return $rows;
			}
		}
```

- [ ] **Step 4: Replace the `database` class**

In `fakes.php`, add `'skipped' => array(),` to the array in `FakeStore::reset()`, right after `'saved' => array(),`. Then replace the whole `class database { ... }` with:

```php
class database {
	private static $instance = null;
	public $app_name;
	public $app_uuid;
	public $user_uuid;
	public $domain_uuid;

	// like FusionPBX: the user and domain come from the parameters, else from the session
	public function __construct(array $params = array()) {
		$this->user_uuid = !empty($params['user_uuid']) ? $params['user_uuid'] : ($_SESSION['user_uuid'] ?? null);
		$this->domain_uuid = !empty($params['domain_uuid']) ? $params['domain_uuid'] : ($_SESSION['domain_uuid'] ?? null);
	}

	// FusionPBX 5.6.5 database::new(): one shared instance, whose user and domain
	// are updated from the parameters, else the session, on every call
	public static function new(array $params = array()) {
		if (self::$instance === null) {
			self::$instance = new database($params);
		}
		if (!empty($params['user_uuid'])) {
			self::$instance->user_uuid = $params['user_uuid'];
		} elseif (!empty($_SESSION['user_uuid'])) {
			self::$instance->user_uuid = $_SESSION['user_uuid'];
		}
		if (!empty($params['domain_uuid'])) {
			self::$instance->domain_uuid = $params['domain_uuid'];
		} elseif (!empty($_SESSION['domain_uuid'])) {
			self::$instance->domain_uuid = $_SESSION['domain_uuid'];
		}
		return self::$instance;
	}

	public function select(string $sql, ?array $parameters = array(), string $return_type = 'all') {
		return fake_sql($sql, $parameters, $return_type);
	}

	public function execute(string $sql, ?array $parameters = array()) {
		return fake_sql($sql, $parameters);
	}

	/**
	 * Like FusionPBX 5.6.5's save(): a record whose table lacks the "<singular>_add"
	 * permission is skipped without an error (its table is added to "skipped" so
	 * tests can see it), saved records get insert_user from this object, and it
	 * returns false when the database rejects the records (simulated by save_fails).
	 * Records are stored in v_<table>, nested child records in their own tables.
	 */
	public function save(array $array) {
		$records = array();
		$collect = function ($table, $rows) use (&$collect, &$records) {
			foreach ($rows as $row) {
				foreach ($row as $key => $child) {
					if (is_array($child) && isset($child[0]) && is_array($child[0])) {
						$collect($key, $child);
						unset($row[$key]);
					}
				}
				$records[] = array($table, $row);
			}
		};
		foreach ($array as $table => $rows) {
			$collect($table, $rows);
		}

		if (FakeStore::read()['save_fails']) {
			return false;
		}
		$allowed = array();
		$skipped = array();
		foreach ($records as $record) {
			if (permission_exists(rtrim($record[0], 's').'_add')) {
				$record[1]['insert_user'] = $this->user_uuid;
				$allowed[] = $record;
			} else {
				$skipped[] = $record[0];
			}
		}

		FakeStore::update(function (&$state) use ($allowed, $skipped, $array) {
			$state['saved'][] = array('app_uuid' => $this->app_uuid, 'array' => $array);
			foreach ($allowed as $record) {
				$state['tables']['v_'.$record[0]][] = $record[1];
			}
			$state['skipped'] = array_merge($state['skipped'], $skipped);
		});
		return true;
	}
}
```

- [ ] **Step 5: Replace `permission_exists` and `if_group`, and add `permissions` and `groups`**

In `fakes.php`, replace the existing `permission_exists()` and `if_group()` functions with:

```php
/**
 * FusionPBX 5.6.5's permissions class: a singleton holding the permissions of
 * the session, or else of the user's groups, as they were when it was created.
 */
class permissions {
	private static $permission = null;
	private $permissions = array();

	public function __construct($database = null, $domain_uuid = null, $user_uuid = null) {
		$domain_uuid = is_uuid($domain_uuid) ? $domain_uuid : ($_SESSION['domain_uuid'] ?? null);
		$user_uuid = is_uuid($user_uuid) ? $user_uuid : ($_SESSION['user_uuid'] ?? null);
		if (isset($_SESSION['permissions'])) {
			$this->permissions = $_SESSION['permissions'];
			return;
		}
		$group_names = array_column((new groups($database, $domain_uuid, $user_uuid))->assigned(), 'group_name');
		foreach (FakeStore::read()['tables']['v_group_permissions'] ?? array() as $row) {
			if (in_array($row['group_name'], $group_names, true)
				&& ($row['domain_uuid'] === null || $row['domain_uuid'] === $domain_uuid)
				&& $row['permission_assigned'] === 'true') {
				$this->permissions[$row['permission_name']] = 1;
			}
		}
	}

	public static function new($database = null, $domain_uuid = null, $user_uuid = null) {
		if (self::$permission === null) {
			self::$permission = new permissions($database, $domain_uuid, $user_uuid);
		}
		return self::$permission;
	}

	public function exists($permission_name) {
		return !empty($permission_name) && isset($this->permissions[$permission_name]);
	}

	public function session() {
		foreach ($this->permissions as $permission_name => $row) {
			$_SESSION['permissions'][$permission_name] = true;
			$_SESSION['user']['permissions'][$permission_name] = true;
		}
	}
}

// FusionPBX 5.6.5: the user's groups (v_user_groups rows here carry group_name directly)
class groups {
	private $groups = array();

	public function __construct($database = null, $domain_uuid = null, $user_uuid = null) {
		if (is_uuid($domain_uuid) && is_uuid($user_uuid)) {
			foreach (FakeStore::read()['tables']['v_user_groups'] ?? array() as $row) {
				if ($row['domain_uuid'] === $domain_uuid && $row['user_uuid'] === $user_uuid) {
					$this->groups[] = $row;
				}
			}
		}
	}

	public function assigned() {
		return $this->groups;
	}

	public function session() {
		$_SESSION['groups'] = $this->groups;
		$_SESSION['user']['groups'] = $this->groups;
	}
}

function permission_exists($permission_name) {
	global $database, $domain_uuid, $user_uuid;
	return permissions::new($database, $domain_uuid, $user_uuid)->exists($permission_name);
}

function if_group($group) {
	foreach ($_SESSION['groups'] ?? array() as $row) {
		if (($row['group_name'] ?? null) === $group) {
			return true;
		}
	}
	return false;
}
```

- [ ] **Step 6: Update the stand-in `require.php` and `login.php`**

Replace `tests/Support/fusionpbx/resources/require.php` with:

```php
<?php
// fake FusionPBX resources/require.php
require_once __DIR__.'/fakes.php';

// like FusionPBX 5.6.5, connect before anyone knows the user
global $database;
$database = database::new();

// FPBX_SESSION_MODE=honored: like current FusionPBX, skip the session when $no_session is set
// FPBX_SESSION_MODE=ignored: like a FusionPBX that always starts the session
global $no_session;
if (session_status() === PHP_SESSION_NONE && (getenv('FPBX_SESSION_MODE') === 'ignored' || empty($no_session))) {
	session_start();
}

// like FusionPBX 5.6.5 require.php:157, switching domains from the query string
// checks a permission, which fixes the permissions for the rest of the request
if (!empty($_GET['domain_uuid']) && is_uuid($_GET['domain_uuid']) && ($_GET['domain_change'] ?? '') === 'true') {
	permission_exists('domain_select');
}
```

In `tests/Support/fusionpbx/login.php`, replace `$_SESSION['groups'] = array('superadmin');` with:

```php
$_SESSION['groups'] = array(array('group_name' => 'superadmin'));
```

- [ ] **Step 7: Run the new tests and the whole suite**

Run: `vendor/bin/phpunit --filter FakesTest`
Expected: PASS (10 tests).

Run: `composer test`
Expected: PASS (133 tests). The existing actions still write `$_SESSION["permissions"]` before their first `save()`, so the singleton picks those permissions up and nothing is skipped.

- [ ] **Step 8: Commit**

```bash
git add tests/Support/fusionpbx tests/Unit/FakesTest.php
git commit -m "#43940 Make the FusionPBX test stand-ins behave like 5.6.5

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Key columns, key management permissions and admin pages

**Files:**
- Modify: `app_config.php`
- Create: `lib/auth.php` (only `rest_api_is_true` in this task)
- Modify: `index.php` (full rewrite below)
- Modify: `key_edit.php` (full rewrite below)
- Modify: `tests/Support/RestApiTestCase.php` (full rewrite below)
- Modify: `tests/Http/AdminKeysTest.php` (full rewrite below)
- Create: `tests/Unit/AppConfigTest.php`
- Create: `tests/Unit/AuthTest.php`

**Interfaces:**
- Consumes (Task 1): the joins and multi-column `ORDER BY` in `fake_sql`, and `if_group` group rows.
- Produces:
  - `rest_api_is_true($value): bool` in `lib/auth.php`
  - `rest_api_keys` columns `user_uuid`, `key_enabled` (`'true'` / `'false'`), `expires` (`Y-m-d H:i:s` or null)
  - `RestApiTestCase` constants:
    - `KEY_ID`, `SECRET`, `USER_UUID` (`dddddddd-0000-4000-8000-000000000001`, username `api_billing`)
    - `DOMAIN_UUID` (`aaaaaaaa-0000-4000-8000-000000000001`, `tenant1.example.com`)
    - `OTHER_DOMAIN_UUID` (`…0002`, `tenant2.example.com`)
    - `GROUP` (`api_integration`)
    - `ACTION_PERMISSIONS`
  - `RestApiTestCase` helpers: `groupPermissions(array): array` (static), `grantOnly(array): void`, `updateRows(string $table, array $match, array $changes): void`

- [ ] **Step 1: Write the failing unit tests**

Create `tests/Unit/AppConfigTest.php`:

```php
<?php
namespace RestApi\Test\Unit;

use PHPUnit\Framework\TestCase;

class AppConfigTest extends TestCase
{
	private function app(): array
	{
		$x = 0;
		$apps = array();
		require PLUGIN_DIR.'/app_config.php';
		return $apps[0];
	}

	public function testKeysTableHasTheUserEnabledAndExpiryColumns(): void
	{
		$fields = array_map(function ($field) {
			return is_array($field['name']) ? $field['name']['text'] : $field['name'];
		}, $this->app()['db'][0]['fields']);

		$this->assertSame(array('key_uuid', 'name', 'key_secret', 'created', 'last_used', 'user_uuid', 'key_enabled', 'expires'), $fields);
	}

	public function testKeyManagementPermissionsAreForSuperadmins(): void
	{
		$permissions = $this->app()['permissions'];

		$this->assertSame(
			array('rest_api_key_view', 'rest_api_key_add', 'rest_api_key_edit', 'rest_api_key_delete'),
			array_column($permissions, 'name')
		);
		foreach ($permissions as $permission) {
			$this->assertSame(array('superadmin'), $permission['groups']);
		}
	}
}
```

Create `tests/Unit/AuthTest.php`:

```php
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
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --filter 'AppConfigTest|AuthTest'`
Expected: FAIL. The field list lacks the three new columns, the permission list is `rest_api_manage_keys`, and `lib/auth.php` can't be opened.

- [ ] **Step 3: Add the columns and permissions to `app_config.php`**

In `app_config.php`, insert after the `last_used` field block (after its `$z++;`):

```php
$apps[$x]['db'][$y]['fields'][$z]['name'] = "user_uuid";
$apps[$x]['db'][$y]['fields'][$z]['type']['pgsql'] = 'uuid';
$apps[$x]['db'][$y]['fields'][$z]['type']['sqlite'] = 'text';
$apps[$x]['db'][$y]['fields'][$z]['type']['mysql'] = 'char(36)';
$apps[$x]['db'][$y]['fields'][$z]['description']['en-us'] = "FusionPBX user the key acts as";
$z++;

$apps[$x]['db'][$y]['fields'][$z]['name'] = "key_enabled";
$apps[$x]['db'][$y]['fields'][$z]['type']['pgsql'] = 'boolean';
$apps[$x]['db'][$y]['fields'][$z]['type']['sqlite'] = 'text';
$apps[$x]['db'][$y]['fields'][$z]['type']['mysql'] = 'text';
$apps[$x]['db'][$y]['fields'][$z]['toggle'] = ['true','false'];
$apps[$x]['db'][$y]['fields'][$z]['description']['en-us'] = "only enabled keys authenticate";
$z++;

$apps[$x]['db'][$y]['fields'][$z]['name'] = "expires";
$apps[$x]['db'][$y]['fields'][$z]['type']['pgsql'] = 'timestamptz';
$apps[$x]['db'][$y]['fields'][$z]['type']['sqlite'] = 'date';
$apps[$x]['db'][$y]['fields'][$z]['type']['mysql'] = 'datetime';
$apps[$x]['db'][$y]['fields'][$z]['description']['en-us'] = "date this key stops working, empty for never";
$z++;
```

Replace the permissions block at the end (from `$y=0;` through the final `$y++;`) with:

```php
$y=0;

$apps[$x]['permissions'][$y]['name'] = "rest_api_key_view";
$apps[$x]['permissions'][$y]['groups'][] = "superadmin";
$y++;

$apps[$x]['permissions'][$y]['name'] = "rest_api_key_add";
$apps[$x]['permissions'][$y]['groups'][] = "superadmin";
$y++;

$apps[$x]['permissions'][$y]['name'] = "rest_api_key_edit";
$apps[$x]['permissions'][$y]['groups'][] = "superadmin";
$y++;

$apps[$x]['permissions'][$y]['name'] = "rest_api_key_delete";
$apps[$x]['permissions'][$y]['groups'][] = "superadmin";
$y++;
```

- [ ] **Step 4: Create `lib/auth.php`**

```php
<?php
// API keys act as the FusionPBX user they are bound to (#43940)

// PostgreSQL booleans reach PHP as true/false or "t"/"f"; FusionPBX writes "true"/"false"
function rest_api_is_true($value) {
	return $value === true || $value === 1 || in_array($value, array('true', 't', '1'), true);
}
```

- [ ] **Step 5: Run the unit tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'AppConfigTest|AuthTest'`
Expected: PASS (14 tests).

- [ ] **Step 6: Rewrite the HTTP fixtures in `RestApiTestCase`**

Replace `tests/Support/RestApiTestCase.php` with:

```php
<?php
namespace RestApi\Test\Support;

/**
 * HTTP tests against rest.php with one API key, KEY_ID:SECRET, bound to the
 * user api_billing of tenant1.example.com. The user's group has every
 * permission the plugin's actions need.
 */
abstract class RestApiTestCase extends HttpTestCase
{
	protected const KEY_ID = '11111111-1111-4111-8111-111111111111';
	protected const SECRET = 'Q7mZp2Kx9VbN4tRw8LcY';
	protected const USER_UUID = 'dddddddd-0000-4000-8000-000000000001';
	protected const DOMAIN_UUID = 'aaaaaaaa-0000-4000-8000-000000000001';
	protected const OTHER_DOMAIN_UUID = 'aaaaaaaa-0000-4000-8000-000000000002';
	protected const GROUP = 'api_integration';
	protected const ACTION_PERMISSIONS = array(
		'extension_add', 'voicemail_add', 'destination_add', 'dialplan_add', 'dialplan_detail_add',
		'ring_group_add', 'ring_group_destination_add', 'extension_view', 'destination_view',
		'xml_cdr_view', 'click_to_call_call',
	);

	protected function tables(): array
	{
		return array(
			'rest_api_keys' => array(
				array('key_uuid' => self::KEY_ID, 'name' => 'billing', 'key_secret' => password_hash(self::SECRET, PASSWORD_DEFAULT, array('cost' => 4)), 'user_uuid' => self::USER_UUID, 'key_enabled' => 'true', 'expires' => null, 'created' => '2026-01-01', 'last_used' => null),
			),
			'v_domains' => array(
				array('domain_uuid' => self::DOMAIN_UUID, 'domain_parent_uuid' => null, 'domain_name' => 'tenant1.example.com', 'domain_enabled' => 'true', 'domain_description' => ''),
				array('domain_uuid' => self::OTHER_DOMAIN_UUID, 'domain_parent_uuid' => null, 'domain_name' => 'tenant2.example.com', 'domain_enabled' => 'true', 'domain_description' => ''),
			),
			'v_users' => array(
				array('user_uuid' => self::USER_UUID, 'domain_uuid' => self::DOMAIN_UUID, 'username' => 'api_billing', 'user_enabled' => 'true'),
			),
			'v_user_groups' => array(
				array('domain_uuid' => self::DOMAIN_UUID, 'user_uuid' => self::USER_UUID, 'group_name' => self::GROUP),
			),
			'v_group_permissions' => self::groupPermissions(self::ACTION_PERMISSIONS),
		);
	}

	/** v_group_permissions rows giving the key user's group these permissions. */
	protected static function groupPermissions(array $permissions): array
	{
		return array_map(function ($permission) {
			return array('domain_uuid' => null, 'group_name' => self::GROUP, 'permission_name' => $permission, 'permission_assigned' => 'true');
		}, $permissions);
	}

	/** Give the key user's group exactly these permissions for the following requests. */
	protected function grantOnly(array $permissions): void
	{
		\FakeStore::update(function (&$state) use ($permissions) {
			$state['tables']['v_group_permissions'] = self::groupPermissions($permissions);
		});
	}

	/** Merge $changes into every row of $table that has the values in $match. */
	protected function updateRows(string $table, array $match, array $changes): void
	{
		\FakeStore::update(function (&$state) use ($table, $match, $changes) {
			foreach ($state['tables'][$table] ?? array() as $i => $row) {
				if (array_intersect_assoc($match, $row) == $match) {
					$state['tables'][$table][$i] = array_merge($row, $changes);
				}
			}
		});
	}

	protected function api($body, ?string $credentials = self::KEY_ID.':'.self::SECRET, array $headers = array()): array
	{
		if ($credentials !== null) {
			$headers['Authorization'] = 'Basic '.base64_encode($credentials);
		}
		return $this->request('POST', '/app/rest_api/rest.php', is_string($body) ? $body : json_encode($body), $headers);
	}

	protected function json(array $response)
	{
		return json_decode($response['body'], true);
	}
}
```

- [ ] **Step 7: Write the failing admin tests**

Replace `tests/Http/AdminKeysTest.php` with:

```php
<?php
namespace RestApi\Test\Http;

use RestApi\Test\Support\RestApiTestCase;

/**
 * Key management pages: index.php (list, delete) and key_edit.php (create, edit).
 */
class AdminKeysTest extends RestApiTestCase
{
	private const ALL = 'rest_api_key_view,rest_api_key_add,rest_api_key_edit,rest_api_key_delete';
	private const OPS_USER = 'dddddddd-0000-4000-8000-000000000002';

	private string $cookie = '';

	protected function tables(): array
	{
		$tables = parent::tables();
		$tables['v_users'][] = array('user_uuid' => self::OPS_USER, 'domain_uuid' => self::OTHER_DOMAIN_UUID, 'username' => 'ops', 'user_enabled' => 'false');
		return $tables;
	}

	private function login(string $permissions = self::ALL): void
	{
		$response = $this->request('GET', '/login.php?permissions='.$permissions);
		$this->cookie = explode(';', $response['headers']['set-cookie'][0])[0];
	}

	private function page(string $path): array
	{
		return $this->request('GET', '/app/rest_api/'.$path, '', array('Cookie' => $this->cookie));
	}

	private function submit(string $path, array $fields): array
	{
		return $this->request('POST', '/app/rest_api/'.$path, http_build_query($fields), array(
			'Cookie' => $this->cookie,
			'Content-Type' => 'application/x-www-form-urlencoded',
		));
	}

	/** The CSRF token field rendered in a page, as name => value. */
	private function tokenField(array $page): array
	{
		$this->assertMatchesRegularExpression("/<input type='hidden' name='([0-9a-f]{16})' value='([0-9a-f]{32})'>/", $page['body'], 'page has no CSRF token');
		preg_match("/<input type='hidden' name='([0-9a-f]{16})' value='([0-9a-f]{32})'>/", $page['body'], $m);
		return array($m[1] => $m[2]);
	}

	/** Valid form fields for a key bound to the API user. */
	private function keyFields(array $overrides = array()): array
	{
		return array_merge(array('name' => 'Billing', 'key_uuid' => '', 'user_uuid' => self::USER_UUID, 'key_enabled' => 'true', 'expires' => ''), $overrides);
	}

	private function keys(): array
	{
		return $this->state()['tables']['rest_api_keys'] ?? array();
	}

	public function testListRequiresTheViewPermission(): void
	{
		foreach (array('extension_view', 'rest_api_manage_keys') as $permission) {
			$this->login($permission);

			$response = $this->page('index.php');

			$this->assertStringContainsString('permission denied', $response['body'], $permission);
			$this->assertStringNotContainsString(self::KEY_ID, $response['body']);
		}
	}

	public function testListShowsEachKeysUserAndState(): void
	{
		$this->login();

		$body = $this->page('index.php')['body'];

		$this->assertStringContainsString('api_billing@tenant1.example.com', $body);
		$this->assertStringContainsString('never', $body);
	}

	public function testListFlagsKeysWithoutUserAndExpiredOrDisabledKeys(): void
	{
		\FakeStore::update(function (&$state) {
			$state['tables']['rest_api_keys'][0]['user_uuid'] = null;
			$state['tables']['rest_api_keys'][] = array('key_uuid' => '22222222-2222-4222-8222-222222222222', 'name' => 'old', 'key_secret' => 'x', 'user_uuid' => self::USER_UUID, 'key_enabled' => 'false', 'expires' => '2020-01-01 00:00:00+00', 'created' => '2019-01-01', 'last_used' => null);
		});
		$this->login();

		$body = $this->page('index.php')['body'];

		$this->assertStringContainsString('no user', $body);
		$this->assertStringContainsString('expired', $body);
		$this->assertStringContainsString('<b>no</b>', $body);
	}

	public function testNewAndDeleteButtonsNeedTheirPermissions(): void
	{
		$this->login('rest_api_key_view');

		$body = $this->page('index.php')['body'];

		$this->assertStringNotContainsString('<a href="key_edit.php">', $body);
		$this->assertStringNotContainsString('id="modal-delete"', $body);
	}

	public function testOpeningTheNewKeyFormDoesNotCreateAKey(): void
	{
		$this->login();

		$this->page('key_edit.php');

		$this->assertCount(1, $this->keys());
	}

	public function testCreatedKeyIsBoundToItsUserAuthenticatesAndIsStoredHashed(): void
	{
		$this->login();
		$form = $this->page('key_edit.php');

		$response = $this->submit('key_edit.php', $this->keyFields() + $this->tokenField($form));

		$this->assertMatchesRegularExpression('/<code>([0-9a-f-]{36}):([0-9A-Za-z]{20})<\/code>/', $response['body']);
		preg_match('/<code>([0-9a-f-]{36}):([0-9A-Za-z]{20})<\/code>/', $response['body'], $m);
		$created = $this->keys()[1];
		$this->assertSame(array($m[1], 'Billing', self::USER_UUID, 'true', null), array($created['key_uuid'], $created['name'], $created['user_uuid'], $created['key_enabled'], $created['expires']));
		$this->assertStringNotContainsString($m[2], json_encode($created));
		$this->assertSame(200, $this->api(array('action' => 'domain-details', 'domain_name' => 'tenant1.example.com'), $m[1].':'.$m[2])['status']);
	}

	public function testCreatingAKeyRequiresAnExistingUser(): void
	{
		$this->login();

		foreach (array('', 'not-a-uuid', 'dddddddd-0000-4000-8000-00000000ffff') as $user_uuid) {
			$form = $this->page('key_edit.php');
			$response = $this->submit('key_edit.php', $this->keyFields(array('user_uuid' => $user_uuid)) + $this->tokenField($form));

			$this->assertStringContainsString('select the user this key acts as', $response['body'], $user_uuid);
		}
		$this->assertCount(1, $this->keys());
	}

	public function testRejectsAnInvalidExpiryDate(): void
	{
		$this->login();
		$form = $this->page('key_edit.php');

		$response = $this->submit('key_edit.php', $this->keyFields(array('expires' => 'not a date')) + $this->tokenField($form));

		$this->assertStringContainsString('invalid expiry date', $response['body']);
		$this->assertCount(1, $this->keys());
	}

	public function testIgnoresArrayValuesInTheForm(): void
	{
		$this->login();
		$form = $this->page('key_edit.php');

		$response = $this->submit('key_edit.php', $this->keyFields(array('name' => array('x'), 'expires' => array('2030-01-01'))) + $this->tokenField($form));

		$this->assertSame(200, $response['status']);
		$this->assertSame(array('', null), array($this->keys()[1]['name'], $this->keys()[1]['expires']));
	}

	public function testEditsTheUserStateAndExpiryOfAKey(): void
	{
		$this->login();
		$form = $this->page('key_edit.php?key_uuid='.self::KEY_ID);

		$this->submit('key_edit.php', array('name' => 'Invoicing', 'key_uuid' => self::KEY_ID, 'user_uuid' => self::OPS_USER, 'expires' => '2026-12-31T23:30') + $this->tokenField($form));

		$key = $this->keys()[0];
		$this->assertSame(array('Invoicing', self::OPS_USER, 'false', '2026-12-31 23:30:00'), array($key['name'], $key['user_uuid'], $key['key_enabled'], $key['expires']));
	}

	public function testUserPickerListsUsersOfEveryDomain(): void
	{
		$this->login();

		$body = $this->page('key_edit.php?key_uuid='.self::KEY_ID)['body'];

		$this->assertStringContainsString("<option value='".self::USER_UUID."' selected='selected'>api_billing@tenant1.example.com</option>", $body);
		$this->assertStringContainsString("<option value='".self::OPS_USER."'>ops@tenant2.example.com (disabled)</option>", $body);
	}

	public function testRejectsCreatingAKeyWithoutAValidToken(): void
	{
		$this->login();
		$token = $this->tokenField($this->page('key_edit.php'));

		$this->submit('key_edit.php', $this->keyFields(array('name' => 'Forged')));
		$this->submit('key_edit.php', $this->keyFields(array('name' => 'Forged')) + array(key($token) => str_repeat('0', 32)));

		$this->assertCount(1, $this->keys());
	}

	public function testRejectsEditingAKeyWithoutAToken(): void
	{
		$this->login();

		$this->submit('key_edit.php', $this->keyFields(array('name' => '<script>alert(1)</script>', 'key_uuid' => self::KEY_ID)));

		$this->assertSame('billing', $this->keys()[0]['name']);
	}

	public function testCreatingRequiresTheAddPermission(): void
	{
		$this->login('rest_api_key_view,rest_api_key_edit');
		$token = $this->tokenField($this->page('key_edit.php?key_uuid='.self::KEY_ID));

		$form = $this->page('key_edit.php');
		$this->submit('key_edit.php', $this->keyFields() + $token);

		$this->assertStringContainsString('permission denied', $form['body']);
		$this->assertCount(1, $this->keys());
	}

	public function testEditingRequiresTheEditPermission(): void
	{
		$this->login('rest_api_key_view,rest_api_key_add');
		$form = $this->page('key_edit.php?key_uuid='.self::KEY_ID);

		$response = $this->submit('key_edit.php', $this->keyFields(array('name' => 'Renamed', 'key_uuid' => self::KEY_ID)) + $this->tokenField($form));

		$this->assertStringContainsString("name=\"name\" value=\"billing\" disabled='disabled'", $form['body']);
		$this->assertStringNotContainsString('id="btn_save"', $form['body']);
		$this->assertStringContainsString('permission denied', $response['body']);
		$this->assertSame('billing', $this->keys()[0]['name']);
	}

	public function testDeletesAKey(): void
	{
		$this->login();
		$list = $this->page('index.php');

		$this->submit('index.php', array('action' => 'delete', 'key_uuid' => self::KEY_ID) + $this->tokenField($list));

		$this->assertSame(array(), $this->keys());
	}

	public function testDeletingRequiresTheDeletePermission(): void
	{
		$this->login('rest_api_key_view');
		$token = $this->tokenField($this->page('key_edit.php?key_uuid='.self::KEY_ID));

		$this->submit('index.php', array('action' => 'delete', 'key_uuid' => self::KEY_ID) + $token);

		$this->assertCount(1, $this->keys());
	}

	public function testRejectsDeletingAKeyWithoutAToken(): void
	{
		$this->login();

		$this->submit('index.php', array('action' => 'delete', 'key_uuid' => self::KEY_ID));

		$this->assertCount(1, $this->keys());
	}

	public function testEscapesKeyNames(): void
	{
		\FakeStore::update(function (&$state) {
			$state['tables']['rest_api_keys'][0]['name'] = '<script>alert(1)</script>"\'';
		});
		$this->login();

		$list = $this->page('index.php')['body'];
		$edit = $this->page('key_edit.php?key_uuid='.self::KEY_ID)['body'];

		foreach (array($list, $edit) as $body) {
			$this->assertStringNotContainsString('<script>alert(1)</script>', $body);
			$this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
		}
		$this->assertStringContainsString('value="&lt;script&gt;alert(1)&lt;/script&gt;&quot;&apos;"', $edit);
	}

	public function testIgnoresAMalformedKeyUuid(): void
	{
		$this->login();

		$response = $this->page('key_edit.php?key_uuid='.urlencode('<script>x</script>'));

		$this->assertSame(302, $response['status']);
		$this->assertSame(array('index.php'), $response['headers']['location']);
	}

	public function testKeyEditRequiresTheViewPermission(): void
	{
		$this->login('extension_view');

		$form = $this->page('key_edit.php?key_uuid='.self::KEY_ID);
		$this->submit('key_edit.php', $this->keyFields(array('name' => 'Unauthorized')));

		$this->assertStringContainsString('permission denied', $form['body']);
		$this->assertStringNotContainsString('billing', $form['body']);
		$this->assertCount(1, $this->keys());
	}

	public function testIgnoresAMalformedKeyUuidWhenSaving(): void
	{
		$this->login();
		$form = $this->page('key_edit.php');

		$response = $this->submit('key_edit.php', $this->keyFields(array('name' => 'Renamed', 'key_uuid' => "' OR '1'='1")) + $this->tokenField($form));

		$this->assertSame(302, $response['status']);
		$this->assertSame(array('index.php'), $response['headers']['location']);
		$this->assertSame(array('billing'), array_column($this->keys(), 'name'));
	}

	public function testRedirectsAnUnknownKeyToTheList(): void
	{
		$this->login();

		$response = $this->page('key_edit.php?key_uuid=22222222-2222-4222-8222-222222222222');

		$this->assertSame(302, $response['status']);
		$this->assertSame(array('index.php'), $response['headers']['location']);
	}
}
```

- [ ] **Step 8: Run the admin tests to verify they fail**

Run: `vendor/bin/phpunit --filter AdminKeysTest`
Expected: FAIL. Among others, `testListRequiresTheViewPermission` fails because `rest_api_manage_keys` is still accepted, and the user picker and `no user` tests fail.

- [ ] **Step 9: Rewrite `index.php`**

```php
<?php
/*
	GNU Public License
	Version: GPL 3
*/
require_once "root.php";
require_once "resources/require.php";
require_once "resources/check_auth.php";
require_once "resources/header.php";
require_once "resources/paging.php";
require_once "lib/auth.php";

if(!permission_exists('rest_api_key_view')) {
	echo "permission denied";
	require_once "resources/footer.php";
	die();
}
$can_delete = permission_exists('rest_api_key_delete');

$object = new token;

if($can_delete && ($_POST['action'] ?? '') == "delete" && is_uuid($_POST['key_uuid'] ?? '')) {
	if(!$object->validate('rest_api_keys')) {
		message::add("invalid token", 'negative');
		header('Location: index.php');
		exit;
	}

	$sql = "DELETE FROM rest_api_keys WHERE key_uuid = :key_uuid";
	$parameters['key_uuid'] = $_POST['key_uuid'];
	$database = new database;
	$database->execute($sql, $parameters);
	unset($parameters);
}

$token = $object->create('rest_api_keys');

if($can_delete) {
	echo "<form method='post'>";
	echo modal::create([
		'id'=>'modal-delete',
		'type'=>'delete',
		'actions'=>button::create([
			'type'=>'submit',
			'label'=>"delete",
			'icon'=>'check',
			'id'=>'btn_delete',
			'style'=>'float: right; margin-left: 15px;',
			'collapse'=>'never',
			'name'=>'action',
			'value'=>'delete',
			'onclick'=>"modal_close();"
		]
	)]);
	echo "<input type='hidden' name='key_uuid' id='key_uuid'/>";
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>";
	echo "</form>";
}

echo "<div class='action_bar' id='action_bar'>\n";
echo "	<div class='heading'><b>REST API Keys</b></div>\n";
echo "	<div class='actions'>\n";
if(permission_exists('rest_api_key_add')) {
	echo button::create(['type'=>'button','label'=>"New",'icon'=>$_SESSION['theme']['button_icon_add'] ?? null,'id'=>'btn_add','name'=>'btn_add','link'=>'key_edit.php']);
}
echo "	</div>\n";
echo "	<div style='clear: both;'></div>\n";
echo "</div>\n";
echo "<br /><br />\n";
echo "endpoint: <code>https://".escape($_SERVER['HTTP_HOST'])."/app/rest_api/rest.php</code>\n";

// keys without a user (from before #43940, or whose user was deleted) can't authenticate
$sql = "SELECT k.key_uuid, k.name, k.key_enabled, k.expires, k.created, k.last_used, u.username, d.domain_name";
$sql .= " FROM rest_api_keys k LEFT JOIN v_users u ON u.user_uuid = k.user_uuid LEFT JOIN v_domains d ON d.domain_uuid = u.domain_uuid";
$sql .= " ORDER BY k.last_used DESC";
$database = new database;
$keys = $database->select($sql, null, 'all');
?>
<table class="table">
<tr>
	<th>Name</th>
	<th>Key ID</th>
	<th>User</th>
	<th>Enabled</th>
	<th>Expires</th>
	<th>Created</th>
	<th>Last Used</th>
<?php if($can_delete) { ?>
	<th>Actions</th>
<?php } ?>
</tr>
<?php
foreach($keys as $key) {
	$expired = !empty($key['expires']) && strtotime($key['expires']) <= time();
?>
<tr>
	<td><a href="key_edit.php?key_uuid=<?php echo escape($key['key_uuid']); ?>"><?php echo escape($key['name']); ?></a></td>
	<td><a href="key_edit.php?key_uuid=<?php echo escape($key['key_uuid']); ?>"><code><?php echo escape($key['key_uuid']); ?></code></a></td>
	<td><?php echo $key['username'] ? escape($key['username']."@".$key['domain_name']) : "<b>no user</b>"; ?></td>
	<td><?php echo rest_api_is_true($key['key_enabled']) ? "yes" : "<b>no</b>"; ?></td>
	<td><?php echo empty($key['expires']) ? "never" : escape($key['expires']).($expired ? " <b>expired</b>" : ""); ?></td>
	<td><?php echo escape($key['created']); ?></td>
	<td><?php echo escape($key['last_used']); ?></td>
<?php if($can_delete) { ?>
	<td class="middle button"><?php
		echo button::create(['type'=>'button','icon'=>$_SESSION['theme']['button_icon_delete'] ?? null,'onclick'=>"document.querySelector('#key_uuid').value = '".escape($key['key_uuid'])."'; modal_open('modal-delete','btn_delete');"]);
	?></td>
<?php } ?>
</tr>
<?php
}

echo "</table>";

require_once "footer.php";
```

(`$_SESSION['theme'][...] ?? null` keeps the page free of "undefined array key" warnings when the theme isn't in the session, as in the test login.)

- [ ] **Step 10: Rewrite `key_edit.php`**

```php
<?php
/*
	GNU Public License
	Version: GPL 3
*/
require_once "root.php";
require_once "resources/require.php";
require_once "resources/check_auth.php";
require_once "resources/header.php";
require_once "resources/paging.php";
require_once "lib/auth.php";

function deny_access() {
    echo "permission denied";
    require_once "resources/footer.php";
    die();
}

// a POSTed text field, or "" when it is missing or not a string
function posted($name) {
    return is_string($_POST[$name] ?? null) ? $_POST[$name] : '';
}

if(!permission_exists('rest_api_key_view')) {
    deny_access();
}

$object = new token;
$database = new database;

$key_uuid = null;
$name = "";
$user_uuid = "";
$key_enabled = true;
$expires = "";
$key_secret = null;
$error = null;

if(!empty($_POST)) {
    if(!$object->validate('rest_api_keys')) {
        message::add("invalid token", 'negative');
        header('Location: index.php');
        exit;
    }

    $key_uuid = posted('key_uuid') !== '' ? posted('key_uuid') : null;
    if($key_uuid !== null && !is_uuid($key_uuid)) {
        header('Location: index.php');
        exit;
    }
    if(!permission_exists($key_uuid ? 'rest_api_key_edit' : 'rest_api_key_add')) {
        deny_access();
    }

    $name = posted('name');
    $user_uuid = posted('user_uuid');
    $key_enabled = posted('key_enabled') === 'true';
    $expires = posted('expires');

    // the key acts as this user, so it must exist (#43940)
    $sql = "SELECT user_uuid FROM v_users WHERE user_uuid = :user_uuid";
    if(!is_uuid($user_uuid) || !$database->select($sql, array('user_uuid' => $user_uuid), 'column')) {
        $error = "select the user this key acts as";
    }
    $expires_at = null;
    if($expires !== '') {
        $time = strtotime($expires);
        if($time === false) {
            $error = "invalid expiry date";
        } else {
            $expires_at = date('Y-m-d H:i:s', $time);
        }
    }

    $parameters = array(
        'name' => $name,
        'user_uuid' => $user_uuid,
        'key_enabled' => $key_enabled ? 'true' : 'false',
        'expires' => $expires_at,
    );
    if(!$error && $key_uuid) { // update
        $sql = "UPDATE rest_api_keys SET name = :name, user_uuid = :user_uuid, key_enabled = :key_enabled, expires = :expires WHERE key_uuid = :key_uuid";
        $parameters['key_uuid'] = $key_uuid;
        $database->execute($sql, $parameters);
        header('Location: key_edit.php?key_uuid='.urlencode($key_uuid), false, 302);
        die();
    }
    if(!$error) {
        // a new key. the secret is only shown in this response
        $key_uuid = uuid();
        $key_secret = generate_password(20, 3);
        $sql = "INSERT INTO rest_api_keys (key_uuid, name, key_secret, user_uuid, key_enabled, expires, created) VALUES (:key_uuid, :name, :key_secret, :user_uuid, :key_enabled, :expires, now())";
        $parameters['key_uuid'] = $key_uuid;
        $parameters['key_secret'] = password_hash($key_secret, PASSWORD_DEFAULT, array('cost' => 10));
        $database->execute($sql, $parameters);
    }
    unset($parameters);
} elseif(!empty($_GET['key_uuid'])) {
    if(!is_uuid($_GET['key_uuid'])) {
        header('Location: index.php');
        exit;
    }

    $key_uuid = $_GET['key_uuid'];
    $sql = "SELECT name, user_uuid, key_enabled, expires FROM rest_api_keys WHERE key_uuid = :key_uuid";
    $key = $database->select($sql, array('key_uuid' => $key_uuid), 'row');
    if(!$key) {
        header('Location: index.php');
        exit;
    }
    $name = (string)$key['name'];
    $user_uuid = (string)$key['user_uuid'];
    $key_enabled = rest_api_is_true($key['key_enabled']);
    $expires = empty($key['expires']) ? '' : date('Y-m-d\TH:i', strtotime($key['expires']));
} elseif(!permission_exists('rest_api_key_add')) {
    deny_access();
}

$editable = permission_exists($key_uuid ? 'rest_api_key_edit' : 'rest_api_key_add');
$disabled = $editable ? "" : " disabled='disabled'";

$sql = "SELECT u.user_uuid, u.username, u.user_enabled, d.domain_name";
$sql .= " FROM v_users u LEFT JOIN v_domains d ON d.domain_uuid = u.domain_uuid";
$sql .= " ORDER BY d.domain_name, u.username";
$users = $database->select($sql, null, 'all');

$token = $object->create('rest_api_keys');

if($key_uuid && permission_exists('rest_api_key_delete')) {
    echo "<form method='post' action='index.php'>";
    echo modal::create([
        'id'=>'modal-delete',
        'type'=>'delete',
        'actions'=>button::create([
            'type'=>'submit',
            'label'=>"delete",
            'icon'=>'check',
            'id'=>'btn_delete',
            'style'=>'float: right; margin-left: 15px;',
            'collapse'=>'never',
            'name'=>'action',
            'value'=>'delete',
            'onclick'=>"modal_close();"
        ]
    )]);
    echo "<input type='hidden' name='key_uuid' id='key_uuid' value='".escape($key_uuid)."' />";
    echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>";
    echo "</form>";
}

echo "<form method='post' name='frm' id='frm'>\n";
echo "<input type='hidden' name='key_uuid' value='".escape($key_uuid)."' />";
echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>";

echo "<div class='action_bar' id='action_bar'>\n";
echo "	<div class='heading'><b>REST API Keys</b></div>\n";
echo "	<div class='actions'>\n";
echo button::create(['type'=>'button','label'=>"back",'icon'=>$_SESSION['theme']['button_icon_back'] ?? null,'id'=>'btn_back','style'=>'margin-right: 15px;','link'=>'index.php']);
if($key_uuid && permission_exists('rest_api_key_delete')) {
    echo button::create(['type'=>'button','label'=>'Delete','icon'=>$_SESSION['theme']['button_icon_delete'] ?? null,'onclick'=>"modal_open('modal-delete','btn_delete');"]);
}
if($editable) {
    echo button::create(['type'=>'submit','label'=>"save", 'icon'=>$_SESSION['theme']['button_icon_save'] ?? null,'id'=>'btn_save','style'=>'margin-left: 15px;']);
}
echo "	</div>\n";
echo "	<div style='clear: both;'></div>\n";
echo "</div>\n";
echo "<br /><br />\n";
if($error) {
    echo "<p><b>".escape($error)."</b></p>\n";
}
echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
if($key_secret) {
    $api_token = $key_uuid.":".$key_secret;
?>
    <tr>
        <td width="30%" class="vncellreq" valign="top" align="left" nowrap="nowrap">Secret</td>
        <td width="70%" class="vtable" align="left"><b><code><?php echo escape($api_token); ?></code></b><?php
            echo button::create(['type'=>'button','icon'=>'clipboard', 'onclick'=>'copy("'.escape($api_token).'")']);
        ?><br />will never be shown again</td>
    </tr>
<?php } ?>
    <tr>
        <td width="30%" class="vncellreq" valign="top" align="left" nowrap="nowrap">Name</td>
        <td width="70%" class="vtable" align="left">
            <input class="formfld" type="text" name="name" value="<?php echo escape($name); ?>"<?php echo $disabled; ?> /><br />
        </td>
    </tr>
    <tr>
        <td width="30%" class="vncellreq" valign="top" align="left" nowrap="nowrap">User</td>
        <td width="70%" class="vtable" align="left">
            <select class="formfld" name="user_uuid"<?php echo $disabled; ?>>
                <option value=""></option>
<?php
$user_found = false;
foreach($users as $user) {
    $label = $user['username']."@".$user['domain_name'].(rest_api_is_true($user['user_enabled']) ? "" : " (disabled)");
    $selected = $user['user_uuid'] === $user_uuid ? " selected='selected'" : "";
    $user_found = $user_found || $selected !== "";
    echo "<option value='".escape($user['user_uuid'])."'".$selected.">".escape($label)."</option>\n";
}
?>
            </select><br />
            <?php echo $key_uuid && !$user_found ? "<b>no user</b>: this key can't authenticate until you pick one<br />" : ""; ?>
            requests made with this key act as this user, with the permissions of its groups
        </td>
    </tr>
    <tr>
        <td width="30%" class="vncell" valign="top" align="left" nowrap="nowrap">Enabled</td>
        <td width="70%" class="vtable" align="left">
            <input type="checkbox" name="key_enabled" value="true"<?php echo $key_enabled ? " checked='checked'" : ""; ?><?php echo $disabled; ?> />
        </td>
    </tr>
    <tr>
        <td width="30%" class="vncell" valign="top" align="left" nowrap="nowrap">Expires</td>
        <td width="70%" class="vtable" align="left">
            <input class="formfld" type="datetime-local" name="expires" value="<?php echo escape($expires); ?>"<?php echo $disabled; ?> /><br />
            empty: never expires
        </td>
    </tr>
</table>

</form>
<?php
require_once "footer.php";
```

- [ ] **Step 11: Run the admin tests and the whole suite**

Run: `vendor/bin/phpunit --filter AdminKeysTest`
Expected: PASS (23 tests).

Run: `composer test`
Expected: PASS. `rest.php` still ignores the new columns, so the API tests are unaffected. `SessionIsolationTestCase` only uses `KEY_ID` and `SECRET`.

- [ ] **Step 12: Commit**

```bash
git add app_config.php lib/auth.php index.php key_edit.php tests/Support/RestApiTestCase.php tests/Http/AdminKeysTest.php tests/Unit/AppConfigTest.php tests/Unit/AuthTest.php
git commit -m "#43940 Bind keys to users in the admin pages and add key permissions

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Authenticate keys as their user in `rest.php`

**Files:**
- Modify: `lib/auth.php`
- Modify: `rest.php` (full file below)
- Modify: `tests/Unit/AuthTest.php`
- Modify: `tests/Http/RestAuthenticationTest.php`
- Modify: `tests/Support/SessionIsolationTestCase.php`

**Interfaces:**
- Consumes:
  - from Task 1: `database::new()`, `groups`, `permissions::new()`
  - from Task 2: `rest_api_is_true()` and the `RestApiTestCase` fixtures and helpers
- Produces:
  - `REST_API_DUMMY_HASH` (const)
  - `rest_api_find_key($database, string $key_uuid): array|false`, with keys `key_secret`, `key_enabled`, `expires`, `user_uuid`, `username`, `user_enabled`, `domain_uuid`, `domain_name`, `domain_enabled`
  - `rest_api_key_rejection($key, string $secret, int $now): ?string`, which returns null when the key may be used, else one of: `"unknown key"`, `"invalid secret"`, `"key disabled"`, `"key expired"`, `"key has no user"`, `"user disabled"`, `"domain disabled"`
  - `rest_api_start_user_request(array $key): void`. It sets:
    - `$_SESSION` `domain_uuid`, `domain_name`, `user_uuid` and `username`
    - the globals `$domain_uuid` and `$user_uuid`
    - `database::new()`'s user and domain
    - `$_SESSION['groups']` and `$_SESSION['permissions']`
  - In `rest.php`, after auth: `$key` holds the row from `rest_api_find_key()`. Tasks 4 and 5 use it.

- [ ] **Step 1: Write the failing unit tests**

Append to `tests/Unit/AuthTest.php`, inside the class:

```php
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
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --filter AuthTest`
Expected: ERROR `Call to undefined function rest_api_key_rejection()`.

- [ ] **Step 3: Add the key functions to `lib/auth.php`**

Append to `lib/auth.php`:

```php

// a hash of a random secret, checked when the key doesn't exist so the
// response time doesn't reveal which key IDs exist
const REST_API_DUMMY_HASH = '$2y$10$RMOGGby4/44PfMH0JmbH5e/WVfRdEXEWRh0nwMH3D0qGsNa.RTpVW';

// the key with its user and the user's domain; their columns are null when the
// key has no user or the user was deleted
function rest_api_find_key($database, $key_uuid) {
	$sql = "SELECT k.key_secret, k.key_enabled, k.expires,";
	$sql .= " u.user_uuid, u.username, u.user_enabled,";
	$sql .= " d.domain_uuid, d.domain_name, d.domain_enabled";
	$sql .= " FROM rest_api_keys k";
	$sql .= " LEFT JOIN v_users u ON u.user_uuid = k.user_uuid";
	$sql .= " LEFT JOIN v_domains d ON d.domain_uuid = u.domain_uuid";
	$sql .= " WHERE k.key_uuid = :key_uuid";
	return $database->select($sql, array('key_uuid' => $key_uuid), 'row');
}

// why the key can't be used, for the log, or null when it can
function rest_api_key_rejection($key, $secret, $now) {
	if(!$key) {
		password_verify($secret, REST_API_DUMMY_HASH);
		return "unknown key";
	}
	if(!password_verify($secret, (string)$key['key_secret'])) {
		return "invalid secret";
	}
	if(!rest_api_is_true($key['key_enabled'])) {
		return "key disabled";
	}
	// an expiry that can't be read counts as passed
	if(!empty($key['expires'])) {
		$expires = strtotime($key['expires']);
		if($expires === false || $expires <= $now) {
			return "key expired";
		}
	}
	if(empty($key['user_uuid'])) {
		return "key has no user";
	}
	if(!rest_api_is_true($key['user_enabled'])) {
		return "user disabled";
	}
	if(empty($key['domain_uuid']) || !rest_api_is_true($key['domain_enabled'])) {
		return "domain disabled";
	}
	return null;
}

/**
 * Makes the rest of the request run as the key's user, as FusionPBX does for a
 * logged-in user. Must run before anything checks a permission: FusionPBX 5.6.5
 * keeps the permissions of the first check for the whole request, and its
 * shared database object records the user as insert_user.
 */
function rest_api_start_user_request(array $key) {
	global $database, $domain_uuid, $user_uuid;

	$domain_uuid = $key['domain_uuid'];
	$user_uuid = $key['user_uuid'];
	$_SESSION['domain_uuid'] = $domain_uuid;
	$_SESSION['domain_name'] = $key['domain_name'];
	$_SESSION['user_uuid'] = $user_uuid;
	$_SESSION['username'] = $key['username'];

	$database = database::new(array('user_uuid' => $user_uuid, 'domain_uuid' => $domain_uuid));
	// permissions come from the groups of the user's own domain, whatever domain the request acts on
	(new groups($database, $domain_uuid, $user_uuid))->session();
	permissions::new($database, $domain_uuid, $user_uuid)->session();
}
```

- [ ] **Step 4: Run the unit tests to verify they pass**

Run: `vendor/bin/phpunit --filter AuthTest`
Expected: PASS (27 tests).

- [ ] **Step 5: Write the failing HTTP tests**

In `tests/Http/RestAuthenticationTest.php`, add this method at the top of the class:

```php
	protected static function extraFiles(): array
	{
		return array(
			'app/rest_api/actions/test-whoami.php' => '<?php $required_params = array(); $required_permissions = array(); function do_action($body) { return array("user_uuid" => $_SESSION["user_uuid"] ?? null, "domain_uuid" => $_SESSION["domain_uuid"] ?? null, "domain_name" => $_SESSION["domain_name"] ?? null, "in_group" => if_group("api_integration"), "can_add_extensions" => permission_exists("extension_add"), "can_select_domains" => permission_exists("domain_select")); }',
		);
	}
```

Then add these methods to the class:

```php
	public static function unusableKeys(): array
	{
		// table => changes for every row, or null to delete every row
		return array(
			'key without user' => array('rest_api_keys', array('user_uuid' => null)),
			'key whose user was deleted' => array('v_users', null),
			'disabled key' => array('rest_api_keys', array('key_enabled' => 'false')),
			'key from before the upgrade' => array('rest_api_keys', array('key_enabled' => null, 'user_uuid' => null)),
			'expired key' => array('rest_api_keys', array('expires' => '2020-01-01 00:00:00+00')),
			'disabled user' => array('v_users', array('user_enabled' => 'false')),
			'disabled domain' => array('v_domains', array('domain_enabled' => 'false')),
			'user whose domain was deleted' => array('v_domains', null),
		);
	}

	#[DataProvider('unusableKeys')]
	public function testRejectsKeysThatCannotActAsAnEnabledUser(string $table, ?array $changes): void
	{
		\FakeStore::update(function (&$state) use ($table, $changes) {
			foreach ($state['tables'][$table] as $i => $row) {
				if ($changes === null) {
					unset($state['tables'][$table][$i]);
				} else {
					$state['tables'][$table][$i] = array_merge($row, $changes);
				}
			}
			$state['tables'][$table] = array_values($state['tables'][$table]);
		});

		$response = $this->api(self::LOOKUP);

		$this->assertSame(401, $response['status']);
		$this->assertSame(array('error' => 'unauthorized'), $this->json($response));
		$this->assertNull($this->state()['tables']['rest_api_keys'][0]['last_used']);
	}

	public function testAcceptsAKeyThatExpiresLater(): void
	{
		$this->updateRows('rest_api_keys', array('key_uuid' => self::KEY_ID), array('expires' => '2099-01-01 00:00:00+00'));

		$this->assertSame(200, $this->api(self::LOOKUP)['status']);
	}

	public function testRunsTheRequestAsTheKeysUser(): void
	{
		$response = $this->api(array('action' => 'test-whoami'));

		$this->assertSame(array(
			'user_uuid' => self::USER_UUID,
			'domain_uuid' => self::DOMAIN_UUID,
			'domain_name' => 'tenant1.example.com',
			'in_group' => true,
			'can_add_extensions' => true,
			'can_select_domains' => false,
		), $this->json($response));
	}

	public function testRecordsTheKeysUserOnCreatedRecords(): void
	{
		$response = $this->api(array('action' => 'extension-create', 'domain_uuid' => self::DOMAIN_UUID, 'extension' => '150'));

		$this->assertSame(200, $response['status']);
		$this->assertSame(self::USER_UUID, $this->state()['tables']['v_extensions'][0]['insert_user']);
	}

	public function testADomainChangeInTheQueryStringDoesNotDropTheUsersPermissions(): void
	{
		$response = $this->request('POST', '/app/rest_api/rest.php?domain_change=true&domain_uuid='.self::OTHER_DOMAIN_UUID, json_encode(array('action' => 'test-whoami')), array(
			'Authorization' => 'Basic '.base64_encode(self::KEY_ID.':'.self::SECRET),
		));

		$this->assertTrue($this->json($response)['can_add_extensions']);
		$this->assertSame(self::DOMAIN_UUID, $this->json($response)['domain_uuid']);
	}
```

In `tests/Support/SessionIsolationTestCase.php`:

(a) Replace the `test-session` action contents with:

```php
			'app/rest_api/actions/test-session.php' => '<?php $required_params = array(); $required_permissions = array(); function do_action($body) { return array("user_uuid" => $_SESSION["user_uuid"] ?? null, "permissions" => array_keys($_SESSION["permissions"] ?? array())); }',
```

(b) Replace `testActionsDoNotSeeTheBrowserSession` with:

```php
	public function testActionsSeeTheKeysUserNotTheBrowserSession(): void
	{
		$response = $this->apiWithBrowserSession(array('action' => 'test-session'));

		$this->assertSame(self::USER_UUID, $this->json($response)['user_uuid']);
		$this->assertEqualsCanonicalizing(self::ACTION_PERMISSIONS, $this->json($response)['permissions']);
	}
```

(c) Rename `testPermissionsGrantedByAnActionDoNotReachTheBrowserSession` to `testCreatingRecordsDoesNotChangeTheBrowserSession`. Leave its body unchanged.

- [ ] **Step 6: Run them to verify they fail**

Run: `vendor/bin/phpunit --filter 'RestAuthenticationTest|SessionIsolation'`
Expected: FAIL. The unusable keys get 200, and `test-whoami` reports a null user.

- [ ] **Step 7: Rewrite `rest.php`**

Replace `rest.php` with:

```php
<?php
// API requests must never read or write a FusionPBX browser session, so
// anything written to $_SESSION here only lives for this request.
// close a session started by session.auto_start without saving it: keep a
// browser user's session as it was, discard a new empty one and its cookie
if(session_status() === PHP_SESSION_ACTIVE) {
	if(empty($_SESSION)) {
		session_destroy();
	} else {
		session_abort();
	}
	header_remove('Set-Cookie');
}
$_SESSION = array();

// FusionPBX's require.php switches domains when the query string asks for it.
// that checks a permission before we know the key's user, and FusionPBX keeps
// the (empty) result for the whole request. the API only reads the JSON body
$_GET = array();

// ask require.php not to start a session. in case it starts one anyway, make
// sure it can't resume a browser session from its cookie or send a cookie
$no_session = true;
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');

require_once "root.php";
require_once "resources/require.php";
require_once "lib/input_validation.php";
require_once "lib/auth.php";

// whatever require.php did, never save the session. cookies are disabled, so
// any session started from here on is a new, empty one and safe to destroy
if(session_status() === PHP_SESSION_ACTIVE) {
	session_destroy();
}
register_shutdown_function(function() {
	if(session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
});

function return_error($msg, $code=500) {
	http_response_code($code);
	echo json_encode(array("error" => $msg));
	die();
}

if(!isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
	error_log("rejecting request with no auth");
	return_error("unauthorized", 401);
}

if(!is_uuid($_SERVER['PHP_AUTH_USER'])) {
	error_log("rejecting request with malformed token identifier");
	return_error("unauthorized", 401);
}

// the key must be enabled, not expired, and bound to an enabled user of an enabled domain
$key = rest_api_find_key(database::new(), $_SERVER['PHP_AUTH_USER']);
$rejection = rest_api_key_rejection($key, $_SERVER['PHP_AUTH_PW'], time());
if($rejection !== null) {
	error_log("rejecting request: ".$rejection." (".$_SERVER['PHP_AUTH_USER'].")");
	return_error("unauthorized", 401);
}

// from here on the request runs as the key's user
rest_api_start_user_request($key);

// set the key last used time
$sql = "UPDATE rest_api_keys SET last_used = NOW() WHERE key_uuid = :key_id";
database::new()->execute($sql, array('key_id' => $_SERVER['PHP_AUTH_USER']));

$body = json_decode(file_get_contents('php://input'));
if(!is_object($body)) {
	return_error("request body must be a JSON object", 400);
}

$validation_errors = ensure_parameters($body, array("action"));
if($validation_errors) {
	return_error($validation_errors, 400);
}

// action and app names end up in include paths, so only allow plain names
$action = is_string($body->action) ? strtolower($body->action) : "";
if(!preg_match('/^[a-z0-9-]+$/D', $action)) {
	return_error("unknown action", 400);
}
$file = __DIR__."/actions/".$action.".php";
if(!empty($body->app)) {
	$app = $body->app;
	if(!is_string($app) || !preg_match('/^[a-z0-9_]+$/D', $app)) {
		return_error("unknown app", 400);
	}
	$app_dir = realpath(__DIR__."/../".$app);
	$app_index = $app_dir."/app_api.php";
	if(!$app_dir || !file_exists($app_index)) {
		return_error("unknown app", 400);
	}
	include($app_index);
	if(!empty($app_api[$app][$action])) {
		// the app's own mapping must not point outside the app directory
		$file = realpath($app_dir."/".$app_api[$app][$action]);
		if(!$file || strpos($file, $app_dir."/") !== 0) {
			return_error("unknown action", 400);
		}
	}
}

if(!file_exists($file)) {
	return_error("unknown action", 400);
}

include($file);
$validation_errors = ensure_parameters($body, $required_params);
if($validation_errors) {
	return_error($validation_errors, 400);
}

if(function_exists('do_action')) {
	$resp = do_action($body);
	if(!empty($resp['code'])) {
		http_response_code($resp['code']);
		unset($resp['code']);
	} elseif(!empty($resp['error'])) {
		http_response_code(500);
	}

	echo json_encode($resp);
}
```

- [ ] **Step 8: Run the HTTP tests and the whole suite**

Run: `vendor/bin/phpunit --filter 'RestAuthenticationTest|SessionIsolation'`
Expected: PASS.

Run: `composer test`
Expected: PASS. The actions still write `$_SESSION["permissions"]`, but those writes no longer have any effect: the permissions singleton is already built from the user's group, which has every permission the actions need.

- [ ] **Step 9: Commit**

```bash
git add lib/auth.php rest.php tests/Unit/AuthTest.php tests/Http/RestAuthenticationTest.php tests/Support/SessionIsolationTestCase.php
git commit -m "#43940 Authenticate API keys as their FusionPBX user

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Every action declares and gets checked for its permissions

**Files:**
- Modify: `actions/cdr-list.php`, `actions/destination-create.php`, `actions/destination-details.php`, `actions/domain-details.php`, `actions/extension-create.php`, `actions/extension-details.php`, `actions/extension-list.php`, `actions/originate.php`, `actions/ringgroup-create.php`
- Modify: `lib/auth.php`
- Modify: `rest.php`
- Modify: `tests/Support/ActionTestCase.php`
- Modify: `tests/Unit/Actions/DestinationCreateTest.php`, `tests/Unit/Actions/ExtensionCreateTest.php`, `tests/Unit/Actions/RingGroupCreateTest.php`
- Modify: `tests/Http/RestRoutingTest.php`
- Create: `tests/Unit/ActionDeclarationsTest.php`
- Create: `tests/Http/RestPermissionsTest.php`

**Interfaces:**
- Consumes (Task 3): `rest_api_start_user_request()` has already loaded the user's permissions.
- Produces:
  - `rest_api_missing_permissions(array $required): array` (the names the user lacks, in order)
  - Each action file sets `$required_permissions` (array)
  - `ActionTestCase::$requiredPermissions` (array) and `ActionTestCase::grantOnly(array $permissions): void`. `setUp()` grants exactly the declared permissions.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/ActionDeclarationsTest.php`:

```php
<?php
namespace RestApi\Test\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Every action declares the FusionPBX permissions rest.php checks before running
 * it (#43940). FusionPBX's save() silently skips tables the user can't add to,
 * so the list must cover every table the action saves.
 */
#[RunTestsInSeparateProcesses]
class ActionDeclarationsTest extends TestCase
{
	private const PERMISSIONS = array(
		'cdr-list' => array('xml_cdr_view'),
		'destination-create' => array('destination_add', 'dialplan_add', 'dialplan_detail_add'),
		'destination-details' => array('destination_view'),
		'domain-details' => array(),
		'extension-create' => array('extension_add', 'voicemail_add'),
		'extension-details' => array('extension_view'),
		'extension-list' => array('extension_view'),
		'originate' => array('click_to_call_call'),
		'ringgroup-create' => array('ring_group_add', 'ring_group_destination_add', 'dialplan_add'),
	);

	public static function actions(): array
	{
		$actions = array();
		foreach (self::PERMISSIONS as $action => $permissions) {
			$actions[$action] = array($action, $permissions);
		}
		return $actions;
	}

	#[DataProvider('actions')]
	public function testDeclaresTheFusionPbxPermissionsItNeeds(string $action, array $expected): void
	{
		require PLUGIN_DIR.'/actions/'.$action.'.php';

		$this->assertTrue(isset($required_permissions), $action.' does not declare $required_permissions');
		$this->assertSame($expected, $required_permissions);
	}

	public function testEveryActionIsListed(): void
	{
		$files = array_map(function ($file) { return basename($file, '.php'); }, glob(PLUGIN_DIR.'/actions/*.php'));
		sort($files);

		$this->assertSame(array_keys(self::PERMISSIONS), $files);
	}

	public function testNoActionGrantsItselfPermissions(): void
	{
		foreach (glob(PLUGIN_DIR.'/actions/*.php') as $file) {
			$this->assertDoesNotMatchRegularExpression('/\$_SESSION\s*\[\s*[\'"]permissions[\'"]\s*\]/', file_get_contents($file), basename($file));
		}
	}
}
```

Create `tests/Http/RestPermissionsTest.php`:

```php
<?php
namespace RestApi\Test\Http;

use RestApi\Test\Support\RestApiTestCase;

/**
 * Actions run with the FusionPBX permissions of the key's user (#43940).
 */
class RestPermissionsTest extends RestApiTestCase
{
	protected static function extraFiles(): array
	{
		return array(
			'app/rest_api/actions/test-undeclared.php' => '<?php $required_params = array(); function do_action($body) { return array("ran" => true); }',
			'app/legacy/app_api.php' => '<?php $app_api["legacy"]["old"] = "api/old.php";',
			'app/legacy/api/old.php' => '<?php $required_params = array(); function do_action($body) { return array("ran" => true); }',
			// an app's app_api.php must not declare permissions for the action it maps
			'app/leaky/app_api.php' => '<?php $required_permissions = array(); $app_api["leaky"]["leak"] = "api/leak.php";',
			'app/leaky/api/leak.php' => '<?php $required_params = array(); function do_action($body) { return array("ran" => true); }',
		);
	}

	public function testSavesEveryTableWithTheUsersPermissions(): void
	{
		$response = $this->api(array('action' => 'extension-create', 'domain_uuid' => self::DOMAIN_UUID, 'extension' => '150'));

		$this->assertSame(200, $response['status']);
		$this->assertSame(array(), $this->state()['skipped']);
		$this->assertCount(1, $this->state()['tables']['v_voicemails']);
	}

	public function testRejectsAnActionWhenAPermissionIsMissing(): void
	{
		$this->grantOnly(array('extension_add'));

		$response = $this->api(array('action' => 'extension-create', 'domain_uuid' => self::DOMAIN_UUID, 'extension' => '150'));

		$this->assertSame(403, $response['status']);
		$this->assertSame(array('error' => 'forbidden', 'missing_permissions' => array('voicemail_add')), $this->json($response));
		$this->assertArrayNotHasKey('v_extensions', $this->state()['tables']);
	}

	public function testChecksPermissionsBeforeParameters(): void
	{
		$this->grantOnly(array());

		$response = $this->api(array('action' => 'originate', 'domain_uuid' => self::DOMAIN_UUID));

		$this->assertSame(403, $response['status']);
		$this->assertSame(array('click_to_call_call'), $this->json($response)['missing_permissions']);
	}

	public function testActionsWithoutPermissionsOnlyNeedAUsableKey(): void
	{
		$this->grantOnly(array());

		$this->assertSame(200, $this->api(array('action' => 'domain-details', 'domain_name' => 'tenant1.example.com'))['status']);
	}

	public function testRefusesToRunAnActionThatDoesNotDeclareItsPermissions(): void
	{
		foreach (array(array('action' => 'test-undeclared'), array('action' => 'old', 'app' => 'legacy'), array('action' => 'leak', 'app' => 'leaky')) as $body) {
			$response = $this->api($body);

			$this->assertSame(500, $response['status'], json_encode($body));
			$this->assertSame(array('error' => 'action does not declare permissions'), $this->json($response));
		}
	}
}
```

In `tests/Http/RestRoutingTest.php`, replace the `call_stats` action line with:

```php
			'app/call_stats/api/stats.php' => '<?php $required_params = array(); $required_permissions = array(); function do_action($body) { return array("calls" => 42); }',
```

In `tests/Support/ActionTestCase.php`, replace `setUp()` with the code below and add `grantOnly()` and the property:

```php
	/** Permissions the action declares. setUp() grants exactly these. */
	protected array $requiredPermissions = array();

	protected function setUp(): void
	{
		require_once FUSIONPBX_FAKES_DIR.'/resources/fakes.php';
		require_once PLUGIN_DIR.'/lib/input_validation.php';
		$_SESSION = array();
		FakeStore::reset($this->tables());
		require PLUGIN_DIR.'/actions/'.$this->action().'.php';
		$this->requiredPermissions = $required_permissions ?? array();
		$this->grantOnly($this->requiredPermissions);
	}

	/**
	 * Give the user exactly these permissions. FusionPBX keeps the permissions
	 * of the first check, so call this before running the action.
	 */
	protected function grantOnly(array $permissions): void
	{
		$_SESSION['permissions'] = array_fill_keys($permissions, true);
	}
```

In each of `DestinationCreateTest::testRoutesTheNumberToTheExtensionInItsDomain`, `ExtensionCreateTest::testCreatesTheExtensionWithVoicemailAndCallerId` and `RingGroupCreateTest::testCreatesTheRingGroupWithItsDestinationsAndDialplan`, add as the last line:

```php
		$this->assertSame(array(), $this->state()['skipped'], 'the declared permissions must cover every saved table');
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --filter 'ActionDeclarationsTest|RestPermissionsTest|CreateTest'`
Expected: FAIL in `ActionDeclarationsTest` (no action declares `$required_permissions`; the session-permission scan finds 8 writes) and `RestPermissionsTest` (undeclared actions run; the missing-permission request is not rejected). The three create tests may still pass at this point: the session writes inside the actions happen before the first permission check, so the permissions singleton picks them up. Their new `skipped` assertion guards Step 3, where those writes are removed.

- [ ] **Step 3: Declare the permissions in each action and remove the session writes**

Add a `$required_permissions` line directly after the `$required_params` line in each file:

| File | Line to add |
|---|---|
| `actions/cdr-list.php` | `$required_permissions = array("xml_cdr_view");` |
| `actions/destination-create.php` | `$required_permissions = array("destination_add", "dialplan_add", "dialplan_detail_add");` |
| `actions/destination-details.php` | `$required_permissions = array("destination_view");` |
| `actions/domain-details.php` | `$required_permissions = array();` |
| `actions/extension-create.php` | `$required_permissions = array("extension_add", "voicemail_add");` |
| `actions/extension-details.php` | `$required_permissions = array("extension_view");` |
| `actions/extension-list.php` | `$required_permissions = array("extension_view");` |
| `actions/originate.php` | `$required_permissions = array("click_to_call_call");` |
| `actions/ringgroup-create.php` | `$required_permissions = array("ring_group_add", "ring_group_destination_add", "dialplan_add");` |

Delete these lines, including the blank line after each group:
- `actions/destination-create.php:108-110` (`$_SESSION["permissions"]["dialplan_detail_add"]`, `["dialplan_add"]`, `["destination_add"]`)
- `actions/extension-create.php:87-88` (`["extension_add"]`, `["voicemail_add"]`)
- `actions/ringgroup-create.php:108-110` (`["ring_group_add"]`, `["ring_group_destination_add"]`, `["dialplan_add"]`)

- [ ] **Step 4: Add `rest_api_missing_permissions` to `lib/auth.php`**

Append:

```php

// the permissions in $required that the request's user doesn't have
function rest_api_missing_permissions(array $required) {
	$missing = array();
	foreach($required as $permission) {
		if(!permission_exists($permission)) {
			$missing[] = $permission;
		}
	}
	return $missing;
}
```

- [ ] **Step 5: Check the declared permissions in `rest.php`**

In `rest.php`, replace:

```php
include($file);
$validation_errors = ensure_parameters($body, $required_params);
```

with:

```php
// only the action file can declare what it needs: anything set before, e.g.
// by an app's app_api.php, doesn't count. undeclared actions never run
$required_permissions = null;
include($file);
if(!is_array($required_permissions)) {
	error_log("refusing to run action ".$action.": it does not declare \$required_permissions");
	return_error("action does not declare permissions", 500);
}

// checked before the parameters, so callers without access don't learn what an action expects
$missing_permissions = rest_api_missing_permissions($required_permissions);
if($missing_permissions) {
	http_response_code(403);
	echo json_encode(array("error" => "forbidden", "missing_permissions" => $missing_permissions));
	die();
}

$validation_errors = ensure_parameters($body, $required_params);
```

- [ ] **Step 6: Run the tests and the whole suite**

Run: `vendor/bin/phpunit --filter 'ActionDeclarationsTest|RestPermissionsTest|CreateTest'`
Expected: PASS.

Run: `composer test`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add actions lib/auth.php rest.php tests/Support/ActionTestCase.php tests/Unit/ActionDeclarationsTest.php tests/Unit/Actions tests/Http/RestPermissionsTest.php tests/Http/RestRoutingTest.php
git commit -m "#43940 Check each action's FusionPBX permissions instead of granting them

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Scope requests to the user's domain

**Files:**
- Modify: `lib/auth.php`
- Modify: `rest.php`
- Modify: `actions/cdr-list.php`, `actions/destination-create.php`, `actions/extension-create.php`, `actions/extension-details.php`, `actions/extension-list.php`, `actions/originate.php`, `actions/ringgroup-create.php` (only `$required_params`)
- Modify: `actions/destination-details.php`, `actions/domain-details.php` (full files below)
- Modify: `tests/Support/ActionTestCase.php`
- Modify: `tests/Unit/Actions/ListActionsTest.php`
- Create: `tests/Http/RestDomainScopeTest.php`

**Interfaces:**
- Consumes:
  - from Task 3: `$key` in `rest.php`
  - from Task 4: the `rest.php` block that checks permissions
- Produces:
  - `rest_api_resolve_domain($body, array $key): array`. It returns the context `array('domain_explicit' => bool, 'cross_domain' => bool, 'user_domain_uuid' => string)`, or `array('error' => string, 'code' => int)`. It sets `$body->domain_uuid` (lowercase, or the user's domain).
  - `do_action($body, $context)` is called with that context. Actions that use it declare `function do_action($body, $context = array())`.
  - `ActionTestCase::runAction(array $body, array $context = array())`. Defaults: `domain_explicit` true, `cross_domain` false, `user_domain_uuid` `aaaaaaaa-0000-4000-8000-000000000001`.

- [ ] **Step 1: Write the failing HTTP tests**

Create `tests/Http/RestDomainScopeTest.php`:

```php
<?php
namespace RestApi\Test\Http;

use RestApi\Test\Support\RestApiTestCase;

/**
 * Requests act on the key user's domain, or on another domain when the user
 * has domain_select (#43940).
 */
class RestDomainScopeTest extends RestApiTestCase
{
	private const MISSING_DOMAIN = 'aaaaaaaa-0000-4000-8000-00000000ffff';

	protected function tables(): array
	{
		return parent::tables() + array(
			'v_extensions' => array(
				array('extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000100', 'domain_uuid' => self::DOMAIN_UUID, 'extension' => '100', 'emergency_caller_id_number' => ''),
				array('extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000200', 'domain_uuid' => self::OTHER_DOMAIN_UUID, 'extension' => '200', 'emergency_caller_id_number' => ''),
			),
			'v_destinations' => array(
				array('destination_uuid' => 'bbbbbbbb-0000-4000-8000-000000000002', 'domain_uuid' => self::OTHER_DOMAIN_UUID, 'destination_number' => '5552000', 'destination_actions' => null),
			),
		);
	}

	private function allowOtherDomains(): void
	{
		$this->grantOnly(array_merge(self::ACTION_PERMISSIONS, array('domain_select')));
	}

	private function extensions(array $response): array
	{
		return array_column($this->json($response), 'extension');
	}

	public function testActsOnTheUsersDomainByDefault(): void
	{
		$response = $this->api(array('action' => 'extension-list'));

		$this->assertSame(200, $response['status']);
		$this->assertSame(array('100'), $this->extensions($response));
	}

	public function testAcceptsTheUsersOwnDomainInAnyCase(): void
	{
		$response = $this->api(array('action' => 'extension-list', 'domain_uuid' => strtoupper(self::DOMAIN_UUID)));

		$this->assertSame(200, $response['status']);
		$this->assertSame(array('100'), $this->extensions($response));
	}

	public function testRejectsAnotherDomainWithoutDomainSelect(): void
	{
		foreach (array(self::OTHER_DOMAIN_UUID, self::MISSING_DOMAIN) as $domain_uuid) {
			$response = $this->api(array('action' => 'extension-list', 'domain_uuid' => $domain_uuid));

			$this->assertSame(403, $response['status'], $domain_uuid);
			$this->assertSame(array('error' => 'forbidden'), $this->json($response));
		}
	}

	public function testActsOnAnotherDomainWithDomainSelect(): void
	{
		$this->allowOtherDomains();

		$response = $this->api(array('action' => 'extension-list', 'domain_uuid' => self::OTHER_DOMAIN_UUID));

		$this->assertSame(array('200'), $this->extensions($response));
	}

	public function testReportsAMissingOrDisabledDomainToUsersWithDomainSelect(): void
	{
		$this->allowOtherDomains();
		$this->updateRows('v_domains', array('domain_uuid' => self::OTHER_DOMAIN_UUID), array('domain_enabled' => 'false'));

		foreach (array(self::OTHER_DOMAIN_UUID, self::MISSING_DOMAIN) as $domain_uuid) {
			$response = $this->api(array('action' => 'extension-list', 'domain_uuid' => $domain_uuid));

			$this->assertSame(404, $response['status'], $domain_uuid);
			$this->assertSame(array('error' => 'domain not found'), $this->json($response));
		}
	}

	public function testRejectsADomainUuidThatIsNotAUuid(): void
	{
		foreach (array('tenant2', array(self::OTHER_DOMAIN_UUID), 42) as $domain_uuid) {
			$response = $this->api(array('action' => 'extension-list', 'domain_uuid' => $domain_uuid));

			$this->assertSame(400, $response['status'], json_encode($domain_uuid));
			$this->assertSame(array('error' => 'invalid domain_uuid'), $this->json($response));
		}
	}

	public function testDomainDetailsReturnsTheUsersDomainByDefault(): void
	{
		$response = $this->api(array('action' => 'domain-details'));

		$this->assertSame(200, $response['status']);
		$this->assertSame('tenant1.example.com', $this->json($response)['domain_name']);
	}

	public function testDomainDetailsOnlyFindsOtherDomainsByNameWithDomainSelect(): void
	{
		$lookup = array('action' => 'domain-details', 'domain_name' => 'tenant2.example.com');

		$response = $this->api($lookup);
		$this->assertSame(404, $response['status']);
		$this->assertSame(array('error' => 'domain not found'), $this->json($response));

		$this->allowOtherDomains();
		$this->assertSame(self::OTHER_DOMAIN_UUID, $this->json($this->api($lookup))['domain_uuid']);
	}

	public function testDomainDetailsRejectsANameThatIsNotAString(): void
	{
		$response = $this->api(array('action' => 'domain-details', 'domain_name' => array('tenant1.example.com')));

		$this->assertSame(404, $response['status']);
	}

	public function testDestinationDetailsSearchesAllDomainsOnlyForDomainSelectWithoutADomain(): void
	{
		$lookup = array('action' => 'destination-details', 'number' => '5552000');

		$this->assertSame(404, $this->api($lookup)['status']);

		$this->allowOtherDomains();
		$this->assertSame('bbbbbbbb-0000-4000-8000-000000000002', $this->json($this->api($lookup))['destination_uuid']);
		$this->assertSame(404, $this->api($lookup + array('domain_uuid' => self::DOMAIN_UUID))['status']);
	}
}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --filter RestDomainScopeTest`
Expected: FAIL. `extension-list` without `domain_uuid` returns 400 (missing parameter), other domains return 200, and so on.

- [ ] **Step 3: Add `rest_api_resolve_domain` to `lib/auth.php`**

Append:

```php

/**
 * Picks the domain the request acts on and checks the user may act on it.
 * Sets $body->domain_uuid, to the user's own domain when the request has none.
 * Returns the context passed to do_action(), or array("error" => ..., "code" => ...).
 */
function rest_api_resolve_domain($body, array $key) {
	$context = array(
		'domain_explicit' => !empty($body->domain_uuid),
		'cross_domain' => permission_exists('domain_select'),
		'user_domain_uuid' => $key['domain_uuid'],
	);
	if(!$context['domain_explicit']) {
		$body->domain_uuid = $key['domain_uuid'];
		return $context;
	}
	if(!is_uuid($body->domain_uuid)) {
		return array("error" => "invalid domain_uuid", "code" => 400);
	}
	$body->domain_uuid = strtolower($body->domain_uuid);
	if($body->domain_uuid === strtolower($key['domain_uuid'])) {
		return $context;
	}
	// before looking the domain up, so the answer doesn't reveal whether it exists
	if(!$context['cross_domain']) {
		return array("error" => "forbidden", "code" => 403);
	}
	$sql = "SELECT domain_enabled FROM v_domains WHERE domain_uuid = :domain_uuid";
	$enabled = database::new()->select($sql, array('domain_uuid' => $body->domain_uuid), 'column');
	if(!rest_api_is_true($enabled)) {
		return array("error" => "domain not found", "code" => 404);
	}
	return $context;
}
```

- [ ] **Step 4: Resolve the domain in `rest.php` and pass the context**

In `rest.php`, replace:

```php
// checked before the parameters, so callers without access don't learn what an action expects
$missing_permissions = rest_api_missing_permissions($required_permissions);
```

with:

```php
$context = rest_api_resolve_domain($body, $key);
if(isset($context['error'])) {
	return_error($context['error'], $context['code']);
}

// checked before the parameters, so callers without access don't learn what an action expects
$missing_permissions = rest_api_missing_permissions($required_permissions);
```

Replace `$resp = do_action($body);` with:

```php
	// actions declared as do_action($body) just ignore the context
	$resp = do_action($body, $context);
```

- [ ] **Step 5: Drop `domain_uuid` from `$required_params`**

`rest.php` always fills in `domain_uuid` now. Change the `$required_params` lines:

| File | New line |
|---|---|
| `actions/cdr-list.php` | `$required_params = array();` |
| `actions/destination-create.php` | `$required_params = array("number", "extension");` |
| `actions/extension-create.php` | `$required_params = array("extension");` |
| `actions/extension-details.php` | `$required_params = array("extension_uuid");` |
| `actions/extension-list.php` | `$required_params = array();` |
| `actions/originate.php` | `$required_params = array("caller_id_number", "destination_a", "destination_b");` |
| `actions/ringgroup-create.php` | `$required_params = array("name", "extension", "destinations", "strategy");` |

- [ ] **Step 6: Scope `destination-details` and `domain-details`**

Replace `actions/destination-details.php` with:

```php
<?php
$required_params = array("number");
$required_permissions = array("destination_view");

function do_action($body, $context = array()) {
    $sql = "SELECT * FROM v_destinations WHERE destination_number = :number";
    $parameters['number'] = $body->number;
    // a user who may act on every domain can look a number up without knowing
    // its domain. everyone else only finds numbers of the domain they act on
    if(!empty($context['domain_explicit']) || empty($context['cross_domain'])) {
        $sql .= " AND domain_uuid = :domain_uuid";
        $parameters['domain_uuid'] = $body->domain_uuid;
    }
    $database = new database;
    $extension = $database->select($sql, $parameters, 'row');
    if(!$extension) {
        return array("error" => "no such destination", "code" => 404);
    }

    // destination_actions is JSON-encoded in the DB. parse it here (#5)
    if($extension['destination_actions']) {
        $extension['destination_actions'] = json_decode($extension['destination_actions']);
    }

    return $extension;
}
```

Replace `actions/domain-details.php` with:

```php
<?php
$required_params = array();
$required_permissions = array();

function do_action($body, $context = array()) {
    $database = new database;
    if(empty($context['domain_explicit']) && !empty($body->domain_name)) {
        if(!is_string($body->domain_name)) {
            return array("error" => "domain not found", "code" => 404);
        }
        $sql = "SELECT * FROM v_domains WHERE domain_name = :domain_name";
        $domain = $database->select($sql, array('domain_name' => $body->domain_name), 'row');
        // rest.php only checked the domain_uuid it filled in. a named domain the
        // user may not act on is "not found" rather than forbidden, so names
        // don't reveal which domains exist
        if($domain && $domain['domain_uuid'] !== ($context['user_domain_uuid'] ?? null) && empty($context['cross_domain'])) {
            $domain = false;
        }
    } else {
        $sql = "SELECT * FROM v_domains WHERE domain_uuid = :domain_uuid";
        $domain = $database->select($sql, array('domain_uuid' => $body->domain_uuid), 'row');
    }
    if(!$domain) {
        return array("error" => "domain not found", "code" => 404);
    }
    return $domain;
}
```

- [ ] **Step 7: Pass a context in the action unit tests**

In `tests/Support/ActionTestCase.php`, add the constant `protected const DOMAIN_UUID = 'aaaaaaaa-0000-4000-8000-000000000001';` at the top of the class. Then replace `runAction()` with:

```php
	/** Run the action as rest.php does, with the context from rest_api_resolve_domain(). */
	protected function runAction(array $body, array $context = array())
	{
		return do_action((object)$body, $context + array(
			'domain_explicit' => true,
			'cross_domain' => false,
			'user_domain_uuid' => self::DOMAIN_UUID,
		));
	}
```

In `tests/Unit/Actions/ListActionsTest.php`:

(a) Replace the `v_destinations` fixture with:

```php
			'v_destinations' => array(
				array('destination_uuid' => 'bbbbbbbb-0000-4000-8000-000000000001', 'domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'destination_number' => '5551234', 'destination_actions' => '[{"destination_app":"transfer","destination_data":"100 XML tenant1.example.com"}]'),
				array('destination_uuid' => 'bbbbbbbb-0000-4000-8000-000000000002', 'domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000002', 'destination_number' => '5552000', 'destination_actions' => null),
			),
```

(b) Replace the four `testDomainDetails*` methods and the two `testDestinationDetails*` methods with:

```php
	public function testDomainDetailsFindsTheUsersDomainByName(): void
	{
		$this->load('domain-details');

		$result = $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'domain_name' => 'tenant1.example.com'), array('domain_explicit' => false));

		$this->assertSame(self::DOMAIN_UUID, $result['domain_uuid']);
	}

	public function testDomainDetailsHidesAnotherDomainByName(): void
	{
		$this->load('domain-details');

		$result = $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'domain_name' => 'tenant2.example.com'), array('domain_explicit' => false));

		$this->assertSame(array('error' => 'domain not found', 'code' => 404), $result);
	}

	public function testDomainDetailsFindsAnotherDomainByNameWithDomainSelect(): void
	{
		$this->load('domain-details');

		$result = $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'domain_name' => 'tenant2.example.com'), array('domain_explicit' => false, 'cross_domain' => true));

		$this->assertSame('aaaaaaaa-0000-4000-8000-000000000002', $result['domain_uuid']);
	}

	public function testDomainDetailsFindsADomainByUuid(): void
	{
		$this->load('domain-details');

		$result = $this->runAction(array('domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000002', 'domain_name' => 'tenant1.example.com'), array('cross_domain' => true));

		$this->assertSame('tenant2.example.com', $result['domain_name']);
	}

	public function testDomainDetailsReturnsTheActingDomainWithoutAName(): void
	{
		$this->load('domain-details');

		$result = $this->runAction(array('domain_uuid' => self::DOMAIN_UUID), array('domain_explicit' => false));

		$this->assertSame('tenant1.example.com', $result['domain_name']);
	}

	public function testDomainDetailsReportsAnUnknownName(): void
	{
		$this->load('domain-details');

		$this->assertSame(404, $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'domain_name' => 'unknown.example.com'), array('domain_explicit' => false))['code']);
	}

	public function testDomainDetailsRejectsANameThatIsNotAString(): void
	{
		$this->load('domain-details');

		$this->assertSame(404, $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'domain_name' => array('tenant1.example.com')), array('domain_explicit' => false))['code']);
	}

	public function testDestinationDetailsDecodesTheDestinationActions(): void
	{
		$this->load('destination-details');

		$result = $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'number' => '5551234'));

		$this->assertEquals(array((object)array('destination_app' => 'transfer', 'destination_data' => '100 XML tenant1.example.com')), $result['destination_actions']);
	}

	public function testDestinationDetailsOnlySearchesTheActingDomain(): void
	{
		$this->load('destination-details');

		$this->assertSame(404, $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'number' => '5552000'), array('domain_explicit' => false))['code']);
	}

	public function testDestinationDetailsSearchesEveryDomainForDomainSelectWithoutADomain(): void
	{
		$this->load('destination-details');

		$result = $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'number' => '5552000'), array('domain_explicit' => false, 'cross_domain' => true));

		$this->assertSame('bbbbbbbb-0000-4000-8000-000000000002', $result['destination_uuid']);
	}

	public function testDestinationDetailsReportsAnUnknownNumber(): void
	{
		$this->load('destination-details');

		$this->assertSame(404, $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'number' => '5550000'))['code']);
	}
```

- [ ] **Step 8: Run the tests and the whole suite**

Run: `vendor/bin/phpunit --filter 'RestDomainScopeTest|ListActionsTest'`
Expected: PASS.

Run: `composer test`
Expected: PASS. `RestRoutingTest::testListsMissingRequiredParameters` still lists `caller_id_number`, `destination_a` and `destination_b`.

- [ ] **Step 9: Commit**

```bash
git add lib/auth.php rest.php actions tests/Support/ActionTestCase.php tests/Unit/Actions/ListActionsTest.php tests/Http/RestDomainScopeTest.php
git commit -m "#43940 Scope requests to the key user's domain

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Return explicit columns, and the SIP password only with `extension_password`

**Files:**
- Create: `lib/fields.php`
- Modify: `rest.php` (one `require_once`)
- Modify: `actions/extension-create.php`, `actions/extension-details.php`, `actions/destination-create.php`, `actions/destination-details.php`, `actions/ringgroup-create.php`, `actions/domain-details.php`
- Modify: `tests/Support/ActionTestCase.php` (one `require_once`)
- Modify: `tests/Unit/ActionDeclarationsTest.php`
- Modify: `tests/Unit/Actions/ExtensionCreateTest.php`, `tests/Unit/Actions/DestinationCreateTest.php`, `tests/Unit/Actions/RingGroupCreateTest.php`, `tests/Unit/Actions/ListActionsTest.php`

**Interfaces:**
- Consumes (Task 5): `do_action($body, $context = array())` in `destination-details` and `domain-details`.
- Produces:
  - Constants `REST_API_EXTENSION_FIELDS`, `REST_API_DESTINATION_FIELDS`, `REST_API_RING_GROUP_FIELDS`, `REST_API_RING_GROUP_DESTINATION_FIELDS` and `REST_API_DOMAIN_FIELDS` (arrays of column names)
  - `rest_api_decode_destination($destination)`, which returns the row with `destination_actions` JSON-decoded

- [ ] **Step 1: Write the failing tests**

In `tests/Unit/ActionDeclarationsTest.php`, add:

```php
	public function testNoActionReturnsEveryColumn(): void
	{
		foreach (glob(PLUGIN_DIR.'/actions/*.php') as $file) {
			$this->assertDoesNotMatchRegularExpression('/select\s+\*/i', file_get_contents($file), basename($file));
		}
	}
```

In `tests/Support/ActionTestCase.php`, add `require_once PLUGIN_DIR.'/lib/fields.php';` after the `input_validation.php` line in `setUp()`.

In `tests/Unit/Actions/ExtensionCreateTest.php`, replace `testCreatesTheExtensionWithVoicemailAndCallerId` with the two tests below:

```php
	public function testCreatesTheExtensionWithVoicemailAndCallerId(): void
	{
		$result = $this->runAction(array('domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'extension' => '101', 'caller_id_name' => 'Sales', 'caller_id_number' => '5551000'));

		$tables = $this->state()['tables'];
		$this->assertSame(REST_API_EXTENSION_FIELDS, array_keys($result));
		$this->assertSame($tables['v_extensions'][1]['extension_uuid'], $result['extension_uuid']);
		$this->assertSame('101', $result['extension']);
		$this->assertSame('tenant1.example.com', $result['user_context']);
		$this->assertSame(array('Sales', '5551000'), array($result['outbound_caller_id_name'], $result['outbound_caller_id_number']));
		$this->assertArrayNotHasKey('password', $result);
		$this->assertSame('101', $tables['v_voicemails'][0]['voicemail_id']);
		$this->assertSame(array(), $this->state()['skipped'], 'the declared permissions must cover every saved table');
	}

	public function testReturnsTheSipPasswordToUsersAllowedToSeeIt(): void
	{
		$this->grantOnly(array_merge($this->requiredPermissions, array('extension_password')));

		$result = $this->runAction(array('domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'extension' => '101'));

		$this->assertSame(10, strlen($result['password']));
		$this->assertSame($this->state()['tables']['v_extensions'][1]['password'], $result['password']);
	}
```

In `tests/Unit/Actions/DestinationCreateTest.php`, in `testRoutesTheNumberToTheExtensionInItsDomain`, replace `$this->assertSame($destination, $result);` with:

```php
		$this->assertSame(REST_API_DESTINATION_FIELDS, array_keys($result));
		$this->assertSame($destination['destination_uuid'], $result['destination_uuid']);
		$this->assertEquals(array((object)array('destination_app' => 'transfer', 'destination_data' => '100 XML tenant1.example.com')), $result['destination_actions']);
```

In `tests/Unit/Actions/RingGroupCreateTest.php`, in `testCreatesTheRingGroupWithItsDestinationsAndDialplan`, replace `$this->runAction($this->body());` with `$result = $this->runAction($this->body());`. Then add before the `skipped` assertion:

```php
		$this->assertSame(array_merge(REST_API_RING_GROUP_FIELDS, array('ring_group_destinations')), array_keys($result));
		$this->assertSame(array('100', '101'), array_column($result['ring_group_destinations'], 'destination_number'));
		$this->assertSame(REST_API_RING_GROUP_DESTINATION_FIELDS, array_keys($result['ring_group_destinations'][0]));
```

In `tests/Unit/Actions/ListActionsTest.php`, add:

```php
	public function testDomainDetailsReturnsOnlyTheDomainColumns(): void
	{
		$this->load('domain-details');

		$this->assertSame(REST_API_DOMAIN_FIELDS, array_keys($this->runAction(array('domain_uuid' => self::DOMAIN_UUID))));
	}

	public function testDestinationDetailsReturnsOnlyTheDestinationColumns(): void
	{
		$this->load('destination-details');

		$this->assertSame(REST_API_DESTINATION_FIELDS, array_keys($this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'number' => '5551234'))));
	}
```

- [ ] **Step 2: Run them to verify they fail**

Run: `vendor/bin/phpunit --filter 'ActionDeclarationsTest|ExtensionCreateTest|DestinationCreateTest|RingGroupCreateTest|ListActionsTest'`
Expected: ERROR. `lib/fields.php` can't be opened.

- [ ] **Step 3: Create `lib/fields.php`**

```php
<?php
// the columns each action returns, so secrets and columns FusionPBX adds later
// never leak through the API (#43940)

const REST_API_EXTENSION_FIELDS = array(
    "extension_uuid",
    "extension",
    "number_alias",
    "effective_caller_id_name",
    "effective_caller_id_number",
    "outbound_caller_id_name",
    "outbound_caller_id_number",
    "emergency_caller_id_name",
    "emergency_caller_id_number",
    "directory_first_name",
    "directory_last_name",
    "directory_visible",
    "directory_exten_visible",
    "limit_max",
    "limit_destination",
    "missed_call_app",
    "missed_call_data",
    "user_context",
    "toll_allow",
    "call_timeout",
    "call_group",
    "call_screen_enabled",
    "user_record",
    "hold_music",
    "auth_acl",
    "cidr",
    "sip_force_contact",
    "nibble_account",
    "sip_force_expires",
    "mwi_account",
    "sip_bypass_media",
    "unique_id",
    "dial_string",
    "dial_user",
    "dial_domain",
    "do_not_disturb",
    "forward_all_destination",
    "forward_all_enabled",
    "forward_busy_destination",
    "forward_busy_enabled",
    "forward_no_answer_destination",
    "forward_no_answer_enabled",
    "forward_user_not_registered_destination",
    "forward_user_not_registered_enabled",
    "follow_me_uuid",
    "enabled",
    "description",
    "forward_caller_id_uuid",
    "absolute_codec_string",
    "force_ping",
    "follow_me_enabled",
    "follow_me_destinations",
    "max_registrations",
    "insert_date",
    "insert_user",
    "update_date",
    "update_user"
);

const REST_API_DESTINATION_FIELDS = array(
    "destination_uuid",
    "domain_uuid",
    "destination_number",
    "destination_type",
    "destination_actions",
    "destination_context",
    "destination_enabled",
    "destination_description",
    "dialplan_uuid",
    "insert_date",
    "update_date"
);

const REST_API_RING_GROUP_FIELDS = array(
    "ring_group_uuid",
    "domain_uuid",
    "ring_group_name",
    "ring_group_extension",
    "ring_group_strategy",
    "ring_group_enabled",
    "ring_group_description",
    "dialplan_uuid"
);

const REST_API_RING_GROUP_DESTINATION_FIELDS = array(
    "ring_group_destination_uuid",
    "destination_number",
    "destination_delay",
    "destination_timeout",
    "destination_enabled"
);

const REST_API_DOMAIN_FIELDS = array(
    "domain_uuid",
    "domain_parent_uuid",
    "domain_name",
    "domain_enabled",
    "domain_description"
);

// destination_actions is JSON-encoded in the database. return it parsed (#5)
function rest_api_decode_destination($destination) {
    if($destination && !empty($destination['destination_actions'])) {
        $destination['destination_actions'] = json_decode($destination['destination_actions']);
    }
    return $destination;
}
```

In `rest.php`, add `require_once "lib/fields.php";` after `require_once "lib/auth.php";`.

- [ ] **Step 4: Use the field lists in the actions**

`actions/extension-details.php`: delete the `$fields = array(...);` block (lines 5–63). Then replace the `$sql = "SELECT v_extensions.".implode(", v_extensions.", $fields);` line with:

```php
    $sql = "SELECT v_extensions.".implode(", v_extensions.", REST_API_EXTENSION_FIELDS);
```

`actions/extension-create.php`: replace the final block (from `$sql = "SELECT * FROM v_extensions ...` to `return $database->select($sql, $parameters, 'row');`) with:

```php
    $sql = "SELECT ".implode(", ", REST_API_EXTENSION_FIELDS)." FROM v_extensions WHERE extension_uuid = :extension_uuid";
    $parameters['extension_uuid'] = $extension_uuid;
    $database = new database;
    $extension = $database->select($sql, $parameters, 'row');
    // the SIP password lets an integration provision the phone. FusionPBX only
    // shows it to users with extension_password, so the API does the same
    if($extension && permission_exists('extension_password')) {
        $sql = "SELECT password FROM v_extensions WHERE extension_uuid = :extension_uuid";
        $extension['password'] = $database->select($sql, $parameters, 'column');
    }
    return $extension;
```

`actions/destination-create.php`: replace the final block (from `$sql = "SELECT * FROM v_destinations ...` to `return $extension;`) with:

```php
    $sql = "SELECT ".implode(", ", REST_API_DESTINATION_FIELDS)." FROM v_destinations WHERE destination_uuid = :destination_uuid";
    $parameters['destination_uuid'] = $destination_uuid;
    $database = new database;
    return rest_api_decode_destination($database->select($sql, $parameters, 'row'));
```

`actions/destination-details.php`: replace `$sql = "SELECT * FROM v_destinations WHERE destination_number = :number";` with:

```php
    $sql = "SELECT ".implode(", ", REST_API_DESTINATION_FIELDS)." FROM v_destinations WHERE destination_number = :number";
```

Then replace the block from `// destination_actions is JSON-encoded` through `return $extension;` with:

```php
    return rest_api_decode_destination($extension);
```

`actions/ringgroup-create.php`: replace the final block (from `$sql = "SELECT * FROM v_ring_groups ...` to `return $ring_group;`) with:

```php
    $parameters['ring_group_uuid'] = $ring_group_uuid;
    $database = new database;
    $sql = "SELECT ".implode(", ", REST_API_RING_GROUP_FIELDS)." FROM v_ring_groups WHERE ring_group_uuid = :ring_group_uuid";
    $ring_group = $database->select($sql, $parameters, 'row');
    $sql = "SELECT ".implode(", ", REST_API_RING_GROUP_DESTINATION_FIELDS)." FROM v_ring_group_destinations WHERE ring_group_uuid = :ring_group_uuid";
    $ring_group['ring_group_destinations'] = $database->select($sql, $parameters, 'all');
    return $ring_group;
```

`actions/domain-details.php`: replace both `"SELECT * FROM v_domains WHERE` occurrences with `"SELECT ".implode(", ", REST_API_DOMAIN_FIELDS)." FROM v_domains WHERE`.

- [ ] **Step 5: Run the tests and the whole suite**

Run: `vendor/bin/phpunit --filter 'ActionDeclarationsTest|ExtensionCreateTest|DestinationCreateTest|RingGroupCreateTest|ListActionsTest'`
Expected: PASS.

Run: `composer test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add lib/fields.php rest.php actions tests/Support/ActionTestCase.php tests/Unit
git commit -m "#43940 Return explicit columns and the SIP password only with extension_password

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: README and final verification

**Files:**
- Modify: `README.md`

**Interfaces:**
- Consumes: the behaviour from Tasks 2–6.

- [ ] **Step 1: Replace the `# Use` section of `README.md`**

Replace everything from `# Use` up to, but not including, `# Actions` with:

````markdown
# Compatibility

This version targets FusionPBX **5.6.5**.

# Use

## API keys

Every request is made with an API key, sent with HTTP Basic auth as `<key id>:<secret>`. Each key is bound to a FusionPBX user, and the request runs as that user. It has the permissions of the user's groups, acts on the user's domain, and records it creates show that user as `insert_user`.

Keys are managed under the REST API app in the FusionPBX menu. Managing keys needs the `rest_api_key_view`, `rest_api_key_add`, `rest_api_key_edit` and `rest_api_key_delete` permissions, which superadmins have by default. **Anyone who can add or edit keys can bind a key to any user, including a superadmin, and use the API as that user.** Only give these permissions to superadmins.

A key authenticates only when all of these hold:
- the key is enabled
- the key hasn't expired (an empty expiry means never)
- its user exists and is enabled
- the user's domain is enabled

Otherwise the request gets `401 {"error": "unauthorized"}`.

The secret is shown once, when the key is created. If you lose it, create a new key and delete the old one.

Recommended setup: create one user for each integration, in a custom group that only has the permissions of the actions the integration uses (see the table below). Bind the integration's key to that user.

## Requests

The API endpoint is shown on the API key page, usually `https://<your fusionpbx>/app/rest_api/rest.php`. Every request is an HTTP POST with a JSON body. The body's `action` parameter names the action (see below). For example, the `domain-details` action with `domain_name=fusionpbx.example.net`:

```
$ curl -s --user "5bc14e83-fc4e-4578-99b8-c7151eb2ec54:jM2GQuYgQTkIGE6nJ2SP" -d '{"action": "domain-details", "domain_name": "fusionpbx.example.net"}' https://fusionpbx.example.net/app/rest_api/rest.php | jq
{
  "domain_uuid": "3a644e67-de8f-4798-b07e-6f22c33a656e",
  "domain_parent_uuid": null,
  "domain_name": "fusionpbx.example.net",
  "domain_enabled": true,
  "domain_description": ""
}
```

## Permissions

Each action needs these FusionPBX permissions in the key user's groups:

| Action | Permissions |
|---|---|
| `cdr-list` | `xml_cdr_view` |
| `destination-create` | `destination_add`, `dialplan_add`, `dialplan_detail_add` |
| `destination-details` | `destination_view` |
| `domain-details` | none |
| `extension-create` | `extension_add`, `voicemail_add` (`extension_password` to also get the SIP password back) |
| `extension-details` | `extension_view` |
| `extension-list` | `extension_view` |
| `originate` | `click_to_call_call` |
| `ringgroup-create` | `ring_group_add`, `ring_group_destination_add`, `dialplan_add` |

A missing permission returns `403 {"error": "forbidden", "missing_permissions": [...]}`.

## Domains

`domain_uuid` is optional on every action. Without it, the action acts on the key user's own domain. Acting on another domain needs the FusionPBX `domain_select` permission. Without it, any other `domain_uuid` returns `403 {"error": "forbidden"}`. With it, a domain that doesn't exist or is disabled returns `404 {"error": "domain not found"}`.

Lookups never return records of domains the user can't act on: they answer 404 as if the record didn't exist. One exception: `destination-details` without `domain_uuid`, by a user with `domain_select`, searches every domain. Use it to find the domain an inbound number belongs to.

## Errors

| Status | Meaning |
|---|---|
| 400 | Invalid body, unknown action, missing or invalid parameter (`missing_parameters` lists missing ones) |
| 401 | Unusable key (see above) |
| 403 | The user lacks a permission (`missing_permissions`), or the request is for another domain without `domain_select` |
| 404 | Record or domain not found |
| 500 | Server error, or an action that doesn't declare its permissions |

## Actions from other apps

Other FusionPBX apps can expose actions through an `app_api.php` file (call them with `"app": "<app name>"`). Each such action file must declare the FusionPBX permissions it needs, for example `$required_permissions = array("my_app_view");`, or `array()` for none. Actions that don't declare them aren't run. `do_action()` receives the request body and, as an optional second argument, the context: `domain_explicit`, `cross_domain` and `user_domain_uuid`.

## Upgrading from earlier versions

This version changes how keys work:
- After upgrading (Advanced → Upgrade → Schema), **existing keys stop working** until a superadmin edits each one, picks a user and enables it.
- Each integration's user needs the permissions listed above.
- Responses only contain the documented columns. `extension-create` no longer returns the SIP password unless the user has `extension_password`. Anything that read other columns from `extension-create`, `destination-create`, `destination-details`, `ringgroup-create` or `domain-details` must be updated.
- `domain_uuid` is now optional. It defaults to the key user's domain.

````

- [ ] **Step 2: Update the action parameter tables in `README.md`**

In each action table under `# Actions`, replace the `domain_uuid` row as follows:
- `destination-create`, `extension-create`, `ringgroup-create`, `originate`, `cdr-list`, `extension-list` and `extension-details`: replace the row with `| \`domain_uuid\` | no | Domain to act on. Defaults to the key user's domain |`.
- `destination-details`: add `| \`domain_uuid\` | no | Domain to search. Defaults to the key user's domain; users with \`domain_select\` who leave it out search every domain |` after the `number` row.
- `domain-details`: replace both rows with:

```markdown
| Parameter     | Required | Description |
|---------------|----------|-------------|
| `domain_uuid` | no       | UUID of the domain to look up. |
| `domain_name` | no       | Name of the domain to look up, used when `domain_uuid` is not given. |

looks up details of a domain. Mostly useful for converting between domain uuid and domain name. With neither parameter it returns the key user's domain.
```

Also, in the `extension-create` section, add after "create an extension": `Returns the extension's details, plus its SIP \`password\` when the key user has \`extension_password\`.`

- [ ] **Step 3: Run the full verification**

Run: `composer test`
Expected: `OK`. No warnings, notices or deprecations.

Run: `grep -rnE "SELECT \*|_SESSION\[.permissions.\] *=|rest_api_manage_keys|api_key" actions lib rest.php index.php key_edit.php app_config.php`
Expected: no output.

If Xdebug is available, run `composer coverage`. Expected: every new branch in `rest.php`, `lib/auth.php`, `index.php` and `key_edit.php` is covered. Report any uncovered lines rather than skipping them silently.

- [ ] **Step 4: Commit**

```bash
git add README.md
git commit -m "#43940 Document user-bound keys, permissions and the upgrade

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

The manual smoke test on FusionPBX 5.6.5 (spec Section 8) runs at deploy time; there is no 5.6.5 server yet. Do not mark the Redmine ticket resolved until it has passed.
