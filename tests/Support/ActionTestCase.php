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
	/** Action file name without extension, e.g. "originate". */
	abstract protected function action(): string;

	/** Permissions the action declares. setUp() grants exactly these. */
	protected array $requiredPermissions = array();

	protected function setUp(): void
	{
		require_once FUSIONPBX_FAKES_DIR.'/resources/fakes.php';
		require_once PLUGIN_DIR.'/lib/input_validation.php';
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

	protected function runAction(array $body)
	{
		return do_action((object)$body);
	}

	/** Make the next database save() fail, as FusionPBX's does on a database error. */
	protected function failSaves(): void
	{
		FakeStore::update(function (&$state) {
			$state['save_fails'] = true;
		});
	}

	protected function state(): array
	{
		return FakeStore::read();
	}
}
