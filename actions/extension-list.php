<?php
$required_params = array();
$required_permissions = array("extension_view");

// The domain's extensions as the same Extension objects
// extension-user-list returns, by number (as text)
function do_action($body) {
    $pagination = rest_api_parse_pagination($body);
    if(isset($pagination["error"])) {
        return $pagination;
    }
    list($page, $per_page) = $pagination;

    $database = new database;
    $parameters['domain_uuid'] = $body->domain_uuid;
    // FusionPBX's select() returns false on a database error
    $total = $database->select("SELECT COUNT(*) FROM v_extensions WHERE domain_uuid = :domain_uuid", $parameters, 'column');
    if($total === false) {
        return array("error" => "database error", "code" => 500);
    }
    $pagination = array("page" => $page, "per_page" => $per_page, "total" => (int)$total);
    // past the last page: nothing to sort and skip
    $offset = ($page - 1) * $per_page;
    if($offset >= $pagination["total"]) {
        return array("data" => array(), "pagination" => $pagination);
    }

    $sql = "SELECT ".implode(", ", REST_API_USER_EXTENSION_FIELDS)." FROM v_extensions WHERE domain_uuid = :domain_uuid";
    $sql .= " ORDER BY extension, extension_uuid LIMIT ".$per_page." OFFSET ".$offset;
    $extensions = $database->select($sql, $parameters, 'all');
    if(!is_array($extensions)) {
        return array("error" => "database error", "code" => 500);
    }

    // an extension can be linked to several users (v_extension_users) and
    // the response has room for one: the lowest user_uuid, so it is stable
    $users = array();
    if($extensions) {
        $placeholders = array();
        $link_parameters = array("domain_uuid" => $body->domain_uuid);
        foreach($extensions as $i => $extension) {
            $placeholders[] = ":extension_uuid_".$i;
            $link_parameters["extension_uuid_".$i] = $extension["extension_uuid"];
        }
        $sql = "SELECT extension_uuid, user_uuid FROM v_extension_users WHERE domain_uuid = :domain_uuid";
        $sql .= " AND extension_uuid IN (".implode(", ", $placeholders).") ORDER BY user_uuid";
        $links = $database->select($sql, $link_parameters, 'all');
        if(!is_array($links)) {
            return array("error" => "database error", "code" => 500);
        }
        foreach($links as $link) {
            if(!isset($users[$link["extension_uuid"]])) {
                $users[$link["extension_uuid"]] = $link["user_uuid"];
            }
        }
    }

    $data = array();
    foreach($extensions as $extension) {
        $extension["user_uuid"] = $users[$extension["extension_uuid"]] ?? null;
        $data[] = rest_api_format_extension($extension);
    }
    return array("data" => $data, "pagination" => $pagination);
}
