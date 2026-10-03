<?php
namespace RestApi\Test\Support;

/**
 * Login, page and form helpers shared by the key management page tests.
 */
trait AdminKeysHelpers
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
}
