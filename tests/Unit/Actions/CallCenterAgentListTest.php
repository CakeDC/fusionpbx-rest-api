<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

/**
 * callcenter-agent-list: the call center agents of a domain, each with the
 * queues it serves (its tiers) and its wrap-up time.
 */
#[RunTestsInSeparateProcesses]
class CallCenterAgentListTest extends ActionTestCase
{
	private const ANA = '66666666-0000-4000-8000-000000000001';
	private const BEN = '66666666-0000-4000-8000-000000000002';
	private const NO_USER = '66666666-0000-4000-8000-000000000003';
	private const OTHER = '66666666-0000-4000-8000-000000000020';
	private const ANA_USER = 'dddddddd-0000-4000-8000-000000000001';
	private const BEN_USER = 'dddddddd-0000-4000-8000-000000000002';
	private const OTHER_USER = 'dddddddd-0000-4000-8000-000000000020';
	private const SALES = '77777777-0000-4000-8000-000000000001';
	private const SUPPORT = '77777777-0000-4000-8000-000000000002';
	private const BILLING = '77777777-0000-4000-8000-000000000003';
	private const OTHER_QUEUE = '77777777-0000-4000-8000-000000000020';
	private const OTHER_DOMAIN_UUID = 'aaaaaaaa-0000-4000-8000-000000000002';

	protected function action(): string
	{
		return 'callcenter-agent-list';
	}

	private static function tier(string $uuid, string $agent, string $queue, string $level, string $position, string $domain_uuid = self::DOMAIN_UUID): array
	{
		return array('call_center_tier_uuid' => $uuid, 'domain_uuid' => $domain_uuid, 'call_center_agent_uuid' => $agent, 'call_center_queue_uuid' => $queue, 'tier_level' => $level, 'tier_position' => $position);
	}

	protected function tables(): array
	{
		$d1 = self::DOMAIN_UUID;
		return parent::tables() + array(
			'v_call_center_agents' => array(
				array('call_center_agent_uuid' => self::BEN, 'domain_uuid' => $d1, 'user_uuid' => self::BEN_USER, 'agent_name' => 'ben', 'agent_wrap_up_time' => '', 'agent_password' => 'secret', 'agent_contact' => 'user/102@tenant1.example.com'),
				array('call_center_agent_uuid' => self::ANA, 'domain_uuid' => $d1, 'user_uuid' => self::ANA_USER, 'agent_name' => 'ana', 'agent_wrap_up_time' => '10', 'agent_password' => 'secret', 'agent_contact' => 'user/101@tenant1.example.com'),
				// a FusionPBX agent can exist without a user; the API has no way to show it
				array('call_center_agent_uuid' => self::NO_USER, 'domain_uuid' => $d1, 'user_uuid' => null, 'agent_name' => 'overflow', 'agent_wrap_up_time' => '5'),
				array('call_center_agent_uuid' => self::OTHER, 'domain_uuid' => self::OTHER_DOMAIN_UUID, 'user_uuid' => self::OTHER_USER, 'agent_name' => 'ana', 'agent_wrap_up_time' => '0'),
			),
			'v_call_center_tiers' => array(
				// served by level (a number), then position
				self::tier('55555555-0000-4000-8000-000000000001', self::ANA, self::BILLING, '10', '1'),
				self::tier('55555555-0000-4000-8000-000000000002', self::ANA, self::SUPPORT, '2', '1'),
				self::tier('55555555-0000-4000-8000-000000000003', self::ANA, self::SALES, '2', '0'),
				self::tier('55555555-0000-4000-8000-000000000004', self::NO_USER, self::SALES, '1', '1'),
				self::tier('55555555-0000-4000-8000-000000000020', self::OTHER, self::OTHER_QUEUE, '1', '1', self::OTHER_DOMAIN_UUID),
			),
		);
	}

	private function list(string $domain_uuid = self::DOMAIN_UUID): array
	{
		return $this->runAction(array('domain_uuid' => $domain_uuid));
	}

	public function testListsTheAgentsWithTheirQueuesAndWrapUpTime(): void
	{
		$this->assertSame(array('data' => array(
			array(
				'user_uuid' => self::ANA_USER,
				'queues' => array(
					array('call_center_queue_uuid' => self::SALES, 'level' => 2, 'position' => 0),
					array('call_center_queue_uuid' => self::SUPPORT, 'level' => 2, 'position' => 1),
					array('call_center_queue_uuid' => self::BILLING, 'level' => 10, 'position' => 1),
				),
				'wrap_up_time' => 10,
			),
			array('user_uuid' => self::BEN_USER, 'queues' => array(), 'wrap_up_time' => null),
		)), $this->list());
	}

	public function testOnlyListsAgentsOfTheRequestedDomain(): void
	{
		$this->assertSame(array('data' => array(
			array('user_uuid' => self::OTHER_USER, 'queues' => array(array('call_center_queue_uuid' => self::OTHER_QUEUE, 'level' => 1, 'position' => 1)), 'wrap_up_time' => 0),
		)), $this->list(self::OTHER_DOMAIN_UUID));
	}

	// one query for the tiers of every agent, not one per agent
	public function testReadsTheTiersTogether(): void
	{
		$this->list();

		$this->assertCount(2, $this->state()['queries']);
	}

	public function testReturnsAnEmptyListForADomainWithoutAgents(): void
	{
		\FakeStore::update(function (&$state) {
			$state['tables']['v_call_center_agents'] = array();
		});

		$this->assertSame(array('data' => array()), $this->list());
		$this->assertCount(1, $this->state()['queries']);
	}

	// FusionPBX's select() returns false on a database error. an empty list
	// would look like a domain without agents
	public function testAnswers500WhenTheDatabaseFails(): void
	{
		$this->failSelects();

		$this->assertSame(array('error' => 'database error', 'code' => 500), $this->list());
	}
}
