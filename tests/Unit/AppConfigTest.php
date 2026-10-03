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
