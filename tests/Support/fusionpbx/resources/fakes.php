<?php
/*
 * Minimal stand-ins for the FusionPBX functions and classes the plugin uses.
 * Used in-process by the unit tests and by the fake document root of the HTTP tests.
 */

// these files live inside the web root when the plugin is deployed. never run them there
if (PHP_SAPI !== 'cli' && getenv('REST_API_TEST_HARNESS') !== '1') {
	http_response_code(404);
	exit;
}

/**
 * State shared by the fakes: tables, executed queries, event socket traffic.
 * Kept in memory for unit tests, or in the JSON file named by FAKE_DB_FILE so
 * the HTTP tests can seed and inspect it around requests.
 */
class FakeStore {
	private static $memory = null;

	public static function reset(array $tables = array()) {
		self::write(array(
			'tables' => $tables,
			'queries' => array(),
			'saved' => array(),
			'skipped' => array(),
			'esl_commands' => array(),
			'esl_response' => "+OK 7f4de3d2-0000-4000-8000-00000000c411\n",
			'esl_available' => true,
			'save_fails' => false,
			'select_fails' => false,
			'uuid_counter' => 0,
		));
	}

	public static function read() {
		$file = getenv('FAKE_DB_FILE');
		if ($file) {
			return json_decode(file_get_contents($file), true);
		}
		if (self::$memory === null) {
			self::reset();
		}
		return self::$memory;
	}

	public static function write(array $state) {
		$file = getenv('FAKE_DB_FILE');
		if ($file) {
			file_put_contents($file, json_encode($state), LOCK_EX);
			return;
		}
		self::$memory = $state;
	}

	public static function update(callable $fn) {
		$state = self::read();
		$result = $fn($state);
		self::write($state);
		return $result;
	}
}

/**
 * Executes the small SQL subset used by the plugin against FakeStore tables.
 * Like PDO, it rejects placeholders without a value and values without a
 * placeholder. Anything it can't parse throws, so unsupported queries are visible.
 */
function fake_sql(string $sql, ?array $parameters, string $return_type = 'all') {
	$parameters = $parameters ?? array();
	$sql = trim(preg_replace('/\s+/', ' ', $sql));

	preg_match_all('/:(\w+)/', $sql, $matches);
	$placeholders = array_unique($matches[1]);
	$missing = array_diff($placeholders, array_keys($parameters));
	$unused = array_diff(array_keys($parameters), $placeholders);
	if ($missing || $unused) {
		throw new RuntimeException("SQL parameter mismatch (missing: ".implode(',', $missing)."; unused: ".implode(',', $unused).") in: ".$sql);
	}

	$value = function ($expression) use ($parameters) {
		$expression = trim($expression);
		if (strcasecmp($expression, 'now()') === 0) {
			return '2026-10-02 12:00:00+00';
		}
		if (preg_match('/^:(\w+)$/', $expression, $m)) {
			return $parameters[$m[1]];
		}
		throw new RuntimeException("unsupported SQL value: ".$expression);
	};

	// a WHERE clause as a row filter. $aliases are the names the row's columns
	// can be qualified with; $tables feed EXISTS subqueries
	$where = function ($clause, array $tables, array $aliases) use ($parameters) {
		$condition = FakeSqlCondition::parse($clause, $parameters, $tables);
		return function ($row) use ($condition, $aliases) {
			return $condition(array(array('aliases' => $aliases, 'row' => $row))) === true;
		};
	};

	// FROM table [alias] [LEFT JOIN table alias ON a.column = b.column]...
	// each row holds every column as "alias.column". the first table's columns
	// are also there without alias, as in a single-table query
	$from = function ($clause, array $tables) {
		if (!preg_match('/^(\w+)(?: (?!left\b)(\w+))?((?: left join \w+ \w+ on \w+\.\w+ = \w+\.\w+)*)$/i', $clause, $m)) {
			throw new RuntimeException("unsupported SQL from: ".$clause);
		}
		$alias = !empty($m[2]) ? $m[2] : $m[1];
		$aliases = array($alias);
		$rows = array();
		foreach ($tables[$m[1]] ?? array() as $row) {
			foreach ($row as $column => $v) {
				$row[$alias.'.'.$column] = $v;
			}
			$rows[] = $row;
		}
		preg_match_all('/ left join (\w+) (\w+) on (\w+\.\w+) = (\w+\.\w+)/i', $m[3] ?? '', $joins, PREG_SET_ORDER);
		foreach ($joins as $join) {
			list(, $table, $join_alias, $left, $right) = $join;
			$aliases[] = $join_alias;
			if (strpos($left, $join_alias.'.') !== 0) {
				list($left, $right) = array($right, $left);
			}
			$column = substr($left, strlen($join_alias) + 1);
			foreach ($rows as $i => $row) {
				foreach ($tables[$table] ?? array() as $candidate) {
					if (isset($row[$right], $candidate[$column]) && (string)$candidate[$column] === (string)$row[$right]) {
						foreach ($candidate as $c => $v) {
							$rows[$i][$join_alias.'.'.$c] = $v;
						}
						break;
					}
				}
			}
		}
		return array($rows, $aliases);
	};

	return FakeStore::update(function (&$state) use ($sql, $parameters, $return_type, $value, $where, $from) {
		$state['queries'][] = array('sql' => $sql, 'parameters' => $parameters);

		if (preg_match('/^select (.+?) from (.+?)(?: where (.+?))?(?: order by (.+?))?(?: limit (\d+))?(?: offset (\d+))?$/i', $sql, $m)) {
			list($rows, $aliases) = $from($m[2], $state['tables']);
			if (!empty($m[3])) {
				$rows = array_values(array_filter($rows, $where($m[3], $state['tables'], $aliases)));
			}
			if (preg_match('/^count\(\*\)$/i', trim($m[1]))) {
				$rows = array(array('count' => count($rows)));
				$m[1] = 'count';
			}
			if (!empty($m[4])) {
				$order = array();
				foreach (explode(',', $m[4]) as $part) {
					if (!preg_match('/^([\w.]+)(?: (asc|desc))?$/i', trim($part), $o)) {
						throw new RuntimeException("unsupported SQL order: ".$part);
					}
					$order[] = array($o[1], strcasecmp($o[2] ?? '', 'desc') === 0 ? -1 : 1);
				}
				usort($rows, function ($a, $b) use ($order) {
					foreach ($order as list($column, $direction)) {
						$cmp = strcmp((string)($a[$column] ?? ''), (string)($b[$column] ?? ''));
						if ($cmp !== 0) {
							return $cmp * $direction;
						}
					}
					return 0;
				});
			}
			if (!empty($m[5]) || !empty($m[6])) {
				$rows = array_slice($rows, (int)($m[6] ?? 0), !empty($m[5]) ? (int)$m[5] : null);
			}
			if (trim($m[1]) === '*') {
				$rows = array_map(function ($row) {
					return array_filter($row, function ($column) { return strpos($column, '.') === false; }, ARRAY_FILTER_USE_KEY);
				}, $rows);
			} else {
				$columns = array_map('trim', explode(',', $m[1]));
				$rows = array_map(function ($row) use ($columns) {
					$out = array();
					foreach ($columns as $c) {
						$out[preg_replace('/^\w+\./', '', $c)] = $row[$c] ?? null;
					}
					return $out;
				}, $rows);
			}
			switch ($return_type) {
				case 'row':
					return $rows[0] ?? false;
				case 'column':
					return $rows ? reset($rows[0]) : false;
				default:
					return $rows;
			}
		}

		if (preg_match('/^insert into (\w+) \((.+?)\) values \((.+)\)$/i', $sql, $m)) {
			$columns = array_map('trim', explode(',', $m[2]));
			$values = array_map($value, explode(',', $m[3]));
			$state['tables'][$m[1]][] = array_combine($columns, $values);
			return true;
		}

		if (preg_match('/^update (\w+) set (.+?) where (.+)$/i', $sql, $m)) {
			$matches_row = $where($m[3], $state['tables'], array($m[1]));
			$assignments = array();
			foreach (explode(',', $m[2]) as $assignment) {
				list($column, $expression) = array_map('trim', explode('=', $assignment, 2));
				$assignments[$column] = $value($expression);
			}
			foreach ($state['tables'][$m[1]] ?? array() as $i => $row) {
				if ($matches_row($row)) {
					$state['tables'][$m[1]][$i] = array_merge($row, $assignments);
				}
			}
			return true;
		}

		if (preg_match('/^delete from (\w+) where (.+)$/i', $sql, $m)) {
			$matches_row = $where($m[2], $state['tables'], array($m[1]));
			$state['tables'][$m[1]] = array_values(array_filter($state['tables'][$m[1]] ?? array(), function ($row) use ($matches_row) {
				return !$matches_row($row);
			}));
			return true;
		}

		throw new RuntimeException("unsupported SQL: ".$sql);
	});
}

/**
 * WHERE clauses for fake_sql(), with Postgres semantics for what the plugin uses:
 * AND, OR, NOT, parentheses, = <> != < <= > >=, IS [NOT] NULL, [NOT] IN (...),
 * [NOT] IN (SELECT expression FROM table alias WHERE ...), [NOT] LIKE ...
 * [ESCAPE ...], CAST(... AS type) and correlated [NOT] EXISTS (SELECT 1 FROM
 * table alias WHERE ...). Like SQL it has three-valued logic: a comparison with NULL
 * is unknown (null), and a row only matches when the condition is true.
 */
class FakeSqlCondition {
	private $tokens;
	private $pos = 0;
	private $parameters;
	private $tables;

	private function __construct(array $tokens, array $parameters, array $tables) {
		$this->tokens = $tokens;
		$this->parameters = $parameters;
		$this->tables = $tables;
	}

	/**
	 * Returns function (array $scopes): ?bool. $scopes are the rows in reach,
	 * innermost last, each array('aliases' => [...], 'row' => [...]).
	 */
	public static function parse(string $clause, array $parameters, array $tables): callable {
		$tokens = array();
		$offset = 0;
		while ($offset < strlen($clause)) {
			if (!preg_match("/\\s*('(?:[^']|'')*'|:\\w+|\\d+|now\\(\\)|[\\w.]+|<>|!=|<=|>=|[=<>(),])\\s*/Ai", $clause, $m, 0, $offset)) {
				throw new RuntimeException("unsupported SQL condition: ".$clause);
			}
			$tokens[] = $m[1];
			$offset += strlen($m[0]);
		}
		$parser = new self($tokens, $parameters, $tables);
		$condition = $parser->disjunction();
		if ($parser->pos !== count($tokens)) {
			throw new RuntimeException("unsupported SQL condition: ".$clause);
		}
		return $condition;
	}

	private function peek(): ?string {
		return $this->tokens[$this->pos] ?? null;
	}

	private function accept(string $token): bool {
		if ($this->peek() !== null && strcasecmp($this->peek(), $token) === 0) {
			$this->pos++;
			return true;
		}
		return false;
	}

	private function expect(string $token): void {
		if (!$this->accept($token)) {
			throw new RuntimeException("unsupported SQL condition: expected ".$token." at ".implode(' ', array_slice($this->tokens, $this->pos)));
		}
	}

	private function disjunction(): callable {
		$parts = array($this->conjunction());
		while ($this->accept('or')) {
			$parts[] = $this->conjunction();
		}
		return function (array $scopes) use ($parts) {
			$result = false;
			foreach ($parts as $part) {
				$value = $part($scopes);
				if ($value === true) {
					return true;
				}
				if ($value === null) {
					$result = null;
				}
			}
			return $result;
		};
	}

	private function conjunction(): callable {
		$parts = array($this->negation());
		while ($this->accept('and')) {
			$parts[] = $this->negation();
		}
		return function (array $scopes) use ($parts) {
			$result = true;
			foreach ($parts as $part) {
				$value = $part($scopes);
				if ($value === false) {
					return false;
				}
				if ($value === null) {
					$result = null;
				}
			}
			return $result;
		};
	}

	private function negation(): callable {
		if ($this->accept('not')) {
			$inner = $this->negation();
			return function (array $scopes) use ($inner) {
				$value = $inner($scopes);
				return $value === null ? null : !$value;
			};
		}
		if ($this->accept('exists')) {
			$this->expect('(');
			$subquery = $this->subquery();
			$this->expect(')');
			return $subquery;
		}
		if ($this->peek() === '(') {
			$this->pos++;
			$inner = $this->disjunction();
			$this->expect(')');
			return $inner;
		}
		return $this->comparison();
	}

	// SELECT 1 FROM table [alias] WHERE condition: true when any row matches
	private function subquery(): callable {
		$this->expect('select');
		$this->expect('1');
		$this->expect('from');
		$table = $this->tokens[$this->pos++] ?? '';
		$alias = $table;
		if ($this->peek() !== null && strcasecmp($this->peek(), 'where') !== 0) {
			$alias = $this->tokens[$this->pos++];
		}
		$this->expect('where');
		$condition = $this->disjunction();
		$tables = $this->tables;
		return function (array $scopes) use ($table, $alias, $condition, $tables) {
			foreach ($tables[$table] ?? array() as $row) {
				foreach ($row as $column => $value) {
					$row[$alias.'.'.$column] = $value;
				}
				if ($condition(array_merge($scopes, array(array('aliases' => array($alias), 'row' => $row)))) === true) {
					return true;
				}
			}
			return false;
		};
	}

	// SELECT expression FROM table [alias] [WHERE condition] inside IN (...):
	// the values of the expression for the matching rows
	private function listSubquery(?string $left_type): callable {
		$this->expect('select');
		list($expression, $type) = $this->operand();
		if ($left_type !== null && $type !== null && $left_type !== $type) {
			throw new RuntimeException("operator does not exist: ".$left_type." = ".$type);
		}
		$this->expect('from');
		$table = $this->tokens[$this->pos++] ?? '';
		$alias = $table;
		if ($this->peek() !== null && $this->peek() !== ')' && strcasecmp($this->peek(), 'where') !== 0) {
			$alias = $this->tokens[$this->pos++];
		}
		$condition = $this->accept('where') ? $this->disjunction() : null;
		$tables = $this->tables;
		return function (array $scopes) use ($table, $alias, $expression, $condition, $tables) {
			$values = array();
			foreach ($tables[$table] ?? array() as $row) {
				foreach ($row as $column => $value) {
					$row[$alias.'.'.$column] = $value;
				}
				$inner = array_merge($scopes, array(array('aliases' => array($alias), 'row' => $row)));
				if ($condition === null || $condition($inner) === true) {
					$values[] = $expression($inner);
				}
			}
			return $values;
		};
	}

	private function comparison(): callable {
		list($left, $left_type) = $this->operand();
		if ($this->accept('is')) {
			$not = $this->accept('not');
			$this->expect('null');
			return function (array $scopes) use ($left, $not) {
				return ($left($scopes) === null) !== $not;
			};
		}
		$not = $this->accept('not');
		if ($this->accept('in')) {
			$this->expect('(');
			if ($this->peek() !== null && strcasecmp($this->peek(), 'select') === 0) {
				$candidates = $this->listSubquery($left_type);
			} else {
				$list = array($this->operand()[0]);
				while ($this->accept(',')) {
					$list[] = $this->operand()[0];
				}
				$candidates = function (array $scopes) use ($list) {
					return array_map(function ($item) use ($scopes) { return $item($scopes); }, $list);
				};
			}
			$this->expect(')');
			// like SQL: no match against a set holding NULL is unknown
			return function (array $scopes) use ($left, $candidates, $not) {
				$value = $left($scopes);
				if ($value === null) {
					return null;
				}
				$unknown = false;
				foreach ($candidates($scopes) as $candidate) {
					if ($candidate === null) {
						$unknown = true;
					} elseif (fake_sql_compare($value, $candidate) === 0) {
						return !$not;
					}
				}
				return $unknown ? null : $not;
			};
		}
		if ($this->accept('like')) {
			$pattern = $this->operand()[0];
			$escape = $this->accept('escape') ? $this->operand()[0] : null;
			return function (array $scopes) use ($left, $pattern, $escape, $not) {
				$value = $left($scopes);
				$like = $pattern($scopes);
				if ($value === null || $like === null) {
					return null;
				}
				return fake_sql_like((string)$value, (string)$like, $escape ? (string)$escape($scopes) : '\\') !== $not;
			};
		}
		$operator = $this->tokens[$this->pos++] ?? '';
		if ($not || !in_array($operator, array('=', '<>', '!=', '<', '<=', '>', '>='), true)) {
			throw new RuntimeException("unsupported SQL operator: ".$operator);
		}
		list($right, $right_type) = $this->operand();
		if ($left_type !== null && $right_type !== null && $left_type !== $right_type) {
			throw new RuntimeException("operator does not exist: ".$left_type." ".$operator." ".$right_type);
		}
		return function (array $scopes) use ($left, $right, $operator) {
			$a = $left($scopes);
			$b = $right($scopes);
			if ($a === null || $b === null) {
				return null;
			}
			$cmp = fake_sql_compare($a, $b);
			switch ($operator) {
				case '=': return $cmp === 0;
				case '<>':
				case '!=': return $cmp !== 0;
				case '<': return $cmp < 0;
				case '<=': return $cmp <= 0;
				case '>': return $cmp > 0;
				default: return $cmp >= 0;
			}
		};
	}

	// array(function (array $scopes) returning the value, Postgres type or null
	// when the type comes from the context: placeholders and literals)
	private function operand(): array {
		$token = $this->tokens[$this->pos++] ?? null;
		if ($token === null || in_array(strtolower($token), array('and', 'or', 'not', 'is', 'in', 'like', '(', ')', ','), true)) {
			throw new RuntimeException("unsupported SQL value: ".$token);
		}
		if (strcasecmp($token, 'cast') === 0) {
			$this->expect('(');
			$inner = $this->operand()[0];
			$this->expect('as');
			$type = strtolower($this->tokens[$this->pos++] ?? '');
			$this->expect(')');
			return array(function (array $scopes) use ($inner) {
				$value = $inner($scopes);
				return $value === null ? null : (string)$value;
			}, $type);
		}
		if ($token[0] === ':') {
			$value = $this->parameters[substr($token, 1)];
			return array(function () use ($value) { return $value; }, null);
		}
		if ($token[0] === "'") {
			$value = str_replace("''", "'", substr($token, 1, -1));
			return array(function () use ($value) { return $value; }, null);
		}
		if (ctype_digit($token)) {
			return array(function () use ($token) { return $token; }, null);
		}
		if (strcasecmp($token, 'now()') === 0) {
			return array(function () { return '2026-10-02 12:00:00+00'; }, null);
		}
		if (strcasecmp($token, 'true') === 0 || strcasecmp($token, 'false') === 0) {
			$value = strtolower($token);
			return array(function () use ($value) { return $value; }, null);
		}
		if (strcasecmp($token, 'null') === 0) {
			return array(function () { return null; }, null);
		}
		$column = preg_replace('/^\w+\./', '', $token);
		return array(function (array $scopes) use ($token) {
			return fake_sql_column($token, $scopes);
		}, FAKE_SQL_COLUMN_TYPES[$column] ?? null);
	}
}

// FusionPBX 5.6.5 column types where uuid and text columns meet
// (v_xml_cdr.bridge_uuid is text). Postgres has no text = uuid operator
const FAKE_SQL_COLUMN_TYPES = array(
	'xml_cdr_uuid' => 'uuid',
	'domain_uuid' => 'uuid',
	'extension_uuid' => 'uuid',
	'originating_leg_uuid' => 'uuid',
	'call_center_queue_uuid' => 'uuid',
	'bridge_uuid' => 'text',
);

// a column of the innermost row, or of the row whose alias qualifies it.
// a column the row doesn't have is NULL
function fake_sql_column(string $name, array $scopes) {
	if (strpos($name, '.') === false) {
		return end($scopes)['row'][$name] ?? null;
	}
	$alias = substr($name, 0, strpos($name, '.'));
	for ($i = count($scopes) - 1; $i >= 0; $i--) {
		if (in_array($alias, $scopes[$i]['aliases'], true)) {
			return $scopes[$i]['row'][$name] ?? null;
		}
	}
	throw new RuntimeException("unknown SQL alias: ".$name);
}

// compares like Postgres would for the stored types: timestamps as instants,
// booleans as true/false, everything else as text
function fake_sql_compare($a, $b): int {
	$a = is_bool($a) ? ($a ? 'true' : 'false') : (string)$a;
	$b = is_bool($b) ? ($b ? 'true' : 'false') : (string)$b;
	$timestamp = '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/';
	if (preg_match($timestamp, $a) && preg_match($timestamp, $b)) {
		$utc = new DateTimeZone('UTC');
		return (new DateTimeImmutable($a, $utc)) <=> (new DateTimeImmutable($b, $utc));
	}
	return strcmp($a, $b) <=> 0;
}

// LIKE: % is any text, _ one character, the escape character makes the next one literal
function fake_sql_like(string $value, string $pattern, string $escape): bool {
	$regex = '';
	for ($i = 0; $i < strlen($pattern); $i++) {
		$char = $pattern[$i];
		if ($char === $escape && $i + 1 < strlen($pattern)) {
			$regex .= preg_quote($pattern[++$i], '/');
		} elseif ($char === '%') {
			$regex .= '.*';
		} elseif ($char === '_') {
			$regex .= '.';
		} else {
			$regex .= preg_quote($char, '/');
		}
	}
	return preg_match('/^'.$regex.'$/s', $value) === 1;
}

class database {
	private static $instance = null;
	public $app_name;
	public $app_uuid;
	public $user_uuid;
	public $domain_uuid;

	// like FusionPBX: the user and domain come from the parameters, else from the session
	public function __construct(array $params = array()) {
		$this->user_uuid = !empty($params['user_uuid']) ? $params['user_uuid'] : ($_SESSION['user_uuid'] ?? null);
		$this->domain_uuid = !empty($params['domain_uuid']) ? $params['domain_uuid'] : ($_SESSION['domain_uuid'] ?? null);
	}

	// FusionPBX 5.6.5 database::new(): one shared instance, whose user and domain
	// are updated from the parameters, else the session, on every call
	public static function new(array $params = array()) {
		if (self::$instance === null) {
			self::$instance = new database($params);
		}
		if (!empty($params['user_uuid'])) {
			self::$instance->user_uuid = $params['user_uuid'];
		} elseif (!empty($_SESSION['user_uuid'])) {
			self::$instance->user_uuid = $_SESSION['user_uuid'];
		}
		if (!empty($params['domain_uuid'])) {
			self::$instance->domain_uuid = $params['domain_uuid'];
		} elseif (!empty($_SESSION['domain_uuid'])) {
			self::$instance->domain_uuid = $_SESSION['domain_uuid'];
		}
		return self::$instance;
	}

	// like FusionPBX 5.6.5, a database error returns false (simulated by select_fails)
	public function select(string $sql, ?array $parameters = array(), string $return_type = 'all') {
		if (FakeStore::read()['select_fails'] ?? false) {
			return false;
		}
		return fake_sql($sql, $parameters, $return_type);
	}

	public function execute(string $sql, ?array $parameters = array()) {
		return fake_sql($sql, $parameters);
	}

	/**
	 * Like FusionPBX 5.6.5's save(): a record whose table lacks the "<singular>_add"
	 * permission is skipped without an error (its table is added to "skipped" so
	 * tests can see it), saved records get insert_user from this object, and it
	 * returns false when the database rejects the records (simulated by save_fails).
	 * Records are stored in v_<table>, nested child records in their own tables.
	 */
	public function save(array $array) {
		$records = array();
		$collect = function ($table, $rows) use (&$collect, &$records) {
			foreach ($rows as $row) {
				foreach ($row as $key => $child) {
					if (is_array($child) && isset($child[0]) && is_array($child[0])) {
						$collect($key, $child);
						unset($row[$key]);
					}
				}
				$records[] = array($table, $row);
			}
		};
		foreach ($array as $table => $rows) {
			$collect($table, $rows);
		}

		if (FakeStore::read()['save_fails']) {
			return false;
		}
		$allowed = array();
		$skipped = array();
		foreach ($records as $record) {
			if (permission_exists(rtrim($record[0], 's').'_add')) {
				$record[1]['insert_user'] = $this->user_uuid;
				$allowed[] = $record;
			} else {
				$skipped[] = $record[0];
			}
		}

		FakeStore::update(function (&$state) use ($allowed, $skipped, $array) {
			$state['saved'][] = array('app_uuid' => $this->app_uuid, 'array' => $array);
			foreach ($allowed as $record) {
				$state['tables']['v_'.$record[0]][] = $record[1];
			}
			$state['skipped'] = array_merge($state['skipped'], $skipped);
		});
		return true;
	}
}

function uuid() {
	$n = FakeStore::update(function (&$state) {
		return ++$state['uuid_counter'];
	});
	return sprintf('00000000-0000-4000-8000-%012d', $n);
}

function is_uuid($str) {
	return is_string($str) && preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/i', $str) === 1;
}

function escape($string) {
	if (is_string($string)) {
		return htmlspecialchars($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	} elseif (is_numeric($string)) {
		return $string;
	}
	$string = (array) $string;
	if (isset($string[0])) {
		return htmlspecialchars((string)$string[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}
	return false;
}

function generate_password(int $length = 20, int $strength = 3): string {
	$chars = "0123456789";
	if ($strength >= 2) {
		$chars .= "abcdefghijkmnopqrstuvwxyz";
	}
	if ($strength >= 3) {
		$chars .= "ABCDEFGHIJKLMNPQRSTUVWXYZ";
	}
	if ($strength >= 4) {
		$chars .= "!^$%*?.";
	}
	$password = '';
	for ($i = 0; $i < $length; $i++) {
		$password .= $chars[random_int(0, strlen($chars) - 1)];
	}
	return $password;
}

/**
 * FusionPBX 5.6.5's permissions class: a singleton holding the permissions of
 * the session, or else of the user's groups, as they were when it was created.
 */
class permissions {
	private static $permission = null;
	private $permissions = array();

	public function __construct($database = null, $domain_uuid = null, $user_uuid = null) {
		$domain_uuid = is_uuid($domain_uuid) ? $domain_uuid : ($_SESSION['domain_uuid'] ?? null);
		$user_uuid = is_uuid($user_uuid) ? $user_uuid : ($_SESSION['user_uuid'] ?? null);
		if (isset($_SESSION['permissions'])) {
			$this->permissions = $_SESSION['permissions'];
			return;
		}
		$group_names = array_column((new groups($database, $domain_uuid, $user_uuid))->assigned(), 'group_name');
		foreach (FakeStore::read()['tables']['v_group_permissions'] ?? array() as $row) {
			if (in_array($row['group_name'], $group_names, true)
				&& ($row['domain_uuid'] === null || $row['domain_uuid'] === $domain_uuid)
				&& $row['permission_assigned'] === 'true') {
				$this->permissions[$row['permission_name']] = 1;
			}
		}
	}

	public static function new($database = null, $domain_uuid = null, $user_uuid = null) {
		if (self::$permission === null) {
			self::$permission = new permissions($database, $domain_uuid, $user_uuid);
		}
		return self::$permission;
	}

	public function exists($permission_name) {
		return !empty($permission_name) && isset($this->permissions[$permission_name]);
	}

	public function session() {
		foreach ($this->permissions as $permission_name => $row) {
			$_SESSION['permissions'][$permission_name] = true;
			$_SESSION['user']['permissions'][$permission_name] = true;
		}
	}
}

// FusionPBX 5.6.5: the user's groups (v_user_groups rows here carry group_name directly)
class groups {
	private $groups = array();

	public function __construct($database = null, $domain_uuid = null, $user_uuid = null) {
		if (is_uuid($domain_uuid) && is_uuid($user_uuid)) {
			foreach (FakeStore::read()['tables']['v_user_groups'] ?? array() as $row) {
				if ($row['domain_uuid'] === $domain_uuid && $row['user_uuid'] === $user_uuid) {
					$this->groups[] = $row;
				}
			}
		}
	}

	public function assigned() {
		return $this->groups;
	}

	public function session() {
		$_SESSION['groups'] = $this->groups;
		$_SESSION['user']['groups'] = $this->groups;
	}
}

function permission_exists($permission_name) {
	global $database, $domain_uuid, $user_uuid;
	return permissions::new($database, $domain_uuid, $user_uuid)->exists($permission_name);
}

function if_group($group) {
	foreach ($_SESSION['groups'] ?? array() as $row) {
		if (($row['group_name'] ?? null) === $group) {
			return true;
		}
	}
	return false;
}

function event_socket_create($host = null, $port = null, $password = null) {
	return FakeStore::read()['esl_available'] ? 'fake-socket' : false;
}

function event_socket_request($fp, $cmd) {
	return FakeStore::update(function (&$state) use ($cmd) {
		$state['esl_commands'][] = $cmd;
		return $state['esl_response'];
	});
}

class token {
	public function create($key) {
		$token = array('name' => bin2hex(random_bytes(8)), 'hash' => bin2hex(random_bytes(16)));
		$_SESSION['tokens'][$key][] = $token;
		return $token;
	}

	public function validate($key, $value = '') {
		foreach ($_SESSION['tokens'][$key] ?? array() as $token) {
			if (isset($_REQUEST[$token['name']]) && hash_equals($token['hash'], (string)$_REQUEST[$token['name']])) {
				return true;
			}
		}
		return false;
	}
}

class button {
	public static function create(array $array) {
		$attributes = '';
		foreach (array('type', 'name', 'value', 'id', 'onclick') as $attribute) {
			if (isset($array[$attribute])) {
				$attributes .= " ".$attribute."=\"".htmlspecialchars((string)$array[$attribute], ENT_QUOTES)."\"";
			}
		}
		if (!empty($array['link'])) {
			return "<a href=\"".htmlspecialchars($array['link'], ENT_QUOTES)."\">".($array['label'] ?? '')."</a>";
		}
		return "<button".$attributes.">".($array['label'] ?? '')."</button>";
	}
}

class modal {
	public static function create(array $array) {
		return "<div class=\"modal\" id=\"".$array['id']."\">".($array['actions'] ?? '')."</div>";
	}
}

class message {
	public static function add($message, $type = 'positive') {
		$_SESSION['messages'][$type][] = $message;
	}
}
