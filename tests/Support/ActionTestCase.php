<?php
namespace RestApi\Test\Support;

use FakeStore;
use PHPUnit\Framework\TestCase;

/**
 * Loads one action file against the FusionPBX fakes. Every action defines a
 * global do_action(), so subclasses must be marked #[RunTestsInSeparateProcesses].
 */
abstract class ActionTestCase extends TestCase
{
	protected const DOMAIN_UUID = 'aaaaaaaa-0000-4000-8000-000000000001';

	/** Action file name without extension, e.g. "originate". */
	abstract protected function action(): string;

	/** Permissions the action declares. setUp() grants exactly these. */
	protected array $requiredPermissions = array();

	protected function setUp(): void
	{
		require_once FUSIONPBX_FAKES_DIR.'/resources/fakes.php';
		require_once PLUGIN_DIR.'/lib/input_validation.php';
		require_once PLUGIN_DIR.'/lib/fields.php';
		require_once PLUGIN_DIR.'/lib/fs_parser.php';
		require_once PLUGIN_DIR.'/lib/call_center.php';
		require_once PLUGIN_DIR.'/lib/recordings.php';
		require_once PLUGIN_DIR.'/lib/calls.php';
		$_SESSION = array();
		FakeStore::reset($this->tables());
		require PLUGIN_DIR.'/actions/'.$this->action().'.php';
		$this->requiredPermissions = $required_permissions ?? array();
		$this->grantOnly($this->requiredPermissions);
	}

	/**
	 * Give the user exactly these permissions. FusionPBX keeps the permissions
	 * of the first check, so call this before running the action.
	 */
	protected function grantOnly(array $permissions): void
	{
		$_SESSION['permissions'] = array_fill_keys($permissions, true);
	}

	/** Initial table rows, keyed by table name. */
	protected function tables(): array
	{
		return array(
			'v_domains' => array(
				array('domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'domain_name' => 'tenant1.example.com', 'domain_enabled' => 'true'),
				array('domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000002', 'domain_name' => 'tenant2.example.com', 'domain_enabled' => 'true'),
			),
		);
	}

	/** Run the action as rest.php does, with the context from rest_api_resolve_domain(). */
	protected function runAction(array $body, array $context = array())
	{
		return do_action((object)$body, $context + array(
			'domain_explicit' => true,
			'cross_domain' => false,
			'user_domain_uuid' => self::DOMAIN_UUID,
		));
	}

	/** Make the next database save() fail, as FusionPBX's does on a database error. */
	protected function failSaves(): void
	{
		FakeStore::update(function (&$state) {
			$state['save_fails'] = true;
		});
	}

	/** Make every database select() fail, as FusionPBX's returns false on a database error. */
	protected function failSelects(): void
	{
		FakeStore::update(function (&$state) {
			$state['select_fails'] = true;
		});
	}

	protected function state(): array
	{
		return FakeStore::read();
	}
}
