<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

/**
 * call-hold: puts a call of the domain on hold (uuid_hold, never toggled).
 */
#[RunTestsInSeparateProcesses]
class CallHoldTest extends ActionTestCase
{
	private const CALL = 'f0000000-0000-4000-8000-000000000001';
	private const DUMP = 'api uuid_dump '.self::CALL.' json';
	private const HOLD = 'api uuid_hold '.self::CALL;

	protected function action(): string
	{
		return 'call-hold';
	}

	private static function dump(array $fields = array()): string
	{
		return json_encode($fields + array(
			'Unique-ID' => self::CALL,
			'Channel-Call-State' => 'ACTIVE',
			'Caller-Caller-ID-Number' => '101',
			'Caller-Destination-Number' => '+15550001111',
			'Other-Leg-Unique-ID' => 'f0000000-0000-4000-8000-000000000002',
			'variable_domain_uuid' => self::DOMAIN_UUID,
		))."\n";
	}

	private function replies(array $dumps, string $hold = "+OK\n"): void
	{
		\FakeStore::update(function (&$state) use ($dumps, $hold) {
			$state['esl_responses'][self::DUMP] = $dumps;
			$state['esl_responses'][self::HOLD] = $hold;
		});
	}

	protected function setUp(): void
	{
		parent::setUp();
		// before and after the hold
		$this->replies(array(self::dump(), self::dump(array('Channel-Call-State' => 'HELD'))));
	}

	private function hold($call_uuid = self::CALL): array
	{
		return $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'call_uuid' => $call_uuid));
	}

	/** The result and what the action logged. */
	private function holdLogged(): array
	{
		$log = tempnam(sys_get_temp_dir(), 'rest_api_log');
		$previous = ini_set('error_log', $log);
		try {
			$result = $this->hold();
		} finally {
			ini_set('error_log', $previous);
		}
		$logged = file_get_contents($log);
		unlink($log);
		return array($result, $logged);
	}

	public function testPutsTheCallOnHoldAndReturnsIt(): void
	{
		$this->assertSame(
			array('call_uuid' => self::CALL, 'domain_uuid' => self::DOMAIN_UUID, 'state' => 'held', 'caller_id_number' => '101', 'destination_number' => '+15550001111'),
			$this->hold()
		);
		$this->assertSame(array(self::DUMP, self::HOLD, self::DUMP), $this->state()['esl_commands']);
	}

	// FreeSWITCH would refuse to hold a held call again
	public function testHoldingAHeldCallIsHarmless(): void
	{
		$this->replies(array(self::dump(array('Channel-Call-State' => 'HELD'))));

		$this->assertSame('held', $this->hold()['state']);
		$this->assertSame(array(self::DUMP), $this->state()['esl_commands']);
	}

	public function testAnswersNotFoundForACallOfAnotherDomain(): void
	{
		$this->replies(array(self::dump(array('variable_domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000002'))));

		$this->assertSame(array('error' => 'call not found', 'code' => 404), $this->hold());
		$this->assertSame(array(self::DUMP), $this->state()['esl_commands']);
	}

	public function testAnswersNotFoundForAnUnknownCall(): void
	{
		$this->replies(array("-ERR No such channel!\n"));

		$this->assertSame(array('error' => 'call not found', 'code' => 404), $this->hold());
	}

	public function testRejectsAMalformedCallUuid(): void
	{
		foreach (array('f0000000; shutdown', '', array(self::CALL)) as $call_uuid) {
			$this->assertSame(array('error' => 'invalid call_uuid', 'code' => 400), $this->hold($call_uuid), json_encode($call_uuid));
		}
		$this->assertSame(array(), $this->state()['esl_commands']);
	}

	// e.g. a call that isn't answered yet
	public function testAnswers500WhenFreeswitchRefusesToHold(): void
	{
		$this->replies(array(self::dump(array('Channel-Call-State' => 'RINGING'))), "-ERR Operation failed\n");

		list($result, $logged) = $this->holdLogged();

		$this->assertSame(array('error' => 'event socket error', 'code' => 500), $result);
		$this->assertStringContainsString('-ERR Operation failed', $logged);
	}

	public function testAnswers500WhenTheEventSocketIsUnavailable(): void
	{
		\FakeStore::update(function (&$state) {
			$state['esl_available'] = false;
		});

		list($result) = $this->holdLogged();

		$this->assertSame(array('error' => 'event socket error', 'code' => 500), $result);
	}
}
