<?php
namespace RestApi\Test\Support;

/**
 * HTTP tests against rest.php with one API key: KEY_ID:SECRET.
 */
abstract class RestApiTestCase extends HttpTestCase
{
	protected const KEY_ID = '11111111-1111-4111-8111-111111111111';
	protected const SECRET = 'Q7mZp2Kx9VbN4tRw8LcY';

	protected function tables(): array
	{
		return array(
			'rest_api_keys' => array(
				array('key_uuid' => self::KEY_ID, 'name' => 'billing', 'key_secret' => password_hash(self::SECRET, PASSWORD_DEFAULT, array('cost' => 4)), 'created' => '2026-01-01', 'last_used' => null),
			),
			'v_domains' => array(
				array('domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'domain_name' => 'tenant1.example.com', 'domain_enabled' => 'true'),
			),
		);
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
