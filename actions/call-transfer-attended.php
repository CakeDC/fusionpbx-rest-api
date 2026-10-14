<?php
$required_params = array("call_uuid", "stage");
$required_permissions = array("call_active_transfer");

// warm transfer with FreeSWITCH's att_xfer, run on the agent's leg (call_uuid,
// bridged to the caller). not yet verified on a real FusionPBX 5.6.5 call.
// - consult: att_xfer holds the caller with music and calls the target through
//   the domain's dialplan. the consult leg's uuid and the number consulted
//   are noted on the agent's channel (rest_api_consult_uuid,
//   rest_api_consult_target), so nothing is kept between requests
// - cancel: hangs up the consult leg; att_xfer returns the agent to the caller
// - complete: hangs up the agent's leg; att_xfer bridges caller and target.
//   the agent's call is returned as ended
// att_xfer also ends a consultation on its own (the target doesn't answer or
// hangs up), so the consult leg is read on every stage: a note whose leg is
// gone is stale, and is cleared
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
    $consultation = rest_api_consultation($call);
    if(isset($consultation["error"])) {
        return $consultation;
    }

    if($body->stage === "consult") {
        if($consultation !== null) {
            return array("error" => "consultation already in progress", "code" => 400);
        }
        if(!is_uuid(strtolower((string)($call["Other-Leg-Unique-ID"] ?? "")))) {
            return array("error" => "call is not bridged", "code" => 400);
        }
        // a stale note is overwritten
        $consult = uuid();
        $target = (string)$body->target;
        $commands = array(
            "api uuid_setvar ".$agent." rest_api_consult_uuid ".$consult,
            "api uuid_setvar ".$agent." rest_api_consult_target ".$target,
            "api uuid_broadcast ".$agent." att_xfer::{origination_uuid=".$consult."}loopback/".$target."/".$domain_name." aleg",
        );
        foreach($commands as $command) {
            if(is_array($reply = rest_api_fs_command($command))) {
                return $reply;
            }
        }
        // uuid_broadcast only queues att_xfer, so the consult leg may not
        // exist yet: the call is answered as the consultation makes it
        return rest_api_call_item($call, $body->domain_uuid, $target);
    }

    if($consultation === null) {
        if(rest_api_noted_consultation($call) !== null && is_array($reply = rest_api_forget_consultation($agent))) {
            return $reply;
        }
        return array("error" => "no consultation in progress", "code" => 400);
    }

    if($body->stage === "cancel") {
        // the consulted party may have hung up since
        $reply = fs_api_value("api uuid_kill ".$consultation["uuid"]);
        if(is_array($reply) && strpos($reply["details"] ?? "", "-ERR No such channel") !== 0) {
            error_log("rest_api: api uuid_kill ".$consultation["uuid"]." failed: ".json_encode($reply));
            return array("error" => "event socket error", "code" => 500);
        }
        if(is_array($reply = rest_api_forget_consultation($agent))) {
            return $reply;
        }
        return rest_api_call_after($agent, $body->domain_uuid);
    }

    // complete: only once the consulted party talks with the agent, or
    // hanging up the agent's leg would just end the call
    $leg = $consultation["leg"];
    if(($leg["Answer-State"] ?? "") !== "answered" || strtolower((string)($leg["variable_bridge_uuid"] ?? "")) !== $agent) {
        return array("error" => "consultation not answered", "code" => 400);
    }
    if(is_array($reply = rest_api_fs_command("api uuid_kill ".$agent))) {
        return $reply;
    }
    return rest_api_call_ended($call, $body->domain_uuid);
}

// clears the consultation noted on the agent's channel
function rest_api_forget_consultation($agent) {
    foreach(array("rest_api_consult_uuid", "rest_api_consult_target") as $variable) {
        if(is_array($reply = rest_api_fs_command("api uuid_setvar ".$agent." ".$variable))) {
            return $reply;
        }
    }
    return null;
}
