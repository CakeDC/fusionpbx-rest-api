<?php
$required_params = array();
$required_permissions = array("ring_group_view", "ring_group_destination_view");

// ZuluCall's listRingGroups (#43979): the domain's ring groups by extension,
// each with its destinations (rest_api_format_ring_groups())
function do_action($body) {
    $pagination = rest_api_parse_pagination($body);
    if(isset($pagination["error"])) {
        return $pagination;
    }
    list($page, $per_page) = $pagination;

    $database = new database;
    $parameters['domain_uuid'] = $body->domain_uuid;
    // FusionPBX's select() returns false on a database error
    $total = $database->select("SELECT COUNT(*) FROM v_ring_groups WHERE domain_uuid = :domain_uuid", $parameters, 'column');
    if($total === false) {
        return array("error" => "database error", "code" => 500);
    }
    $pagination = array("page" => $page, "per_page" => $per_page, "total" => (int)$total);
    // past the last page: nothing to sort and skip
    $offset = ($page - 1) * $per_page;
    if($offset >= $pagination["total"]) {
        return array("data" => array(), "pagination" => $pagination);
    }

    $sql = "SELECT ring_group_uuid, domain_uuid, ring_group_name, ring_group_extension, ring_group_strategy FROM v_ring_groups";
    $sql .= " WHERE domain_uuid = :domain_uuid ORDER BY ring_group_extension, ring_group_uuid LIMIT ".$per_page." OFFSET ".$offset;
    $rows = $database->select($sql, $parameters, 'all');
    $ring_groups = is_array($rows) ? rest_api_format_ring_groups($database, $rows) : false;
    if($ring_groups === false) {
        return array("error" => "database error", "code" => 500);
    }
    return array("data" => $ring_groups, "pagination" => $pagination);
}
