<?php
namespace RestApi\Test\Pgsql;

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
}
