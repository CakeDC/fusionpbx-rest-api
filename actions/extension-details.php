<?php
$required_params = array("extension_uuid");
$required_permissions = array("extension_view");

function do_action($body) {
    // PostgreSQL refuses a malformed uuid, which would answer 500
    if(!is_uuid($body->extension_uuid)) {
        return array("error" => "invalid extension_uuid", "code" => 400);
    }
    $sql = "SELECT v_extensions.".implode(", v_extensions.", REST_API_EXTENSION_FIELDS);
    $sql .= " FROM v_extensions WHERE domain_uuid = :domain_uuid AND extension_uuid = :extension_uuid";
    $parameters['domain_uuid'] = $body->domain_uuid;
    $parameters['extension_uuid'] = $body->extension_uuid;
    $database = new database;
    // FusionPBX's select() returns false on a database error, which must not
    // pass for a missing extension
    $extensions = $database->select($sql, $parameters, 'all');
    if(!is_array($extensions)) {
        return array("error" => "database error", "code" => 500);
    }
    if(!$extensions) {
        return array("error" => "extension not found", "code" => 404);
    }
    return rest_api_format_extension($extensions[0]);
}
