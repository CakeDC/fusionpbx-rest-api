<?php
namespace RestApi\Test\Pgsql;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\RunsOnPostgres;
use RestApi\Test\Unit\Actions\ExtensionDetailsTest;

/** ExtensionDetailsTest on PostgreSQL. */
#[RunTestsInSeparateProcesses]
class ExtensionDetailsPgsqlTest extends ExtensionDetailsTest
{
	use RunsOnPostgres;
}
