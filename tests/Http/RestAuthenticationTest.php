<?php
namespace RestApi\Test\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use RestApi\Test\Support\RestApiTestCase;

class RestAuthenticationTest extends RestApiTestCase
{
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
}
