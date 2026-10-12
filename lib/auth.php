<?php
// API keys act as the FusionPBX user they are bound to

// PostgreSQL booleans reach PHP as true/false or "t"/"f"; FusionPBX writes "true"/"false"
function rest_api_is_true($value) {
	return $value === true || $value === 1 || in_array($value, array('true', 't', '1'), true);
}

// a hash of a random secret, checked when the key doesn't exist so the
// response time doesn't reveal which key IDs exist
const REST_API_DUMMY_HASH = '$2y$10$RMOGGby4/44PfMH0JmbH5e/WVfRdEXEWRh0nwMH3D0qGsNa.RTpVW';

// the key with its user and the user's domain; their columns are null when the
// key has no user or the user was deleted
function rest_api_find_key($database, $key_uuid) {
	$sql = "SELECT k.key_secret, k.key_enabled, k.expires,";
	$sql .= " u.user_uuid, u.username, u.user_enabled,";
	$sql .= " d.domain_uuid, d.domain_name, d.domain_enabled";
	$sql .= " FROM rest_api_keys k";
	$sql .= " LEFT JOIN v_users u ON u.user_uuid = k.user_uuid";
	$sql .= " LEFT JOIN v_domains d ON d.domain_uuid = u.domain_uuid";
	$sql .= " WHERE k.key_uuid = :key_uuid";
	return $database->select($sql, array('key_uuid' => $key_uuid), 'row');
}

// why the key can't be used, for the log, or null when it can
function rest_api_key_rejection($key, $secret, $now) {
	if(!$key) {
		password_verify($secret, REST_API_DUMMY_HASH);
		return "unknown key";
	}
	if(!password_verify($secret, (string)$key['key_secret'])) {
		return "invalid secret";
	}
	if(!rest_api_is_true($key['key_enabled'])) {
		return "key disabled";
	}
	// an expiry that can't be read counts as passed
	if(!empty($key['expires'])) {
		$expires = strtotime($key['expires']);
		if($expires === false || $expires <= $now) {
			return "key expired";
		}
	}
	if(empty($key['user_uuid'])) {
		return "key has no user";
	}
	if(!rest_api_is_true($key['user_enabled'])) {
		return "user disabled";
	}
	if(empty($key['domain_uuid']) || !rest_api_is_true($key['domain_enabled'])) {
		return "domain disabled";
	}
	return null;
}

/**
 * Makes the rest of the request run as the key's user, as FusionPBX does for a
 * logged-in user. Must run before anything checks a permission: FusionPBX 5.6.5
 * keeps the permissions of the first check for the whole request, and its
 * shared database object records the user as insert_user.
 */
function rest_api_start_user_request(array $key) {
	global $database, $domain_uuid, $user_uuid;

	$domain_uuid = $key['domain_uuid'];
	$user_uuid = $key['user_uuid'];
	$_SESSION['domain_uuid'] = $domain_uuid;
	$_SESSION['domain_name'] = $key['domain_name'];
	$_SESSION['user_uuid'] = $user_uuid;
	$_SESSION['username'] = $key['username'];

	$database = database::new(array('user_uuid' => $user_uuid, 'domain_uuid' => $domain_uuid));
	// permissions come from the groups of the user's own domain, whatever domain the request acts on
	(new groups($database, $domain_uuid, $user_uuid))->session();
	permissions::new($database, $domain_uuid, $user_uuid)->session();
}

// the permissions in $required that the request's user doesn't have
function rest_api_missing_permissions(array $required) {
	$missing = array();
	foreach($required as $permission) {
		if(!permission_exists($permission)) {
			$missing[] = $permission;
		}
	}
	return $missing;
}

/**
 * Picks the domain the request acts on and checks the user may act on it.
 * Sets $body->domain_uuid, to the user's own domain when the request has none.
 * Returns the context passed to do_action(), or array("error" => ..., "code" => ...).
 */
function rest_api_resolve_domain($body, array $key) {
	$context = array(
		'domain_explicit' => !empty($body->domain_uuid),
		'cross_domain' => permission_exists('domain_select'),
		'user_domain_uuid' => $key['domain_uuid'],
	);
	if(!$context['domain_explicit']) {
		$body->domain_uuid = $key['domain_uuid'];
		return $context;
	}
	if(!is_uuid($body->domain_uuid)) {
		return array("error" => "invalid domain_uuid", "code" => 400);
	}
	$body->domain_uuid = strtolower($body->domain_uuid);
	if($body->domain_uuid === strtolower($key['domain_uuid'])) {
		return $context;
	}
	// before looking the domain up, so the answer doesn't reveal whether it exists
	if(!$context['cross_domain']) {
		return array("error" => "forbidden", "code" => 403);
	}
	$sql = "SELECT domain_enabled FROM v_domains WHERE domain_uuid = :domain_uuid";
	$enabled = database::new()->select($sql, array('domain_uuid' => $body->domain_uuid), 'column');
	if(!rest_api_is_true($enabled)) {
		return array("error" => "domain not found", "code" => 404);
	}
	return $context;
}
