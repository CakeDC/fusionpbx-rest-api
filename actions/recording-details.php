<?php
$required_params = array("recording_id");
$required_permissions = array("call_recording_view");

// a call recording's metadata. as in FusionPBX's Call Recordings app
// (view_call_recordings), a recording is a call leg with a recording file,
// other than the ring group legs that lost the race, and its id is the leg's
// xml_cdr_uuid. v_xml_cdr is read directly, as the view only exists when that
// app is installed
function do_action($body) {
    if(!is_uuid($body->recording_id)) {
        return array("error" => "invalid recording_id", "code" => 400);
    }
    // FusionPBX stores uuids in lower case
    $recording_id = strtolower($body->recording_id);

    $sql = "SELECT xml_cdr_uuid, domain_uuid, record_name, duration, start_stamp FROM v_xml_cdr";
    $sql .= " WHERE xml_cdr_uuid = :recording_id AND domain_uuid = :domain_uuid";
    $sql .= " AND record_name IS NOT NULL AND record_name <> '' AND record_path IS NOT NULL AND record_path <> ''";
    $sql .= " AND hangup_cause <> 'LOSE_RACE'";
    $database = new database;
    // FusionPBX's select() returns false on a database error
    $legs = $database->select($sql, array("recording_id" => $recording_id, "domain_uuid" => $body->domain_uuid), 'all');
    if(!is_array($legs)) {
        return array("error" => "database error", "code" => 500);
    }
    if(!$legs) {
        return array("error" => "recording not found", "code" => 404);
    }
    $leg = $legs[0];
    return array(
        "recording_id" => $leg["xml_cdr_uuid"],
        "domain_uuid" => $leg["domain_uuid"],
        "filename" => $leg["record_name"],
        "duration" => (int)$leg["duration"],
        "xml_cdr_uuid" => $leg["xml_cdr_uuid"],
        "created" => $leg["start_stamp"],
    );
}
