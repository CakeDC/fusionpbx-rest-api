<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

/**
 * destination-update. A new target
 * replaces the destination's actions in v_destinations, in the dialplan XML
 * and in its action details; everything else FusionPBX put in the dialplan
 * stays as it is.
 */
#[RunTestsInSeparateProcesses]
class DestinationUpdateTest extends ActionTestCase
{
	private const DESTINATION = 'bbbbbbbb-0000-4000-8000-000000000001';
	private const DIALPLAN = 'cccccccc-0000-4000-8000-000000000001';
	private const RING_GROUP = '99999999-0000-4000-8000-000000000001';
	private const OTHER_RING_GROUP = '99999999-0000-4000-8000-000000000002';
	private const IVR = '88888888-0000-4000-8000-000000000001';
	private const OTHER_DOMAIN_UUID = 'aaaaaaaa-0000-4000-8000-000000000002';
	private const OLD_ACTION = '<action application="transfer" data="100 XML tenant1.example.com"/>';

	protected function action(): string
	{
		return 'destination-update';
	}

	private static function dialplanXml(string $actions = "\t\t".self::OLD_ACTION."\n"): string
	{
		return "<extension name=\"5551234\" continue=\"false\" uuid=\"".self::DIALPLAN."\">\n"
			."\t<condition field=\"destination_number\" expression=\"^\\+?1?(5551234)$\">\n"
			."\t\t<action application=\"export\" data=\"call_direction=inbound\" inline=\"true\"/>\n"
			."\t\t<action application=\"record_session\" data=\"\${record_path}/\${record_name}\" inline=\"false\"/>\n"
			.$actions
			."\t</condition>\n"
			."</extension>\n";
	}

	protected function tables(): array
	{
		$d1 = self::DOMAIN_UUID;
		$d2 = self::OTHER_DOMAIN_UUID;
		return parent::tables() + array(
			'v_destinations' => array(
				array('destination_uuid' => self::DESTINATION, 'dialplan_uuid' => self::DIALPLAN, 'domain_uuid' => $d1, 'destination_type' => 'inbound', 'destination_number' => '5551234', 'destination_prefix' => '', 'destination_context' => 'public', 'destination_app' => 'transfer', 'destination_data' => '100 XML tenant1.example.com', 'destination_actions' => '[{"destination_app":"transfer","destination_data":"100 XML tenant1.example.com"}]', 'destination_enabled' => 'true', 'destination_record' => 'true'),
				array('destination_uuid' => 'bbbbbbbb-0000-4000-8000-000000000002', 'dialplan_uuid' => 'cccccccc-0000-4000-8000-000000000002', 'domain_uuid' => $d1, 'destination_type' => 'outbound', 'destination_number' => '5559999', 'destination_context' => 'tenant1.example.com', 'destination_actions' => null, 'destination_enabled' => 'true'),
				array('destination_uuid' => 'bbbbbbbb-0000-4000-8000-000000000020', 'dialplan_uuid' => 'cccccccc-0000-4000-8000-000000000020', 'domain_uuid' => $d2, 'destination_type' => 'inbound', 'destination_number' => '5552000', 'destination_context' => 'public', 'destination_actions' => null, 'destination_enabled' => 'true'),
			),
			'v_dialplans' => array(
				array('dialplan_uuid' => self::DIALPLAN, 'domain_uuid' => $d1, 'dialplan_context' => 'public', 'dialplan_enabled' => 'true', 'dialplan_xml' => self::dialplanXml()),
			),
			'v_dialplan_details' => array(
				array('dialplan_detail_uuid' => 'dd000000-0000-4000-8000-000000000001', 'domain_uuid' => $d1, 'dialplan_uuid' => self::DIALPLAN, 'dialplan_detail_tag' => 'condition', 'dialplan_detail_type' => 'destination_number', 'dialplan_detail_data' => '^(5551234)$', 'dialplan_detail_group' => '0', 'dialplan_detail_order' => '20'),
				array('dialplan_detail_uuid' => 'dd000000-0000-4000-8000-000000000002', 'domain_uuid' => $d1, 'dialplan_uuid' => self::DIALPLAN, 'dialplan_detail_tag' => 'action', 'dialplan_detail_type' => 'record_session', 'dialplan_detail_data' => '${record_path}/${record_name}', 'dialplan_detail_group' => '0', 'dialplan_detail_order' => '30'),
				array('dialplan_detail_uuid' => 'dd000000-0000-4000-8000-000000000003', 'domain_uuid' => $d1, 'dialplan_uuid' => self::DIALPLAN, 'dialplan_detail_tag' => 'action', 'dialplan_detail_type' => 'transfer', 'dialplan_detail_data' => '100 XML tenant1.example.com', 'dialplan_detail_group' => '0', 'dialplan_detail_order' => '40'),
			),
			'v_extensions' => array(
				array('extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000101', 'domain_uuid' => $d1, 'extension' => '101', 'number_alias' => '1101', 'user_context' => 'tenant1.example.com'),
				array('extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000200', 'domain_uuid' => $d2, 'extension' => '200', 'number_alias' => '', 'user_context' => 'tenant2.example.com'),
			),
			'v_ring_groups' => array(
				array('ring_group_uuid' => self::RING_GROUP, 'domain_uuid' => $d1, 'ring_group_extension' => '600', 'ring_group_context' => 'tenant1.example.com'),
				array('ring_group_uuid' => self::OTHER_RING_GROUP, 'domain_uuid' => $d2, 'ring_group_extension' => '600', 'ring_group_context' => 'tenant2.example.com'),
			),
			'v_ivr_menus' => array(
				array('ivr_menu_uuid' => self::IVR, 'domain_uuid' => $d1, 'ivr_menu_extension' => '700', 'ivr_menu_context' => 'tenant1.example.com'),
			),
			'v_voicemails' => array(
				array('voicemail_uuid' => 'c0000000-0000-4000-8000-000000000101', 'domain_uuid' => $d1, 'voicemail_id' => '101'),
			),
		);
	}

	private function update(array $fields, string $number = '5551234'): array
	{
		return $this->runAction($fields + array('domain_uuid' => self::DOMAIN_UUID, 'number' => $number));
	}

	private function row(string $table, string $key, string $value): array
	{
		foreach ($this->state()['tables'][$table] as $row) {
			if ($row[$key] === $value) {
				return $row;
			}
		}
		$this->fail("no $table row with $key = $value");
	}

	private function actionDetails(): array
	{
		$details = array_filter($this->state()['tables']['v_dialplan_details'], function ($row) {
			return $row['dialplan_uuid'] === self::DIALPLAN && $row['dialplan_detail_tag'] === 'action';
		});
		return array_values(array_map(function ($row) {
			return array($row['dialplan_detail_type'], $row['dialplan_detail_data'], (string)$row['dialplan_detail_order']);
		}, $details));
	}

	public static function targets(): array
	{
		return array(
			'ring group' => array('ring_group', self::RING_GROUP, '600 XML tenant1.example.com'),
			'ivr' => array('ivr', self::IVR, '700 XML tenant1.example.com'),
			'extension' => array('extension', '101', '101 XML tenant1.example.com'),
			'extension alias' => array('extension', '1101', '1101 XML tenant1.example.com'),
			'voicemail' => array('voicemail', '101', '*99101 XML tenant1.example.com'),
		);
	}

	#[DataProvider('targets')]
	public function testRoutesTheNumberToTheNewTarget(string $type, string $target, string $data): void
	{
		$result = $this->update(array('destination_type' => $type, 'target' => $target));

		$this->assertSame(array('domain_uuid' => self::DOMAIN_UUID, 'number' => '5551234', 'destination_type' => $type, 'target' => $target, 'enabled' => true), $result);
		$destination = $this->row('v_destinations', 'destination_uuid', self::DESTINATION);
		$this->assertSame(array(array('destination_app' => 'transfer', 'destination_data' => $data)), json_decode($destination['destination_actions'], true));
		$this->assertSame(array('transfer', $data), array($destination['destination_app'], $destination['destination_data']));
		$this->assertSame(
			self::dialplanXml("\t\t<action application=\"transfer\" data=\"".$data."\"/>\n"),
			$this->row('v_dialplans', 'dialplan_uuid', self::DIALPLAN)['dialplan_xml']
		);
		// the record_session action FusionPBX added stays; the transfer is replaced in place
		$this->assertSame(array(array('record_session', '${record_path}/${record_name}', '30'), array('transfer', $data, '40')), $this->actionDetails());
	}

	public function testReplacesEveryOldActionWithTheNewOne(): void
	{
		$second = '<action application="transfer" data="101 XML tenant1.example.com"/>';
		\FakeStore::update(function (&$state) use ($second) {
			$state['tables']['v_destinations'][0]['destination_actions'] = '[{"destination_app":"transfer","destination_data":"100 XML tenant1.example.com"},{"destination_app":"transfer","destination_data":"101 XML tenant1.example.com"}]';
			$state['tables']['v_dialplans'][0]['dialplan_xml'] = self::dialplanXml("\t\t".self::OLD_ACTION."\n\t\t".$second."\n");
			$state['tables']['v_dialplan_details'][] = array('dialplan_detail_uuid' => 'dd000000-0000-4000-8000-000000000004', 'domain_uuid' => self::DOMAIN_UUID, 'dialplan_uuid' => self::DIALPLAN, 'dialplan_detail_tag' => 'action', 'dialplan_detail_type' => 'transfer', 'dialplan_detail_data' => '101 XML tenant1.example.com', 'dialplan_detail_group' => '0', 'dialplan_detail_order' => '50');
		});

		$this->update(array('destination_type' => 'ring_group', 'target' => self::RING_GROUP));

		$this->assertSame(
			self::dialplanXml("\t\t<action application=\"transfer\" data=\"600 XML tenant1.example.com\"/>\n"),
			$this->row('v_dialplans', 'dialplan_uuid', self::DIALPLAN)['dialplan_xml']
		);
		$this->assertSame(array(array('record_session', '${record_path}/${record_name}', '30'), array('transfer', '600 XML tenant1.example.com', '40')), $this->actionDetails());
	}

	// FusionPBX only writes details when destinations.dialplan_details is on
	public function testAddsNoActionDetailWhenTheDialplanHasNone(): void
	{
		\FakeStore::update(function (&$state) {
			$state['tables']['v_dialplan_details'] = array();
		});

		$this->update(array('destination_type' => 'ring_group', 'target' => self::RING_GROUP));

		$this->assertSame(array(), $this->state()['tables']['v_dialplan_details']);
	}

	public function testDisablesTheDestinationAndItsDialplan(): void
	{
		$result = $this->update(array('enabled' => false));

		$this->assertFalse($result['enabled']);
		$this->assertSame('false', $this->row('v_destinations', 'destination_uuid', self::DESTINATION)['destination_enabled']);
		$dialplan = $this->row('v_dialplans', 'dialplan_uuid', self::DIALPLAN);
		$this->assertSame('false', $dialplan['dialplan_enabled']);
		$this->assertSame(self::dialplanXml(), $dialplan['dialplan_xml']);
		$this->assertCount(3, $this->state()['tables']['v_dialplan_details']);
	}

	// a dialplan edited by hand no longer says what destination_actions says:
	// better refuse than guess which line routes the call
	public function testAnswersConflictWhenTheDialplanDoesNotHoldTheActions(): void
	{
		\FakeStore::update(function (&$state) {
			$state['tables']['v_dialplans'][0]['dialplan_xml'] = self::dialplanXml("\t\t<action application=\"bridge\" data=\"sofia/gateway/x/5550000\"/>\n");
		});

		$this->assertSame(array('error' => "the destination's dialplan does not match its actions", 'code' => 409), $this->update(array('destination_type' => 'ring_group', 'target' => self::RING_GROUP)));
		$this->assertSame(array(), $this->state()['saved']);
	}

	public static function dialplanModes(): array
	{
		return array(
			'multiple' => array('multiple', array('dialplan:public')),
			'single' => array('single', array('dialplan:public:5551234')),
			'not set' => array(null, array()),
		);
	}

	// as FusionPBX's destination_edit.php does after saving
	#[DataProvider('dialplanModes')]
	public function testClearsTheDialplanCache(?string $mode, array $keys): void
	{
		\FakeStore::update(function (&$state) use ($mode) {
			$state['settings']['destinations']['dialplan_mode'] = $mode;
		});

		$this->update(array('enabled' => false));

		$this->assertSame($keys, $this->state()['cache_deleted']);
	}

	public function testAnswersNotFoundForANumberThatIsNotAnInboundDestinationOfTheDomain(): void
	{
		foreach (array('5559999', '5552000', '5550000') as $number) {
			$this->assertSame(array('error' => 'destination not found', 'code' => 404), $this->update(array('enabled' => false), $number), $number);
		}
		$this->assertSame(array(), $this->state()['saved']);
	}

	public static function missingTargets(): array
	{
		return array(
			'ring group of another domain' => array('ring_group', self::OTHER_RING_GROUP),
			'extension of another domain' => array('extension', '200'),
			'unknown ivr' => array('ivr', '88888888-0000-4000-8000-00000000ffff'),
			'unknown voicemail' => array('voicemail', '102'),
		);
	}

	#[DataProvider('missingTargets')]
	public function testAnswersNotFoundForATargetOutsideTheDomain(string $type, string $target): void
	{
		$this->assertSame(array('error' => 'target not found', 'code' => 404), $this->update(array('destination_type' => $type, 'target' => $target)));
		$this->assertSame(array(), $this->state()['saved']);
	}

	public static function invalidFields(): array
	{
		return array(
			'type without target' => array(array('destination_type' => 'extension'), 'target'),
			'target without type' => array(array('target' => '101'), 'destination_type'),
			'unknown type' => array(array('destination_type' => 'fax', 'target' => '101'), 'destination_type'),
			'extension with a dialplan character' => array(array('destination_type' => 'extension', 'target' => '101 XML x'), 'target'),
			'voicemail box not a number' => array(array('destination_type' => 'voicemail', 'target' => '*99101'), 'target'),
			'malformed ring group uuid' => array(array('destination_type' => 'ring_group', 'target' => '600'), 'target'),
			'enabled not a boolean' => array(array('enabled' => 'maybe'), 'enabled'),
			'number not a string' => array(array('number' => array('5551234'), 'enabled' => false), 'number'),
		);
	}

	#[DataProvider('invalidFields')]
	public function testRejectsAnInvalidField(array $fields, string $name): void
	{
		$this->assertSame(array('error' => 'invalid '.$name, 'code' => 400), $this->update($fields));
		$this->assertSame(array(), $this->state()['saved']);
	}

	public function testRejectsARequestWithoutAnyField(): void
	{
		$this->assertSame(array('error' => 'nothing to update', 'code' => 400), $this->update(array()));
	}

	public function testAnswers500WhenTheDatabaseFails(): void
	{
		$this->failSelects();

		$this->assertSame(array('error' => 'database error', 'code' => 500), $this->update(array('enabled' => false)));
	}

	public function testAnswers500WhenTheSaveFails(): void
	{
		$this->failSaves();

		$this->assertSame(array('error' => 'error updating destination', 'code' => 500), $this->update(array('destination_type' => 'ring_group', 'target' => self::RING_GROUP)));
		$this->assertCount(3, $this->state()['tables']['v_dialplan_details']);
		$this->assertSame(array(), $this->state()['cache_deleted']);
	}
}
