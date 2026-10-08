<?php
$required_params = array("call_uuid");
// FusionPBX has no permission to answer a call (see app_config.php)
$required_permissions = array("rest_api_call_control");

// answers a ringing call (uuid_answer) of the domain and returns it
function do_action($body) {
    $call = rest_api_call($body->call_uuid, $body->domain_uuid);
    if(isset($call["error"])) {
        return $call;
    }
    $reply = rest_api_fs_command("api uuid_answer ".$call["Unique-ID"]);
    if(is_array($reply)) {
        return $reply;
    }
    $call = rest_api_call($call["Unique-ID"], $body->domain_uuid);
    return isset($call["error"]) ? $call : rest_api_format_call($call, $body->domain_uuid);
}
