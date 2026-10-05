<?php
$required_params = array();
$required_permissions = array();

function do_action($body, $context = array()) {
    $database = new database;
    if(empty($context['domain_explicit']) && !empty($body->domain_name)) {
        if(!is_string($body->domain_name)) {
            return array("error" => "domain not found", "code" => 404);
        }
        $sql = "SELECT ".implode(", ", REST_API_DOMAIN_FIELDS)." FROM v_domains WHERE domain_name = :domain_name";
        $domain = $database->select($sql, array('domain_name' => $body->domain_name), 'row');
        // rest.php only checked the domain_uuid it filled in. a named domain the
        // user may not act on is "not found" rather than forbidden, so names
        // don't reveal which domains exist
        if($domain && $domain['domain_uuid'] !== ($context['user_domain_uuid'] ?? null) && empty($context['cross_domain'])) {
            $domain = false;
        }
    } else {
        $sql = "SELECT ".implode(", ", REST_API_DOMAIN_FIELDS)." FROM v_domains WHERE domain_uuid = :domain_uuid";
        $domain = $database->select($sql, array('domain_uuid' => $body->domain_uuid), 'row');
    }
    if(!$domain) {
        return array("error" => "domain not found", "code" => 404);
    }
    return $domain;
}
