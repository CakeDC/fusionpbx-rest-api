<?php
$required_params = array();
$required_permissions = array("call_center_agent_view", "call_center_tier_view");

// the call center agents of a domain, by name, each with the queues it serves
// (its tiers, by level then position) and its wrap-up time. an agent without a
// FusionPBX user is left out: the API identifies agents by user_uuid
function do_action($body) {
    $database = new database;
    $parameters = array("domain_uuid" => $body->domain_uuid);
    // FusionPBX's select() returns false on a database error
    $sql = "SELECT call_center_agent_uuid, user_uuid, agent_wrap_up_time FROM v_call_center_agents";
    $sql .= " WHERE domain_uuid = :domain_uuid AND user_uuid IS NOT NULL ORDER BY agent_name, call_center_agent_uuid";
    $agents = $database->select($sql, $parameters, 'all');
    if(!is_array($agents)) {
        return array("error" => "database error", "code" => 500);
    }

    // the tiers of every agent in one query
    $queues = array();
    if($agents) {
        $placeholders = array();
        foreach($agents as $i => $agent) {
            $placeholders[] = ":agent_".$i;
            $parameters["agent_".$i] = $agent["call_center_agent_uuid"];
        }
        $sql = "SELECT call_center_agent_uuid, call_center_queue_uuid, tier_level, tier_position FROM v_call_center_tiers";
        $sql .= " WHERE domain_uuid = :domain_uuid AND call_center_agent_uuid IN (".implode(", ", $placeholders).")";
        $tiers = $database->select($sql, $parameters, 'all');
        if(!is_array($tiers)) {
            return array("error" => "database error", "code" => 500);
        }
        // level and position are numeric columns, sorted here as numbers
        usort($tiers, function($a, $b) {
            return array((float)$a["tier_level"], (float)$a["tier_position"], $a["call_center_queue_uuid"])
                <=> array((float)$b["tier_level"], (float)$b["tier_position"], $b["call_center_queue_uuid"]);
        });
        foreach($tiers as $tier) {
            $queues[$tier["call_center_agent_uuid"]][] = array(
                "call_center_queue_uuid" => $tier["call_center_queue_uuid"],
                "level" => (int)$tier["tier_level"],
                "position" => (int)$tier["tier_position"],
            );
        }
    }

    $data = array();
    foreach($agents as $agent) {
        $data[] = array(
            "user_uuid" => $agent["user_uuid"],
            "queues" => $queues[$agent["call_center_agent_uuid"]] ?? array(),
            "wrap_up_time" => is_numeric($agent["agent_wrap_up_time"]) ? (int)$agent["agent_wrap_up_time"] : null,
        );
    }
    return array("data" => $data);
}
