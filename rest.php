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

// FusionPBX's require.php switches domains when the query string asks for it.
// that checks a permission before we know the key's user, and FusionPBX keeps
// the (empty) result for the whole request. the API only reads the JSON body
$_GET = array();

// ask require.php not to start a session. in case it starts one anyway, make
// sure it can't resume a browser session from its cookie or send a cookie
$no_session = true;
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');

require_once "root.php";
require_once "resources/require.php";
require_once "lib/input_validation.php";
require_once "lib/auth.php";
require_once "lib/fields.php";

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

// the key must be enabled, not expired, and bound to an enabled user of an enabled domain
$key = rest_api_find_key(database::new(), $_SERVER['PHP_AUTH_USER']);
$rejection = rest_api_key_rejection($key, $_SERVER['PHP_AUTH_PW'], time());
if($rejection !== null) {
	error_log("rejecting request: ".$rejection." (".$_SERVER['PHP_AUTH_USER'].")");
	return_error("unauthorized", 401);
}

// from here on the request runs as the key's user
rest_api_start_user_request($key);

// set the key last used time
$sql = "UPDATE rest_api_keys SET last_used = NOW() WHERE key_uuid = :key_id";
database::new()->execute($sql, array('key_id' => $_SERVER['PHP_AUTH_USER']));

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

// only the action file can declare what it needs: anything set before, e.g.
// by an app's app_api.php, doesn't count. undeclared actions never run
$required_permissions = null;
include($file);
if(!is_array($required_permissions)) {
	error_log("refusing to run action ".$action.": it does not declare \$required_permissions");
	return_error("action does not declare permissions", 500);
}

$context = rest_api_resolve_domain($body, $key);
if(isset($context['error'])) {
	return_error($context['error'], $context['code']);
}

// checked before the parameters, so callers without access don't learn what an action expects
$missing_permissions = rest_api_missing_permissions($required_permissions);
if($missing_permissions) {
	http_response_code(403);
	echo json_encode(array("error" => "forbidden", "missing_permissions" => $missing_permissions));
	die();
}

$validation_errors = ensure_parameters($body, $required_params);
if($validation_errors) {
	return_error($validation_errors, 400);
}

if(function_exists('do_action')) {
	// actions declared as do_action($body) just ignore the context
	$resp = do_action($body, $context);
	if(!empty($resp['code'])) {
		http_response_code($resp['code']);
		unset($resp['code']);
	} elseif(!empty($resp['error'])) {
		http_response_code(500);
	}

	// a 204 has no body
	if(http_response_code() !== 204) {
		echo json_encode($resp);
	}
}
