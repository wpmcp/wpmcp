<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Wordfence support, read-heavy, as free ops on the theme dispatcher pair so
 * it adds no top-level tools:
 *  - get-wordfence-status:       scan summary (issues by severity, last scan
 *                                time, scan running), firewall mode and the
 *                                brute force settings
 *  - list-wordfence-scan-issues: scan issues with type, severity, status and file
 *  - list-wordfence-blocked-attacks: recent firewall blocks from wfHits
 *  - list-wordfence-blocks:      the active IP blocks from wfBlocks7
 *  - list-wordfence-login-failures: recent failed logins from wfLogins
 *  - block-wordfence-ip, unblock-wordfence-ip, ignore-wordfence-scan-issue:
 *                                writes, destructive mode (confirm:true with a
 *                                preview). Wordfence keeps this state in its
 *                                own tables, which no Snapshot type covers,
 *                                so the writes are reported recoverable:false
 *
 * Wordfence's classes (wfConfig, wfBlock, wfIssues, wordfence) are reached
 * through one adapter object, filterable through wpmcp_wordfence_api, so
 * tests inject a fake and nothing here calls them without class_exists guards.
 * API keys and the alert email never leave: the settings read is an allowlist.
 */
final class Wordfence_Integration
{
    private const MAX_LIMIT = 200;

    /** @return array<string,array<string,mixed>> */
    public static function operations(): array
    {
        $requires = static fn () => Ops_Status_Packs::presence(self::is_active(), 'wordfence_inactive', 'Wordfence');
        $limit    = [ 'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'default' => 50 ] ];
        $ip       = [ 'ip' => [ 'type' => 'string', 'minLength' => 3, 'maxLength' => 45 ] ];

        return [
            'get-wordfence-status'            => Ops_Status_Packs::read_op(
                'Wordfence status: scan summary (issues by severity, last scan time), firewall mode and brute force settings. No keys',
                $requires,
                static fn (): array => self::status()
            ),
            'list-wordfence-scan-issues'      => Ops_Status_Packs::read_op(
                'Wordfence scan issues: id, type, severity, status, short message and file',
                $requires,
                static fn (array $args): array => self::scan_issues($args),
                $limit + [ 'status' => [ 'type' => 'string', 'enum' => [ 'new', 'ignoreP', 'ignoreC', 'all' ], 'default' => 'new' ] ]
            ),
            'list-wordfence-blocked-attacks'  => Ops_Status_Packs::read_op(
                'Recent requests Wordfence blocked (firewall and manual blocks), newest first',
                $requires,
                static fn (array $args): array => self::blocked_attacks($args),
                $limit
            ),
            'list-wordfence-blocks'           => Ops_Status_Packs::read_op(
                'Active Wordfence IP blocks: id, ip, reason, type, expiration',
                $requires,
                static fn (array $args): array => self::blocks($args),
                $limit
            ),
            'list-wordfence-login-failures'   => Ops_Status_Packs::read_op(
                'Recent failed logins Wordfence recorded, newest first',
                $requires,
                static fn (array $args): array => self::login_failures($args),
                $limit
            ),
            'block-wordfence-ip'              => [
                'mode'            => 'destructive',
                'capability'      => 'manage_options',
                'description'     => 'Block an IP address in Wordfence (permanent unless duration given, in seconds). Not reversible through rollback: use unblock-wordfence-ip',
                'input_schema'    => [ 'type' => 'object', 'properties' => $ip + [
                    'reason'   => [ 'type' => 'string', 'maxLength' => 200, 'default' => 'Blocked via WP MCP' ],
                    'duration' => [ 'type' => 'integer', 'minimum' => 0, 'default' => 0 ],
                ], 'required' => [ 'ip' ] ],
                'requires'        => $requires,
                'validate'        => static fn (array $args): ?array => self::validate_ip($args),
                'confirm_preview' => static fn (array $args): array => [
                    'message' => sprintf('Block %s in Wordfence.', $args['ip']),
                    'data'    => [ 'ip' => $args['ip'], 'duration' => (int) ($args['duration'] ?? 0) ],
                ],
                'undoable'        => false,
                'handler'         => static fn (array $args): array => self::block_ip($args),
            ],
            'unblock-wordfence-ip'            => [
                'mode'            => 'destructive',
                'capability'      => 'manage_options',
                'description'     => 'Remove the Wordfence blocks on an IP address',
                'input_schema'    => [ 'type' => 'object', 'properties' => $ip, 'required' => [ 'ip' ] ],
                'requires'        => $requires,
                'validate'        => static fn (array $args): ?array => self::validate_ip($args),
                'confirm_preview' => static fn (array $args): array => [
                    'message' => sprintf('Unblock %s in Wordfence.', $args['ip']),
                    'data'    => [ 'ip' => $args['ip'] ],
                ],
                'undoable'        => false,
                'handler'         => static fn (array $args): array => self::unblock_ip($args),
            ],
            'ignore-wordfence-scan-issue'     => [
                'mode'            => 'destructive',
                'capability'      => 'manage_options',
                'description'     => 'Mark a Wordfence scan issue ignored (ignoreP: always for this issue, ignoreC: until the file changes)',
                'input_schema'    => [ 'type' => 'object', 'properties' => [
                    'issue_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    'how'      => [ 'type' => 'string', 'enum' => [ 'ignoreP', 'ignoreC' ], 'default' => 'ignoreC' ],
                ], 'required' => [ 'issue_id' ] ],
                'requires'        => $requires,
                'confirm_preview' => static fn (array $args): array => [
                    'message' => sprintf('Ignore Wordfence scan issue %d.', (int) $args['issue_id']),
                    'data'    => [ 'issue_id' => (int) $args['issue_id'], 'how' => $args['how'] ?? 'ignoreC' ],
                ],
                'undoable'        => false,
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
     * The adapter over Wordfence's static classes. Tests inject a fake through
     * wpmcp_wordfence_api; the default only touches classes that exist.
     *
     * @return object
     */
    private static function api(): object
    {
        $api = apply_filters('wpmcp_wordfence_api', null);
        return is_object($api) ? $api : new Wordfence_Api();
    }

    /** @return array<string,mixed> */
    private static function status(): array
    {
        $api     = self::api();
        $summary = $api->issue_summary();

        return [
            'version'     => $api->version(),
            'scan'        => [
                'running'            => (bool) $api->config('wf_scanRunning'),
                'last_scan_time'     => Ops_Status_Packs::iso_time($api->config('lastScanCompleted') ? $api->config('scanTime') : $api->config('scanTime')),
                'last_scan_result'   => self::scan_result($api->config('lastScanCompleted')),
                'issues_by_severity' => $summary['by_severity'] ?? [],
                'new_issues'         => (int) ($summary['new'] ?? 0),
                'ignored_issues'     => (int) ($summary['ignored'] ?? 0),
            ],
            'firewall'    => [
                'enabled' => (bool) $api->config('firewallEnabled'),
                'mode'    => self::firewall_mode($api->waf_status()),
            ],
            'brute_force' => [
                'login_security_enabled' => (bool) $api->config('loginSecurityEnabled'),
                'max_login_failures'     => (int) $api->config('loginSec_maxFailures'),
                'max_forgot_password'    => (int) $api->config('loginSec_maxForgotPasswd'),
                'count_window'           => (int) $api->config('loginSec_countFailMins'),
                'lockout_minutes'        => (int) $api->config('loginSec_lockoutMins'),
                'lock_invalid_users'     => (bool) $api->config('loginSec_lockInvalidUsers'),
                'block_admin_username'   => (bool) $api->config('loginSec_blockAdminReg'),
                'strong_passwords'       => (bool) $api->config('loginSec_strongPasswds_enabled'),
            ],
        ];
    }

    private static function scan_result($last_completed): ?string
    {
        if (null === $last_completed || '' === $last_completed) {
            return null;
        }
        return 'ok' === $last_completed ? 'ok' : 'error';
    }

    private static function firewall_mode($status): string
    {
        $status = is_string($status) ? $status : '';
        return in_array($status, [ 'enabled', 'learning-mode', 'disabled' ], true) ? $status : 'unknown';
    }

    /** @return array<string,mixed> */
    private static function scan_issues(array $args): array
    {
        $status = $args['status'] ?? 'new';
        $limit  = self::limit($args);
        $rows   = self::api()->issues('all' === $status ? null : $status, $limit);
        $out    = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $data  = isset($row['data']) && is_array($row['data']) ? $row['data'] : [];
            $out[] = [
                'id'        => (int) ($row['id'] ?? 0),
                'time'      => Ops_Status_Packs::iso_time($row['time'] ?? 0),
                'type'      => sanitize_key((string) ($row['type'] ?? '')),
                'severity'  => (int) ($row['severity'] ?? 0),
                'status'    => sanitize_key((string) ($row['status'] ?? '')),
                'message'   => wp_strip_all_tags((string) ($row['shortMsg'] ?? '')),
                'file'      => isset($data['file']) ? (string) $data['file'] : null,
            ];
        }
        return [ 'issues' => $out, 'count' => count($out), 'limit' => $limit ];
    }

    /** @return array<string,mixed> */
    private static function blocked_attacks(array $args): array
    {
        global $wpdb;
        $table = $wpdb->base_prefix . 'wfHits';
        $limit = self::limit($args);
        if (! Ops_Status_Packs::table_exists($table)) {
            return [ 'attacks' => [], 'count' => 0, 'table_missing' => true ];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Wordfence's own table has no WP API.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, ctime, INET6_NTOA(IP) AS ip, statusCode, action, actionDescription, URL FROM %i WHERE action <> %s ORDER BY ctime DESC LIMIT %d', $table, '', $limit), ARRAY_A);
        $out  = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[] = [
                'id'          => (int) $row['id'],
                'time'        => Ops_Status_Packs::iso_time((float) $row['ctime']),
                'ip'          => self::ip($row['ip']),
                'status_code' => (int) $row['statusCode'],
                'action'      => sanitize_text_field((string) $row['action']),
                'description' => sanitize_text_field((string) $row['actionDescription']),
                'url'         => esc_url_raw((string) $row['URL']),
            ];
        }
        return [ 'attacks' => $out, 'count' => count($out), 'limit' => $limit ];
    }

    /** @return array<string,mixed> */
    private static function blocks(array $args): array
    {
        global $wpdb;
        $table = $wpdb->base_prefix . 'wfBlocks7';
        $limit = self::limit($args);
        if (! Ops_Status_Packs::table_exists($table)) {
            return [ 'blocks' => [], 'count' => 0, 'table_missing' => true ];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Wordfence's own table has no WP API.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, type, INET6_NTOA(IP) AS ip, blockedTime, reason, expiration, blockedHits FROM %i WHERE expiration = 0 OR expiration > %d ORDER BY blockedTime DESC LIMIT %d', $table, time(), $limit), ARRAY_A);
        $out  = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[] = [
                'id'           => (int) $row['id'],
                'type'         => (int) $row['type'],
                'ip'           => self::ip($row['ip']),
                'blocked_time' => Ops_Status_Packs::iso_time($row['blockedTime']),
                'reason'       => sanitize_text_field((string) $row['reason']),
                'expiration'   => Ops_Status_Packs::iso_time($row['expiration']),
                'blocked_hits' => (int) $row['blockedHits'],
            ];
        }
        return [ 'blocks' => $out, 'count' => count($out), 'limit' => $limit ];
    }

    /** @return array<string,mixed> */
    private static function login_failures(array $args): array
    {
        global $wpdb;
        $table = $wpdb->base_prefix . 'wfLogins';
        $limit = self::limit($args);
        if (! Ops_Status_Packs::table_exists($table)) {
            return [ 'failures' => [], 'count' => 0, 'table_missing' => true ];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Wordfence's own table has no WP API.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT id, ctime, INET6_NTOA(IP) AS ip, username, action FROM %i WHERE fail = 1 ORDER BY ctime DESC LIMIT %d', $table, $limit), ARRAY_A);
        $out  = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[] = [
                'id'       => (int) $row['id'],
                'time'     => Ops_Status_Packs::iso_time((float) $row['ctime']),
                'ip'       => self::ip($row['ip']),
                'username' => sanitize_user((string) $row['username'], true),
                'action'   => sanitize_key((string) $row['action']),
            ];
        }
        return [ 'failures' => $out, 'count' => count($out), 'limit' => $limit ];
    }

    private static function validate_ip(array $args): ?array
    {
        if (false === filter_var($args['ip'] ?? '', FILTER_VALIDATE_IP)) {
            return [ 'code' => 'invalid_ip', 'message' => 'ip must be a valid IPv4 or IPv6 address.' ];
        }
        return null;
    }

    /** @return array<string,mixed> */
    private static function block_ip(array $args): array
    {
        $duration = (int) ($args['duration'] ?? 0);
        self::api()->block_ip($args['ip'], (string) ($args['reason'] ?? 'Blocked via WP MCP'), $duration);
        return [ 'blocked' => true, 'ip' => $args['ip'], 'duration' => $duration, 'recoverable' => false ];
    }

    /** @return array<string,mixed> */
    private static function unblock_ip(array $args): array
    {
        $removed = self::api()->unblock_ip($args['ip']);
        return [ 'unblocked' => true, 'ip' => $args['ip'], 'removed' => (int) $removed, 'recoverable' => false ];
    }

    /** @return array<string,mixed> */
    private static function ignore_issue(array $args): array
    {
        $how = $args['how'] ?? 'ignoreC';
        $ok  = self::api()->ignore_issue((int) $args['issue_id'], $how);
        if (! $ok) {
            throw new Operation_Refused('wordfence_issue_not_found', sprintf('Wordfence scan issue %d was not found.', (int) $args['issue_id']));
        }
        return [ 'ignored' => true, 'issue_id' => (int) $args['issue_id'], 'how' => $how, 'recoverable' => false ];
    }

    /** An INET6_NTOA value as Wordfence stores it (IPv4 mapped into IPv6) back to dotted IPv4 when it is one. */
    private static function ip($value): string
    {
        $value = (string) $value;
        return 0 === stripos($value, '::ffff:') ? substr($value, 7) : $value;
    }

    private static function limit(array $args): int
    {
        return max(1, min(self::MAX_LIMIT, (int) ($args['limit'] ?? 50)));
    }
}
