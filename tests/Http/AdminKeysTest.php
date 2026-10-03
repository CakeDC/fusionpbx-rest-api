<?php
namespace RestApi\Test\Http;

use RestApi\Test\Support\AdminKeysHelpers;
use RestApi\Test\Support\RestApiTestCase;

/**
 * Key management pages: index.php (list, delete) and key_edit.php (create, edit).
 */
class AdminKeysTest extends RestApiTestCase
{
	use AdminKeysHelpers;

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

		$this->submit('key_edit.php', array('name' => 'Invoicing', 'key_uuid' => self::KEY_ID, 'user_uuid' => self::OPS_USER, 'expires' => '2026-12-31T23:30:15') + $this->tokenField($form));

		$key = $this->keys()[0];
		$this->assertSame(array('Invoicing', self::OPS_USER, 'false'), array($key['name'], $key['user_uuid'], $key['key_enabled']));
		// compare instants: the stored text carries an offset
		$this->assertSame(strtotime('2026-12-31T23:30:15'), strtotime($key['expires']));
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
