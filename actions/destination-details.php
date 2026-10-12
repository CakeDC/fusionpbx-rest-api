<?php
$required_params = array("number");
$required_permissions = array("destination_view");

function do_action($body, $context = array()) {
    $sql = "SELECT ".implode(", ", REST_API_DESTINATION_FIELDS)." FROM v_destinations WHERE destination_number = :number";
    $parameters['number'] = $body->number;
    // a user who may act on every domain can look a number up without knowing
    // its domain. everyone else only finds numbers of the domain they act on
    if(!empty($context['domain_explicit']) || empty($context['cross_domain'])) {
        $sql .= " AND domain_uuid = :domain_uuid";
        $parameters['domain_uuid'] = $body->domain_uuid;
    }
    $database = new database;
    // FusionPBX's select() returns false on a database error
    $destinations = $database->select($sql, $parameters, 'all');
    if(!is_array($destinations)) {
        return array("error" => "database error", "code" => 500);
    }
    if(!$destinations) {
        return array("error" => "no such destination", "code" => 404);
    }
    // FusionPBX allows the same number twice (another context or prefix, or
    // another domain): which row is meant can't be told from the number
    if(count($destinations) > 1) {
        return array("error" => "ambiguous destination", "code" => 409);
    }

    return rest_api_decode_destination($destinations[0]);
}
