<?php
// the columns each action returns, so secrets and columns FusionPBX adds later
// never leak through the API (#43940)

const REST_API_EXTENSION_FIELDS = array(
    "extension_uuid",
    "extension",
    "number_alias",
    "effective_caller_id_name",
    "effective_caller_id_number",
    "outbound_caller_id_name",
    "outbound_caller_id_number",
    "emergency_caller_id_name",
    "emergency_caller_id_number",
    "directory_first_name",
    "directory_last_name",
    "directory_visible",
    "directory_exten_visible",
    "limit_max",
    "limit_destination",
    "missed_call_app",
    "missed_call_data",
    "user_context",
    "toll_allow",
    "call_timeout",
    "call_group",
    "call_screen_enabled",
    "user_record",
    "hold_music",
    "auth_acl",
    "cidr",
    "sip_force_contact",
    "nibble_account",
    "sip_force_expires",
    "mwi_account",
    "sip_bypass_media",
    "unique_id",
    "dial_string",
    "dial_user",
    "dial_domain",
    "do_not_disturb",
    "forward_all_destination",
    "forward_all_enabled",
    "forward_busy_destination",
    "forward_busy_enabled",
    "forward_no_answer_destination",
    "forward_no_answer_enabled",
    "forward_user_not_registered_destination",
    "forward_user_not_registered_enabled",
    "follow_me_uuid",
    "enabled",
    "description",
    "forward_caller_id_uuid",
    "absolute_codec_string",
    "force_ping",
    "follow_me_enabled",
    "follow_me_destinations",
    "max_registrations",
    "insert_date",
    "insert_user",
    "update_date",
    "update_user"
);

// extension-user-list: the Extension of the ZuluCall contract (#43936)
const REST_API_USER_EXTENSION_FIELDS = array(
    "extension_uuid",
    "extension",
    "domain_uuid",
    "directory_first_name",
    "directory_last_name",
    "emergency_caller_id_number",
    "outbound_caller_id_number",
    "enabled"
);

const REST_API_DESTINATION_FIELDS = array(
    "destination_uuid",
    "domain_uuid",
    "destination_number",
    "destination_type",
    "destination_actions",
    "destination_context",
    "destination_enabled",
    "destination_description",
    "dialplan_uuid",
    "insert_date",
    "update_date"
);

const REST_API_RING_GROUP_FIELDS = array(
    "ring_group_uuid",
    "domain_uuid",
    "ring_group_name",
    "ring_group_extension",
    "ring_group_strategy",
    "ring_group_enabled",
    "ring_group_description",
    "dialplan_uuid"
);

const REST_API_RING_GROUP_DESTINATION_FIELDS = array(
    "ring_group_destination_uuid",
    "destination_number",
    "destination_delay",
    "destination_timeout",
    "destination_enabled"
);

// ZuluCall's RingGroup (#43979) from v_ring_groups rows (ring_group_uuid,
// domain_uuid, ring_group_name, ring_group_extension, ring_group_strategy),
// with the destinations of all of them read in one query. destinations are
// in FusionPBX's order, by delay (a number, sorted here so text and numeric
// columns agree) then number. false on a database error
function rest_api_format_ring_groups($database, array $rows) {
    $destinations = array();
    if($rows) {
        $parameters = array();
        $placeholders = array();
        foreach(array_values($rows) as $i => $row) {
            $placeholders[] = ":ring_group_uuid_".$i;
            $parameters["ring_group_uuid_".$i] = $row["ring_group_uuid"];
        }
        $sql = "SELECT ring_group_uuid, destination_number, destination_delay FROM v_ring_group_destinations";
        $sql .= " WHERE ring_group_uuid IN (".implode(", ", $placeholders).")";
        $records = $database->select($sql, $parameters, 'all');
        if(!is_array($records)) {
            return false;
        }
        usort($records, function($a, $b) {
            return array((float)$a["destination_delay"], (string)$a["destination_number"])
                <=> array((float)$b["destination_delay"], (string)$b["destination_number"]);
        });
        foreach($records as $record) {
            $destinations[$record["ring_group_uuid"]][] = array("number" => (string)$record["destination_number"]);
        }
    }

    $ring_groups = array();
    foreach($rows as $row) {
        $ring_groups[] = array(
            "ring_group_uuid" => $row["ring_group_uuid"],
            "domain_uuid" => $row["domain_uuid"],
            "name" => $row["ring_group_name"],
            "extension" => $row["ring_group_extension"],
            "strategy" => $row["ring_group_strategy"],
            "destinations" => $destinations[$row["ring_group_uuid"]] ?? array(),
        );
    }
    return $ring_groups;
}

const REST_API_DOMAIN_FIELDS = array(
    "domain_uuid",
    "domain_parent_uuid",
    "domain_name",
    "domain_enabled",
    "domain_description"
);

// ZuluCall's Domain (listDomains, #43969)
const REST_API_DOMAIN_LIST_FIELDS = array(
    "domain_uuid",
    "domain_name",
    "domain_enabled"
);

// ZuluCall's PbxUser (listUsers, #43970). never password, salt or api_key
const REST_API_USER_FIELDS = array(
    "user_uuid",
    "domain_uuid",
    "username",
    "user_enabled"
);

// destination_actions is JSON-encoded in the database. return it parsed (#5)
function rest_api_decode_destination($destination) {
    if($destination && !empty($destination['destination_actions'])) {
        $destination['destination_actions'] = json_decode($destination['destination_actions']);
    }
    return $destination;
}

// ZuluCall's Destination (#43976) from v_destinations rows. FusionPBX stores
// a route as transfer actions, "<number> XML <context>" (voicemail: "*99<box>"),
// so destination_type and target come from what the number is in the domain.
// a route with anything but one such action, or to a number none of the four
// types owns, gets null for both. false on a database error
function rest_api_format_destinations($database, $domain_uuid, array $rows) {
    $numbers = array();
    foreach($rows as $i => $row) {
        $actions = json_decode((string)$row["destination_actions"], true);
        if(is_array($actions) && count($actions) === 1 && ($actions[0]["destination_app"] ?? null) === "transfer"
            && preg_match('/^(\S+) XML \S+$/', (string)($actions[0]["destination_data"] ?? ""), $m)) {
            $numbers[$i] = $m[1];
        }
    }

    // each "?" becomes a list of the numbers, every one with its own placeholder
    $lookup = function($sql, $values) use ($database, $domain_uuid) {
        if(!$values) {
            return array();
        }
        $values = array_values(array_unique(array_map("strval", $values)));
        $parameters = array("domain_uuid" => $domain_uuid);
        $list = 0;
        $sql = preg_replace_callback('/\?/', function() use ($values, &$parameters, &$list) {
            $names = array();
            foreach($values as $v => $value) {
                $parameters["n".$list."_".$v] = $value;
                $names[] = ":n".$list."_".$v;
            }
            $list++;
            return implode(", ", $names);
        }, $sql);
        return $database->select($sql, $parameters, 'all');
    };
    $voicemail_ids = array();
    foreach($numbers as $number) {
        if(strpos($number, "*99") === 0) {
            $voicemail_ids[] = substr($number, 3);
        }
    }
    // type => array(records, number column, target column)
    $found = array(
        "voicemail" => array($lookup("SELECT voicemail_id FROM v_voicemails WHERE domain_uuid = :domain_uuid AND voicemail_id IN (?)", $voicemail_ids), "voicemail_id", "voicemail_id"),
        "ring_group" => array($lookup("SELECT ring_group_extension, ring_group_uuid FROM v_ring_groups WHERE domain_uuid = :domain_uuid AND ring_group_extension IN (?)", $numbers), "ring_group_extension", "ring_group_uuid"),
        "ivr" => array($lookup("SELECT ivr_menu_extension, ivr_menu_uuid FROM v_ivr_menus WHERE domain_uuid = :domain_uuid AND ivr_menu_extension IN (?)", $numbers), "ivr_menu_extension", "ivr_menu_uuid"),
        // the number dialed is the target, whether extension or alias
        "extension" => array($lookup("SELECT extension, number_alias FROM v_extensions WHERE domain_uuid = :domain_uuid AND (extension IN (?) OR number_alias IN (?))", $numbers), null, null),
    );
    $targets = array();
    foreach($found as $type => list($records, $number_column, $target_column)) {
        if(!is_array($records)) {
            return false;
        }
        foreach($records as $record) {
            if($number_column !== null) {
                $targets[$type][(string)$record[$number_column]] = (string)$record[$target_column];
                continue;
            }
            foreach(array("extension", "number_alias") as $column) {
                if((string)$record[$column] !== "") {
                    $targets[$type][(string)$record[$column]] = (string)$record[$column];
                }
            }
        }
    }

    $destinations = array();
    foreach($rows as $i => $row) {
        $type = null;
        $target = null;
        if(isset($numbers[$i])) {
            $number = $numbers[$i];
            $voicemail_id = strpos($number, "*99") === 0 ? substr($number, 3) : null;
            if($voicemail_id !== null && isset($targets["voicemail"][$voicemail_id])) {
                list($type, $target) = array("voicemail", $targets["voicemail"][$voicemail_id]);
            } else {
                foreach(array("ring_group", "ivr", "extension") as $candidate) {
                    if(isset($targets[$candidate][$number])) {
                        list($type, $target) = array($candidate, $targets[$candidate][$number]);
                        break;
                    }
                }
            }
        }
        $destinations[] = array(
            "domain_uuid" => $row["domain_uuid"],
            "number" => $row["destination_number"],
            "destination_type" => $type,
            "target" => $target,
            "enabled" => in_array($row["destination_enabled"], array(true, 1, "1", "t", "true"), true),
        );
    }
    return $destinations;
}

// the "<number> XML <context>" a transfer to the target needs, as FusionPBX's
// destination select builds it (voicemail: "*99<box>"); null when the target
// isn't in the domain, false on a database error. the target is validated
function rest_api_destination_transfer_data($database, $domain_uuid, $type, $target) {
    $queries = array(
        "extension" => "SELECT extension, number_alias, user_context FROM v_extensions WHERE domain_uuid = :domain_uuid AND (extension = :target OR number_alias = :target_alias)",
        "ring_group" => "SELECT ring_group_extension, ring_group_context FROM v_ring_groups WHERE domain_uuid = :domain_uuid AND ring_group_uuid = :target",
        "ivr" => "SELECT ivr_menu_extension, ivr_menu_context FROM v_ivr_menus WHERE domain_uuid = :domain_uuid AND ivr_menu_uuid = :target",
        "voicemail" => "SELECT voicemail_id FROM v_voicemails WHERE domain_uuid = :domain_uuid AND voicemail_id = :target",
    );
    $parameters = array("domain_uuid" => $domain_uuid, "target" => $target);
    if($type === "extension") {
        $parameters["target_alias"] = $target;
    }
    $records = $database->select($queries[$type], $parameters, 'all');
    $domain_name = $database->select("SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid", array("domain_uuid" => $domain_uuid), 'column');
    if(!is_array($records) || $domain_name === false) {
        return false;
    }
    if(!$records) {
        return null;
    }
    $record = $records[0];
    switch($type) {
        case "extension":
            return $target." XML ".($record["user_context"] ?: $domain_name);
        case "ring_group":
            return $record["ring_group_extension"]." XML ".($record["ring_group_context"] ?: $domain_name);
        case "ivr":
            return $record["ivr_menu_extension"]." XML ".($record["ivr_menu_context"] ?: $domain_name);
        default:
            return "*99".$record["voicemail_id"]." XML ".$domain_name;
    }
}

// a destination action as FusionPBX's destination_edit.php writes it in the
// dialplan XML (xml::sanitize(), keeping ${regex} and ${sofia_contact})
function rest_api_dialplan_action_xml($app, $data) {
    $allowed = array("regex", "sofia_contact");
    foreach($allowed as $command) {
        $data = str_replace('${'.$command, '#{'.$command, (string)$data);
    }
    $data = rest_api_xml_sanitize($data);
    foreach($allowed as $command) {
        $data = str_replace('#{'.$command, '${'.$command, $data);
    }
    return '<action application="'.rest_api_xml_sanitize($app).'" data="'.$data.'"/>';
}

// FusionPBX's xml::sanitize(): drops ${...} variables and escapes the rest
function rest_api_xml_sanitize($value) {
    return htmlspecialchars(preg_replace('/\$\{[^}]+\}/', '', (string)$value), ENT_XML1);
}

// cdr-search and cdr-details: the Cdr of the ZuluCall contract (#43937)
const REST_API_CDR_FIELDS = array(
    "xml_cdr_uuid",
    "direction",
    "caller_id_name",
    "caller_id_number",
    "destination_number",
    "start_stamp",
    "end_stamp",
    "duration",
    "hangup_cause",
    "hangup_cause_q850",
    "missed_call",
    "leg",
    "bridge_uuid",
    "originating_leg_uuid",
    "extension_uuid",
    "record_name",
    "record_path",
    "call_center_queue_uuid",
    "cc_queue"
);

// the database may return numbers as text and booleans as "t"/"f". record_name
// and record_path are null when the call was not recorded
function rest_api_format_cdr($cdr) {
    $cdr['duration'] = (int)$cdr['duration'];
    $cdr['hangup_cause_q850'] = is_numeric($cdr['hangup_cause_q850']) ? (int)$cdr['hangup_cause_q850'] : null;
    $cdr['missed_call'] = in_array($cdr['missed_call'], array(true, 1, "1", "t", "true"), true);
    foreach(array("record_name", "record_path") as $column) {
        if($cdr[$column] === "") {
            $cdr[$column] = null;
        }
    }
    return $cdr;
}

// domain_enabled is boolean on Postgres, text on sqlite and mysql
function rest_api_format_domain($domain) {
    $domain['domain_enabled'] = in_array($domain['domain_enabled'], array(true, 1, "1", "t", "true"), true);
    return $domain;
}

// user_enabled is boolean on Postgres, text on sqlite and mysql
function rest_api_format_user($user) {
    $user['user_enabled'] = in_array($user['user_enabled'], array(true, 1, "1", "t", "true"), true);
    return $user;
}

// enabled is "true"/"false" text, or a boolean when the column is one
function rest_api_format_user_extension($extension) {
    $extension['enabled'] = in_array($extension['enabled'], array(true, 1, "1", "t", "true"), true);
    return $extension;
}
