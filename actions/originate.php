<?php
$required_params = array("caller_id_number", "destination_a", "destination_b");
$required_permissions = array("click_to_call_call");

// click-to-call: calls destination_a (the agent's own extension) first and
// bridges destination_b once it answers, destination_b through the domain's
// dialplan. answers 201 with the call: the agent's leg, whose uuid is set up
// front (origination_uuid) and which carries the domain, so call-hangup,
// call-hold, call-transfer and the other call actions accept it, and act on
// it as the agent's (destination_b is the other party).
// a loopback channel bows out once the call it places is bridged, so its
// uuid would be gone by the time the call actions get it: an extension of
// the domain is called directly (user/, as FusionPBX's click-to-call does),
// and any other destination_a through a loopback that doesn't bow out
function do_action($body) {
  foreach(array("caller_id_number", "destination_a", "destination_b") as $field) {
    if(!is_dial_number($body->{$field})) {
      return array("error" => "invalid ".$field, "code" => 400);
    }
  }

  // FusionPBX's select() returns false on a database error, which must not
  // pass for a missing domain
  $database = new database;
  $domains = $database->select("SELECT domain_name FROM v_domains WHERE domain_uuid = :domain_uuid", array("domain_uuid" => $body->domain_uuid), 'all');
  if(!is_array($domains)) {
    return array("error" => "database error", "code" => 500);
  }
  if(!$domains) {
    return array("error" => "domain not found", "code" => 404);
  }
  $domain_name = $domains[0]["domain_name"];

  $variables = "domain_uuid=".$body->domain_uuid.",domain_name=".$domain_name.",ignore_early_media=true,originate_timeout=30,effective_caller_id_number=".$body->caller_id_number;
  if(!empty($body->caller_id_name) && is_string($body->caller_id_name)) {
    $variables .= ",effective_caller_id_name=".rawurlencode($body->caller_id_name);
  }
  $call_uuid = uuid();
  // the agent's phone shows the caller ID too
  $leg_a_variables = "origination_uuid=".$call_uuid.",".$variables.",origination_caller_id_number=".$body->caller_id_number;
  if(!empty($body->caller_id_name) && is_string($body->caller_id_name)) {
    $leg_a_variables .= ",origination_caller_id_name=".rawurlencode($body->caller_id_name);
  }
  // the extension number goes into the dial string
  $extensions = $database->select("SELECT extension FROM v_extensions WHERE domain_uuid = :domain_uuid AND (extension = :number OR number_alias = :alias)", array("domain_uuid" => $body->domain_uuid, "number" => (string)$body->destination_a, "alias" => (string)$body->destination_a), 'all');
  if(!is_array($extensions)) {
    return array("error" => "database error", "code" => 500);
  }
  // an extension's own number wins over another's alias that equals it
  $extension = null;
  foreach($extensions as $row) {
    if($extension === null || (string)$row["extension"] === (string)$body->destination_a) {
      $extension = (string)$row["extension"];
    }
  }
  if($extension !== null && is_dial_number($extension)) {
    $leg_a = "{".$leg_a_variables."}user/".$extension."@".$domain_name;
  } else {
    $leg_a = "{".$leg_a_variables.",loopback_bowout=false}loopback/".$body->destination_a."/".$domain_name;
  }
  $leg_b = "{".$variables."}loopback/".$body->destination_b."/".$domain_name;
  $command = "api originate ".$leg_a." '&bridge(".$leg_b.")'";

  $reply = fs_api_value($command);
  if(is_array($reply)) {
    error_log("rest_api: ".$command." failed: ".json_encode($reply));
    // FreeSWITCH says why the call failed, e.g. -ERR NO_ANSWER
    if(preg_match('/^-ERR ([A-Z_]+)/', $reply["details"] ?? "", $cause)) {
      return array("error" => "call failed: ".$cause[1], "code" => 500);
    }
    return array("error" => "event socket error", "code" => 500);
  }

  $call = rest_api_call_after($call_uuid, $body->domain_uuid);
  if(!isset($call["error"])) {
    $call["code"] = 201;
  }
  return $call;
}
