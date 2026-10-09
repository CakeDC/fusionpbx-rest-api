<?php
$required_params = array();
$required_permissions = array("call_active_view");

// the active calls of a domain, from FreeSWITCH's "show channels", one item
// per call (the legs "show calls" pairs), optionally those of some extensions.
// a channel's domain is decided as FusionPBX's active calls page does: its
// context (the part after "@" if any) unless that is "public" or "default",
// else the domain of its presence_id.
// call actions act on the leg they get, so a call is listed by the leg of the
// extensions asked for (the one whose presence is theirs), else by its first
// leg
function do_action($body) {
    $extensions = null;
    if(isset($body->extension)) {
        $extensions = rest_api_parse_list($body->extension);
        if($extensions === false) {
            return array("error" => "invalid extension", "code" => 400);
        }
        foreach($extensions as $extension) {
            if(!is_dial_number($extension)) {
                return array("error" => "invalid extension", "code" => 400);
            }
        }
    }

    $database = new database;
    // FusionPBX's select() returns false on a database error
    $domain_name = $database->select("SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid", array("domain_uuid" => $body->domain_uuid), 'column');
    if($domain_name === false) {
        return array("error" => "database error", "code" => 500);
    }

    $replies = array();
    foreach(array("channels", "calls") as $list) {
        $command = "api show ".$list." as json";
        $replies[$list] = fs_api_json($command);
        if(isset($replies[$list]["error"])) {
            error_log("rest_api: ".$command." failed: ".json_encode($replies[$list]));
            return array("error" => "event socket error", "code" => 500);
        }
    }

    // "show channels" can't tell an answered call from a bridged one: the
    // legs "show calls" pairs are bridged, and are one call, known by its
    // first leg. a ring group pairs its first leg with every leg it rings
    $bridged = array();
    $call_of = array();
    foreach($replies["calls"]["rows"] ?? array() as $call) {
        if(!empty($call["uuid"]) && !empty($call["b_uuid"])) {
            $bridged[$call["uuid"]] = true;
            $bridged[$call["b_uuid"]] = true;
            $call_of[$call["b_uuid"]] = $call["uuid"];
        }
    }

    // every live channel, of any domain: a consult leg may not carry one
    $live = array();
    foreach($replies["channels"]["rows"] ?? array() as $channel) {
        $live[strtolower((string)($channel["uuid"] ?? ""))] = true;
    }

    // the channels of the domain by call, in the order they are listed:
    // the leg each call is listed by, and how well it fits (2: the
    // extension's presence, 1: the extension called or was called, 0: the
    // call's first leg without extensions)
    $calls = array();
    foreach($replies["channels"]["rows"] ?? array() as $channel) {
        $context = (string)($channel["context"] ?? "");
        $presence_id = (string)($channel["presence_id"] ?? "");
        if($context !== "" && $context !== "public" && $context !== "default") {
            $channel_domain = strpos($context, "@") !== false ? explode("@", $context, 2)[1] : $context;
        } else {
            $channel_domain = strpos($presence_id, "@") !== false ? explode("@", $presence_id, 2)[1] : null;
        }
        if($channel_domain !== $domain_name) {
            continue;
        }
        $uuid = $channel["uuid"];
        $key = $call_of[$uuid] ?? $uuid;
        if($extensions === null) {
            $fit = $key === $uuid ? 1 : 0;
        } elseif(in_array(explode("@", $presence_id, 2)[0], $extensions, true)) {
            $fit = 2;
        } elseif(in_array((string)($channel["cid_num"] ?? ""), $extensions, true) || in_array((string)($channel["dest"] ?? ""), $extensions, true)) {
            $fit = 1;
        } else {
            continue;
        }
        if(!isset($calls[$key]) || $fit > $calls[$key]["fit"]) {
            $calls[$key] = array("channel" => $channel, "fit" => $fit);
        }
    }

    $data = array();
    foreach($calls as $call) {
        $channel = $call["channel"];
        $state = rest_api_call_state($channel["callstate"] ?? "", isset($bridged[$channel["uuid"]]));
        // only an answered call can consult; it is held while it does
        $consulting = null;
        if($state === "answered" || $state === "bridged") {
            $consulting = rest_api_listed_consulting($channel["uuid"], $live);
            if(is_array($consulting)) {
                return $consulting;
            }
            if($consulting !== null) {
                $state = "held";
            }
        }
        $data[] = array(
            "call_uuid" => $channel["uuid"],
            "domain_uuid" => $body->domain_uuid,
            "state" => $state,
            "caller_id_number" => ($channel["cid_num"] ?? "") !== "" ? $channel["cid_num"] : null,
            "destination_number" => ($channel["dest"] ?? "") !== "" ? $channel["dest"] : null,
            "consulting" => $consulting,
        );
    }
    return array("data" => $data);
}
