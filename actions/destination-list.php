<?php
$required_params = array();
$required_permissions = array("destination_view");

// ZuluCall's listDestinations (#43976): the domain's inbound numbers by
// number, with what each one routes to (rest_api_format_destinations())
function do_action($body) {
    $pagination = rest_api_parse_pagination($body);
    if(isset($pagination["error"])) {
        return $pagination;
    }
    list($page, $per_page) = $pagination;

    $database = new database;
    $parameters['domain_uuid'] = $body->domain_uuid;
    $where = " FROM v_destinations WHERE domain_uuid = :domain_uuid AND destination_type = 'inbound'";
    // FusionPBX's select() returns false on a database error
    $total = $database->select("SELECT COUNT(*)".$where, $parameters, 'column');
    if($total === false) {
        return array("error" => "database error", "code" => 500);
    }
    $pagination = array("page" => $page, "per_page" => $per_page, "total" => (int)$total);
    // past the last page: nothing to sort and skip
    $offset = ($page - 1) * $per_page;
    if($offset >= $pagination["total"]) {
        return array("data" => array(), "pagination" => $pagination);
    }

    $sql = "SELECT domain_uuid, destination_number, destination_actions, destination_enabled".$where;
    $sql .= " ORDER BY destination_number, destination_uuid LIMIT ".$per_page." OFFSET ".$offset;
    $rows = $database->select($sql, $parameters, 'all');
    if(!is_array($rows)) {
        return array("error" => "database error", "code" => 500);
    }
    $destinations = rest_api_format_destinations($database, $body->domain_uuid, $rows);
    if($destinations === false) {
        return array("error" => "database error", "code" => 500);
    }
    return array("data" => $destinations, "pagination" => $pagination);
}
