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

// destination_actions is JSON-encoded in the database. return it parsed (#5)
function rest_api_decode_destination($destination) {
    if($destination && !empty($destination['destination_actions'])) {
        $destination['destination_actions'] = json_decode($destination['destination_actions']);
    }
    return $destination;
}
