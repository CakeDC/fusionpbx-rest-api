<?php
$required_params = array("recording_id");
$required_permissions = array("call_recording_download");

// the audio of a call recording (see lib/recordings.php), read from the leg's
// record_path/record_name as FusionPBX's Call Recordings app does. rest.php
// streams the file named in "send_file", so a large one isn't held in memory
function do_action($body) {
    $leg = rest_api_recording(new database, $body->domain_uuid, $body->recording_id);
    if(isset($leg["error"])) {
        return $leg;
    }

    // record_path and record_name come from the call's data: only a file inside
    // FusionPBX's recordings directory, links resolved, is ever read
    $settings = new settings(array("database" => database::new(), "domain_uuid" => $body->domain_uuid));
    $root = $settings->get("switch", "recordings", "/var/lib/freeswitch/recordings");
    $file = realpath($leg["record_path"]."/".$leg["record_name"]);
    if($file === false || !is_file($file)) {
        return array("error" => "recording not found", "code" => 404);
    }
    $real_root = realpath($root);
    if($real_root === false || strpos($file, rtrim($real_root, "/")."/") !== 0) {
        error_log("rest_api: recording ".$leg["xml_cdr_uuid"]." is at ".$file.", outside the recordings directory ".$root);
        return array("error" => "recording not found", "code" => 404);
    }
    if(!is_readable($file)) {
        return array("error" => "recording file can not be read", "code" => 500);
    }

    $types = array("wav" => "audio/wav", "mp3" => "audio/mpeg");
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    return array("send_file" => array(
        "path" => $file,
        "content_type" => $types[$extension] ?? "application/octet-stream",
        "name" => basename($leg["record_name"]),
    ));
}
