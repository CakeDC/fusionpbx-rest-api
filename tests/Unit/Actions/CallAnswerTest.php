<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

/**
 * call-answer: answers a ringing call (uuid_answer). A call_uuid is global to
 * FreeSWITCH, so the channel's domain is checked first (uuid_dump).
 */
#[RunTestsInSeparateProcesses]
class CallAnswerTest extends ActionTestCase
{
	private const CALL = 'f0000000-0000-4000-8000-000000000001';
	private const DUMP = 'api uuid_dump '.self::CALL.' json';
	private const ANSWER = 'api uuid_answer '.self::CALL;

	protected function action(): string
	{
		return 'call-answer';
	}

	private static function dump(array $fields = array()): string
	{
		return json_encode($fields + array(
			'Unique-ID' => self::CALL,
			'Channel-Call-State' => 'RINGING',
			'Caller-Caller-ID-Number' => '+15550001111',
			'Caller-Destination-Number' => '101',
			'variable_domain_uuid' => self::DOMAIN_UUID,
		))."\n";
	}

	protected function setUp(): void
	{
		parent::setUp();
		// before and after the answer
		$this->replies(array(self::dump(), self::dump(array('Channel-Call-State' => 'ACTIVE'))));
	}

	private function replies(array $dumps, string $answer = "+OK\n"): void
	{
		\FakeStore::update(function (&$state) use ($dumps, $answer) {
			$state['esl_responses'][self::DUMP] = $dumps;
			$state['esl_responses'][self::ANSWER] = $answer;
		});
	}

	private function answer($call_uuid = self::CALL): array
	{
		return $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'call_uuid' => $call_uuid));
	}

	/** The result, with what the action logs kept out of the test output. */
	private function answerLogged(): array
	{
		$log = tempnam(sys_get_temp_dir(), 'rest_api_log');
		$previous = ini_set('error_log', $log);
		try {
			$result = $this->answer();
		} finally {
			ini_set('error_log', $previous);
		}
		$logged = file_get_contents($log);
		unlink($log);
		return array($result, $logged);
	}

	public function testAnswersTheCallAndReturnsIt(): void
	{
		$this->assertSame(
			array('call_uuid' => self::CALL, 'domain_uuid' => self::DOMAIN_UUID, 'state' => 'answered', 'caller_id_number' => '+15550001111', 'destination_number' => '101', 'consulting' => null),
			$this->answer()
		);
		$this->assertSame(array(self::DUMP, self::ANSWER, self::DUMP), $this->state()['esl_commands']);
	}

	// a call_uuid is global to FreeSWITCH: a call of another domain is not touched
	public function testAnswersNotFoundForACallOfAnotherDomain(): void
	{
		$this->replies(array(self::dump(array('variable_domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000002'))));

		$this->assertSame(array('error' => 'call not found', 'code' => 404), $this->answer());
		$this->assertSame(array(self::DUMP), $this->state()['esl_commands']);
	}

	// a channel without a domain can't be checked, so it isn't touched either
	public function testAnswersNotFoundForACallWithoutADomain(): void
	{
		$this->replies(array(json_encode(array('Unique-ID' => self::CALL, 'Channel-Call-State' => 'RINGING'))));

		$this->assertSame(array('error' => 'call not found', 'code' => 404), $this->answer());
		$this->assertSame(array(self::DUMP), $this->state()['esl_commands']);
	}

	public function testAnswersNotFoundForAnUnknownCall(): void
	{
		$this->replies(array("-ERR No such channel!\n"));

		$this->assertSame(array('error' => 'call not found', 'code' => 404), $this->answer());
	}

	public function testRejectsAMalformedCallUuid(): void
	{
		foreach (array('f0000000; shutdown', '', array(self::CALL), 42) as $call_uuid) {
			$this->assertSame(array('error' => 'invalid call_uuid', 'code' => 400), $this->answer($call_uuid), json_encode($call_uuid));
		}
		$this->assertSame(array(), $this->state()['esl_commands']);
	}

	// FreeSWITCH's uuids are lower case
	public function testAcceptsAnUpperCaseCallUuid(): void
	{
		$this->assertSame(self::CALL, $this->answer(strtoupper(self::CALL))['call_uuid']);
		$this->assertSame(array(self::DUMP, self::ANSWER, self::DUMP), $this->state()['esl_commands']);
	}

	public function testAnswers500WhenFreeswitchRefusesToAnswer(): void
	{
		$this->replies(array(self::dump()), "-ERR uuid_answer failed\n");

		list($result, $logged) = $this->answerLogged();

		$this->assertSame(array('error' => 'event socket error', 'code' => 500), $result);
		$this->assertStringContainsString('-ERR uuid_answer failed', $logged);
	}

	public function testAnswers500WhenTheEventSocketIsUnavailable(): void
	{
		\FakeStore::update(function (&$state) {
			$state['esl_available'] = false;
		});

		list($result, $logged) = $this->answerLogged();

		$this->assertSame(array('error' => 'event socket error', 'code' => 500), $result);
		$this->assertStringContainsString('Failed to connect to event socket', $logged);
	}
}
