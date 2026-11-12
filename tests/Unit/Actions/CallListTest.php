<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

/**
 * call-list: the active calls of a domain, from FreeSWITCH's "show channels",
 * one item per call (the legs "show calls" pairs are one call). A channel's
 * domain is decided as FusionPBX's active calls page does: from its context,
 * else from the domain of its presence_id.
 */
#[RunTestsInSeparateProcesses]
class CallListTest extends ActionTestCase
{
	private const CHANNELS = 'api show channels as json';
	private const CALLS = 'api show calls as json';

	protected function action(): string
	{
		return 'call-list';
	}

	private static function channel(string $uuid, array $fields): array
	{
		// a phone's leg is named after its SIP user, which is its presence;
		// a carrier's after the caller
		if (!isset($fields['name'])) {
			$presence_user = explode('@', $fields['presence_id'] ?? '101@tenant1.example.com', 2)[0];
			$fields['name'] = $presence_user !== '' ? 'sofia/internal/'.$presence_user.'@tenant1.example.com' : 'sofia/external/'.($fields['cid_num'] ?? '101').'@carrier.example.com';
		}
		return $fields + array(
			'uuid' => $uuid, 'direction' => 'inbound', 'name' => 'sofia/internal/101@tenant1.example.com', 'state' => 'CS_EXECUTE',
			'cid_name' => 'Ana', 'cid_num' => '101', 'dest' => '102', 'application' => '', 'context' => 'tenant1.example.com',
			'callstate' => 'ACTIVE', 'presence_id' => '101@tenant1.example.com', 'accountcode' => 'tenant1.example.com',
		);
	}

	private function respond(array $channels, array $calls = array()): void
	{
		$channels_json = json_encode($channels ? array('row_count' => count($channels), 'rows' => $channels) : array('row_count' => 0));
		$calls_json = json_encode($calls ? array('row_count' => count($calls), 'rows' => $calls) : array('row_count' => 0));
		\FakeStore::update(function (&$state) use ($channels_json, $calls_json) {
			$state['esl_responses'][self::CHANNELS] = $channels_json."\n";
			$state['esl_responses'][self::CALLS] = $calls_json."\n";
		});
	}

	private function list(array $body = array()): array
	{
		return $this->runAction($body + array('domain_uuid' => self::DOMAIN_UUID));
	}

	private static function call(string $uuid, string $state, string $caller = '101', string $destination = '102'): array
	{
		return array('call_uuid' => $uuid, 'domain_uuid' => self::DOMAIN_UUID, 'state' => $state, 'caller_id_number' => $caller, 'destination_number' => $destination, 'consulting' => null);
	}

	public function testListsTheCallsOfTheDomain(): void
	{
		$this->respond(array(
			self::channel('a1', array()),
			// an inbound call from a carrier is in the public context; its
			// presence_id tells the domain
			self::channel('a2', array('context' => 'public', 'presence_id' => '5551234@tenant1.example.com', 'cid_num' => '+15550001111', 'dest' => '5551234', 'callstate' => 'RINGING')),
			self::channel('a3', array('context' => 'user@tenant1.example.com', 'callstate' => 'HELD')),
			// other domains, including one whose name contains this one's
			self::channel('x1', array('context' => 'tenant2.example.com', 'presence_id' => '201@tenant2.example.com')),
			self::channel('x2', array('context' => 'public', 'presence_id' => '5552000@tenant2.example.com')),
			self::channel('x3', array('context' => 'tenant1.example.com.evil.com', 'presence_id' => '101@tenant1.example.com.evil.com')),
			self::channel('x4', array('context' => 'default', 'presence_id' => '')),
		));

		$this->assertSame(array('data' => array(
			self::call('a1', 'answered'),
			self::call('a2', 'ringing', '+15550001111', '5551234'),
			self::call('a3', 'held'),
		)), $this->list());
		$this->assertSame(array(self::CHANNELS, self::CALLS), $this->state()['esl_commands']);
	}

	public static function callStates(): array
	{
		return array(
			'down' => array('DOWN', 'ringing'),
			'dialing' => array('DIALING', 'ringing'),
			'ringing' => array('RINGING', 'ringing'),
			'early media' => array('EARLY', 'ringing'),
			'ring wait' => array('RING_WAIT', 'ringing'),
			'active' => array('ACTIVE', 'answered'),
			'unheld' => array('UNHELD', 'answered'),
			'held' => array('HELD', 'held'),
			'hangup' => array('HANGUP', 'ended'),
		);
	}

	#[DataProvider('callStates')]
	public function testMapsFreeswitchCallStates(string $callstate, string $state): void
	{
		$this->respond(array(self::channel('a1', array('callstate' => $callstate))));

		$this->assertSame($state, $this->list()['data'][0]['state']);
	}

	// "show channels" can't tell an answered call from a bridged one; the
	// legs "show calls" pairs are bridged, and are one call: without an
	// extension, it is listed by its first leg
	public function testListsABridgedCallOnceByItsFirstLeg(): void
	{
		$this->respond(
			array(self::channel('b1', array('direction' => 'outbound')), self::channel('a1', array()), self::channel('a2', array())),
			array(array('uuid' => 'a1', 'b_uuid' => 'b1'), array('uuid' => 'a2', 'b_uuid' => ''))
		);

		$this->assertSame(array(array('a1', 'bridged'), array('a2', 'answered')), array_map(function ($call) {
			return array($call['call_uuid'], $call['state']);
		}, $this->list()['data']));
	}

	// a ring group rings several phones at once: one call while they ring
	public function testListsTheLegsRingingFromOneCallOnce(): void
	{
		$this->respond(
			array(
				self::channel('a1', array('cid_num' => '+15550001111', 'dest' => '600', 'presence_id' => '')),
				self::channel('b1', array('direction' => 'outbound', 'callstate' => 'RINGING', 'cid_num' => '+15550001111', 'dest' => '101', 'presence_id' => '101@tenant1.example.com')),
				self::channel('b2', array('direction' => 'outbound', 'callstate' => 'RINGING', 'cid_num' => '+15550001111', 'dest' => '102', 'presence_id' => '102@tenant1.example.com')),
			),
			array(array('uuid' => 'a1', 'b_uuid' => 'b1'), array('uuid' => 'a1', 'b_uuid' => 'b2'))
		);

		$this->assertSame(array('a1'), array_column($this->list()['data'], 'call_uuid'));
		$this->assertSame(array('b2'), array_column($this->list(array('extension' => '102'))['data'], 'call_uuid'));
		// an agent with both phones still sees the call once
		$this->assertSame(array('b1'), array_column($this->list(array('extension' => '101,102'))['data'], 'call_uuid'));
	}

	public function testListsOnlyTheCallsOfAnExtension(): void
	{
		$this->respond(array(
			self::channel('a1', array('cid_num' => '101', 'dest' => '5550000', 'presence_id' => '101@tenant1.example.com')),
			self::channel('a2', array('cid_num' => '+15550001111', 'dest' => '101', 'presence_id' => '')),
			// a call to a ring group that rings 101: its presence is 101
			self::channel('a3', array('cid_num' => '+15550001111', 'dest' => '600', 'presence_id' => '101@tenant1.example.com')),
			self::channel('a4', array('cid_num' => '102', 'dest' => '1011', 'presence_id' => '102@tenant1.example.com')),
		));

		$this->assertSame(array('a1', 'a2', 'a3'), array_column($this->list(array('extension' => '101'))['data'], 'call_uuid'));
	}

	// both legs of a call between two extensions name both numbers; the
	// extension's own leg is the one whose presence it is, which call actions
	// expect (holding a leg plays the music to the other one)
	public function testListsACallBetweenTwoExtensionsByTheLegOfTheExtension(): void
	{
		$this->respond(
			array(
				self::channel('a1', array('cid_num' => '101', 'dest' => '102', 'presence_id' => '101@tenant1.example.com')),
				self::channel('b1', array('direction' => 'outbound', 'cid_num' => '101', 'dest' => '102', 'presence_id' => '102@tenant1.example.com')),
			),
			array(array('uuid' => 'a1', 'b_uuid' => 'b1'))
		);

		$this->assertSame(array(array('a1', 'bridged')), array_map(function ($call) {
			return array($call['call_uuid'], $call['state']);
		}, $this->list(array('extension' => '101'))['data']));
		$this->assertSame(array('b1'), array_column($this->list(array('extension' => '102'))['data'], 'call_uuid'));
	}

	// the leg of the extension called may have no context or presence of
	// the domain: it is in the domain of the leg it is paired with, and is
	// the extension's by its SIP user
	public function testListsTheCalledExtensionByItsLegWithoutADomainOfItsOwn(): void
	{
		$this->respond(
			array(
				self::channel('a1', array('cid_num' => '101', 'dest' => '102', 'presence_id' => '101@tenant1.example.com')),
				self::channel('b1', array('direction' => 'outbound', 'name' => 'sofia/internal/102@192.0.2.10:5060', 'context' => 'default', 'cid_num' => '101', 'dest' => '102', 'presence_id' => '')),
			),
			array(array('uuid' => 'a1', 'b_uuid' => 'b1'))
		);

		$this->assertSame(array(array('a1', 'bridged')), array_map(function ($call) {
			return array($call['call_uuid'], $call['state']);
		}, $this->list()['data']));
		$this->assertSame(array('a1'), array_column($this->list(array('extension' => '101'))['data'], 'call_uuid'));
		$this->assertSame(array(array('b1', 'bridged')), array_map(function ($call) {
			return array($call['call_uuid'], $call['state']);
		}, $this->list(array('extension' => '102'))['data']));
	}

	// the leg of the extension called may carry the caller's presence
	public function testListsTheCalledExtensionByItsSipUser(): void
	{
		$this->respond(
			array(
				self::channel('a1', array('cid_num' => '101', 'dest' => '102', 'presence_id' => '101@tenant1.example.com')),
				self::channel('b1', array('direction' => 'outbound', 'name' => 'sofia/internal/102@192.0.2.10:5060', 'cid_num' => '101', 'dest' => '102', 'presence_id' => '101@tenant1.example.com')),
			),
			array(array('uuid' => 'a1', 'b_uuid' => 'b1'))
		);

		$this->assertSame(array('b1'), array_column($this->list(array('extension' => '102'))['data'], 'call_uuid'));
	}

	// a leg without a domain is not listed for the domain of a call it isn't in
	public function testDoesntListALegWithoutADomainPairedWithAnotherDomain(): void
	{
		$this->respond(
			array(
				self::channel('x1', array('context' => 'tenant2.example.com', 'cid_num' => '201', 'dest' => '102', 'presence_id' => '201@tenant2.example.com')),
				self::channel('x2', array('direction' => 'outbound', 'name' => 'sofia/internal/102@192.0.2.10:5060', 'context' => 'default', 'cid_num' => '201', 'dest' => '102', 'presence_id' => '')),
				self::channel('x3', array('name' => 'sofia/internal/102@192.0.2.10:5060', 'context' => 'default', 'cid_num' => '102', 'dest' => '5550000', 'presence_id' => '')),
			),
			array(array('uuid' => 'x1', 'b_uuid' => 'x2'))
		);

		$this->assertSame(array('data' => array()), $this->list());
		$this->assertSame(array('data' => array()), $this->list(array('extension' => '102')));
	}

	// a carrier's leg named like an extension isn't the extension's phone
	public function testDoesntTakeACarriersLegForAnExtension(): void
	{
		$this->respond(
			array(
				self::channel('a1', array('name' => 'loopback/102-a', 'cid_num' => '+15550001111', 'dest' => '5551234', 'context' => 'public', 'presence_id' => '5551234@tenant1.example.com')),
			)
		);

		$this->assertSame(array('data' => array()), $this->list(array('extension' => '102')));
	}

	// "show calls" pairs the legs only once bridged; while the call rings,
	// the leg ringing the extension names the caller's leg in its call_uuid
	public function testListsARingingCallOnce(): void
	{
		$this->respond(array(
			self::channel('a1', array('call_uuid' => 'a1', 'callstate' => 'RING_WAIT', 'cid_num' => '103', 'dest' => '101', 'presence_id' => '103@tenant1.example.com')),
			self::channel('b1', array('call_uuid' => 'a1', 'direction' => 'outbound', 'callstate' => 'RINGING', 'cid_num' => '103', 'dest' => '101', 'presence_id' => '101@tenant1.example.com')),
		));

		$this->assertSame(array(array('a1', 'ringing')), array_map(function ($call) {
			return array($call['call_uuid'], $call['state']);
		}, $this->list()['data']));
		$this->assertSame(array('b1'), array_column($this->list(array('extension' => '101'))['data'], 'call_uuid'));
		$this->assertSame(array('a1'), array_column($this->list(array('extension' => '103'))['data'], 'call_uuid'));
		// both extensions in the call: still once
		$this->assertSame(array('a1'), array_column($this->list(array('extension' => array('103', '101')))['data'], 'call_uuid'));
	}

	// the ringing leg of the extension called may have no domain of its own,
	// and a ring group rings several at once
	public function testListsTheRingingLegsOfARingGroupOnce(): void
	{
		$this->respond(array(
			self::channel('a1', array('callstate' => 'RING_WAIT', 'cid_num' => '+15550001111', 'dest' => '600', 'presence_id' => '')),
			self::channel('b1', array('call_uuid' => 'a1', 'direction' => 'outbound', 'name' => 'sofia/internal/101@192.0.2.10:5060', 'context' => 'default', 'callstate' => 'RINGING', 'cid_num' => '+15550001111', 'dest' => '101', 'presence_id' => '')),
			self::channel('b2', array('call_uuid' => 'a1', 'direction' => 'outbound', 'name' => 'sofia/internal/102@192.0.2.11:5060', 'context' => 'default', 'callstate' => 'RINGING', 'cid_num' => '+15550001111', 'dest' => '102', 'presence_id' => '')),
		));

		$this->assertSame(array('a1'), array_column($this->list()['data'], 'call_uuid'));
		$this->assertSame(array('b2'), array_column($this->list(array('extension' => '102'))['data'], 'call_uuid'));
	}

	// the extension's leg may be placed through a loopback, and be listed
	// before the leg that placed it
	public function testListsARingingCallPlacedThroughALoopbackOnce(): void
	{
		$this->respond(array(
			self::channel('b1', array('call_uuid' => 'l1', 'direction' => 'outbound', 'callstate' => 'RINGING', 'cid_num' => '103', 'dest' => '101', 'presence_id' => '101@tenant1.example.com')),
			self::channel('a1', array('call_uuid' => 'a1', 'callstate' => 'RING_WAIT', 'cid_num' => '103', 'dest' => '101', 'presence_id' => '103@tenant1.example.com')),
			self::channel('l1', array('call_uuid' => 'a1', 'name' => 'loopback/101-a', 'context' => 'default', 'callstate' => 'RING_WAIT', 'cid_num' => '103', 'dest' => '101', 'presence_id' => '')),
		));

		$this->assertSame(array('a1'), array_column($this->list()['data'], 'call_uuid'));
		$this->assertSame(array('b1'), array_column($this->list(array('extension' => '101'))['data'], 'call_uuid'));
		$this->assertSame(array('a1'), array_column($this->list(array('extension' => '103'))['data'], 'call_uuid'));
	}

	// the extensions as a comma separated string, both in a ringing call
	public function testListsARingingCallOnceForACommaSeparatedString(): void
	{
		$this->respond(array(
			self::channel('a1', array('call_uuid' => 'a1', 'callstate' => 'RING_WAIT', 'cid_num' => '103', 'dest' => '101', 'presence_id' => '103@tenant1.example.com')),
			self::channel('b1', array('call_uuid' => 'a1', 'direction' => 'outbound', 'callstate' => 'RINGING', 'cid_num' => '103', 'dest' => '101', 'presence_id' => '101@tenant1.example.com')),
		));

		$this->assertSame(array('a1'), array_column($this->list(array('extension' => '101, 103'))['data'], 'call_uuid'));
	}

	// on a call through a gateway the extension's phone is the sofia/internal
	// leg, whichever way the call goes and in whatever order the legs come,
	// not the leg that only carries its number
	public function testListsACallThroughAGatewayByThePhonesLeg(): void
	{
		// the extension calls out
		$this->respond(
			array(
				self::channel('b1', array('direction' => 'outbound', 'name' => 'sofia/gateway/carrier/5551234', 'cid_num' => '101', 'dest' => '5551234', 'presence_id' => '')),
				self::channel('a1', array('cid_num' => '101', 'dest' => '5551234', 'presence_id' => '101@tenant1.example.com')),
			),
			array(array('uuid' => 'a1', 'b_uuid' => 'b1'))
		);
		$this->assertSame(array(array('a1', 'bridged')), array_map(function ($call) {
			return array($call['call_uuid'], $call['state']);
		}, $this->list(array('extension' => '101'))['data']));

		// a carrier calls the extension
		$this->respond(
			array(
				self::channel('a1', array('name' => 'sofia/external/+15550001111@carrier.example.com', 'cid_num' => '+15550001111', 'dest' => '102', 'presence_id' => '')),
				self::channel('b1', array('direction' => 'outbound', 'name' => 'sofia/internal/102@192.0.2.10:5060', 'context' => 'default', 'cid_num' => '+15550001111', 'dest' => '102', 'presence_id' => '')),
			),
			array(array('uuid' => 'a1', 'b_uuid' => 'b1'))
		);
		$this->assertSame(array(array('b1', 'bridged')), array_map(function ($call) {
			return array($call['call_uuid'], $call['state']);
		}, $this->list(array('extension' => '102'))['data']));
	}

	// a carrier's leg is named after a number, which isn't an extension's
	// SIP user even when it looks like one
	public function testDoesntTakeACarriersLegForTheExtensionsPhone(): void
	{
		foreach (array('sofia/external/102@carrier.example.com', 'sofia/external-ipv6/102@carrier.example.com', 'sofia/gateway/carrier/102') as $name) {
			$this->respond(
				array(
					self::channel('a1', array('cid_num' => '101', 'dest' => '102', 'presence_id' => '101@tenant1.example.com')),
					self::channel('b1', array('direction' => 'outbound', 'name' => $name, 'cid_num' => '101', 'dest' => '102', 'presence_id' => '')),
				),
				array(array('uuid' => 'a1', 'b_uuid' => 'b1'))
			);

			$this->assertSame(array('a1'), array_column($this->list(array('extension' => '102'))['data'], 'call_uuid'), $name);
		}
	}

	// a gateway leg may take the presence of the extension that called out:
	// both legs fit the extension, and the one nearest the call's first leg
	// (the phone's) is listed, in whatever order the legs come
	public function testPrefersThePhonesLegOverAGatewayLegWithItsPresence(): void
	{
		$phone = self::channel('a1', array('cid_num' => '101', 'dest' => '5551234', 'presence_id' => '101@tenant1.example.com'));
		$gateway = self::channel('b1', array('direction' => 'outbound', 'name' => 'sofia/gateway/carrier/5551234', 'cid_num' => '101', 'dest' => '5551234', 'presence_id' => '101@tenant1.example.com'));
		foreach (array('phone first' => array($phone, $gateway), 'gateway first' => array($gateway, $phone)) as $order => $channels) {
			$this->respond($channels, array(array('uuid' => 'a1', 'b_uuid' => 'b1')));

			$this->assertSame(array('a1'), array_column($this->list(array('extension' => '101'))['data'], 'call_uuid'), $order);
		}
	}

	// a phone registered twice rings on two legs, equally near the call's
	// first leg: the lowest uuid is listed, in whatever order the legs come
	public function testListsTheSameLegOfAPhoneRingingTwiceInAnyOrder(): void
	{
		$caller = self::channel('a1', array('callstate' => 'RING_WAIT', 'cid_num' => '103', 'dest' => '101', 'presence_id' => '103@tenant1.example.com'));
		$first = self::channel('b1', array('call_uuid' => 'a1', 'direction' => 'outbound', 'name' => 'sofia/internal/101@192.0.2.10:5060', 'context' => 'default', 'callstate' => 'RINGING', 'cid_num' => '103', 'dest' => '101', 'presence_id' => ''));
		$second = self::channel('b2', array('call_uuid' => 'a1', 'direction' => 'outbound', 'name' => 'sofia/internal/101@192.0.2.11:5060', 'context' => 'default', 'callstate' => 'RINGING', 'cid_num' => '103', 'dest' => '101', 'presence_id' => ''));
		foreach (array('b1 first' => array($caller, $first, $second), 'b2 first' => array($second, $first, $caller)) as $order => $channels) {
			$this->respond($channels);

			$this->assertSame(array('b1'), array_column($this->list(array('extension' => '101'))['data'], 'call_uuid'), $order);
		}
	}

	// a leg with a domain of its own keeps it, whatever the leg paired with it
	public function testPairedLegsOfDifferentDomainsKeepTheirOwn(): void
	{
		$this->respond(
			array(
				self::channel('a1', array('cid_num' => '101', 'dest' => '201', 'presence_id' => '101@tenant1.example.com')),
				self::channel('b1', array('direction' => 'outbound', 'context' => 'tenant2.example.com', 'cid_num' => '101', 'dest' => '201', 'presence_id' => '201@tenant2.example.com')),
			),
			array(array('uuid' => 'a1', 'b_uuid' => 'b1'))
		);

		$this->assertSame(array('a1'), array_column($this->list()['data'], 'call_uuid'));
		$this->assertSame(array('a1'), array_column($this->list(array('extension' => '201'))['data'], 'call_uuid'));
		$this->assertSame(array('b1'), array_column($this->list(array('domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000002'))['data'], 'call_uuid'));
	}

	// a call_uuid naming a channel that is gone, or the channel itself,
	// doesn't join legs
	public function testListsALegWhoseCallUuidIsNoLiveLegByItself(): void
	{
		$this->respond(array(
			self::channel('a1', array('call_uuid' => 'gone')),
			self::channel('a2', array('call_uuid' => 'a2')),
		));

		$this->assertSame(array('a1', 'a2'), array_column($this->list()['data'], 'call_uuid'));
	}

	// a ringing leg placed by a call of another domain isn't listed
	public function testDoesntListARingingLegWithoutADomainPlacedByAnotherDomain(): void
	{
		$this->respond(array(
			self::channel('x1', array('context' => 'tenant2.example.com', 'callstate' => 'RING_WAIT', 'cid_num' => '201', 'dest' => '101', 'presence_id' => '201@tenant2.example.com')),
			self::channel('x2', array('call_uuid' => 'x1', 'direction' => 'outbound', 'name' => 'sofia/internal/101@192.0.2.10:5060', 'context' => 'default', 'callstate' => 'RINGING', 'cid_num' => '201', 'dest' => '101', 'presence_id' => '')),
		));

		$this->assertSame(array('data' => array()), $this->list(array('extension' => '101')));
	}

	// a user can have several extensions; each call is listed once
	public function testListsTheCallsOfSeveralExtensions(): void
	{
		$this->respond(
			array(
				self::channel('a1', array('cid_num' => '101', 'dest' => '102', 'presence_id' => '101@tenant1.example.com')),
				self::channel('b1', array('direction' => 'outbound', 'cid_num' => '101', 'dest' => '102', 'presence_id' => '102@tenant1.example.com')),
				self::channel('a2', array('cid_num' => '+15550001111', 'dest' => '103', 'presence_id' => '')),
				self::channel('a3', array('cid_num' => '104', 'dest' => '5550000', 'presence_id' => '104@tenant1.example.com')),
			),
			array(array('uuid' => 'a1', 'b_uuid' => 'b1'))
		);

		foreach (array('101,102,103', ' 101 , 102,103', array('101', '102', '103'), array('101', 102, '103')) as $extensions) {
			$this->assertSame(array('a1', 'a2'), array_column($this->list(array('extension' => $extensions))['data'], 'call_uuid'), json_encode($extensions));
		}
	}

	private const AGENT = 'f0000000-0000-4000-8000-000000000001';
	private const CONSULT = 'f0000000-0000-4000-8000-000000000003';
	private const GET_CONSULT = 'api uuid_getvar '.self::AGENT.' rest_api_consult_uuid';
	private const GET_TARGET = 'api uuid_getvar '.self::AGENT.' rest_api_consult_target';

	/**
	 * The agent's answered call, noted by call-transfer-attended as consulting
	 * 102, and the consult leg when $consult_leg: a loopback without the
	 * domain, so it isn't listed itself.
	 */
	private function respondConsulting(bool $consult_leg = true, string $callstate = 'ACTIVE'): void
	{
		$channels = array(self::channel(self::AGENT, array('callstate' => $callstate)));
		if ($consult_leg) {
			$channels[] = self::channel(self::CONSULT, array('name' => 'loopback/102-a', 'context' => 'default', 'presence_id' => '', 'cid_num' => '101', 'dest' => '102'));
		}
		$this->respond($channels);
		\FakeStore::update(function (&$state) {
			$state['esl_responses'][self::GET_CONSULT] = self::CONSULT."\n";
			$state['esl_responses'][self::GET_TARGET] = "102\n";
		});
	}

	// ZuluCall keeps no consultation state: the agent's call carries the number
	// consulted, and is held meanwhile
	public function testReportsTheNumberAWarmTransferConsults(): void
	{
		$this->respondConsulting();

		$this->assertSame(array('data' => array(
			array('call_uuid' => self::AGENT, 'domain_uuid' => self::DOMAIN_UUID, 'state' => 'held', 'caller_id_number' => '101', 'destination_number' => '102', 'consulting' => '102'),
		)), $this->list());
		$this->assertSame(array(self::CHANNELS, self::CALLS, self::GET_CONSULT, self::GET_TARGET), $this->state()['esl_commands']);
	}

	// 103 called the agent (101), who consults 102: the consult leg names the
	// agent's leg in its call_uuid, so the consultation, ringing or answered,
	// is part of the agent's call, which is listed by the agent's leg for the
	// agent's extension and carries the number consulted
	public static function consultations(): array
	{
		return array(
			'ringing' => array('RING_WAIT', 'RINGING', false, false),
			'ringing, target listed first' => array('RING_WAIT', 'RINGING', false, true),
			'answered' => array('ACTIVE', 'ACTIVE', true, false),
			'answered, target listed first' => array('ACTIVE', 'ACTIVE', true, true),
		);
	}

	#[DataProvider('consultations')]
	public function testListsTheConsultationOfAWarmTransferAsPartOfTheAgentsCall(string $consult_state, string $target_state, bool $answered, bool $target_first): void
	{
		$consult = self::channel(self::CONSULT, array('call_uuid' => self::AGENT, 'name' => 'loopback/102-a', 'context' => 'default', 'callstate' => $consult_state, 'presence_id' => '', 'cid_num' => '101', 'dest' => '102'));
		$target = self::channel('t1', array('call_uuid' => self::CONSULT, 'direction' => 'outbound', 'context' => 'default', 'callstate' => $target_state, 'cid_num' => '101', 'dest' => '102', 'presence_id' => '102@tenant1.example.com'));
		$calls = array(array('uuid' => 'c1', 'b_uuid' => self::AGENT));
		if ($answered) {
			$calls[] = array('uuid' => self::CONSULT, 'b_uuid' => 't1');
		}
		$this->respond(
			array_merge(
				array(
					self::channel('c1', array('cid_num' => '103', 'dest' => '101', 'presence_id' => '103@tenant1.example.com')),
					self::channel(self::AGENT, array('call_uuid' => 'c1', 'direction' => 'outbound', 'cid_num' => '103', 'dest' => '101', 'presence_id' => '101@tenant1.example.com')),
				),
				$target_first ? array($target, $consult) : array($consult, $target)
			),
			$calls
		);
		\FakeStore::update(function (&$state) {
			$state['esl_responses'][self::GET_CONSULT] = self::CONSULT."\n";
			$state['esl_responses'][self::GET_TARGET] = "102\n";
		});

		$this->assertSame(array(
			array('call_uuid' => self::AGENT, 'domain_uuid' => self::DOMAIN_UUID, 'state' => 'held', 'caller_id_number' => '103', 'destination_number' => '101', 'consulting' => '102'),
		), $this->list(array('extension' => '101'))['data']);
		$this->assertSame(array('c1'), array_column($this->list()['data'], 'call_uuid'));
		$this->assertSame(array('t1'), array_column($this->list(array('extension' => '102'))['data'], 'call_uuid'));
	}

	// a consult leg named after the agent's SIP user fits the agent's
	// extension as well as the agent's leg, which placed it: the agent's leg
	// is listed, so call actions don't act on the consult leg, in whatever
	// order the legs come
	public function testListsTheAgentsLegRatherThanAConsultLegNamedAfterTheAgent(): void
	{
		$caller = self::channel('c1', array('cid_num' => '103', 'dest' => '101', 'presence_id' => '103@tenant1.example.com'));
		$agent = self::channel(self::AGENT, array('call_uuid' => 'c1', 'direction' => 'outbound', 'cid_num' => '103', 'dest' => '101', 'presence_id' => '101@tenant1.example.com'));
		$consult = self::channel(self::CONSULT, array('call_uuid' => self::AGENT, 'name' => 'sofia/internal/101@192.0.2.10:5060', 'context' => 'default', 'callstate' => 'RING_WAIT', 'presence_id' => '', 'cid_num' => '101', 'dest' => '102'));
		foreach (array('agent first' => array($caller, $agent, $consult), 'consult first' => array($consult, $caller, $agent)) as $order => $channels) {
			$this->respond($channels, array(array('uuid' => 'c1', 'b_uuid' => self::AGENT)));
			\FakeStore::update(function (&$state) {
				$state['esl_responses'][self::GET_CONSULT] = self::CONSULT."\n";
				$state['esl_responses'][self::GET_TARGET] = "102\n";
			});

			$this->assertSame(array(
				array('call_uuid' => self::AGENT, 'domain_uuid' => self::DOMAIN_UUID, 'state' => 'held', 'caller_id_number' => '103', 'destination_number' => '101', 'consulting' => '102'),
			), $this->list(array('extension' => '101'))['data'], $order);
		}
	}

	// att_xfer ends a consultation on its own when the target doesn't answer
	// or hangs up, and leaves the note behind
	public function testIgnoresAConsultationWhoseLegIsGone(): void
	{
		$this->respondConsulting(false);

		$call = $this->list()['data'][0];

		$this->assertSame(array('answered', null), array($call['state'], $call['consulting']));
		$this->assertNotContains(self::GET_TARGET, $this->state()['esl_commands']);
	}

	public function testReportsNoConsultationForACallWithoutANote(): void
	{
		$this->respondConsulting();
		\FakeStore::update(function (&$state) {
			$state['esl_responses'][self::GET_CONSULT] = "_undef_\n";
		});

		$this->assertNull($this->list()['data'][0]['consulting']);
	}

	// only an answered call can consult
	public function testDoesntLookForAConsultationOfACallThatIsNotAnswered(): void
	{
		foreach (array('RINGING', 'HELD') as $callstate) {
			$this->respondConsulting(true, $callstate);
			\FakeStore::update(function (&$state) {
				$state['esl_commands'] = array();
			});

			$this->assertNull($this->list()['data'][0]['consulting'], $callstate);
			$this->assertSame(array(self::CHANNELS, self::CALLS), $this->state()['esl_commands'], $callstate);
		}
	}

	// the channel can hang up between "show channels" and uuid_getvar
	public function testListsAChannelThatWentAwayWithoutAConsultation(): void
	{
		$this->respondConsulting();
		\FakeStore::update(function (&$state) {
			$state['esl_responses'][self::GET_CONSULT] = "-ERR No such channel!\n";
		});

		$this->assertNull($this->list()['data'][0]['consulting']);
	}

	public function testAnswers500WhenAConsultationCantBeRead(): void
	{
		$this->respondConsulting();
		\FakeStore::update(function (&$state) {
			$state['esl_responses'][self::GET_TARGET] = "-ERR Operation failed\n";
		});

		$this->assertSame(array('error' => 'event socket error', 'code' => 500), $this->listLogged());
	}

	public function testReturnsAnEmptyListWithoutCalls(): void
	{
		$this->respond(array());

		$this->assertSame(array('data' => array()), $this->list());
	}

	// the extensions are only compared, but never passed on unchecked
	public function testRejectsAnInvalidExtension(): void
	{
		foreach (array('101; shutdown', '', ',', '101,10 2', array(), array('101', array('102')), array('101', '102; shutdown'), 101.5) as $extension) {
			$this->assertSame(array('error' => 'invalid extension', 'code' => 400), $this->list(array('extension' => $extension)), json_encode($extension));
		}
		$this->assertSame(array(), $this->state()['esl_commands']);
	}

	public function testAnswers500WhenTheEventSocketIsUnavailable(): void
	{
		\FakeStore::update(function (&$state) {
			$state['esl_available'] = false;
		});

		$this->assertSame(array('error' => 'event socket error', 'code' => 500), $this->listLogged());
	}

	public function testAnswers500WhenFreeswitchAnswersSomethingElseThanJson(): void
	{
		\FakeStore::update(function (&$state) {
			$state['esl_response'] = "-ERR no reply\n";
		});

		$this->assertSame(array('error' => 'event socket error', 'code' => 500), $this->listLogged());
	}

	public function testAnswers500WhenTheDatabaseFails(): void
	{
		$this->respond(array());
		$this->failSelects();

		$this->assertSame(array('error' => 'database error', 'code' => 500), $this->list());
		$this->assertSame(array(), $this->state()['esl_commands']);
	}

	/** The result, with what the action logs kept out of the test output. */
	private function listLogged(): array
	{
		$log = tempnam(sys_get_temp_dir(), 'rest_api_log');
		$previous = ini_set('error_log', $log);
		try {
			$result = $this->list();
		} finally {
			ini_set('error_log', $previous);
		}
		$this->assertNotSame('', file_get_contents($log), 'the reason is logged');
		unlink($log);
		return $result;
	}
}
