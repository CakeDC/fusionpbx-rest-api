<?php
$required_params = array("extension_uuid");
$required_permissions = array("extension_edit");

// Fields that are left out stay as they are. Like FusionPBX's extension_edit.php,
// each field needs the permission of the columns it writes; save() would silently
// skip them, so a missing one refuses the whole update instead
function do_action($body) {
    $caller_id_columns = array(
        "caller_id_name" => array("effective_caller_id_name", "outbound_caller_id_name", "emergency_caller_id_name"),
        "caller_id_number" => array("effective_caller_id_number", "outbound_caller_id_number", "emergency_caller_id_number"),
    );
    // user_uuid null removes the links, so it counts as given
    $link_user = property_exists($body, "user_uuid");

    $needed = array();
    foreach($caller_id_columns as $field => $columns) {
        if(isset($body->$field)) {
            $needed = array_merge($needed, $columns);
        }
    }
    if(isset($body->enabled)) {
        $needed[] = "extension_enabled";
    }
    if($link_user) {
        $needed[] = $body->user_uuid === null ? "extension_user_delete" : "extension_user_add";
    }
    if(!$needed) {
        return array("error" => "nothing to update", "code" => 400);
    }
    $missing = array_values(array_filter($needed, function($permission) {
        return !permission_exists($permission);
    }));
    if($missing) {
        return array("error" => "forbidden", "missing_permissions" => $missing, "code" => 403);
    }

    if(!is_uuid($body->extension_uuid)) {
        return array("error" => "invalid extension_uuid", "code" => 400);
    }
    if(isset($body->caller_id_name) && !rest_api_is_caller_id_name($body->caller_id_name)) {
        return array("error" => "invalid caller_id_name", "code" => 400);
    }
    // an empty number clears it, as extension-create leaves it
    if(isset($body->caller_id_number) && $body->caller_id_number !== "" && !is_dial_number($body->caller_id_number)) {
        return array("error" => "invalid caller_id_number", "code" => 400);
    }
    $enabled = isset($body->enabled) ? rest_api_parse_bool($body->enabled) : null;
    if(isset($body->enabled) && $enabled === null) {
        return array("error" => "invalid enabled", "code" => 400);
    }
    if($link_user && $body->user_uuid !== null && !is_uuid($body->user_uuid)) {
        return array("error" => "invalid user_uuid", "code" => 400);
    }

    $database = new database;
    $parameters = array("extension_uuid" => $body->extension_uuid, "domain_uuid" => $body->domain_uuid);
    // FusionPBX's select() returns false on a database error. save() updates
    // by extension_uuid alone, so the domain is checked here
    $sql = "SELECT extension, number_alias, user_context FROM v_extensions WHERE extension_uuid = :extension_uuid AND domain_uuid = :domain_uuid";
    $extensions = $database->select($sql, $parameters, 'all');
    if(!is_array($extensions)) {
        return array("error" => "database error", "code" => 500);
    }
    if(!$extensions) {
        return array("error" => "extension not found", "code" => 404);
    }
    $extension = $extensions[0];

    $array = array();
    $row = array();
    foreach($caller_id_columns as $field => $columns) {
        if(isset($body->$field)) {
            foreach($columns as $column) {
                $row[$column] = (string)$body->$field;
            }
        }
    }
    if($enabled !== null) {
        $row["enabled"] = $enabled ? "true" : "false";
    }
    if($row) {
        $array["extensions"][] = array("extension_uuid" => $body->extension_uuid) + $row;
    }

    if($link_user && $body->user_uuid !== null) {
        $sql = "SELECT user_uuid FROM v_users WHERE user_uuid = :user_uuid AND domain_uuid = :domain_uuid";
        $users = $database->select($sql, array("user_uuid" => $body->user_uuid, "domain_uuid" => $body->domain_uuid), 'all');
        if(!is_array($users)) {
            return array("error" => "database error", "code" => 500);
        }
        if(!$users) {
            return array("error" => "user not found", "code" => 404);
        }
        // like extension_edit.php, the users already linked stay linked
        $sql = "SELECT extension_user_uuid FROM v_extension_users WHERE extension_uuid = :extension_uuid AND user_uuid = :user_uuid";
        $links = $database->select($sql, array("extension_uuid" => $body->extension_uuid, "user_uuid" => $body->user_uuid), 'all');
        if(!is_array($links)) {
            return array("error" => "database error", "code" => 500);
        }
        if(!$links) {
            $array["extension_users"][] = array(
                "extension_user_uuid" => uuid(),
                "domain_uuid" => $body->domain_uuid,
                "user_uuid" => $body->user_uuid,
                "extension_uuid" => $body->extension_uuid,
            );
        }
    }

    $database->app_name = 'rest_api';
    $database->app_uuid = '2bfe71d9-e112-4b8b-bcff-75aeb0e06302';
    if($array && !$database->save($array)) {
        return array("error" => "error updating extension", "code" => 500);
    }
    if($link_user && $body->user_uuid === null) {
        $unlink = array("extension_users" => array(array("extension_uuid" => $body->extension_uuid)));
        if(!$database->delete($unlink)) {
            return array("error" => "error updating extension", "code" => 500);
        }
    }

    // FusionPBX serves registrations from a cached directory entry, cleared
    // the way extension_edit.php does
    $cache = new cache;
    $cache->delete("directory:".$extension["extension"]."@".$extension["user_context"]);
    if(!empty($extension["number_alias"])) {
        $cache->delete("directory:".$extension["number_alias"]."@".$extension["user_context"]);
    }

    $sql = "SELECT ".implode(", ", REST_API_EXTENSION_FIELDS)." FROM v_extensions WHERE extension_uuid = :extension_uuid AND domain_uuid = :domain_uuid";
    $updated = $database->select($sql, $parameters, 'all');
    if(!is_array($updated) || !$updated) {
        return array("error" => "database error", "code" => 500);
    }
    return $updated[0];
}
