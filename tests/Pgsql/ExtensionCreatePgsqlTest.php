<?php
namespace RestApi\Test\Pgsql;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\RunsOnPostgres;
use RestApi\Test\Unit\Actions\ExtensionCreateTest;

/** ExtensionCreateTest on PostgreSQL. */
#[RunTestsInSeparateProcesses]
class ExtensionCreatePgsqlTest extends ExtensionCreateTest
{
	use RunsOnPostgres;
}
