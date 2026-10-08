<?php
$required_params = array("user_uuid");
$required_permissions = array("user_view");

// A stored domain_uuid + user_uuid pair still resolves to a FusionPBX user.
// a disabled user is returned too, with user_enabled false
function do_action($body) {
    if(!is_uuid($body->user_uuid)) {
        return array("error" => "invalid user_uuid", "code" => 400);
    }
    // FusionPBX stores uuids in lower case; lower-cased like domain_uuid so
    // text columns (sqlite, mysql) match an upper-case uuid too
    $body->user_uuid = strtolower($body->user_uuid);

    $sql = "SELECT ".implode(", ", REST_API_USER_FIELDS)." FROM v_users WHERE user_uuid = :user_uuid AND domain_uuid = :domain_uuid";
    $parameters['user_uuid'] = $body->user_uuid;
    $parameters['domain_uuid'] = $body->domain_uuid;
    $database = new database;
    // select() returns false on a database error, and 'row' would also give
    // false for a missing user: a deleted user must not be reported by mistake
    $users = $database->select($sql, $parameters, 'all');
    if(!is_array($users)) {
        return array("error" => "database error", "code" => 500);
    }
    if(!$users) {
        return array("error" => "user not found", "code" => 404);
    }
    return rest_api_format_user($users[0]);
}
