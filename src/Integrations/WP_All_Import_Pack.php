<?php

namespace WPMCP\Integrations;

use WPMCP\Privacy\Masking;
use WPMCP\Safety\Plugin_Table_Rows_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WP All Import and WP All Export saved jobs (issue #909) on the
 * plugin-data pair, free tier, read only, so it adds no top-level tool and
 * writes nothing: the ops answer "which feeds and exports does this site
 * run, what do they map, and how did the last run go".
 *
 * Both plugins are free on wordpress.org (read in WP All Import 4.2.0 and
 * WP All Export 1.6.0). Their tables:
 *  - {prefix}pmxi_imports (WP All Import, PMXI_VERSION): id, friendly_name,
 *    name (the source file name), type (the SOURCE kind: url, upload, file
 *    or ftp; the post type is options.custom_type), feed_type, path (the
 *    source URL or path), xpath, root_element, the processing, executing,
 *    triggered, canceled and failed flags, the record counters and
 *    last_activity. options is PHP-serialized and holds the mapping and the
 *    FTP connection (ftp_host, ftp_port, ftp_path, ftp_username,
 *    ftp_password, ftp_private_key).
 *  - {prefix}pmxi_history: one row per run (import_id, type manual,
 *    processing, trigger, continue or cli, time_run in seconds, date,
 *    summary). The run's log file is uploads/wpallimport/logs/<run id>.html,
 *    or inside a md5(<run id> . NONCE_SALT) folder there when the plugin's
 *    secure mode is on (its default).
 *  - {prefix}pmxe_exports (WP All Export, PMXE_VERSION): id, friendly_name,
 *    exported, the status flags, last_activity and the serialized options
 *    (cpt, export_to, the field list cc_label, cc_type, cc_name, custom PHP
 *    and SQL per field in cc_php, cc_code and cc_sql, filters, schedule and
 *    the notification address scheduled_email).
 *
 * Secrets never leave: an FTP password or private key, and a username, are
 * reported as has_password, has_private_key and has_username only; the
 * user and password part of a source URL is removed and a query value whose
 * name says key, token, secret, password, signature or auth (or that looks
 * like a random token) becomes [redacted]. Export fields come back without
 * their custom PHP or SQL code (php:true says one is set), and the raw
 * WHERE and JOIN clauses as has_custom_where. Run logs are cut to their
 * warnings and errors, at most 50, with emails and IPs masked and links
 * that carry a token removed, since a log line can quote any record. The
 * export notification address is personal data: left out of lists, full in
 * wpallexport-get-export (manage_options).
 *
 * Running an import is not part of this slice: an import rewrites posts in
 * bulk and the plugin keeps no before-image, so it cannot be undone the way
 * every WP MCP write must be. Running an export is left with it.
 *
 * Presence is filterable (wpmcp_wp_all_import_active,
 * wpmcp_wp_all_export_active), and each plugin gates only its own ops.
 */
final class WP_All_Import_Pack
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads the plugins' own tables live; they have no cached read API.

    public const CAPABILITY = 'manage_options';

    public const DEFAULT_PAGE_SIZE = 20;

    public const MAX_PAGE_SIZE = 100;

    public const MAX_LOG_ISSUES = 50;

    public const MAX_TEMPLATE = 2000;

    private const LOG_CHUNK = 1048576;

    private const PLUGINS = [
        'import' => [ 'label' => 'WP All Import', 'file' => 'wp-all-import/plugin.php', 'tables' => [ 'pmxi_imports', 'pmxi_history' ] ],
        'export' => [ 'label' => 'WP All Export', 'file' => 'wp-all-export/wp-all-export.php', 'tables' => [ 'pmxe_exports' ] ],
    ];

    /** Query keys whose value is a credential. */
    private const SECRET_KEY = '/key|token|secret|pass|pwd|sig|auth|credential|session/i';

    public static function import_active(): bool
    {
        return (bool) apply_filters('wpmcp_wp_all_import_active', defined('PMXI_VERSION'));
    }

    public static function export_active(): bool
    {
        return (bool) apply_filters('wpmcp_wp_all_export_active', defined('PMXE_VERSION'));
    }

    public static function active(): bool
    {
        return self::import_active() || self::export_active();
    }

    private static function table(string $suffix): string
    {
        global $wpdb;
        return $wpdb->prefix . $suffix;
    }

    /**
     * true when the plugin is loaded and its tables exist, otherwise
     * plugin_inactive, plugin_missing or wp_all_import_tables_missing.
     *
     * @return true|array{code: string, message: string, data: array}
     */
    public static function presence(string $plugin)
    {
        $def    = self::PLUGINS[ $plugin ];
        $active = 'import' === $plugin ? self::import_active() : self::export_active();
        $url    = admin_url('plugins.php');
        if (! $active) {
            $installed = defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . $def['file']);
            return [
                'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
                'message' => sprintf('%1$s is %2$s on this site. Install and activate %1$s (%3$s), then try again.', $def['label'], $installed ? 'installed but not active' : 'not installed', $url),
                'data'    => [ 'plugins_url' => $url ],
            ];
        }
        foreach ($def['tables'] as $suffix) {
            $table = self::table($suffix);
            if (! Plugin_Table_Rows_Snapshot::table_exists($table)) {
                return [
                    'code'    => 'wp_all_import_tables_missing',
                    'message' => sprintf('%s\'s table %s does not exist on this site. Deactivate and reactivate it so it creates its tables, then try again.', $def['label'], $table),
                    'data'    => [ 'plugins_url' => $url ],
                ];
            }
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Catalog
    // ------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    public static function import_operations(): array
    {
        $base = [
            'mode'       => 'read',
            'capability' => self::CAPABILITY,
            'requires'   => static fn () => self::presence('import'),
        ];
        $page = [
            'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
            'offset'    => [ 'type' => 'integer', 'minimum' => 0 ],
        ];
        $id   = [ 'type' => 'integer', 'minimum' => 1 ];

        return [
            'wpallimport-list-imports' => $base + [
                'description'  => 'List WP All Import saved imports, most recent activity first: id, name, source_type (url, upload, file, ftp), source (credentials removed), feed_type, post_type, schedule, status, record counts and last_run (date, type, seconds, summary). page_size (default 20, max 100), offset. Read only',
                'input_schema' => [ 'type' => 'object', 'properties' => $page ],
                'handler'      => static fn (array $args): array => self::list_imports($args),
            ],
            'wpallimport-get-import'   => $base + [
                'description'  => 'One WP All Import import: source, FTP connection (host, port, path; has_username, has_password, has_private_key only), xpath, mapping (title, content, excerpt, slug, custom_fields, taxonomies; long templates cut to 2,000 characters), settings (unique_key, matching, create, update and delete missing rules), schedule and counts. Read only',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'import_id' => $id ], 'required' => [ 'import_id' ] ],
                'validate'     => static fn (array $args): ?array => self::import_refusal((int) $args['import_id']),
                'handler'      => static fn (array $args): array => self::get_import((int) $args['import_id']),
            ],
            'wpallimport-list-runs'    => $base + [
                'description'  => 'Run history of one WP All Import import, newest first: id, date, type (manual, processing, trigger, continue, cli), seconds, summary. page_size, offset. Read only',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'import_id' => $id ] + $page, 'required' => [ 'import_id' ] ],
                'validate'     => static fn (array $args): ?array => self::import_refusal((int) $args['import_id']),
                'handler'      => static fn (array $args): array => self::list_runs($args),
            ],
            'wpallimport-get-run'      => $base + [
                'description'  => 'One run of a WP All Import import (the latest unless run_id): its summary and the warnings and errors of its log (at most 50, emails and IPs masked, token links removed), with error and warning counts. Read only',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'import_id' => $id, 'run_id' => $id ], 'required' => [ 'import_id' ] ],
                'validate'     => static fn (array $args): ?array => self::run_refusal($args),
                'handler'      => static fn (array $args): array => self::get_run($args),
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function export_operations(): array
    {
        $base = [
            'mode'       => 'read',
            'capability' => self::CAPABILITY,
            'requires'   => static fn () => self::presence('export'),
        ];

        return [
            'wpallexport-list-exports' => $base + [
                'description'  => 'List WP All Export saved exports, most recent activity first: id, name, post_types, format, file (name only), exported count, schedule, status. page_size (default 20, max 100), offset. Read only',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
                        'offset'    => [ 'type' => 'integer', 'minimum' => 0 ],
                    ],
                ],
                'handler'      => static fn (array $args): array => self::list_exports($args),
            ],
            'wpallexport-get-export'   => $base + [
                'description'  => 'One WP All Export export: post types, format, fields (label, type, name, php:true when custom code is set; the code itself is never returned), settings (only new or modified records, has_custom_where), schedule with its notification email. Read only',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'export_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'export_id' ] ],
                'validate'     => static fn (array $args): ?array => self::export_refusal((int) $args['export_id']),
                'handler'      => static fn (array $args): array => self::get_export((int) $args['export_id']),
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        return array_merge(self::import_operations(), self::export_operations());
    }

    // ------------------------------------------------------------------
    // Refusals
    // ------------------------------------------------------------------

    /** @return array{code: string, message: string, data: array}|null */
    private static function import_refusal(int $id): ?array
    {
        if (null === self::import_row($id)) {
            return [ 'code' => 'import_not_found', 'message' => sprintf('WP All Import has no import %d.', $id), 'data' => [ 'import_id' => $id ] ];
        }
        return null;
    }

    /** @return array{code: string, message: string, data: array}|null */
    private static function run_refusal(array $args): ?array
    {
        $refusal = self::import_refusal((int) $args['import_id']);
        if (null !== $refusal) {
            return $refusal;
        }
        if (null === self::run_row((int) $args['import_id'], isset($args['run_id']) ? (int) $args['run_id'] : 0)) {
            return [
                'code'    => 'run_not_found',
                'message' => isset($args['run_id'])
                    ? sprintf('Import %d has no run %d.', (int) $args['import_id'], (int) $args['run_id'])
                    : sprintf('Import %d has not run yet.', (int) $args['import_id']),
                'data'    => [ 'import_id' => (int) $args['import_id'] ],
            ];
        }
        return null;
    }

    /** @return array{code: string, message: string, data: array}|null */
    private static function export_refusal(int $id): ?array
    {
        if (null === self::export_row($id)) {
            return [ 'code' => 'export_not_found', 'message' => sprintf('WP All Export has no export %d.', $id), 'data' => [ 'export_id' => $id ] ];
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Rows
    // ------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private static function import_row(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::table('pmxi_imports'), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** The run $run_id of an import, or its latest run when $run_id is 0. @return array<string, mixed>|null */
    private static function run_row(int $import_id, int $run_id): ?array
    {
        global $wpdb;
        $table = self::table('pmxi_history');
        $row   = $run_id > 0
            ? $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE import_id = %d AND id = %d', $table, $import_id, $run_id), ARRAY_A)
            : $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE import_id = %d ORDER BY date DESC, id DESC LIMIT 1', $table, $import_id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private static function export_row(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::table('pmxe_exports'), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** A serialized options column as an array, never instantiating objects. @return array<string, mixed> */
    private static function options($raw): array
    {
        if (! is_string($raw) || '' === $raw || ! is_serialized($raw)) {
            return [];
        }
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- allowed_classes:false never instantiates an object; the plugins store options this way.
        $value = @unserialize(trim($raw), [ 'allowed_classes' => false ]);
        return is_array($value) ? $value : [];
    }

    /** @return array{0: int, 1: int} page size and offset */
    private static function page(array $args): array
    {
        return [
            max(1, min(self::MAX_PAGE_SIZE, (int) ($args['page_size'] ?? self::DEFAULT_PAGE_SIZE))),
            max(0, (int) ($args['offset'] ?? 0)),
        ];
    }

    // ------------------------------------------------------------------
    // Imports
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function list_imports(array $args): array
    {
        global $wpdb;
        [ $size, $offset ] = self::page($args);
        $table = self::table('pmxi_imports');
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table));
        $rows  = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY last_activity DESC, id DESC LIMIT %d OFFSET %d', $table, $size, $offset), ARRAY_A);

        $imports = [];
        foreach ($rows as $row) {
            $imports[] = self::import_summary($row, self::options($row['options'] ?? ''));
        }
        return [
            'imports' => $imports,
            'total'   => $total,
            'offset'  => $offset,
            'hasMore' => $offset + count($imports) < $total,
        ];
    }

    /** @return array<string, mixed> */
    private static function import_summary(array $row, array $options): array
    {
        $last = self::run_row((int) $row['id'], 0);
        return [
            'id'               => (int) $row['id'],
            'parent_import_id' => (int) ($row['parent_import_id'] ?? 0),
            'name'             => '' !== (string) ($row['friendly_name'] ?? '') ? (string) $row['friendly_name'] : (string) ($row['name'] ?? ''),
            'file'             => basename(str_replace('\\', '/', (string) ($row['name'] ?? ''))),
            'source_type'      => (string) ($row['type'] ?? ''),
            'source'           => self::mask_source((string) ($row['path'] ?? '')),
            'feed_type'        => (string) ($row['feed_type'] ?? ''),
            'post_type'        => (string) ($options['custom_type'] ?? ''),
            'schedule'         => self::schedule($options),
            'status'           => self::status($row),
            'counts'           => [
                'records'  => (int) ($row['count'] ?? 0),
                'imported' => (int) ($row['imported'] ?? 0),
                'created'  => (int) ($row['created'] ?? 0),
                'updated'  => (int) ($row['updated'] ?? 0),
                'skipped'  => (int) ($row['skipped'] ?? 0),
                'deleted'  => (int) ($row['deleted'] ?? 0),
            ],
            'registered_on'    => (string) ($row['registered_on'] ?? ''),
            'last_activity'    => (string) ($row['last_activity'] ?? ''),
            'last_run'         => null === $last ? null : self::run_summary($last),
        ];
    }

    /** @return array<string, mixed> */
    private static function get_import(int $id): array
    {
        $row     = self::import_row($id) ?? [];
        $options = self::options($row['options'] ?? '');
        $import  = self::import_summary($row, $options);

        $fields = [];
        $names  = is_array($options['custom_name'] ?? null) ? array_values($options['custom_name']) : [];
        $values = is_array($options['custom_value'] ?? null) ? array_values($options['custom_value']) : [];
        foreach ($names as $i => $name) {
            if ('' !== (string) $name) {
                $fields[ (string) $name ] = self::cut(is_scalar($values[ $i ] ?? '') ? (string) ($values[ $i ] ?? '') : '');
            }
        }
        $taxonomies = [];
        foreach ((array) ($options['tax_single_xpath'] ?? []) as $tax => $template) {
            if (is_scalar($template) && '' !== (string) $template) {
                $taxonomies[ (string) $tax ] = self::cut((string) $template);
            }
        }
        foreach ((array) ($options['tax_hierarchical_xpath'] ?? []) as $tax => $templates) {
            $templates = array_values(array_filter((array) $templates, static fn ($t) => is_scalar($t) && '' !== (string) $t));
            if ([] !== $templates) {
                $taxonomies[ (string) $tax ] = self::cut(implode(' > ', array_map('strval', $templates)));
            }
        }

        $flags = [];
        foreach ($options as $key => $value) {
            if (is_string($key) && 0 === strpos($key, 'is_update_') && is_scalar($value)) {
                $flags[ substr($key, strlen('is_update_')) ] = (bool) $value;
            }
        }
        ksort($flags);

        $import['xpath']        = (string) ($row['xpath'] ?? '');
        $import['root_element'] = (string) ($row['root_element'] ?? '');
        $import['ftp']          = [
            'host'            => self::mask_source((string) ($options['ftp_host'] ?? '')),
            'port'            => (int) ($options['ftp_port'] ?? 0),
            'path'            => (string) ($options['ftp_path'] ?? ''),
            'root'            => (string) ($options['ftp_root'] ?? ''),
            'has_username'    => '' !== (string) ($options['ftp_username'] ?? ''),
            'has_password'    => '' !== (string) ($options['ftp_password'] ?? ''),
            'has_private_key' => '' !== (string) ($options['ftp_private_key'] ?? ''),
        ];
        $import['mapping']      = [
            'title'         => self::cut((string) ($options['title'] ?? '')),
            'content'       => self::cut((string) ($options['content'] ?? '')),
            'excerpt'       => self::cut((string) ($options['post_excerpt'] ?? '')),
            'slug'          => self::cut((string) ($options['post_slug'] ?? '')),
            'custom_fields' => $fields,
            'taxonomies'    => $taxonomies,
        ];
        $import['settings']     = [
            'wizard_type'         => (string) ($options['wizard_type'] ?? ''),
            'record_type'         => (string) ($options['type'] ?? ''),
            'status'              => (string) ($options['status'] ?? ''),
            'delimiter'           => (string) ($options['delimiter'] ?? ''),
            'unique_key'          => self::cut((string) ($options['unique_key'] ?? '')),
            'duplicate_matching'  => (string) ($options['duplicate_matching'] ?? ''),
            'duplicate_indicator' => (string) ($options['duplicate_indicator'] ?? ''),
            'create_new_records'  => (bool) ($options['create_new_records'] ?? false),
            'delete_missing'      => (bool) ($options['is_delete_missing'] ?? false),
            'update_all_data'     => (string) ($options['update_all_data'] ?? ''),
            'update'              => $flags,
        ];
        return [ 'import' => $import ];
    }

    /** @return array<string, mixed> */
    private static function list_runs(array $args): array
    {
        global $wpdb;
        [ $size, $offset ] = self::page($args);
        $table  = self::table('pmxi_history');
        $import = (int) $args['import_id'];
        $total  = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE import_id = %d', $table, $import));
        $rows   = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE import_id = %d ORDER BY date DESC, id DESC LIMIT %d OFFSET %d', $table, $import, $size, $offset), ARRAY_A);
        $runs   = array_map([ self::class, 'run_summary' ], $rows);
        return [
            'import_id' => $import,
            'runs'      => $runs,
            'total'     => $total,
            'offset'    => $offset,
            'hasMore'   => $offset + count($runs) < $total,
        ];
    }

    /** @return array<string, mixed> */
    private static function run_summary(array $row): array
    {
        return [
            'id'      => (int) $row['id'],
            'date'    => (string) ($row['date'] ?? ''),
            'type'    => (string) ($row['type'] ?? ''),
            'seconds' => is_numeric($row['time_run'] ?? null) ? (int) $row['time_run'] : null,
            'summary' => self::clean_line((string) ($row['summary'] ?? '')),
        ];
    }

    /** @return array<string, mixed> */
    private static function get_run(array $args): array
    {
        $row = self::run_row((int) $args['import_id'], isset($args['run_id']) ? (int) $args['run_id'] : 0) ?? [ 'id' => 0 ];
        return [
            'import_id' => (int) $args['import_id'],
            'run'       => self::run_summary($row),
            'log'       => self::log((int) $row['id']),
            'masked'    => true,
        ];
    }

    /** The run's log file, the plain path or the secure one. */
    private static function log_file(int $run_id): ?string
    {
        if ($run_id < 1) {
            return null;
        }
        $uploads = wp_upload_dir(null, false);
        $base    = rtrim((string) ($uploads['basedir'] ?? ''), '/\\') . '/wpallimport/logs';
        $salt    = defined('NONCE_SALT') ? NONCE_SALT : wp_salt('nonce');
        foreach ([ $base . '/' . md5($run_id . $salt) . '/' . $run_id . '.html', $base . '/' . $run_id . '.html' ] as $file) {
            if (is_file($file) && is_readable($file)) {
                return $file;
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    private static function log(int $run_id): array
    {
        $file = self::log_file($run_id);
        $out  = [ 'found' => null !== $file, 'errors' => 0, 'warnings' => 0, 'issues' => [], 'truncated' => false ];
        if (null === $file) {
            return $out;
        }
        // Read in 1 MB chunks with file_get_contents() and an offset (the
        // Plugin Check ruleset allows it, unlike fopen and fgets), so a large
        // log is never loaded whole.
        $size   = (int) filesize($file);
        $offset = 0;
        $carry  = '';
        while ($offset < $size) {
            $chunk   = $offset < $size ? file_get_contents($file, false, null, $offset, self::LOG_CHUNK) : '';
            $chunk   = false === $chunk ? '' : $chunk;
            $offset += self::LOG_CHUNK;
            $lines   = explode("\n", $carry . $chunk);
            $carry   = $offset < $size ? (string) array_pop($lines) : '';
            foreach ($lines as $line) {
                $out = self::log_line($out, $line);
            }
            if ('' === $chunk) {
                break;
            }
        }
        return $out;
    }

    /**
     * The log summary with one more line counted, and kept when it is a
     * warning or an error and the cap is not reached.
     *
     * @param array<string, mixed> $out
     * @return array<string, mixed>
     */
    private static function log_line(array $out, string $line): array
    {
        if (1 !== preg_match('~<b>\s*(ERROR|WARNING)\s*</b>~i', $line, $m)) {
            return $out;
        }
        $level = 'ERROR' === strtoupper($m[1]) ? 'error' : 'warning';
        $out[ 'error' === $level ? 'errors' : 'warnings' ]++;
        if (count($out['issues']) >= self::MAX_LOG_ISSUES) {
            $out['truncated'] = true;
            return $out;
        }
        $text = self::clean_line($line);
        $time = '';
        if (1 === preg_match('/^\[(\d{1,2}:\d{2}:\d{2})\]\s*/', $text, $t)) {
            $time = $t[1];
            $text = substr($text, strlen($t[0]));
        }
        $out['issues'][] = [ 'level' => $level, 'time' => $time, 'message' => self::cut($text, 500) ];
        return $out;
    }

    // ------------------------------------------------------------------
    // Exports
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function list_exports(array $args): array
    {
        global $wpdb;
        [ $size, $offset ] = self::page($args);
        $table = self::table('pmxe_exports');
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table));
        $rows  = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY last_activity DESC, id DESC LIMIT %d OFFSET %d', $table, $size, $offset), ARRAY_A);

        $exports = [];
        foreach ($rows as $row) {
            $exports[] = self::export_summary($row, self::options($row['options'] ?? ''));
        }
        return [
            'exports' => $exports,
            'total'   => $total,
            'offset'  => $offset,
            'hasMore' => $offset + count($exports) < $total,
        ];
    }

    /** @return array<string, mixed> */
    private static function export_summary(array $row, array $options): array
    {
        $types = array_values(array_filter(array_map('strval', array_filter((array) ($options['cpt'] ?? []), 'is_scalar'))));
        if ([] === $types && '' !== (string) ($row['export_post_type'] ?? '')) {
            $types = [ (string) $row['export_post_type'] ];
        }
        if (! empty($options['is_user_export'])) {
            $types = [ 'users' ];
        } elseif (! empty($options['is_comment_export'])) {
            $types = [ 'comments' ];
        }
        $file     = (string) ($options['current_filepath'] ?? '');
        $file     = '' !== $file ? $file : (string) ($options['filepath'] ?? '');
        $schedule = self::schedule($options);
        unset($schedule['email']);

        return [
            'id'            => (int) $row['id'],
            'parent_id'     => (int) ($row['parent_id'] ?? 0),
            'name'          => '' !== (string) ($row['friendly_name'] ?? '') ? (string) $row['friendly_name'] : (string) ($options['friendly_name'] ?? ''),
            'post_types'    => $types,
            'export_type'   => (string) ($options['export_type'] ?? ''),
            'format'        => (string) ($options['export_to'] ?? ''),
            'file'          => '' === $file ? '' : basename(str_replace('\\', '/', $file)),
            'exported'      => (int) ($row['exported'] ?? 0),
            'schedule'      => $schedule,
            'status'        => self::status($row),
            'registered_on' => (string) ($row['registered_on'] ?? ''),
            'last_activity' => (string) ($row['last_activity'] ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    private static function get_export(int $id): array
    {
        $row     = self::export_row($id) ?? [];
        $options = self::options($row['options'] ?? '');
        $export  = self::export_summary($row, $options);

        $labels = array_values((array) ($options['cc_label'] ?? []));
        $types  = array_values((array) ($options['cc_type'] ?? []));
        $names  = array_values((array) ($options['cc_name'] ?? []));
        $php    = array_values((array) ($options['cc_php'] ?? []));
        $sql    = array_values((array) ($options['cc_sql'] ?? []));
        $fields = [];
        foreach ($labels as $i => $label) {
            $fields[] = [
                'label' => is_scalar($label) ? (string) $label : '',
                'type'  => is_scalar($types[ $i ] ?? null) ? (string) $types[ $i ] : '',
                'name'  => is_scalar($names[ $i ] ?? null) ? (string) $names[ $i ] : '',
                // Custom PHP (cc_php with cc_code) or SQL (cc_sql) is set; the code itself never leaves.
                'php'   => ! empty($php[ $i ]) || (is_scalar($sql[ $i ] ?? null) && '' !== trim((string) $sql[ $i ])),
            ];
        }

        $export['fields']   = $fields;
        $export['settings'] = [
            'only_new'          => (bool) ($options['export_only_new_stuff'] ?? false),
            'only_modified'     => (bool) ($options['export_only_modified_stuff'] ?? false),
            'has_custom_where'  => '' !== trim((string) ($options['whereclause'] ?? '')) || '' !== trim((string) ($options['joinclause'] ?? '')),
            'has_filter_rules'  => ! in_array(trim(is_scalar($options['filter_rules_hierarhy'] ?? '') ? (string) ($options['filter_rules_hierarhy'] ?? '') : '[]'), [ '', '[]' ], true),
            'delimiter'         => (string) ($options['delimiter'] ?? ''),
            'split_large_files' => (bool) ($options['split_large_exports'] ?? false),
        ];
        $export['schedule'] = self::schedule($options);
        return [ 'export' => $export ];
    }

    // ------------------------------------------------------------------
    // Shared
    // ------------------------------------------------------------------

    /** @return array<string, mixed> the schedule both plugins store in options */
    private static function schedule(array $options): array
    {
        $times = array_values(array_filter(array_map(static fn ($t) => is_scalar($t) ? (string) $t : '', (array) ($options['scheduling_times'] ?? [])), static fn ($t) => '' !== $t));
        return [
            'enabled'      => ! empty($options['scheduling_enable']) || ! empty($options['is_scheduled']),
            'run_on'       => (string) ($options['scheduling_run_on'] ?? ''),
            'weekly_days'  => (string) ($options['scheduling_weekly_days'] ?? ''),
            'monthly_day'  => (string) ($options['scheduling_monthly_day'] ?? ''),
            'times'        => $times,
            'timezone'     => (string) ($options['scheduling_timezone'] ?? ''),
            'period'       => (string) ($options['scheduled_period'] ?? ''),
            'email'        => (string) ($options['scheduled_email'] ?? ''),
        ];
    }

    private static function status(array $row): string
    {
        if (! empty($row['failed'])) {
            return 'failed';
        }
        if (! empty($row['processing']) || ! empty($row['executing'])) {
            return 'running';
        }
        if (! empty($row['triggered'])) {
            return 'triggered';
        }
        if (! empty($row['canceled'])) {
            return 'canceled';
        }
        return 'idle';
    }

    /** A source URL or path with its user and password removed and secret query values redacted. */
    public static function mask_source(string $source): string
    {
        $source = trim($source);
        if ('' === $source) {
            return '';
        }
        // user:password@ or user@ before a host.
        $source = (string) preg_replace('~^([a-z][a-z0-9+.\-]*://)[^/@\s]*@~i', '$1', $source);
        $source = (string) preg_replace('~^[^/@\s:]+:[^/@\s]*@~', '', $source);
        $parts  = explode('?', $source, 2);
        if (2 !== count($parts)) {
            return $parts[0];
        }
        [ $fragment_free, $fragment ] = array_pad(explode('#', $parts[1], 2), 2, null);
        $pairs = [];
        foreach (explode('&', (string) $fragment_free) as $pair) {
            if ('' === $pair) {
                continue;
            }
            [ $name, $value ] = array_pad(explode('=', $pair, 2), 2, null);
            if (null !== $value && '' !== $value && (1 === preg_match(self::SECRET_KEY, rawurldecode($name)) || self::opaque_token(rawurldecode($value)))) {
                $value = '[redacted]';
            }
            $pairs[] = null === $value ? $name : $name . '=' . $value;
        }
        return $parts[0] . '?' . implode('&', $pairs) . (null === $fragment ? '' : '#' . $fragment);
    }

    /** Whether a value looks like a random token (16+ characters with letters and digits). */
    private static function opaque_token(string $value): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9_\-=.]{16,}$/', $value) && 1 === preg_match('/\d/', $value) && 1 === preg_match('/[A-Za-z]/', $value);
    }

    /** One log or summary line as plain text: tags gone, emails and IPs masked, token links removed. */
    private static function clean_line(string $line): string
    {
        $text = str_ireplace([ '<br>', '<br/>', '<br />' ], ' ', $line);
        $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES)));
        return Activity_Log_Pack::mask_text(Email_Log_Pack::redact_links($text));
    }

    private static function cut(string $text, int $limit = self::MAX_TEMPLATE): string
    {
        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($length <= $limit) {
            return $text;
        }
        return (function_exists('mb_substr') ? mb_substr($text, 0, $limit) : substr($text, 0, $limit)) . '...';
    }
}
