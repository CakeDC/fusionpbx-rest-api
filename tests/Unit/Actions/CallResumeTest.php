<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

/**
 * call-resume: takes a held call of the domain off hold (uuid_hold off).
 */
#[RunTestsInSeparateProcesses]
class CallResumeTest extends ActionTestCase
{
	private const CALL = 'f0000000-0000-4000-8000-000000000001';
	private const DUMP = 'api uuid_dump '.self::CALL.' json';
	private const RESUME = 'api uuid_hold off '.self::CALL;

	protected function action(): string
	{
		return 'call-resume';
	}

	private static function dump(array $fields = array()): string
	{
		return json_encode($fields + array(
			'Unique-ID' => self::CALL,
			'Channel-Call-State' => 'HELD',
			'Caller-Caller-ID-Number' => '101',
			'Caller-Destination-Number' => '+15550001111',
			'Other-Leg-Unique-ID' => 'f0000000-0000-4000-8000-000000000002',
			'variable_domain_uuid' => self::DOMAIN_UUID,
		))."\n";
	}

	private function replies(array $dumps, string $resume = "+OK\n"): void
	{
		\FakeStore::update(function (&$state) use ($dumps, $resume) {
			$state['esl_responses'][self::DUMP] = $dumps;
			$state['esl_responses'][self::RESUME] = $resume;
		});
	}

	protected function setUp(): void
	{
		parent::setUp();
		// before and after the resume
		$this->replies(array(self::dump(), self::dump(array('Channel-Call-State' => 'ACTIVE'))));
	}

	private function resume($call_uuid = self::CALL): array
	{
		return $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'call_uuid' => $call_uuid));
	}

	/** The result and what the action logged. */
	private function resumeLogged(): array
	{
		$log = tempnam(sys_get_temp_dir(), 'rest_api_log');
		$previous = ini_set('error_log', $log);
		try {
			$result = $this->resume();
		} finally {
			ini_set('error_log', $previous);
		}
		$logged = file_get_contents($log);
		unlink($log);
		return array($result, $logged);
	}

	// still bridged to the other party once off hold
	public function testTakesTheCallOffHoldAndReturnsIt(): void
	{
		$this->assertSame(
			array('call_uuid' => self::CALL, 'domain_uuid' => self::DOMAIN_UUID, 'state' => 'bridged', 'caller_id_number' => '101', 'destination_number' => '+15550001111'),
			$this->resume()
		);
		$this->assertSame(array(self::DUMP, self::RESUME, self::DUMP), $this->state()['esl_commands']);
	}

	public function testResumingACallThatIsNotHeldIsHarmless(): void
	{
		$this->replies(array(self::dump(array('Channel-Call-State' => 'ACTIVE', 'Other-Leg-Unique-ID' => ''))));

		$this->assertSame('answered', $this->resume()['state']);
		$this->assertSame(array(self::DUMP), $this->state()['esl_commands']);
	}

	public function testAnswersNotFoundForACallOfAnotherDomain(): void
	{
		$this->replies(array(self::dump(array('variable_domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000002'))));

		$this->assertSame(array('error' => 'call not found', 'code' => 404), $this->resume());
		$this->assertSame(array(self::DUMP), $this->state()['esl_commands']);
	}

	public function testAnswersNotFoundForAnUnknownCall(): void
	{
		$this->replies(array("-ERR No such channel!\n"));

		$this->assertSame(array('error' => 'call not found', 'code' => 404), $this->resume());
	}

	public function testRejectsAMalformedCallUuid(): void
	{
		foreach (array('f0000000; shutdown', '', array(self::CALL)) as $call_uuid) {
			$this->assertSame(array('error' => 'invalid call_uuid', 'code' => 400), $this->resume($call_uuid), json_encode($call_uuid));
		}
		$this->assertSame(array(), $this->state()['esl_commands']);
	}

	public function testAnswers500WhenFreeswitchRefuses(): void
	{
		$this->replies(array(self::dump()), "-ERR Operation failed\n");

		list($result, $logged) = $this->resumeLogged();

		$this->assertSame(array('error' => 'event socket error', 'code' => 500), $result);
		$this->assertStringContainsString('-ERR Operation failed', $logged);
	}

	public function testAnswers500WhenTheEventSocketIsUnavailable(): void
	{
		\FakeStore::update(function (&$state) {
			$state['esl_available'] = false;
		});

		list($result) = $this->resumeLogged();

		$this->assertSame(array('error' => 'event socket error', 'code' => 500), $result);
	}
}
