<?php
namespace RestApi\Test\Support;

use FakeStore;
use PDO;
use PDOException;

/**
 * A real PostgreSQL behind the fake database class (database::$pgsql), so the
 * plugin's SQL runs on the server it is deployed with instead of fake_sql().
 * The database is named by REST_API_PGSQL_DSN, e.g.
 * "pgsql:host=127.0.0.1 port=5432 dbname=fusionpbx user=fusionpbx password=fusionpbx".
 *
 * The tables of FusionPbxSchema are created with 5.6.5's columns and types.
 * Before each query they are reloaded from the FakeStore rows when they
 * changed, so tests seed and edit rows as they do for fake_sql(), and save()
 * keeps writing there.
 */
final class Pgsql
{
	public const DSN_VARIABLE = 'REST_API_PGSQL_DSN';

	private PDO $db;
	private ?string $lastError = null;
	/** @var array<string, string> hash of the FakeStore rows each table holds */
	private array $loaded = array();

	private function __construct(string $dsn)
	{
		$this->db = new PDO($dsn);
		$this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		// timestamps come back as the fixtures write them
		$this->db->exec("SET TIME ZONE 'UTC'");
		foreach (array_keys(FusionPbxSchema::TABLES) as $table) {
			$this->db->exec(FusionPbxSchema::createTable($table));
		}
	}

	/** Connect and put it behind the fake database class. Null when REST_API_PGSQL_DSN isn't set. */
	public static function install(): ?self
	{
		$dsn = getenv(self::DSN_VARIABLE);
		if (!$dsn) {
			return null;
		}
		return \database::$pgsql = new self($dsn);
	}

	/** The connection, for tests that inspect the server. */
	public function pdo(): PDO
	{
		return $this->db;
	}

	/** Like FusionPBX 5.6.5's database::select(), which also disables server-side prepares. */
	public function select(string $sql, ?array $parameters, string $return_type)
	{
		$this->db->setAttribute(PDO::PGSQL_ATTR_DISABLE_PREPARES, true);
		return $this->run($sql, $parameters, $return_type);
	}

	/** The PDO error of the last query that failed, for database::$message. */
	public function lastError(): ?string
	{
		return $this->lastError;
	}

	/** Like FusionPBX 5.6.5's database::execute(). */
	public function execute(string $sql, ?array $parameters)
	{
		$this->db->setAttribute(PDO::PGSQL_ATTR_DISABLE_PREPARES, false);
		return $this->run($sql, $parameters, 'all');
	}

	// FusionPBX returns false on a database error. the error is kept in
	// "pgsql_errors" so a test can show it instead of a bare 500
	private function run(string $sql, ?array $parameters, string $return_type)
	{
		$this->load();
		FakeStore::update(function (&$state) use ($sql, $parameters) {
			$state['queries'][] = array('sql' => $sql, 'parameters' => $parameters);
		});
		try {
			$statement = $this->db->prepare($sql);
			$statement->execute(is_array($parameters) ? $parameters : array());
			switch ($return_type) {
				case 'row':
					return $statement->fetch(PDO::FETCH_ASSOC);
				case 'column':
					return $statement->fetchColumn();
				default:
					return $statement->fetchAll(PDO::FETCH_ASSOC);
			}
		} catch (PDOException $e) {
			$this->lastError = $e->getMessage();
			FakeStore::update(function (&$state) use ($sql, $e) {
				$state['pgsql_errors'][] = $e->getMessage().' in: '.$sql;
			});
			return false;
		}
	}

	// copy the FakeStore rows of each table into it, when they changed
	private function load(): void
	{
		$tables = FakeStore::read()['tables'];
		foreach (array_keys(FusionPbxSchema::TABLES) as $table) {
			$rows = $tables[$table] ?? array();
			$hash = md5(serialize($rows));
			if ($hash === ($this->loaded[$table] ?? null)) {
				continue;
			}
			$this->db->exec('TRUNCATE '.$table);
			foreach ($rows as $row) {
				$columns = array_keys($row);
				$sql = 'INSERT INTO '.$table.' ('.implode(', ', $columns).') VALUES (:'.implode(', :', $columns).')';
				$this->db->prepare($sql)->execute($row);
			}
			$this->db->exec('ANALYZE '.$table);
			$this->loaded[$table] = $hash;
		}
	}
}
