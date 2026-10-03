<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

#[RunTestsInSeparateProcesses]
class ExtensionCreateTest extends ActionTestCase
{
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
		);
	}

	public function testCreatesTheExtensionWithVoicemailAndCallerId(): void
	{
		$result = $this->runAction(array('domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'extension' => '101', 'caller_id_name' => 'Sales', 'caller_id_number' => '5551000'));

		$tables = $this->state()['tables'];
		$this->assertSame($tables['v_extensions'][1], $result);
		$this->assertSame('101', $result['extension']);
		$this->assertSame('tenant1.example.com', $result['user_context']);
		$this->assertSame(array('Sales', '5551000'), array($result['outbound_caller_id_name'], $result['outbound_caller_id_number']));
		$this->assertSame(10, strlen($result['password']));
		$this->assertSame('101', $tables['v_voicemails'][0]['voicemail_id']);
		$this->assertSame(array(), $this->state()['skipped'], 'the declared permissions must cover every saved table');
	}

	public function testRejectsAnExistingExtension(): void
	{
		$result = $this->runAction(array('domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'extension' => '100'));

		$this->assertSame(array('error' => 'extension already exists'), $result);
		$this->assertSame(array(), $this->state()['saved']);
	}

	public function testRejectsAnUnknownDomain(): void
	{
		$result = $this->runAction(array('domain_uuid' => 'aaaaaaaa-0000-4000-8000-00000000ffff', 'extension' => '101'));

		$this->assertSame(array('error' => 'domain not found'), $result);
		$this->assertSame(array(), $this->state()['saved']);
	}

	public function testReportsAFailedSave(): void
	{
		$this->failSaves();

		$this->assertSame(array('error' => 'error adding extension'), $this->runAction(array('domain_uuid' => 'aaaaaaaa-0000-4000-8000-000000000001', 'extension' => '101')));
		$this->assertCount(1, $this->state()['tables']['v_extensions']);
	}
}
