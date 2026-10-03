<?php
$required_params = array("extension_uuid");
$required_permissions = array("extension_view");

function do_action($body) {
    $sql = "SELECT v_extensions.".implode(", v_extensions.", REST_API_EXTENSION_FIELDS);
    $sql .= " FROM v_extensions WHERE domain_uuid = :domain_uuid AND extension_uuid = :extension_uuid";
    $parameters['domain_uuid'] = $body->domain_uuid;
    $parameters['extension_uuid'] = $body->extension_uuid;
    $database = new database;
    $extension = $database->select($sql, $parameters, 'row');
    if(!$extension) {
        return array("error" => "extension not found", "code" => 404);
    }
    return $extension;
}
