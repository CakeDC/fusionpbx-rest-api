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
	private function upgrade(int $domains_processed, $index = false, string $type = 'pgsql', array $failing = array()): array
	{
		$database = new class($index, $type, $failing) {
			public $type;
			public $executed = array();
			public $message = array();
			private $index;
			private $failing;

			public function __construct($index, string $type, array $failing)
			{
				$this->index = $index;
				$this->type = $type;
				$this->failing = $failing;
			}

			public function select(string $sql, ?array $parameters = array(), string $return_type = 'all')
			{
				$this->executed[] = 'SELECT index '.$parameters['name'];
				return $this->index;
			}

			public function execute($sql, $parameters = null, $return_type = 'all')
			{
				$this->executed[] = $sql;
				// like FusionPBX 5.6.5: the PDOException is caught, kept in
				// message and false is returned
				if (in_array($sql, $this->failing, true)) {
					$this->message = array('message' => 'SQLSTATE[57014]: canceling statement due to lock timeout', 'code' => '57014');
					return false;
				}
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

	/** The upgrade's error_log output. */
	private function logged(callable $upgrade): string
	{
		$log = tempnam(sys_get_temp_dir(), 'rest_api_log');
		$previous = ini_set('error_log', $log);
		try {
			$upgrade();
		} finally {
			ini_set('error_log', $previous);
		}
		$output = file_get_contents($log);
		unlink($log);
		return $output;
	}

	// execute() returns false instead of throwing: without a log line nobody
	// would know cdr-search runs without its index
	public function testLogsAFailedIndexBuild(): void
	{
		$log = $this->logged(function () {
			$this->upgrade(1, false, 'pgsql', array(self::CREATE));
		});

		$this->assertStringContainsString('v_xml_cdr_originating_leg_uuid_idx', $log);
		$this->assertStringContainsString('canceling statement due to lock timeout', $log);
	}

	// CREATE INDEX IF NOT EXISTS would keep the invalid index the DROP failed to remove
	public function testLogsAFailedDropAndLeavesTheIndexForTheNextUpgrade(): void
	{
		$executed = array();
		$log = $this->logged(function () use (&$executed) {
			$executed = $this->upgrade(1, array('indisvalid' => false), 'pgsql', array(self::DROP));
		});

		$this->assertSame(array('SELECT index v_xml_cdr_originating_leg_uuid_idx', self::DROP), $executed);
		$this->assertStringContainsString('canceling statement due to lock timeout', $log);
	}

	public function testLogsNothingWhenTheIndexIsBuilt(): void
	{
		$this->assertSame('', $this->logged(function () {
			$this->upgrade(1);
		}));
	}
}
