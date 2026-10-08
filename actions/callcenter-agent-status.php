<?php
$required_params = array("user_uuid");
$required_permissions = array("call_center_agent_view");

// reads, or with "status" sets, the call center status of a user's agent.
// mod_callcenter knows the agent by its call_center_agent_uuid, and the
// commands are those of FusionPBX's agent status page. a user with several
// agents in the domain is answered for the first by agent name
function do_action($body) {
    $statuses = array("Available", "Available (On Demand)", "On Break", "Logged Out");
    $set = isset($body->status);
    // saving the status needs it; save() would skip it silently
    if($set && !permission_exists("call_center_agent_edit")) {
        return array("error" => "forbidden", "missing_permissions" => array("call_center_agent_edit"), "code" => 403);
    }
    if(!is_uuid($body->user_uuid)) {
        return array("error" => "invalid user_uuid", "code" => 400);
    }
    // FusionPBX stores uuids in lower case
    $user_uuid = strtolower($body->user_uuid);
    // the status goes into an event socket command, so only the known ones
    if($set && !in_array($body->status, $statuses, true)) {
        return array("error" => "invalid status", "code" => 400);
    }

    $database = new database;
    // FusionPBX's select() returns false on a database error
    $sql = "SELECT call_center_agent_uuid FROM v_call_center_agents WHERE user_uuid = :user_uuid AND domain_uuid = :domain_uuid";
    $sql .= " ORDER BY agent_name, call_center_agent_uuid";
    $agents = $database->select($sql, array("user_uuid" => $user_uuid, "domain_uuid" => $body->domain_uuid), 'all');
    if(!is_array($agents)) {
        return array("error" => "database error", "code" => 500);
    }
    if(!$agents || !is_uuid($agents[0]["call_center_agent_uuid"])) {
        return array("error" => "agent not found", "code" => 404);
    }
    $agent_uuid = $agents[0]["call_center_agent_uuid"];

    if($set) {
        $commands = array("api callcenter_config agent set status ".$agent_uuid." '".$body->status."'");
        // as the agent status page does
        if($body->status === "Available" || $body->status === "Logged Out") {
            $commands[] = "api callcenter_config agent set state ".$agent_uuid." 'Waiting'";
        }
        foreach($commands as $command) {
            $reply = fs_api_value($command);
            if(is_array($reply)) {
                error_log("rest_api: ".$command." failed: ".json_encode($reply));
                return array("error" => "event socket error", "code" => 500);
            }
        }

        // FreeSWITCH reloads agents with the status of their row
        // (callcenter.conf.lua), so it survives a restart
        $array["call_center_agents"][] = array("call_center_agent_uuid" => $agent_uuid, "agent_status" => $body->status);
        $database->app_name = 'rest_api';
        $database->app_uuid = '2bfe71d9-e112-4b8b-bcff-75aeb0e06302';
        if(!$database->save($array)) {
            return array("error" => "error saving agent status", "code" => 500);
        }
    }

    $live = array();
    foreach(array("status", "state") as $field) {
        $command = "api callcenter_config agent get ".$field." ".$agent_uuid;
        $live[$field] = fs_api_value($command);
        if(is_array($live[$field])) {
            error_log("rest_api: ".$command." failed: ".json_encode($live[$field]));
            return array("error" => "event socket error", "code" => 500);
        }
    }
    return array("user_uuid" => $user_uuid, "status" => $live["status"], "state" => $live["state"]);
}
