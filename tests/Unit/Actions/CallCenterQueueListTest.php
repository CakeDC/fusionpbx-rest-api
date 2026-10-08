<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

/**
 * callcenter-queue-list: the call center queues of a domain.
 */
#[RunTestsInSeparateProcesses]
class CallCenterQueueListTest extends ActionTestCase
{
	private const SALES = '77777777-0000-4000-8000-000000000001';
	private const SUPPORT = '77777777-0000-4000-8000-000000000002';
	private const OTHER = '77777777-0000-4000-8000-000000000020';
	private const OTHER_DOMAIN_UUID = 'aaaaaaaa-0000-4000-8000-000000000002';

	protected function action(): string
	{
		return 'callcenter-queue-list';
	}

	protected function tables(): array
	{
		return parent::tables() + array(
			'v_call_center_queues' => array(
				array('call_center_queue_uuid' => self::SUPPORT, 'domain_uuid' => self::DOMAIN_UUID, 'queue_name' => 'Support', 'queue_extension' => '801', 'queue_strategy' => 'longest-idle-agent', 'queue_tier_rule_wait_second' => '', 'queue_context' => 'tenant1.example.com', 'queue_email_address' => 'boss@example.com'),
				array('call_center_queue_uuid' => self::SALES, 'domain_uuid' => self::DOMAIN_UUID, 'queue_name' => 'Sales', 'queue_extension' => '800', 'queue_strategy' => 'ring-all', 'queue_tier_rule_wait_second' => '30', 'queue_context' => 'tenant1.example.com', 'queue_email_address' => ''),
				array('call_center_queue_uuid' => self::OTHER, 'domain_uuid' => self::OTHER_DOMAIN_UUID, 'queue_name' => 'Sales', 'queue_extension' => '800', 'queue_strategy' => 'ring-all', 'queue_tier_rule_wait_second' => null, 'queue_context' => 'tenant2.example.com'),
			),
		);
	}

	private function list(string $domain_uuid = self::DOMAIN_UUID): array
	{
		return $this->runAction(array('domain_uuid' => $domain_uuid));
	}

	// queue_tier_rules_wait_second is FusionPBX's queue_tier_rule_wait_second,
	// a number, or null when it isn't set
	public function testListsTheQueuesOfTheDomainByExtension(): void
	{
		$this->assertSame(array('data' => array(
			array('call_center_queue_uuid' => self::SALES, 'name' => 'Sales', 'extension' => '800', 'strategy' => 'ring-all', 'queue_tier_rules_wait_second' => 30),
			array('call_center_queue_uuid' => self::SUPPORT, 'name' => 'Support', 'extension' => '801', 'strategy' => 'longest-idle-agent', 'queue_tier_rules_wait_second' => null),
		)), $this->list());
	}

	public function testOnlyListsQueuesOfTheRequestedDomain(): void
	{
		$this->assertSame(array(self::OTHER), array_column($this->list(self::OTHER_DOMAIN_UUID)['data'], 'call_center_queue_uuid'));
	}

	public function testReturnsAnEmptyListForADomainWithoutQueues(): void
	{
		\FakeStore::update(function (&$state) {
			$state['tables']['v_call_center_queues'] = array();
		});

		$this->assertSame(array('data' => array()), $this->list());
	}

	// Postgres returns numeric columns as strings, possibly with decimals
	public function testReadsANumericWaitSecond(): void
	{
		\FakeStore::update(function (&$state) {
			$state['tables']['v_call_center_queues'][1]['queue_tier_rule_wait_second'] = '30.0';
		});

		$this->assertSame(30, $this->list()['data'][0]['queue_tier_rules_wait_second']);
	}

	// FusionPBX's select() returns false on a database error. an empty list
	// would look like a domain without queues
	public function testAnswers500WhenTheDatabaseFails(): void
	{
		$this->failSelects();

		$this->assertSame(array('error' => 'database error', 'code' => 500), $this->list());
	}
}
