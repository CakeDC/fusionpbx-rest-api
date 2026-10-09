<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;
use RestApi\Test\Support\CallCenterAgentList;

/**
 * callcenter-agent-status: reads, or with "status" sets, a call center agent's
 * status in mod_callcenter, which knows the agent by its call_center_agent_uuid
 * (as FusionPBX's agent status page does). The live status, state and wrap-up
 * come from one "agent list" of the agent.
 */
#[RunTestsInSeparateProcesses]
class CallCenterAgentStatusTest extends ActionTestCase
{
	private const ANA = '66666666-0000-4000-8000-000000000001';
	private const ANA_SECOND = '66666666-0000-4000-8000-000000000002';
	private const OTHER = '66666666-0000-4000-8000-000000000020';
	private const ANA_USER = 'dddddddd-0000-4000-8000-000000000001';
	private const NO_AGENT_USER = 'dddddddd-0000-4000-8000-000000000003';
	private const OTHER_USER = 'dddddddd-0000-4000-8000-000000000020';
	private const OTHER_DOMAIN_UUID = 'aaaaaaaa-0000-4000-8000-000000000002';
	private const LIST = 'api callcenter_config agent list '.self::ANA;

	protected function action(): string
	{
		return 'callcenter-agent-status';
	}

	protected function tables(): array
	{
		return parent::tables() + array(
			'v_call_center_agents' => array(
				// a second agent of the same user sorts after "ana"
				array('call_center_agent_uuid' => self::ANA_SECOND, 'domain_uuid' => self::DOMAIN_UUID, 'user_uuid' => self::ANA_USER, 'agent_name' => 'ana-overflow', 'agent_status' => 'Logged Out'),
				array('call_center_agent_uuid' => self::ANA, 'domain_uuid' => self::DOMAIN_UUID, 'user_uuid' => self::ANA_USER, 'agent_name' => 'ana', 'agent_status' => 'Logged Out'),
				array('call_center_agent_uuid' => self::OTHER, 'domain_uuid' => self::OTHER_DOMAIN_UUID, 'user_uuid' => self::OTHER_USER, 'agent_name' => 'ana', 'agent_status' => 'Available'),
			),
		);
	}

	protected function setUp(): void
	{
		parent::setUp();
		$this->grantOnly(array('call_center_agent_view', 'call_center_agent_edit'));
		$this->live('Logged Out', 'Waiting');
	}

	/** What mod_callcenter lists for the agent. */
	private function live(string $status, string $state, array $times = array()): void
	{
		$reply = CallCenterAgentList::reply(CallCenterAgentList::row(self::ANA, $status, $state, $times));
		\FakeStore::update(function (&$store) use ($reply) {
			$store['esl_responses'][self::LIST] = $reply;
			$store['esl_response'] = "+OK\n";
		});
	}

	private function agentStatus(array $fields = array(), $user_uuid = self::ANA_USER): array
	{
		return $this->runAction($fields + array('domain_uuid' => self::DOMAIN_UUID, 'user_uuid' => $user_uuid));
	}

	private function savedStatus(string $agent_uuid = self::ANA): string
	{
		foreach ($this->state()['tables']['v_call_center_agents'] as $row) {
			if ($row['call_center_agent_uuid'] === $agent_uuid) {
				return $row['agent_status'];
			}
		}
		$this->fail('no agent '.$agent_uuid);
	}

	/** The action's result and what it wrote to the error log. */
	private function agentStatusLogged(array $fields): array
	{
		$log = tempnam(sys_get_temp_dir(), 'rest_api_log');
		$previous = ini_set('error_log', $log);
		try {
			$result = $this->agentStatus($fields);
		} finally {
			ini_set('error_log', $previous);
		}
		$logged = file_get_contents($log);
		unlink($log);
		return array($result, $logged);
	}

	public function testReadsTheLiveStatusAndState(): void
	{
		$this->live('On Break', 'Waiting');

		$this->assertSame(array('user_uuid' => self::ANA_USER, 'status' => 'On Break', 'state' => 'Waiting', 'wrap_up_until' => null), $this->agentStatus());
		$this->assertSame(array(self::LIST), $this->state()['esl_commands']);
		$this->assertSame(array(), $this->state()['saved']);
	}

	// after a call mod_callcenter offers the agent nothing until its wrap-up
	// time is over, though its state stays Waiting
	public function testReportsWhenTheWrapUpEnds(): void
	{
		$ended = time() - 4;
		$this->live('Available', 'Waiting', array('wrap_up_time' => 30, 'last_bridge_end' => $ended));

		$this->assertSame(array('user_uuid' => self::ANA_USER, 'status' => 'Available', 'state' => 'Waiting', 'wrap_up_until' => gmdate('Y-m-d\TH:i:s\Z', $ended + 30)), $this->agentStatus());
	}

	// a reject, busy or no-answer delay sets ready_time; the later one counts,
	// as when mod_callcenter offers a call
	public function testReportsALaterReadyTimeAsTheEndOfTheWrapUp(): void
	{
		$ready = time() + 50;
		$this->live('Available', 'Waiting', array('wrap_up_time' => 30, 'last_bridge_end' => time() - 4, 'ready_time' => $ready));

		$this->assertSame(gmdate('Y-m-d\TH:i:s\Z', $ready), $this->agentStatus()['wrap_up_until']);
	}

	public static function agentsNotInWrapUp(): array
	{
		return array(
			'never on a call' => array(array()),
			'wrap-up over' => array(array('wrap_up_time' => 30, 'last_bridge_end' => time() - 31)),
			'no wrap-up time' => array(array('wrap_up_time' => 0, 'last_bridge_end' => time() - 1)),
			'ready time past' => array(array('ready_time' => time() - 1)),
		);
	}

	#[DataProvider('agentsNotInWrapUp')]
	public function testReportsNoWrapUpWhenItIsOver(array $times): void
	{
		$this->live('Available', 'Waiting', $times);

		$this->assertNull($this->agentStatus()['wrap_up_until']);
	}

	// only the agent's row counts, whatever else mod_callcenter lists
	public function testReadsTheRowOfTheAgent(): void
	{
		\FakeStore::update(function (&$store) {
			$store['esl_responses'][self::LIST] = CallCenterAgentList::reply(
				CallCenterAgentList::row(self::ANA_SECOND, 'Available', 'Receiving'),
				CallCenterAgentList::row(self::ANA, 'On Break', 'Idle')
			);
		});

		$result = $this->agentStatus();

		$this->assertSame(array('On Break', 'Idle'), array($result['status'], $result['state']));
	}

	// FusionPBX loads every agent into mod_callcenter; one it doesn't know is
	// listed as nothing
	public function testAnswers500ForAnAgentMissingInFreeswitch(): void
	{
		\FakeStore::update(function (&$store) {
			$store['esl_responses'][self::LIST] = CallCenterAgentList::reply();
		});

		list($result, $logged) = $this->agentStatusLogged(array());

		$this->assertSame(array('error' => 'event socket error', 'code' => 500), $result);
		$this->assertStringContainsString(self::ANA.' is not loaded in mod_callcenter', $logged);
	}

	// reading only needs call_center_agent_view
	public function testReadsWithoutTheEditPermission(): void
	{
		$this->grantOnly(array('call_center_agent_view'));

		$this->assertSame('Logged Out', $this->agentStatus()['status']);
	}

	public function testSetsTheStatus(): void
	{
		$this->live('On Break', 'Waiting');

		$result = $this->agentStatus(array('status' => 'On Break'));

		$this->assertSame(array('user_uuid' => self::ANA_USER, 'status' => 'On Break', 'state' => 'Waiting', 'wrap_up_until' => null), $result);
		$this->assertSame(array("api callcenter_config agent set status ".self::ANA." 'On Break'", self::LIST), $this->state()['esl_commands']);
	}

	// the configuration FreeSWITCH reloads takes the status from the agent's row
	public function testSavesTheStatusSoItSurvivesARestart(): void
	{
		$this->agentStatus(array('status' => 'Available (On Demand)'));

		$this->assertSame('Available (On Demand)', $this->savedStatus());
		$this->assertSame('Logged Out', $this->savedStatus(self::ANA_SECOND));
	}

	public static function statusesThatResetTheState(): array
	{
		return array('available' => array('Available'), 'logged out' => array('Logged Out'));
	}

	// as FusionPBX's agent status page does
	#[DataProvider('statusesThatResetTheState')]
	public function testPutsTheAgentBackToWaiting(string $status): void
	{
		$this->agentStatus(array('status' => $status));

		$this->assertSame(array(
			"api callcenter_config agent set status ".self::ANA." '".$status."'",
			"api callcenter_config agent set state ".self::ANA." 'Waiting'",
			self::LIST,
		), $this->state()['esl_commands']);
	}

	public function testRequiresTheEditPermissionToSet(): void
	{
		$this->grantOnly(array('call_center_agent_view'));

		$this->assertSame(array('error' => 'forbidden', 'missing_permissions' => array('call_center_agent_edit'), 'code' => 403), $this->agentStatus(array('status' => 'On Break')));
		$this->assertSame(array(), $this->state()['esl_commands']);
	}

	public function testRejectsAnUnknownStatus(): void
	{
		foreach (array('Do Not Disturb', "On Break' ; shutdown", 'available', array('On Break')) as $status) {
			$this->assertSame(array('error' => 'invalid status', 'code' => 400), $this->agentStatus(array('status' => $status)), json_encode($status));
		}
		$this->assertSame(array(), $this->state()['esl_commands']);
	}

	public function testAnswersNotFoundForAUserWithoutAnAgentInTheDomain(): void
	{
		foreach (array(self::NO_AGENT_USER, self::OTHER_USER) as $user_uuid) {
			$this->assertSame(array('error' => 'agent not found', 'code' => 404), $this->agentStatus(array(), $user_uuid), $user_uuid);
		}
		$this->assertSame(array(), $this->state()['esl_commands']);
	}

	public function testRejectsAMalformedUserUuid(): void
	{
		foreach (array('ana', '', array(self::ANA_USER), 42) as $user_uuid) {
			$this->assertSame(array('error' => 'invalid user_uuid', 'code' => 400), $this->agentStatus(array(), $user_uuid), json_encode($user_uuid));
		}
	}

	// FusionPBX stores uuids in lower case
	public function testFindsTheAgentByAnUpperCaseUserUuid(): void
	{
		$this->assertSame(self::ANA_USER, $this->agentStatus(array(), strtoupper(self::ANA_USER))['user_uuid']);
	}

	// the status isn't saved when FreeSWITCH didn't take it
	public function testAnswers500WhenFreeswitchRefusesTheStatus(): void
	{
		\FakeStore::update(function (&$state) {
			$state['esl_response'] = "-ERR Invalid Agent!\n";
		});

		list($result, $logged) = $this->agentStatusLogged(array('status' => 'On Break'));

		$this->assertSame(array('error' => 'event socket error', 'code' => 500), $result);
		$this->assertStringContainsString('-ERR Invalid Agent!', $logged);
		$this->assertSame('Logged Out', $this->savedStatus());
	}

	public function testAnswers500WhenTheEventSocketIsUnavailable(): void
	{
		\FakeStore::update(function (&$state) {
			$state['esl_available'] = false;
		});

		list($result, $logged) = $this->agentStatusLogged(array());

		$this->assertSame(array('error' => 'event socket error', 'code' => 500), $result);
		$this->assertStringContainsString('Failed to connect to event socket', $logged);
	}

	public function testAnswers500WhenTheDatabaseFails(): void
	{
		$this->failSelects();

		$this->assertSame(array('error' => 'database error', 'code' => 500), $this->agentStatus());
		$this->assertSame(array(), $this->state()['esl_commands']);
	}

	public function testAnswers500WhenTheSaveFails(): void
	{
		$this->failSaves();

		$this->assertSame(array('error' => 'error saving agent status', 'code' => 500), $this->agentStatus(array('status' => 'On Break')));
	}
}
