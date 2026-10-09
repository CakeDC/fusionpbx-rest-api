<?php
$required_params = array("extension");
$required_permissions = array("extension_add", "voicemail_add");
// 201 with the extension, 409 when the number exists in the domain.
// user_uuid links the new extension to a user
function do_action($body) {
    $user_uuid = isset($body->user_uuid) ? $body->user_uuid : null;
    // save() would silently skip the link without extension_user_add
    if($user_uuid !== null && !permission_exists("extension_user_add")) {
        return array("error" => "forbidden", "missing_permissions" => array("extension_user_add"), "code" => 403);
    }

    // the number ends up in the directory, the dialplan and the voicemail id
    if(!is_dial_number($body->extension)) {
        return array("error" => "invalid extension", "code" => 400);
    }
    $caller_id_name = isset($body->caller_id_name) ? $body->caller_id_name : "";
    if(!rest_api_is_caller_id_name($caller_id_name)) {
        return array("error" => "invalid caller_id_name", "code" => 400);
    }
    $caller_id_number = isset($body->caller_id_number) ? $body->caller_id_number : "";
    if($caller_id_number !== "" && !is_dial_number($caller_id_number)) {
        return array("error" => "invalid caller_id_number", "code" => 400);
    }
    $caller_id_number = (string)$caller_id_number;
    if($user_uuid !== null && !is_uuid($user_uuid)) {
        return array("error" => "invalid user_uuid", "code" => 400);
    }
    $body->extension = (string)$body->extension;

    $database = new database;
    // FusionPBX's select() returns false on a database error, which must not
    // pass for a missing extension and create a duplicate
    $sql = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
    $domains = $database->select($sql, array('domain_uuid' => $body->domain_uuid), 'all');
    if(!is_array($domains)) {
        return array("error" => "database error", "code" => 500);
    }
    if(!$domains) {
        return array("error" => "domain not found", "code" => 404);
    }
    $domain_name = $domains[0]['domain_name'];

    $sql = "SELECT extension_uuid FROM v_extensions WHERE extension = :extension AND domain_uuid = :domain_uuid";
    $existing = $database->select($sql, array('extension' => $body->extension, 'domain_uuid' => $body->domain_uuid), 'all');
    if(!is_array($existing)) {
        return array("error" => "database error", "code" => 500);
    }
    if($existing) {
        return array("error" => "extension already exists", "code" => 409);
    }

    if($user_uuid !== null) {
        $sql = "SELECT user_uuid FROM v_users WHERE user_uuid = :user_uuid AND domain_uuid = :domain_uuid";
        $users = $database->select($sql, array('user_uuid' => $user_uuid, 'domain_uuid' => $body->domain_uuid), 'all');
        if(!is_array($users)) {
            return array("error" => "database error", "code" => 500);
        }
        if(!$users) {
            return array("error" => "user not found", "code" => 404);
        }
    }
    $extension_uuid = uuid();

    $array["extensions"][] = array(
        "domain_uuid" => $body->domain_uuid,
        "extension_uuid" => $extension_uuid,
        "extension" => $body->extension,
        "password" => generate_password(10, 4),
        "accountcode" => $domain_name,
        "effective_caller_id_name" => $caller_id_name,
        "effective_caller_id_number" => $caller_id_number,
        "outbound_caller_id_name" => $caller_id_name,
        "outbound_caller_id_number" => $caller_id_number,
        "emergency_caller_id_name" => $caller_id_name,
        "emergency_caller_id_number" => $caller_id_number,
        "directory_first_name" => "",
        "directory_last_name" => "",
        "directory_visible" => "true",
        "directory_exten_visible" => "true",
        "max_registrations" => "",
        "limit_max" => "5",
        "limit_destination" => "!USER_BUSY",
        "user_context" => $domain_name,
        "missed_call_app" => "",
        "missed_call_data" => "",
        "toll_allow" => "",
        "call_timeout" => "30",
        "call_group" => "",
        "call_screen_enabled" => "false",
        "user_record" => "",
        "hold_music" => "",
        "auth_acl" => "",
        "cidr" => "",
        "sip_force_contact" => "",
        "sip_force_expires" => "",
        "mwi_account" => "",
        "sip_bypass_media" => "",
        "absolute_codec_string" => "",
        "force_ping" => "",
        "dial_string" => "",
        "enabled" => "true",
        "description" => ""
    );
    $array["voicemails"][] = array(
        "domain_uuid" => $body->domain_uuid,
        "voicemail_uuid" => uuid(),
        "voicemail_id" => $body->extension,
        "voicemail_password" => generate_password(8, 1),
        "voicemail_mail_to" => "",
        "voicemail_file" => "attach",
        "voicemail_local_after_email" => "true",
        "voicemail_transcription_enabled" => "false",
        "voicemail_tutorial" => null,
        "voicemail_enabled" => "true",
        "voicemail_description" => ""
    );

    if($user_uuid !== null) {
        $array["extension_users"][] = array(
            "extension_user_uuid" => uuid(),
            "domain_uuid" => $body->domain_uuid,
            "user_uuid" => $user_uuid,
            "extension_uuid" => $extension_uuid,
        );
    }

    $database->app_name = 'rest_api';
    $database->app_uuid = '2bfe71d9-e112-4b8b-bcff-75aeb0e06302';
    if(!$database->save($array)) {
        return array("error" => "error adding extension", "code" => 500);
    }

    $sql = "SELECT ".implode(", ", REST_API_EXTENSION_FIELDS)." FROM v_extensions WHERE extension_uuid = :extension_uuid";
    $parameters['extension_uuid'] = $extension_uuid;
    $extensions = $database->select($sql, $parameters, 'all');
    if(!is_array($extensions) || !$extensions) {
        return array("error" => "database error", "code" => 500);
    }
    $extension = rest_api_format_extension($extensions[0]);
    // the SIP password lets an integration provision the phone. FusionPBX only
    // shows it to users with extension_password, so the API does the same
    if(permission_exists('extension_password')) {
        $sql = "SELECT password FROM v_extensions WHERE extension_uuid = :extension_uuid";
        $extension['password'] = $database->select($sql, $parameters, 'column');
    }
    $extension['code'] = 201;
    return $extension;
}
