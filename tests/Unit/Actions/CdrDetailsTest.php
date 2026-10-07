<?php
namespace RestApi\Test\Unit\Actions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\ActionTestCase;
use RestApi\Test\Support\CdrSample as S;

#[RunTestsInSeparateProcesses]
class CdrDetailsTest extends ActionTestCase
{
	protected function action(): string
	{
		return 'cdr-details';
	}

	protected function tables(): array
	{
		return parent::tables() + array('v_xml_cdr' => S::rows());
	}

	private function details($xml_cdr_uuid): array
	{
		return $this->runAction(array('domain_uuid' => self::DOMAIN_UUID, 'xml_cdr_uuid' => $xml_cdr_uuid));
	}

	public static function calls(): array
	{
		return array(
			'ring group from its a leg' => array(S::A1, S::A1, array(S::A1, S::B1A, S::B1B, S::B1C)),
			'ring group from a b leg' => array(S::B1B, S::A1, array(S::A1, S::B1A, S::B1B, S::B1C)),
			'local call from the b leg' => array(S::B2, S::A2, array(S::A2, S::B2)),
			'single leg' => array(S::A3, S::A3, array(S::A3)),
			'linked only by bridge_uuid, from the b leg' => array(S::B7, S::A7, array(S::A7, S::B7)),
			'linked only by bridge_uuid, from the a leg' => array(S::A7, S::A7, array(S::A7, S::B7)),
			'a leg missing' => array(S::B5A, S::B5B, array(S::B5B, S::B5A)),
			'unlinked b leg' => array(S::B6, S::B6, array(S::B6)),
		);
	}

	#[DataProvider('calls')]
	public function testReturnsTheCallWithEveryLeg(string $requested, string $main, array $legs): void
	{
		$result = $this->details($requested);

		$this->assertSame($main, $result['xml_cdr_uuid']);
		$this->assertSame($legs, array_column($result['legs'], 'xml_cdr_uuid'));
	}

	public function testEveryLegHasTheFieldsToResolveTheRecordingAndTheViewer(): void
	{
		$legs = $this->details(S::A1)['legs'];

		$this->assertSame(
			array(
				array('a', null, null, 'c1.wav', S::RECORD_PATH),
				array('b', S::A1, S::EXT_1003, 'c1.wav', S::RECORD_PATH),
				array('b', S::A1, S::EXT_1004, null, null),
				array('b', S::A1, S::EXT_1005, null, null),
			),
			array_map(function ($leg) {
				return array($leg['leg'], $leg['originating_leg_uuid'], $leg['extension_uuid'], $leg['record_name'], $leg['record_path']);
			}, $legs)
		);
		$this->assertSame(REST_API_CDR_FIELDS, array_keys($legs[0]));
		$this->assertSame(487, $legs[2]['hangup_cause_q850']);
	}

	public function testIgnoresLegsOfAnotherDomainThatClaimTheCall(): void
	{
		$this->assertSame(array(S::A2, S::B2), array_column($this->details(S::A2)['legs'], 'xml_cdr_uuid'));
	}

	public function testDoesNotFindACallOfAnotherDomain(): void
	{
		$this->assertSame(array('error' => 'call not found', 'code' => 404), $this->details(S::X1));
		$this->assertSame(array('error' => 'call not found', 'code' => 404), $this->details(S::X2));
	}

	public function testDoesNotFindAnUnknownCall(): void
	{
		$this->assertSame(array('error' => 'call not found', 'code' => 404), $this->details(S::MISSING_A5));
	}

	public function testRejectsAMissingOrMalformedUuid(): void
	{
		$this->assertSame(array('error' => 'invalid xml_cdr_uuid', 'code' => 400), $this->runAction(array('domain_uuid' => self::DOMAIN_UUID)));
		foreach (array('', 'not-a-uuid', array(S::A1), 42) as $xml_cdr_uuid) {
			$this->assertSame(array('error' => 'invalid xml_cdr_uuid', 'code' => 400), $this->details($xml_cdr_uuid), json_encode($xml_cdr_uuid));
		}
	}

	// PDO prepared statements reject a named placeholder used twice
	public function testUsesEveryPlaceholderOnce(): void
	{
		$this->details(S::B5A);

		foreach ($this->state()['queries'] as $query) {
			preg_match_all('/:\w+/', $query['sql'], $placeholders);
			$this->assertSame(array_unique($placeholders[0]), $placeholders[0], $query['sql']);
		}
	}

	// FusionPBX's select() returns false on a database error: not a 404, and
	// never a call with legs missing
	public function testAnswers500WhenTheDatabaseFails(): void
	{
		$this->failSelects();

		$this->assertSame(array('error' => 'database error', 'code' => 500), $this->details(S::A1));
	}

	// bridge_uuid is a text column. Postgres rejects a value that isn't a uuid
	// against the uuid xml_cdr_uuid, which would fail the whole request
	public function testDoesNotLookUpABridgeUuidThatIsNotAUuid(): void
	{
		\FakeStore::update(function (&$state) {
			foreach ($state['tables']['v_xml_cdr'] as $i => $row) {
				if ($row['xml_cdr_uuid'] === S::A7) {
					$state['tables']['v_xml_cdr'][$i]['bridge_uuid'] = 'not-a-uuid';
				}
			}
		});

		$result = $this->details(S::A7);

		$this->assertSame(array(S::A7), array_column($result['legs'], 'xml_cdr_uuid'));
		foreach ($this->state()['queries'] as $query) {
			$this->assertNotContains('not-a-uuid', $query['parameters'], $query['sql']);
		}
	}
}
