<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

/**
 * extension-delete deletes what 5.6.5's
 * extension class deletes with "delete extension and voicemail".
 */
#[RunTestsInSeparateProcesses]
class ExtensionDeleteTest extends ActionTestCase
{
	private const EXT_100 = 'eeeeeeee-0000-4000-8000-000000000100';
	private const EXT_101 = 'eeeeeeee-0000-4000-8000-000000000101';
	private const EXT_OTHER = 'eeeeeeee-0000-4000-8000-000000000200';
	private const FOLLOW_ME = 'f0000000-0000-4000-8000-000000000100';
	private const OTHER_FOLLOW_ME = 'f0000000-0000-4000-8000-000000000101';
	private const VM_100 = 'c0000000-0000-4000-8000-000000000100';
	private const VM_1100 = 'c0000000-0000-4000-8000-000000001100';
	private const VM_101 = 'c0000000-0000-4000-8000-000000000101';
	private const VM_OTHER = 'c0000000-0000-4000-8000-000000000200';
	private const OTHER_DOMAIN_UUID = 'aaaaaaaa-0000-4000-8000-000000000002';

	private string $voicemailDir;

	protected function action(): string
	{
		return 'extension-delete';
	}

	protected function tables(): array
	{
		$d1 = self::DOMAIN_UUID;
		$d2 = self::OTHER_DOMAIN_UUID;
		return parent::tables() + array(
			'v_extensions' => array(
				array('extension_uuid' => self::EXT_100, 'domain_uuid' => $d1, 'extension' => '100', 'number_alias' => '1100', 'user_context' => 'tenant1.example.com', 'follow_me_uuid' => self::FOLLOW_ME),
				array('extension_uuid' => self::EXT_101, 'domain_uuid' => $d1, 'extension' => '101', 'number_alias' => '', 'user_context' => 'tenant1.example.com', 'follow_me_uuid' => self::OTHER_FOLLOW_ME),
				array('extension_uuid' => self::EXT_OTHER, 'domain_uuid' => $d2, 'extension' => '100', 'number_alias' => '', 'user_context' => 'tenant2.example.com', 'follow_me_uuid' => null),
			),
			'v_extension_users' => array(
				array('extension_user_uuid' => 'l1', 'domain_uuid' => $d1, 'extension_uuid' => self::EXT_100, 'user_uuid' => 'u1'),
				array('extension_user_uuid' => 'l2', 'domain_uuid' => $d1, 'extension_uuid' => self::EXT_101, 'user_uuid' => 'u1'),
			),
			'v_follow_me' => array(
				array('follow_me_uuid' => self::FOLLOW_ME, 'domain_uuid' => $d1),
				array('follow_me_uuid' => self::OTHER_FOLLOW_ME, 'domain_uuid' => $d1),
			),
			'v_follow_me_destinations' => array(
				array('follow_me_destination_uuid' => 'fd1', 'follow_me_uuid' => self::FOLLOW_ME, 'follow_me_destination' => '5551234'),
				array('follow_me_destination_uuid' => 'fd2', 'follow_me_uuid' => self::OTHER_FOLLOW_ME, 'follow_me_destination' => '5551234'),
			),
			// ring groups stop dialing the deleted number and its alias
			'v_ring_group_destinations' => array(
				array('ring_group_destination_uuid' => 'r1', 'domain_uuid' => $d1, 'destination_number' => '100'),
				array('ring_group_destination_uuid' => 'r2', 'domain_uuid' => $d1, 'destination_number' => '1100'),
				array('ring_group_destination_uuid' => 'r3', 'domain_uuid' => $d1, 'destination_number' => '101'),
				array('ring_group_destination_uuid' => 'r4', 'domain_uuid' => $d2, 'destination_number' => '100'),
			),
			'v_extension_settings' => array(
				array('extension_setting_uuid' => 's1', 'domain_uuid' => $d1, 'extension_uuid' => self::EXT_100),
				array('extension_setting_uuid' => 's2', 'domain_uuid' => $d1, 'extension_uuid' => self::EXT_101),
			),
			// the boxes of the extension and of its numeric alias go too
			'v_voicemails' => array(
				array('voicemail_uuid' => self::VM_100, 'domain_uuid' => $d1, 'voicemail_id' => '100'),
				array('voicemail_uuid' => self::VM_1100, 'domain_uuid' => $d1, 'voicemail_id' => '1100'),
				array('voicemail_uuid' => self::VM_101, 'domain_uuid' => $d1, 'voicemail_id' => '101'),
				array('voicemail_uuid' => self::VM_OTHER, 'domain_uuid' => $d2, 'voicemail_id' => '100'),
			),
			'v_voicemail_options' => array(
				array('voicemail_option_uuid' => 'o1', 'domain_uuid' => $d1, 'voicemail_uuid' => self::VM_100),
				array('voicemail_option_uuid' => 'o2', 'domain_uuid' => $d1, 'voicemail_uuid' => self::VM_101),
			),
			'v_voicemail_messages' => array(
				array('voicemail_message_uuid' => 'm1', 'domain_uuid' => $d1, 'voicemail_uuid' => self::VM_100),
				array('voicemail_message_uuid' => 'm2', 'domain_uuid' => $d2, 'voicemail_uuid' => self::VM_OTHER),
			),
			// a box that copies its messages to the deleted one stops doing so
			'v_voicemail_destinations' => array(
				array('voicemail_destination_uuid' => 'vd1', 'domain_uuid' => $d1, 'voicemail_uuid' => self::VM_100, 'voicemail_uuid_copy' => self::VM_101),
				array('voicemail_destination_uuid' => 'vd2', 'domain_uuid' => $d1, 'voicemail_uuid' => self::VM_101, 'voicemail_uuid_copy' => self::VM_100),
				array('voicemail_destination_uuid' => 'vd3', 'domain_uuid' => $d1, 'voicemail_uuid' => self::VM_101, 'voicemail_uuid_copy' => 'c0000000-0000-4000-8000-000000000999'),
			),
			'v_voicemail_greetings' => array(
				array('voicemail_greeting_uuid' => 'g1', 'domain_uuid' => $d1, 'voicemail_id' => '100'),
				array('voicemail_greeting_uuid' => 'g2', 'domain_uuid' => $d2, 'voicemail_id' => '100'),
			),
		);
	}

	protected function setUp(): void
	{
		parent::setUp();
		$this->voicemailDir = sys_get_temp_dir().'/rest_api_voicemail_'.getmypid();
		foreach (array('tenant1.example.com/100', 'tenant1.example.com/101', 'tenant2.example.com/100') as $box) {
			mkdir($this->voicemailDir.'/default/'.$box, 0777, true);
			file_put_contents($this->voicemailDir.'/default/'.$box.'/msg_1.wav', 'audio');
		}
		\FakeStore::update(function (&$state) {
			$state['settings']['switch']['voicemail'] = $this->voicemailDir;
		});
	}

	protected function tearDown(): void
	{
		exec('chmod -R u+w '.escapeshellarg($this->voicemailDir).' && rm -rf '.escapeshellarg($this->voicemailDir));
	}

	private function delete(string $extension_uuid = self::EXT_100): array
	{
		return $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'extension_uuid' => $extension_uuid));
	}

	private function ids(string $table, string $column): array
	{
		return array_column($this->state()['tables'][$table], $column);
	}

	public function testAnswersNoContent(): void
	{
		$this->assertSame(array('code' => 204), $this->delete());
	}

	public function testDeletesTheExtensionAndWhatFusionPbxDeletesWithIt(): void
	{
		$this->delete();

		$this->assertSame(array(self::EXT_101, self::EXT_OTHER), $this->ids('v_extensions', 'extension_uuid'));
		$this->assertSame(array('l2'), $this->ids('v_extension_users', 'extension_user_uuid'));
		$this->assertSame(array(self::OTHER_FOLLOW_ME), $this->ids('v_follow_me', 'follow_me_uuid'));
		$this->assertSame(array('fd2'), $this->ids('v_follow_me_destinations', 'follow_me_destination_uuid'));
		$this->assertSame(array('r3', 'r4'), $this->ids('v_ring_group_destinations', 'ring_group_destination_uuid'));
		$this->assertSame(array('s2'), $this->ids('v_extension_settings', 'extension_setting_uuid'));
	}

	public function testDeletesTheVoicemailBoxes(): void
	{
		$this->delete();

		$this->assertSame(array(self::VM_101, self::VM_OTHER), $this->ids('v_voicemails', 'voicemail_uuid'));
		$this->assertSame(array('o2'), $this->ids('v_voicemail_options', 'voicemail_option_uuid'));
		$this->assertSame(array('m2'), $this->ids('v_voicemail_messages', 'voicemail_message_uuid'));
		$this->assertSame(array('vd3'), $this->ids('v_voicemail_destinations', 'voicemail_destination_uuid'));
		$this->assertSame(array('g2'), $this->ids('v_voicemail_greetings', 'voicemail_greeting_uuid'));
	}

	// the rows are already gone, so the extension is deleted; a file left
	// behind is logged rather than lost silently
	public function testLogsVoicemailFilesItCantRemove(): void
	{
		$this->skipAsRoot();
		$box = $this->voicemailDir.'/default/tenant1.example.com/100';
		chmod($box, 0555);
		$log = tempnam(sys_get_temp_dir(), 'rest_api_log');
		$previous = ini_set('error_log', $log);
		try {
			$result = $this->delete();
		} finally {
			ini_set('error_log', $previous);
		}
		$logged = file_get_contents($log);
		unlink($log);

		$this->assertSame(array('code' => 204), $result);
		$this->assertFileExists($box.'/msg_1.wav');
		$this->assertStringContainsString('could not remove '.$box.'/msg_1.wav', $logged);
		$this->assertStringContainsString('could not remove '.$box, $logged);
	}

	public function testDeletesTheVoicemailFilesOfTheDomainOnly(): void
	{
		$this->delete();

		$this->assertDirectoryDoesNotExist($this->voicemailDir.'/default/tenant1.example.com/100');
		$this->assertFileExists($this->voicemailDir.'/default/tenant1.example.com/101/msg_1.wav');
		$this->assertFileExists($this->voicemailDir.'/default/tenant2.example.com/100/msg_1.wav');
	}

	// voicemail ids are only built from numeric extensions, as in FusionPBX,
	// so no other value reaches a file path
	public function testLeavesTheVoicemailOfANonNumericExtension(): void
	{
		\FakeStore::update(function (&$state) {
			$state['tables']['v_extensions'][0]['extension'] = 'reception';
			$state['tables']['v_extensions'][0]['number_alias'] = '';
		});

		$this->assertSame(array('code' => 204), $this->delete());
		$this->assertContains(self::VM_100, $this->ids('v_voicemails', 'voicemail_uuid'));
		$this->assertFileExists($this->voicemailDir.'/default/tenant1.example.com/100/msg_1.wav');
	}

	public function testClearsTheCachedDirectoryEntries(): void
	{
		$this->delete();

		$this->assertSame(array('directory:100@tenant1.example.com', 'directory:1100@tenant1.example.com'), $this->state()['cache_deleted']);
	}

	public function testAnswersNotFoundForAnExtensionOfAnotherDomainOrAnUnknownOne(): void
	{
		foreach (array(self::EXT_OTHER, 'eeeeeeee-0000-4000-8000-00000000ffff') as $extension_uuid) {
			$this->assertSame(array('error' => 'extension not found', 'code' => 404), $this->delete($extension_uuid));
		}
		$this->assertSame(array(self::EXT_100, self::EXT_101, self::EXT_OTHER), $this->ids('v_extensions', 'extension_uuid'));
		$this->assertFileExists($this->voicemailDir.'/default/tenant2.example.com/100/msg_1.wav');
	}

	public function testASecondDeleteAnswersNotFound(): void
	{
		$this->delete();

		$this->assertSame(array('error' => 'extension not found', 'code' => 404), $this->delete());
	}

	public function testRejectsAMalformedExtensionUuid(): void
	{
		foreach (array('100', array(self::EXT_100), 100) as $extension_uuid) {
			$this->assertSame(array('error' => 'invalid extension_uuid', 'code' => 400), $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'extension_uuid' => $extension_uuid)));
		}
	}

	public function testAnswers500WhenTheDatabaseFails(): void
	{
		$this->failSelects();

		$this->assertSame(array('error' => 'database error', 'code' => 500), $this->delete());
	}

	// the files stay when the rows couldn't be deleted
	public function testAnswers500WhenTheDeleteFails(): void
	{
		\FakeStore::update(function (&$state) {
			$state['delete_fails'] = true;
		});

		$this->assertSame(array('error' => 'error deleting extension', 'code' => 500), $this->delete());
		$this->assertFileExists($this->voicemailDir.'/default/tenant1.example.com/100/msg_1.wav');
		$this->assertSame(array(), $this->state()['cache_deleted']);
	}
}
