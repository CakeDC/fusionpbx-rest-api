<?php
namespace RestApi\Test\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use RestApi\Test\Support\RestApiTestCase;

class RestAuthenticationTest extends RestApiTestCase
{
	protected static function extraFiles(): array
	{
		return array(
			'app/rest_api/actions/test-whoami.php' => '<?php $required_params = array(); $required_permissions = array(); function do_action($body) { return array("user_uuid" => $_SESSION["user_uuid"] ?? null, "domain_uuid" => $_SESSION["domain_uuid"] ?? null, "domain_name" => $_SESSION["domain_name"] ?? null, "in_group" => if_group("api_integration"), "can_add_extensions" => permission_exists("extension_add"), "can_select_domains" => permission_exists("domain_select")); }',
		);
	}

	private const LOOKUP = array('action' => 'domain-details', 'domain_name' => 'tenant1.example.com');

	public function testAcceptsTheKeySecret(): void
	{
		$response = $this->api(self::LOOKUP);

		$this->assertSame(200, $response['status']);
		$this->assertSame('aaaaaaaa-0000-4000-8000-000000000001', $this->json($response)['domain_uuid']);
	}

	public function testRecordsWhenTheKeyWasLastUsed(): void
	{
		$this->api(self::LOOKUP);

		$this->assertNotNull($this->state()['tables']['rest_api_keys'][0]['last_used']);
	}

	public static function rejectedCredentials(): array
	{
		return array(
			'no credentials' => array(null),
			'wrong secret' => array(self::KEY_ID.':wrong-secret'),
			'empty secret' => array(self::KEY_ID.':'),
			'no secret' => array(self::KEY_ID),
			'secret of another key format' => array(self::KEY_ID.':'.password_hash(self::SECRET, PASSWORD_DEFAULT, array('cost' => 4))),
			'unknown key id' => array('22222222-2222-4222-8222-222222222222:'.self::SECRET),
			'malformed key id' => array("x' OR '1'='1:".self::SECRET),
		);
	}

	#[DataProvider('rejectedCredentials')]
	public function testRejectsInvalidCredentials(?string $credentials): void
	{
		$response = $this->api(self::LOOKUP, $credentials);

		$this->assertSame(401, $response['status']);
		$this->assertSame(array('error' => 'unauthorized'), $this->json($response));
		$this->assertNull($this->state()['tables']['rest_api_keys'][0]['last_used']);
	}

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
}
