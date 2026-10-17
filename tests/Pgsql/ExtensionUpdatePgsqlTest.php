<?php
namespace RestApi\Test\Pgsql;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\RunsOnPostgres;
use RestApi\Test\Unit\Actions\ExtensionUpdateTest;

/** ExtensionUpdateTest on PostgreSQL. */
#[RunTestsInSeparateProcesses]
class ExtensionUpdatePgsqlTest extends ExtensionUpdateTest
{
	use RunsOnPostgres;
}
