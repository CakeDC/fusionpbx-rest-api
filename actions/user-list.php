<?php
$required_params = array();
$required_permissions = array("user_view");

// ZuluCall's listUsers (#43970): the FusionPBX users of a domain, disabled
// ones included, to pick the user of an identity mapping (#43389)
function do_action($body) {
    $pagination = rest_api_parse_pagination($body);
    if(isset($pagination["error"])) {
        return $pagination;
    }
    list($page, $per_page) = $pagination;

    $database = new database;
    $parameters['domain_uuid'] = $body->domain_uuid;
    // FusionPBX's select() returns false on a database error
    $total = $database->select("SELECT COUNT(*) FROM v_users WHERE domain_uuid = :domain_uuid", $parameters, 'column');
    if($total === false) {
        return array("error" => "database error", "code" => 500);
    }
    $pagination = array("page" => $page, "per_page" => $per_page, "total" => (int)$total);
    // past the last page: nothing to sort and skip
    $offset = ($page - 1) * $per_page;
    if($offset >= $pagination["total"]) {
        return array("data" => array(), "pagination" => $pagination);
    }

    $sql = "SELECT ".implode(", ", REST_API_USER_FIELDS)." FROM v_users WHERE domain_uuid = :domain_uuid";
    $sql .= " ORDER BY username, user_uuid LIMIT ".$per_page." OFFSET ".$offset;
    $users = $database->select($sql, $parameters, 'all');
    if(!is_array($users)) {
        return array("error" => "database error", "code" => 500);
    }

    return array(
        "data" => array_map("rest_api_format_user", $users),
        "pagination" => $pagination,
    );
}
