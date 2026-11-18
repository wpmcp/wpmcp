<?php

// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Integrations).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Integrations).

namespace WPMCP\Integrations;

use WPMCP\Privacy\Masking;
use WPMCP\Safety\Mutation_Failed;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Backup\Url_Rewriter;
use WPMCP\Tools\Content\Content_Guard;
use WPMCP\Tools\Database\Database_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Database search and replace for sites that run Better Search Replace or
 * Search Regex (issue #895), on the plugin-data pair, free tier, so it adds
 * no top-level tool. Ops are prefixed searchreplace- because the pair is
 * shared.
 *
 * The free Better Search Replace has no saved profiles, no history and no
 * protection for salts or keys, and its replace runs over a whole table in
 * one pass, so the write here is WP MCP's own: a serialization-safe walk
 * (Url_Rewriter::transform(), the walk the URL rewrite and
 * find-replace-content share) over the text columns of the tables the
 * caller names, row by row, each row snapshotted first.
 *
 * Reads:
 *  - searchreplace-list-tables: this site's tables with an estimated row
 *    count, primary key and whether a run may write them, plus the plugins'
 *    own saved state: Search Regex presets (its searchregex_presets option)
 *    and whether Better Search Replace has a last result.
 *  - searchreplace-dry-run: per-table rows and matches, up to three samples
 *    of before and after text around the first match (emails and IPs in
 *    them masked), the rows a run would skip and why, and a dry_run_token.
 *    The token is stateless (an HMAC over the user, the arguments, the row
 *    count and an expiry), so a dry run writes nothing.
 *  - searchreplace-get-last-run: the last run WP MCP made (with its
 *    session_id, for undo) and Better Search Replace's own last result
 *    (its bsr_results transient), when either exists.
 *
 * Write, off until a site opts in with wpmcp_enable_search_replace_write,
 * manage_options, confirm:true in the call's own arguments:
 *  - searchreplace-run repeats the scan and refuses unless the dry_run_token
 *    is valid for exactly these arguments and the scan finds no more rows
 *    than the dry run did. Each changed row is written through Safe_Mutation
 *    as a db_rows before-image of the whole row, all under one session, so
 *    rollback-session undoes the run. A row that changed since the scan is
 *    left alone and reported.
 *
 * What a run never touches: the users and usermeta tables (and sitemeta on
 * a network) and any table a site adds to wpmcp_db_protected_tables, WP MCP's
 * own tables, tables without a primary key (a row could not be restored),
 * other sites' tables, primary key columns, the posts guid column, the salt
 * and key options, active_plugins, WP MCP's own options, and the rows of WP
 * MCP's private stores (agent memory, chats). A serialized value holding an
 * object, and JSON that would no longer decode, are skipped and reported.
 * Search is literal (no regex), never empty and never equal to replace.
 *
 * Size limit: a run changes at most MAX_ROWS rows (filterable, down only in
 * practice: wpmcp_search_replace_max_rows) and MAX_SNAPSHOT_BYTES of
 * before-images; past either the dry run says so and gives no token.
 */
final class Search_Replace_Pack
{
    public const CAPABILITY = 'manage_options';

    public const OPT_IN_FILTER = 'wpmcp_enable_search_replace_write';

    public const ACTIVE_FILTER = 'wpmcp_search_replace_active';

    public const MAX_ROWS_FILTER = 'wpmcp_search_replace_max_rows';

    /** WP MCP's record of its last run. */
    public const LAST_RUN_OPTION = 'wpmcp_search_replace_last_run';

    /** Better Search Replace's own last result (a transient it keeps for a day). */
    public const BSR_RESULTS = 'bsr_results';

    /** Search Regex's saved presets, a JSON list in one option. */
    public const SEARCH_REGEX_PRESETS = 'searchregex_presets';

    public const MAX_ROWS = 2000;

    public const MAX_SNAPSHOT_BYTES = 33554432;

    public const MAX_TABLES = 100;

    public const MAX_SEARCH = 1000;

    public const TOKEN_TTL = 1800;

    private const MAX_LISTED_TABLES = 500;

    private const MAX_PRESETS = 50;

    private const SAMPLES = 3;

    private const SKIPPED_LISTED = 20;

    private const SNIPPET_PAD = 40;

    /** Options a run never rewrites: the salts and keys, and the active plugin list. */
    private const PROTECTED_OPTION_PATTERN = '/^(?:(?:auth|secure_auth|logged_in|nonce)_(?:key|salt)|secret_key|active_plugins|active_sitewide_plugins)$/';

    /** WP MCP's own options and transients, which a run leaves alone. */
    private const OWN_OPTION_PATTERN = '/^(?:_site)?(?:_transient(?:_timeout)?_)?wpmcp_/';

    private const TEXT_TYPE_PATTERN = '/^(?:var)?char\b|^(?:tiny|medium|long)?text\b|^json\b/i';

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_search_replace_active', self::bsr_active() || self::search_regex_active());
    }

    private static function bsr_active(): bool
    {
        return defined('BSR_VERSION') || class_exists('Better_Search_Replace', false);
    }

    private static function search_regex_active(): bool
    {
        return defined('SEARCHREGEX_FILE') || defined('SEARCHREGEX_VERSION');
    }

    /** @return true|array{code: string, message: string, data: array} */
    public static function presence()
    {
        if (self::active()) {
            return true;
        }
        return Plugin_Refusal::any_of(
            'search_replace_inactive',
            'Neither Better Search Replace nor Search Regex is active on this site.',
            [ 'plugins' => [ 'better-search-replace', 'search-regex' ] ]
        );
    }

    /** The most rows one run may change. */
    public static function max_rows(): int
    {
        return max(1, min(self::MAX_ROWS, (int) apply_filters('wpmcp_search_replace_max_rows', self::MAX_ROWS)));
    }

    // ------------------------------------------------------------------
    // Catalog
    // ------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $base = [
            'capability' => self::CAPABILITY,
            'requires'   => static fn () => self::presence(),
        ];
        $args = [
            'search'         => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_SEARCH ],
            'replace'        => [ 'type' => 'string', 'maxLength' => self::MAX_SEARCH ],
            'tables'         => [
                'type'        => 'array',
                'minItems'    => 1,
                'maxItems'    => self::MAX_TABLES,
                'uniqueItems' => true,
                'items'       => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 64 ],
            ],
            'case_sensitive' => [ 'type' => 'boolean' ],
        ];
        $run_args = $args + [ 'dry_run_token' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ] ];

        return [
            'searchreplace-list-tables' => $base + [
                'mode'         => 'read',
                'description'  => 'Search and replace (Better Search Replace, Search Regex): this site\'s tables with estimated rows, primary key and whether a run may write them (writable:false with the reason), plus Search Regex saved presets and whether Better Search Replace has a last result',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => static fn (): array => self::list_tables(),
            ],
            'searchreplace-dry-run'     => $base + [
                'mode'         => 'read',
                'description'  => 'Dry run of a serialization-safe search and replace over the named tables (literal search, case_sensitive default true): rows and matches per table, up to 3 before and after samples (emails and IPs masked), rows it would skip and why, and the dry_run_token searchreplace-run needs (valid 30 minutes, same arguments). Writes nothing. Personal data',
                'input_schema' => [ 'type' => 'object', 'properties' => $args, 'required' => [ 'search', 'replace', 'tables' ] ],
                'validate'     => static fn (array $a): ?array => self::args_refusal($a),
                'handler'      => static fn (array $a): array => self::dry_run($a),
            ],
            'searchreplace-get-last-run' => $base + [
                'mode'         => 'read',
                'description'  => 'The last search and replace WP MCP ran (tables, rows changed, session_id for rollback-session) and Better Search Replace\'s own last result, when either exists',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => static fn (): array => self::last_run(),
            ],
            'searchreplace-run'         => $base + [
                'mode'                 => 'destructive',
                'enabled_by_default'   => (bool) apply_filters('wpmcp_enable_search_replace_write', false),
                'opt_in_filter'        => self::OPT_IN_FILTER,
                'self_snapshotting'    => true,
                'confirm_in_arguments' => true,
                'description'          => 'Run a serialization-safe search and replace over the named tables. Needs the dry_run_token of a searchreplace-dry-run with the same arguments and confirm:true; refuses when the data now has more matching rows than the dry run. Never touches users, usermeta, WP MCP tables, salts, keys or active_plugins. Each row is snapshotted: rollback-session with the returned session_id undoes the run. Off unless ' . self::OPT_IN_FILTER,
                'input_schema'         => [ 'type' => 'object', 'properties' => $run_args, 'required' => [ 'search', 'replace', 'tables', 'dry_run_token' ] ],
                'validate'             => static fn (array $a): ?array => self::args_refusal($a) ?? self::token_refusal($a),
                'confirm_preview'      => static fn (array $a): array => self::run_preview($a),
                'handler'              => static fn (array $a, array $context): array => self::run($a, $context),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Refusals
    // ------------------------------------------------------------------

    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    /** Refusal for the search and the tables, or null. */
    private static function args_refusal(array $args): ?array
    {
        $search  = (string) ($args['search'] ?? '');
        $replace = (string) ($args['replace'] ?? '');
        if ('' === trim($search)) {
            return self::refusal('empty_search', 'search must not be empty or only spaces.');
        }
        if ($search === $replace) {
            return self::refusal('nothing_to_replace', 'search and replace are identical; nothing would change.');
        }
        foreach ((array) ($args['tables'] ?? []) as $table) {
            $why = self::table_refusal((string) $table);
            if (null !== $why) {
                return $why;
            }
        }
        return null;
    }

    /**
     * Refusal for one table, or null: it must be one of this site's tables,
     * not protected, not WP MCP's own, and have a primary key.
     */
    private static function table_refusal(string $name): ?array
    {
        $table = Database_Guard::scoped_table($name);
        if (is_wp_error($table)) {
            return self::refusal('unknown_table', sprintf('"%s" is not a table on this site.', $name), [ 'table' => $name ]);
        }
        $why = self::unwritable_reason((string) $table);
        if (null === $why) {
            return null;
        }
        return self::refusal('table_refused', sprintf('Refusing to search and replace in "%s": %s.', $table, $why), [ 'table' => (string) $table ]);
    }

    /** Why a run may not write a resolved table, or null. */
    private static function unwritable_reason(string $table): ?string
    {
        global $wpdb;

        $prefix = (string) $wpdb->prefix;
        if (0 !== strpos(strtolower($table), strtolower($prefix))) {
            return 'it does not belong to this site (no ' . $prefix . ' prefix)';
        }
        $rest = substr($table, strlen($prefix));
        if ($prefix === (string) $wpdb->base_prefix && is_multisite() && 1 === preg_match('/^[0-9]+_/', $rest)) {
            return 'it belongs to another site of the network';
        }
        if (0 === stripos($rest, 'wpmcp_') || Database_Guard::is_internal_store_table($table)) {
            return 'it is one of WP MCP\'s own tables';
        }
        if (Database_Guard::is_protected($table)) {
            return 'it is protected (users, user meta and network options are never rewritten)';
        }
        if ([] === Database_Guard::primary_key($table)) {
            return 'it has no primary key, so a changed row could not be restored';
        }
        return null;
    }

    /** Refusal unless the dry_run_token is valid for these arguments, or null. */
    private static function token_refusal(array $args): ?array
    {
        if (null === self::token_rows($args)) {
            return self::refusal(
                'dry_run_required',
                'Run searchreplace-dry-run with exactly these search, replace, tables and case_sensitive first, and pass its dry_run_token (it is valid for 30 minutes and for the user who ran it).',
                []
            );
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Token
    // ------------------------------------------------------------------

    /** The arguments a token is bound to, normalized. */
    private static function token_payload(array $args, int $rows, int $expires): string
    {
        $tables = array_map('strtolower', array_map('strval', (array) ($args['tables'] ?? [])));
        sort($tables);

        return (string) wp_json_encode([
            'user'    => get_current_user_id(),
            'search'  => (string) ($args['search'] ?? ''),
            'replace' => (string) ($args['replace'] ?? ''),
            'case'    => self::case_sensitive($args),
            'tables'  => $tables,
            'rows'    => $rows,
            'expires' => $expires,
        ]);
    }

    private static function token(array $args, int $rows): array
    {
        $expires = time() + self::TOKEN_TTL;
        $mac     = hash_hmac('sha256', self::token_payload($args, $rows, $expires), wp_salt('auth'));
        return [ 'sr1.' . $expires . '.' . $rows . '.' . $mac, $expires ];
    }

    /** The row count a valid token was issued for, or null. */
    private static function token_rows(array $args): ?int
    {
        $parts = explode('.', (string) ($args['dry_run_token'] ?? ''));
        if (4 !== count($parts) || 'sr1' !== $parts[0] || ! ctype_digit($parts[1]) || ! ctype_digit($parts[2])) {
            return null;
        }
        $expires = (int) $parts[1];
        $rows    = (int) $parts[2];
        if ($expires < time()) {
            return null;
        }
        $mac = hash_hmac('sha256', self::token_payload($args, $rows, $expires), wp_salt('auth'));
        return hash_equals($mac, (string) $parts[3]) ? $rows : null;
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    private static function list_tables(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the live table list with its row estimates; nothing to cache.
        $status = (array) $wpdb->get_results($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like((string) $wpdb->prefix) . '%'), ARRAY_A);

        $tables = [];
        foreach ($status as $row) {
            $name = (string) ($row['Name'] ?? '');
            if ('' === $name || ! Database_Guard::table_in_scope($name)) {
                continue;
            }
            $why  = self::unwritable_reason($name);
            $item = [
                'name'          => $name,
                'rows_estimate' => (int) ($row['Rows'] ?? 0),
                'primary_key'   => Database_Guard::primary_key($name),
                'writable'      => null === $why,
            ];
            if (null !== $why) {
                $item['reason'] = $why;
            }
            $tables[] = $item;
            if (count($tables) >= self::MAX_LISTED_TABLES) {
                break;
            }
        }

        return [
            'tables'   => $tables,
            'plugins'  => [
                'better_search_replace' => self::bsr_active(),
                'search_regex'          => self::search_regex_active(),
            ],
            'profiles' => self::search_regex_presets(),
            'history'  => [
                'better_search_replace_last_result' => is_array(get_transient(self::BSR_RESULTS)),
                'wpmcp_last_run'                    => is_array(get_option(self::LAST_RUN_OPTION, null)),
            ],
            'note'     => 'Better Search Replace (free) keeps no saved profiles, only its last result; searchreplace-get-last-run reads it. Search Regex presets are listed under profiles.',
        ];
    }

    /** Search Regex presets: id, name, description, search, replacement, flags and sources. */
    private static function search_regex_presets(): array
    {
        $raw     = get_option(self::SEARCH_REGEX_PRESETS, '');
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach (array_slice(array_values($decoded), 0, self::MAX_PRESETS) as $preset) {
            if (! is_array($preset)) {
                continue;
            }
            $search = (array) ($preset['search'] ?? []);
            $out[]  = [
                'id'          => (string) ($preset['id'] ?? ''),
                'name'        => (string) ($preset['name'] ?? ''),
                'description' => (string) ($preset['description'] ?? ''),
                'search'      => (string) ($search['searchPhrase'] ?? ''),
                'replacement' => (string) ($search['replacement'] ?? ''),
                'flags'       => array_values(array_map('strval', (array) ($search['searchFlags'] ?? []))),
                'sources'     => array_values(array_map('strval', (array) ($search['source'] ?? []))),
            ];
        }
        return $out;
    }

    private static function last_run(): array
    {
        $ours = get_option(self::LAST_RUN_OPTION, null);
        $bsr  = get_transient(self::BSR_RESULTS);

        $theirs = null;
        if (is_array($bsr)) {
            $tables = [];
            foreach ((array) ($bsr['table_reports'] ?? []) as $table => $report) {
                $report   = (array) $report;
                $tables[] = [
                    'table'   => (string) $table,
                    'changes' => (int) ($report['change'] ?? 0),
                    'updates' => (int) ($report['updates'] ?? 0),
                ];
            }
            $theirs = [
                'search'           => (string) ($bsr['search_for'] ?? ''),
                'replace'          => (string) ($bsr['replace_with'] ?? ''),
                'dry_run'          => 'on' === ($bsr['dry_run'] ?? 'off'),
                'case_insensitive' => 'on' === ($bsr['case_insensitive'] ?? 'off'),
                'replace_guids'    => 'on' === ($bsr['replace_guids'] ?? 'off'),
                'changes'          => (int) ($bsr['change'] ?? 0),
                'updates'          => (int) ($bsr['updates'] ?? 0),
                'tables'           => $tables,
            ];
        }

        return [
            'wpmcp'                 => is_array($ours) ? $ours : null,
            'better_search_replace' => $theirs,
        ];
    }

    // ------------------------------------------------------------------
    // Scan
    // ------------------------------------------------------------------

    private static function case_sensitive(array $args): bool
    {
        return false !== ($args['case_sensitive'] ?? true);
    }

    /**
     * Scan the named tables. Returns per table the rows that would change
     * (with their full before-image and the new values of the changed
     * columns), the skipped cells, and the totals.
     *
     * @return array{tables: array<int, array<string, mixed>>, rows: int, matches: int, bytes: int, truncated: bool}
     */
    private static function scan(array $args): array
    {
        $search  = (string) $args['search'];
        $replace = (string) $args['replace'];
        $case    = self::case_sensitive($args);
        $limit   = self::max_rows();
        $walker  = new Url_Rewriter();

        $out = [ 'tables' => [], 'rows' => 0, 'matches' => 0, 'bytes' => 0, 'truncated' => false ];
        foreach ((array) $args['tables'] as $name) {
            $table   = (string) Database_Guard::scoped_table((string) $name);
            $pk      = Database_Guard::primary_key($table);
            $columns = self::text_columns($table, $pk);
            $report  = [ 'table' => $table, 'primary_key' => $pk, 'rows' => [], 'matches' => 0, 'skipped' => [], 'excluded' => 0 ];

            $budget = $limit - $out['rows'];
            $found  = [] === $columns ? [] : self::candidate_rows($table, $columns, $search, $budget + 1);
            if (count($found) > $budget) {
                $out['truncated'] = true;
                $found            = array_slice($found, 0, $budget);
            }

            foreach ($found as $row) {
                if (self::excluded_row($table, $row)) {
                    ++$report['excluded'];
                    continue;
                }
                $changes = [];
                $count   = 0;
                foreach ($columns as $column) {
                    $value = $row[ $column ] ?? null;
                    if (! is_string($value) || ! self::contains($value, $search, $case)) {
                        continue;
                    }
                    $hits = 0;
                    $new  = $walker->transform(
                        $value,
                        static function (string $leaf) use ($search, $replace, $case, &$hits): string {
                            $n    = 0;
                            $leaf = $case ? str_replace($search, $replace, $leaf, $n) : str_ireplace($search, $replace, $leaf, $n);
                            $hits += $n;
                            return $leaf;
                        },
                        false
                    );
                    $skip = self::skip_reason($value, $new);
                    if (null !== $skip) {
                        $report['skipped'][] = [ 'key' => self::key($row, $pk), 'column' => $column, 'reason' => $skip ];
                        continue;
                    }
                    $changes[ $column ] = [ 'from' => $value, 'to' => (string) $new, 'matches' => $hits ];
                    $count             += $hits;
                }
                if ([] === $changes) {
                    continue;
                }
                $report['rows'][]   = [ 'row' => $row, 'changes' => $changes ];
                $report['matches'] += $count;
                $out['matches']    += $count;
                $out['bytes']      += strlen((string) wp_json_encode($row));
                ++$out['rows'];
            }
            $out['tables'][] = $report;
        }
        return $out;
    }

    private static function contains(string $haystack, string $needle, bool $case): bool
    {
        return false !== ($case ? strpos($haystack, $needle) : stripos($haystack, $needle));
    }

    /** Why a changed cell is left alone, or null to write it. */
    private static function skip_reason(string $old, $new): ?string
    {
        if (! is_string($new) || $new === $old) {
            return is_serialized($old) ? 'serialized value holds an object or does not decode' : 'nothing replaceable outside array keys';
        }
        $trim = ltrim($old);
        if ('' !== $trim && ('{' === $trim[0] || '[' === $trim[0]) && null !== json_decode($old, true) && null === json_decode($new, true)) {
            return 'JSON would no longer decode after the replace';
        }
        if (! preg_match('//u', $new)) {
            return 'the result is not valid UTF-8';
        }
        return null;
    }

    /**
     * The text columns a run may rewrite: char, varchar, text and JSON,
     * never a primary key column or the posts guid.
     *
     * @param string[] $pk
     * @return string[]
     */
    private static function text_columns(string $table, array $pk): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the live column types of a validated table.
        $rows = (array) $wpdb->get_results($wpdb->prepare('SHOW COLUMNS FROM %i', $table), ARRAY_A);

        $out = [];
        foreach ($rows as $row) {
            $field = (string) ($row['Field'] ?? '');
            if ('' === $field || in_array($field, $pk, true) || 1 !== preg_match(self::TEXT_TYPE_PATTERN, (string) ($row['Type'] ?? ''))) {
                continue;
            }
            if ('guid' === $field && strtolower($table) === strtolower((string) $wpdb->posts)) {
                continue;
            }
            $out[] = $field;
        }
        return $out;
    }

    /**
     * Rows with the search in any text column (LIKE, so a superset when the
     * collation ignores case; the exact test runs in PHP), oldest key first.
     *
     * @param string[] $columns
     * @return array<int, array<string, string|null>>
     */
    private static function candidate_rows(string $table, array $columns, string $search, int $limit): array
    {
        global $wpdb;

        $like   = '%' . $wpdb->esc_like($search) . '%';
        $where  = implode(' OR ', array_fill(0, count($columns), '%i LIKE %s'));
        $values = [ $table ];
        foreach ($columns as $column) {
            $values[] = $column;
            $values[] = $like;
        }
        $pk    = Database_Guard::primary_key($table);
        $order = implode(', ', array_fill(0, count($pk), '%i'));
        foreach ($pk as $column) {
            $values[] = $column;
        }
        $values[] = max(1, $limit);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- assembled only from literal '%i LIKE %s' and '%i' fragments; every identifier and value is bound through $values.
        $sql = $wpdb->prepare('SELECT * FROM %i WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT %d', $values);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- reads the live rows a search and replace would change; $sql is prepared on the line above.
        $rows = $wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? array_values($rows) : [];
    }

    /** Whether a row is one a run never rewrites (salts, keys, WP MCP's own data). */
    private static function excluded_row(string $table, array $row): bool
    {
        global $wpdb;

        $name = strtolower($table);
        if (strtolower((string) $wpdb->options) === $name) {
            $option = (string) ($row['option_name'] ?? '');
            return 1 === preg_match(self::PROTECTED_OPTION_PATTERN, $option) || 1 === preg_match(self::OWN_OPTION_PATTERN, $option);
        }
        $private = Content_Guard::private_post_types();
        if (strtolower((string) $wpdb->posts) === $name) {
            return in_array((string) ($row['post_type'] ?? ''), $private, true);
        }
        if (strtolower((string) $wpdb->postmeta) === $name) {
            $post_id = (int) ($row['post_id'] ?? 0);
            return $post_id > 0 && in_array((string) get_post_type($post_id), $private, true);
        }
        return false;
    }

    /** @param string[] $pk */
    private static function key(array $row, array $pk): array
    {
        $key = [];
        foreach ($pk as $column) {
            $key[ $column ] = $row[ $column ] ?? null;
        }
        return $key;
    }

    /** A short window around the first match, with emails and IPs masked. */
    private static function snippet(string $text, string $needle, bool $case): string
    {
        $at    = $case ? strpos($text, $needle) : stripos($text, $needle);
        $at    = false === $at ? 0 : (int) $at;
        $start = max(0, $at - self::SNIPPET_PAD);
        $cut   = substr($text, $start, strlen($needle) + 2 * self::SNIPPET_PAD);
        $cut   = function_exists('mb_scrub') ? mb_scrub($cut, 'UTF-8') : $cut;

        return ($start > 0 ? '...' : '') . self::mask_text($cut) . ($start + strlen($cut) < strlen($text) ? '...' : '');
    }

    /** Emails and IPv4 addresses inside free text, masked with Masking. */
    private static function mask_text(string $text): string
    {
        $text = (string) preg_replace_callback(
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
            static fn (array $m): string => Masking::email($m[0]),
            $text
        );
        return (string) preg_replace_callback(
            '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/',
            static fn (array $m): string => (string) Masking::value($m[0]),
            $text
        );
    }

    // ------------------------------------------------------------------
    // Dry run
    // ------------------------------------------------------------------

    /** Why a scan cannot be run, or null. */
    private static function limit_reason(array $scan): ?string
    {
        if ($scan['truncated']) {
            return sprintf('More than %d rows match, the most one run may change. Run it table by table, or with a narrower search.', self::max_rows());
        }
        if ($scan['bytes'] > self::MAX_SNAPSHOT_BYTES) {
            return sprintf('The rows that would change hold %d bytes, past the %d byte snapshot limit of one run. Run it table by table.', (int) $scan['bytes'], self::MAX_SNAPSHOT_BYTES);
        }
        return null;
    }

    private static function dry_run(array $args): array
    {
        $scan   = self::scan($args);
        $case   = self::case_sensitive($args);
        $search = (string) $args['search'];

        $tables   = [];
        $excluded = 0;
        foreach ($scan['tables'] as $report) {
            $samples = [];
            foreach (array_slice($report['rows'], 0, self::SAMPLES) as $item) {
                foreach ($item['changes'] as $column => $change) {
                    $samples[] = [
                        'key'    => self::key($item['row'], $report['primary_key']),
                        'column' => (string) $column,
                        'before' => self::snippet($change['from'], $search, $case),
                        'after'  => self::snippet($change['to'], (string) $args['replace'], true),
                    ];
                    break;
                }
            }
            $tables[]  = [
                'table'         => $report['table'],
                'rows'          => count($report['rows']),
                'matches'       => $report['matches'],
                'samples'       => $samples,
                'skipped'       => array_slice($report['skipped'], 0, self::SKIPPED_LISTED),
                'skipped_count' => count($report['skipped']),
                'excluded_rows' => $report['excluded'],
            ];
            $excluded += $report['excluded'];
        }

        $out = [
            'search'         => $search,
            'replace'        => (string) $args['replace'],
            'case_sensitive' => $case,
            'tables'         => $tables,
            'rows'           => $scan['rows'],
            'matches'        => $scan['matches'],
            'excluded_rows'  => $excluded,
            'truncated'      => $scan['truncated'],
            'masked'         => true,
        ];

        $limit = self::limit_reason($scan);
        if (null !== $limit) {
            return $out + [ 'dry_run_token' => null, 'limit' => $limit ];
        }
        if (0 === $scan['rows']) {
            return $out + [ 'dry_run_token' => null, 'note' => 'Nothing matches, so there is nothing to run.' ];
        }

        [ $token, $expires ] = self::token($args, $scan['rows']);
        return $out + [
            'dry_run_token' => $token,
            'token_expires' => gmdate('Y-m-d H:i:s', $expires) . ' UTC',
            'next'          => 'searchreplace-run with the same search, replace, tables and case_sensitive, this dry_run_token and confirm:true. Every changed row is snapshotted; rollback-session with the session_id it returns undoes the run.',
        ];
    }

    // ------------------------------------------------------------------
    // Run
    // ------------------------------------------------------------------

    private static function run_preview(array $args): array
    {
        $rows = (int) self::token_rows($args);
        return [
            'message' => sprintf(
                'Replace "%s" with "%s" in up to %d rows of %s (as the dry run showed). Every row is snapshotted and rollback-session undoes the run.',
                (string) $args['search'],
                (string) $args['replace'],
                $rows,
                implode(', ', array_map('strval', (array) $args['tables']))
            ),
            'data'    => [ 'rows' => $rows, 'tables' => array_values(array_map('strval', (array) $args['tables'])) ],
        ];
    }

    private static function run(array $args, array $context): array
    {
        $allowed = (int) self::token_rows($args);
        $scan    = self::scan($args);

        $limit = self::limit_reason($scan);
        if (null !== $limit) {
            throw new Operation_Refused('size_limit', esc_html($limit . ' Nothing was changed.'), [ 'rows' => (int) $scan['rows'] ]);
        }
        if ($scan['rows'] > $allowed) {
            throw new Operation_Refused(
                'dry_run_stale',
                sprintf('The data changed since the dry run: %d rows match now, %d did then. Run searchreplace-dry-run again. Nothing was changed.', (int) $scan['rows'], (int) $allowed),
                [ 'rows' => (int) $scan['rows'], 'dry_run_rows' => (int) $allowed ]
            );
        }

        $session = (string) $context['session_id'];
        $ids     = [];
        $failed  = [];
        $summary = [];
        Snapshot_Store::hold_pruning(static function () use ($scan, $session, $context, $args, &$ids, &$failed, &$summary): void {
            foreach ($scan['tables'] as $report) {
                $changed = 0;
                $matches = 0;
                foreach ($report['rows'] as $item) {
                    $key = self::key($item['row'], $report['primary_key']);
                    try {
                        $ids[] = self::write_row($report['table'], $report['primary_key'], $item, $session, (string) $context['tool_name'], $args);
                        ++$changed;
                        $matches += array_sum(array_column($item['changes'], 'matches'));
                    } catch (Mutation_Failed $e) {
                        $failed[] = [ 'table' => $report['table'], 'key' => $key, 'reason' => $e->getMessage() ];
                    }
                }
                $summary[] = [ 'table' => $report['table'], 'rows_changed' => $changed, 'matches' => $matches, 'skipped' => count($report['skipped']), 'excluded_rows' => $report['excluded'] ];
            }
        });

        $rows_changed = array_sum(array_column($summary, 'rows_changed'));
        $record       = [
            'ran_at'         => gmdate('Y-m-d H:i:s') . ' UTC',
            'user_id'        => get_current_user_id(),
            'search'         => (string) $args['search'],
            'replace'        => (string) $args['replace'],
            'case_sensitive' => self::case_sensitive($args),
            'tables'         => $summary,
            'rows_changed'   => $rows_changed,
            'failed'         => count($failed),
            'session_id'     => $session,
        ];
        update_option(self::LAST_RUN_OPTION, $record, false);

        return [
            'result'        => [
                'tables'       => $summary,
                'rows_changed' => $rows_changed,
                'failed'       => $failed,
                'note'         => 'rollback-session with this session_id restores every changed row.',
            ],
            'operation_ids' => $ids,
        ];
    }

    /**
     * Write one row's changed columns under its own db_rows snapshot (the
     * whole row as the before-image). The row is read again inside the
     * write; one that changed since the scan is refused and its undo point
     * voided.
     *
     * @param string[] $pk
     */
    private static function write_row(string $table, array $pk, array $item, string $session, string $tool, array $args): string
    {
        $where = self::key($item['row'], $pk);
        $set   = array_map(static fn (array $c): string => $c['to'], $item['changes']);
        $probe = static function () use ($table, $where): array {
            return Database_Guard::before_image($table, $where, 1);
        };

        $out = Safe_Mutation::run(
            [
                'object_type'         => 'db_rows',
                'object_id'           => $table,
                'session_id'          => $session,
                'tool_name'           => $tool,
                'args'                => [ 'operation' => 'searchreplace-run', 'search' => (string) $args['search'], 'tables' => (array) $args['tables'] ],
                'object_ref'          => $table . ' ' . (string) wp_json_encode($where),
                'state_probe'         => $probe,
                'extra_snapshot_data' => [
                    'table'       => $table,
                    'operation'   => 'update',
                    'primary_key' => $pk,
                    'where'       => $where,
                    'set'         => $set,
                    'rows'        => [ $item['row'] ],
                ],
            ],
            static function () use ($table, $where, $set, $item): int {
                global $wpdb;

                $current = Database_Guard::before_image($table, $where, 1)[0] ?? null;
                foreach ($item['changes'] as $column => $change) {
                    if (null === $current || (string) ($current[ $column ] ?? '') !== $change['from']) {
                        throw new Mutation_Failed('The row changed after it was scanned; it was left alone.');
                    }
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a search and replace rewrites rows of arbitrary validated tables, which no WordPress API covers; the row is snapshotted first and the caches it makes stale are cleared right after.
                $affected = $wpdb->update($table, $set, $where);
                if (false === $affected) {
                    throw new Mutation_Failed('The row could not be written: ' . esc_html((string) $wpdb->last_error));
                }
                Database_Guard::invalidate_caches($table, [ 'rows' => [ $item['row'] ], 'where' => $where, 'data' => $set ]);
                return (int) $affected;
            }
        );

        return (string) $out['operation_id'];
    }
}
