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
			'esl_commands' => array(),
			'esl_response' => "+OK 7f4de3d2-0000-4000-8000-00000000c411\n",
			'esl_available' => true,
			'save_fails' => false,
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

	$where = function ($clause) use ($value) {
		$conditions = array();
		foreach (preg_split('/ and /i', $clause) as $condition) {
			if (!preg_match('/^(?:\w+\.)?(\w+) = (.+)$/', trim($condition), $m)) {
				throw new RuntimeException("unsupported SQL condition: ".$condition);
			}
			$conditions[$m[1]] = $value($m[2]);
		}
		return function ($row) use ($conditions) {
			foreach ($conditions as $column => $expected) {
				if (!array_key_exists($column, $row) || (string)$row[$column] !== (string)$expected) {
					return false;
				}
			}
			return true;
		};
	};

	return FakeStore::update(function (&$state) use ($sql, $parameters, $return_type, $value, $where) {
		$state['queries'][] = array('sql' => $sql, 'parameters' => $parameters);

		if (preg_match('/^select (.+?) from (\w+)(?: where (.+?))?(?: order by (\w+)( desc| asc)?)?(?: limit (\d+))?$/i', $sql, $m)) {
			$rows = $state['tables'][$m[2]] ?? array();
			if (!empty($m[3])) {
				$rows = array_values(array_filter($rows, $where($m[3])));
			}
			if (!empty($m[4])) {
				$column = $m[4];
				usort($rows, function ($a, $b) use ($column) { return strcmp((string)($a[$column] ?? ''), (string)($b[$column] ?? '')); });
				if (strcasecmp(trim($m[5] ?? ''), 'desc') === 0) {
					$rows = array_reverse($rows);
				}
			}
			if (!empty($m[6])) {
				$rows = array_slice($rows, 0, (int)$m[6]);
			}
			if (trim($m[1]) !== '*') {
				$columns = array_map(function ($c) { return preg_replace('/^\w+\./', '', trim($c)); }, explode(',', $m[1]));
				$rows = array_map(function ($row) use ($columns) {
					$out = array();
					foreach ($columns as $c) {
						$out[$c] = $row[$c] ?? null;
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
			$matches_row = $where($m[3]);
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
			$matches_row = $where($m[2]);
			$state['tables'][$m[1]] = array_values(array_filter($state['tables'][$m[1]] ?? array(), function ($row) use ($matches_row) {
				return !$matches_row($row);
			}));
			return true;
		}

		throw new RuntimeException("unsupported SQL: ".$sql);
	});
}

class database {
	public $app_name;
	public $app_uuid;

	public function select(string $sql, ?array $parameters = array(), string $return_type = 'all') {
		return fake_sql($sql, $parameters, $return_type);
	}

	public function execute(string $sql, ?array $parameters = array()) {
		return fake_sql($sql, $parameters);
	}

	/**
	 * Like FusionPBX's save(): every record needs the "<singular>_add" permission,
	 * and it returns false when the database rejects the records (simulated by save_fails).
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
		foreach ($records as $record) {
			if (!permission_exists(rtrim($record[0], 's').'_add')) {
				return false;
			}
		}

		FakeStore::update(function (&$state) use ($records, $array) {
			$state['saved'][] = array('app_uuid' => $this->app_uuid, 'array' => $array);
			foreach ($records as $record) {
				$state['tables']['v_'.$record[0]][] = $record[1];
			}
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

function permission_exists($permission_name) {
	return !empty($_SESSION['permissions'][$permission_name]);
}

function if_group($group) {
	return in_array($group, $_SESSION['groups'] ?? array(), true);
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
