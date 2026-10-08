<?php
$required_params = array("call_uuid");
$required_permissions = array("call_active_hangup");

// hangs up a call of the domain (uuid_kill, as FusionPBX's active calls page
// does)
function do_action($body) {
    $call = rest_api_call($body->call_uuid, $body->domain_uuid);
    if(isset($call["error"])) {
        return $call;
    }
    $reply = rest_api_fs_command("api uuid_kill ".$call["Unique-ID"]);
    if(is_array($reply)) {
        return $reply;
    }
    return array("code" => 204);
}
