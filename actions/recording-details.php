<?php
$required_params = array("recording_id");
$required_permissions = array("call_recording_view");

// a call recording's metadata (see lib/recordings.php for what a recording is)
function do_action($body) {
    $leg = rest_api_recording(new database, $body->domain_uuid, $body->recording_id);
    if(isset($leg["error"])) {
        return $leg;
    }
    return array(
        "recording_id" => $leg["xml_cdr_uuid"],
        "domain_uuid" => $leg["domain_uuid"],
        "filename" => $leg["record_name"],
        "duration" => (int)$leg["duration"],
        "xml_cdr_uuid" => $leg["xml_cdr_uuid"],
        "created" => $leg["start_stamp"],
    );
}
