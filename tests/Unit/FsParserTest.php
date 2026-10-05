<?php
namespace RestApi\Test\Unit;

use FakeStore;
use PHPUnit\Framework\TestCase;

class FsParserTest extends TestCase
{
	public static function setUpBeforeClass(): void
	{
		require_once FUSIONPBX_FAKES_DIR.'/resources/fakes.php';
		require_once PLUGIN_DIR.'/lib/fs_parser.php';
	}

	protected function setUp(): void
	{
		$_SESSION = array('event_socket_ip_address' => '127.0.0.1', 'event_socket_port' => '8021', 'event_socket_password' => 'ClueCon');
		FakeStore::reset();
	}

	private function respondWith(string $response): void
	{
		FakeStore::update(function (&$state) use ($response) {
			$state['esl_response'] = $response;
		});
	}

	public function testParsesPipeSeparatedRowsUsingTheHeaderLine(): void
	{
		$this->respondWith("name|state\nsofia/internal/100|ACTIVE\nsofia/internal/101|RINGING\n+OK\n");

		$this->assertSame(array(
			array('name' => 'sofia/internal/100', 'state' => 'ACTIVE'),
			array('name' => 'sofia/internal/101', 'state' => 'RINGING'),
		), parse_fs('api show channels'));
		$this->assertSame(array('api show channels'), FakeStore::read()['esl_commands']);
	}

	public function testReturnsAnErrorWhenFreeswitchRejectsTheCommand(): void
	{
		$this->respondWith("-ERR no such command\n");

		$this->assertSame(array('error' => 'freeswitch rejected request', 'details' => '-ERR no such command'), parse_fs('api bogus'));
	}

	public function testReturnsAnErrorWhenTheEventSocketIsUnavailable(): void
	{
		FakeStore::update(function (&$state) {
			$state['esl_available'] = false;
		});

		$this->assertSame(array('error' => 'Failed to connect to event socket'), parse_fs('api show channels'));
		$this->assertSame(array(), FakeStore::read()['esl_commands']);
	}
}
