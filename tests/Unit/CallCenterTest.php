<?php
namespace RestApi\Test\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * rest_api_call_center_error(): a callcenter_config command that failed
 * because FreeSWITCH runs without mod_callcenter is told apart from any other
 * failure of the event socket, and so is an agent mod_callcenter hasn't
 * loaded.
 */
class CallCenterTest extends TestCase
{
	public static function setUpBeforeClass(): void
	{
		require_once PLUGIN_DIR.'/lib/call_center.php';
	}

	/** rest_api_call_center_error()'s result and what it wrote to the error log. */
	private static function errorLogged(string $command, array $reply): array
	{
		$log = tempnam(sys_get_temp_dir(), 'rest_api_log');
		$previous = ini_set('error_log', $log);
		try {
			$result = rest_api_call_center_error($command, $reply);
		} finally {
			ini_set('error_log', $previous);
		}
		$logged = file_get_contents($log);
		unlink($log);
		return array($result, $logged);
	}

	public static function notLoaded(): array
	{
		return array(
			'mod_event_socket\'s reply' => array('-ERR callcenter_config Command not found!'),
			'with trailing text' => array('-ERR callcenter_config Command not found! (try "help")'),
		);
	}

	#[DataProvider('notLoaded')]
	public function testAnswersModCallcenterNotLoadedForAnUnknownCommand(string $details): void
	{
		$command = 'api callcenter_config agent list x';
		$reply = array('error' => 'freeswitch rejected request', 'details' => $details);

		list($result, $logged) = self::errorLogged($command, $reply);

		$this->assertSame(array('error' => 'mod_callcenter not loaded', 'code' => 500), $result);
		$this->assertStringContainsString($command.' failed: '.json_encode($reply), $logged);
	}

	public function testAnswersAgentNotLoadedForAnAgentMod_callcenterDoesntKnow(): void
	{
		$command = "api callcenter_config agent set status x 'On Break'";
		$reply = array('error' => 'freeswitch rejected request', 'details' => '-ERR Invalid Agent!');

		list($result, $logged) = self::errorLogged($command, $reply);

		$this->assertSame(array('error' => 'agent not loaded in mod_callcenter', 'code' => 500), $result);
		$this->assertStringContainsString($command.' failed: '.json_encode($reply), $logged);
	}

	public static function otherFailures(): array
	{
		return array(
			'mod_callcenter refusing the command' => array(array('error' => 'freeswitch rejected request', 'details' => '-ERR Invalid Status!')),
			'another command not found' => array(array('error' => 'freeswitch rejected request', 'details' => '-ERR show Command not found!')),
			'the text not at the start' => array(array('error' => 'freeswitch rejected request', 'details' => 'reply: -ERR callcenter_config Command not found!')),
			'an empty reply' => array(array('error' => 'freeswitch rejected request', 'details' => '')),
			'no event socket' => array(array('error' => 'Failed to connect to event socket')),
		);
	}

	#[DataProvider('otherFailures')]
	public function testAnswersEventSocketErrorForAnyOtherFailure(array $reply): void
	{
		list($result, $logged) = self::errorLogged('api callcenter_config agent list x', $reply);

		$this->assertSame(array('error' => 'event socket error', 'code' => 500), $result);
		$this->assertStringContainsString(json_encode($reply), $logged);
	}
}
