<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Gravity Tables read integration (wpmcp/gravitytables-read pair).
 *
 * Gravity Tables ("Advanced Data Tables for Gravity Forms") stores each table
 * definition as a row in its own custom table, {prefix}gravity_tables (columns
 * id, title, form_id, settings JSON, shortcode, status, timestamps; verified
 * against Gravity Tables 4.x). This integration reads that table directly,
 * the same way the Bricks integration reads _bricks_page_content_2 postmeta
 * directly rather than booting the plugin, so an agent can inventory the
 * tables a site publishes and read one table's full configuration.
 *
 * Read-only: rows are managed through Gravity Tables' own builder, and this
 * custom table is not a Safe_Mutation snapshot target, so writes are deferred.
 * Pairs naturally with the Gravity Forms integration, since each table is a
 * view over a Gravity Forms form's entries.
 */
class Gravity_Tables_Integration extends Integration_Dispatcher
{
    public function integration(): string
    {
        return 'gravitytables';
    }

    private static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'gravity_tables';
    }

    public function is_available(): bool
    {
        global $wpdb;
        $table    = self::table();
        $suppress = $wpdb->suppress_errors(true);
        // A lightweight probe: a SELECT against the table succeeds (empty
        // last_error) when Gravity Tables has created it, and errors when it
        // has not. More reliable than SHOW TABLES/information_schema, which
        // do not reflect the table inside the test harness transaction.
        // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- existence probe against a third-party table name built from $wpdb->prefix; no input reaches it.
        $wpdb->get_var("SELECT 1 FROM `{$table}` LIMIT 1");
        $exists = '' === $wpdb->last_error;
        $wpdb->suppress_errors($suppress);
        return $exists;
    }

    protected function summary(): string
    {
        return 'Gravity Tables (data tables built from Gravity Forms entries)';
    }

    protected function operations(): array
    {
        return [
            'list-tables' => [
                'mode'         => 'read',
                'description'  => 'List published Gravity Tables with id, title, linked form id, and shortcode',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => function (): array {
                    global $wpdb;
                    $table = self::table();
                    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- third-party table name built from $wpdb->prefix; values, when present, are bound by prepare().
                    $rows  = $wpdb->get_results(
                        "SELECT id, title, form_id, shortcode, updated_at FROM `{$table}` WHERE status = 'active' ORDER BY updated_at DESC",
                        ARRAY_A
                    );
                    $out = [];
                    foreach ((array) $rows as $r) {
                        $out[] = [
                            'id'         => (int) $r['id'],
                            'title'      => (string) $r['title'],
                            'form_id'    => (int) $r['form_id'],
                            'shortcode'  => (string) $r['shortcode'],
                            'updated_at' => $r['updated_at'] ?? null,
                        ];
                    }
                    return [ 'tables' => $out, 'total' => count($out) ];
                },
            ],
            'get-table' => [
                'mode'         => 'read',
                'description'  => 'Read one table\'s full configuration, including the decoded settings (selected fields, labels, sortable/filterable columns)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'table_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'table_id' ],
                ],
                'handler'      => function (array $args): array {
                    global $wpdb;
                    $table = self::table();
                    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- third-party table name built from $wpdb->prefix; values, when present, are bound by prepare().
                    $row   = $wpdb->get_row(
                        $wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", (int) $args['table_id']),
                        ARRAY_A
                    );
                    if (null === $row) {
                        return [ 'table' => null ];
                    }
                    $settings         = json_decode((string) ($row['settings'] ?? ''), true);
                    $row['settings']  = is_array($settings) ? $settings : [];
                    $row['id']        = (int) $row['id'];
                    $row['form_id']   = (int) ($row['form_id'] ?? 0);
                    return [ 'table' => $row ];
                },
            ],
        ];
    }
}
