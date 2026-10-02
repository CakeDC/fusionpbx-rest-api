<?php
namespace RestApi\Test\Http;

use RestApi\Test\Support\RestApiTestCase;

/**
 * Key management pages: index.php (list, delete) and key_edit.php (create, rename).
 */
class AdminKeysTest extends RestApiTestCase
{
	private string $cookie = '';

	private function login(string $permissions = 'rest_api_manage_keys'): void
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

	private function keys(): array
	{
		return $this->state()['tables']['rest_api_keys'] ?? array();
	}

	public function testRequiresTheManageKeysPermission(): void
	{
		$this->login('extension_view');

		$response = $this->page('index.php');

		$this->assertStringContainsString('permission denied', $response['body']);
		$this->assertStringNotContainsString(self::KEY_ID, $response['body']);
	}

	public function testOpeningTheNewKeyFormDoesNotCreateAKey(): void
	{
		$this->login();

		$this->page('key_edit.php');

		$this->assertCount(1, $this->keys());
	}

	public function testCreatedKeyAuthenticatesApiRequestsAndIsStoredHashed(): void
	{
		$this->login();
		$form = $this->page('key_edit.php');

		$response = $this->submit('key_edit.php', array('name' => 'Billing', 'key_uuid' => '') + $this->tokenField($form));

		$this->assertMatchesRegularExpression('/<code>([0-9a-f-]{36}):([0-9A-Za-z]{20})<\/code>/', $response['body']);
		preg_match('/<code>([0-9a-f-]{36}):([0-9A-Za-z]{20})<\/code>/', $response['body'], $m);
		$created = $this->keys()[1];
		$this->assertSame(array($m[1], 'Billing'), array($created['key_uuid'], $created['name']));
		$this->assertStringNotContainsString($m[2], json_encode($created));
		$this->assertSame(200, $this->api(array('action' => 'domain-details', 'domain_name' => 'tenant1.example.com'), $m[1].':'.$m[2])['status']);
	}

	public function testRejectsCreatingAKeyWithoutAValidToken(): void
	{
		$this->login();
		$token = $this->tokenField($this->page('key_edit.php'));

		$this->submit('key_edit.php', array('name' => 'Forged', 'key_uuid' => ''));
		$this->submit('key_edit.php', array('name' => 'Forged', 'key_uuid' => '', key($token) => str_repeat('0', 32)));

		$this->assertCount(1, $this->keys());
	}

	public function testRenamesAKey(): void
	{
		$this->login();
		$form = $this->page('key_edit.php?key_uuid='.self::KEY_ID);

		$this->submit('key_edit.php', array('name' => 'Invoicing', 'key_uuid' => self::KEY_ID) + $this->tokenField($form));

		$this->assertSame('Invoicing', $this->keys()[0]['name']);
	}

	public function testRejectsRenamingAKeyWithoutAToken(): void
	{
		$this->login();

		$this->submit('key_edit.php', array('name' => '<script>alert(1)</script>', 'key_uuid' => self::KEY_ID));

		$this->assertSame('billing', $this->keys()[0]['name']);
	}

	public function testDeletesAKey(): void
	{
		$this->login();
		$list = $this->page('index.php');

		$this->submit('index.php', array('action' => 'delete', 'key_uuid' => self::KEY_ID) + $this->tokenField($list));

		$this->assertSame(array(), $this->keys());
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

	public function testKeyEditRequiresTheManageKeysPermission(): void
	{
		$this->login('extension_view');

		$form = $this->page('key_edit.php?key_uuid='.self::KEY_ID);
		$this->submit('key_edit.php', array('name' => 'Unauthorized', 'key_uuid' => ''));

		$this->assertStringContainsString('permission denied', $form['body']);
		$this->assertStringNotContainsString('billing', $form['body']);
		$this->assertCount(1, $this->keys());
	}

	public function testIgnoresAMalformedKeyUuidWhenSaving(): void
	{
		$this->login();
		$form = $this->page('key_edit.php');

		$response = $this->submit('key_edit.php', array('name' => 'Renamed', 'key_uuid' => "' OR '1'='1") + $this->tokenField($form));

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
