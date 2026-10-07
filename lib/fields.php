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

// destination_actions is JSON-encoded in the database. return it parsed (#5)
function rest_api_decode_destination($destination) {
    if($destination && !empty($destination['destination_actions'])) {
        $destination['destination_actions'] = json_decode($destination['destination_actions']);
    }
    return $destination;
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

// enabled is "true"/"false" text, or a boolean when the column is one
function rest_api_format_user_extension($extension) {
    $extension['enabled'] = in_array($extension['enabled'], array(true, 1, "1", "t", "true"), true);
    return $extension;
}
