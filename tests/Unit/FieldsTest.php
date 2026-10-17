<?php
namespace RestApi\Test\Unit;

use PHPUnit\Framework\TestCase;

// fake_sql() doesn't check column names, but PostgreSQL refuses a SELECT of a
// column the table doesn't have, so the returned columns are pinned to 5.6.5's
class FieldsTest extends TestCase
{
	// v_extensions in FusionPBX 5.6.5's app/extensions/app_config.php
	private const V_EXTENSIONS = array(
		'extension_uuid', 'domain_uuid', 'extension', 'number_alias', 'password', 'accountcode',
		'effective_caller_id_name', 'effective_caller_id_number', 'outbound_caller_id_name',
		'outbound_caller_id_number', 'emergency_caller_id_name', 'emergency_caller_id_number',
		'directory_first_name', 'directory_last_name', 'directory_visible', 'directory_exten_visible',
		'max_registrations', 'limit_max', 'limit_destination', 'missed_call_app', 'missed_call_data',
		'user_context', 'toll_allow', 'call_timeout', 'call_group', 'call_screen_enabled', 'user_record',
		'hold_music', 'auth_acl', 'cidr', 'sip_force_contact', 'nibble_account', 'sip_force_expires',
		'mwi_account', 'sip_bypass_media', 'unique_id', 'dial_string', 'dial_user', 'dial_domain',
		'do_not_disturb', 'forward_all_destination', 'forward_all_enabled', 'forward_busy_destination',
		'forward_busy_enabled', 'forward_no_answer_destination', 'forward_no_answer_enabled',
		'forward_user_not_registered_destination', 'forward_user_not_registered_enabled', 'follow_me_uuid',
		'follow_me_enabled', 'follow_me_destinations', 'extension_language', 'extension_dialect',
		'extension_voice', 'extension_type', 'enabled', 'description', 'absolute_codec_string', 'force_ping',
		'insert_date', 'insert_user', 'update_date', 'update_user',
	);

	public static function setUpBeforeClass(): void
	{
		require_once PLUGIN_DIR.'/lib/fields.php';
	}

	public function testExtensionFieldsExistInFusionPbx(): void
	{
		$this->assertSame(array(), array_values(array_diff(REST_API_EXTENSION_FIELDS, self::V_EXTENSIONS)));
	}

	public function testUserExtensionFieldsExistInFusionPbx(): void
	{
		$this->assertSame(array(), array_values(array_diff(REST_API_USER_EXTENSION_FIELDS, self::V_EXTENSIONS)));
	}
}
