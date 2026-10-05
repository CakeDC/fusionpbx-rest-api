<?php
// test-only login: ?permissions=a,b creates a session with those permissions
require_once __DIR__.'/resources/fakes.php';

session_start();
$_SESSION['user_uuid'] = '00000000-0000-4000-8000-0000000000ad';
$_SESSION['groups'] = array(array('group_name' => 'superadmin'));
$_SESSION['permissions'] = array();
foreach (array_filter(explode(',', $_GET['permissions'] ?? '')) as $permission) {
	$_SESSION['permissions'][$permission] = true;
}
echo "logged in";
