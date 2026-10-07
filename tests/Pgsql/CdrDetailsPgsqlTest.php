<?php
namespace RestApi\Test\Pgsql;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\RunsOnPostgres;
use RestApi\Test\Unit\Actions\CdrDetailsTest;

/** CdrDetailsTest on PostgreSQL. */
#[RunTestsInSeparateProcesses]
class CdrDetailsPgsqlTest extends CdrDetailsTest
{
	use RunsOnPostgres;
}
