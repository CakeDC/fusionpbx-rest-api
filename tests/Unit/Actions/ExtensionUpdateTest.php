<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

/**
 * extension-update. Like FusionPBX's
 * extension_edit.php, each field needs the permission of the columns it writes.
 */
#[RunTestsInSeparateProcesses]
class ExtensionUpdateTest extends ActionTestCase
{
	private const EXT_100 = 'eeeeeeee-0000-4000-8000-000000000100';
	private const EXT_200 = 'eeeeeeee-0000-4000-8000-000000000200';
	private const KEY_USER = 'dddddddd-0000-4000-8000-000000000001';
	private const AGENT = 'dddddddd-0000-4000-8000-000000000010';
	private const COLLEAGUE = 'dddddddd-0000-4000-8000-000000000011';
	private const OTHER_DOMAIN_USER = 'dddddddd-0000-4000-8000-000000000020';
	private const OTHER_DOMAIN_UUID = 'aaaaaaaa-0000-4000-8000-000000000002';
	private const CALLER_ID_COLUMNS = array(
		'effective_caller_id_name', 'effective_caller_id_number',
		'outbound_caller_id_name', 'outbound_caller_id_number',
		'emergency_caller_id_name', 'emergency_caller_id_number',
	);
	private const ALL_PERMISSIONS = array(
		'extension_edit', 'effective_caller_id_name', 'effective_caller_id_number',
		'outbound_caller_id_name', 'outbound_caller_id_number', 'emergency_caller_id_name',
		'emergency_caller_id_number', 'extension_enabled', 'extension_user_add', 'extension_user_delete',
	);

	protected function action(): string
	{
		return 'extension-update';
	}

	protected function tables(): array
	{
		$caller_id = array_fill_keys(self::CALLER_ID_COLUMNS, 'old');
		return parent::tables() + array(
			'v_extensions' => array(
				array('extension_uuid' => self::EXT_100, 'domain_uuid' => self::DOMAIN_UUID, 'extension' => '100', 'number_alias' => '', 'user_context' => 'tenant1.example.com', 'password' => 'sip-secret', 'enabled' => 'true') + $caller_id,
				array('extension_uuid' => self::EXT_200, 'domain_uuid' => self::OTHER_DOMAIN_UUID, 'extension' => '200', 'number_alias' => '', 'user_context' => 'tenant2.example.com', 'password' => 'sip-secret', 'enabled' => 'true') + $caller_id,
			),
			'v_users' => array(
				array('user_uuid' => self::AGENT, 'domain_uuid' => self::DOMAIN_UUID, 'username' => 'agent'),
				array('user_uuid' => self::COLLEAGUE, 'domain_uuid' => self::DOMAIN_UUID, 'username' => 'colleague'),
				array('user_uuid' => self::OTHER_DOMAIN_USER, 'domain_uuid' => self::OTHER_DOMAIN_UUID, 'username' => 'agent'),
			),
			'v_extension_users' => array(
				array('extension_user_uuid' => 'ffffffff-0000-4000-8000-000000000001', 'domain_uuid' => self::DOMAIN_UUID, 'extension_uuid' => self::EXT_100, 'user_uuid' => self::COLLEAGUE),
				array('extension_user_uuid' => 'ffffffff-0000-4000-8000-000000000002', 'domain_uuid' => self::OTHER_DOMAIN_UUID, 'extension_uuid' => self::EXT_200, 'user_uuid' => self::OTHER_DOMAIN_USER),
			),
		);
	}

	protected function setUp(): void
	{
		parent::setUp();
		// rest.php runs the request as the key's user, which becomes update_user
		$_SESSION['user_uuid'] = self::KEY_USER;
		$this->grantOnly(self::ALL_PERMISSIONS);
	}

	private function update(array $fields, string $extension_uuid = self::EXT_100): array
	{
		return $this->runAction($fields + array('domain_uuid' => self::DOMAIN_UUID, 'extension_uuid' => $extension_uuid));
	}

	private function extension(string $extension_uuid = self::EXT_100): array
	{
		$rows = array_filter($this->state()['tables']['v_extensions'], function ($row) use ($extension_uuid) {
			return $row['extension_uuid'] === $extension_uuid;
		});
		return reset($rows);
	}

	private function linkedUsers(string $extension_uuid = self::EXT_100): array
	{
		$links = array_filter($this->state()['tables']['v_extension_users'], function ($row) use ($extension_uuid) {
			return $row['extension_uuid'] === $extension_uuid;
		});
		return array_values(array_column($links, 'user_uuid'));
	}

	// the same columns extension-create fills from caller_id_name/number
	public function testUpdatesTheCallerIdColumnsLikeExtensionCreate(): void
	{
		$this->update(array('caller_id_name' => 'Ana Ruiz', 'caller_id_number' => '+15551230100'));

		$extension = $this->extension();
		foreach (self::CALLER_ID_COLUMNS as $column) {
			$this->assertSame(str_ends_with($column, '_name') ? 'Ana Ruiz' : '+15551230100', $extension[$column], $column);
		}
		$this->assertSame(self::KEY_USER, $extension['update_user']);
	}

	public function testOnlyChangesTheGivenFields(): void
	{
		$this->update(array('enabled' => false));

		$extension = $this->extension();
		$this->assertSame('false', $extension['enabled']);
		$this->assertSame(array_fill_keys(self::CALLER_ID_COLUMNS, 'old'), array_intersect_key($extension, array_flip(self::CALLER_ID_COLUMNS)));
		$this->assertSame(array(self::COLLEAGUE), $this->linkedUsers());
	}

	public function testAnEmptyCallerIdClearsIt(): void
	{
		$this->update(array('caller_id_name' => '', 'caller_id_number' => ''));

		$this->assertSame(array_fill_keys(self::CALLER_ID_COLUMNS, ''), array_intersect_key($this->extension(), array_flip(self::CALLER_ID_COLUMNS)));
	}

	public function testReturnsTheUpdatedExtensionLikeExtensionDetails(): void
	{
		$result = $this->update(array('enabled' => 'false'));

		$this->assertSame(REST_API_EXTENSION_FIELDS, array_keys($result));
		// a boolean, as extension-list returns it, whatever the column type
		$this->assertFalse($result['enabled']);
		$this->assertArrayNotHasKey('password', $result);
	}

	// FusionPBX serves registrations from a cached directory entry
	public function testClearsTheCachedDirectoryEntry(): void
	{
		$this->update(array('enabled' => false));

		$this->assertSame(array('directory:100@tenant1.example.com'), $this->state()['cache_deleted']);
	}

	public function testClearsTheNumberAliasEntryToo(): void
	{
		\FakeStore::update(function (&$state) {
			$state['tables']['v_extensions'][0]['number_alias'] = '1100';
		});

		$this->update(array('enabled' => false));

		$this->assertSame(array('directory:100@tenant1.example.com', 'directory:1100@tenant1.example.com'), $this->state()['cache_deleted']);
	}

	// like extension_edit.php, linking a user keeps the users already linked
	public function testLinksAUserOfTheDomain(): void
	{
		$this->update(array('user_uuid' => self::AGENT));

		$this->assertSame(array(self::COLLEAGUE, self::AGENT), $this->linkedUsers());
		$links = $this->state()['tables']['v_extension_users'];
		$this->assertSame(self::DOMAIN_UUID, end($links)['domain_uuid']);
	}

	public function testLinkingALinkedUserAgainAddsNothing(): void
	{
		$this->update(array('user_uuid' => self::COLLEAGUE));

		$this->assertSame(array(self::COLLEAGUE), $this->linkedUsers());
	}

	public function testNullUserUuidRemovesEveryLink(): void
	{
		$this->update(array('user_uuid' => null));

		$this->assertSame(array(), $this->linkedUsers());
		$this->assertSame(array(self::OTHER_DOMAIN_USER), $this->linkedUsers(self::EXT_200));
	}

	public function testAnswersNotFoundForAnExtensionOfAnotherDomainOrAnUnknownOne(): void
	{
		foreach (array(self::EXT_200, 'eeeeeeee-0000-4000-8000-00000000ffff') as $extension_uuid) {
			$this->assertSame(array('error' => 'extension not found', 'code' => 404), $this->update(array('enabled' => false), $extension_uuid));
		}
		$this->assertSame('true', $this->extension(self::EXT_200)['enabled']);
		$this->assertSame(array(), $this->state()['saved']);
	}

	public function testAnswersNotFoundForAUserOfAnotherDomain(): void
	{
		$this->assertSame(array('error' => 'user not found', 'code' => 404), $this->update(array('enabled' => false, 'user_uuid' => self::OTHER_DOMAIN_USER)));
		$this->assertSame(array(), $this->state()['saved']);
	}

	public static function invalidFields(): array
	{
		return array(
			'malformed extension_uuid' => array(array('extension_uuid' => 'not-a-uuid', 'enabled' => true), 'extension_uuid'),
			'number with letters' => array(array('caller_id_number' => '555-CALL'), 'caller_id_number'),
			'number not a string' => array(array('caller_id_number' => array('100')), 'caller_id_number'),
			'name not a string' => array(array('caller_id_name' => 42), 'caller_id_name'),
			'name with a newline' => array(array('caller_id_name' => "Ana\nRuiz"), 'caller_id_name'),
			'name too long' => array(array('caller_id_name' => str_repeat('a', 256)), 'caller_id_name'),
			'enabled not a boolean' => array(array('enabled' => 'maybe'), 'enabled'),
			'malformed user_uuid' => array(array('user_uuid' => 'agent'), 'user_uuid'),
		);
	}

	#[DataProvider('invalidFields')]
	public function testRejectsAnInvalidField(array $fields, string $name): void
	{
		$this->assertSame(array('error' => 'invalid '.$name, 'code' => 400), $this->update($fields));
		$this->assertSame(array(), $this->state()['saved']);
	}

	public function testRejectsARequestWithoutAnyField(): void
	{
		$this->assertSame(array('error' => 'nothing to update', 'code' => 400), $this->update(array()));
	}

	public static function fieldPermissions(): array
	{
		return array(
			'caller_id_name' => array(array('caller_id_name' => 'Ana'), array('effective_caller_id_name', 'outbound_caller_id_name', 'emergency_caller_id_name')),
			'caller_id_number' => array(array('caller_id_number' => '100'), array('effective_caller_id_number', 'outbound_caller_id_number', 'emergency_caller_id_number')),
			'enabled' => array(array('enabled' => false), array('extension_enabled')),
			'user_uuid' => array(array('user_uuid' => self::AGENT), array('extension_user_add')),
			'null user_uuid' => array(array('user_uuid' => null), array('extension_user_delete')),
		);
	}

	// save() would silently skip what the user may not write, so a missing
	// permission refuses the whole update instead of applying part of it
	#[DataProvider('fieldPermissions')]
	public function testRequiresThePermissionsOfTheColumnsItWrites(array $fields, array $permissions): void
	{
		$this->grantOnly(array_values(array_diff(self::ALL_PERMISSIONS, $permissions)));

		$this->assertSame(array('error' => 'forbidden', 'missing_permissions' => $permissions, 'code' => 403), $this->update($fields));
		$this->assertSame(array(), $this->state()['saved']);
	}

	public function testAnswers500WhenTheDatabaseFails(): void
	{
		$this->failSelects();

		$this->assertSame(array('error' => 'database error', 'code' => 500), $this->update(array('enabled' => false)));
	}

	public function testAnswers500WhenTheSaveFails(): void
	{
		$this->failSaves();

		$this->assertSame(array('error' => 'error updating extension', 'code' => 500), $this->update(array('enabled' => false)));
		$this->assertSame(array(), $this->state()['cache_deleted']);
	}

	// the extension is already changed: FusionPBX must stop serving the old entry
	public function testClearsTheCacheEvenWhenTheUnlinkFails(): void
	{
		\FakeStore::update(function (&$state) {
			$state['delete_fails'] = true;
		});

		$this->assertSame(array('error' => 'error updating extension', 'code' => 500), $this->update(array('enabled' => false, 'user_uuid' => null)));
		$this->assertSame('false', $this->extension()['enabled']);
		$this->assertSame(array('directory:100@tenant1.example.com'), $this->state()['cache_deleted']);
	}

	public function testUpdatesSeveralFieldsAtOnce(): void
	{
		$this->update(array('caller_id_name' => 'Ana Ruiz', 'enabled' => false, 'user_uuid' => self::AGENT));

		$extension = $this->extension();
		$this->assertSame(array('Ana Ruiz', 'false'), array($extension['effective_caller_id_name'], $extension['enabled']));
		$this->assertSame('old', $extension['effective_caller_id_number']);
		$this->assertSame(array(self::COLLEAGUE, self::AGENT), $this->linkedUsers());
	}

	public function testEnablesADisabledExtension(): void
	{
		\FakeStore::update(function (&$state) {
			$state['tables']['v_extensions'][0]['enabled'] = 'false';
		});

		$this->update(array('enabled' => true));

		$this->assertSame('true', $this->extension()['enabled']);
	}

	public function testRemovingTheLinksOfAnExtensionWithoutLinksIsHarmless(): void
	{
		\FakeStore::update(function (&$state) {
			$state['tables']['v_extension_users'] = array_values(array_filter($state['tables']['v_extension_users'], function ($row) {
				return $row['extension_uuid'] !== self::EXT_100;
			}));
		});

		$result = $this->update(array('user_uuid' => null));

		$this->assertSame(self::EXT_100, $result['extension_uuid']);
		$this->assertSame(array(), $this->linkedUsers());
		$this->assertSame(array(self::OTHER_DOMAIN_USER), $this->linkedUsers(self::EXT_200));
	}
}
