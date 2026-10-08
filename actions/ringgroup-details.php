<?php
$required_params = array("ring_group_uuid");
$required_permissions = array("ring_group_view", "ring_group_destination_view");

// ZuluCall's getRingGroup (#43980): one ring group of the domain, disabled or
// not, as ringgroup-list returns it (rest_api_format_ring_groups())
function do_action($body) {
    if(!is_uuid($body->ring_group_uuid)) {
        return array("error" => "invalid ring_group_uuid", "code" => 400);
    }

    $database = new database;
    $sql = "SELECT ring_group_uuid, domain_uuid, ring_group_name, ring_group_extension, ring_group_strategy FROM v_ring_groups";
    $sql .= " WHERE ring_group_uuid = :ring_group_uuid AND domain_uuid = :domain_uuid";
    $parameters = array("ring_group_uuid" => $body->ring_group_uuid, "domain_uuid" => $body->domain_uuid);
    // FusionPBX's select() returns false on a database error
    $rows = $database->select($sql, $parameters, 'all');
    if(!is_array($rows)) {
        return array("error" => "database error", "code" => 500);
    }
    if(!$rows) {
        return array("error" => "ring group not found", "code" => 404);
    }
    $ring_groups = rest_api_format_ring_groups($database, $rows);
    if($ring_groups === false) {
        return array("error" => "database error", "code" => 500);
    }
    return $ring_groups[0];
}
