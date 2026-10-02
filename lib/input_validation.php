<?php

function ensure_parameters($body, $required) {
    $missing = array();
    foreach($required as $param) {
        if(empty($body->{$param})) {
            $missing[] = $param;
        }
    }
    if(sizeof($missing) == 0) {
        return false;
    }

    return array("error" => "missing required parameter(s)", "missing_parameters" => $missing);
}

// numbers and extensions end up in event socket commands and dialplan XML,
// so only allow digits, * and # with an optional leading +
function is_dial_number($value) {
    return (is_string($value) || is_int($value)) && preg_match('/^\+?[0-9*#]+$/D', (string)$value) === 1;
}
