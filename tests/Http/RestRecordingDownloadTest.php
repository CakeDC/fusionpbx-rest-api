<?php
namespace RestApi\Test\Http;

use RestApi\Test\Support\RestApiTestCase;

/**
 * recording-download through rest.php: the file itself is streamed, with its
 * type and name, instead of a JSON body.
 */
class RestRecordingDownloadTest extends RestApiTestCase
{
	private const RECORDED = 'c0000001-0000-4000-8000-000000000001';

	private string $recordings;

	protected function setUp(): void
	{
		$this->recordings = sys_get_temp_dir().'/rest_api_http_recordings_'.getmypid();
		$day = $this->recordings.'/tenant1.example.com/archive/2026/Sep/01';
		@mkdir($day, 0777, true);
		// binary data, larger than PHP's output buffers
		file_put_contents($day.'/c1 "x".wav', str_repeat("RIFF\x00\xff", 50000));
		parent::setUp();
		$recordings = $this->recordings;
		\FakeStore::update(function (&$state) use ($recordings, $day) {
			$state['settings']['switch']['recordings'] = $recordings;
			$state['tables']['v_xml_cdr'] = array(array(
				'xml_cdr_uuid' => self::RECORDED, 'domain_uuid' => self::DOMAIN_UUID, 'start_stamp' => '2026-09-01 09:00:00+00',
				'duration' => 95, 'hangup_cause' => 'NORMAL_CLEARING', 'record_name' => 'c1 "x".wav', 'record_path' => $day,
			));
		});
	}

	protected function tearDown(): void
	{
		exec('rm -rf '.escapeshellarg($this->recordings));
		parent::tearDown();
	}

	public function testStreamsTheRecordingWithItsTypeAndName(): void
	{
		$response = $this->api(array('action' => 'recording-download', 'recording_id' => self::RECORDED));

		$this->assertSame(200, $response['status']);
		$this->assertSame(array('audio/wav'), $response['headers']['content-type']);
		$this->assertSame(array('300000'), $response['headers']['content-length']);
		// the name can't break out of the header's quotes
		$this->assertSame(array('attachment; filename="c1__x_.wav"'), $response['headers']['content-disposition']);
		$this->assertSame(str_repeat("RIFF\x00\xff", 50000), $response['body']);
	}

	public function testAnswersJsonWhenThereIsNoRecording(): void
	{
		$response = $this->api(array('action' => 'recording-download', 'recording_id' => 'c0000001-0000-4000-8000-00000000ffff'));

		$this->assertSame(404, $response['status']);
		$this->assertSame(array('error' => 'recording not found'), $this->json($response));
	}

	public function testNeedsCallRecordingDownload(): void
	{
		$this->grantOnly(array_values(array_diff(self::ACTION_PERMISSIONS, array('call_recording_download'))));

		$response = $this->api(array('action' => 'recording-download', 'recording_id' => self::RECORDED));

		$this->assertSame(403, $response['status']);
		$this->assertSame(array('error' => 'forbidden', 'missing_permissions' => array('call_recording_download')), $this->json($response));
	}
}
