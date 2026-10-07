<?php
namespace RestApi\Test\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * app_defaults.php runs on Advanced -> Upgrade -> App Defaults. FusionPBX 5.6.5
 * includes it once per domain, with $database and $domains_processed in scope.
 */
class AppDefaultsTest extends TestCase
{
	private const CREATE = 'CREATE INDEX CONCURRENTLY IF NOT EXISTS v_xml_cdr_originating_leg_uuid_idx ON v_xml_cdr (originating_leg_uuid)';
	private const DROP = 'DROP INDEX CONCURRENTLY IF EXISTS v_xml_cdr_originating_leg_uuid_idx';

	/** Run app_defaults.php against a database that records the statements it gets. */
	private function upgrade(int $domains_processed, $index = false, string $type = 'pgsql'): array
	{
		$database = new class($index, $type) {
			public $type;
			public $executed = array();
			private $index;

			public function __construct($index, string $type)
			{
				$this->index = $index;
				$this->type = $type;
			}

			public function select(string $sql, ?array $parameters = array(), string $return_type = 'all')
			{
				$this->executed[] = 'SELECT index '.$parameters['name'];
				return $this->index;
			}

			public function execute($sql, $parameters = null, $return_type = 'all')
			{
				$this->executed[] = $sql;
				return array();
			}
		};
		include PLUGIN_DIR.'/app_defaults.php';
		return $database->executed;
	}

	public function testCreatesTheCdrLegIndexOnTheFirstDomain(): void
	{
		$this->assertSame(array('SELECT index v_xml_cdr_originating_leg_uuid_idx', self::CREATE), $this->upgrade(1));
	}

	// app_defaults.php runs once per domain; the index belongs to the whole table
	public function testDoesNothingForTheOtherDomains(): void
	{
		$this->assertSame(array(), $this->upgrade(2));
	}

	public static function validIndexes(): array
	{
		return array('PDO boolean' => array(true), 'text' => array('t'));
	}

	#[DataProvider('validIndexes')]
	public function testKeepsAValidIndex($indisvalid): void
	{
		$this->assertSame(array('SELECT index v_xml_cdr_originating_leg_uuid_idx'), $this->upgrade(1, array('indisvalid' => $indisvalid)));
	}

	// an interrupted CREATE INDEX CONCURRENTLY leaves an invalid index that
	// Postgres never uses, and IF NOT EXISTS would keep it forever
	public function testRebuildsAnInvalidIndex(): void
	{
		$this->assertSame(
			array('SELECT index v_xml_cdr_originating_leg_uuid_idx', self::DROP, self::CREATE),
			$this->upgrade(1, array('indisvalid' => false))
		);
	}

	public function testLeavesOtherDatabasesAlone(): void
	{
		$this->assertSame(array(), $this->upgrade(1, false, 'mysql'));
	}
}
