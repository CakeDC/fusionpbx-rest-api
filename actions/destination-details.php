<?php
$required_params = array("number");
$required_permissions = array("destination_view");

function do_action($body, $context = array()) {
    $sql = "SELECT * FROM v_destinations WHERE destination_number = :number";
    $parameters['number'] = $body->number;
    // a user who may act on every domain can look a number up without knowing
    // its domain. everyone else only finds numbers of the domain they act on
    if(!empty($context['domain_explicit']) || empty($context['cross_domain'])) {
        $sql .= " AND domain_uuid = :domain_uuid";
        $parameters['domain_uuid'] = $body->domain_uuid;
    }
    $database = new database;
    $extension = $database->select($sql, $parameters, 'row');
    if(!$extension) {
        return array("error" => "no such destination", "code" => 404);
    }

    // destination_actions is JSON-encoded in the DB. parse it here (#5)
    if($extension['destination_actions']) {
        $extension['destination_actions'] = json_decode($extension['destination_actions']);
    }

    return $extension;
}
