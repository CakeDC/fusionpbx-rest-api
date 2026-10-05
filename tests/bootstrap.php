<?php
if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require dirname(__DIR__).'/vendor/autoload.php';

// the plugin code under test. PLUGIN_DIR lets the suite run against another
// checkout, e.g. an older commit, to confirm the tests catch its bugs
define('PLUGIN_DIR', rtrim(getenv('PLUGIN_DIR') ?: dirname(__DIR__), '/'));
define('FUSIONPBX_FAKES_DIR', __DIR__.'/Support/fusionpbx');
