<?php
// call recordings. as in FusionPBX's Call Recordings app (view_call_recordings),
// a recording is a call leg with a recording file, other than the ring group
// legs that lost the race, and its id is the leg's xml_cdr_uuid. v_xml_cdr is
// read directly, as the view only exists when that app is installed

// the recording's leg (xml_cdr_uuid, domain_uuid, record_name, record_path,
// duration, start_stamp), or the response to answer: 400 for a malformed id,
// 404 when the domain has no such recording, 500 on a database error
function rest_api_recording($database, $domain_uuid, $recording_id) {
    if(!is_uuid($recording_id)) {
        return array("error" => "invalid recording_id", "code" => 400);
    }
    $sql = "SELECT xml_cdr_uuid, domain_uuid, record_name, record_path, duration, start_stamp FROM v_xml_cdr";
    $sql .= " WHERE xml_cdr_uuid = :recording_id AND domain_uuid = :domain_uuid";
    $sql .= " AND record_name IS NOT NULL AND record_name <> '' AND record_path IS NOT NULL AND record_path <> ''";
    $sql .= " AND hangup_cause <> 'LOSE_RACE'";
    // FusionPBX stores uuids in lower case. its select() returns false on a
    // database error
    $legs = $database->select($sql, array("recording_id" => strtolower($recording_id), "domain_uuid" => $domain_uuid), 'all');
    if(!is_array($legs)) {
        return array("error" => "database error", "code" => 500);
    }
    if(!$legs) {
        return array("error" => "recording not found", "code" => 404);
    }
    return $legs[0];
}
