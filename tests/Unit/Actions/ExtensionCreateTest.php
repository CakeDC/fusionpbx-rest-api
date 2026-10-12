<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

/**
 * extension-create.
 */
#[RunTestsInSeparateProcesses]
class ExtensionCreateTest extends ActionTestCase
{
	private const AGENT = 'dddddddd-0000-4000-8000-000000000010';
	private const OTHER_DOMAIN_USER = 'dddddddd-0000-4000-8000-000000000020';

	protected function action(): string
	{
		return 'extension-create';
	}

	protected function tables(): array
	{
		return parent::tables() + array(
			'v_extensions' => array(
				array('extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000100', 'domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'extension' => '100'),
			),
			'v_users' => array(
				array('user_uuid' => self::AGENT, 'domain_uuid' => self::DOMAIN_UUID, 'username' => 'agent'),
				array('user_uuid' => self::OTHER_DOMAIN_USER, 'domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000002', 'username' => 'agent'),
			),
		);
	}

	private function create(array $fields): array
	{
		return $this->runAction($fields + array('domain_uuid' => self::DOMAIN_UUID));
	}

	public function testCreatesTheExtensionWithVoicemailAndCallerId(): void
	{
		$result = $this->create(array('extension' => '101', 'caller_id_name' => 'Sales', 'caller_id_number' => '5551000'));

		$tables = $this->state()['tables'];
		$this->assertSame(201, $result['code']);
		unset($result['code']);
		$this->assertSame(REST_API_EXTENSION_FIELDS, array_keys($result));
		$this->assertSame($tables['v_extensions'][1]['extension_uuid'], $result['extension_uuid']);
		$this->assertSame('101', $result['extension']);
		$this->assertSame('tenant1.example.com', $result['user_context']);
		$this->assertSame(array('Sales', '5551000'), array($result['outbound_caller_id_name'], $result['outbound_caller_id_number']));
		$this->assertArrayNotHasKey('password', $result);
		$this->assertTrue($result['enabled']);
		$this->assertSame('101', $tables['v_voicemails'][0]['voicemail_id']);
		$this->assertArrayNotHasKey('v_extension_users', $tables);
		$this->assertSame(array(), $this->state()['skipped'], 'the declared permissions must cover every saved table');
	}

	// JSON numbers are accepted and stored as text, like FusionPBX's form posts
	public function testAcceptsANumericExtension(): void
	{
		$this->assertSame('101', $this->create(array('extension' => 101))['extension']);
	}

	public function testReturnsTheSipPasswordToUsersAllowedToSeeIt(): void
	{
		$this->grantOnly(array_merge($this->requiredPermissions, array('extension_password')));

		$result = $this->create(array('extension' => '101'));

		$this->assertSame(10, strlen($result['password']));
		$this->assertSame($this->state()['tables']['v_extensions'][1]['password'], $result['password']);
	}

	public function testLinksTheExtensionToAUserOfTheDomain(): void
	{
		$this->grantOnly(array_merge($this->requiredPermissions, array('extension_user_add')));

		$result = $this->create(array('extension' => '101', 'user_uuid' => self::AGENT));

		$this->assertSame(201, $result['code']);
		$links = $this->state()['tables']['v_extension_users'];
		$this->assertCount(1, $links);
		$this->assertSame(
			array(self::DOMAIN_UUID, self::AGENT, $result['extension_uuid']),
			array($links[0]['domain_uuid'], $links[0]['user_uuid'], $links[0]['extension_uuid'])
		);
		$this->assertSame(array(), $this->state()['skipped']);
	}

	// save() would skip the link silently without extension_user_add
	public function testRequiresExtensionUserAddToLinkAUser(): void
	{
		$result = $this->create(array('extension' => '101', 'user_uuid' => self::AGENT));

		$this->assertSame(array('error' => 'forbidden', 'missing_permissions' => array('extension_user_add'), 'code' => 403), $result);
		$this->assertSame(array(), $this->state()['saved']);
	}

	public function testAnswersNotFoundForAUserOfAnotherDomain(): void
	{
		$this->grantOnly(array_merge($this->requiredPermissions, array('extension_user_add')));

		$this->assertSame(array('error' => 'user not found', 'code' => 404), $this->create(array('extension' => '101', 'user_uuid' => self::OTHER_DOMAIN_USER)));
		$this->assertSame(array(), $this->state()['saved']);
	}

	public function testAnswersConflictForAnExistingExtension(): void
	{
		$this->assertSame(array('error' => 'extension already exists', 'code' => 409), $this->create(array('extension' => '100')));
		$this->assertSame(array(), $this->state()['saved']);
	}

	public function testAnswersNotFoundForAnUnknownDomain(): void
	{
		$result = $this->runAction(array('domain_uuid' => 'aaaaaaaa-0000-4000-8000-00000000ffff', 'extension' => '101'));

		$this->assertSame(array('error' => 'domain not found', 'code' => 404), $result);
		$this->assertSame(array(), $this->state()['saved']);
	}

	public static function invalidFields(): array
	{
		return array(
			'extension with a space' => array(array('extension' => '10 1'), 'extension'),
			'extension with a dialplan character' => array(array('extension' => '101;'), 'extension'),
			'extension not a string' => array(array('extension' => array('101')), 'extension'),
			'number with letters' => array(array('extension' => '101', 'caller_id_number' => '555-CALL'), 'caller_id_number'),
			'name with a newline' => array(array('extension' => '101', 'caller_id_name' => "Ana\nRuiz"), 'caller_id_name'),
			'name not a string' => array(array('extension' => '101', 'caller_id_name' => 42), 'caller_id_name'),
			'malformed user_uuid' => array(array('extension' => '101', 'user_uuid' => 'agent'), 'user_uuid'),
		);
	}

	#[DataProvider('invalidFields')]
	public function testRejectsAnInvalidField(array $fields, string $name): void
	{
		$this->grantOnly(array_merge($this->requiredPermissions, array('extension_user_add')));

		$this->assertSame(array('error' => 'invalid '.$name, 'code' => 400), $this->create($fields));
		$this->assertSame(array(), $this->state()['saved']);
	}

	// select() returns false on a database error, which must not pass for
	// "no such extension" and create a duplicate
	public function testAnswers500WhenTheDatabaseFails(): void
	{
		$this->failSelects();

		$this->assertSame(array('error' => 'database error', 'code' => 500), $this->create(array('extension' => '101')));
		$this->assertSame(array(), $this->state()['saved']);
	}

	public function testReportsAFailedSave(): void
	{
		$this->failSaves();

		$this->assertSame(array('error' => 'error adding extension', 'code' => 500), $this->create(array('extension' => '101')));
		$this->assertCount(1, $this->state()['tables']['v_extensions']);
	}
}
