<?php
namespace RestApi\Test\Pgsql;

use FakeStore;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use RestApi\Test\Support\Pgsql;

/**
 * app_defaults.php on PostgreSQL: CREATE/DROP INDEX CONCURRENTLY must run
 * through FusionPBX's execute(), and the pg_index lookup must recognise a valid index.
 */
#[RunTestsInSeparateProcesses]
class AppDefaultsPgsqlTest extends TestCase
{
	private const INDEX = 'v_xml_cdr_originating_leg_uuid_idx';

	private Pgsql $pgsql;

	protected function setUp(): void
	{
		require_once FUSIONPBX_FAKES_DIR.'/resources/fakes.php';
		FakeStore::reset();
		$pgsql = Pgsql::install();
		if (!$pgsql) {
			$this->fail('set '.Pgsql::DSN_VARIABLE.' to run the pgsql suite, or run: composer test-pgsql');
		}
		$this->pgsql = $pgsql;
		$this->pgsql->pdo()->exec('DROP INDEX IF EXISTS '.self::INDEX);
	}

	protected function assertPostConditions(): void
	{
		$this->assertSame(array(), FakeStore::read()['pgsql_errors'] ?? array(), 'PostgreSQL rejected a query');
	}

	/** Run app_defaults.php as Upgrade -> App Defaults does for the first domain; returns the statements it ran. */
	private function upgrade(): array
	{
		FakeStore::update(function (&$state) {
			$state['queries'] = array();
		});
		$database = new \database;
		$domains_processed = 1;
		include PLUGIN_DIR.'/app_defaults.php';
		return array_column(FakeStore::read()['queries'], 'sql');
	}

	private function index(): array|false
	{
		$sql = "SELECT i.indisvalid, pg_get_indexdef(i.indexrelid) AS definition FROM pg_class c JOIN pg_index i ON i.indexrelid = c.oid WHERE c.relname = '".self::INDEX."'";
		return $this->pgsql->pdo()->query($sql)->fetch(\PDO::FETCH_ASSOC);
	}

	public function testCreatesAValidIndexOnOriginatingLegUuid(): void
	{
		$this->upgrade();

		$index = $this->index();
		$this->assertTrue($index['indisvalid']);
		$this->assertStringContainsString('(originating_leg_uuid)', $index['definition']);
	}

	public function testLeavesTheIndexAloneOnTheNextUpgrade(): void
	{
		$this->upgrade();

		$this->assertCount(1, $this->upgrade(), 'only the pg_index lookup');
		$this->assertTrue($this->index()['indisvalid']);
	}

	// what an interrupted CREATE INDEX CONCURRENTLY leaves behind
	public function testRebuildsAnInvalidIndex(): void
	{
		$this->upgrade();
		$this->pgsql->pdo()->exec("UPDATE pg_index SET indisvalid = false WHERE indexrelid = '".self::INDEX."'::regclass");

		$this->assertCount(3, $this->upgrade(), 'lookup, drop and create');
		$this->assertTrue($this->index()['indisvalid']);
	}

	// cdr-search and cdr-details bind originating_leg_uuid as a parameter
	public function testLookupsByOriginatingLegUuidCanUseTheIndex(): void
	{
		$this->upgrade();
		$this->pgsql->pdo()->exec('SET enable_seqscan = off');

		$plan = (new \database)->select('EXPLAIN SELECT xml_cdr_uuid FROM v_xml_cdr WHERE originating_leg_uuid = :uuid', array('uuid' => 'c0000001-0000-4000-8000-00000000000a'), 'all');

		$this->assertStringContainsString(self::INDEX, implode("\n", array_column($plan, 'QUERY PLAN')));
	}
}
