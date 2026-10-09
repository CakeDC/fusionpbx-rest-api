<?php
$required_params = array("call_uuid", "target_type", "target");
$required_permissions = array("call_active_transfer");

// blind transfer of a call of the domain to an extension, ring group or
// queue of the domain. call_uuid is the agent's call: when it is bridged, the
// other party is transferred (uuid_transfer -bleg, as FusionPBX's active calls
// page parks a call), otherwise the channel itself. either way the agent is
// done with the call, which is returned as ended
function do_action($body) {
    $types = array("extension", "ring_group", "queue");
    if(!in_array($body->target_type, $types, true)) {
        return array("error" => "invalid target_type", "code" => 400);
    }
    // the target ends up in an event socket command
    $valid = $body->target_type === "extension" ? is_dial_number($body->target) : is_uuid($body->target);
    if(!$valid) {
        return array("error" => "invalid target", "code" => 400);
    }
    $target = $body->target_type === "extension" ? (string)$body->target : strtolower($body->target);

    // "<number> XML <context>", as the dialplan reaches the target
    $destination = rest_api_destination_transfer_data(new database, $body->domain_uuid, $body->target_type, $target);
    if($destination === false) {
        return array("error" => "database error", "code" => 500);
    }
    if($destination === null) {
        return array("error" => "target not found", "code" => 404);
    }

    $call = rest_api_call($body->call_uuid, $body->domain_uuid);
    if(isset($call["error"])) {
        return $call;
    }
    $bleg = is_uuid(strtolower((string)($call["Other-Leg-Unique-ID"] ?? ""))) ? " -bleg" : "";
    $reply = rest_api_fs_command("api uuid_transfer ".$call["Unique-ID"].$bleg." ".$destination);
    if(is_array($reply)) {
        return $reply;
    }
    return rest_api_call_ended($call, $body->domain_uuid);
}
