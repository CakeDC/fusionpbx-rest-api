<?php
namespace RestApi\Test\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Every action declares the FusionPBX permissions rest.php checks before running
 * it (#43940). FusionPBX's save() silently skips tables the user can't add to,
 * so the list must cover every table the action saves.
 */
#[RunTestsInSeparateProcesses]
class ActionDeclarationsTest extends TestCase
{
	private const PERMISSIONS = array(
		'cdr-details' => array('xml_cdr_view'),
		'cdr-list' => array('xml_cdr_view'),
		'cdr-search' => array('xml_cdr_view'),
		'destination-create' => array('destination_add', 'dialplan_add', 'dialplan_detail_add'),
		'destination-details' => array('destination_view'),
		'domain-details' => array(),
		'domain-list' => array('domain_view'),
		'extension-create' => array('extension_add', 'voicemail_add'),
		'extension-details' => array('extension_view'),
		'extension-list' => array('extension_view'),
		'extension-user-list' => array('extension_view', 'user_view'),
		'originate' => array('click_to_call_call'),
		'ringgroup-create' => array('ring_group_add', 'ring_group_destination_add', 'dialplan_add'),
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
