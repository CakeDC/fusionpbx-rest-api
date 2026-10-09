<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

/**
 * user-list: the FusionPBX users of a domain.
 */
#[RunTestsInSeparateProcesses]
class UserListTest extends ActionTestCase
{
	private const AGENT = 'dddddddd-0000-4000-8000-000000000010';
	private const ADMIN = 'dddddddd-0000-4000-8000-000000000011';
	private const FORMER = 'dddddddd-0000-4000-8000-000000000012';
	private const OTHER_DOMAIN_USER = 'dddddddd-0000-4000-8000-000000000020';
	private const OTHER_DOMAIN_UUID = 'aaaaaaaa-0000-4000-8000-000000000002';

	protected function action(): string
	{
		return 'user-list';
	}

	protected function tables(): array
	{
		$secrets = array('password' => '$2y$10$hash', 'salt' => 'pepper', 'api_key' => 'fusionpbx-api-key', 'user_email' => 'someone@example.com');
		return parent::tables() + array(
			'v_users' => array(
				array('user_uuid' => self::FORMER, 'domain_uuid' => self::DOMAIN_UUID, 'username' => 'former', 'user_enabled' => 'false') + $secrets,
				array('user_uuid' => self::ADMIN, 'domain_uuid' => self::DOMAIN_UUID, 'username' => 'admin', 'user_enabled' => 'true') + $secrets,
				array('user_uuid' => self::AGENT, 'domain_uuid' => self::DOMAIN_UUID, 'username' => 'agent', 'user_enabled' => 'true') + $secrets,
				array('user_uuid' => self::OTHER_DOMAIN_USER, 'domain_uuid' => self::OTHER_DOMAIN_UUID, 'username' => 'agent', 'user_enabled' => 'true') + $secrets,
			),
		);
	}

	private function list(array $body = array(), string $domain_uuid = self::DOMAIN_UUID): array
	{
		return $this->runAction($body + array('domain_uuid' => $domain_uuid));
	}

	// disabled users are listed too: the caller decides what to do with them
	public function testListsTheUsersOfTheDomainByUsername(): void
	{
		$this->assertSame(array(
			'data' => array(
				array('user_uuid' => self::ADMIN, 'domain_uuid' => self::DOMAIN_UUID, 'username' => 'admin', 'user_enabled' => true),
				array('user_uuid' => self::AGENT, 'domain_uuid' => self::DOMAIN_UUID, 'username' => 'agent', 'user_enabled' => true),
				array('user_uuid' => self::FORMER, 'domain_uuid' => self::DOMAIN_UUID, 'username' => 'former', 'user_enabled' => false),
			),
			'pagination' => array('page' => 1, 'per_page' => 25, 'total' => 3),
		), $this->list());
	}

	public function testOnlyListsUsersOfTheRequestedDomain(): void
	{
		$this->assertSame(array(self::OTHER_DOMAIN_USER), array_column($this->list(array(), self::OTHER_DOMAIN_UUID)['data'], 'user_uuid'));
	}

	// Postgres returns user_enabled as a PHP boolean
	public function testReadsABooleanUserEnabled(): void
	{
		\FakeStore::update(function (&$state) {
			$state['tables']['v_users'][0]['user_enabled'] = false;
			$state['tables']['v_users'][1]['user_enabled'] = true;
		});

		$this->assertSame(array(true, true, false), array_column($this->list()['data'], 'user_enabled'));
	}

	public function testPaginates(): void
	{
		$second = $this->list(array('page' => 2, 'per_page' => 2));
		$beyond = $this->list(array('page' => '3', 'per_page' => '2'));

		$this->assertSame(array(self::FORMER), array_column($second['data'], 'user_uuid'));
		$this->assertSame(array('page' => 2, 'per_page' => 2, 'total' => 3), $second['pagination']);
		$this->assertSame(array(), $beyond['data']);
		$this->assertSame(array('page' => 3, 'per_page' => 2, 'total' => 3), $beyond['pagination']);
	}

	// past the last page the count is enough
	public function testOnlyCountsPastTheLastPage(): void
	{
		$this->list(array('page' => 1000000, 'per_page' => 200));

		$queries = $this->state()['queries'];
		$this->assertCount(1, $queries);
		$this->assertStringStartsWith('SELECT COUNT(*)', $queries[0]['sql']);
	}

	public static function invalidParameters(): array
	{
		return array(
			array('page', 0),
			array('page', 1000001),
			array('per_page', 201),
			array('per_page', 'all'),
		);
	}

	#[DataProvider('invalidParameters')]
	public function testRejectsAnInvalidParameter(string $name, $value): void
	{
		$this->assertSame(array('error' => 'invalid '.$name, 'code' => 400), $this->list(array($name => $value)));
	}

	// FusionPBX's select() returns false on a database error. an empty list
	// would look like a domain without users
	public function testAnswers500WhenTheDatabaseFails(): void
	{
		$this->failSelects();

		$this->assertSame(array('error' => 'database error', 'code' => 500), $this->list());
	}
}
