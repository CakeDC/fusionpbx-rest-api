<?php
namespace RestApi\Test\Pgsql;

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RestApi\Test\Support\RunsOnPostgres;
use RestApi\Test\Unit\Actions\RecordingDetailsTest;

/** RecordingDetailsTest on PostgreSQL. */
#[RunTestsInSeparateProcesses]
class RecordingDetailsPgsqlTest extends RecordingDetailsTest
{
	use RunsOnPostgres;
}
