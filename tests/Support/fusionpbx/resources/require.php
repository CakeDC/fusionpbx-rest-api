<?php
// fake FusionPBX resources/require.php
require_once __DIR__.'/fakes.php';

// FPBX_SESSION_MODE=honored: like current FusionPBX, skip the session when $no_session is set
// FPBX_SESSION_MODE=ignored: like a FusionPBX that always starts the session
global $no_session;
if (session_status() === PHP_SESSION_NONE && (getenv('FPBX_SESSION_MODE') === 'ignored' || empty($no_session))) {
	session_start();
}
