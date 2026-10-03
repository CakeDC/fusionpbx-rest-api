<?php
// API keys act as the FusionPBX user they are bound to (#43940)

// PostgreSQL booleans reach PHP as true/false or "t"/"f"; FusionPBX writes "true"/"false"
function rest_api_is_true($value) {
	return $value === true || $value === 1 || in_array($value, array('true', 't', '1'), true);
}
