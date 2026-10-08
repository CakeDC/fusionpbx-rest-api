<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

/**
 * domain-list. Users with domain_select see
 * every domain, others only their own.
 */
#[RunTestsInSeparateProcesses]
class DomainListTest extends ActionTestCase
{
	private const TENANT2 = 'aaaaaaaa-0000-4000-8000-000000000002';
	private const DISABLED = 'aaaaaaaa-0000-4000-8000-000000000003';

	protected function action(): string
	{
		return 'domain-list';
	}

	protected function tables(): array
	{
		return array(
			'v_domains' => array(
				array('domain_uuid' => self::TENANT2, 'domain_parent_uuid' => null, 'domain_name' => 'tenant2.example.com', 'domain_enabled' => 'true', 'domain_description' => 'second'),
				array('domain_uuid' => self::DOMAIN_UUID, 'domain_parent_uuid' => null, 'domain_name' => 'tenant1.example.com', 'domain_enabled' => 'true', 'domain_description' => 'first'),
				array('domain_uuid' => self::DISABLED, 'domain_parent_uuid' => null, 'domain_name' => 'old.example.com', 'domain_enabled' => 'false', 'domain_description' => ''),
			),
		);
	}

	private function list(array $body = array(), bool $cross_domain = false): array
	{
		return $this->runAction($body + array('domain_uuid' => self::DOMAIN_UUID), array('domain_explicit' => false, 'cross_domain' => $cross_domain));
	}

	public function testListsOnlyTheUsersDomainWithoutDomainSelect(): void
	{
		$this->assertSame(array(
			'data' => array(
				array('domain_uuid' => self::DOMAIN_UUID, 'domain_name' => 'tenant1.example.com', 'domain_enabled' => true),
			),
			'pagination' => array('page' => 1, 'per_page' => 25, 'total' => 1),
		), $this->list());
	}

	// disabled domains are listed too, so an administrator can see them
	public function testListsEveryDomainByNameWithDomainSelect(): void
	{
		$this->assertSame(array(
			'data' => array(
				array('domain_uuid' => self::DISABLED, 'domain_name' => 'old.example.com', 'domain_enabled' => false),
				array('domain_uuid' => self::DOMAIN_UUID, 'domain_name' => 'tenant1.example.com', 'domain_enabled' => true),
				array('domain_uuid' => self::TENANT2, 'domain_name' => 'tenant2.example.com', 'domain_enabled' => true),
			),
			'pagination' => array('page' => 1, 'per_page' => 25, 'total' => 3),
		), $this->list(array(), true));
	}

	// Postgres returns domain_enabled as a PHP boolean
	public function testReadsABooleanDomainEnabled(): void
	{
		\FakeStore::update(function (&$state) {
			$state['tables']['v_domains'][1]['domain_enabled'] = true;
		});

		$this->assertTrue($this->list()['data'][0]['domain_enabled']);
	}

	public function testPaginates(): void
	{
		$second = $this->list(array('page' => 2, 'per_page' => 2), true);
		$beyond = $this->list(array('page' => '3', 'per_page' => '2'), true);

		$this->assertSame(array(self::TENANT2), array_column($second['data'], 'domain_uuid'));
		$this->assertSame(array('page' => 2, 'per_page' => 2, 'total' => 3), $second['pagination']);
		$this->assertSame(array(), $beyond['data']);
		$this->assertSame(array('page' => 3, 'per_page' => 2, 'total' => 3), $beyond['pagination']);
	}

	// past the last page the count is enough
	public function testOnlyCountsPastTheLastPage(): void
	{
		$this->list(array('page' => 1000000, 'per_page' => 200), true);

		$queries = $this->state()['queries'];
		$this->assertCount(1, $queries);
		$this->assertStringStartsWith('SELECT COUNT(*)', $queries[0]['sql']);
	}

	public static function invalidParameters(): array
	{
		return array(
			array('page', 0),
			array('page', '1.5'),
			array('page', 1000001),
			array('per_page', 0),
			array('per_page', 201),
			array('per_page', 'all'),
		);
	}

	#[DataProvider('invalidParameters')]
	public function testRejectsAnInvalidParameter(string $name, $value): void
	{
		$this->assertSame(array('error' => 'invalid '.$name, 'code' => 400), $this->list(array($name => $value), true));
	}

	// FusionPBX's select() returns false on a database error. an empty list
	// would look like a user without domains
	public function testAnswers500WhenTheDatabaseFails(): void
	{
		$this->failSelects();

		$this->assertSame(array('error' => 'database error', 'code' => 500), $this->list(array(), true));
	}
}
