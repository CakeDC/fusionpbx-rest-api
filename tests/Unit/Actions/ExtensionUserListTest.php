<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

#[RunTestsInSeparateProcesses]
class ExtensionUserListTest extends ActionTestCase
{
	private const AGENT = 'dddddddd-0000-4000-8000-000000000010';
	private const AGENT_WITHOUT_EXTENSION = 'dddddddd-0000-4000-8000-000000000011';
	private const COLLEAGUE = 'dddddddd-0000-4000-8000-000000000012';
	private const OTHER_DOMAIN_AGENT = 'dddddddd-0000-4000-8000-000000000020';
	private const OTHER_DOMAIN_UUID = 'aaaaaaaa-0000-4000-8000-000000000002';

	protected function action(): string
	{
		return 'extension-user-list';
	}

	protected function tables(): array
	{
		return parent::tables() + array(
			'v_users' => array(
				array('user_uuid' => self::AGENT, 'domain_uuid' => self::DOMAIN_UUID, 'username' => 'agent'),
				array('user_uuid' => self::AGENT_WITHOUT_EXTENSION, 'domain_uuid' => self::DOMAIN_UUID, 'username' => 'newcomer'),
				array('user_uuid' => self::COLLEAGUE, 'domain_uuid' => self::DOMAIN_UUID, 'username' => 'colleague'),
				array('user_uuid' => self::OTHER_DOMAIN_AGENT, 'domain_uuid' => self::OTHER_DOMAIN_UUID, 'username' => 'agent'),
			),
			'v_extensions' => array(
				array('extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000102', 'domain_uuid' => self::DOMAIN_UUID, 'extension' => '102', 'password' => 'sip-secret', 'directory_first_name' => 'Ana', 'directory_last_name' => 'Ruiz', 'emergency_caller_id_number' => '911', 'outbound_caller_id_number' => '+15551230102', 'enabled' => 'false'),
				array('extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000101', 'domain_uuid' => self::DOMAIN_UUID, 'extension' => '101', 'password' => 'sip-secret', 'directory_first_name' => 'Ana', 'directory_last_name' => 'Ruiz', 'emergency_caller_id_number' => '911', 'outbound_caller_id_number' => '+15551230101', 'enabled' => 'true'),
				array('extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000103', 'domain_uuid' => self::DOMAIN_UUID, 'extension' => '103', 'password' => 'sip-secret', 'enabled' => 'true'),
				array('extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000201', 'domain_uuid' => self::OTHER_DOMAIN_UUID, 'extension' => '201', 'password' => 'sip-secret', 'enabled' => 'true'),
			),
			'v_extension_users' => array(
				array('extension_user_uuid' => 'ffffffff-0000-4000-8000-000000000001', 'domain_uuid' => self::DOMAIN_UUID, 'extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000102', 'user_uuid' => self::AGENT),
				array('extension_user_uuid' => 'ffffffff-0000-4000-8000-000000000002', 'domain_uuid' => self::DOMAIN_UUID, 'extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000101', 'user_uuid' => self::AGENT),
				array('extension_user_uuid' => 'ffffffff-0000-4000-8000-000000000003', 'domain_uuid' => self::DOMAIN_UUID, 'extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000103', 'user_uuid' => self::COLLEAGUE),
				array('extension_user_uuid' => 'ffffffff-0000-4000-8000-000000000004', 'domain_uuid' => self::OTHER_DOMAIN_UUID, 'extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000201', 'user_uuid' => self::OTHER_DOMAIN_AGENT),
			),
		);
	}

	public function testReturnsEveryExtensionOfTheUserSortedByNumber(): void
	{
		$result = $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'user_uuid' => self::AGENT));

		$this->assertSame(array('data' => array(
			array(
				'extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000101',
				'extension' => '101',
				'domain_uuid' => self::DOMAIN_UUID,
				'directory_first_name' => 'Ana',
				'directory_last_name' => 'Ruiz',
				'emergency_caller_id_number' => '911',
				'outbound_caller_id_number' => '+15551230101',
				'enabled' => true,
				'user_uuid' => self::AGENT,
			),
			array(
				'extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000102',
				'extension' => '102',
				'domain_uuid' => self::DOMAIN_UUID,
				'directory_first_name' => 'Ana',
				'directory_last_name' => 'Ruiz',
				'emergency_caller_id_number' => '911',
				'outbound_caller_id_number' => '+15551230102',
				'enabled' => false,
				'user_uuid' => self::AGENT,
			),
		)), $result);
	}

	public function testReturnsAnEmptyListForAUserWithoutExtensions(): void
	{
		$result = $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'user_uuid' => self::AGENT_WITHOUT_EXTENSION));

		$this->assertSame(array('data' => array()), $result);
	}

	public function testDoesNotFindAUserOfAnotherDomain(): void
	{
		$result = $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'user_uuid' => self::OTHER_DOMAIN_AGENT));

		$this->assertSame(array('error' => 'user not found', 'code' => 404), $result);
	}

	public function testDoesNotFindAnUnknownUser(): void
	{
		$result = $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'user_uuid' => 'dddddddd-0000-4000-8000-000000000099'));

		$this->assertSame(array('error' => 'user not found', 'code' => 404), $result);
	}

	// lower-cased like domain_uuid, as in user-details
	public function testFindsTheUserByAnUpperCaseUuid(): void
	{
		$result = $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'user_uuid' => strtoupper(self::AGENT)));

		$this->assertSame(array('101', '102'), array_column($result['data'], 'extension'));
		$this->assertSame(array(self::AGENT, self::AGENT), array_column($result['data'], 'user_uuid'));
	}

	public function testRejectsAMalformedUserUuid(): void
	{
		foreach (array('not-a-uuid', '', array(self::AGENT), 42) as $user_uuid) {
			$result = $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'user_uuid' => $user_uuid));

			$this->assertSame(array('error' => 'invalid user_uuid', 'code' => 400), $result, json_encode($user_uuid));
		}
	}

	// v_extension_users keeps its own domain_uuid; an extension linked from
	// another domain must not show up
	public function testIgnoresLinksToExtensionsOfAnotherDomain(): void
	{
		\FakeStore::update(function (&$state) {
			$state['tables']['v_extension_users'][] = array('extension_user_uuid' => 'ffffffff-0000-4000-8000-000000000005', 'domain_uuid' => self::DOMAIN_UUID, 'extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000201', 'user_uuid' => self::AGENT_WITHOUT_EXTENSION);
		});

		$result = $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'user_uuid' => self::AGENT_WITHOUT_EXTENSION));

		$this->assertSame(array('data' => array()), $result);
	}

	// v_extension_users has no unique (user, extension) constraint
	public function testReturnsAnExtensionLinkedTwiceOnce(): void
	{
		\FakeStore::update(function (&$state) {
			$state['tables']['v_extension_users'][] = array('extension_user_uuid' => 'ffffffff-0000-4000-8000-000000000006', 'domain_uuid' => self::DOMAIN_UUID, 'extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000101', 'user_uuid' => self::AGENT);
		});

		$result = $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'user_uuid' => self::AGENT));

		$this->assertSame(array('101', '102'), array_column($result['data'], 'extension'));
	}

	public function testAnswersServerErrorWhenTheDatabaseFails(): void
	{
		$this->failSelects();

		$result = $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'user_uuid' => self::AGENT));

		$this->assertSame(array('error' => 'database error', 'code' => 500), $result);
	}

	// enabled is a boolean in the response, whether the column is text or boolean
	public function testReturnsEnabledAsABoolean(): void
	{
		foreach (array(array('true', true), array('false', false), array('t', true), array('f', false), array(true, true), array(false, false), array(null, false)) as list($stored, $enabled)) {
			\FakeStore::update(function (&$state) use ($stored) {
				$state['tables']['v_extensions'][1]['enabled'] = $stored;
			});

			$result = $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'user_uuid' => self::AGENT));

			$this->assertSame($enabled, $result['data'][0]['enabled'], var_export($stored, true));
		}
	}
}
