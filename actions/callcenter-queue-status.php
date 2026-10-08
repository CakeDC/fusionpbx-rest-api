<?php
$required_params = array("call_center_queue_uuid");
$required_permissions = array("call_center_active_view");

// live counts of a call center queue, from mod_callcenter. FusionPBX loads a
// queue as <queue_extension>@<domain_name>, the name its Active Call Center
// page asks the event socket about
function do_action($body) {
    if(!is_uuid($body->call_center_queue_uuid)) {
        return array("error" => "invalid call_center_queue_uuid", "code" => 400);
    }
    // FusionPBX stores uuids in lower case
    $queue_uuid = strtolower($body->call_center_queue_uuid);

    $database = new database;
    // FusionPBX's select() returns false on a database error
    $sql = "SELECT q.queue_extension, d.domain_name FROM v_call_center_queues q LEFT JOIN v_domains d ON d.domain_uuid = q.domain_uuid";
    $sql .= " WHERE q.call_center_queue_uuid = :call_center_queue_uuid AND q.domain_uuid = :domain_uuid";
    $queues = $database->select($sql, array("call_center_queue_uuid" => $queue_uuid, "domain_uuid" => $body->domain_uuid), 'all');
    if(!is_array($queues)) {
        return array("error" => "database error", "code" => 500);
    }
    if(!$queues) {
        return array("error" => "queue not found", "code" => 404);
    }
    // the name goes into an event socket command, so it must be one word
    $name = $queues[0]["queue_extension"]."@".$queues[0]["domain_name"];
    if(!preg_match('/^[^\s|]+$/D', $name)) {
        return array("error" => "invalid queue extension", "code" => 500);
    }

    $members = parse_fs("api callcenter_config queue list members ".$name);
    $agents = isset($members["error"]) ? $members : parse_fs("api callcenter_config queue list agents ".$name);
    if(isset($members["error"]) || isset($agents["error"])) {
        error_log("rest_api: callcenter_config for ".$name." failed: ".json_encode(isset($members["error"]) ? $members : $agents));
        return array("error" => "event socket error", "code" => 500);
    }

    // members are every call in the queue; waiting ones have no agent yet
    $waiting = array_filter($members, function($member) {
        return ($member["state"] ?? null) === "Waiting";
    });
    return array(
        "call_center_queue_uuid" => $queue_uuid,
        "waiting_calls" => count($waiting),
        "member_count" => count($members),
        "agent_count" => count($agents),
    );
}
