<?php
$required_params = array();
$required_permissions = array("xml_cdr_view");

// ZuluCall's call history (#43937): filtered, paginated CDRs, by default one
// row per call. cdr-list stays as it was for older clients.
//
// FusionPBX doesn't give both legs of a call the same id: an "a" leg's
// bridge_uuid is the xml_cdr_uuid of its "b" leg, and a "b" leg's
// originating_leg_uuid is the xml_cdr_uuid of its "a" leg. a call is shown
// as its "a" leg or, when that isn't in the domain, as the earliest of the
// "b" legs that share an originating_leg_uuid. bridge_uuid is a text column
// and the other ids are uuid, so they are compared as text
function do_action($body) {
    $parameters = array();
    // every value gets its own placeholder, so none is used twice in a query
    $bind = function($value) use (&$parameters) {
        $name = "p".count($parameters);
        $parameters[$name] = $value;
        return ":".$name;
    };

    $calls_only = isset($body->calls_only) ? rest_api_parse_bool($body->calls_only) : true;
    if($calls_only === null) {
        return array("error" => "invalid calls_only", "code" => 400);
    }
    $page = isset($body->page) ? rest_api_parse_int($body->page, 1, 1000000) : 1;
    if($page === false) {
        return array("error" => "invalid page", "code" => 400);
    }
    $per_page = isset($body->per_page) ? rest_api_parse_int($body->per_page, 1, 200) : 25;
    if($per_page === false) {
        return array("error" => "invalid per_page", "code" => 400);
    }
    $sorts = array("-start_stamp" => "DESC", "start_stamp" => "ASC");
    $sort = isset($body->sort) ? $body->sort : "-start_stamp";
    if(!is_string($sort) || !isset($sorts[$sort])) {
        return array("error" => "invalid sort", "code" => 400);
    }

    $where = array("c.domain_uuid = ".$bind($body->domain_uuid));
    // the domain's legs, for the subqueries
    $domain_legs = function($alias, $condition = "") use ($bind, $body) {
        return " FROM v_xml_cdr ".$alias." WHERE ".$alias.".domain_uuid = ".$bind($body->domain_uuid).$condition;
    };
    if($calls_only) {
        // a "b" leg is the main leg only when no "a" leg of the domain
        // originated it or was bridged to it, and no sibling started earlier.
        // FusionPBX indexes only xml_cdr_uuid: lookups by it are correlated,
        // bridge_uuid is matched against a set Postgres hashes once per query
        $where[] = "(c.leg = 'a' OR ("
            ."NOT EXISTS (SELECT 1 FROM v_xml_cdr p WHERE p.xml_cdr_uuid = c.originating_leg_uuid"
            ." AND p.domain_uuid = ".$bind($body->domain_uuid)." AND p.leg = 'a')"
            ." AND CAST(c.xml_cdr_uuid AS text) NOT IN (SELECT p.bridge_uuid".$domain_legs("p", " AND p.leg = 'a' AND p.bridge_uuid IS NOT NULL").")"
            ." AND NOT EXISTS (SELECT 1 FROM v_xml_cdr s WHERE s.domain_uuid = ".$bind($body->domain_uuid)
            ." AND s.originating_leg_uuid = c.originating_leg_uuid"
            ." AND (s.start_stamp < c.start_stamp OR (s.start_stamp = c.start_stamp AND s.xml_cdr_uuid < c.xml_cdr_uuid)))))";
    }

    $start = null;
    if(isset($body->start_date)) {
        $start = rest_api_parse_timestamp($body->start_date);
        if($start === false) {
            return array("error" => "invalid start_date", "code" => 400);
        }
        $where[] = "c.start_stamp >= ".$bind($start[0]->format("Y-m-d H:i:s.uP"));
    }
    if(isset($body->end_date)) {
        $end = rest_api_parse_timestamp($body->end_date);
        if($end === false) {
            return array("error" => "invalid end_date", "code" => 400);
        }
        // a date alone includes that whole day
        if($end[1]) {
            $end_exclusive = $end[0]->modify("+1 day");
            $invalid = $start && $start[0] >= $end_exclusive;
            $where[] = "c.start_stamp < ".$bind($end_exclusive->format("Y-m-d H:i:s.uP"));
        } else {
            $invalid = $start && $start[0] > $end[0];
            $where[] = "c.start_stamp <= ".$bind($end[0]->format("Y-m-d H:i:s.uP"));
        }
        if($invalid) {
            return array("error" => "invalid end_date", "code" => 400);
        }
    }

    if(isset($body->direction)) {
        if(!in_array($body->direction, array("inbound", "outbound", "local"), true)) {
            return array("error" => "invalid direction", "code" => 400);
        }
        $where[] = "c.direction = ".$bind($body->direction);
    }

    if(isset($body->missed)) {
        $missed = rest_api_parse_bool($body->missed);
        if($missed === null) {
            return array("error" => "invalid missed", "code" => 400);
        }
        $where[] = "c.missed_call = ".($missed ? "true" : "false");
    }

    if(isset($body->counterparty)) {
        if(!is_string($body->counterparty) || $body->counterparty === "" || strlen($body->counterparty) > 64) {
            return array("error" => "invalid counterparty", "code" => 400);
        }
        $like = "%".rest_api_like_escape($body->counterparty)."%";
        $caller_matches = "c.caller_id_number LIKE ".$bind($like)." ESCAPE '!'";
        $destination_matches = "c.destination_number LIKE ".$bind($like)." ESCAPE '!'";
        if(isset($body->own_number)) {
            $own = rest_api_parse_list($body->own_number);
            if($own === false) {
                return array("error" => "invalid own_number", "code" => 400);
            }
            // each use binds the numbers again, as a placeholder can't be repeated
            $in_own = function($column) use ($own, $bind) {
                return $column." IN (".implode(", ", array_map($bind, $own)).")";
            };
            $not_own = function($column) use ($in_own) {
                return "(".$column." IS NULL OR NOT ".$in_own($column).")";
            };
            // only the other party: the destination when the viewer called,
            // the caller when the viewer was called, else either
            $where[] = "(("
                .$in_own("c.caller_id_number")." AND ".$destination_matches.") OR ("
                .$not_own("c.caller_id_number")." AND ".$in_own("c.destination_number")." AND ".$caller_matches.") OR ("
                .$not_own("c.caller_id_number")." AND ".$not_own("c.destination_number")
                ." AND (c.caller_id_number LIKE ".$bind($like)." ESCAPE '!' OR c.destination_number LIKE ".$bind($like)." ESCAPE '!')))";
        } else {
            $where[] = "(".$caller_matches." OR ".$destination_matches.")";
        }
    } elseif(isset($body->own_number) && rest_api_parse_list($body->own_number) === false) {
        return array("error" => "invalid own_number", "code" => 400);
    }

    if(isset($body->extension_uuid)) {
        $extensions = rest_api_parse_list($body->extension_uuid);
        if($extensions === false || count(array_filter($extensions, "is_uuid")) !== count($extensions)) {
            return array("error" => "invalid extension_uuid", "code" => 400);
        }
        // a column of the domain's legs that belong to one of the extensions
        $of_extensions = function($column) use ($domain_legs, $extensions, $bind) {
            return "(SELECT ".$column.$domain_legs("l", " AND l.extension_uuid IN (".implode(", ", array_map($bind, $extensions)).")").")";
        };
        // any leg of the call: the row itself, its "b" legs, the leg it was
        // bridged to and, for a "b" leg shown as the call, its siblings. the
        // same legs cdr-details returns. sets, so Postgres hashes each once
        $any_leg = array(
            "c.extension_uuid IN (".implode(", ", array_map($bind, $extensions)).")",
            "c.xml_cdr_uuid IN ".$of_extensions("l.originating_leg_uuid"),
            "c.bridge_uuid IN ".$of_extensions("CAST(l.xml_cdr_uuid AS text)"),
        );
        if($calls_only) {
            $any_leg[] = "((c.leg IS NULL OR c.leg <> 'a') AND c.originating_leg_uuid IN ".$of_extensions("l.originating_leg_uuid").")";
        } else {
            // a leg listed alone matches through its "a" leg and its siblings too
            $any_leg[] = "c.originating_leg_uuid IN ".$of_extensions("l.xml_cdr_uuid");
            $any_leg[] = "CAST(c.xml_cdr_uuid AS text) IN ".$of_extensions("l.bridge_uuid");
            $any_leg[] = "c.originating_leg_uuid IN ".$of_extensions("l.originating_leg_uuid");
        }
        $where[] = "(".implode(" OR ", $any_leg).")";
    }

    $database = new database;
    $where = implode(" AND ", $where);
    // FusionPBX's select() returns false on a database error
    $total = $database->select("SELECT COUNT(*) FROM v_xml_cdr c WHERE ".$where, $parameters, 'column');
    if($total === false) {
        return array("error" => "database error", "code" => 500);
    }

    $sql = "SELECT c.".implode(", c.", REST_API_CDR_FIELDS)." FROM v_xml_cdr c WHERE ".$where;
    $sql .= " ORDER BY c.start_stamp ".$sorts[$sort].", c.xml_cdr_uuid ".$sorts[$sort];
    $sql .= " LIMIT ".$per_page." OFFSET ".(($page - 1) * $per_page);
    $rows = $database->select($sql, $parameters, 'all');
    if(!is_array($rows)) {
        return array("error" => "database error", "code" => 500);
    }

    return array(
        "data" => array_map("rest_api_format_cdr", $rows),
        "pagination" => array("page" => $page, "per_page" => $per_page, "total" => (int)$total),
    );
}
