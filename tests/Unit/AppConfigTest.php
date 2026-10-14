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
		$permissions = array_slice($this->app()['permissions'], 0, 4);

		$this->assertSame(
			array('rest_api_key_view', 'rest_api_key_add', 'rest_api_key_edit', 'rest_api_key_delete'),
			array_column($permissions, 'name')
		);
		foreach ($permissions as $permission) {
			$this->assertSame(array('superadmin'), $permission['groups']);
		}
	}

	// FusionPBX 5.6.5 has no permission to answer, hold or resume a call
	public function testDeclaresTheCallControlPermissionForAdmins(): void
	{
		$permissions = array_column($this->app()['permissions'], null, 'name');

		$this->assertSame(array('rest_api_key_view', 'rest_api_key_add', 'rest_api_key_edit', 'rest_api_key_delete', 'rest_api_call_control'), array_keys($permissions));
		$this->assertSame(array('superadmin', 'admin'), $permissions['rest_api_call_control']['groups']);
	}
}
