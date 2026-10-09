<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

/**
 * call-hangup: hangs up a call (uuid_kill, as FusionPBX's active calls page
 * does) after checking the channel belongs to the domain.
 */
#[RunTestsInSeparateProcesses]
class CallHangupTest extends ActionTestCase
{
	private const CALL = 'f0000000-0000-4000-8000-000000000001';
	private const DUMP = 'api uuid_dump '.self::CALL.' json';
	private const KILL = 'api uuid_kill '.self::CALL;

	protected function action(): string
	{
		return 'call-hangup';
	}

	private static function dump(array $fields = array()): string
	{
		return json_encode($fields + array(
			'Unique-ID' => self::CALL,
			'Channel-Call-State' => 'ACTIVE',
			'variable_domain_uuid' => self::DOMAIN_UUID,
		))."\n";
	}

	private function replies(string $dump, string $kill = "+OK\n"): void
	{
		\FakeStore::update(function (&$state) use ($dump, $kill) {
			$state['esl_responses'][self::DUMP] = $dump;
			$state['esl_responses'][self::KILL] = $kill;
		});
	}

	protected function setUp(): void
	{
		parent::setUp();
		$this->replies(self::dump());
	}

	private function hangup($call_uuid = self::CALL): array
	{
		return $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'call_uuid' => $call_uuid));
	}

	/** The result and what the action logged. */
	private function hangupLogged(): array
	{
		$log = tempnam(sys_get_temp_dir(), 'rest_api_log');
		$previous = ini_set('error_log', $log);
		try {
			$result = $this->hangup();
		} finally {
			ini_set('error_log', $previous);
		}
		$logged = file_get_contents($log);
		unlink($log);
		return array($result, $logged);
	}

	public function testHangsUpTheCall(): void
	{
		$this->assertSame(array('code' => 204), $this->hangup());
		$this->assertSame(array(self::DUMP, self::KILL), $this->state()['esl_commands']);
	}

	// a call_uuid is global to FreeSWITCH: a call of another domain is not touched
	public function testAnswersNotFoundForACallOfAnotherDomain(): void
	{
		$this->replies(self::dump(array('variable_domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000002')));

		$this->assertSame(array('error' => 'call not found', 'code' => 404), $this->hangup());
		$this->assertSame(array(self::DUMP), $this->state()['esl_commands']);
	}

	public function testAnswersNotFoundForAnUnknownCall(): void
	{
		$this->replies("-ERR No such channel!\n");

		$this->assertSame(array('error' => 'call not found', 'code' => 404), $this->hangup());
		$this->assertSame(array(self::DUMP), $this->state()['esl_commands']);
	}

	public function testRejectsAMalformedCallUuid(): void
	{
		foreach (array('f0000000; shutdown', '', array(self::CALL)) as $call_uuid) {
			$this->assertSame(array('error' => 'invalid call_uuid', 'code' => 400), $this->hangup($call_uuid), json_encode($call_uuid));
		}
		$this->assertSame(array(), $this->state()['esl_commands']);
	}

	public function testAcceptsAnUpperCaseCallUuid(): void
	{
		$this->assertSame(array('code' => 204), $this->hangup(strtoupper(self::CALL)));
		$this->assertSame(array(self::DUMP, self::KILL), $this->state()['esl_commands']);
	}

	// the call can end between the domain check and uuid_kill: it is over,
	// which is what was asked
	public function testHangsUpACallThatEndsMeanwhile(): void
	{
		$this->replies(self::dump(), "-ERR No such channel!\n");

		$this->assertSame(array('code' => 204), $this->hangup());
		$this->assertSame(array(self::DUMP, self::KILL), $this->state()['esl_commands']);
	}

	public function testAnswers500WhenFreeswitchRefuses(): void
	{
		$this->replies(self::dump(), "-ERR Operation failed\n");

		list($result, $logged) = $this->hangupLogged();

		$this->assertSame(array('error' => 'event socket error', 'code' => 500), $result);
		$this->assertStringContainsString('-ERR Operation failed', $logged);
	}

	public function testAnswers500WhenTheEventSocketIsUnavailable(): void
	{
		\FakeStore::update(function (&$state) {
			$state['esl_available'] = false;
		});

		list($result, $logged) = $this->hangupLogged();

		$this->assertSame(array('error' => 'event socket error', 'code' => 500), $result);
		$this->assertStringContainsString('Failed to connect to event socket', $logged);
	}
}
