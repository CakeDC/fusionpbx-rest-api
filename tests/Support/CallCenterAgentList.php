<?php
namespace RestApi\Test\Support;

/**
 * The reply of mod_callcenter's "callcenter_config agent list <agent>": its
 * agents table, pipe separated, with the header before the first row only
 * (list_result_callback), so an agent it doesn't know is a bare +OK.
 */
final class CallCenterAgentList
{
	public const HEADER = 'name|instance_id|uuid|type|contact|status|state|max_no_answer|wrap_up_time|reject_delay_time|busy_delay_time|no_answer_delay_time|last_bridge_start|last_bridge_end|last_offered_call|last_status_change|no_answer_count|calls_answered|talk_time|ready_time|external_calls_count';

	public static function command(string $agent_uuid): string
	{
		return 'api callcenter_config agent list '.$agent_uuid;
	}

	/**
	 * One agent's row; $times sets wrap_up_time, last_bridge_end and
	 * ready_time (epochs, 0 when never set).
	 */
	public static function row(string $agent_uuid, string $status, string $state, array $times = array()): string
	{
		$times += array('wrap_up_time' => 10, 'last_bridge_end' => 0, 'ready_time' => 0);
		return $agent_uuid.'|single_box||callback|user/101@tenant1.example.com|'.$status.'|'.$state.'|3|'.$times['wrap_up_time'].'|10|60|0|0|'.$times['last_bridge_end'].'|0|1759900000|0|4|120|'.$times['ready_time'].'|0';
	}

	/** The reply listing these rows. */
	public static function reply(string ...$rows): string
	{
		return implode("\n", array_merge($rows ? array(self::HEADER) : array(), $rows, array('+OK')))."\n";
	}
}
