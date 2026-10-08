<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

/**
 * extension-list (#43974): ZuluCall's listExtensions, paginated Extension
 * objects like extension-user-list returns.
 */
#[RunTestsInSeparateProcesses]
class ExtensionListTest extends ActionTestCase
{
	private const EXT_100 = 'eeeeeeee-0000-4000-8000-000000000100';
	private const EXT_101 = 'eeeeeeee-0000-4000-8000-000000000101';
	private const EXT_1000 = 'eeeeeeee-0000-4000-8000-000000001000';
	private const EXT_OTHER = 'eeeeeeee-0000-4000-8000-000000000200';
	private const AGENT = 'dddddddd-0000-4000-8000-000000000010';
	private const COLLEAGUE = 'dddddddd-0000-4000-8000-000000000011';
	private const OTHER_DOMAIN_UUID = 'aaaaaaaa-0000-4000-8000-000000000002';

	protected function action(): string
	{
		return 'extension-list';
	}

	protected function tables(): array
	{
		$d1 = self::DOMAIN_UUID;
		return parent::tables() + array(
			'v_extensions' => array(
				array('extension_uuid' => self::EXT_1000, 'domain_uuid' => $d1, 'extension' => '1000', 'password' => 'sip-secret', 'directory_first_name' => null, 'directory_last_name' => null, 'emergency_caller_id_number' => '', 'outbound_caller_id_number' => null, 'enabled' => 'false'),
				array('extension_uuid' => self::EXT_101, 'domain_uuid' => $d1, 'extension' => '101', 'password' => 'sip-secret', 'directory_first_name' => 'Ana', 'directory_last_name' => 'Ruiz', 'emergency_caller_id_number' => '911', 'outbound_caller_id_number' => '+15551230101', 'enabled' => 'true'),
				array('extension_uuid' => self::EXT_100, 'domain_uuid' => $d1, 'extension' => '100', 'password' => 'sip-secret', 'directory_first_name' => 'Reception', 'directory_last_name' => '', 'emergency_caller_id_number' => '911', 'outbound_caller_id_number' => '+15551230100', 'enabled' => 'true'),
				array('extension_uuid' => self::EXT_OTHER, 'domain_uuid' => self::OTHER_DOMAIN_UUID, 'extension' => '200', 'password' => 'sip-secret', 'enabled' => 'true'),
			),
			'v_extension_users' => array(
				// 101 is shared by two users: the one with the lowest uuid is shown
				array('extension_user_uuid' => 'ffffffff-0000-4000-8000-000000000001', 'domain_uuid' => $d1, 'extension_uuid' => self::EXT_101, 'user_uuid' => self::COLLEAGUE),
				array('extension_user_uuid' => 'ffffffff-0000-4000-8000-000000000002', 'domain_uuid' => $d1, 'extension_uuid' => self::EXT_101, 'user_uuid' => self::AGENT),
				array('extension_user_uuid' => 'ffffffff-0000-4000-8000-000000000003', 'domain_uuid' => $d1, 'extension_uuid' => self::EXT_100, 'user_uuid' => self::COLLEAGUE),
			),
		);
	}

	private function list(array $body = array(), string $domain_uuid = self::DOMAIN_UUID): array
	{
		return $this->runAction($body + array('domain_uuid' => $domain_uuid));
	}

	// sorted by number as text, like extension-user-list
	public function testListsTheExtensionsOfTheDomainByNumber(): void
	{
		$this->assertSame(array(
			'data' => array(
				array('extension_uuid' => self::EXT_100, 'extension' => '100', 'domain_uuid' => self::DOMAIN_UUID, 'directory_first_name' => 'Reception', 'directory_last_name' => '', 'emergency_caller_id_number' => '911', 'outbound_caller_id_number' => '+15551230100', 'enabled' => true, 'user_uuid' => self::COLLEAGUE),
				array('extension_uuid' => self::EXT_1000, 'extension' => '1000', 'domain_uuid' => self::DOMAIN_UUID, 'directory_first_name' => null, 'directory_last_name' => null, 'emergency_caller_id_number' => '', 'outbound_caller_id_number' => null, 'enabled' => false, 'user_uuid' => null),
				array('extension_uuid' => self::EXT_101, 'extension' => '101', 'domain_uuid' => self::DOMAIN_UUID, 'directory_first_name' => 'Ana', 'directory_last_name' => 'Ruiz', 'emergency_caller_id_number' => '911', 'outbound_caller_id_number' => '+15551230101', 'enabled' => true, 'user_uuid' => self::AGENT),
			),
			'pagination' => array('page' => 1, 'per_page' => 25, 'total' => 3),
		), $this->list());
	}

	public function testOnlyListsExtensionsOfTheRequestedDomain(): void
	{
		$this->assertSame(array(self::EXT_OTHER), array_column($this->list(array(), self::OTHER_DOMAIN_UUID)['data'], 'extension_uuid'));
	}

	public function testNeverReturnsTheSipPassword(): void
	{
		foreach ($this->list()['data'] as $extension) {
			$this->assertArrayNotHasKey('password', $extension);
		}
	}

	// Postgres returns enabled as a PHP boolean
	public function testReadsABooleanEnabled(): void
	{
		\FakeStore::update(function (&$state) {
			$state['tables']['v_extensions'][0]['enabled'] = true;
			$state['tables']['v_extensions'][1]['enabled'] = false;
		});

		$this->assertSame(array(true, true, false), array_column($this->list()['data'], 'enabled'));
	}

	public function testPaginates(): void
	{
		$second = $this->list(array('page' => 2, 'per_page' => 2));
		$beyond = $this->list(array('page' => '3', 'per_page' => '2'));

		$this->assertSame(array(self::EXT_101), array_column($second['data'], 'extension_uuid'));
		$this->assertSame(array(self::AGENT), array_column($second['data'], 'user_uuid'));
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
	// would look like a domain without extensions
	public function testAnswers500WhenTheDatabaseFails(): void
	{
		$this->failSelects();

		$this->assertSame(array('error' => 'database error', 'code' => 500), $this->list());
	}
}
