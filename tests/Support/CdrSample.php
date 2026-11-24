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
 *
 * queueRows() adds call center calls, with the values mod_callcenter sets on
 * the member (caller) and agent channels. Calls of tenant1, newest first:
 *
 * - QA4: support queue call whose unset values FusionPBX stored empty, as
 *   "_undef_" or as a 0 epoch
 * - QA3: support queue, the caller hung up after 4 seconds of waiting
 * - QA2: sales queue, abandoned after 40 seconds
 * - QA1 + QB1: sales queue, answered by 1001 after 12 seconds
 *
 * tenant2 has XQ1, in a queue with the sales queue's uuid.
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

	public const QA1 = 'c0000011-0000-4000-8000-00000000000a';
	public const QB1 = 'c0000011-0000-4000-8000-0000000000b1';
	public const QA2 = 'c0000012-0000-4000-8000-00000000000a';
	public const QA3 = 'c0000013-0000-4000-8000-00000000000a';
	public const QA4 = 'c0000014-0000-4000-8000-00000000000a';
	public const XQ1 = 'c0000019-0000-4000-8000-00000000000a';

	public const QA5 = 'c0000015-0000-4000-8000-00000000000a';
	public const QB5 = 'c0000015-0000-4000-8000-0000000000b1';

	public const QUEUE_SALES = 'dddddddd-0000-4000-8000-000000000001';
	public const QUEUE_SUPPORT = 'dddddddd-0000-4000-8000-000000000002';

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

	/** Call center calls, to add to rows(). */
	public static function queueRows(): array
	{
		$sales = array('call_center_queue_uuid' => self::QUEUE_SALES, 'cc_queue' => '6000@tenant1.example.com');
		$support = array('call_center_queue_uuid' => self::QUEUE_SUPPORT, 'cc_queue' => '6001@tenant1.example.com');
		return array(
			self::leg(self::QA1, 'a', '2026-09-08 09:00:00+00', $sales + array('caller_id_number' => '+15552220001', 'destination_number' => '6000', 'bridge_uuid' => self::QB1, 'duration' => '180', 'cc_side' => 'member', 'cc_queue_joined_epoch' => '1788858000', 'cc_queue_answered_epoch' => '1788858012', 'cc_cause' => 'answered')),
			self::leg(self::QB1, 'b', '2026-09-08 09:00:12+00', $sales + array('caller_id_number' => '+15552220001', 'destination_number' => '1001', 'extension_uuid' => self::EXT_1001, 'duration' => '168', 'cc_side' => 'agent')),
			self::leg(self::QA2, 'a', '2026-09-08 10:00:00+00', $sales + array('caller_id_number' => '+15552220002', 'destination_number' => '6000', 'duration' => '40', 'missed_call' => 'true', 'cc_side' => 'member', 'cc_queue_joined_epoch' => '1788861600', 'cc_queue_canceled_epoch' => '1788861640', 'cc_cancel_reason' => 'BREAK_OUT', 'cc_cause' => 'cancel')),
			self::leg(self::QA3, 'a', '2026-09-08 11:00:00+00', $support + array('caller_id_number' => '+15552220003', 'destination_number' => '6001', 'duration' => '4', 'missed_call' => 'true', 'cc_side' => 'member', 'cc_queue_joined_epoch' => '1788865200', 'cc_queue_canceled_epoch' => '1788865204', 'cc_cancel_reason' => 'BREAK_OUT', 'cc_cause' => 'cancel')),
			self::leg(self::QA4, 'a', '2026-09-08 12:00:00+00', $support + array('caller_id_number' => '+15552220004', 'destination_number' => '6001', 'cc_side' => '', 'cc_queue_joined_epoch' => '1788868800', 'cc_queue_answered_epoch' => '0', 'cc_cancel_reason' => '_undef_', 'cc_cause' => '')),
			self::leg(self::XQ1, 'a', '2026-09-08 09:30:00+00', $sales + array('domain_uuid' => self::OTHER_DOMAIN_UUID, 'caller_id_number' => '+15552220009', 'destination_number' => '6000', 'cc_side' => 'member', 'cc_queue_joined_epoch' => '1788859800', 'cc_cause' => 'answered')),
		);
	}

	public static function leg(string $uuid, string $leg, string $start, array $columns): array
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
			'cc_side' => null,
			'cc_queue_joined_epoch' => null,
			'cc_queue_answered_epoch' => null,
			'cc_queue_canceled_epoch' => null,
			'cc_cancel_reason' => null,
			'cc_cause' => null,
			'json' => '{"variables":{"secret":"not returned"}}',
		), $columns);
	}
}
