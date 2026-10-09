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
    // the call may have ended since the check: it is over, as asked
    $command = "api uuid_kill ".$call["Unique-ID"];
    $reply = fs_api_value($command);
    if(is_array($reply) && strpos($reply["details"] ?? "", "-ERR No such channel") !== 0) {
        error_log("rest_api: ".$command." failed: ".json_encode($reply));
        return array("error" => "event socket error", "code" => 500);
    }
    return array("code" => 204);
}
