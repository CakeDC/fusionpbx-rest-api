<?php
$required_params = array("user_uuid");
$required_permissions = array("extension_view", "user_view");

// a user can be linked to several extensions (v_extension_users), so all of
// them are returned, by number. FusionPBX has no primary extension (#43936)
function do_action($body) {
    if(!is_uuid($body->user_uuid)) {
        return array("error" => "invalid user_uuid", "code" => 400);
    }

    $database = new database;
    $parameters['domain_uuid'] = $body->domain_uuid;
    $parameters['user_uuid'] = $body->user_uuid;

    // FusionPBX's select() returns false on a database error, which must not
    // pass for a missing user or a user without extensions
    $sql = "SELECT user_uuid FROM v_users WHERE user_uuid = :user_uuid AND domain_uuid = :domain_uuid";
    $users = $database->select($sql, $parameters, 'all');
    if(!is_array($users)) {
        return array("error" => "database error", "code" => 500);
    }
    if(!$users) {
        return array("error" => "user not found", "code" => 404);
    }

    // the join's domain check keeps out links to extensions of another domain.
    // v_extension_users has no unique (user, extension) constraint: DISTINCT
    // drops duplicate links
    $sql = "SELECT DISTINCT e.".implode(", e.", REST_API_USER_EXTENSION_FIELDS).", eu.user_uuid";
    $sql .= " FROM v_extension_users eu LEFT JOIN v_extensions e ON e.extension_uuid = eu.extension_uuid";
    $sql .= " WHERE eu.user_uuid = :user_uuid AND e.domain_uuid = :domain_uuid";
    $sql .= " ORDER BY e.extension";
    $extensions = $database->select($sql, $parameters, 'all');
    if(!is_array($extensions)) {
        return array("error" => "database error", "code" => 500);
    }
    // ZuluCall's listUserExtensions contract wraps the list in "data"
    return array("data" => array_map("rest_api_format_user_extension", $extensions));
}
