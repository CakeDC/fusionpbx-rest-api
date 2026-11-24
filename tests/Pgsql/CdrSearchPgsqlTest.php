<?php
namespace RestApi\Test\Pgsql;

use FakeStore;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\RunsOnPostgres;
use RestApi\Test\Support\CdrSample as S;
use RestApi\Test\Unit\Actions\CdrSearchTest;

/** CdrSearchTest on PostgreSQL. */
#[RunTestsInSeparateProcesses]
class CdrSearchPgsqlTest extends CdrSearchTest
{
	use RunsOnPostgres;

	// Postgres's uuid column type casts the bound string case-insensitively;
	// the fake store does a case-sensitive text comparison, so this only runs here
	public function testUppercaseQueueUuidFilterMatchesRows(): void
	{
		$this->withQueueCalls();

		$result = $this->search(array('call_center_queue_uuid' => strtoupper(S::QUEUE_SALES)));
		$this->assertSame(array(S::QA2, S::QA1), array_column($result['data'], 'xml_cdr_uuid'));
	}

	// The epochs are numeric in 5.6.5, which PDO returns as text that keeps
	// the stored scale ("1788858000.000000")
	public function testNumericEpochsWithAScaleAreIntegers(): void
	{
		$this->withQueueCalls();
		self::withScaledEpochs();

		$rows = $this->search(array('call_center_queue_uuid' => S::QUEUE_SALES.','.S::QUEUE_SUPPORT))['data'];
		$epochs = array();
		foreach($rows as $row) {
			$epochs[$row['xml_cdr_uuid']] = array($row['cc_queue_joined_epoch'], $row['cc_queue_answered_epoch'], $row['cc_queue_canceled_epoch']);
		}

		$this->assertSame(array(
			S::QA4 => array(1788868800, null, null),
			S::QA3 => array(1788865200, null, 1788865204),
			S::QA2 => array(1788861600, null, 1788861640),
			S::QA1 => array(1788858000, 1788858012, null),
		), $epochs);
	}

	/** Rewrites the queue legs' epochs as numeric text with a scale, a 0 one as 0.000000. */
	public static function withScaledEpochs(): void
	{
		FakeStore::update(function (&$state) {
			foreach($state['tables']['v_xml_cdr'] as &$row) {
				foreach(array('cc_queue_joined_epoch', 'cc_queue_answered_epoch', 'cc_queue_canceled_epoch') as $column) {
					if(isset($row[$column]) && is_numeric($row[$column])) {
						$row[$column] = $row[$column].'.000000';
					}
				}
			}
		});
	}
}
