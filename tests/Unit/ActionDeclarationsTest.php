<?php
namespace RestApi\Test\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Every action declares the FusionPBX permissions rest.php checks before running
 * it. FusionPBX's save() silently skips tables the user can't add to,
 * so the list must cover every table the action saves.
 */
#[RunTestsInSeparateProcesses]
class ActionDeclarationsTest extends TestCase
{
	private const PERMISSIONS = array(
		'call-answer' => array('rest_api_call_control'),
		'call-hangup' => array('call_active_hangup'),
		'call-hold' => array('rest_api_call_control'),
		'call-list' => array('call_active_view'),
		'call-resume' => array('rest_api_call_control'),
		'callcenter-agent-list' => array('call_center_agent_view', 'call_center_tier_view'),
		'callcenter-agent-state' => array('call_center_agent_view', 'call_center_agent_edit'),
		'callcenter-agent-status' => array('call_center_agent_view'),
		'callcenter-queue-list' => array('call_center_queue_view'),
		'callcenter-queue-status' => array('call_center_active_view'),
		'cdr-details' => array('xml_cdr_view'),
		'cdr-list' => array('xml_cdr_view'),
		'cdr-search' => array('xml_cdr_view'),
		'destination-create' => array('destination_add', 'dialplan_add', 'dialplan_detail_add'),
		'destination-delete' => array('destination_delete', 'dialplan_delete', 'dialplan_detail_delete'),
		'destination-details' => array('destination_view'),
		'destination-list' => array('destination_view'),
		'destination-update' => array('destination_edit', 'dialplan_edit', 'dialplan_detail_add', 'dialplan_detail_delete'),
		'domain-details' => array(),
		'domain-list' => array('domain_view'),
		'extension-create' => array('extension_add', 'voicemail_add'),
		'extension-delete' => array(
			'extension_delete', 'extension_user_delete', 'follow_me_delete', 'follow_me_destination_delete',
			'ring_group_destination_delete', 'extension_setting_delete', 'voicemail_delete',
			'voicemail_option_delete', 'voicemail_message_delete', 'voicemail_destination_delete',
			'voicemail_greeting_delete',
		),
		'extension-details' => array('extension_view'),
		'extension-list' => array('extension_view'),
		'extension-update' => array('extension_edit'),
		'extension-user-list' => array('extension_view', 'user_view'),
		'originate' => array('click_to_call_call'),
		'recording-details' => array('call_recording_view'),
		'recording-download' => array('call_recording_download'),
		'ringgroup-create' => array('ring_group_add', 'ring_group_destination_add', 'dialplan_add'),
		'ringgroup-delete' => array('ring_group_delete', 'ring_group_user_delete', 'ring_group_destination_delete', 'dialplan_delete', 'dialplan_detail_delete'),
		'ringgroup-details' => array('ring_group_view', 'ring_group_destination_view'),
		'ringgroup-list' => array('ring_group_view', 'ring_group_destination_view'),
		'ringgroup-update' => array('ring_group_edit'),
		'user-details' => array('user_view'),
		'user-list' => array('user_view'),
	);

	public static function actions(): array
	{
		$actions = array();
		foreach (self::PERMISSIONS as $action => $permissions) {
			$actions[$action] = array($action, $permissions);
		}
		return $actions;
	}

	#[DataProvider('actions')]
	public function testDeclaresTheFusionPbxPermissionsItNeeds(string $action, array $expected): void
	{
		require PLUGIN_DIR.'/actions/'.$action.'.php';

		$this->assertTrue(isset($required_permissions), $action.' does not declare $required_permissions');
		$this->assertSame($expected, $required_permissions);
	}

	public function testEveryActionIsListed(): void
	{
		$files = array_map(function ($file) { return basename($file, '.php'); }, glob(PLUGIN_DIR.'/actions/*.php'));
		sort($files);

		$this->assertSame(array_keys(self::PERMISSIONS), $files);
	}

	public function testNoActionGrantsItselfPermissions(): void
	{
		foreach (glob(PLUGIN_DIR.'/actions/*.php') as $file) {
			$this->assertDoesNotMatchRegularExpression('/\$_SESSION\s*\[\s*[\'"]permissions[\'"]\s*\]/', file_get_contents($file), basename($file));
		}
	}

	public function testNoActionReturnsEveryColumn(): void
	{
		foreach (glob(PLUGIN_DIR.'/actions/*.php') as $file) {
			$this->assertDoesNotMatchRegularExpression('/select\s+\*/i', file_get_contents($file), basename($file));
		}
	}
}
