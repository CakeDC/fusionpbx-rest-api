<?php
$required_params = array("caller_id_number", "destination_a", "destination_b");
$required_permissions = array("click_to_call_call");

// click-to-call: calls destination_a (the agent's own extension) first and
// bridges destination_b once it answers, both through the domain's dialplan.
// answers 201 with the call: its uuid is set up front (origination_uuid) and
// both legs carry the domain, so call-hangup, call-hold and the other call
// actions accept it
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
  $leg_a = "{origination_uuid=".$call_uuid.",".$variables."}loopback/".$body->destination_a."/".$domain_name;
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
