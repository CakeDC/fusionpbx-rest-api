<?php
$required_params = array();
$required_permissions = array("call_active_view");

// the active calls of a domain, from FreeSWITCH's "show channels", optionally
// those of one extension. a channel's domain is decided as FusionPBX's active
// calls page does: its context (the part after "@" if any) unless that is
// "public" or "default", else the domain of its presence_id
function do_action($body) {
    $extension = null;
    if(isset($body->extension)) {
        if(!is_dial_number($body->extension)) {
            return array("error" => "invalid extension", "code" => 400);
        }
        $extension = (string)$body->extension;
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
    // legs "show calls" pairs are bridged
    $bridged = array();
    foreach($replies["calls"]["rows"] ?? array() as $call) {
        if(!empty($call["uuid"]) && !empty($call["b_uuid"])) {
            $bridged[$call["uuid"]] = true;
            $bridged[$call["b_uuid"]] = true;
        }
    }

    $data = array();
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
        // the extension is a party: it called, was called, or is the presence
        // (e.g. a ring group ringing it)
        if($extension !== null && $extension !== ($channel["cid_num"] ?? null) && $extension !== ($channel["dest"] ?? null)
            && $extension !== explode("@", $presence_id, 2)[0]) {
            continue;
        }
        $state = rest_api_call_state($channel["callstate"] ?? "", isset($bridged[$channel["uuid"]]));
        $data[] = array(
            "call_uuid" => $channel["uuid"],
            "domain_uuid" => $body->domain_uuid,
            "state" => $state,
            "caller_id_number" => ($channel["cid_num"] ?? "") !== "" ? $channel["cid_num"] : null,
            "destination_number" => ($channel["dest"] ?? "") !== "" ? $channel["dest"] : null,
        );
    }
    return array("data" => $data);
}
