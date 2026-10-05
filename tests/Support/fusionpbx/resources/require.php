<?php
// fake FusionPBX resources/require.php
require_once __DIR__.'/fakes.php';

// like FusionPBX 5.6.5, connect before anyone knows the user
global $database;
$database = database::new();

// FPBX_SESSION_MODE=honored: like current FusionPBX, skip the session when $no_session is set
// FPBX_SESSION_MODE=ignored: like a FusionPBX that always starts the session
global $no_session;
if (session_status() === PHP_SESSION_NONE && (getenv('FPBX_SESSION_MODE') === 'ignored' || empty($no_session))) {
	session_start();
}

// like FusionPBX 5.6.5 require.php:157, switching domains from the query string
// checks a permission, which fixes the permissions for the rest of the request
if (!empty($_GET['domain_uuid']) && is_uuid($_GET['domain_uuid']) && ($_GET['domain_change'] ?? '') === 'true') {
	permission_exists('domain_select');
}
