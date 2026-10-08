<?php
$required_params = array("number");
// FusionPBX's destinations class grants itself the dialplan permissions for
// the moment; the plugin never grants permissions (#43940), and delete()
// silently skips a table without one, so they are required
$required_permissions = array("destination_delete", "dialplan_delete", "dialplan_detail_delete");

// ZuluCall's deleteDestination (#43978). deletes what 5.6.5's
// destinations::delete() deletes: the destination, its dialplan and the
// dialplan's details. that class checks the browser's CSRF token, so it can't
// be called from here
function do_action($body) {
    if(!(is_string($body->number) || is_int($body->number)) || !preg_match('/^\+?[0-9]+$/D', (string)$body->number)) {
        return array("error" => "invalid number", "code" => 400);
    }

    $database = new database;
    // FusionPBX's select() returns false on a database error
    $sql = "SELECT destination_uuid, dialplan_uuid, destination_context FROM v_destinations";
    $sql .= " WHERE domain_uuid = :domain_uuid AND destination_number = :number AND destination_type = 'inbound'";
    $destinations = $database->select($sql, array("domain_uuid" => $body->domain_uuid, "number" => (string)$body->number), 'all');
    if(!is_array($destinations)) {
        return array("error" => "database error", "code" => 500);
    }
    if(!$destinations) {
        return array("error" => "destination not found", "code" => 404);
    }
    $destination = $destinations[0];

    $array["destinations"][] = array("destination_uuid" => $destination["destination_uuid"], "domain_uuid" => $body->domain_uuid);
    if(is_uuid($destination["dialplan_uuid"])) {
        $array["dialplan_details"][] = array("dialplan_uuid" => $destination["dialplan_uuid"]);
        $array["dialplans"][] = array("dialplan_uuid" => $destination["dialplan_uuid"], "domain_uuid" => $body->domain_uuid);
    }

    $database->app_name = 'rest_api';
    $database->app_uuid = '2bfe71d9-e112-4b8b-bcff-75aeb0e06302';
    if(!$database->delete($array)) {
        return array("error" => "error deleting destination", "code" => 500);
    }

    // as destinations::delete() does
    $cache = new cache;
    $cache->delete("dialplan:".$destination["destination_context"]);

    return array("code" => 204);
}
