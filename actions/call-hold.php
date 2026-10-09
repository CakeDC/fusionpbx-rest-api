<?php
$required_params = array("call_uuid");
// FusionPBX has no permission to hold a call (see app_config.php)
$required_permissions = array("rest_api_call_control");

// puts a call of the domain on hold (uuid_hold, never toggled, so the other
// party hears the hold music) and returns it
function do_action($body) {
    $call = rest_api_call($body->call_uuid, $body->domain_uuid);
    if(isset($call["error"])) {
        return $call;
    }
    // FreeSWITCH would refuse to hold a held call again
    if(($call["Channel-Call-State"] ?? "") !== "HELD") {
        $reply = rest_api_fs_command("api uuid_hold ".$call["Unique-ID"]);
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
