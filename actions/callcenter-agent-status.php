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
    $agent_uuid = rest_api_call_center_agent($database, $body->domain_uuid, $user_uuid);
    if(is_array($agent_uuid)) {
        return $agent_uuid;
    }

    if($set) {
        $reply = rest_api_call_center_command("api callcenter_config agent set status ".$agent_uuid." '".$body->status."'");
        if(is_array($reply)) {
            return $reply;
        }

        // FreeSWITCH reloads agents with the status of their row
        // (callcenter.conf.lua), so it survives a restart. saved as soon as
        // mod_callcenter has it, so the row matches it whatever comes next
        $array["call_center_agents"][] = array("call_center_agent_uuid" => $agent_uuid, "agent_status" => $body->status);
        $database->app_name = 'rest_api';
        $database->app_uuid = '2bfe71d9-e112-4b8b-bcff-75aeb0e06302';
        if(!$database->save($array)) {
            return array("error" => "error saving agent status", "code" => 500);
        }

        // as the agent status page does
        if($body->status === "Available" || $body->status === "Logged Out") {
            $reply = rest_api_call_center_command("api callcenter_config agent set state ".$agent_uuid." 'Waiting'");
            if(is_array($reply)) {
                return $reply;
            }
        }
    }

    return rest_api_call_center_agent_live($agent_uuid, $user_uuid);
}
