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

	public function testBridgesBothLegsThroughLoopbackInTheDomain(): void
	{
		$result = $this->runAction($this->body());

		$this->assertSame(array('success' => true, 'call_uuid' => '7f4de3d2-0000-4000-8000-00000000c411'), $result);
		$this->assertSame(array(
			"api originate {ignore_early_media=true,originate_timeout=30,effective_caller_id_number=5551000}loopback/101/tenant1.example.com"
			." '&bridge({ignore_early_media=true,originate_timeout=30,effective_caller_id_number=5551000}loopback/100/tenant1.example.com)'",
		), $this->state()['esl_commands']);
	}

	public function testUrlEncodesTheCallerIdName(): void
	{
		$this->runAction($this->body(array('caller_id_name' => 'Front Desk, Inc}')));

		$this->assertStringContainsString(
			'{ignore_early_media=true,originate_timeout=30,effective_caller_id_number=5551000,effective_caller_id_name=Front%20Desk%2C%20Inc%7D}loopback/101/',
			$this->state()['esl_commands'][0]
		);
	}

	public function testReportsFailureWhenFreeswitchRejectsTheCall(): void
	{
		FakeStore::update(function (&$state) {
			$state['esl_response'] = "-ERR NO_ROUTE_DESTINATION\n";
		});

		$this->assertSame(array('success' => false, 'call_uuid' => null), $this->runAction($this->body()));
	}

	public function testRejectsUnknownDomainWithoutCalling(): void
	{
		$result = $this->runAction($this->body(array('domain_uuid' => 'aaaaaaaa-0000-4000-8000-00000000ffff')));

		$this->assertSame(404, $result['code']);
		$this->assertSame(array(), $this->state()['esl_commands']);
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

	public function testReportsAnUnreachableEventSocket(): void
	{
		FakeStore::update(function (&$state) {
			$state['esl_available'] = false;
		});

		$result = $this->runAction($this->body());

		$this->assertSame(array('error' => 'internal_server_error', 'message' => 'failed to connect to FreeSWITCH'), $result);
		$this->assertSame(array(), $this->state()['esl_commands']);
	}
}
