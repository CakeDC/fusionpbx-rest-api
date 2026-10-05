<?php
namespace RestApi\Test\Support;

use PHPUnit\Framework\TestCase;

/**
 * Serves the plugin from a fake FusionPBX document root with PHP's built-in
 * web server: <docroot>/app/rest_api is a copy of the plugin, and
 * <docroot>/resources holds the FusionPBX fakes.
 */
abstract class HttpTestCase extends TestCase
{
	protected static string $docroot;
	protected static string $stateFile;
	protected static string $sessionDir;
	protected static string $baseUrl;
	/** @var resource */
	private static $server;

	/** FPBX_SESSION_MODE of the fake require.php: "honored" or "ignored". */
	protected static function sessionMode(): string
	{
		return 'honored';
	}

	/** Extra php -d settings for the server. */
	protected static function phpSettings(): array
	{
		return array();
	}

	/** Extra files to place in the document root, as path => contents. */
	protected static function extraFiles(): array
	{
		return array();
	}

	public static function setUpBeforeClass(): void
	{
		require_once FUSIONPBX_FAKES_DIR.'/resources/fakes.php';

		self::$docroot = sys_get_temp_dir().'/rest_api_test_'.bin2hex(random_bytes(6));
		self::copyTree(FUSIONPBX_FAKES_DIR, self::$docroot);
		self::copyTree(PLUGIN_DIR, self::$docroot.'/app/rest_api', array('.git', '.idea', 'vendor', 'tests', '.phpunit.cache'));
		foreach (static::extraFiles() as $path => $contents) {
			@mkdir(dirname(self::$docroot.'/'.$path), 0777, true);
			file_put_contents(self::$docroot.'/'.$path, $contents);
		}
		self::$stateFile = self::$docroot.'.state.json';
		self::$sessionDir = self::$docroot.'.sessions';
		mkdir(self::$sessionDir);

		$port = self::freePort();
		self::$baseUrl = 'http://127.0.0.1:'.$port;
		// show every PHP warning in the response, so a warning breaks the test that triggers it
		$command = array(PHP_BINARY, '-d', 'session.save_path='.self::$sessionDir, '-d', 'display_errors=1', '-d', 'error_reporting='.E_ALL, '-d', 'html_errors=0');
		$coverage_dir = getenv('COVERAGE_DIR') ? realpath(getenv('COVERAGE_DIR')) : false;
		if ($coverage_dir) {
			if (!self::serverHasXdebug()) {
				array_push($command, '-d', 'zend_extension=xdebug');
			}
			array_push($command, '-d', 'xdebug.mode=coverage', '-d', 'auto_prepend_file='.__DIR__.'/coverage_prepend.php');
		}
		foreach (static::phpSettings() as $name => $value) {
			array_push($command, '-d', $name.'='.$value);
		}
		array_push($command, '-S', '127.0.0.1:'.$port, '-t', self::$docroot);
		$env = array_merge(getenv(), array(
			'REST_API_TEST_HARNESS' => '1',
			'FAKE_DB_FILE' => self::$stateFile,
			'FPBX_SESSION_MODE' => static::sessionMode(),
			'COVERAGE_DIR' => (string)$coverage_dir,
			'COVERAGE_SERVED_DIR' => self::$docroot.'/app/rest_api',
			'PLUGIN_DIR' => PLUGIN_DIR,
		));
		self::$server = proc_open($command, array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, null, $env);

		for ($i = 0; $i < 100; $i++) {
			$socket = @fsockopen('127.0.0.1', $port);
			if ($socket) {
				fclose($socket);
				return;
			}
			usleep(20000);
		}
		self::fail('PHP built-in server did not start');
	}

	public static function tearDownAfterClass(): void
	{
		proc_terminate(self::$server);
		proc_close(self::$server);
		exec('rm -rf '.escapeshellarg(self::$docroot).' '.escapeshellarg(self::$sessionDir).' '.escapeshellarg(self::$stateFile));
	}

	protected function setUp(): void
	{
		putenv('FAKE_DB_FILE='.self::$stateFile);
		\FakeStore::reset($this->tables());
		array_map('unlink', glob(self::$sessionDir.'/sess_*'));
	}

	protected function tearDown(): void
	{
		putenv('FAKE_DB_FILE');
	}

	/** Initial table rows, keyed by table name. */
	protected function tables(): array
	{
		return array();
	}

	protected function state(): array
	{
		return \FakeStore::read();
	}

	/**
	 * @return array{status: int, headers: array<string, list<string>>, body: string}
	 */
	protected function request(string $method, string $path, string $body = '', array $headers = array()): array
	{
		$headers += array('Content-Type' => 'application/json');
		$header_lines = array();
		foreach ($headers as $name => $value) {
			$header_lines[] = $name.': '.$value;
		}
		$context = stream_context_create(array('http' => array(
			'method' => $method,
			'header' => implode("\r\n", $header_lines),
			'content' => $body,
			'ignore_errors' => true,
			'follow_location' => 0,
			'timeout' => 10,
		)));
		$stream = fopen(self::$baseUrl.$path, 'r', false, $context);
		$response_headers = stream_get_meta_data($stream)['wrapper_data'];
		$response_body = stream_get_contents($stream);
		fclose($stream);

		$status = (int)explode(' ', $response_headers[0] ?? 'HTTP/1.1 0')[1];
		$parsed = array();
		foreach (array_slice($response_headers, 1) as $line) {
			list($name, $value) = array_map('trim', explode(':', $line, 2));
			$parsed[strtolower($name)][] = $value;
		}
		return array('status' => $status, 'headers' => $parsed, 'body' => (string)$response_body);
	}

	private static function copyTree(string $from, string $to, array $exclude = array()): void
	{
		@mkdir($to, 0777, true);
		foreach (scandir($from) as $entry) {
			if ($entry === '.' || $entry === '..' || in_array($entry, $exclude, true)) {
				continue;
			}
			if (is_dir($from.'/'.$entry)) {
				self::copyTree($from.'/'.$entry, $to.'/'.$entry);
			} else {
				copy($from.'/'.$entry, $to.'/'.$entry);
			}
		}
	}

	/** Whether the PHP binary loads Xdebug from its own configuration. */
	private static function serverHasXdebug(): bool
	{
		return trim((string)shell_exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg('echo (int)extension_loaded("xdebug");'))) === '1';
	}

	private static function freePort(): int
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0');
		$port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
		fclose($socket);
		return $port;
	}
}
