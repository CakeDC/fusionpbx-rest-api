<?php
// xml_cdr_uuid is checked here, so its error is a plain message
$required_params = array();
$required_permissions = array("xml_cdr_view");

// one call with every leg (#43937). any leg's xml_cdr_uuid finds it. legs are
// linked as in cdr-search: a.bridge_uuid = b.xml_cdr_uuid, or
// b.originating_leg_uuid = a.xml_cdr_uuid
function do_action($body) {
    if(!isset($body->xml_cdr_uuid) || !is_uuid($body->xml_cdr_uuid)) {
        return array("error" => "invalid xml_cdr_uuid", "code" => 400);
    }

    $database = new database;
    // FusionPBX's select() returns false on a database error. a failed lookup
    // must not pass for a missing call or a call without some of its legs
    $failed = false;
    // legs of the requested domain only, oldest first
    $find = function($condition, $uuid) use ($database, $body, &$failed) {
        $sql = "SELECT c.".implode(", c.", REST_API_CDR_FIELDS)." FROM v_xml_cdr c";
        $sql .= " WHERE c.domain_uuid = :domain_uuid AND ".$condition;
        $sql .= " ORDER BY c.start_stamp, c.xml_cdr_uuid";
        $rows = $database->select($sql, array("domain_uuid" => $body->domain_uuid, "uuid" => $uuid), 'all');
        if(!is_array($rows)) {
            $failed = true;
            return array();
        }
        return $rows;
    };

    $found = $find("c.xml_cdr_uuid = :uuid", $body->xml_cdr_uuid);
    if($failed) {
        return array("error" => "database error", "code" => 500);
    }
    if(!$found) {
        return array("error" => "call not found", "code" => 404);
    }

    // the main leg: the "a" leg, else the earliest leg sharing its originating_leg_uuid
    $main = $found[0];
    if($main['leg'] !== "a") {
        $a = $main['originating_leg_uuid'] ? $find("c.leg = 'a' AND c.xml_cdr_uuid = :uuid", $main['originating_leg_uuid']) : array();
        if(!$a) {
            $a = $find("c.leg = 'a' AND c.bridge_uuid = :uuid", $main['xml_cdr_uuid']);
        }
        if($a) {
            $main = $a[0];
        } elseif($main['originating_leg_uuid']) {
            $siblings = $find("c.originating_leg_uuid = :uuid", $main['originating_leg_uuid']);
            if($siblings) {
                $main = $siblings[0];
            }
        }
    }

    $legs = array($main['xml_cdr_uuid'] => $main);
    $linked = $find("c.originating_leg_uuid = :uuid", $main['xml_cdr_uuid']);
    // bridge_uuid is text: only a uuid can match the uuid xml_cdr_uuid
    if(is_uuid($main['bridge_uuid'])) {
        $linked = array_merge($linked, $find("c.xml_cdr_uuid = :uuid", $main['bridge_uuid']));
    }
    if($main['leg'] !== "a" && $main['originating_leg_uuid']) {
        $linked = array_merge($linked, $find("c.originating_leg_uuid = :uuid", $main['originating_leg_uuid']));
    }
    foreach($linked as $leg) {
        $legs[$leg['xml_cdr_uuid']] = $leg;
    }
    if($failed) {
        return array("error" => "database error", "code" => 500);
    }
    usort($legs, function($x, $y) {
        return array($x['start_stamp'], $x['xml_cdr_uuid']) <=> array($y['start_stamp'], $y['xml_cdr_uuid']);
    });

    return array(
        "xml_cdr_uuid" => $main['xml_cdr_uuid'],
        "legs" => array_map("rest_api_format_cdr", $legs),
    );
}
