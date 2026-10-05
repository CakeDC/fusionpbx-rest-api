<?php
// fake FusionPBX resources/check_auth.php: send anonymous users to the login page
require_once __DIR__.'/fakes.php';

if (empty($_SESSION['user_uuid'])) {
	header('Location: /login.php');
	exit;
}
