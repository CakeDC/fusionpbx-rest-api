<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

#[RunTestsInSeparateProcesses]
class ExtensionDetailsTest extends ActionTestCase
{
	protected function action(): string
	{
		return 'extension-details';
	}

	protected function tables(): array
	{
		return parent::tables() + array(
			'v_extensions' => array(
				array('extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000100', 'domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'extension' => '100', 'password' => 'sip-secret', 'effective_caller_id_name' => 'Front Desk', 'enabled' => 'true'),
			),
		);
	}

	public function testReturnsTheExtensionOfTheDomain(): void
	{
		$result = $this->runAction(array('domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000100'));

		$this->assertSame('100', $result['extension']);
		$this->assertSame('Front Desk', $result['effective_caller_id_name']);
		$this->assertArrayNotHasKey('password', $result);
		// a boolean, as extension-list returns it, whatever the column type
		$this->assertTrue($result['enabled']);
	}

	public function testDoesNotReturnAnExtensionFromAnotherDomain(): void
	{
		$result = $this->runAction(array('domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000002', 'extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000100'));

		$this->assertSame(array('error' => 'extension not found', 'code' => 404), $result);
	}

	public function testRejectsAnInvalidExtensionUuid(): void
	{
		$result = $this->runAction(array('domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'extension_uuid' => 'abc'));

		$this->assertSame(array('error' => 'invalid extension_uuid', 'code' => 400), $result);
	}

	// select() returns false on a database error: not a missing extension
	public function testAnswers500WhenTheDatabaseFails(): void
	{
		$this->failSelects();

		$result = $this->runAction(array('domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'extension_uuid' => 'eeeeeeee-0000-4000-8000-000000000100'));

		$this->assertSame(array('error' => 'database error', 'code' => 500), $result);
	}
}
