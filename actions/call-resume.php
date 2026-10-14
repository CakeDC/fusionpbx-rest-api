<?php
$required_params = array("call_uuid");
// FusionPBX has no permission to resume a call (see app_config.php)
$required_permissions = array("rest_api_call_control");

// takes a held call of the domain off hold (uuid_hold off) and returns it
function do_action($body) {
    $call = rest_api_call($body->call_uuid, $body->domain_uuid);
    if(isset($call["error"])) {
        return $call;
    }
    // a call that isn't held is left as it is
    if(($call["Channel-Call-State"] ?? "") === "HELD") {
        $reply = rest_api_fs_command("api uuid_hold off ".$call["Unique-ID"]);
        if(is_array($reply)) {
            return $reply;
        }
        $call = rest_api_call($call["Unique-ID"], $body->domain_uuid);
        if(isset($call["error"])) {
            return $call;
        }
    }
    return rest_api_format_call($call, $body->domain_uuid);
}
