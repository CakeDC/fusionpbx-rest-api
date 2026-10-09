<?php
namespace RestApi\Test\Unit\Actions;

use FakeStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

#[RunTestsInSeparateProcesses]
class OriginateTest extends ActionTestCase
{
	protected function action(): string
	{
		return 'originate';
	}

	private function body(array $overrides = array()): array
	{
		return array_merge(array(
			'domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001',
			'caller_id_number' => '5551000',
			'destination_a' => '100',
			'destination_b' => '101',
		), $overrides);
	}

	// the stand-in's first uuid()
	private const CALL = '00000000-0000-4000-8000-000000000001';
	private const LEG_VARIABLES = 'domain_uuid=aaaaaaaa-0000-4000-8000-000000000001,domain_name=tenant1.example.com,ignore_early_media=true,originate_timeout=30,effective_caller_id_number=5551000';

	protected function setUp(): void
	{
		parent::setUp();
		FakeStore::update(function (&$state) {
			$state['esl_response'] = "+OK ".self::CALL."\n";
			$state['esl_responses']['api uuid_dump '.self::CALL.' json'] = json_encode(array(
				'Unique-ID' => self::CALL, 'Channel-Call-State' => 'ACTIVE', 'Caller-Caller-ID-Number' => '5551000',
				'Caller-Destination-Number' => '101', 'Other-Leg-Unique-ID' => '', 'variable_domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001',
			))."\n";
		});
	}

	// destination_a (the agent's own extension in click-to-call) rings first;
	// destination_b is only called once it answers
	public function testCallsDestinationAFirstThenBridgesDestinationB(): void
	{
		$result = $this->runAction($this->body());

		$this->assertSame(array(
			"api originate {origination_uuid=".self::CALL.",".self::LEG_VARIABLES."}loopback/100/tenant1.example.com"
			." '&bridge({".self::LEG_VARIABLES."}loopback/101/tenant1.example.com)'",
			'api uuid_dump '.self::CALL.' json',
		), $this->state()['esl_commands']);
		$this->assertSame(array('call_uuid' => self::CALL, 'domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'state' => 'answered', 'caller_id_number' => '5551000', 'destination_number' => '101', 'consulting' => null, 'code' => 201), $result);
	}

	// the returned call_uuid is set up front and carries the domain, so
	// call-hangup, call-hold and the others find it in the domain
	public function testTheCallUuidWorksWithTheCallControlActions(): void
	{
		$this->runAction($this->body());

		$originate = $this->state()['esl_commands'][0];
		$this->assertStringContainsString('{origination_uuid='.self::CALL.',domain_uuid=aaaaaaaa-0000-4000-8000-000000000001,', $originate);
		$this->assertSame(self::CALL, rest_api_call(self::CALL, 'aaaaaaaa-0000-4000-8000-000000000001')['Unique-ID']);
	}

	public function testUrlEncodesTheCallerIdName(): void
	{
		$this->runAction($this->body(array('caller_id_name' => 'Front Desk, Inc}')));

		$this->assertStringContainsString(
			'effective_caller_id_number=5551000,effective_caller_id_name=Front%20Desk%2C%20Inc%7D}loopback/100/',
			$this->state()['esl_commands'][0]
		);
	}

	// e.g. destination_a doesn't answer
	public function testAnswers500WithTheReasonWhenFreeswitchRejectsTheCall(): void
	{
		FakeStore::update(function (&$state) {
			$state['esl_response'] = "-ERR NO_ANSWER\n";
		});

		list($result, $logged) = $this->runLogged();

		$this->assertSame(array('error' => 'call failed: NO_ANSWER', 'code' => 500), $result);
		$this->assertStringContainsString('-ERR NO_ANSWER', $logged);
	}

	public function testReportsAnEndedCallWhenItIsGoneRightAway(): void
	{
		FakeStore::update(function (&$state) {
			$state['esl_responses']['api uuid_dump '.self::CALL.' json'] = "-ERR No such channel!\n";
		});

		$this->assertSame('ended', $this->runAction($this->body())['state']);
	}

	public function testRejectsUnknownDomainWithoutCalling(): void
	{
		$result = $this->runAction($this->body(array('domain_uuid' => 'aaaaaaaa-0000-4000-8000-00000000ffff')));

		$this->assertSame(array('error' => 'domain not found', 'code' => 404), $result);
		$this->assertSame(array(), $this->state()['esl_commands']);
	}

	// a database error must not pass for a missing domain
	public function testAnswers500WhenTheDatabaseFails(): void
	{
		$this->failSelects();

		$this->assertSame(array('error' => 'database error', 'code' => 500), $this->runAction($this->body()));
		$this->assertSame(array(), $this->state()['esl_commands']);
	}

	/** The result and what the action logged. */
	private function runLogged(): array
	{
		$log = tempnam(sys_get_temp_dir(), 'rest_api_log');
		$previous = ini_set('error_log', $log);
		try {
			$result = $this->runAction($this->body());
		} finally {
			ini_set('error_log', $previous);
		}
		$logged = file_get_contents($log);
		unlink($log);
		return array($result, $logged);
	}

	public static function injections(): array
	{
		return array(
			'second event socket command' => array('destination_b', "101\n\napi system id"),
			'single newline' => array('destination_b', "101\n"),
			'extra application' => array('destination_a', "100 &system(id)"),
			'quote breaking the bridge argument' => array('destination_a', "100)' &system('id"),
			'other tenant domain' => array('destination_a', '100/tenant2.example.com'),
			'channel variable' => array('caller_id_number', '5551000,origination_caller_id_number=911'),
			'closing brace' => array('caller_id_number', '5551000}sofia/gateway/x/1900'),
			'array instead of string' => array('destination_b', array('101')),
		);
	}

	#[DataProvider('injections')]
	public function testRejectsValuesThatCouldAlterTheCommand(string $field, $value): void
	{
		$result = $this->runAction($this->body(array($field => $value)));

		$this->assertSame(400, $result['code']);
		$this->assertSame(array(), $this->state()['esl_commands']);
	}

	public function testAnswers500WhenTheEventSocketIsUnreachable(): void
	{
		FakeStore::update(function (&$state) {
			$state['esl_available'] = false;
		});

		list($result, $logged) = $this->runLogged();

		$this->assertSame(array('error' => 'event socket error', 'code' => 500), $result);
		$this->assertStringContainsString('Failed to connect to event socket', $logged);
	}
}
