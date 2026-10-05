<?php
namespace RestApi\Test\Support;

/**
 * API requests that carry a logged-in user's FusionPBX session cookie must not
 * see or change that session (#43939 item 8). Subclasses run this against the
 * different ways FusionPBX may start the session.
 */
abstract class SessionIsolationTestCase extends RestApiTestCase
{
	private const SESSION_ID = 'browsersession0123456789';
	private const SESSION_DATA = 'user_uuid|s:36:"00000000-0000-4000-8000-0000000000ad";permissions|a:1:{s:14:"extension_view";b:1;}';

	protected static function extraFiles(): array
	{
		return array(
			'app/rest_api/actions/test-session.php' => '<?php $required_params = array(); function do_action($body) { return array("user_uuid" => $_SESSION["user_uuid"] ?? null, "permissions" => array_keys($_SESSION["permissions"] ?? array())); }',
		);
	}

	protected function setUp(): void
	{
		parent::setUp();
		file_put_contents($this->sessionFile(), self::SESSION_DATA);
	}

	private function sessionFile(): string
	{
		return self::$sessionDir.'/sess_'.self::SESSION_ID;
	}

	private function apiWithBrowserSession(array $body): array
	{
		return $this->api($body, self::KEY_ID.':'.self::SECRET, array('Cookie' => 'PHPSESSID='.self::SESSION_ID));
	}

	public function testActionsDoNotSeeTheBrowserSession(): void
	{
		$response = $this->apiWithBrowserSession(array('action' => 'test-session'));

		$this->assertSame(array('user_uuid' => null, 'permissions' => array()), $this->json($response));
	}

	public function testPermissionsGrantedByAnActionDoNotReachTheBrowserSession(): void
	{
		$response = $this->apiWithBrowserSession(array('action' => 'destination-create', 'domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'number' => '5551234', 'extension' => '100'));

		$this->assertSame(200, $response['status']);
		$this->assertSame(self::SESSION_DATA, file_get_contents($this->sessionFile()));
	}

	public function testNoSessionCookieIsSent(): void
	{
		$with_cookie = $this->apiWithBrowserSession(array('action' => 'test-session'));
		$without_cookie = $this->api(array('action' => 'test-session'));

		$this->assertArrayNotHasKey('set-cookie', $with_cookie['headers']);
		$this->assertArrayNotHasKey('set-cookie', $without_cookie['headers']);
	}

	public function testNoSessionIsStored(): void
	{
		$this->api(array('action' => 'test-session'));
		$this->apiWithBrowserSession(array('action' => 'test-session'));

		$this->assertSame(array($this->sessionFile()), glob(self::$sessionDir.'/sess_*'));
	}
}
