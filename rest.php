<?php
// never start or resume a FusionPBX browser session for API requests, so
// anything written to $_SESSION here only lives for this request
$no_session = true;

require_once "root.php";
require_once "resources/require.php";
require_once "lib/input_validation.php";

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
if(!preg_match('/^[a-z0-9-]+$/', $action)) {
	return_error("unknown action", 400);
}
$file = __DIR__."/actions/".$action.".php";
if(!empty($body->app)) {
	$app = $body->app;
	if(!is_string($app) || !preg_match('/^[a-z0-9_]+$/', $app)) {
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
	if($resp['code']) {
		http_response_code($resp['code']);
		unset($resp['code']);
	} elseif($resp['error']) {
		http_response_code(500);
	}

	echo json_encode($resp);
}
