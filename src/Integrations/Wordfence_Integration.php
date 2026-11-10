<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Plugin_Table_Rows_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Wordfence support (issue #577) as free ops on the theme dispatcher pair, so
 * it adds no top-level tools:
 *  - get-wordfence-status:           scan summary (open issues by severity,
 *                                    last scan time and result, scan
 *                                    running), firewall mode and the brute
 *                                    force settings
 *  - list-wordfence-scan-issues:     scan issues with type, severity, status
 *                                    and file
 *  - list-wordfence-blocked-attacks: recent requests Wordfence blocked (wfHits)
 *  - list-wordfence-blocks:          the active IP blocks (wfBlocks7)
 *  - list-wordfence-login-failures:  recent failed logins (wfLogins)
 *  - block-wordfence-ip, unblock-wordfence-ip, ignore-wordfence-scan-issue:
 *                                    destructive writes (confirm:true with a
 *                                    preview), each snapshotted first as a
 *                                    plugin_table_rows image of the Wordfence
 *                                    rows it touches (every wfBlocks7 row on
 *                                    the IP, or the wfIssues row), so
 *                                    rollback-operation puts them back exactly
 *
 * Every op takes Wordfence's own admin capability (manage_options,
 * manage_network on a multisite network, where its tables and settings are
 * network-wide) and declares a 'requires' check, so with Wordfence inactive
 * the ops stay documented (dependency_met:false) and answer
 * wordfence_inactive without touching anything. The reads build their
 * answers from allowlisted fields only (never the API key, the alert email
 * or any other stored setting wholesale) and pass through redact() as a
 * second line, which also masks secret-shaped query parameters in attack
 * URLs.
 *
 * Wordfence's classes are reached through one adapter object (Wordfence_Api,
 * filterable through wpmcp_wordfence_api so tests inject a fake); the reads
 * query its tables directly, named the way Wordfence names them
 * (Plugin_Table_Rows_Snapshot::wordfence_table()).
 */
final class Wordfence_Integration
{
    private const MAX_LIMIT = 200;

    /** wfBlock type constants => names. */
    private const BLOCK_TYPES = [
        1 => 'manual',
        2 => 'wfsn_temporary',
        5 => 'rate_block',
        6 => 'rate_throttle',
        7 => 'lockout',
        8 => 'automatic_temporary',
        9 => 'automatic_permanent',
    ];

    /** wfIssues severity values => names. */
    private const SEVERITIES = [ 100 => 'critical', 75 => 'high', 50 => 'medium', 25 => 'low' ];

    /** @return array<string,array<string,mixed>> */
    public static function operations(): array
    {
        $requires = static fn () => Ops_Status_Packs::presence(self::is_active(), 'wordfence_inactive', 'Wordfence');
        $cap      = [ 'capability' => Plugin_Table_Rows_Snapshot::wordfence_capability() ];
        $limit    = [ 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'default' => 50 ] ];
        $ip       = [ 'ip' => [ 'type' => 'string', 'minLength' => 2, 'maxLength' => 45 ] ];

        return [
            'get-wordfence-status'           => $cap + Ops_Status_Packs::read_op(
                'Wordfence status: open scan issues by severity, last scan, firewall mode and brute force settings. No keys',
                $requires,
                static fn (): array => self::status()
            ),
            'list-wordfence-scan-issues'     => $cap + Ops_Status_Packs::read_op(
                'Wordfence scan issues: id, type, severity, status, message and file',
                $requires,
                static fn (array $args): array => self::scan_issues($args),
                $limit + [ 'status' => [ 'type' => 'string', 'enum' => [ 'new', 'ignoreP', 'ignoreC', 'all' ], 'default' => 'new' ] ]
            ),
            'list-wordfence-blocked-attacks' => $cap + Ops_Status_Packs::read_op(
                'Recent requests Wordfence blocked, newest first',
                $requires,
                static fn (array $args): array => self::blocked_attacks($args),
                $limit
            ),
            'list-wordfence-blocks'          => $cap + Ops_Status_Packs::read_op(
                'Active Wordfence IP blocks: id, ip, type, reason, expiration',
                $requires,
                static fn (array $args): array => self::blocks($args),
                $limit
            ),
            'list-wordfence-login-failures'  => $cap + Ops_Status_Packs::read_op(
                'Recent failed logins Wordfence recorded, newest first',
                $requires,
                static fn (array $args): array => self::login_failures($args),
                $limit
            ),
            'block-wordfence-ip'             => $cap + [
                'mode'            => 'destructive',
                'description'     => 'Block an IP in Wordfence (permanent unless duration, in seconds). Snapshotted; restorable with rollback-operation',
                'input_schema'    => [ 'type' => 'object', 'properties' => $ip + [
                    'reason'   => [ 'type' => 'string', 'maxLength' => 200, 'default' => 'Blocked via WP MCP' ],
                    'duration' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ],
                ], 'required' => [ 'ip' ] ],
                'requires'        => $requires,
                'validate'        => static fn (array $args): ?array => self::validate_block($args),
                'confirm_preview' => static fn (array $args): array => [
                    'message' => sprintf('Block %s in Wordfence.', $args['ip']),
                    'data'    => [ 'ip' => $args['ip'], 'duration' => (int) ($args['duration'] ?? 0) ],
                ],
                'snapshot'        => static fn (array $args): array => self::blocks_target((string) $args['ip']),
                'handler'         => static fn (array $args): array => self::block_ip($args),
            ],
            'unblock-wordfence-ip'           => $cap + [
                'mode'            => 'destructive',
                'description'     => 'Remove every Wordfence block on an IP. Snapshotted; restorable with rollback-operation',
                'input_schema'    => [ 'type' => 'object', 'properties' => $ip, 'required' => [ 'ip' ] ],
                'requires'        => $requires,
                'validate'        => static fn (array $args): ?array => self::validate_unblock($args),
                'confirm_preview' => static fn (array $args): array => [
                    'message' => sprintf('Unblock %s in Wordfence.', $args['ip']),
                    'data'    => [ 'ip' => $args['ip'], 'blocks' => count(self::ip_rows((string) $args['ip'])) ],
                ],
                'snapshot'        => static fn (array $args): array => self::blocks_target((string) $args['ip']),
                'handler'         => static fn (array $args): array => self::unblock_ip($args),
            ],
            'ignore-wordfence-scan-issue'    => $cap + [
                'mode'            => 'destructive',
                'description'     => 'Ignore a Wordfence scan issue (ignoreP: always, ignoreC: until it changes). Snapshotted; restorable with rollback-operation',
                'input_schema'    => [ 'type' => 'object', 'properties' => [
                    'issue_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    'how'      => [ 'type' => 'string', 'enum' => [ 'ignoreP', 'ignoreC' ], 'default' => 'ignoreC' ],
                ], 'required' => [ 'issue_id' ] ],
                'requires'        => $requires,
                'validate'        => static fn (array $args): ?array => self::validate_issue($args),
                'confirm_preview' => static fn (array $args): array => [
                    'message' => sprintf('Ignore Wordfence scan issue %d.', (int) $args['issue_id']),
                    'data'    => [ 'issue_id' => (int) $args['issue_id'], 'how' => $args['how'] ?? 'ignoreC' ],
                ],
                'snapshot'        => static fn (array $args): array => [
                    'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
                    'object_id'   => Plugin_Table_Rows_Snapshot::key('wordfence_issues', 'network', [ (int) $args['issue_id'] ]),
                ],
                'handler'         => static fn (array $args): array => self::ignore_issue($args),
            ],
        ];
    }

    /** Whether Wordfence is loaded, filterable through wpmcp_wordfence_active. */
    public static function is_active(): bool
    {
        return (bool) apply_filters('wpmcp_wordfence_active', class_exists('wordfence', false) && class_exists('wfConfig', false));
    }

    /**
     * The adapter over Wordfence's classes. Tests inject a fake through
     * wpmcp_wordfence_api; the default only touches classes that exist.
     *
     * @return object
     */
    private static function api(): object
    {
        $api = apply_filters('wpmcp_wordfence_api', null);
        return is_object($api) ? $api : new Wordfence_Api();
    }

    private static function table(string $name): string
    {
        return Plugin_Table_Rows_Snapshot::wordfence_table($name);
    }

    // -----------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------

    /** @return array<string,mixed> */
    private static function status(): array
    {
        $api    = self::api();
        $counts = self::issue_counts();

        return [
            'version'     => $api->version(),
            'scan'        => [
                'running'            => (bool) $api->scan_running(),
                'last_scan_time'     => Ops_Status_Packs::iso_time($api->config('scanTime')),
                'last_scan_result'   => self::scan_result($api->config('lastScanCompleted')),
                'issues_by_severity' => $counts['by_severity'],
                'new_issues'         => $counts['new'],
                'ignored_issues'     => $counts['ignored'],
            ],
            'firewall'    => [
                'mode'                  => self::firewall_mode($api->firewall_mode()),
                'rate_limiting_enabled' => (bool) $api->config('firewallEnabled'),
            ],
            'brute_force' => [
                'enabled'              => (bool) $api->config('loginSecurityEnabled'),
                'max_login_failures'   => (int) $api->config('loginSec_maxFailures'),
                'max_forgot_password'  => (int) $api->config('loginSec_maxForgotPasswd'),
                'count_window_minutes' => (int) $api->config('loginSec_countFailMins'),
                'lockout_minutes'      => (int) $api->config('loginSec_lockoutMins'),
                'lock_invalid_users'   => (bool) $api->config('loginSec_lockInvalidUsers'),
                'block_admin_username' => (bool) $api->config('loginSec_blockAdminReg'),
                'strong_passwords'     => (bool) $api->config('loginSec_strongPasswds_enabled'),
            ],
        ];
    }

    /**
     * Open issues by severity name, and the new and ignored totals, counted
     * in wfIssues the way Wordfence's getIssueCounts() does.
     *
     * @return array{by_severity: array<string,int>, new: int, ignored: int}
     */
    private static function issue_counts(): array
    {
        global $wpdb;
        $out   = [ 'by_severity' => [], 'new' => 0, 'ignored' => 0 ];
        $table = self::table('wfIssues');
        if (! Ops_Status_Packs::table_exists($table)) {
            return $out;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Wordfence's own table has no WP API.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT status, severity, COUNT(*) AS n FROM %i WHERE status IN (%s, %s, %s) GROUP BY status, severity ORDER BY severity DESC', $table, 'new', 'ignoreP', 'ignoreC'), ARRAY_A);
        foreach (is_array($rows) ? $rows : [] as $row) {
            $n = (int) $row['n'];
            if ('new' !== $row['status']) {
                $out['ignored'] += $n;
                continue;
            }
            $out['new'] += $n;
            $name                        = self::severity((int) $row['severity']);
            $out['by_severity'][ $name ] = ($out['by_severity'][ $name ] ?? 0) + $n;
        }
        return $out;
    }

    private static function severity(int $value): string
    {
        return self::SEVERITIES[ $value ] ?? (string) $value;
    }

    private static function scan_result($last_completed): ?string
    {
        if (null === $last_completed || false === $last_completed || '' === $last_completed) {
            return null;
        }
        return 'ok' === $last_completed ? 'ok' : 'failed';
    }

    private static function firewall_mode($status): string
    {
        $status = is_string($status) ? $status : '';
        return in_array($status, [ 'enabled', 'learning-mode', 'disabled' ], true) ? $status : 'unknown';
    }

    /** @return array<string,mixed> */
    private static function scan_issues(array $args): array
    {
        global $wpdb;
        $status   = (string) ($args['status'] ?? 'new');
        $limit    = self::limit($args);
        $table    = self::table('wfIssues');
        $statuses = 'all' === $status ? [ 'new', 'ignoreP', 'ignoreC' ] : [ $status ];
        if (! Ops_Status_Packs::table_exists($table)) {
            return [ 'issues' => [], 'count' => 0, 'limit' => $limit, 'table_missing' => true ];
        }
        $in = implode(',', array_fill(0, count($statuses), '%s'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Wordfence's own table has no WP API; $in holds only %s placeholders.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, time, type, severity, status, shortMsg, data FROM %i WHERE status IN ($in) ORDER BY severity DESC, time DESC, id DESC LIMIT %d", $table, ...array_merge($statuses, [ $limit ])), ARRAY_A);
        $out  = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[] = [
                'id'       => (int) $row['id'],
                'time'     => Ops_Status_Packs::iso_time($row['time']),
                'type'     => sanitize_key((string) $row['type']),
                'severity' => self::severity((int) $row['severity']),
                'status'   => (string) $row['status'],
                'message'  => wp_strip_all_tags((string) $row['shortMsg']),
                'file'     => self::issue_file($row['data']),
            ];
        }
        return [ 'issues' => $out, 'count' => count($out), 'limit' => $limit ];
    }

    /** The file an issue names, from its serialized data, read without instantiating any class. */
    private static function issue_file($data): ?string
    {
        if (! is_string($data) || '' === $data) {
            return null;
        }
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Wordfence stores issue data serialized; allowed_classes:false keeps any object out.
        $value = @unserialize($data, [ 'allowed_classes' => false ]);
        return is_array($value) && isset($value['file']) && is_string($value['file']) ? $value['file'] : null;
    }

    /** @return array<string,mixed> */
    private static function blocked_attacks(array $args): array
    {
        global $wpdb;
        $table = self::table('wfHits');
        $limit = self::limit($args);
        if (! Ops_Status_Packs::table_exists($table)) {
            return [ 'attacks' => [], 'count' => 0, 'limit' => $limit, 'table_missing' => true ];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Wordfence's own table has no WP API.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, ctime, IP, statusCode, action, actionDescription, URL FROM %i WHERE action LIKE %s ORDER BY ctime DESC, id DESC LIMIT %d', $table, 'blocked%', $limit), ARRAY_A);
        $out  = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[] = [
                'id'          => (int) $row['id'],
                'time'        => Ops_Status_Packs::iso_time((float) $row['ctime']),
                'ip'          => self::ip($row['IP']),
                'status_code' => (int) $row['statusCode'],
                'action'      => sanitize_text_field((string) $row['action']),
                'description' => sanitize_text_field((string) $row['actionDescription']),
                'url'         => self::url((string) $row['URL']),
            ];
        }
        return [ 'attacks' => $out, 'count' => count($out), 'limit' => $limit ];
    }

    /** @return array<string,mixed> */
    private static function blocks(array $args): array
    {
        global $wpdb;
        $table = self::table('wfBlocks7');
        $limit = self::limit($args);
        if (! Ops_Status_Packs::table_exists($table)) {
            return [ 'blocks' => [], 'count' => 0, 'limit' => $limit, 'table_missing' => true ];
        }
        $types = array_keys(self::BLOCK_TYPES);
        $in    = implode(',', array_fill(0, count($types), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Wordfence's own table has no WP API; $in holds only %d placeholders.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, type, IP, blockedTime, reason, expiration, blockedHits FROM %i WHERE type IN ($in) AND (expiration = 0 OR expiration > %d) ORDER BY blockedTime DESC, id DESC LIMIT %d", $table, ...array_merge($types, [ time(), $limit ])), ARRAY_A);
        $out  = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[] = self::block_view($row);
        }
        return [ 'blocks' => $out, 'count' => count($out), 'limit' => $limit ];
    }

    /** @return array<string,mixed> */
    private static function block_view(array $row): array
    {
        return [
            'id'           => (int) $row['id'],
            'type'         => self::BLOCK_TYPES[ (int) $row['type'] ] ?? (string) $row['type'],
            'ip'           => self::ip($row['IP']),
            'blocked_time' => Ops_Status_Packs::iso_time($row['blockedTime']),
            'reason'       => sanitize_text_field((string) $row['reason']),
            'expiration'   => Ops_Status_Packs::iso_time($row['expiration']),
            'blocked_hits' => (int) $row['blockedHits'],
        ];
    }

    /** @return array<string,mixed> */
    private static function login_failures(array $args): array
    {
        global $wpdb;
        $table = self::table('wfLogins');
        $limit = self::limit($args);
        if (! Ops_Status_Packs::table_exists($table)) {
            return [ 'failures' => [], 'count' => 0, 'limit' => $limit, 'table_missing' => true ];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Wordfence's own table has no WP API.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, ctime, IP, username, action FROM %i WHERE fail = 1 ORDER BY ctime DESC, id DESC LIMIT %d', $table, $limit), ARRAY_A);
        $out  = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[] = [
                'id'       => (int) $row['id'],
                'time'     => Ops_Status_Packs::iso_time((float) $row['ctime']),
                'ip'       => self::ip($row['IP']),
                'username' => sanitize_user((string) $row['username'], true),
                'action'   => sanitize_key((string) $row['action']),
            ];
        }
        return [ 'failures' => $out, 'count' => count($out), 'limit' => $limit ];
    }

    // -----------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------

    /** The 16 bytes Wordfence stores for an IP: IPv6, with IPv4 mapped into it. */
    public static function ip_binary(string $ip): string
    {
        $bin = (string) inet_pton($ip);
        return 4 === strlen($bin) ? str_repeat("\0", 10) . "\xff\xff" . $bin : $bin;
    }

    /** @return array{object_type: string, object_id: string} */
    private static function blocks_target(string $ip): array
    {
        return [
            'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
            'object_id'   => Plugin_Table_Rows_Snapshot::key('wordfence_blocks', 'network', [ bin2hex(self::ip_binary($ip)) ]),
        ];
    }

    /**
     * Every wfBlocks7 row on an IP, of any type and expiry: the set an
     * unblock deletes.
     *
     * @return array<int, array<string,mixed>>
     */
    private static function ip_rows(string $ip): array
    {
        global $wpdb;
        $table = self::table('wfBlocks7');
        if (false === filter_var($ip, FILTER_VALIDATE_IP) || ! Ops_Status_Packs::table_exists($table)) {
            return [];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Wordfence's own table has no WP API.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, type, IP, blockedTime, reason, expiration, blockedHits FROM %i WHERE IP = UNHEX(%s) ORDER BY id ASC', $table, bin2hex(self::ip_binary($ip))), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    private static function invalid_ip(array $args): ?array
    {
        if (false === filter_var($args['ip'] ?? '', FILTER_VALIDATE_IP)) {
            return [ 'code' => 'invalid_ip', 'message' => 'ip must be a valid IPv4 or IPv6 address.' ];
        }
        if (! Ops_Status_Packs::table_exists(self::table('wfBlocks7'))) {
            return [ 'code' => 'wordfence_table_missing', 'message' => 'Wordfence\'s blocks table is missing; open Wordfence once in wp-admin so it can finish installing.' ];
        }
        return null;
    }

    /** Refuses what Wordfence's own Blocking screen refuses: this request's own IP and an allowlisted IP. */
    private static function validate_block(array $args): ?array
    {
        $refusal = self::invalid_ip($args);
        if (null !== $refusal) {
            return $refusal;
        }
        $ip  = (string) $args['ip'];
        $api = self::api();
        $own = $api->own_ip();
        if (is_string($own) && false !== filter_var($own, FILTER_VALIDATE_IP) && self::ip_binary($own) === self::ip_binary($ip)) {
            return [ 'code' => 'wordfence_own_ip', 'message' => 'That is the IP this request comes from; blocking it would lock you out.' ];
        }
        if ($api->is_allowlisted($ip)) {
            return [ 'code' => 'wordfence_ip_allowlisted', 'message' => 'That IP is on the Wordfence allowlist (or in a range Wordfence never blocks), so Wordfence would not block it.' ];
        }
        return null;
    }

    private static function validate_unblock(array $args): ?array
    {
        $refusal = self::invalid_ip($args);
        if (null !== $refusal) {
            return $refusal;
        }
        if ([] === self::ip_rows((string) $args['ip'])) {
            return [ 'code' => 'wordfence_ip_not_blocked', 'message' => sprintf('Wordfence has no block on %s.', $args['ip']) ];
        }
        return null;
    }

    private static function validate_issue(array $args): ?array
    {
        if (null === self::issue_status((int) $args['issue_id'])) {
            return [ 'code' => 'wordfence_issue_not_found', 'message' => sprintf('Wordfence scan issue %d was not found.', (int) $args['issue_id']) ];
        }
        return null;
    }

    private static function issue_status(int $id): ?string
    {
        global $wpdb;
        $table = self::table('wfIssues');
        if (! Ops_Status_Packs::table_exists($table)) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Wordfence's own table has no WP API.
        $status = $wpdb->get_var($wpdb->prepare('SELECT status FROM %i WHERE id = %d', $table, $id));
        return null === $status ? null : (string) $status;
    }

    /** @return array<string,mixed> */
    private static function block_ip(array $args): array
    {
        $ip       = (string) $args['ip'];
        $duration = (int) ($args['duration'] ?? 0);
        self::api()->block_ip($ip, sanitize_text_field((string) ($args['reason'] ?? 'Blocked via WP MCP')), $duration);
        return [
            'ip'       => $ip,
            'duration' => $duration,
            'blocks'   => array_map([ self::class, 'block_view' ], self::ip_rows($ip)),
        ];
    }

    /** @return array<string,mixed> */
    private static function unblock_ip(array $args): array
    {
        $ip     = (string) $args['ip'];
        $before = count(self::ip_rows($ip));
        self::api()->unblock_ip($ip);
        $left = count(self::ip_rows($ip));
        return [ 'ip' => $ip, 'removed' => $before - $left, 'remaining' => $left ];
    }

    /** @return array<string,mixed> */
    private static function ignore_issue(array $args): array
    {
        $id  = (int) $args['issue_id'];
        $how = (string) ($args['how'] ?? 'ignoreC');
        self::api()->ignore_issue($id, $how);
        return [ 'issue_id' => $id, 'how' => $how, 'status' => self::issue_status($id) ];
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** A Wordfence binary IP column as text, IPv4-mapped addresses as plain IPv4. */
    private static function ip($value): ?string
    {
        if (! is_string($value) || 16 !== strlen($value)) {
            return null;
        }
        if (str_repeat("\0", 10) . "\xff\xff" === substr($value, 0, 12)) {
            $value = substr($value, 12);
        }
        $text = inet_ntop($value);
        return false === $text ? null : $text;
    }

    /** A request URL with the values of secret-shaped query parameters masked. */
    private static function url(string $url): string
    {
        $url   = esc_url_raw($url);
        $parts = explode('?', $url, 2);
        if (2 !== count($parts) || '' === $parts[1]) {
            return $url;
        }
        $query = [];
        foreach (explode('&', $parts[1]) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, null);
            $masked         = Ops_Status_Packs::redact([ rawurldecode((string) $name) => $value ]);
            $query[]        = null === $value ? $name : $name . '=' . (Ops_Status_Packs::REDACTED === reset($masked) ? Ops_Status_Packs::REDACTED : $value);
        }
        return $parts[0] . '?' . implode('&', $query);
    }

    private static function limit(array $args): int
    {
        return max(1, min(self::MAX_LIMIT, (int) ($args['limit'] ?? 50)));
    }
}
