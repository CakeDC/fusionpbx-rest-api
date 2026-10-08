<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

/**
 * call-transfer-attended: warm transfer with FreeSWITCH's att_xfer, run on the
 * agent's leg. The consultation is tracked on the agent's channel itself
 * (channel variables), so nothing is kept between requests.
 */
#[RunTestsInSeparateProcesses]
class CallTransferAttendedTest extends ActionTestCase
{
	private const AGENT = 'f0000000-0000-4000-8000-000000000001';
	private const CALLER = 'f0000000-0000-4000-8000-000000000002';
	private const CONSULT = 'f0000000-0000-4000-8000-000000000003';
	// the stand-in's first uuid()
	private const NEW_CONSULT = '00000000-0000-4000-8000-000000000001';
	private const DUMP_AGENT = 'api uuid_dump '.self::AGENT.' json';
	private const DUMP_CALLER = 'api uuid_dump '.self::CALLER.' json';

	protected function action(): string
	{
		return 'call-transfer-attended';
	}

	private static function agent(array $fields = array()): string
	{
		return json_encode($fields + array(
			'Unique-ID' => self::AGENT,
			'Channel-Call-State' => 'ACTIVE',
			'Caller-Caller-ID-Number' => '+15550001111',
			'Caller-Destination-Number' => '101',
			'Other-Leg-Unique-ID' => self::CALLER,
			'variable_domain_uuid' => self::DOMAIN_UUID,
		))."\n";
	}

	/** The agent's channel while a consultation is in progress. */
	private static function consulting(array $fields = array()): string
	{
		return self::agent($fields + array('variable_rest_api_consult_uuid' => self::CONSULT, 'variable_rest_api_consult_caller' => self::CALLER));
	}

	private function replies(array $agent_dumps, ?string $caller_dump = null): void
	{
		\FakeStore::update(function (&$state) use ($agent_dumps, $caller_dump) {
			$state['esl_responses'][self::DUMP_AGENT] = $agent_dumps;
			$state['esl_responses'][self::DUMP_CALLER] = $caller_dump ?? "-ERR No such channel!\n";
			$state['esl_response'] = "+OK\n";
		});
	}

	private function transfer(string $stage, array $fields = array(), $call_uuid = self::AGENT): array
	{
		return $this->runAction($fields + array('domain_uuid' => self::DOMAIN_UUID, 'call_uuid' => $call_uuid, 'stage' => $stage));
	}

	/** The result and what the action logged. */
	private function transferLogged(string $stage, array $fields = array()): array
	{
		$log = tempnam(sys_get_temp_dir(), 'rest_api_log');
		$previous = ini_set('error_log', $log);
		try {
			$result = $this->transfer($stage, $fields);
		} finally {
			ini_set('error_log', $previous);
		}
		$logged = file_get_contents($log);
		unlink($log);
		return array($result, $logged);
	}

	private function commands(): array
	{
		return $this->state()['esl_commands'];
	}

	// att_xfer holds the caller with music and calls the target through the
	// domain's dialplan; the legs are noted on the agent's channel
	public function testConsultCallsTheTargetFromTheAgentsLeg(): void
	{
		$this->replies(array(self::agent(), self::consulting()));

		$result = $this->transfer('consult', array('target' => '102'));

		$this->assertSame(array('call_uuid' => self::AGENT, 'domain_uuid' => self::DOMAIN_UUID, 'state' => 'bridged', 'caller_id_number' => '+15550001111', 'destination_number' => '101'), $result);
		$this->assertSame(array(
			self::DUMP_AGENT,
			'api uuid_setvar '.self::AGENT.' rest_api_consult_uuid '.self::NEW_CONSULT,
			'api uuid_setvar '.self::AGENT.' rest_api_consult_caller '.self::CALLER,
			'api uuid_broadcast '.self::AGENT.' att_xfer::{origination_uuid='.self::NEW_CONSULT.'}loopback/102/tenant1.example.com aleg',
			self::DUMP_AGENT,
		), $this->commands());
	}

	public function testConsultNeedsABridgedCall(): void
	{
		$this->replies(array(self::agent(array('Other-Leg-Unique-ID' => '', 'Channel-Call-State' => 'RINGING'))));

		$this->assertSame(array('error' => 'call is not bridged', 'code' => 400), $this->transfer('consult', array('target' => '102')));
		$this->assertSame(array(self::DUMP_AGENT), $this->commands());
	}

	public function testConsultsOneTargetAtATime(): void
	{
		$this->replies(array(self::consulting()));

		$this->assertSame(array('error' => 'consultation already in progress', 'code' => 400), $this->transfer('consult', array('target' => '103')));
		$this->assertSame(array(self::DUMP_AGENT), $this->commands());
	}

	// the target goes into a dial string
	public function testRejectsAnInvalidOrMissingTarget(): void
	{
		$this->replies(array(self::agent()));

		foreach (array(array('target' => '102}user/x'), array('target' => ''), array()) as $fields) {
			$this->assertSame(array('error' => 'invalid target', 'code' => 400), $this->transfer('consult', $fields), json_encode($fields));
		}
		$this->assertSame(array(), $this->commands());
	}

	// att_xfer returns the agent to the caller when the consult leg hangs up
	public function testCancelHangsUpTheConsultation(): void
	{
		$this->replies(array(self::consulting(), self::agent()));

		$result = $this->transfer('cancel');

		$this->assertSame(array(self::AGENT, 'bridged'), array($result['call_uuid'], $result['state']));
		$this->assertSame(array(
			self::DUMP_AGENT,
			'api uuid_kill '.self::CONSULT,
			'api uuid_setvar '.self::AGENT.' rest_api_consult_uuid',
			'api uuid_setvar '.self::AGENT.' rest_api_consult_caller',
			self::DUMP_AGENT,
		), $this->commands());
	}

	// the consulted party may have hung up already
	public function testCancelsAConsultationWhoseLegIsGone(): void
	{
		$this->replies(array(self::consulting(), self::agent()));
		\FakeStore::update(function (&$state) {
			$state['esl_responses']['api uuid_kill '.self::CONSULT] = "-ERR No such channel!\n";
		});

		$this->assertSame(self::AGENT, $this->transfer('cancel')['call_uuid']);
	}

	// att_xfer bridges the caller and the target when the agent's leg hangs up
	public function testCompleteDropsTheAgent(): void
	{
		$this->replies(array(self::consulting()), json_encode(array(
			'Unique-ID' => self::CALLER, 'Channel-Call-State' => 'ACTIVE', 'Caller-Caller-ID-Number' => '+15550001111',
			'Caller-Destination-Number' => '101', 'Other-Leg-Unique-ID' => self::CONSULT, 'variable_domain_uuid' => self::DOMAIN_UUID,
		)));

		$result = $this->transfer('complete');

		$this->assertSame(array('call_uuid' => self::CALLER, 'domain_uuid' => self::DOMAIN_UUID, 'state' => 'bridged', 'caller_id_number' => '+15550001111', 'destination_number' => '101'), $result);
		$this->assertSame(array(self::DUMP_AGENT, 'api uuid_kill '.self::AGENT, self::DUMP_CALLER), $this->commands());
	}

	public function testCompleteReportsACallerThatIsGoneAsEnded(): void
	{
		$this->replies(array(self::consulting()));

		$this->assertSame(array('call_uuid' => self::CALLER, 'domain_uuid' => self::DOMAIN_UUID, 'state' => 'ended', 'caller_id_number' => null, 'destination_number' => null), $this->transfer('complete'));
	}

	public static function stagesNeedingAConsultation(): array
	{
		return array(array('cancel'), array('complete'));
	}

	#[DataProvider('stagesNeedingAConsultation')]
	public function testRejectsAStageWithoutAConsultationInProgress(string $stage): void
	{
		$this->replies(array(self::agent()));

		$this->assertSame(array('error' => 'no consultation in progress', 'code' => 400), $this->transfer($stage));
		$this->assertSame(array(self::DUMP_AGENT), $this->commands());
	}

	public function testRejectsAnUnknownStage(): void
	{
		foreach (array('start', '', array('consult')) as $stage) {
			$this->assertSame(array('error' => 'invalid stage', 'code' => 400), $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'call_uuid' => self::AGENT, 'stage' => $stage)), json_encode($stage));
		}
		$this->assertSame(array(), $this->commands());
	}

	public function testAnswersNotFoundForACallOfAnotherDomain(): void
	{
		$this->replies(array(self::consulting(array('variable_domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000002'))));

		$this->assertSame(array('error' => 'call not found', 'code' => 404), $this->transfer('complete'));
		$this->assertSame(array(self::DUMP_AGENT), $this->commands());
	}

	public function testRejectsAMalformedCallUuid(): void
	{
		$this->assertSame(array('error' => 'invalid call_uuid', 'code' => 400), $this->transfer('cancel', array(), 'f0000000; shutdown'));
	}

	public function testAnswers500WhenFreeswitchRefuses(): void
	{
		$this->replies(array(self::agent()));
		\FakeStore::update(function (&$state) {
			$state['esl_response'] = "-ERR Operation failed\n";
		});

		list($result, $logged) = $this->transferLogged('consult', array('target' => '102'));

		$this->assertSame(array('error' => 'event socket error', 'code' => 500), $result);
		$this->assertStringContainsString('uuid_setvar', $logged);
	}

	public function testAnswers500WhenTheDatabaseFails(): void
	{
		$this->replies(array(self::agent()));
		$this->failSelects();

		$this->assertSame(array('error' => 'database error', 'code' => 500), $this->transfer('consult', array('target' => '102')));
		$this->assertStringNotContainsString('att_xfer', implode("\n", $this->commands()));
	}
}
