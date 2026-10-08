<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;

/**
 * recording-details: a call recording's metadata. As in FusionPBX's Call
 * Recordings app (view_call_recordings), a recording is a recorded call leg
 * and its id is the leg's xml_cdr_uuid.
 */
#[RunTestsInSeparateProcesses]
class RecordingDetailsTest extends ActionTestCase
{
	private const RECORDED = 'c0000001-0000-4000-8000-000000000001';
	private const NOT_RECORDED = 'c0000001-0000-4000-8000-000000000002';
	private const LOST_RACE = 'c0000001-0000-4000-8000-000000000003';
	private const EMPTY_NAME = 'c0000001-0000-4000-8000-000000000004';
	private const OTHER = 'c0000001-0000-4000-8000-000000000020';
	private const OTHER_DOMAIN_UUID = 'aaaaaaaa-0000-4000-8000-000000000002';

	protected function action(): string
	{
		return 'recording-details';
	}

	private static function leg(string $uuid, array $columns): array
	{
		return $columns + array(
			'xml_cdr_uuid' => $uuid,
			'domain_uuid' => self::DOMAIN_UUID,
			'direction' => 'inbound',
			'caller_id_number' => '+15550001111',
			'destination_number' => '5000',
			'start_stamp' => '2026-09-01 09:00:00+00',
			'duration' => 95,
			'hangup_cause' => 'NORMAL_CLEARING',
			'record_name' => null,
			'record_path' => null,
		);
	}

	protected function tables(): array
	{
		$path = '/var/lib/freeswitch/recordings/tenant1.example.com/archive/2026/Sep/01';
		return parent::tables() + array(
			'v_xml_cdr' => array(
				self::leg(self::RECORDED, array('record_name' => 'c1.wav', 'record_path' => $path)),
				self::leg(self::NOT_RECORDED, array()),
				// the ring group legs that lost the race carry the winner's recording
				self::leg(self::LOST_RACE, array('record_name' => 'c1.wav', 'record_path' => $path, 'hangup_cause' => 'LOSE_RACE')),
				self::leg(self::EMPTY_NAME, array('record_name' => '', 'record_path' => '')),
				self::leg(self::OTHER, array('domain_uuid' => self::OTHER_DOMAIN_UUID, 'record_name' => 'x.wav', 'record_path' => '/var/lib/freeswitch/recordings/tenant2.example.com/archive/2026/Sep/01')),
			),
		);
	}

	private function details($recording_id): array
	{
		return $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'recording_id' => $recording_id));
	}

	public function testReturnsTheRecordingOfALeg(): void
	{
		$this->assertSame(array(
			'recording_id' => self::RECORDED,
			'domain_uuid' => self::DOMAIN_UUID,
			'filename' => 'c1.wav',
			'duration' => 95,
			'xml_cdr_uuid' => self::RECORDED,
			'created' => '2026-09-01 09:00:00+00',
		), $this->details(self::RECORDED));
	}

	public function testAnswersNotFoundForALegWithoutARecording(): void
	{
		foreach (array(self::NOT_RECORDED, self::LOST_RACE, self::EMPTY_NAME) as $recording_id) {
			$this->assertSame(array('error' => 'recording not found', 'code' => 404), $this->details($recording_id), $recording_id);
		}
	}

	public function testAnswersNotFoundForALegOfAnotherDomainOrAnUnknownOne(): void
	{
		foreach (array(self::OTHER, 'c0000001-0000-4000-8000-00000000ffff') as $recording_id) {
			$this->assertSame(array('error' => 'recording not found', 'code' => 404), $this->details($recording_id), $recording_id);
		}
	}

	public function testRejectsAMalformedRecordingId(): void
	{
		foreach (array('c1.wav', '', array(self::RECORDED), 42) as $recording_id) {
			$this->assertSame(array('error' => 'invalid recording_id', 'code' => 400), $this->details($recording_id), json_encode($recording_id));
		}
	}

	// FusionPBX stores uuids in lower case
	public function testFindsTheRecordingByAnUpperCaseId(): void
	{
		$this->assertSame(self::RECORDED, $this->details(strtoupper(self::RECORDED))['recording_id']);
	}

	// FusionPBX's select() returns false on a database error, which must not
	// look like a missing recording
	public function testAnswers500WhenTheDatabaseFails(): void
	{
		$this->failSelects();

		$this->assertSame(array('error' => 'database error', 'code' => 500), $this->details(self::RECORDED));
	}
}
