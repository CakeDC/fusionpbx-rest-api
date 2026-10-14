<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

/**
 * callcenter-queue-status: live counts of a queue from mod_callcenter, which
 * names it <queue_extension>@<domain_name> like FusionPBX's Active Call Center.
 */
#[RunTestsInSeparateProcesses]
class CallCenterQueueStatusTest extends ActionTestCase
{
	private const SALES = '77777777-0000-4000-8000-000000000001';
	private const OTHER = '77777777-0000-4000-8000-000000000020';
	private const OTHER_DOMAIN_UUID = 'aaaaaaaa-0000-4000-8000-000000000002';
	private const MEMBERS = 'api callcenter_config queue list members 800@tenant1.example.com';
	private const AGENTS = 'api callcenter_config queue list agents 800@tenant1.example.com';
	private const MEMBERS_HEADER = 'queue|instance_id|uuid|session_uuid|cid_number|cid_name|system_epoch|joined_epoch|rejoined_epoch|bridge_epoch|abandoned_epoch|base_score|skill_score|serving_agent|serving_system|state|score';
	private const AGENTS_HEADER = 'name|instance_id|uuid|type|contact|status|state|max_no_answer|wrap_up_time|reject_delay_time|busy_delay_time|no_answer_delay_time|last_bridge_start|last_bridge_end|last_offered_call|last_status_change|no_answer_count|calls_answered|talk_time|ready_time|external_calls_count';

	protected function action(): string
	{
		return 'callcenter-queue-status';
	}

	protected function tables(): array
	{
		return parent::tables() + array(
			'v_call_center_queues' => array(
				array('call_center_queue_uuid' => self::SALES, 'domain_uuid' => self::DOMAIN_UUID, 'queue_name' => 'Sales', 'queue_extension' => '800'),
				array('call_center_queue_uuid' => self::OTHER, 'domain_uuid' => self::OTHER_DOMAIN_UUID, 'queue_name' => 'Sales', 'queue_extension' => '800'),
			),
		);
	}

	/** The action's result and what it wrote to the error log. */
	private function queueStatusLogged(): array
	{
		$log = tempnam(sys_get_temp_dir(), 'rest_api_log');
		$previous = ini_set('error_log', $log);
		try {
			$result = $this->queueStatus();
		} finally {
			ini_set('error_log', $previous);
		}
		$logged = file_get_contents($log);
		unlink($log);
		return array($result, $logged);
	}

	/** A caller in the queue, who joined it $waited seconds ago. */
	private static function member(string $uuid, string $state, int $waited = 60): string
	{
		$joined = time() - $waited;
		return '800@tenant1.example.com|single_box|'.$uuid.'|'.$uuid.'|5551000|Caller|'.$joined.'|'.$joined.'|0|0|0|0|0||single_box|'.$state.'|0';
	}

	private static function agent(string $name, string $status, string $state): string
	{
		return $name.'|single_box||callback|user/101@tenant1.example.com|'.$status.'|'.$state.'|3|10|10|60|0|0|0|0|1759900000|0|4|120|0|0';
	}

	// mod_callcenter prints the header with the first row only
	// (list_result_callback), so an empty list is a bare +OK
	private function respond(array $members, array $agents): void
	{
		\FakeStore::update(function (&$state) use ($members, $agents) {
			$state['esl_responses'][self::MEMBERS] = implode("\n", array_merge($members ? array(self::MEMBERS_HEADER) : array(), $members, array('+OK')))."\n";
			$state['esl_responses'][self::AGENTS] = implode("\n", array_merge($agents ? array(self::AGENTS_HEADER) : array(), $agents, array('+OK')))."\n";
		});
	}

	private function queueStatus($queue_uuid = self::SALES): array
	{
		return $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'call_center_queue_uuid' => $queue_uuid));
	}

	// members are every call in the queue; waiting ones aren't with an agent
	// yet. an available agent is Available (or on demand) and Waiting for a call
	public function testCountsWaitingCallsMembersAndAgents(): void
	{
		$this->respond(
			array(self::member('m1', 'Waiting', 30), self::member('m2', 'Answered', 300), self::member('m3', 'Waiting', 90)),
			array(
				self::agent('ana', 'Available', 'Waiting'),
				self::agent('ben', 'On Break', 'Waiting'),
				self::agent('cid', 'Available (On Demand)', 'Waiting'),
				self::agent('dan', 'Available', 'In a queue call'),
				self::agent('eve', 'Available', 'Receiving'),
				self::agent('fay', 'Logged Out', 'Waiting'),
			)
		);

		$status = $this->queueStatus();

		// the clock may tick while the action runs
		$this->assertContains($status['longest_wait_seconds'], array(90, 91, 92));
		$status['longest_wait_seconds'] = 90;
		$this->assertSame(array('call_center_queue_uuid' => self::SALES, 'waiting_calls' => 2, 'longest_wait_seconds' => 90, 'member_count' => 3, 'agent_count' => 6, 'agents_available' => 2), $status);
		$this->assertSame(array(self::MEMBERS, self::AGENTS), $this->state()['esl_commands']);
	}

	// with the real reply of mod_callcenter, a bare +OK
	public function testCountsAnEmptyQueue(): void
	{
		$this->respond(array(), array());

		$this->assertSame(array('call_center_queue_uuid' => self::SALES, 'waiting_calls' => 0, 'longest_wait_seconds' => 0, 'member_count' => 0, 'agent_count' => 0, 'agents_available' => 0), $this->queueStatus());
	}

	// callers with an agent don't wait any more
	public function testWaitsNothingWhenNobodyWaits(): void
	{
		$this->respond(array(self::member('m1', 'Answered', 300)), array(self::agent('ana', 'Available', 'In a queue call')));

		$status = $this->queueStatus();

		$this->assertSame(array(1, 0, 0), array($status['member_count'], $status['waiting_calls'], $status['longest_wait_seconds']));
	}

	// FreeSWITCH may run on another host whose clock is ahead
	public function testNeverWaitsLessThanNothing(): void
	{
		$this->respond(array(self::member('m1', 'Waiting', -5)), array());

		$this->assertSame(0, $this->queueStatus()['longest_wait_seconds']);
	}

	// FusionPBX stores uuids in lower case
	public function testFindsTheQueueByAnUpperCaseUuid(): void
	{
		$this->respond(array(), array());

		$this->assertSame(self::SALES, $this->queueStatus(strtoupper(self::SALES))['call_center_queue_uuid']);
	}

	public function testAnswersNotFoundForAQueueOfAnotherDomainOrAnUnknownOne(): void
	{
		foreach (array(self::OTHER, '77777777-0000-4000-8000-00000000ffff') as $queue_uuid) {
			$this->assertSame(array('error' => 'queue not found', 'code' => 404), $this->queueStatus($queue_uuid), $queue_uuid);
		}
		$this->assertSame(array(), $this->state()['esl_commands']);
	}

	public function testRejectsAMalformedQueueUuid(): void
	{
		foreach (array('800', '', array(self::SALES), 800) as $queue_uuid) {
			$this->assertSame(array('error' => 'invalid call_center_queue_uuid', 'code' => 400), $this->queueStatus($queue_uuid), json_encode($queue_uuid));
		}
	}

	// the queue name goes into an event socket command: one word only
	public function testRefusesAQueueNameThatIsNotOneWord(): void
	{
		\FakeStore::update(function (&$state) {
			$state['tables']['v_call_center_queues'][0]['queue_extension'] = "800\nshutdown";
		});

		$this->assertSame(array('error' => 'invalid queue extension', 'code' => 500), $this->queueStatus());
		$this->assertSame(array(), $this->state()['esl_commands']);
	}

	public function testAnswers500WhenTheEventSocketIsUnavailable(): void
	{
		\FakeStore::update(function (&$state) {
			$state['esl_available'] = false;
		});

		list($result, $logged) = $this->queueStatusLogged();

		$this->assertSame(array('error' => 'event socket error', 'code' => 500), $result);
		$this->assertStringContainsString('callcenter_config for 800@tenant1.example.com failed', $logged);
	}

	public function testAnswers500WhenFreeswitchRejectsTheCommand(): void
	{
		\FakeStore::update(function (&$state) {
			$state['esl_response'] = "-ERR callcenter_config Command not found!\n";
		});

		list($result, $logged) = $this->queueStatusLogged();

		$this->assertSame(array('error' => 'event socket error', 'code' => 500), $result);
		$this->assertStringContainsString('-ERR callcenter_config Command not found!', $logged);
	}

	public function testAnswers500WhenTheDatabaseFails(): void
	{
		$this->failSelects();

		$this->assertSame(array('error' => 'database error', 'code' => 500), $this->queueStatus());
		$this->assertSame(array(), $this->state()['esl_commands']);
	}
}
