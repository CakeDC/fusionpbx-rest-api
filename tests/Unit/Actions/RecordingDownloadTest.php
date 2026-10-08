<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

/**
 * recording-download: the audio of a call recording, at the leg's
 * record_path/record_name as FusionPBX's Call Recordings app reads it. rest.php
 * streams the file the action names (see RestRecordingDownloadTest).
 */
#[RunTestsInSeparateProcesses]
class RecordingDownloadTest extends ActionTestCase
{
	private const RECORDED = 'c0000001-0000-4000-8000-000000000001';
	private const NOT_RECORDED = 'c0000001-0000-4000-8000-000000000002';
	private const OTHER = 'c0000001-0000-4000-8000-000000000020';
	private const OTHER_DOMAIN_UUID = 'aaaaaaaa-0000-4000-8000-000000000002';

	private string $recordings;
	private string $day;

	protected function action(): string
	{
		return 'recording-download';
	}

	private static function leg(string $uuid, array $columns): array
	{
		return $columns + array(
			'xml_cdr_uuid' => $uuid,
			'domain_uuid' => self::DOMAIN_UUID,
			'start_stamp' => '2026-09-01 09:00:00+00',
			'duration' => 95,
			'hangup_cause' => 'NORMAL_CLEARING',
			'record_name' => null,
			'record_path' => null,
		);
	}

	protected function setUp(): void
	{
		parent::setUp();
		$this->recordings = sys_get_temp_dir().'/rest_api_recordings_'.getmypid();
		$this->day = $this->recordings.'/tenant1.example.com/archive/2026/Sep/01';
		mkdir($this->day, 0777, true);
		file_put_contents($this->day.'/c1.wav', 'RIFF-audio');
		file_put_contents($this->day.'/c2.mp3', 'ID3-audio');
		file_put_contents($this->recordings.'/../rest_api_secret_'.getmypid(), 'secret');
		$recordings = $this->recordings;
		$day = $this->day;
		\FakeStore::update(function (&$state) use ($recordings, $day) {
			$state['settings']['switch']['recordings'] = $recordings;
			$state['tables']['v_xml_cdr'] = array(
				self::leg(self::RECORDED, array('record_name' => 'c1.wav', 'record_path' => $day)),
				self::leg(self::NOT_RECORDED, array()),
				self::leg(self::OTHER, array('domain_uuid' => self::OTHER_DOMAIN_UUID, 'record_name' => 'c1.wav', 'record_path' => $day)),
			);
		});
	}

	protected function tearDown(): void
	{
		exec('chmod -R u+rw '.escapeshellarg($this->recordings).' && rm -rf '.escapeshellarg($this->recordings));
		@unlink($this->recordings.'/../rest_api_secret_'.getmypid());
	}

	private function recorded(string $name, string $path): void
	{
		\FakeStore::update(function (&$state) use ($name, $path) {
			$state['tables']['v_xml_cdr'][0]['record_name'] = $name;
			$state['tables']['v_xml_cdr'][0]['record_path'] = $path;
		});
	}

	private function download($recording_id = self::RECORDED): array
	{
		return $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'recording_id' => $recording_id));
	}

	/** The action's result and what it wrote to the error log. */
	private function downloadLogged(): array
	{
		$log = tempnam(sys_get_temp_dir(), 'rest_api_log');
		$previous = ini_set('error_log', $log);
		try {
			$result = $this->download();
		} finally {
			ini_set('error_log', $previous);
		}
		$logged = file_get_contents($log);
		unlink($log);
		return array($result, $logged);
	}

	public function testNamesTheFileForRestPhpToStream(): void
	{
		$this->assertSame(
			array('send_file' => array('path' => realpath($this->day.'/c1.wav'), 'content_type' => 'audio/wav', 'name' => 'c1.wav')),
			$this->download()
		);
	}

	public static function contentTypes(): array
	{
		return array('mp3' => array('c2.mp3', 'audio/mpeg'), 'upper case' => array('C3.WAV', 'audio/wav'), 'other' => array('c4.ogg', 'application/octet-stream'));
	}

	#[DataProvider('contentTypes')]
	public function testSendsTheContentTypeOfTheFile(string $name, string $type): void
	{
		file_put_contents($this->day.'/'.$name, 'audio');
		$this->recorded($name, $this->day);

		$this->assertSame($type, $this->download()['send_file']['content_type']);
	}

	public static function pathsOutsideTheRecordings(): array
	{
		return array(
			'a record_path elsewhere' => array('rest_api_secret_%pid%', '%recordings%/..'),
			'a record_name climbing out' => array('../../../../../../rest_api_secret_%pid%', '%day%'),
		);
	}

	// record_path and record_name come from the call's data: a file outside
	// FusionPBX's recordings directory is never read
	#[DataProvider('pathsOutsideTheRecordings')]
	public function testNeverReadsAFileOutsideTheRecordingsDirectory(string $name, string $path): void
	{
		$replace = array('%pid%' => getmypid(), '%recordings%' => $this->recordings, '%day%' => $this->day);
		$this->recorded(strtr($name, $replace), strtr($path, $replace));

		list($result, $logged) = $this->downloadLogged();

		$this->assertSame(array('error' => 'recording not found', 'code' => 404), $result);
		$this->assertStringContainsString('outside the recordings directory', $logged);
	}

	// a symbolic link inside the directory to a file elsewhere is outside too
	public function testDoesNotFollowALinkOutOfTheRecordingsDirectory(): void
	{
		symlink($this->recordings.'/../rest_api_secret_'.getmypid(), $this->day.'/link.wav');
		$this->recorded('link.wav', $this->day);

		list($result) = $this->downloadLogged();

		$this->assertSame(array('error' => 'recording not found', 'code' => 404), $result);
	}

	public function testAnswersNotFoundWhenTheFileIsMissing(): void
	{
		unlink($this->day.'/c1.wav');

		$this->assertSame(array('error' => 'recording not found', 'code' => 404), $this->download());
	}

	public function testAnswers500WhenTheFileCantBeRead(): void
	{
		chmod($this->day.'/c1.wav', 0);

		$this->assertSame(array('error' => 'recording file can not be read', 'code' => 500), $this->download());
	}

	public function testAnswersNotFoundForALegWithoutARecordingOrOfAnotherDomain(): void
	{
		foreach (array(self::NOT_RECORDED, self::OTHER, 'c0000001-0000-4000-8000-00000000ffff') as $recording_id) {
			$this->assertSame(array('error' => 'recording not found', 'code' => 404), $this->download($recording_id), $recording_id);
		}
	}

	public function testRejectsAMalformedRecordingId(): void
	{
		$this->assertSame(array('error' => 'invalid recording_id', 'code' => 400), $this->download('c1.wav'));
	}

	// FusionPBX's installer puts recordings in /var/lib/freeswitch/recordings
	public function testUsesFusionPbxsDefaultRecordingsDirectoryWhenNotSet(): void
	{
		\FakeStore::update(function (&$state) {
			unset($state['settings']['switch']['recordings']);
		});

		list($result, $logged) = $this->downloadLogged();

		$this->assertSame(array('error' => 'recording not found', 'code' => 404), $result);
		$this->assertStringContainsString('/var/lib/freeswitch/recordings', $logged);
	}

	public function testAnswers500WhenTheDatabaseFails(): void
	{
		$this->failSelects();

		$this->assertSame(array('error' => 'database error', 'code' => 500), $this->download());
	}
}
