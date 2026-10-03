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

// a list given as a JSON array or a comma-separated string (#43937). false
// when it isn't one, or has no items
function rest_api_parse_list($value) {
    if(is_string($value)) {
        $value = explode(",", $value);
    }
    if(!is_array($value)) {
        return false;
    }
    $items = array();
    foreach($value as $item) {
        if(!is_string($item) && !is_int($item)) {
            return false;
        }
        $item = trim((string)$item);
        if($item !== "") {
            $items[] = $item;
        }
    }
    return $items ? array_values(array_unique($items)) : false;
}

// true/false, "true"/"false", 1/0 or "1"/"0". null when it is none of them
function rest_api_parse_bool($value) {
    if($value === true || $value === 1 || $value === "1" || $value === "true") {
        return true;
    }
    if($value === false || $value === 0 || $value === "0" || $value === "false") {
        return false;
    }
    return null;
}

// an integer (or digits) from $min to $max, else false
function rest_api_parse_int($value, $min, $max) {
    if(is_string($value) && preg_match('/^[0-9]{1,18}$/D', $value)) {
        $value = (int)$value;
    }
    if(!is_int($value) || $value < $min || $value > $max) {
        return false;
    }
    return $value;
}

// an ISO 8601 date (YYYY-MM-DD) or date-time. a date-time without an offset
// is UTC. returns array(DateTimeImmutable in UTC, whether only a date was given),
// or false
function rest_api_parse_timestamp($value) {
    if(!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,6})?)?(?:Z|[+-](\d{2}):?(\d{2}))?)?$/D', $value, $m)) {
        return false;
    }
    if(!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        return false;
    }
    if(isset($m[4]) && ((int)$m[4] > 23 || (int)$m[5] > 59 || (int)($m[6] ?? 0) > 59)) {
        return false;
    }
    // real UTC offsets go from -12:00 to +14:00
    if(isset($m[7]) && $m[7] !== "" && ((int)$m[7] > 14 || (int)$m[8] > 59)) {
        return false;
    }
    $utc = new DateTimeZone("UTC");
    try {
        $date = new DateTimeImmutable($value, $utc);
    } catch(Exception $e) {
        return false;
    }
    return array($date->setTimezone($utc), !isset($m[4]));
}

// a literal text for LIKE ... ESCAPE '\': no wildcards
function rest_api_like_escape($value) {
    return str_replace(array("\\", "%", "_"), array("\\\\", "\\%", "\\_"), $value);
}
