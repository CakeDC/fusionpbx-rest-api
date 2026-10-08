<?php
$required_params = array("user_uuid", "state");
$required_permissions = array("call_center_agent_view", "call_center_agent_edit");

// sets the call center state of a user's agent, e.g. "Waiting" to end its
// wrap-up, and returns its live status and state. the state only lives in
// mod_callcenter: FusionPBX keeps no column for it
function do_action($body) {
    $states = array("Waiting", "In a queue call", "Receiving a call", "Wrap-up");
    if(!is_uuid($body->user_uuid)) {
        return array("error" => "invalid user_uuid", "code" => 400);
    }
    // FusionPBX stores uuids in lower case
    $user_uuid = strtolower($body->user_uuid);
    // the state goes into an event socket command, so only the known ones
    if(!in_array($body->state, $states, true)) {
        return array("error" => "invalid state", "code" => 400);
    }

    $agent_uuid = rest_api_call_center_agent(new database, $body->domain_uuid, $user_uuid);
    if(is_array($agent_uuid)) {
        return $agent_uuid;
    }
    $reply = rest_api_call_center_command("api callcenter_config agent set state ".$agent_uuid." '".$body->state."'");
    if(is_array($reply)) {
        return $reply;
    }
    return rest_api_call_center_agent_live($agent_uuid, $user_uuid);
}
