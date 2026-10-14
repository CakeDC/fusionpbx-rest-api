<?php
$required_params = array("ring_group_uuid");
// FusionPBX's ring_groups class grants itself the other tables' delete
// permissions for the moment; the plugin never grants permissions, and
// delete() silently skips a table without one, so all are required
$required_permissions = array("ring_group_delete", "ring_group_user_delete", "ring_group_destination_delete", "dialplan_delete", "dialplan_detail_delete");

// deletes what 5.6.5's ring_groups::delete() deletes: the ring group, its
// users, its destinations, its dialplan and the dialplan's details. that class
// checks the browser's CSRF token, so it can't be called from here
function do_action($body) {
    if(!is_uuid($body->ring_group_uuid)) {
        return array("error" => "invalid ring_group_uuid", "code" => 400);
    }
    // FusionPBX stores uuids in lower case
    $ring_group_uuid = strtolower($body->ring_group_uuid);

    $database = new database;
    // FusionPBX's select() returns false on a database error
    $sql = "SELECT dialplan_uuid, ring_group_context FROM v_ring_groups WHERE ring_group_uuid = :ring_group_uuid AND domain_uuid = :domain_uuid";
    $ring_groups = $database->select($sql, array("ring_group_uuid" => $ring_group_uuid, "domain_uuid" => $body->domain_uuid), 'all');
    if(!is_array($ring_groups)) {
        return array("error" => "database error", "code" => 500);
    }
    if(!$ring_groups) {
        return array("error" => "ring group not found", "code" => 404);
    }
    $ring_group = $ring_groups[0];

    $row = array("ring_group_uuid" => $ring_group_uuid, "domain_uuid" => $body->domain_uuid);
    $array = array(
        "ring_groups" => array($row),
        "ring_group_users" => array($row),
        "ring_group_destinations" => array($row),
    );
    if(is_uuid($ring_group["dialplan_uuid"])) {
        $array["dialplans"][] = array("dialplan_uuid" => $ring_group["dialplan_uuid"]);
        $array["dialplan_details"][] = array("dialplan_uuid" => $ring_group["dialplan_uuid"]);
    }

    $database->app_name = 'rest_api';
    $database->app_uuid = '2bfe71d9-e112-4b8b-bcff-75aeb0e06302';
    if(!$database->delete($array)) {
        return array("error" => "error deleting ring group", "code" => 500);
    }

    // as ring_groups::delete() does
    $cache = new cache;
    $cache->delete("dialplan:".$ring_group["ring_group_context"]);

    return array("code" => 204);
}
