<?php
$required_params = array("ring_group_uuid");
$required_permissions = array("ring_group_edit");

// Fields that are left out stay as they are; the extension can't be changed.
// FusionPBX rings destinations by delay, then number, and the API's
// destinations are only numbers, so the list sets which numbers ring: those
// already in the ring group keep their delay, timeout and settings,
// new ones get ringgroup-create's defaults
function do_action($body) {
    $strategies = array("simultaneous", "sequence", "enterprise", "rollover", "random");
    $set_destinations = isset($body->destinations);

    // save() and delete() would silently skip what the user may not change
    $needed = array();
    if(isset($body->name)) {
        $needed[] = "dialplan_edit";
    }
    if($set_destinations) {
        $needed[] = "ring_group_destination_add";
        $needed[] = "ring_group_destination_delete";
    }
    $missing = array_values(array_filter($needed, function($permission) {
        return !permission_exists($permission);
    }));
    if($missing) {
        return array("error" => "forbidden", "missing_permissions" => $missing, "code" => 403);
    }

    if(!is_uuid($body->ring_group_uuid)) {
        return array("error" => "invalid ring_group_uuid", "code" => 400);
    }
    if(isset($body->name) && ($body->name === "" || !rest_api_is_caller_id_name($body->name))) {
        return array("error" => "invalid name", "code" => 400);
    }
    if(isset($body->strategy) && !in_array($body->strategy, $strategies, true)) {
        return array("error" => "invalid strategy", "code" => 400);
    }
    $numbers = array();
    if($set_destinations) {
        // numbers end up in the dial strings of FusionPBX's ring group script
        if(!is_array($body->destinations) || !$body->destinations) {
            return array("error" => "invalid destinations", "code" => 400);
        }
        foreach($body->destinations as $destination) {
            $number = is_object($destination) ? ($destination->number ?? null) : (is_array($destination) ? ($destination["number"] ?? null) : null);
            if(!is_dial_number($number)) {
                return array("error" => "invalid destinations", "code" => 400);
            }
            $numbers[] = (string)$number;
        }
        $numbers = array_values(array_unique($numbers));
    }
    if(!isset($body->name) && !isset($body->strategy) && !$set_destinations) {
        return array("error" => "nothing to update", "code" => 400);
    }

    $database = new database;
    $parameters = array("ring_group_uuid" => $body->ring_group_uuid, "domain_uuid" => $body->domain_uuid);
    // FusionPBX's select() returns false on a database error
    $sql = "SELECT ring_group_context, dialplan_uuid FROM v_ring_groups WHERE ring_group_uuid = :ring_group_uuid AND domain_uuid = :domain_uuid";
    $ring_groups = $database->select($sql, $parameters, 'all');
    if(!is_array($ring_groups)) {
        return array("error" => "database error", "code" => 500);
    }
    if(!$ring_groups) {
        return array("error" => "ring group not found", "code" => 404);
    }
    $ring_group = $ring_groups[0];

    $array = array();
    $row = array();
    if(isset($body->name)) {
        $row["ring_group_name"] = $body->name;
    }
    if(isset($body->strategy)) {
        $row["ring_group_strategy"] = $body->strategy;
    }
    if($row) {
        $array["ring_groups"][] = array("ring_group_uuid" => $body->ring_group_uuid) + $row;
    }

    // the name is also the dialplan's, in the XML ring_group_edit.php writes
    if(isset($body->name) && is_uuid($ring_group["dialplan_uuid"])) {
        $sql = "SELECT dialplan_xml FROM v_dialplans WHERE dialplan_uuid = :dialplan_uuid AND domain_uuid = :domain_uuid";
        $dialplans = $database->select($sql, array("dialplan_uuid" => $ring_group["dialplan_uuid"], "domain_uuid" => $body->domain_uuid), 'all');
        if(!is_array($dialplans)) {
            return array("error" => "database error", "code" => 500);
        }
        if($dialplans) {
            $name = rest_api_xml_attribute($body->name);
            $xml = preg_replace_callback('/<extension name="[^"]*"/', function() use ($name) {
                return '<extension name="'.$name.'"';
            }, (string)$dialplans[0]["dialplan_xml"], 1);
            $array["dialplans"][] = array("dialplan_uuid" => $ring_group["dialplan_uuid"], "dialplan_name" => $body->name, "dialplan_xml" => $xml);
        }
    }

    $removed = array();
    if($set_destinations) {
        $sql = "SELECT ring_group_destination_uuid, destination_number FROM v_ring_group_destinations WHERE ring_group_uuid = :ring_group_uuid";
        $current = $database->select($sql, array("ring_group_uuid" => $body->ring_group_uuid), 'all');
        if(!is_array($current)) {
            return array("error" => "database error", "code" => 500);
        }
        $kept = array();
        foreach($current as $destination) {
            if(in_array((string)$destination["destination_number"], $numbers, true)) {
                $kept[] = (string)$destination["destination_number"];
            } else {
                $removed[] = array("ring_group_destination_uuid" => $destination["ring_group_destination_uuid"]);
            }
        }
        foreach(array_diff($numbers, $kept) as $number) {
            $array["ring_group_destinations"][] = array(
                "ring_group_uuid" => $body->ring_group_uuid,
                "ring_group_destination_uuid" => uuid(),
                "destination_number" => $number,
                "destination_delay" => "0",
                "destination_timeout" => "30",
                "destination_prompt" => "",
                "destination_enabled" => "true",
                "domain_uuid" => $body->domain_uuid,
            );
        }
    }

    $database->app_name = 'rest_api';
    $database->app_uuid = '2bfe71d9-e112-4b8b-bcff-75aeb0e06302';
    // the old destinations go only once the new ones are saved
    if($array && !$database->save($array)) {
        return array("error" => "error updating ring group", "code" => 500);
    }
    if($removed && !$database->delete(array("ring_group_destinations" => $removed))) {
        return array("error" => "error updating ring group", "code" => 500);
    }

    // as ring_group_edit.php does
    $cache = new cache;
    $cache->delete("dialplan:".$ring_group["ring_group_context"]);

    $sql = "SELECT ring_group_uuid, domain_uuid, ring_group_name, ring_group_extension, ring_group_strategy FROM v_ring_groups";
    $sql .= " WHERE ring_group_uuid = :ring_group_uuid AND domain_uuid = :domain_uuid";
    $rows = $database->select($sql, $parameters, 'all');
    $updated = is_array($rows) && $rows ? rest_api_format_ring_groups($database, $rows) : false;
    if(!$updated) {
        return array("error" => "database error", "code" => 500);
    }
    return $updated[0];
}
