<?php
function parse_fs($command) {
  $fp = event_socket_create($_SESSION['event_socket_ip_address'] ?? null, $_SESSION['event_socket_port'] ?? null, $_SESSION['event_socket_password'] ?? null);
  if (!$fp) {
    return array("error" => "Failed to connect to event socket");
  }

  $response = event_socket_request($fp, $command);

  $lines = explode("\n", trim($response));

  $status = array_pop($lines);

  if($status != "+OK") {
    return array("error" => "freeswitch rejected request", "details" => $status);
  }

  $keys = explode("|", array_shift($lines));

  $out = array();
  foreach($lines as $orig_line) {
    $line = array();
    foreach(explode("|", $orig_line) as $key => $value) {
      $line[$keys[$key]] = $value;
    }
    $out[] = $line;
  }

  return $out;
}

// the reply of a command that answers a bare value ("Available", "+OK"),
// trimmed, or an error array like parse_fs()'s. 5.6.5's event_socket_request()
// returns false when it can't reach the event socket
function fs_api_value($command) {
  $fp = event_socket_create($_SESSION['event_socket_ip_address'] ?? null, $_SESSION['event_socket_port'] ?? null, $_SESSION['event_socket_password'] ?? null);
  if (!$fp) {
    return array("error" => "Failed to connect to event socket");
  }

  $response = trim((string)event_socket_request($fp, $command));
  if ($response === "" || strpos($response, "-ERR") === 0) {
    return array("error" => "freeswitch rejected request", "details" => $response);
  }
  return $response;
}
