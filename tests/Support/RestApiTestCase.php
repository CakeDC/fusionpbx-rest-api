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
