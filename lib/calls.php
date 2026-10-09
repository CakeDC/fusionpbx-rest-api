<?php
// active calls in FreeSWITCH, through the event socket

// the API's call state from a channel's FreeSWITCH call state. an answered
// channel bridged to another leg is "bridged"; a state FreeSWITCH adds later
// counts as ringing
function rest_api_call_state($callstate, $bridged) {
    $states = array(
        "DOWN" => "ringing", "DIALING" => "ringing", "RINGING" => "ringing", "EARLY" => "ringing", "RING_WAIT" => "ringing",
        "ACTIVE" => "answered", "UNHELD" => "answered", "HELD" => "held", "HANGUP" => "ended",
    );
    $state = $states[$callstate] ?? "ringing";
    return $state === "answered" && $bridged ? "bridged" : $state;
}

// the channel of a call in the domain, as "uuid_dump <uuid> json" gives it,
// or the response to answer: 400 for a malformed uuid, 404 when the channel
// doesn't exist or belongs to another domain (a call_uuid is global to
// FreeSWITCH), 500 when the event socket fails
function rest_api_call($call_uuid, $domain_uuid) {
    if(!is_uuid($call_uuid)) {
        return array("error" => "invalid call_uuid", "code" => 400);
    }
    $command = "api uuid_dump ".strtolower($call_uuid)." json";
    $dump = fs_api_json($command);
    if(isset($dump["error"])) {
        if(strpos($dump["details"] ?? "", "-ERR No such channel") === 0) {
            return array("error" => "call not found", "code" => 404);
        }
        error_log("rest_api: ".$command." failed: ".json_encode($dump));
        return array("error" => "event socket error", "code" => 500);
    }
    if(strtolower((string)($dump["variable_domain_uuid"] ?? "")) !== strtolower((string)$domain_uuid)) {
        return array("error" => "call not found", "code" => 404);
    }
    // commands use the uuid checked here, not one FreeSWITCH reports back
    $dump["Unique-ID"] = strtolower($call_uuid);
    return $dump;
}

// a channel dump as the API returns a call, with the number it is
// consulting in a warm transfer (or null), or the 500 when the event socket
// fails
function rest_api_format_call($dump, $domain_uuid) {
    $consultation = rest_api_consultation($dump);
    if(isset($consultation["error"])) {
        return $consultation;
    }
    return rest_api_call_item($dump, $domain_uuid, $consultation === null ? null : $consultation["target"]);
}

// a channel dump as a Call. the agent's call is held while it consults
function rest_api_call_item($dump, $domain_uuid, $consulting) {
    $caller = (string)($dump["Caller-Caller-ID-Number"] ?? "");
    $destination = (string)($dump["Caller-Destination-Number"] ?? "");
    $state = rest_api_call_state($dump["Channel-Call-State"] ?? "", !empty($dump["Other-Leg-Unique-ID"]));
    return array(
        "call_uuid" => $dump["Unique-ID"],
        "domain_uuid" => $domain_uuid,
        "state" => $consulting !== null && $state !== "ended" ? "held" : $state,
        "caller_id_number" => $caller !== "" ? $caller : null,
        "destination_number" => $destination !== "" ? $destination : null,
        "consulting" => $consulting,
    );
}

// the agent's call once a transfer is done with it, as it was before, ended
function rest_api_call_ended($dump, $domain_uuid) {
    $call = rest_api_call_item($dump, $domain_uuid, null);
    $call["state"] = "ended";
    return $call;
}

// the warm transfer noted on the agent's channel by call-transfer-attended
// (the consult leg and the number consulted), or null
function rest_api_noted_consultation($dump) {
    $noted = array();
    foreach(array("uuid", "target") as $field) {
        $noted[$field] = strtolower((string)($dump["variable_rest_api_consult_".$field] ?? ""));
    }
    return is_uuid($noted["uuid"]) && is_dial_number($noted["target"]) ? $noted : null;
}

// the consult leg of a warm transfer, as "uuid_dump <uuid> json" gives it,
// null when it is gone, or the 500 when the event socket fails
function rest_api_consult_leg($consult_uuid) {
    $command = "api uuid_dump ".$consult_uuid." json";
    $dump = fs_api_json($command);
    if(isset($dump["error"])) {
        if(strpos($dump["details"] ?? "", "-ERR No such channel") === 0) {
            return null;
        }
        error_log("rest_api: ".$command." failed: ".json_encode($dump));
        return array("error" => "event socket error", "code" => 500);
    }
    return $dump;
}

// the warm transfer the agent's channel is in: the noted one while its
// consult leg exists. att_xfer ends a consultation on its own when the target
// doesn't answer or hangs up, so the note alone can be stale. null when there
// is none, or the 500 when the event socket fails
function rest_api_consultation($dump) {
    $noted = rest_api_noted_consultation($dump);
    if($noted === null) {
        return null;
    }
    $leg = rest_api_consult_leg($noted["uuid"]);
    if($leg === null || isset($leg["error"])) {
        return $leg;
    }
    return $noted + array("leg" => $leg);
}

// the number a channel listed by "show channels" consults in a warm
// transfer: the noted one while its consult leg is among the live channels
// ($live: uuid => true). null when there is none, or the 500 when the event
// socket fails
function rest_api_listed_consulting($uuid, $live) {
    // the uuid goes into an event socket command
    if(!is_uuid($uuid)) {
        return null;
    }
    $noted = array();
    foreach(array("uuid", "target") as $field) {
        $command = "api uuid_getvar ".$uuid." rest_api_consult_".$field;
        $value = fs_api_value($command);
        if(is_array($value)) {
            // the channel went away since it was listed
            if(strpos($value["details"] ?? "", "-ERR No such channel") === 0) {
                return null;
            }
            error_log("rest_api: ".$command." failed: ".json_encode($value));
            return array("error" => "event socket error", "code" => 500);
        }
        // uuid_getvar answers "_undef_" for a variable that isn't set
        $noted[$field] = strtolower($value);
        if($field === "uuid" && (!is_uuid($noted["uuid"]) || !isset($live[$noted["uuid"]]))) {
            return null;
        }
    }
    return is_dial_number($noted["target"]) ? $noted["target"] : null;
}

// a leg read again after a call action, for the response: as a Call, with
// state "ended" when it is already gone, or the 500 when the event socket
// fails. the domain was checked before the action, so it isn't checked here
function rest_api_call_after($call_uuid, $domain_uuid) {
    $dump = fs_api_json("api uuid_dump ".$call_uuid." json");
    if(isset($dump["error"])) {
        if(strpos($dump["details"] ?? "", "-ERR No such channel") === 0) {
            return array("call_uuid" => $call_uuid, "domain_uuid" => $domain_uuid, "state" => "ended", "caller_id_number" => null, "destination_number" => null, "consulting" => null);
        }
        error_log("rest_api: reading ".$call_uuid." after a call action failed: ".json_encode($dump));
        return array("error" => "event socket error", "code" => 500);
    }
    $dump["Unique-ID"] = $call_uuid;
    return rest_api_format_call($dump, $domain_uuid);
}
