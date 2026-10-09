<?php
// call center agents in mod_callcenter, which knows each by its
// call_center_agent_uuid (as FusionPBX's agent status page uses it)

// the agent of a user in the domain, the first by agent name when there are
// several, or the response to answer: 404 without one, 500 on a database error
function rest_api_call_center_agent($database, $domain_uuid, $user_uuid) {
    // FusionPBX's select() returns false on a database error
    $sql = "SELECT call_center_agent_uuid FROM v_call_center_agents WHERE user_uuid = :user_uuid AND domain_uuid = :domain_uuid";
    $sql .= " ORDER BY agent_name, call_center_agent_uuid";
    $agents = $database->select($sql, array("user_uuid" => $user_uuid, "domain_uuid" => $domain_uuid), 'all');
    if(!is_array($agents)) {
        return array("error" => "database error", "code" => 500);
    }
    if(!$agents || !is_uuid($agents[0]["call_center_agent_uuid"])) {
        return array("error" => "agent not found", "code" => 404);
    }
    return $agents[0]["call_center_agent_uuid"];
}

// the agent's live status and state, and when its wrap-up ends, as the API
// returns them, or the 500. one "agent list" of the agent gives them all at
// once; only its own row counts. mod_callcenter offers the agent no call
// until the later of last_bridge_end + wrap_up_time (its state stays Waiting
// meanwhile) and ready_time (set by a reject, busy or no-answer delay)
function rest_api_call_center_agent_live($agent_uuid, $user_uuid) {
    $command = "api callcenter_config agent list ".$agent_uuid;
    $rows = parse_fs($command);
    if(isset($rows["error"])) {
        error_log("rest_api: ".$command." failed: ".json_encode($rows));
        return array("error" => "event socket error", "code" => 500);
    }
    foreach($rows as $row) {
        if(strtolower($row["name"] ?? "") !== $agent_uuid) {
            continue;
        }
        $until = max((int)($row["last_bridge_end"] ?? 0) + (int)($row["wrap_up_time"] ?? 0), (int)($row["ready_time"] ?? 0));
        return array(
            "user_uuid" => $user_uuid,
            "status" => $row["status"] ?? null,
            "state" => $row["state"] ?? null,
            "wrap_up_until" => $until > time() ? gmdate("Y-m-d\\TH:i:s\\Z", $until) : null,
        );
    }
    error_log("rest_api: agent ".$agent_uuid." is not loaded in mod_callcenter");
    return array("error" => "event socket error", "code" => 500);
}
