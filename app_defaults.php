<?php
// run by Advanced -> Upgrade -> App Defaults, once per domain. the index is
// for the whole table, so only the first domain builds it
if (isset($domains_processed, $database) && $domains_processed == 1 && $database->type == 'pgsql') {
    // cdr-search and cdr-details look legs up by originating_leg_uuid (#43937),
    // which FusionPBX doesn't index. CONCURRENTLY doesn't block CDR inserts
    // while it builds on a large table
    $sql = "SELECT i.indisvalid FROM pg_class c JOIN pg_index i ON i.indexrelid = c.oid WHERE c.relname = :name";
    $index = $database->select($sql, array('name' => 'v_xml_cdr_originating_leg_uuid_idx'), 'row');

    // execute() returns false on an error (lock timeout, disk full) instead of
    // throwing. without a log line cdr-search would just run slow. the next
    // upgrade tries again, as the index is then missing or invalid

    // an interrupted build leaves an invalid index, which Postgres never uses
    if ($index && !in_array($index['indisvalid'], array(true, 't', 1, '1'), true)) {
        // IF NOT EXISTS would keep an invalid index the drop failed to remove
        if ($database->execute("DROP INDEX CONCURRENTLY IF EXISTS v_xml_cdr_originating_leg_uuid_idx") === false) {
            error_log('rest_api: could not drop the invalid index v_xml_cdr_originating_leg_uuid_idx: '.($database->message['message'] ?? 'unknown error'));
        } else {
            $index = false;
        }
    }
    if (!$index && $database->execute("CREATE INDEX CONCURRENTLY IF NOT EXISTS v_xml_cdr_originating_leg_uuid_idx ON v_xml_cdr (originating_leg_uuid)") === false) {
        error_log('rest_api: could not create the index v_xml_cdr_originating_leg_uuid_idx: '.($database->message['message'] ?? 'unknown error'));
    }
    unset($sql, $index);
}
