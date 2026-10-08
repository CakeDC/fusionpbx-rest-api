<?php
$required_params = array();
$required_permissions = array("domain_view");

// ZuluCall's listDomains (#43969). every domain only for users with
// domain_select, the same rule rest.php applies to domain_uuid; others get
// their own domain
function do_action($body, $context = array()) {
    $pagination = rest_api_parse_pagination($body);
    if(isset($pagination["error"])) {
        return $pagination;
    }
    list($page, $per_page) = $pagination;

    $where = "";
    $parameters = array();
    if(empty($context['cross_domain'])) {
        $where = " WHERE domain_uuid = :domain_uuid";
        $parameters['domain_uuid'] = $context['user_domain_uuid'] ?? null;
    }

    $database = new database;
    // FusionPBX's select() returns false on a database error
    $total = $database->select("SELECT COUNT(*) FROM v_domains".$where, $parameters, 'column');
    if($total === false) {
        return array("error" => "database error", "code" => 500);
    }
    $pagination = array("page" => $page, "per_page" => $per_page, "total" => (int)$total);
    // past the last page: nothing to sort and skip
    $offset = ($page - 1) * $per_page;
    if($offset >= $pagination["total"]) {
        return array("data" => array(), "pagination" => $pagination);
    }

    $sql = "SELECT ".implode(", ", REST_API_DOMAIN_LIST_FIELDS)." FROM v_domains".$where;
    $sql .= " ORDER BY domain_name, domain_uuid LIMIT ".$per_page." OFFSET ".$offset;
    $domains = $database->select($sql, $parameters, 'all');
    if(!is_array($domains)) {
        return array("error" => "database error", "code" => 500);
    }

    return array(
        "data" => array_map("rest_api_format_domain", $domains),
        "pagination" => $pagination,
    );
}
