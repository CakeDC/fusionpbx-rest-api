<?php
$required_params = array("number");
$required_permissions = array("destination_edit", "dialplan_edit", "dialplan_detail_add", "dialplan_detail_delete");

// ZuluCall's updateDestination (#43977). FusionPBX's destination_edit.php
// rebuilds the whole dialplan from every destination setting (recording, fax
// detection, conditions...); this action only replaces what the contract's
// fields cover: the actions (a transfer to the new target) and the enabled
// flag, in v_destinations, the dialplan XML and its action details
function do_action($body) {
    if(!(is_string($body->number) || is_int($body->number)) || !preg_match('/^\+?[0-9]+$/D', (string)$body->number)) {
        return array("error" => "invalid number", "code" => 400);
    }
    $types = array("extension", "ring_group", "ivr", "voicemail");
    $retarget = isset($body->destination_type) || isset($body->target);
    if($retarget) {
        if(!isset($body->destination_type) || !in_array($body->destination_type, $types, true)) {
            return array("error" => "invalid destination_type", "code" => 400);
        }
        // the target ends up in a transfer action of the dialplan
        $target = isset($body->target) ? $body->target : null;
        $valid = $body->destination_type === "extension" ? is_dial_number($target)
            : ($body->destination_type === "voicemail" ? (is_string($target) || is_int($target)) && ctype_digit((string)$target) : is_uuid($target));
        if(!$valid) {
            return array("error" => "invalid target", "code" => 400);
        }
        $target = (string)$target;
    }
    $enabled = isset($body->enabled) ? rest_api_parse_bool($body->enabled) : null;
    if(isset($body->enabled) && $enabled === null) {
        return array("error" => "invalid enabled", "code" => 400);
    }
    if(!$retarget && $enabled === null) {
        return array("error" => "nothing to update", "code" => 400);
    }

    $database = new database;
    $domain_uuid = $body->domain_uuid;
    // FusionPBX's select() returns false on a database error
    $sql = "SELECT destination_uuid, dialplan_uuid, destination_number, destination_prefix, destination_context, destination_actions";
    $sql .= " FROM v_destinations WHERE domain_uuid = :domain_uuid AND destination_number = :number AND destination_type = 'inbound'";
    $destinations = $database->select($sql, array("domain_uuid" => $domain_uuid, "number" => (string)$body->number), 'all');
    if(!is_array($destinations)) {
        return array("error" => "database error", "code" => 500);
    }
    if(!$destinations) {
        return array("error" => "destination not found", "code" => 404);
    }
    $destination = $destinations[0];

    $sql = "SELECT dialplan_xml FROM v_dialplans WHERE dialplan_uuid = :dialplan_uuid AND domain_uuid = :domain_uuid";
    $dialplans = $database->select($sql, array("dialplan_uuid" => $destination["dialplan_uuid"], "domain_uuid" => $domain_uuid), 'all');
    if(!is_array($dialplans)) {
        return array("error" => "database error", "code" => 500);
    }

    $array = array();
    $row = array("destination_uuid" => $destination["destination_uuid"]);
    $dialplan = array("dialplan_uuid" => $destination["dialplan_uuid"]);
    $old_details = array();
    if($retarget) {
        $data = rest_api_destination_transfer_data($database, $domain_uuid, $body->destination_type, $target);
        if($data === false) {
            return array("error" => "database error", "code" => 500);
        }
        if($data === null) {
            return array("error" => "target not found", "code" => 404);
        }

        // every old action is a line of the dialplan: the first becomes the new
        // transfer, the others go. a dialplan edited by hand that no longer
        // holds them is refused rather than guessed at
        $old_actions = json_decode((string)$destination["destination_actions"], true);
        $xml = $dialplans ? (string)$dialplans[0]["dialplan_xml"] : "";
        if(!is_array($old_actions) || !$old_actions) {
            return array("error" => "the destination's dialplan does not match its actions", "code" => 409);
        }
        foreach($old_actions as $i => $old_action) {
            $line = rest_api_dialplan_action_xml($old_action["destination_app"] ?? "", $old_action["destination_data"] ?? "");
            if(strpos($xml, $line) === false) {
                return array("error" => "the destination's dialplan does not match its actions", "code" => 409);
            }
            if($i === 0) {
                $xml = substr_replace($xml, rest_api_dialplan_action_xml("transfer", $data), strpos($xml, $line), strlen($line));
            } else {
                $xml = preg_replace('/[ \t]*'.preg_quote($line, '/').'\r?\n?/', '', $xml, 1);
            }
        }

        $actions = array(array("destination_app" => "transfer", "destination_data" => $data));
        $row += array("destination_actions" => json_encode($actions), "destination_app" => "transfer", "destination_data" => $data);
        $dialplan["dialplan_xml"] = $xml;

        // the action details (FusionPBX writes them when destinations.dialplan_details
        // is on): the old actions' rows give way to one at the first one's place
        $sql = "SELECT dialplan_detail_uuid, dialplan_detail_type, dialplan_detail_data, dialplan_detail_group, dialplan_detail_order FROM v_dialplan_details";
        $sql .= " WHERE dialplan_uuid = :dialplan_uuid AND domain_uuid = :domain_uuid AND dialplan_detail_tag = 'action' ORDER BY dialplan_detail_order";
        $details = $database->select($sql, array("dialplan_uuid" => $destination["dialplan_uuid"], "domain_uuid" => $domain_uuid), 'all');
        if(!is_array($details)) {
            return array("error" => "database error", "code" => 500);
        }
        foreach($details as $detail) {
            foreach($old_actions as $old_action) {
                if($detail["dialplan_detail_type"] === ($old_action["destination_app"] ?? null) && $detail["dialplan_detail_data"] === ($old_action["destination_data"] ?? null)) {
                    $old_details[] = $detail;
                    break;
                }
            }
        }
        if($old_details) {
            $array["dialplan_details"][] = array(
                "dialplan_detail_uuid" => uuid(),
                "domain_uuid" => $domain_uuid,
                "dialplan_uuid" => $destination["dialplan_uuid"],
                "dialplan_detail_tag" => "action",
                "dialplan_detail_type" => "transfer",
                "dialplan_detail_data" => $data,
                "dialplan_detail_group" => $old_details[0]["dialplan_detail_group"],
                "dialplan_detail_order" => $old_details[0]["dialplan_detail_order"],
                "dialplan_detail_enabled" => "true",
            );
        }
    }
    if($enabled !== null) {
        $row["destination_enabled"] = $enabled ? "true" : "false";
        $dialplan["dialplan_enabled"] = $enabled ? "true" : "false";
    }
    $array["destinations"][] = $row;
    if($dialplans) {
        $array["dialplans"][] = $dialplan;
    }

    $database->app_name = 'rest_api';
    $database->app_uuid = '2bfe71d9-e112-4b8b-bcff-75aeb0e06302';
    if(!$database->save($array)) {
        return array("error" => "error updating destination", "code" => 500);
    }
    if($old_details) {
        $delete = array();
        foreach($old_details as $detail) {
            $delete["dialplan_details"][] = array("dialplan_detail_uuid" => $detail["dialplan_detail_uuid"]);
        }
        if(!$database->delete($delete)) {
            return array("error" => "error updating destination", "code" => 500);
        }
    }

    // the cache keys destination_edit.php clears, by dialplan mode
    $context = $destination["destination_context"];
    $number = $destination["destination_number"];
    $prefix = $destination["destination_prefix"];
    $keys = array();
    $mode = (new settings(array("database" => $database, "domain_uuid" => $domain_uuid)))->get("destinations", "dialplan_mode", "");
    if($mode === "multiple") {
        $keys[] = "dialplan:".$context;
    }
    if($mode === "single") {
        if(is_numeric($prefix) && is_numeric($number)) {
            $keys[] = "dialplan:".$context.":".$prefix.$number;
            $keys[] = "dialplan:".$context.":+".$prefix.$number;
        }
        if(substr($number, 0, 1) === "+" && is_numeric(str_replace("+", "", $number))) {
            $keys[] = "dialplan:".$context.":".$number;
        }
        if(is_numeric($number)) {
            $keys[] = "dialplan:".$context.":".$number;
        }
    }
    $cache = new cache;
    foreach(array_unique($keys) as $key) {
        $cache->delete($key);
    }

    $sql = "SELECT domain_uuid, destination_number, destination_actions, destination_enabled FROM v_destinations WHERE destination_uuid = :destination_uuid";
    $rows = $database->select($sql, array("destination_uuid" => $destination["destination_uuid"]), 'all');
    $updated = is_array($rows) ? rest_api_format_destinations($database, $domain_uuid, $rows) : false;
    if(!$updated) {
        return array("error" => "database error", "code" => 500);
    }
    return $updated[0];
}
