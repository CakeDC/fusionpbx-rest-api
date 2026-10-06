<?php
namespace RestApi\Test\Pgsql;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\RunsOnPostgres;
use RestApi\Test\Unit\Actions\CdrSearchTest;

/** CdrSearchTest on PostgreSQL. */
#[RunTestsInSeparateProcesses]
class CdrSearchPgsqlTest extends CdrSearchTest
{
	use RunsOnPostgres;
}
