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

function deny_access() {
    echo "permission denied";
    require_once "resources/footer.php";
    die();
}

// a POSTed text field, or "" when it is missing or not a string
function posted($name) {
    return is_string($_POST[$name] ?? null) ? $_POST[$name] : '';
}

if(!permission_exists('rest_api_key_view')) {
    deny_access();
}

$object = new token;
$database = new database;

$key_uuid = null;
$name = "";
$user_uuid = "";
$key_enabled = true;
$expires = "";
$expires_warning = null;
$key_secret = null;
$error = null;

if(!empty($_POST)) {
    if(!$object->validate('rest_api_keys')) {
        message::add("invalid token", 'negative');
        header('Location: index.php');
        exit;
    }

    $key_uuid = posted('key_uuid') !== '' ? posted('key_uuid') : null;
    if($key_uuid !== null && !is_uuid($key_uuid)) {
        header('Location: index.php');
        exit;
    }
    if(!permission_exists($key_uuid ? 'rest_api_key_edit' : 'rest_api_key_add')) {
        deny_access();
    }

    $name = posted('name');
    $user_uuid = posted('user_uuid');
    $key_enabled = posted('key_enabled') === 'true';
    $expires = posted('expires');

    // the key acts as this user, so it must exist
    $sql = "SELECT user_uuid FROM v_users WHERE user_uuid = :user_uuid";
    if(!is_uuid($user_uuid) || !$database->select($sql, array('user_uuid' => $user_uuid), 'column')) {
        $error = "select the user this key acts as";
    }
    $expires_at = null;
    if($expires !== '') {
        $time = strtotime($expires);
        if($time === false) {
            $error = "invalid expiry date";
        } else {
            // explicit offset: a timestamptz column would otherwise read it in the database's time zone
            $expires_at = date('Y-m-d H:i:sP', $time);
        }
    }

    $parameters = array(
        'name' => $name,
        'user_uuid' => $user_uuid,
        'key_enabled' => $key_enabled ? 'true' : 'false',
        'expires' => $expires_at,
    );
    if(!$error && $key_uuid) { // update
        $sql = "UPDATE rest_api_keys SET name = :name, user_uuid = :user_uuid, key_enabled = :key_enabled, expires = :expires WHERE key_uuid = :key_uuid";
        $parameters['key_uuid'] = $key_uuid;
        $database->execute($sql, $parameters);
        header('Location: key_edit.php?key_uuid='.urlencode($key_uuid), false, 302);
        die();
    }
    if(!$error) {
        // a new key. the secret is only shown in this response
        $key_uuid = uuid();
        $key_secret = generate_password(20, 3);
        $sql = "INSERT INTO rest_api_keys (key_uuid, name, key_secret, user_uuid, key_enabled, expires, created) VALUES (:key_uuid, :name, :key_secret, :user_uuid, :key_enabled, :expires, now())";
        $parameters['key_uuid'] = $key_uuid;
        $parameters['key_secret'] = password_hash($key_secret, PASSWORD_DEFAULT, array('cost' => 10));
        $database->execute($sql, $parameters);
    }
    unset($parameters);
} elseif(!empty($_GET['key_uuid'])) {
    if(!is_uuid($_GET['key_uuid'])) {
        header('Location: index.php');
        exit;
    }

    $key_uuid = $_GET['key_uuid'];
    $sql = "SELECT name, user_uuid, key_enabled, expires FROM rest_api_keys WHERE key_uuid = :key_uuid";
    $key = $database->select($sql, array('key_uuid' => $key_uuid), 'row');
    if(!$key) {
        header('Location: index.php');
        exit;
    }
    $name = (string)$key['name'];
    $user_uuid = (string)$key['user_uuid'];
    $key_enabled = rest_api_is_true($key['key_enabled']);
    if(!empty($key['expires'])) {
        $time = strtotime((string)$key['expires']);
        if($time === false) {
            // don't show 1970-01-01 for a value we can't read
            $expires_warning = "stored expiry could not be read: ".$key['expires'];
        } else {
            $expires = date('Y-m-d\TH:i:s', $time); // with seconds, so saving unchanged keeps the instant
        }
    }
} elseif(!permission_exists('rest_api_key_add')) {
    deny_access();
}

$editable = permission_exists($key_uuid ? 'rest_api_key_edit' : 'rest_api_key_add');
$disabled = $editable ? "" : " disabled='disabled'";

$sql = "SELECT u.user_uuid, u.username, u.user_enabled, d.domain_name";
$sql .= " FROM v_users u LEFT JOIN v_domains d ON d.domain_uuid = u.domain_uuid";
$sql .= " ORDER BY d.domain_name, u.username";
$users = $database->select($sql, null, 'all');

$token = $object->create('rest_api_keys');

if($key_uuid && permission_exists('rest_api_key_delete')) {
    echo "<form method='post' action='index.php'>";
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
    echo "<input type='hidden' name='key_uuid' id='key_uuid' value='".escape($key_uuid)."' />";
    echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>";
    echo "</form>";
}

echo "<form method='post' name='frm' id='frm'>\n";
echo "<input type='hidden' name='key_uuid' value='".escape($key_uuid)."' />";
echo "<input type='hidden' name='".$token['name']."' value='".$token['hash']."'>";

echo "<div class='action_bar' id='action_bar'>\n";
echo "	<div class='heading'><b>REST API Keys</b></div>\n";
echo "	<div class='actions'>\n";
echo button::create(['type'=>'button','label'=>"back",'icon'=>$_SESSION['theme']['button_icon_back'] ?? null,'id'=>'btn_back','style'=>'margin-right: 15px;','link'=>'index.php']);
if($key_uuid && permission_exists('rest_api_key_delete')) {
    echo button::create(['type'=>'button','label'=>'Delete','icon'=>$_SESSION['theme']['button_icon_delete'] ?? null,'onclick'=>"modal_open('modal-delete','btn_delete');"]);
}
if($editable) {
    echo button::create(['type'=>'submit','label'=>"save", 'icon'=>$_SESSION['theme']['button_icon_save'] ?? null,'id'=>'btn_save','style'=>'margin-left: 15px;']);
}
echo "	</div>\n";
echo "	<div style='clear: both;'></div>\n";
echo "</div>\n";
echo "<br /><br />\n";
if($error) {
    echo "<p><b>".escape($error)."</b></p>\n";
}
echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
if($key_secret) {
    $api_token = $key_uuid.":".$key_secret;
?>
    <tr>
        <td width="30%" class="vncellreq" valign="top" align="left" nowrap="nowrap">Secret</td>
        <td width="70%" class="vtable" align="left"><b><code><?php echo escape($api_token); ?></code></b><?php
            echo button::create(['type'=>'button','icon'=>'clipboard', 'onclick'=>'copy("'.escape($api_token).'")']);
        ?><br />will never be shown again</td>
    </tr>
<?php } ?>
    <tr>
        <td width="30%" class="vncellreq" valign="top" align="left" nowrap="nowrap">Name</td>
        <td width="70%" class="vtable" align="left">
            <input class="formfld" type="text" name="name" value="<?php echo escape($name); ?>"<?php echo $disabled; ?> /><br />
        </td>
    </tr>
    <tr>
        <td width="30%" class="vncellreq" valign="top" align="left" nowrap="nowrap">User</td>
        <td width="70%" class="vtable" align="left">
            <select class="formfld" name="user_uuid"<?php echo $disabled; ?>>
                <option value=""></option>
<?php
$user_found = false;
foreach($users as $user) {
    $label = $user['username']."@".$user['domain_name'].(rest_api_is_true($user['user_enabled']) ? "" : " (disabled)");
    $selected = $user['user_uuid'] === $user_uuid ? " selected='selected'" : "";
    $user_found = $user_found || $selected !== "";
    echo "<option value='".escape($user['user_uuid'])."'".$selected.">".escape($label)."</option>\n";
}
?>
            </select><br />
            <?php echo $key_uuid && !$user_found ? "<b>no user</b>: this key can't authenticate until you pick one<br />" : ""; ?>
            requests made with this key act as this user, with the permissions of its groups
        </td>
    </tr>
    <tr>
        <td width="30%" class="vncell" valign="top" align="left" nowrap="nowrap">Enabled</td>
        <td width="70%" class="vtable" align="left">
            <input type="checkbox" name="key_enabled" value="true"<?php echo $key_enabled ? " checked='checked'" : ""; ?><?php echo $disabled; ?> />
        </td>
    </tr>
    <tr>
        <td width="30%" class="vncell" valign="top" align="left" nowrap="nowrap">Expires</td>
        <td width="70%" class="vtable" align="left">
            <input class="formfld" type="datetime-local" step="1" name="expires" value="<?php echo escape($expires); ?>"<?php echo $disabled; ?> /><br />
            <?php echo $expires_warning ? "<b>".escape($expires_warning)."</b><br />" : ""; ?>
            empty: never expires
        </td>
    </tr>
</table>

</form>
<?php
require_once "footer.php";
