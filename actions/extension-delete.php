<?php
$required_params = array("extension_uuid");
// FusionPBX's extension class grants itself the child tables' delete
// permissions for the moment; the plugin never grants permissions (#43940),
// and delete() silently skips a table without one, so all are required
$required_permissions = array(
    "extension_delete", "extension_user_delete", "follow_me_delete", "follow_me_destination_delete",
    "ring_group_destination_delete", "extension_setting_delete", "voicemail_delete",
    "voicemail_option_delete", "voicemail_message_delete", "voicemail_destination_delete",
    "voicemail_greeting_delete",
);

// ZuluCall's deleteExtension (#43973). deletes what 5.6.5's "delete extension
// and voicemail" deletes (app/extensions/resources/classes/extension.php and
// voicemail::voicemail_delete()); those classes check the browser's CSRF
// token, so they can't be called from here
function do_action($body) {
    if(!is_uuid($body->extension_uuid)) {
        return array("error" => "invalid extension_uuid", "code" => 400);
    }

    $database = new database;
    $domain_uuid = $body->domain_uuid;
    // FusionPBX's select() returns false on a database error
    $sql = "SELECT extension, number_alias, user_context, follow_me_uuid FROM v_extensions WHERE extension_uuid = :extension_uuid AND domain_uuid = :domain_uuid";
    $extensions = $database->select($sql, array("extension_uuid" => $body->extension_uuid, "domain_uuid" => $domain_uuid), 'all');
    if(!is_array($extensions)) {
        return array("error" => "database error", "code" => 500);
    }
    if(!$extensions) {
        return array("error" => "extension not found", "code" => 404);
    }
    $extension = $extensions[0];
    $domain_name = $database->select("SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid", array("domain_uuid" => $domain_uuid), 'column');
    if(!$domain_name) {
        return array("error" => "database error", "code" => 500);
    }

    $array["extensions"][] = array("extension_uuid" => $body->extension_uuid, "domain_uuid" => $domain_uuid);
    $array["extension_users"][] = array("extension_uuid" => $body->extension_uuid);
    if(is_uuid($extension["follow_me_uuid"])) {
        $array["follow_me"][] = array("follow_me_uuid" => $extension["follow_me_uuid"]);
        $array["follow_me_destinations"][] = array("follow_me_uuid" => $extension["follow_me_uuid"]);
    }
    $array["extension_settings"][] = array("extension_uuid" => $body->extension_uuid, "domain_uuid" => $domain_uuid);

    // ring groups stop dialing the number and its alias. like FusionPBX, only
    // numeric numbers are voicemail ids, so nothing else reaches a file path
    $voicemail_ids = array();
    foreach(array($extension["extension"], $extension["number_alias"]) as $number) {
        if($number === null || $number === "") {
            continue;
        }
        $array["ring_group_destinations"][] = array("destination_number" => $number, "domain_uuid" => $domain_uuid);
        if(ctype_digit((string)$number)) {
            $voicemail_ids[] = (string)$number;
        }
    }

    $voicemails = array();
    if($voicemail_ids) {
        $parameters = array("domain_uuid" => $domain_uuid);
        $placeholders = array();
        foreach($voicemail_ids as $i => $voicemail_id) {
            $placeholders[] = ":voicemail_id_".$i;
            $parameters["voicemail_id_".$i] = $voicemail_id;
        }
        $sql = "SELECT voicemail_uuid, voicemail_id FROM v_voicemails WHERE domain_uuid = :domain_uuid AND voicemail_id IN (".implode(", ", $placeholders).")";
        $voicemails = $database->select($sql, $parameters, 'all');
        if(!is_array($voicemails)) {
            return array("error" => "database error", "code" => 500);
        }
    }
    foreach($voicemails as $voicemail) {
        $box = array("voicemail_uuid" => $voicemail["voicemail_uuid"], "domain_uuid" => $domain_uuid);
        $array["voicemails"][] = $box;
        $array["voicemail_options"][] = $box;
        $array["voicemail_messages"][] = $box;
        $array["voicemail_destinations"][] = $box;
        // boxes that copy their messages to this one
        $array["voicemail_destinations"][] = array("voicemail_uuid_copy" => $voicemail["voicemail_uuid"], "domain_uuid" => $domain_uuid);
        $array["voicemail_greetings"][] = array("voicemail_id" => $voicemail["voicemail_id"], "domain_uuid" => $domain_uuid);
    }

    $database->app_name = 'rest_api';
    $database->app_uuid = '2bfe71d9-e112-4b8b-bcff-75aeb0e06302';
    if(!$database->delete($array)) {
        return array("error" => "error deleting extension", "code" => 500);
    }

    // the messages and greetings of each box, as voicemail_delete() removes them
    $voicemail_dir = (new settings(array("database" => $database, "domain_uuid" => $domain_uuid)))->get("switch", "voicemail");
    if(!empty($voicemail_dir) && basename($domain_name) === $domain_name) {
        foreach($voicemails as $voicemail) {
            if(!ctype_digit((string)$voicemail["voicemail_id"])) {
                continue;
            }
            $path = $voicemail_dir."/default/".$domain_name."/".$voicemail["voicemail_id"];
            foreach(glob($path."/*.*") ?: array() as $file) {
                @unlink($file);
            }
            @rmdir($path);
        }
    }

    // FusionPBX serves registrations from a cached directory entry
    $cache = new cache;
    $cache->delete("directory:".$extension["extension"]."@".$extension["user_context"]);
    if(!empty($extension["number_alias"])) {
        $cache->delete("directory:".$extension["number_alias"]."@".$extension["user_context"]);
    }

    return array("code" => 204);
}
