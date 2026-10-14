<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;
use RestApi\Test\Support\CallCenterAgentList;

/**
 * callcenter-agent-state: sets a call center agent's state in mod_callcenter,
 * e.g. "Waiting" to end its wrap-up. The state only lives in FreeSWITCH.
 */
#[RunTestsInSeparateProcesses]
class CallCenterAgentStateTest extends ActionTestCase
{
	private const ANA = '66666666-0000-4000-8000-000000000001';
	private const OTHER = '66666666-0000-4000-8000-000000000020';
	private const ANA_USER = 'dddddddd-0000-4000-8000-000000000001';
	private const NO_AGENT_USER = 'dddddddd-0000-4000-8000-000000000003';
	private const OTHER_USER = 'dddddddd-0000-4000-8000-000000000020';
	private const OTHER_DOMAIN_UUID = 'aaaaaaaa-0000-4000-8000-000000000002';
	private const LIST = 'api callcenter_config agent list '.self::ANA;

	protected function action(): string
	{
		return 'callcenter-agent-state';
	}

	protected function tables(): array
	{
		return parent::tables() + array(
			'v_call_center_agents' => array(
				array('call_center_agent_uuid' => self::ANA, 'domain_uuid' => self::DOMAIN_UUID, 'user_uuid' => self::ANA_USER, 'agent_name' => 'ana', 'agent_status' => 'Available'),
				array('call_center_agent_uuid' => self::OTHER, 'domain_uuid' => self::OTHER_DOMAIN_UUID, 'user_uuid' => self::OTHER_USER, 'agent_name' => 'ana', 'agent_status' => 'Available'),
			),
		);
	}

	protected function setUp(): void
	{
		parent::setUp();
		\FakeStore::update(function (&$state) {
			$state['esl_responses'][self::LIST] = CallCenterAgentList::reply(CallCenterAgentList::row(self::ANA, 'Available', 'Waiting'));
			$state['esl_response'] = "+OK\n";
		});
	}

	private function agentState($state, $user_uuid = self::ANA_USER): array
	{
		return $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'user_uuid' => $user_uuid, 'state' => $state));
	}

	/** The action's result and what it wrote to the error log. */
	private function agentStateLogged($state): array
	{
		$log = tempnam(sys_get_temp_dir(), 'rest_api_log');
		$previous = ini_set('error_log', $log);
		try {
			$result = $this->agentState($state);
		} finally {
			ini_set('error_log', $previous);
		}
		$logged = file_get_contents($log);
		unlink($log);
		return array($result, $logged);
	}

	// e.g. the end of a wrap-up: the agent takes queue calls again
	public function testSetsTheStateAndReturnsTheLiveStatusAndState(): void
	{
		$result = $this->agentState('Waiting');

		$this->assertSame(array('user_uuid' => self::ANA_USER, 'status' => 'Available', 'state' => 'Waiting', 'wrap_up_until' => null), $result);
		$this->assertSame(array("api callcenter_config agent set state ".self::ANA." 'Waiting'", self::LIST), $this->state()['esl_commands']);
		$this->assertSame(array(), $this->state()['saved'], 'FusionPBX keeps no agent state in its database');
	}

	public static function states(): array
	{
		return array(array('Waiting'), array('Receiving'), array('In a queue call'), array('Idle'), array('Reserved'));
	}

	#[DataProvider('states')]
	public function testAcceptsEveryDocumentedState(string $state): void
	{
		$this->agentState($state);

		$this->assertSame("api callcenter_config agent set state ".self::ANA." '".$state."'", $this->state()['esl_commands'][0]);
	}

	// the state goes into an event socket command, so only the known ones.
	// mod_callcenter has no wrap-up state and "Unknown" can't be set
	public function testRejectsAnUnknownState(): void
	{
		foreach (array('Wrap-up', 'Receiving a call', 'Unknown', "Waiting' ; shutdown", 'waiting', '', array('Waiting')) as $state) {
			$this->assertSame(array('error' => 'invalid state', 'code' => 400), $this->agentState($state), json_encode($state));
		}
		$this->assertSame(array(), $this->state()['esl_commands']);
	}

	public function testAnswersNotFoundForAUserWithoutAnAgentInTheDomain(): void
	{
		foreach (array(self::NO_AGENT_USER, self::OTHER_USER) as $user_uuid) {
			$this->assertSame(array('error' => 'agent not found', 'code' => 404), $this->agentState('Waiting', $user_uuid), $user_uuid);
		}
		$this->assertSame(array(), $this->state()['esl_commands']);
	}

	public function testRejectsAMalformedUserUuid(): void
	{
		foreach (array('ana', '', array(self::ANA_USER), 42) as $user_uuid) {
			$this->assertSame(array('error' => 'invalid user_uuid', 'code' => 400), $this->agentState('Waiting', $user_uuid), json_encode($user_uuid));
		}
	}

	// FusionPBX stores uuids in lower case
	public function testFindsTheAgentByAnUpperCaseUserUuid(): void
	{
		$this->assertSame(self::ANA_USER, $this->agentState('Waiting', strtoupper(self::ANA_USER))['user_uuid']);
	}

	public function testAnswers500WhenFreeswitchRefusesTheState(): void
	{
		\FakeStore::update(function (&$state) {
			$state['esl_response'] = "-ERR Invalid Agent!\n";
		});

		list($result, $logged) = $this->agentStateLogged('Waiting');

		$this->assertSame(array('error' => 'event socket error', 'code' => 500), $result);
		$this->assertStringContainsString('-ERR Invalid Agent!', $logged);
		$this->assertCount(1, $this->state()['esl_commands']);
	}

	public function testAnswers500WhenTheEventSocketIsUnavailable(): void
	{
		\FakeStore::update(function (&$state) {
			$state['esl_available'] = false;
		});

		list($result, $logged) = $this->agentStateLogged('Waiting');

		$this->assertSame(array('error' => 'event socket error', 'code' => 500), $result);
		$this->assertStringContainsString('Failed to connect to event socket', $logged);
	}

	public function testAnswers500WhenTheDatabaseFails(): void
	{
		$this->failSelects();

		$this->assertSame(array('error' => 'database error', 'code' => 500), $this->agentState('Waiting'));
		$this->assertSame(array(), $this->state()['esl_commands']);
	}
}
