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

// a channel dump as the API returns a call
function rest_api_format_call($dump, $domain_uuid) {
    $caller = (string)($dump["Caller-Caller-ID-Number"] ?? "");
    $destination = (string)($dump["Caller-Destination-Number"] ?? "");
    return array(
        "call_uuid" => $dump["Unique-ID"],
        "domain_uuid" => $domain_uuid,
        "state" => rest_api_call_state($dump["Channel-Call-State"] ?? "", !empty($dump["Other-Leg-Unique-ID"])),
        "caller_id_number" => $caller !== "" ? $caller : null,
        "destination_number" => $destination !== "" ? $destination : null,
    );
}

// a leg read again after a call action, for the response: as a Call, with
// state "ended" when it is already gone, or the 500 when the event socket
// fails. the domain was checked before the action, so it isn't checked here
function rest_api_call_after($call_uuid, $domain_uuid) {
    $dump = fs_api_json("api uuid_dump ".$call_uuid." json");
    if(isset($dump["error"])) {
        if(strpos($dump["details"] ?? "", "-ERR No such channel") === 0) {
            return array("call_uuid" => $call_uuid, "domain_uuid" => $domain_uuid, "state" => "ended", "caller_id_number" => null, "destination_number" => null);
        }
        error_log("rest_api: reading ".$call_uuid." after a call action failed: ".json_encode($dump));
        return array("error" => "event socket error", "code" => 500);
    }
    $dump["Unique-ID"] = $call_uuid;
    return rest_api_format_call($dump, $domain_uuid);
}
