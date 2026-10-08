<?php
$required_params = array("call_uuid", "stage");
$required_permissions = array("call_active_transfer");

// warm transfer with FreeSWITCH's att_xfer, run on the agent's leg (call_uuid,
// bridged to the caller). not yet verified on a real FusionPBX 5.6.5 call.
// - consult: att_xfer holds the caller with music and calls the target through
//   the domain's dialplan. the consult leg's uuid and the caller's are kept
//   on the agent's channel (rest_api_consult_uuid, rest_api_consult_caller),
//   so nothing is kept between requests
// - cancel: hangs up the consult leg; att_xfer returns the agent to the caller
// - complete: hangs up the agent's leg; att_xfer bridges caller and target
function do_action($body) {
    $stages = array("consult", "cancel", "complete");
    if(!in_array($body->stage, $stages, true)) {
        return array("error" => "invalid stage", "code" => 400);
    }
    if($body->stage === "consult") {
        // the target goes into a dial string
        if(!isset($body->target) || !is_dial_number($body->target)) {
            return array("error" => "invalid target", "code" => 400);
        }
        // FusionPBX's select() returns false on a database error
        $domain_name = (new database)->select("SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid", array("domain_uuid" => $body->domain_uuid), 'column');
        if($domain_name === false || $domain_name === null) {
            return array("error" => "database error", "code" => 500);
        }
    }

    $call = rest_api_call($body->call_uuid, $body->domain_uuid);
    if(isset($call["error"])) {
        return $call;
    }
    $agent = $call["Unique-ID"];
    $consult = strtolower((string)($call["variable_rest_api_consult_uuid"] ?? ""));
    $caller = strtolower((string)($call["variable_rest_api_consult_caller"] ?? ""));

    if($body->stage === "consult") {
        if($consult !== "") {
            return array("error" => "consultation already in progress", "code" => 400);
        }
        $caller = strtolower((string)($call["Other-Leg-Unique-ID"] ?? ""));
        if(!is_uuid($caller)) {
            return array("error" => "call is not bridged", "code" => 400);
        }
        $consult = uuid();
        $commands = array(
            "api uuid_setvar ".$agent." rest_api_consult_uuid ".$consult,
            "api uuid_setvar ".$agent." rest_api_consult_caller ".$caller,
            "api uuid_broadcast ".$agent." att_xfer::{origination_uuid=".$consult."}loopback/".$body->target."/".$domain_name." aleg",
        );
        foreach($commands as $command) {
            if(is_array($reply = rest_api_fs_command($command))) {
                return $reply;
            }
        }
        return rest_api_call_after($agent, $body->domain_uuid);
    }

    if(!is_uuid($consult) || !is_uuid($caller)) {
        return array("error" => "no consultation in progress", "code" => 400);
    }

    if($body->stage === "cancel") {
        // the consulted party may have hung up already
        $reply = fs_api_value("api uuid_kill ".$consult);
        if(is_array($reply) && strpos($reply["details"] ?? "", "-ERR No such channel") !== 0) {
            error_log("rest_api: api uuid_kill ".$consult." failed: ".json_encode($reply));
            return array("error" => "event socket error", "code" => 500);
        }
        foreach(array("rest_api_consult_uuid", "rest_api_consult_caller") as $variable) {
            if(is_array($reply = rest_api_fs_command("api uuid_setvar ".$agent." ".$variable))) {
                return $reply;
            }
        }
        return rest_api_call_after($agent, $body->domain_uuid);
    }

    // complete
    if(is_array($reply = rest_api_fs_command("api uuid_kill ".$agent))) {
        return $reply;
    }
    return rest_api_call_after($caller, $body->domain_uuid);
}
