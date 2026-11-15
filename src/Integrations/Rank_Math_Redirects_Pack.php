<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Rank Math redirections and 404 log (issue #681, first slice): reads and
 * writes Rank Math's own redirect table as ops on the theme dispatcher pair,
 * so the adapter adds no top-level tools.
 *
 * Reads: rankmath-list-redirections, rankmath-get-redirection and
 * rankmath-list-404-log (worst 404s first, each flagged when an exact
 * redirection already covers it). Writes: rankmath-create-redirection,
 * rankmath-update-redirection (also enables and disables through status) and
 * rankmath-delete-redirection. Every write is snapshotted as a
 * plugin_table_rows image of the `rank_math_redirections` row (kind
 * rank_math), so rollback-operation restores exactly: an edit goes back in
 * place, a deleted redirection returns at its own id and a created one is
 * removed again. The 404 log is read only; clearing it is not offered.
 *
 * The rows are written by prepared SQL rather than Rank Math's own
 * Redirection::save(): save() matches an existing row by identical sources
 * and silently updates it, which would turn a create into an overwrite, and
 * a create must name the row its undo removes. Duplicate sources are refused
 * instead. After a write the row's lookup-cache rows are purged through Rank
 * Math's Cache class when it is loaded.
 *
 * Writes change live routing, so they are off until the site opts in with
 * the wpmcp_enable_seo_redirect_write filter (issue #837). Reads stay open.
 * The 404 log's referer is reduced to its host and its user agent truncated.
 * Every op declares a 'requires' check, so a missing plugin, a module that
 * is off or a table that is absent answers a named refusal, not a SQL error.
 */
final class Rank_Math_Redirects_Pack
{
    public const ENABLE_FILTER = 'wpmcp_enable_seo_redirect_write';

    private const COMPARISONS = [ 'exact', 'contains', 'start', 'end', 'regex' ];
    private const CODES       = [ 301, 302, 307, 308, 410, 451 ];
    private const STATUSES    = [ 'active', 'inactive' ];
    private const MAX_LENGTH  = 2000;
    private const MAX_CHAIN   = 10;
    private const MAX_PAGE    = 100;
    private const NEVER       = '1000-01-01 00:00:00';

    /** @return array<string,array<string,mixed>> */
    public static function operations(): array
    {
        // The literal name, not ENABLE_FILTER, so the hook stays greppable.
        $enabled  = (bool) apply_filters('wpmcp_enable_seo_redirect_write', false);
        $redirect = static fn () => self::requirement('redirections');
        $log      = static fn () => self::requirement('404-monitor');
        $id_only  = [
            'type'       => 'object',
            'properties' => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
            'required'   => [ 'id' ],
        ];
        $paging   = [
            'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE ],
            'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
        ];
        $target   = static fn (array $args): array => [
            'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
            'object_id'   => Plugin_Table_Rows_Snapshot::key('rank_math', 'redirections', [ (int) $args['id'] ]),
        ];
        $write    = [
            'mode'               => 'write',
            'capability'         => 'manage_options',
            'enabled_by_default' => $enabled,
            'opt_in_filter'      => self::ENABLE_FILTER,
            'requires'           => $redirect,
        ];

        return [
            'rankmath-list-redirections' => [
                'mode'         => 'read',
                'capability'   => 'manage_options',
                'description'  => 'List Rank Math redirections (sources with comparison, target, header code, status, hits). Filter by status or a search over source and target; paged',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status' => [ 'type' => 'string', 'enum' => [ 'active', 'inactive', 'trashed' ] ],
                        'search' => [ 'type' => 'string', 'maxLength' => 200 ],
                    ] + $paging,
                ],
                'requires'     => $redirect,
                'handler'      => static fn (array $args): array => self::list_redirections($args),
            ],
            'rankmath-get-redirection'   => [
                'mode'         => 'read',
                'capability'   => 'manage_options',
                'description'  => 'Read one Rank Math redirection by id, with its hits and last access',
                'input_schema' => $id_only,
                'requires'     => $redirect,
                'validate'     => static fn (array $args): ?array => self::refusal(static fn () => self::must_row((int) $args['id'])),
                'handler'      => static fn (array $args): array => [ 'redirection' => self::view(self::must_row((int) $args['id'])) ],
            ],
            'rankmath-list-404-log'      => [
                'mode'         => 'read',
                'capability'   => 'manage_options',
                'description'  => 'List the Rank Math 404 monitor log, most requested first (uri, times accessed, last access, referer host, truncated user agent), each flagged already_redirected when an active exact redirection covers it. Needs the 404 Monitor module',
                'input_schema' => [ 'type' => 'object', 'properties' => $paging ],
                'requires'     => $log,
                'handler'      => static fn (array $args): array => self::list_404($args),
            ],
            'rankmath-create-redirection' => $write + [
                'description'  => 'Create a Rank Math redirection from a source path to a target path or http(s) URL (comparison exact, contains, start, end or regex, default exact; header_code 301, 302, 307, 308, or 410 and 451 with no target; default 301). Loops and a source Rank Math already redirects are refused. Snapshotted; rollback-operation removes it. Disabled by default (wpmcp_enable_seo_redirect_write filter)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => self::write_properties(),
                    'required'   => [ 'source' ],
                ],
                'validate'     => static fn (array $args): ?array => self::refusal(static fn () => self::plan_create($args)),
                'snapshot'     => static fn (): array => [
                    'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
                    'object_id'   => Plugin_Table_Rows_Snapshot::key('rank_math', 'redirections', [ self::next_id() ]),
                ],
                'handler'      => static fn (array $args, array $context): array => self::create($args, (int) (Plugin_Table_Rows_Snapshot::parse((string) $context['target']['object_id'])['ids'][0] ?? 0), (string) ($context['operation_id'] ?? '')),
            ],
            'rankmath-update-redirection' => $write + [
                'description'  => 'Change a single-source Rank Math redirection: source, comparison, target, header_code or status (active or inactive, which enables or disables it). Only passed fields change. Snapshotted and reversible. Disabled by default (wpmcp_enable_seo_redirect_write filter)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ] + self::write_properties(),
                    'required'   => [ 'id' ],
                ],
                'validate'     => static fn (array $args): ?array => self::refusal(static fn () => self::plan_update($args)),
                'snapshot'     => $target,
                'handler'      => static fn (array $args): array => self::update($args),
            ],
            'rankmath-delete-redirection' => $write + [
                'mode'         => 'destructive',
                'description'  => 'Delete a Rank Math redirection. The whole row is snapshotted, so rollback-operation brings it back at its own id; set status inactive to keep it instead. Disabled by default (wpmcp_enable_seo_redirect_write filter)',
                'input_schema' => $id_only,
                'validate'     => static fn (array $args): ?array => self::refusal(static fn () => self::must_row((int) $args['id'])),
                'snapshot'     => $target,
                'handler'      => static fn (array $args): array => self::delete((int) $args['id']),
            ],
        ];
    }

    /** Whether Rank Math is loaded, filterable through wpmcp_rank_math_active. */
    public static function is_active(): bool
    {
        return (bool) apply_filters('wpmcp_rank_math_active', defined('RANK_MATH_VERSION') || class_exists('RankMath'));
    }

    public static function redirections_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'rank_math_redirections';
    }

    public static function log_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'rank_math_404_logs';
    }

    /** @return true|array{code:string,message:string} */
    private static function requirement(string $module)
    {
        if (! self::is_active()) {
            return [ 'code' => 'rank_math_inactive', 'message' => 'The Rank Math SEO plugin is not active on this site.' ];
        }
        $modules = (array) get_option('rank_math_modules', []);
        if (! in_array($module, $modules, true)) {
            return [ 'code' => 'rank_math_module_off', 'message' => sprintf('The Rank Math %s module is switched off; turn it on under Rank Math > Dashboard.', '404-monitor' === $module ? '404 Monitor' : 'Redirections') ];
        }
        $table = '404-monitor' === $module ? self::log_table() : self::redirections_table();
        if (! Plugin_Table_Rows_Snapshot::table_exists($table)) {
            return [ 'code' => 'rank_math_not_installed', 'message' => 'The Rank Math module is on but its database table is missing; open the module in Rank Math once to create it.' ];
        }
        return true;
    }

    /** @return array<string,array<string,mixed>> */
    private static function write_properties(): array
    {
        return [
            'source'      => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_LENGTH ],
            'comparison'  => [ 'type' => 'string', 'enum' => self::COMPARISONS ],
            'target'      => [ 'type' => 'string', 'maxLength' => self::MAX_LENGTH ],
            'header_code' => [ 'type' => 'integer', 'enum' => self::CODES ],
            'status'      => [ 'type' => 'string', 'enum' => self::STATUSES ],
        ];
    }

    // -----------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------

    private static function list_redirections(array $args): array
    {
        global $wpdb;

        $where  = [ '1=1' ];
        $params = [ self::redirections_table() ];
        if (isset($args['status'])) {
            $where[]  = 'status = %s';
            $params[] = (string) $args['status'];
        }
        $search = trim((string) ($args['search'] ?? ''));
        if ('' !== $search) {
            $like     = '%' . $wpdb->esc_like($search) . '%';
            $where[]  = '(sources LIKE %s OR url_to LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }
        $clause   = implode(' AND ', $where);
        $per_page = (int) ($args['per_page'] ?? 50);
        $page     = (int) ($args['page'] ?? 1);

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $clause is assembled only from the literal fragments above and the spread carries one value per placeholder. Rank Math's own table has no WP API.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE {$clause}", ...$params));
        $rows  = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM %i WHERE {$clause} ORDER BY id ASC LIMIT %d OFFSET %d", ...array_merge($params, [ $per_page, ( $page - 1 ) * $per_page ])),
            ARRAY_A
        );
        // phpcs:enable

        return [
            'total'        => $total,
            'page'         => $page,
            'per_page'     => $per_page,
            'redirections' => array_map([ self::class, 'view' ], (array) $rows),
        ];
    }

    private static function list_404(array $args): array
    {
        global $wpdb;

        $per_page = (int) ($args['per_page'] ?? 50);
        $page     = (int) ($args['page'] ?? 1);

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rank Math's own table has no WP API; an agent must see the live log.
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', self::log_table()));
        $rows  = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM %i ORDER BY times_accessed DESC, id ASC LIMIT %d OFFSET %d', self::log_table(), $per_page, ( $page - 1 ) * $per_page),
            ARRAY_A
        );
        // phpcs:enable

        $covered = self::exact_patterns();
        $out     = [];
        foreach ((array) $rows as $row) {
            $uri   = (string) $row['uri'];
            $out[] = [
                'id'                 => (int) $row['id'],
                'uri'                => $uri,
                'times_accessed'     => (int) $row['times_accessed'],
                'last_access'        => (string) $row['accessed'],
                'referer_host'       => self::host((string) ($row['referer'] ?? '')),
                'user_agent'         => self::truncate((string) ($row['user_agent'] ?? ''), 120),
                'already_redirected' => isset($covered[ self::clean($uri) ]),
            ];
        }

        return [ 'total' => $total, 'page' => $page, 'per_page' => $per_page, 'entries' => $out ];
    }

    /** @return array<string,true> the cleaned exact patterns of active redirections. */
    private static function exact_patterns(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rank Math's own table; read live.
        $rows = $wpdb->get_col($wpdb->prepare('SELECT sources FROM %i WHERE status = %s', self::redirections_table(), 'active'));
        $out  = [];
        foreach ((array) $rows as $raw) {
            foreach (self::sources((string) $raw) as $source) {
                if ('exact' === $source['comparison']) {
                    $out[ self::clean($source['pattern']) ] = true;
                }
            }
        }
        return $out;
    }

    /**
     * The rows' sources, decoded without instantiating any class.
     *
     * @return array<int,array{pattern:string,comparison:string,ignore:string}>
     */
    private static function sources(string $raw): array
    {
        $data = is_serialized($raw) ? @unserialize($raw, [ 'allowed_classes' => false ]) : null; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- classes are refused; a corrupt value must read as empty.
        $out  = [];
        foreach (is_array($data) ? $data : [] as $source) {
            if (is_array($source) && isset($source['pattern'])) {
                $out[] = [
                    'pattern'    => (string) $source['pattern'],
                    'comparison' => (string) ($source['comparison'] ?? 'exact'),
                    'ignore'     => (string) ($source['ignore'] ?? ''),
                ];
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function view(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'sources'     => self::sources((string) $row['sources']),
            'target'      => (string) $row['url_to'],
            'header_code' => (int) $row['header_code'],
            'status'      => (string) $row['status'],
            'hits'        => (int) $row['hits'],
            'created'     => (string) $row['created'],
            'updated'     => (string) $row['updated'],
            'last_access' => (string) $row['last_accessed'],
        ];
    }

    private static function host(string $url): string
    {
        return (string) wp_parse_url($url, PHP_URL_HOST);
    }

    private static function truncate(string $value, int $max): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    // -----------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------

    private static function create(array $args, int $id, string $operation_id): array
    {
        global $wpdb;

        try {
            $fields = self::plan_create($args);
            $now    = current_time('mysql');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Rank Math's own table; the id was reserved before the snapshot so the undo removes exactly this row.
            $ok = $wpdb->insert(self::redirections_table(), [ 'id' => $id, 'hits' => 0, 'created' => $now, 'updated' => $now, 'last_accessed' => self::NEVER ] + $fields);
            if (false === $ok) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
                throw new Operation_Refused('write_failed', 'Could not create the redirection: ' . $wpdb->last_error);
            }
        } catch (\Throwable $e) {
            // Nothing was written, but the snapshot is persisted; left in place
            // its undo would remove whatever row now holds the reserved id.
            if ('' !== $operation_id) {
                Snapshot_Store::delete_operation($operation_id);
            }
            throw $e;
        }
        Plugin_Table_Rows_Snapshot::purge_rank_math_cache([ $id ]);

        return [ 'redirection' => self::view(self::must_row($id)) ];
    }

    private static function update(array $args): array
    {
        global $wpdb;

        $id     = (int) $args['id'];
        $fields = self::plan_update($args);
        if ([] !== $fields) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rank Math's own table; snapshotted by the dispatcher first.
            if (false === $wpdb->update(self::redirections_table(), $fields + [ 'updated' => current_time('mysql') ], [ 'id' => $id ])) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
                throw new Operation_Refused('write_failed', 'Could not update the redirection: ' . $wpdb->last_error);
            }
        }
        Plugin_Table_Rows_Snapshot::purge_rank_math_cache([ $id ]);

        return [ 'redirection' => self::view(self::must_row($id)), 'changed' => array_keys($fields) ];
    }

    private static function delete(int $id): array
    {
        global $wpdb;

        $row = self::must_row($id);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rank Math's own table; the whole row was snapshotted by the dispatcher first.
        if (false === $wpdb->delete(self::redirections_table(), [ 'id' => $id ], [ '%d' ])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Refused('write_failed', 'Could not delete the redirection: ' . $wpdb->last_error);
        }
        Plugin_Table_Rows_Snapshot::purge_rank_math_cache([ $id ]);

        return [ 'deleted' => self::view($row) ];
    }

    /**
     * The id a create writes under, reserved before the snapshot: past the
     * highest id and the AUTO_INCREMENT counter, so a deleted id is not reused.
     */
    private static function next_id(): int
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live id reservation on Rank Math's own table.
        $max    = (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(id) FROM %i', self::redirections_table()));
        $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', self::redirections_table()), ARRAY_A);
        // phpcs:enable
        $auto = is_array($status) ? (int) ($status['Auto_increment'] ?? 0) : 0;

        return max($max + 1, $auto);
    }

    // -----------------------------------------------------------------
    // Planning (shared by validate() and the handlers)
    // -----------------------------------------------------------------

    /** @return array<string,mixed> the columns a create writes. */
    private static function plan_create(array $args): array
    {
        $comparison = (string) ($args['comparison'] ?? 'exact');
        $pattern    = self::pattern((string) $args['source'], $comparison);
        $source     = [ 'ignore' => '', 'pattern' => $pattern, 'comparison' => $comparison ];
        $serialized = maybe_serialize([ $source ]);

        $existing = self::find_by_sources($serialized, 0);
        if (null !== $existing) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Error('duplicate_source', sprintf('Source "%s" is already redirected by Rank Math redirection %d; update that one instead.', $pattern, (int) $existing['id']), [ 'redirection_id' => (int) $existing['id'] ]);
        }

        $code   = self::code(isset($args['header_code']) ? (int) $args['header_code'] : 301);
        $target = self::target_for($code, (string) ($args['target'] ?? ''));
        $status = (string) ($args['status'] ?? 'active');
        if (! in_array($status, self::STATUSES, true)) {
            throw new Operation_Error('invalid_status', 'Status must be active or inactive.');
        }
        if ('active' === $status && '' !== $target && 'exact' === $comparison) {
            self::assert_no_loop($pattern, $target, 0);
        }

        return [ 'sources' => $serialized, 'url_to' => $target, 'header_code' => $code, 'status' => $status ];
    }

    /** @return array<string,mixed> the changed columns an update writes. */
    private static function plan_update(array $args): array
    {
        $id  = (int) $args['id'];
        $row = self::must_row($id);
        $current = self::sources((string) $row['sources']);
        if (1 !== count($current)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Error('unsupported_redirection', sprintf('Rank Math redirection %d has %d sources; only single-source redirections can be updated here. Edit it under Rank Math > Redirections.', $id, count($current)), [ 'redirection_id' => $id ]);
        }

        $fields     = [];
        $comparison = (string) ($args['comparison'] ?? $current[0]['comparison']);
        $pattern    = $current[0]['pattern'];
        if (isset($args['source']) || isset($args['comparison'])) {
            $pattern    = self::pattern(isset($args['source']) ? (string) $args['source'] : $pattern, $comparison);
            $serialized = maybe_serialize([ [ 'ignore' => $current[0]['ignore'], 'pattern' => $pattern, 'comparison' => $comparison ] ]);
            if ($serialized !== $row['sources']) {
                $dup = self::find_by_sources($serialized, $id);
                if (null !== $dup) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
                    throw new Operation_Error('duplicate_source', sprintf('Source "%s" is already redirected by Rank Math redirection %d.', $pattern, (int) $dup['id']), [ 'redirection_id' => (int) $dup['id'] ]);
                }
                $fields['sources'] = $serialized;
            }
        }

        $code = self::code(isset($args['header_code']) ? (int) $args['header_code'] : (int) $row['header_code']);
        if ($code !== (int) $row['header_code']) {
            $fields['header_code'] = $code;
        }
        $target = self::target_for($code, isset($args['target']) ? (string) $args['target'] : (string) $row['url_to']);
        if ($target !== (string) $row['url_to']) {
            $fields['url_to'] = $target;
        }

        $status = (string) ($args['status'] ?? $row['status']);
        if (isset($args['status']) && ! in_array($status, self::STATUSES, true)) {
            throw new Operation_Error('invalid_status', 'Status must be active or inactive.');
        }
        if ($status !== $row['status']) {
            $fields['status'] = $status;
        }

        if ('active' === $status && '' !== $target && 'exact' === $comparison) {
            self::assert_no_loop($pattern, $target, $id);
        }

        return $fields;
    }

    /** Run a planner and turn an Operation_Error into the dispatcher's refusal shape. */
    private static function refusal(callable $plan): ?array
    {
        try {
            $plan();
        } catch (Operation_Error $e) {
            return [ 'code' => $e->error_code(), 'message' => $e->getMessage(), 'data' => $e->error_data() ];
        }
        return null;
    }

    /** @return array<string,string|null> */
    private static function must_row(int $id): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rank Math's own table; read live.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::redirections_table(), $id), ARRAY_A);
        if (! is_array($row)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Error('redirection_not_found', sprintf('Rank Math redirection %d does not exist.', $id), [ 'redirection_id' => $id ]);
        }
        return $row;
    }

    /** @return array<string,string|null>|null */
    private static function find_by_sources(string $serialized, int $ignore_id): ?array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rank Math's own table; read live.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE sources = %s AND id <> %d LIMIT 1', self::redirections_table(), $serialized, $ignore_id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    // -----------------------------------------------------------------
    // Field guards
    // -----------------------------------------------------------------

    /** The pattern Rank Math stores: no scheme or host, no outer slashes for a path comparison. */
    private static function pattern(string $raw, string $comparison): string
    {
        if (! in_array($comparison, self::COMPARISONS, true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Error('invalid_comparison', 'Comparison must be one of ' . implode(', ', self::COMPARISONS) . '.');
        }
        $pattern = trim($raw, ' ');
        if ('' === $pattern || strlen($pattern) > self::MAX_LENGTH || self::has_control_chars($pattern)) {
            throw new Operation_Error('invalid_source', 'The source is empty, too long or has control characters.');
        }
        if ('regex' === $comparison) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a bad pattern is reported, not warned about.
            if (false === @preg_match('@' . $pattern . '@', '')) {
                throw new Operation_Error('invalid_source', 'The regular expression does not compile.');
            }
            return $pattern;
        }
        if (false !== strpos($pattern, '://') || 0 === strpos($pattern, '//')) {
            throw new Operation_Error('invalid_source', 'The source must be a site path, not a URL with a scheme or host.');
        }
        if ('exact' === $comparison) {
            // As Rank Math stores an exact source: no outer slashes, case kept.
            $pattern = trim($pattern, '/');
            if ('' === $pattern) {
                throw new Operation_Error('invalid_source', 'The site root cannot be redirected.');
            }
        }
        return $pattern;
    }

    /** A path as Rank Math compares it: outer slashes trimmed, lowercased. */
    private static function clean(string $path): string
    {
        $path = trim($path, '/ ');
        return function_exists('mb_strtolower') ? mb_strtolower($path, 'UTF-8') : strtolower($path);
    }

    private static function code(int $code): int
    {
        if (! in_array($code, self::CODES, true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Error('invalid_header_code', sprintf('Code %d is not valid (%s).', $code, implode(', ', self::CODES)));
        }
        return $code;
    }

    /** The target for a code: none for 410 and 451, else a site path or an http(s) URL. */
    private static function target_for(int $code, string $raw): string
    {
        if (in_array($code, [ 410, 451 ], true)) {
            return '';
        }
        $target = trim($raw, ' ');
        if ('' === $target) {
            throw new Operation_Error('invalid_target', 'A redirect needs a target path or URL (410 and 451 take none).');
        }
        if (self::has_control_chars($target) || strlen($target) > self::MAX_LENGTH) {
            throw new Operation_Error('invalid_target', 'The target contains control characters or is too long.');
        }
        if ('/' === $target[0] && 0 !== strpos($target, '//')) {
            // Rank Math stores a relative destination as a full home URL.
            return home_url($target);
        }
        $scheme = strtolower((string) wp_parse_url($target, PHP_URL_SCHEME));
        $host   = (string) wp_parse_url($target, PHP_URL_HOST);
        if (! in_array($scheme, [ 'http', 'https' ], true) || '' === $host || esc_url_raw($target, [ 'http', 'https' ]) !== $target) {
            throw new Operation_Error('invalid_target', 'The target must be a site path starting with "/" or an absolute http(s) URL.');
        }
        return $target;
    }

    private static function has_control_chars(string $value): bool
    {
        return 1 === preg_match('/[\x00-\x1F\x7F]/', $value);
    }

    /** The cleaned site path a target leads to, or null when it leaves the site. */
    private static function internal_path(string $target): ?string
    {
        if ('/' === $target[0] && 0 !== strpos($target, '//')) {
            return self::clean((string) strtok($target, '?#'));
        }
        $host = strtolower((string) wp_parse_url($target, PHP_URL_HOST));
        if ('' === $host || strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST)) !== $host) {
            return null;
        }
        return self::clean((string) wp_parse_url($target, PHP_URL_PATH));
    }

    /**
     * Refuse a redirect that would come back to a path already on its walk,
     * following active single exact redirections. $ignore_id is the row being
     * changed, so it is not followed as its own old self.
     */
    private static function assert_no_loop(string $pattern, string $target, int $ignore_id): void
    {
        global $wpdb;

        $start = self::clean($pattern);
        $chain = [ $start ];
        $seen  = [ $start => true ];
        $next  = $target;

        for ($hop = 0; $hop <= self::MAX_CHAIN; $hop++) {
            $path = self::internal_path($next);
            if (null === $path) {
                return;
            }
            $chain[] = $path;
            if (isset($seen[ $path ])) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
                throw new Operation_Error('redirect_loop', sprintf('Refused: this redirection would loop (%s).', implode(' -> ', $chain)), [ 'chain' => $chain ]);
            }
            $seen[ $path ] = true;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rank Math's own table; read live.
            $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT sources, url_to FROM %i WHERE status = %s AND id <> %d AND url_to <> %s', self::redirections_table(), 'active', $ignore_id, ''), ARRAY_A);
            $next = null;
            foreach ($rows as $row) {
                $sources = self::sources((string) $row['sources']);
                if (1 === count($sources) && 'exact' === $sources[0]['comparison'] && self::clean($sources[0]['pattern']) === $path) {
                    $next = (string) $row['url_to'];
                    break;
                }
            }
            if (null === $next) {
                return;
            }
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
        throw new Operation_Error('redirect_loop', sprintf('Refused: this redirection leads through more than %d redirects, which is treated as a loop.', self::MAX_CHAIN), [ 'chain' => $chain ]);
    }
}
