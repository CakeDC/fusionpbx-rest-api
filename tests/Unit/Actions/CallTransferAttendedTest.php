<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

/**
 * call-transfer-attended: warm transfer with FreeSWITCH's att_xfer, run on the
 * agent's leg. The consultation is noted on the agent's channel itself
 * (channel variables), so nothing is kept between requests, and its consult
 * leg is read on every stage: att_xfer ends a consultation on its own.
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
	private const DUMP_CONSULT = 'api uuid_dump '.self::CONSULT.' json';
	private const FORGET = array(
		'api uuid_setvar '.self::AGENT.' rest_api_consult_uuid',
		'api uuid_setvar '.self::AGENT.' rest_api_consult_target',
	);

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
		return self::agent($fields + array('variable_rest_api_consult_uuid' => self::CONSULT, 'variable_rest_api_consult_target' => '102'));
	}

	/** The consult leg: by default the consulted party has answered and talks with the agent. */
	private static function consultLeg(array $fields = array()): string
	{
		return json_encode($fields + array(
			'Unique-ID' => self::CONSULT,
			'Channel-Call-State' => 'ACTIVE',
			'Answer-State' => 'answered',
			'variable_bridge_uuid' => self::AGENT,
		))."\n";
	}

	private function replies(array $agent_dumps, ?string $consult_dump = null): void
	{
		\FakeStore::update(function (&$state) use ($agent_dumps, $consult_dump) {
			$state['esl_responses'][self::DUMP_AGENT] = $agent_dumps;
			$state['esl_responses'][self::DUMP_CONSULT] = $consult_dump ?? self::consultLeg();
			$state['esl_response'] = "+OK\n";
		});
	}

	/** The consult leg is gone: att_xfer ended the consultation on its own. */
	private function consultLegGone(): void
	{
		\FakeStore::update(function (&$state) {
			$state['esl_responses'][self::DUMP_CONSULT] = "-ERR No such channel!\n";
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
	// domain's dialplan; the legs and the target are noted on the agent's
	// channel. uuid_broadcast only queues att_xfer, so the agent's call is
	// answered as the consultation makes it, held and consulting the target
	public function testConsultCallsTheTargetFromTheAgentsLeg(): void
	{
		$this->replies(array(self::agent()));

		$result = $this->transfer('consult', array('target' => '102'));

		$this->assertSame(array('call_uuid' => self::AGENT, 'domain_uuid' => self::DOMAIN_UUID, 'state' => 'held', 'caller_id_number' => '+15550001111', 'destination_number' => '101', 'consulting' => '102'), $result);
		$this->assertSame(array(
			self::DUMP_AGENT,
			'api uuid_setvar '.self::AGENT.' rest_api_consult_uuid '.self::NEW_CONSULT,
			'api uuid_setvar '.self::AGENT.' rest_api_consult_target 102',
			'api uuid_broadcast '.self::AGENT.' att_xfer::{origination_uuid='.self::NEW_CONSULT.'}loopback/102/tenant1.example.com aleg',
		), $this->commands());
	}

	// the target didn't answer, was busy or unreachable, or hung up after
	// answering: att_xfer ended the consultation without clearing the note
	public function testConsultsAgainAfterAConsultationThatEndedOnItsOwn(): void
	{
		$this->replies(array(self::consulting()));
		$this->consultLegGone();

		$result = $this->transfer('consult', array('target' => '103'));

		$this->assertSame(array('held', '103'), array($result['state'], $result['consulting']));
		$this->assertSame(array(
			self::DUMP_AGENT,
			self::DUMP_CONSULT,
			'api uuid_setvar '.self::AGENT.' rest_api_consult_uuid '.self::NEW_CONSULT,
			'api uuid_setvar '.self::AGENT.' rest_api_consult_target 103',
			'api uuid_broadcast '.self::AGENT.' att_xfer::{origination_uuid='.self::NEW_CONSULT.'}loopback/103/tenant1.example.com aleg',
		), $this->commands());
	}

	public function testConsultNeedsABridgedCall(): void
	{
		$this->replies(array(self::agent(array('Other-Leg-Unique-ID' => '', 'Channel-Call-State' => 'RINGING'))));

		$this->assertSame(array('error' => 'call is not bridged', 'code' => 400), $this->transfer('consult', array('target' => '102')));
		$this->assertSame(array(self::DUMP_AGENT), $this->commands());
	}

	public static function consultLegsInProgress(): array
	{
		return array(
			'answered' => array(array()),
			'ringing' => array(array('Channel-Call-State' => 'RINGING', 'Answer-State' => 'ringing', 'variable_bridge_uuid' => null)),
		);
	}

	#[DataProvider('consultLegsInProgress')]
	public function testConsultsOneTargetAtATime(array $leg): void
	{
		$this->replies(array(self::consulting()), self::consultLeg($leg));

		$this->assertSame(array('error' => 'consultation already in progress', 'code' => 400), $this->transfer('consult', array('target' => '103')));
		$this->assertSame(array(self::DUMP_AGENT, self::DUMP_CONSULT), $this->commands());
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

		$this->assertSame(array(self::AGENT, 'bridged', null), array($result['call_uuid'], $result['state'], $result['consulting']));
		$this->assertSame(array_merge(
			array(self::DUMP_AGENT, self::DUMP_CONSULT, 'api uuid_kill '.self::CONSULT),
			self::FORGET,
			array(self::DUMP_AGENT),
		), $this->commands());
	}

	// e.g. while the target still rings
	public function testCancelsAConsultationThatIsNotAnswered(): void
	{
		$this->replies(array(self::consulting(), self::agent()), self::consultLeg(array('Channel-Call-State' => 'RINGING', 'Answer-State' => 'ringing', 'variable_bridge_uuid' => null)));

		$this->assertSame(self::AGENT, $this->transfer('cancel')['call_uuid']);
		$this->assertContains('api uuid_kill '.self::CONSULT, $this->commands());
	}

	// the consulted party may hang up between the read and the kill
	public function testCancelsAConsultationWhoseLegGoesAway(): void
	{
		$this->replies(array(self::consulting(), self::agent()));
		\FakeStore::update(function (&$state) {
			$state['esl_responses']['api uuid_kill '.self::CONSULT] = "-ERR No such channel!\n";
		});

		$this->assertSame(self::AGENT, $this->transfer('cancel')['call_uuid']);
		$this->assertSame(self::FORGET, array_slice($this->commands(), 3, 2));
	}

	// att_xfer bridges the caller and the target when the agent's leg hangs
	// up; the agent's call is over: returned as it was, ended
	public function testCompleteDropsTheAgent(): void
	{
		$this->replies(array(self::consulting()));

		$result = $this->transfer('complete');

		$this->assertSame(array('call_uuid' => self::AGENT, 'domain_uuid' => self::DOMAIN_UUID, 'state' => 'ended', 'caller_id_number' => '+15550001111', 'destination_number' => '101', 'consulting' => null), $result);
		$this->assertSame(array(self::DUMP_AGENT, self::DUMP_CONSULT, 'api uuid_kill '.self::AGENT), $this->commands());
	}

	public static function unansweredConsultLegs(): array
	{
		return array(
			'ringing' => array(array('Channel-Call-State' => 'RINGING', 'Answer-State' => 'ringing', 'variable_bridge_uuid' => null)),
			'early media' => array(array('Channel-Call-State' => 'EARLY', 'Answer-State' => 'early', 'variable_bridge_uuid' => null)),
			'answered, not yet bridged' => array(array('variable_bridge_uuid' => null)),
			'bridged to another leg' => array(array('variable_bridge_uuid' => 'f0000000-0000-4000-8000-000000000009')),
		);
	}

	// hanging up the agent before the consulted party talks with them would
	// only end the call; the consultation goes on
	#[DataProvider('unansweredConsultLegs')]
	public function testCompleteNeedsTheConsultedPartyToHaveAnswered(array $leg): void
	{
		$this->replies(array(self::consulting()), self::consultLeg($leg));

		$this->assertSame(array('error' => 'consultation not answered', 'code' => 400), $this->transfer('complete'));
		$this->assertSame(array(self::DUMP_AGENT, self::DUMP_CONSULT), $this->commands());
	}

	public static function stagesAfterAConsultation(): array
	{
		return array(array('cancel'), array('complete'));
	}

	// the target didn't answer or hung up: att_xfer returned the agent to the
	// caller, so killing the agent's leg would drop the caller. the stale note
	// is cleared
	#[DataProvider('stagesAfterAConsultation')]
	public function testRejectsAStageAfterAConsultationThatEndedOnItsOwn(string $stage): void
	{
		$this->replies(array(self::consulting()));
		$this->consultLegGone();

		$this->assertSame(array('error' => 'no consultation in progress', 'code' => 400), $this->transfer($stage));
		$this->assertSame(array_merge(array(self::DUMP_AGENT, self::DUMP_CONSULT), self::FORGET), $this->commands());
	}

	public function testAnswers500WhenTheConsultLegCantBeRead(): void
	{
		$this->replies(array(self::consulting()), "-ERR Operation failed\n");

		list($result, $logged) = $this->transferLogged('complete');

		$this->assertSame(array('error' => 'event socket error', 'code' => 500), $result);
		$this->assertStringContainsString(self::DUMP_CONSULT, $logged);
		$this->assertNotContains('api uuid_kill '.self::AGENT, $this->commands());
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
