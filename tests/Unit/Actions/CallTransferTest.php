<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

/**
 * call-transfer: blind transfer. call_uuid is the agent's call: when it is
 * bridged the other party is transferred (uuid_transfer -bleg, as FusionPBX's
 * active calls page parks a call), otherwise the channel itself.
 */
#[RunTestsInSeparateProcesses]
class CallTransferTest extends ActionTestCase
{
	private const CALL = 'f0000000-0000-4000-8000-000000000001';
	private const OTHER_LEG = 'f0000000-0000-4000-8000-000000000002';
	private const RING_GROUP = '99999999-0000-4000-8000-000000000001';
	private const QUEUE = '77777777-0000-4000-8000-000000000001';
	private const OTHER_DOMAIN_UUID = 'aaaaaaaa-0000-4000-8000-000000000002';

	protected function action(): string
	{
		return 'call-transfer';
	}

	protected function tables(): array
	{
		return parent::tables() + array(
			'v_extensions' => array(
				array('extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000102', 'domain_uuid' => self::DOMAIN_UUID, 'extension' => '102', 'number_alias' => '1102', 'user_context' => 'tenant1.example.com'),
				array('extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000200', 'domain_uuid' => self::OTHER_DOMAIN_UUID, 'extension' => '200', 'number_alias' => '', 'user_context' => 'tenant2.example.com'),
			),
			'v_ring_groups' => array(
				array('ring_group_uuid' => self::RING_GROUP, 'domain_uuid' => self::DOMAIN_UUID, 'ring_group_extension' => '600', 'ring_group_context' => 'tenant1.example.com'),
				array('ring_group_uuid' => '99999999-0000-4000-8000-000000000002', 'domain_uuid' => self::OTHER_DOMAIN_UUID, 'ring_group_extension' => '600', 'ring_group_context' => 'tenant2.example.com'),
			),
			'v_call_center_queues' => array(
				array('call_center_queue_uuid' => self::QUEUE, 'domain_uuid' => self::DOMAIN_UUID, 'queue_extension' => '800', 'queue_context' => null),
			),
		);
	}

	private static function dump(string $uuid, array $fields = array()): string
	{
		return json_encode($fields + array(
			'Unique-ID' => $uuid,
			'Channel-Call-State' => 'ACTIVE',
			'Caller-Caller-ID-Number' => '+15550001111',
			'Caller-Destination-Number' => '101',
			'Other-Leg-Unique-ID' => $uuid === self::CALL ? self::OTHER_LEG : self::CALL,
			'variable_domain_uuid' => self::DOMAIN_UUID,
		))."\n";
	}

	private function replies(string $call_dump, string $other_dump, string $transfer = "+OK\n"): void
	{
		\FakeStore::update(function (&$state) use ($call_dump, $other_dump, $transfer) {
			$state['esl_responses']['api uuid_dump '.self::CALL.' json'] = $call_dump;
			$state['esl_responses']['api uuid_dump '.self::OTHER_LEG.' json'] = $other_dump;
			$state['esl_response'] = $transfer;
		});
	}

	protected function setUp(): void
	{
		parent::setUp();
		// after the transfer the other party rings the target
		$this->replies(self::dump(self::CALL), self::dump(self::OTHER_LEG, array('Channel-Call-State' => 'RINGING', 'Other-Leg-Unique-ID' => '')));
	}

	private function transfer(string $type, $target, $call_uuid = self::CALL): array
	{
		return $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'call_uuid' => $call_uuid, 'target_type' => $type, 'target' => $target));
	}

	/** The result and what the action logged. */
	private function transferLogged(string $type, $target): array
	{
		$log = tempnam(sys_get_temp_dir(), 'rest_api_log');
		$previous = ini_set('error_log', $log);
		try {
			$result = $this->transfer($type, $target);
		} finally {
			ini_set('error_log', $previous);
		}
		$logged = file_get_contents($log);
		unlink($log);
		return array($result, $logged);
	}

	public static function targets(): array
	{
		return array(
			'extension' => array('extension', '102', '102 XML tenant1.example.com'),
			'extension alias' => array('extension', '1102', '1102 XML tenant1.example.com'),
			'ring group' => array('ring_group', self::RING_GROUP, '600 XML tenant1.example.com'),
			'queue without its own context' => array('queue', self::QUEUE, '800 XML tenant1.example.com'),
		);
	}

	// the other party of a bridged call is sent to the target
	#[DataProvider('targets')]
	public function testTransfersTheOtherPartyOfABridgedCall(string $type, string $target, string $destination): void
	{
		$result = $this->transfer($type, $target);

		$this->assertSame(
			array('call_uuid' => self::OTHER_LEG, 'domain_uuid' => self::DOMAIN_UUID, 'state' => 'ringing', 'caller_id_number' => '+15550001111', 'destination_number' => '101'),
			$result
		);
		$this->assertContains('api uuid_transfer '.self::CALL.' -bleg '.$destination, $this->state()['esl_commands']);
	}

	// e.g. a call ringing the agent that isn't answered yet
	public function testTransfersTheChannelItselfWhenItIsNotBridged(): void
	{
		$this->replies(self::dump(self::CALL, array('Channel-Call-State' => 'RINGING', 'Other-Leg-Unique-ID' => '')), "-ERR No such channel!\n");
		\FakeStore::update(function (&$state) {
			$state['esl_responses']['api uuid_dump '.self::CALL.' json'] = array(self::dump(self::CALL, array('Channel-Call-State' => 'RINGING', 'Other-Leg-Unique-ID' => '')), self::dump(self::CALL, array('Channel-Call-State' => 'RINGING', 'Other-Leg-Unique-ID' => '', 'Caller-Destination-Number' => '102')));
		});

		$result = $this->transfer('extension', '102');

		$this->assertSame(array(self::CALL, 'ringing', '102'), array($result['call_uuid'], $result['state'], $result['destination_number']));
		$this->assertContains('api uuid_transfer '.self::CALL.' 102 XML tenant1.example.com', $this->state()['esl_commands']);
	}

	// the transferred leg may already be gone when it is read again
	public function testReportsATransferredLegThatIsGoneAsEnded(): void
	{
		$this->replies(self::dump(self::CALL), "-ERR No such channel!\n");

		$result = $this->transfer('extension', '102');

		$this->assertSame(array('call_uuid' => self::OTHER_LEG, 'domain_uuid' => self::DOMAIN_UUID, 'state' => 'ended', 'caller_id_number' => null, 'destination_number' => null), $result);
	}

	public static function missingTargets(): array
	{
		return array(
			'extension of another domain' => array('extension', '200'),
			'unknown extension' => array('extension', '999'),
			'ring group of another domain' => array('ring_group', '99999999-0000-4000-8000-000000000002'),
			'unknown queue' => array('queue', '77777777-0000-4000-8000-00000000ffff'),
		);
	}

	#[DataProvider('missingTargets')]
	public function testAnswersNotFoundForATargetOutsideTheDomain(string $type, string $target): void
	{
		$this->assertSame(array('error' => 'target not found', 'code' => 404), $this->transfer($type, $target));
		$this->assertStringNotContainsString('uuid_transfer', implode("\n", $this->state()['esl_commands']));
	}

	public static function invalidFields(): array
	{
		return array(
			'unknown target type' => array('ivr', '700', 'target_type'),
			'extension with a dialplan character' => array('extension', '102 XML other', 'target'),
			'malformed ring group uuid' => array('ring_group', '600', 'target'),
			'malformed queue uuid' => array('queue', array(self::QUEUE), 'target'),
		);
	}

	// the target ends up in an event socket command
	#[DataProvider('invalidFields')]
	public function testRejectsAnInvalidField(string $type, $target, string $field): void
	{
		$this->assertSame(array('error' => 'invalid '.$field, 'code' => 400), $this->transfer($type, $target));
		$this->assertSame(array(), $this->state()['esl_commands']);
	}

	public function testAnswersNotFoundForACallOfAnotherDomain(): void
	{
		$this->replies(self::dump(self::CALL, array('variable_domain_uuid' => self::OTHER_DOMAIN_UUID)), self::dump(self::OTHER_LEG));

		$this->assertSame(array('error' => 'call not found', 'code' => 404), $this->transfer('extension', '102'));
		$this->assertStringNotContainsString('uuid_transfer', implode("\n", $this->state()['esl_commands']));
	}

	public function testRejectsAMalformedCallUuid(): void
	{
		$this->assertSame(array('error' => 'invalid call_uuid', 'code' => 400), $this->transfer('extension', '102', 'f0000000; shutdown'));
	}

	public function testAnswers500WhenFreeswitchRefuses(): void
	{
		\FakeStore::update(function (&$state) {
			$state['esl_responses']['api uuid_transfer '.self::CALL.' -bleg 102 XML tenant1.example.com'] = "-ERR No such channel!\n";
		});

		list($result, $logged) = $this->transferLogged('extension', '102');

		$this->assertSame(array('error' => 'event socket error', 'code' => 500), $result);
		$this->assertStringContainsString('uuid_transfer', $logged);
	}

	public function testAnswers500WhenTheDatabaseFails(): void
	{
		$this->failSelects();

		$this->assertSame(array('error' => 'database error', 'code' => 500), $this->transfer('extension', '102'));
		$this->assertStringNotContainsString('uuid_transfer', implode("\n", $this->state()['esl_commands']));
	}
}
