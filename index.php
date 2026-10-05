<?php
/*
	GNU Public License
	Version: GPL 3
*/
require_once "root.php";
require_once "resources/require.php";
require_once "resources/check_auth.php";
require_once "resources/header.php";
require_once "resources/paging.php";
require_once "lib/auth.php";

if(!permission_exists('rest_api_key_view')) {
	echo "permission denied";
	require_once "resources/footer.php";
	die();
}
$can_delete = permission_exists('rest_api_key_delete');

$object = new token;

if($can_delete && ($_POST['action'] ?? '') == "delete" && is_uuid($_POST['key_uuid'] ?? '')) {
	if(!$object->validate('rest_api_keys')) {
		message::add("invalid token", 'negative');
		header('Location: index.php');
		exit;
	}

	$sql = "DELETE FROM rest_api_keys WHERE key_uuid = :key_uuid";
	$parameters['key_uuid'] = $_POST['key_uuid'];
	$database = new database;
	$database->execute($sql, $parameters);
	unset($parameters);
}

$token = $object->create('rest_api_keys');

if($can_delete) {
	echo "<form method='post'>";
	echo modal::create([
		'id'=>'modal-delete',
		'type'=>'delete',
		'actions'=>button::create([
			'type'=>'submit',
			'label'=>"delete",
			'icon'=>'check',
			'id'=>'btn_delete',
			'style'=>'float: right; margin-left: 15px;',
			'collapse'=>'never',
			'name'=>'action',
			'value'=>'delete',
			'onclick'=>"modal_close();"
		]
	)]);
	echo "<input type='hidden' name='key_uuid' id='key_uuid'/>";
	echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>";
	echo "</form>";
}

echo "<div class='action_bar' id='action_bar'>\n";
echo "	<div class='heading'><b>REST API Keys</b></div>\n";
echo "	<div class='actions'>\n";
if(permission_exists('rest_api_key_add')) {
	echo button::create(['type'=>'button','label'=>"New",'icon'=>$_SESSION['theme']['button_icon_add'] ?? null,'id'=>'btn_add','name'=>'btn_add','link'=>'key_edit.php']);
}
echo "	</div>\n";
echo "	<div style='clear: both;'></div>\n";
echo "</div>\n";
echo "<br /><br />\n";
echo "endpoint: <code>https://".escape($_SERVER['HTTP_HOST'])."/app/rest_api/rest.php</code>\n";

// keys without a user (from before #43940, or whose user was deleted) can't authenticate
$sql = "SELECT k.key_uuid, k.name, k.key_enabled, k.expires, k.created, k.last_used, u.username, d.domain_name";
$sql .= " FROM rest_api_keys k LEFT JOIN v_users u ON u.user_uuid = k.user_uuid LEFT JOIN v_domains d ON d.domain_uuid = u.domain_uuid";
$sql .= " ORDER BY k.last_used DESC";
$database = new database;
$keys = $database->select($sql, null, 'all');
?>
<table class="table">
<tr>
	<th>Name</th>
	<th>Key ID</th>
	<th>User</th>
	<th>Enabled</th>
	<th>Expires</th>
	<th>Created</th>
	<th>Last Used</th>
<?php if($can_delete) { ?>
	<th>Actions</th>
<?php } ?>
</tr>
<?php
foreach($keys as $key) {
	$expired = !empty($key['expires']) && strtotime($key['expires']) <= time();
?>
<tr>
	<td><a href="key_edit.php?key_uuid=<?php echo escape($key['key_uuid']); ?>"><?php echo escape($key['name']); ?></a></td>
	<td><a href="key_edit.php?key_uuid=<?php echo escape($key['key_uuid']); ?>"><code><?php echo escape($key['key_uuid']); ?></code></a></td>
	<td><?php echo $key['username'] ? escape($key['username']."@".$key['domain_name']) : "<b>no user</b>"; ?></td>
	<td><?php echo rest_api_is_true($key['key_enabled']) ? "yes" : "<b>no</b>"; ?></td>
	<td><?php echo empty($key['expires']) ? "never" : escape($key['expires']).($expired ? " <b>expired</b>" : ""); ?></td>
	<td><?php echo escape($key['created']); ?></td>
	<td><?php echo escape($key['last_used']); ?></td>
<?php if($can_delete) { ?>
	<td class="middle button"><?php
		echo button::create(['type'=>'button','icon'=>$_SESSION['theme']['button_icon_delete'] ?? null,'onclick'=>"document.querySelector('#key_uuid').value = '".escape($key['key_uuid'])."'; modal_open('modal-delete','btn_delete');"]);
	?></td>
<?php } ?>
</tr>
<?php
}

echo "</table>";

require_once "footer.php";
