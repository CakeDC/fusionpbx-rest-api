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
