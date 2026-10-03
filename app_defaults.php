<?php
// run by Advanced -> Upgrade -> App Defaults, once per domain. the index is
// for the whole table, so only the first domain builds it
if (isset($domains_processed, $database) && $domains_processed == 1 && $database->type == 'pgsql') {
    // cdr-search and cdr-details look legs up by originating_leg_uuid (#43937),
    // which FusionPBX doesn't index. CONCURRENTLY doesn't block CDR inserts
    // while it builds on a large table
    $sql = "SELECT i.indisvalid FROM pg_class c JOIN pg_index i ON i.indexrelid = c.oid WHERE c.relname = :name";
    $index = $database->select($sql, array('name' => 'v_xml_cdr_originating_leg_uuid_idx'), 'row');

    // an interrupted build leaves an invalid index, which Postgres never uses
    if ($index && !in_array($index['indisvalid'], array(true, 't', 1, '1'), true)) {
        $database->execute("DROP INDEX CONCURRENTLY IF EXISTS v_xml_cdr_originating_leg_uuid_idx");
        $index = false;
    }
    if (!$index) {
        $database->execute("CREATE INDEX CONCURRENTLY IF NOT EXISTS v_xml_cdr_originating_leg_uuid_idx ON v_xml_cdr (originating_leg_uuid)");
    }
    unset($sql, $index);
}
