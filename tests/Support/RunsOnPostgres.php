<?php
namespace RestApi\Test\Support;

use FakeStore;

/**
 * Runs an ActionTestCase's tests on a real PostgreSQL (see Pgsql). Without
 * REST_API_PGSQL_DSN the tests fail, so the pgsql suite can't pass unrun.
 */
trait RunsOnPostgres
{
	protected function setUp(): void
	{
		parent::setUp();
		if (!Pgsql::install()) {
			$this->fail('set '.Pgsql::DSN_VARIABLE.' to run the pgsql suite, or run: composer test-pgsql');
		}
	}

	// Postgres' error, rather than the 500 it became
	protected function assertPostConditions(): void
	{
		$this->assertSame(array(), FakeStore::read()['pgsql_errors'] ?? array(), 'PostgreSQL rejected a query');
	}
}
