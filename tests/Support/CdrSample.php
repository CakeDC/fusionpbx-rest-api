<?php
namespace RestApi\Test\Support;

/**
 * v_xml_cdr rows shaped like FusionPBX 5.6.5 writes them. Legs are
 * linked as FusionPBX does: an "a" leg's bridge_uuid is the xml_cdr_uuid of
 * the "b" leg it was bridged to, a "b" leg's originating_leg_uuid is the
 * xml_cdr_uuid of its "a" leg. Calls of tenant1, newest first:
 *
 * - A7 + B7: inbound to 1002, linked only through A7's bridge_uuid
 * - B6: a "b" leg linked to nothing, 1003 -> 1001
 * - B5B + B5A: "b" legs to 1005 and 1004 whose "a" leg is missing; B5B is earlier
 * - A4 + B4: missed inbound call to 1003
 * - A3: outbound call from 1001, not recorded
 * - A2 + B2: local call 1002 -> 1001
 * - A1 + B1A, B1B, B1C: inbound call to ring group 5000 ringing 1003, 1004, 1005; 1003 answered, recorded
 *
 * tenant2 has X1 (same extension uuid and numbers as A2) and X2, a "b" leg
 * that claims A2 as its "a" leg. Neither may ever show up for tenant1.
 */
final class CdrSample
{
	public const DOMAIN_UUID = 'aaaaaaaa-0000-4000-8000-000000000001';
	public const OTHER_DOMAIN_UUID = 'aaaaaaaa-0000-4000-8000-000000000002';

	public const EXT_1001 = 'eeeeeeee-0000-4000-8000-000000001001';
	public const EXT_1002 = 'eeeeeeee-0000-4000-8000-000000001002';
	public const EXT_1003 = 'eeeeeeee-0000-4000-8000-000000001003';
	public const EXT_1004 = 'eeeeeeee-0000-4000-8000-000000001004';
	public const EXT_1005 = 'eeeeeeee-0000-4000-8000-000000001005';

	public const A1 = 'c0000001-0000-4000-8000-00000000000a';
	public const B1A = 'c0000001-0000-4000-8000-0000000000b1';
	public const B1B = 'c0000001-0000-4000-8000-0000000000b2';
	public const B1C = 'c0000001-0000-4000-8000-0000000000b3';
	public const A2 = 'c0000002-0000-4000-8000-00000000000a';
	public const B2 = 'c0000002-0000-4000-8000-0000000000b1';
	public const A3 = 'c0000003-0000-4000-8000-00000000000a';
	public const A4 = 'c0000004-0000-4000-8000-00000000000a';
	public const B4 = 'c0000004-0000-4000-8000-0000000000b1';
	public const MISSING_A5 = 'c0000005-0000-4000-8000-00000000000a';
	public const B5A = 'c0000005-0000-4000-8000-0000000000b1';
	public const B5B = 'c0000005-0000-4000-8000-0000000000b2';
	public const B6 = 'c0000006-0000-4000-8000-0000000000b1';
	public const A7 = 'c0000007-0000-4000-8000-00000000000a';
	public const B7 = 'c0000007-0000-4000-8000-0000000000b1';
	public const X1 = 'c0000009-0000-4000-8000-00000000000a';
	public const X2 = 'c0000009-0000-4000-8000-0000000000b1';

	public const RECORD_PATH = '/var/lib/freeswitch/recordings/tenant1.example.com/archive/2026/Sep/01';

	/** The v_xml_cdr table. */
	public static function rows(): array
	{
		return array(
			self::leg(self::A1, 'a', '2026-09-01 09:00:00+00', array('direction' => 'inbound', 'caller_id_name' => 'ACME', 'caller_id_number' => '+15550001111', 'destination_number' => '5000', 'bridge_uuid' => self::B1A, 'duration' => '95', 'record_name' => 'c1.wav', 'record_path' => self::RECORD_PATH)),
			self::leg(self::B1A, 'b', '2026-09-01 09:00:02+00', array('direction' => 'inbound', 'caller_id_number' => '+15550001111', 'destination_number' => '1003', 'originating_leg_uuid' => self::A1, 'extension_uuid' => self::EXT_1003, 'duration' => '90', 'record_name' => 'c1.wav', 'record_path' => self::RECORD_PATH)),
			self::leg(self::B1B, 'b', '2026-09-01 09:00:02+00', array('direction' => 'inbound', 'caller_id_number' => '+15550001111', 'destination_number' => '1004', 'originating_leg_uuid' => self::A1, 'extension_uuid' => self::EXT_1004, 'hangup_cause' => 'ORIGINATOR_CANCEL', 'hangup_cause_q850' => '487')),
			self::leg(self::B1C, 'b', '2026-09-01 09:00:02+00', array('direction' => 'inbound', 'caller_id_number' => '+15550001111', 'destination_number' => '1005', 'originating_leg_uuid' => self::A1, 'extension_uuid' => self::EXT_1005, 'hangup_cause' => 'ORIGINATOR_CANCEL', 'hangup_cause_q850' => '487')),
			self::leg(self::A2, 'a', '2026-09-02 10:00:00+00', array('direction' => 'local', 'caller_id_number' => '1002', 'destination_number' => '1001', 'extension_uuid' => self::EXT_1002, 'bridge_uuid' => self::B2)),
			self::leg(self::B2, 'b', '2026-09-02 10:00:01+00', array('direction' => 'local', 'caller_id_number' => '1002', 'destination_number' => '1001', 'extension_uuid' => self::EXT_1001, 'originating_leg_uuid' => self::A2)),
			self::leg(self::A3, 'a', '2026-09-03 11:00:00+00', array('direction' => 'outbound', 'caller_id_number' => '1001', 'destination_number' => '+15559998888', 'extension_uuid' => self::EXT_1001, 'record_name' => '', 'record_path' => '')),
			self::leg(self::A4, 'a', '2026-09-04 12:00:00+00', array('direction' => 'inbound', 'caller_id_number' => '+15557770000', 'destination_number' => '1003', 'extension_uuid' => self::EXT_1003, 'missed_call' => 'true', 'hangup_cause' => 'NO_ANSWER', 'hangup_cause_q850' => '19', 'duration' => '30')),
			self::leg(self::B4, 'b', '2026-09-04 12:00:01+00', array('direction' => 'inbound', 'caller_id_number' => '+15557770000', 'destination_number' => '1003', 'extension_uuid' => self::EXT_1003, 'originating_leg_uuid' => self::A4, 'missed_call' => 'true', 'hangup_cause' => 'NO_ANSWER', 'hangup_cause_q850' => '19')),
			self::leg(self::B5A, 'b', '2026-09-05 13:00:01+00', array('direction' => 'inbound', 'caller_id_number' => '+15556660000', 'destination_number' => '1004', 'extension_uuid' => self::EXT_1004, 'originating_leg_uuid' => self::MISSING_A5)),
			self::leg(self::B5B, 'b', '2026-09-05 13:00:00+00', array('direction' => 'inbound', 'caller_id_number' => '+15556660000', 'destination_number' => '1005', 'extension_uuid' => self::EXT_1005, 'originating_leg_uuid' => self::MISSING_A5)),
			self::leg(self::B6, 'b', '2026-09-06 14:00:00+00', array('direction' => 'local', 'caller_id_number' => '1003', 'destination_number' => '1001', 'extension_uuid' => self::EXT_1001)),
			self::leg(self::A7, 'a', '2026-09-07 15:00:00+00', array('direction' => 'inbound', 'caller_id_number' => '+15554443333', 'destination_number' => '1002', 'bridge_uuid' => self::B7)),
			self::leg(self::B7, 'b', '2026-09-07 15:00:01+00', array('direction' => 'inbound', 'caller_id_number' => '+15554443333', 'destination_number' => '1002', 'extension_uuid' => self::EXT_1002)),
			self::leg(self::X1, 'a', '2026-09-02 10:00:00+00', array('domain_uuid' => self::OTHER_DOMAIN_UUID, 'direction' => 'local', 'caller_id_number' => '1002', 'destination_number' => '1001', 'extension_uuid' => self::EXT_1001)),
			self::leg(self::X2, 'b', '2026-09-02 10:00:01+00', array('domain_uuid' => self::OTHER_DOMAIN_UUID, 'direction' => 'local', 'caller_id_number' => '1002', 'destination_number' => '1003', 'extension_uuid' => self::EXT_1003, 'originating_leg_uuid' => self::A2)),
		);
	}

	private static function leg(string $uuid, string $leg, string $start, array $columns): array
	{
		// as Postgres prints a timestamptz in UTC
		$end = (new \DateTimeImmutable($start))->modify('+1 minute')->format('Y-m-d H:i:s').'+00';
		return array_merge(array(
			'xml_cdr_uuid' => $uuid,
			'domain_uuid' => self::DOMAIN_UUID,
			'extension_uuid' => null,
			'direction' => 'inbound',
			'caller_id_name' => null,
			'caller_id_number' => null,
			'caller_destination' => null,
			'source_number' => null,
			'destination_number' => null,
			'start_stamp' => $start,
			'end_stamp' => $end,
			'duration' => '60',
			'hangup_cause' => 'NORMAL_CLEARING',
			'hangup_cause_q850' => '16',
			'missed_call' => 'false',
			'leg' => $leg,
			'bridge_uuid' => null,
			'originating_leg_uuid' => null,
			'record_name' => null,
			'record_path' => null,
			'call_center_queue_uuid' => null,
			'cc_queue' => null,
			'json' => '{"variables":{"secret":"not returned"}}',
		), $columns);
	}
}
