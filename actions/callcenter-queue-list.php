<?php
$required_params = array();
$required_permissions = array("call_center_queue_view");

// the call center queues of a domain, by extension
function do_action($body) {
    $sql = "SELECT call_center_queue_uuid, queue_name, queue_extension, queue_strategy, queue_tier_rule_wait_second FROM v_call_center_queues";
    $sql .= " WHERE domain_uuid = :domain_uuid ORDER BY queue_extension, call_center_queue_uuid";
    $database = new database;
    // FusionPBX's select() returns false on a database error
    $queues = $database->select($sql, array("domain_uuid" => $body->domain_uuid), 'all');
    if(!is_array($queues)) {
        return array("error" => "database error", "code" => 500);
    }
    return array("data" => array_map("rest_api_format_call_center_queue", $queues));
}
