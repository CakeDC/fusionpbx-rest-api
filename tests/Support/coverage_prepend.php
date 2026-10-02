<?php
/*
 * auto_prepend_file of the HTTP test server when coverage is collected (see
 * HttpTestCase and `composer coverage`). Records which plugin lines a request
 * ran and saves them as a .cov file for phpcov to merge with the unit coverage.
 */

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;
use SebastianBergmann\CodeCoverage\Report\PHP as PhpReport;

if (PHP_SAPI !== 'cli-server' || getenv('REST_API_TEST_HARNESS') !== '1' || !getenv('COVERAGE_DIR') || !function_exists('xdebug_start_code_coverage')) {
	return;
}

xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);

register_shutdown_function(function () {
	$raw = xdebug_get_code_coverage();
	xdebug_stop_code_coverage();

	// the server runs a copy of the plugin. report the lines against the original files
	$served = getenv('COVERAGE_SERVED_DIR').'/';
	$plugin = getenv('PLUGIN_DIR').'/';
	$mapped = array();
	foreach ($raw as $file => $lines) {
		if (strpos($file, $served) === 0) {
			$mapped[$plugin.substr($file, strlen($served))] = $lines;
		}
	}
	if (!$mapped) {
		return;
	}

	require_once dirname(__DIR__, 2).'/vendor/autoload.php';
	$filter = new Filter();
	$filter->includeFiles(array_keys($mapped));
	$coverage = new CodeCoverage((new Selector())->forLineCoverage($filter), $filter);
	$coverage->append(RawCodeCoverageData::fromXdebugWithoutPathCoverage($mapped), $_SERVER['REQUEST_URI']);
	(new PhpReport())->process($coverage, getenv('COVERAGE_DIR').'/http-'.bin2hex(random_bytes(8)).'.cov');
});
