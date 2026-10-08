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

// the agent's live status and state, as the API returns them, or the 500
function rest_api_call_center_agent_live($agent_uuid, $user_uuid) {
    $live = array("user_uuid" => $user_uuid);
    foreach(array("status", "state") as $field) {
        $live[$field] = rest_api_fs_command("api callcenter_config agent get ".$field." ".$agent_uuid);
        if(is_array($live[$field])) {
            return $live[$field];
        }
    }
    return $live;
}
