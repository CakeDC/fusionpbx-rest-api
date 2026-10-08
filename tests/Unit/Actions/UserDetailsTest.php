<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

/**
 * user-details: checks that a stored domain_uuid + user_uuid pair still
 * resolves to a FusionPBX user.
 */
#[RunTestsInSeparateProcesses]
class UserDetailsTest extends ActionTestCase
{
	private const AGENT = 'dddddddd-0000-4000-8000-000000000010';
	private const FORMER = 'dddddddd-0000-4000-8000-000000000012';
	private const OTHER_DOMAIN_USER = 'dddddddd-0000-4000-8000-000000000020';
	private const OTHER_DOMAIN_UUID = 'aaaaaaaa-0000-4000-8000-000000000002';

	protected function action(): string
	{
		return 'user-details';
	}

	protected function tables(): array
	{
		$secrets = array('password' => '$2y$10$hash', 'salt' => 'pepper', 'api_key' => 'fusionpbx-api-key', 'user_email' => 'someone@example.com');
		return parent::tables() + array(
			'v_users' => array(
				array('user_uuid' => self::AGENT, 'domain_uuid' => self::DOMAIN_UUID, 'username' => 'agent', 'user_enabled' => 'true') + $secrets,
				array('user_uuid' => self::FORMER, 'domain_uuid' => self::DOMAIN_UUID, 'username' => 'former', 'user_enabled' => 'false') + $secrets,
				array('user_uuid' => self::OTHER_DOMAIN_USER, 'domain_uuid' => self::OTHER_DOMAIN_UUID, 'username' => 'agent', 'user_enabled' => 'true') + $secrets,
			),
		);
	}

	private function details($user_uuid): array
	{
		return $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'user_uuid' => $user_uuid));
	}

	public function testReturnsTheUser(): void
	{
		$this->assertSame(
			array('user_uuid' => self::AGENT, 'domain_uuid' => self::DOMAIN_UUID, 'username' => 'agent', 'user_enabled' => true),
			$this->details(self::AGENT)
		);
	}

	// a disabled user is still found: the caller decides what to do with it
	public function testReturnsADisabledUser(): void
	{
		$this->assertFalse($this->details(self::FORMER)['user_enabled']);
	}

	// Postgres returns user_enabled as a PHP boolean
	public function testReadsABooleanUserEnabled(): void
	{
		\FakeStore::update(function (&$state) {
			$state['tables']['v_users'][0]['user_enabled'] = true;
		});

		$this->assertTrue($this->details(self::AGENT)['user_enabled']);
	}

	public function testAnswersNotFoundForAUserOfAnotherDomainOrAnUnknownOne(): void
	{
		foreach (array(self::OTHER_DOMAIN_USER, 'dddddddd-0000-4000-8000-00000000ffff') as $user_uuid) {
			$this->assertSame(array('error' => 'user not found', 'code' => 404), $this->details($user_uuid), $user_uuid);
		}
	}

	// FusionPBX stores uuids in lower case and is_uuid() accepts both, so the
	// uuid is lower-cased like domain_uuid: text columns (sqlite, mysql) would
	// otherwise miss an existing user
	public function testFindsTheUserByAnUpperCaseUuid(): void
	{
		$this->assertSame(self::AGENT, $this->details(strtoupper(self::AGENT))['user_uuid']);
	}

	public function testRejectsAMalformedUserUuid(): void
	{
		foreach (array('agent', '', array(self::AGENT), 42) as $user_uuid) {
			$this->assertSame(array('error' => 'invalid user_uuid', 'code' => 400), $this->details($user_uuid), json_encode($user_uuid));
		}
	}

	// FusionPBX's select() returns false on a database error, which must not
	// look like a deleted user
	public function testAnswers500WhenTheDatabaseFails(): void
	{
		$this->failSelects();

		$this->assertSame(array('error' => 'database error', 'code' => 500), $this->details(self::AGENT));
	}
}
