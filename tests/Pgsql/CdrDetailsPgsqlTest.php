<?php
namespace RestApi\Test\Pgsql;

use FakeStore;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\RunsOnPostgres;
use RestApi\Test\Support\CdrSample as S;
use RestApi\Test\Unit\Actions\CdrDetailsTest;

/** CdrDetailsTest on PostgreSQL. */
#[RunTestsInSeparateProcesses]
class CdrDetailsPgsqlTest extends CdrDetailsTest
{
	use RunsOnPostgres;

	// The epochs are numeric in 5.6.5, which PDO returns as text that keeps
	// the stored scale ("1788858000.000000")
	public function testNumericEpochsWithAScaleAreIntegers(): void
	{
		FakeStore::update(function (&$state) {
			$state['tables']['v_xml_cdr'] = array_merge($state['tables']['v_xml_cdr'], S::queueRows());
		});
		CdrSearchPgsqlTest::withScaledEpochs();

		$leg = $this->details(S::QA1)['legs'][0];
		$this->assertSame(array(1788858000, 1788858012, null), array($leg['cc_queue_joined_epoch'], $leg['cc_queue_answered_epoch'], $leg['cc_queue_canceled_epoch']));

		$leg = $this->details(S::QA4)['legs'][0];
		$this->assertSame(array(1788868800, null, null), array($leg['cc_queue_joined_epoch'], $leg['cc_queue_answered_epoch'], $leg['cc_queue_canceled_epoch']));
	}
}
