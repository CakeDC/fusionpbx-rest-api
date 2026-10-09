<?php
$required_params = array("name", "extension", "destinations", "strategy");
$required_permissions = array("ring_group_add", "ring_group_destination_add", "dialplan_add");

// 201 with the ring group as ringgroup-details returns it, 409 when the
// extension already has a ring group in the domain
function do_action($body) {
    if($body->name === "" || !rest_api_is_caller_id_name($body->name)) {
        return array("error" => "invalid name", "code" => 400);
    }
    if(!is_dial_number($body->extension)) {
        return array("error" => "invalid extension", "code" => 400);
    }
    $body->extension = (string)$body->extension;
    if(!in_array($body->strategy, array("simultaneous", "sequence", "enterprise", "rollover", "random"), true)) {
        return array("error" => "invalid strategy", "code" => 400);
    }
    // a JSON array, or the JSON-encoded string older clients send. numbers end
    // up in the dial strings of FusionPBX's ring group script
    $destinations = is_string($body->destinations) ? json_decode($body->destinations) : $body->destinations;
    if(!is_array($destinations) || !$destinations) {
        return array("error" => "invalid destinations", "code" => 400);
    }
    $numbers = array();
    foreach($destinations as $destination) {
        $number = is_object($destination) ? ($destination->number ?? null) : (is_array($destination) ? ($destination["number"] ?? null) : null);
        if(!is_dial_number($number)) {
            return array("error" => "invalid destinations", "code" => 400);
        }
        $numbers[] = (string)$number;
    }
    $numbers = array_values(array_unique($numbers));

    $database = new database;
    // FusionPBX's select() returns false on a database error, which must not
    // pass for a missing ring group and create a duplicate
    $sql = "SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid";
    $domains = $database->select($sql, array('domain_uuid' => $body->domain_uuid), 'all');
    if(!is_array($domains)) {
        return array("error" => "database error", "code" => 500);
    }
    if(!$domains) {
        return array("error" => "domain not found", "code" => 404);
    }
    $domain_name = $domains[0]['domain_name'];

    $sql = "SELECT ring_group_uuid FROM v_ring_groups WHERE ring_group_extension = :extension AND domain_uuid = :domain_uuid";
    $existing = $database->select($sql, array('extension' => $body->extension, 'domain_uuid' => $body->domain_uuid), 'all');
    if(!is_array($existing)) {
        return array("error" => "database error", "code" => 500);
    }
    if($existing) {
        return array("error" => "ring group already exists", "code" => 409);
    }

    $ring_group_uuid = uuid();
    $dialplan_uuid = uuid();

    $ring_group_destinations = array();
    foreach($numbers as $number) {
        $ring_group_destinations[] = array(
            "ring_group_uuid" => $ring_group_uuid,
            "ring_group_destination_uuid" => uuid(),
            "destination_number" => $number,
            "destination_delay" => "0",
            "destination_timeout" => "30",
            "destination_prompt" => "",
            "destination_enabled" => "true",
            "domain_uuid" => $body->domain_uuid
        );
    }

    $array["ring_groups"][] = array(
        "ring_group_uuid" => $ring_group_uuid,
        "domain_uuid" => $body->domain_uuid,
        "ring_group_name" => $body->name,
        "ring_group_extension" => $body->extension,
        "ring_group_greeting" => "",
        "ring_group_strategy" => $body->strategy,
        "ring_group_call_timeout" => "30",
        "ring_group_caller_id_name" => "",
        "ring_group_caller_id_number" => "",
        "ring_group_distinctive_ring" => "",
        "ring_group_ringback" => "\${us-ring}",
        "ring_group_call_forward_enabled" => "",
        "ring_group_follow_me_enabled" => "",
        "ring_group_missed_call_app" => null,
        "ring_group_missed_call_data" => null,
        "ring_group_forward_enabled" => "false",
        "ring_group_forward_destination" => "",
        "ring_group_forward_toll_allow" => "",
        "ring_group_context" => $domain_name,
        "ring_group_enabled" => "true",
        "ring_group_description" => "",
        "dialplan_uuid" => $dialplan_uuid,
        "ring_group_timeout_app" => "",
        "ring_group_timeout_data" => "",
        "ring_group_destinations" => $ring_group_destinations
    );

    $dialplan_xml = "<extension name=\"".rest_api_xml_attribute($body->name)."\" continue=\"\" uuid=\"".$dialplan_uuid."\">\n";
    $dialplan_xml .= "\t<condition field=\"destination_number\" expression=\"^".preg_quote((string)$body->extension)."$\">\n";
    $dialplan_xml .= "\t\t<action application=\"ring_ready\" data=\"\" />\n";
    $dialplan_xml .= "\t\t<action application=\"set\" data=\"ring_group_uuid=".$ring_group_uuid."\" />\n";
    $dialplan_xml .= "\t\t<action application=\"lua\" data=\"app.lua ring_groups\" />\n";
    $dialplan_xml .= "\t</condition>\n";
    $dialplan_xml .= "</extension>";

    $array["dialplans"][] = array(
        "domain_uuid" => $body->domain_uuid,
        "dialplan_uuid" => $dialplan_uuid,
        "dialplan_name" => $body->name,
        "dialplan_number" => $body->extension,
        "dialplan_context" => $domain_name,
        "dialplan_continue" => "false",
        "dialplan_xml" => $dialplan_xml,
        "dialplan_order" => "101",
        "dialplan_enabled" => "true",
        "dialplan_description" => "",
        "app_uuid" => "1d61fb65-1eec-bc73-a6ee-a6203b4fe6f2" // ring group app
    );

    $database->app_name = 'rest_api';
    $database->app_uuid = '2bfe71d9-e112-4b8b-bcff-75aeb0e06302';
    if(!$database->save($array)) {
        return array("error" => "error adding ring group", "code" => 500);
    }

    // as ring_group_edit.php does, so the extension routes right away
    $cache = new cache;
    $cache->delete("dialplan:".$domain_name);

    $sql = "SELECT ring_group_uuid, domain_uuid, ring_group_name, ring_group_extension, ring_group_strategy FROM v_ring_groups WHERE ring_group_uuid = :ring_group_uuid";
    $rows = $database->select($sql, array("ring_group_uuid" => $ring_group_uuid), 'all');
    $ring_groups = is_array($rows) && $rows ? rest_api_format_ring_groups($database, $rows) : false;
    if(!$ring_groups) {
        return array("error" => "database error", "code" => 500);
    }
    $ring_groups[0]["code"] = 201;
    return $ring_groups[0];
}
