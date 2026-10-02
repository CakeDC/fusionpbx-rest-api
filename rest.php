<?php
// API requests must never read or write a FusionPBX browser session, so
// anything written to $_SESSION here only lives for this request.
// close a session started by session.auto_start without saving it: keep a
// browser user's session as it was, discard a new empty one and its cookie
if(session_status() === PHP_SESSION_ACTIVE) {
	if(empty($_SESSION)) {
		session_destroy();
	} else {
		session_abort();
	}
	header_remove('Set-Cookie');
}
$_SESSION = array();

// ask require.php not to start a session. in case it starts one anyway, make
// sure it can't resume a browser session from its cookie or send a cookie
$no_session = true;
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');

require_once "root.php";
require_once "resources/require.php";
require_once "lib/input_validation.php";

// whatever require.php did, never save the session. cookies are disabled, so
// any session started from here on is a new, empty one and safe to destroy
if(session_status() === PHP_SESSION_ACTIVE) {
	session_destroy();
}
register_shutdown_function(function() {
	if(session_status() === PHP_SESSION_ACTIVE) {
		session_destroy();
	}
});

function return_error($msg, $code=500) {
	http_response_code($code);
	echo json_encode(array("error" => $msg));
	die();
}

if(!isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
	error_log("rejecting request with no auth");
	return_error("unauthorized", 401);
}

if(!is_uuid($_SERVER['PHP_AUTH_USER'])) {
	error_log("rejecting request with malformed token identifier");
	return_error("unauthorized", 401);
}

// get the hash of the secret key for this key id out of the database
$sql = "SELECT key_secret FROM rest_api_keys WHERE key_uuid = :key_id";
$parameters['key_id'] = $_SERVER['PHP_AUTH_USER'];
$database = new database;
$secret = $database->select($sql, $parameters, 'column');
if(!$secret) {
	// spend the same time as a real check so response timing doesn't reveal which key IDs exist
	password_verify($_SERVER['PHP_AUTH_PW'], '$2y$10$RMOGGby4/44PfMH0JmbH5e/WVfRdEXEWRh0nwMH3D0qGsNa.RTpVW');
	error_log("rejecting request with invalid token identifier (".$_SERVER['PHP_AUTH_USER'].")");
	return_error("unauthorized", 401);
}

// verify the hash
if(!password_verify($_SERVER['PHP_AUTH_PW'], $secret)) {
	error_log("rejecting request with valid token identifier but invalid secret");
	return_error("unauthorized", 401);
}

// set the key last used time
$sql = "UPDATE rest_api_keys SET last_used = NOW() WHERE key_uuid = :key_id";
$database = new database;
$result = $database->execute($sql, $parameters);
unset($parameters);

$body = json_decode(file_get_contents('php://input'));
if(!is_object($body)) {
	return_error("request body must be a JSON object", 400);
}

$validation_errors = ensure_parameters($body, array("action"));
if($validation_errors) {
	return_error($validation_errors, 400);
}

// action and app names end up in include paths, so only allow plain names
$action = is_string($body->action) ? strtolower($body->action) : "";
if(!preg_match('/^[a-z0-9-]+$/D', $action)) {
	return_error("unknown action", 400);
}
$file = __DIR__."/actions/".$action.".php";
if(!empty($body->app)) {
	$app = $body->app;
	if(!is_string($app) || !preg_match('/^[a-z0-9_]+$/D', $app)) {
		return_error("unknown app", 400);
	}
	$app_dir = realpath(__DIR__."/../".$app);
	$app_index = $app_dir."/app_api.php";
	if(!$app_dir || !file_exists($app_index)) {
		return_error("unknown app", 400);
	}
	include($app_index);
	if(!empty($app_api[$app][$action])) {
		// the app's own mapping must not point outside the app directory
		$file = realpath($app_dir."/".$app_api[$app][$action]);
		if(!$file || strpos($file, $app_dir."/") !== 0) {
			return_error("unknown action", 400);
		}
	}
}

if(!file_exists($file)) {
	return_error("unknown action", 400);
}

include($file);
$validation_errors = ensure_parameters($body, $required_params);
if($validation_errors) {
	return_error($validation_errors, 400);
}

if(function_exists('do_action')) {
	$resp = do_action($body);
	if(!empty($resp['code'])) {
		http_response_code($resp['code']);
		unset($resp['code']);
	} elseif(!empty($resp['error'])) {
		http_response_code(500);
	}

	echo json_encode($resp);
}
