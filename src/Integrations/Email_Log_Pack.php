<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Plugin_Table_Rows_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Email logs (issue #905) on the plugin-data pair, free tier, read only, so
 * it adds no top-level tool and writes nothing: the ops answer "did
 * WordPress send it, and if not why".
 *
 * Three free plugins on wordpress.org, each with a log table of its own
 * (read in WP Mail Logging 1.17.1, Check and Log Email 2.0.17 and Email Log
 * 2.64). Every time is stored by the plugin as site time (current_time):
 *  - WP Mail Logging (WP_MAIL_LOGGING_PLUGIN_DIR): {prefix}wpml_mails with
 *    mail_id, timestamp, receiver, subject, message, headers, attachments
 *    (paths joined by a comma and a newline) and error. It has NO status
 *    column: a failed send is a row whose error is not empty.
 *  - Check and Log Email (CK_MAIL_VERSION): {prefix}check_email_log with
 *    id, sent_date, to_email, subject, message, headers, attachments and,
 *    after the plugin's own table upgrade, result (1 sent, 0 failed),
 *    error_message, attachment_name and ip_address. Failures also land in
 *    {prefix}check_email_error_logs (check_email_log_id); a table that has
 *    not been upgraded yet is judged from that table.
 *  - Email Log (EMAIL_LOG_FILE): {prefix}email_log with the same columns
 *    as the upgraded Check and Log Email table (to_email, subject,
 *    message, headers, attachments, sent_date, attachment_name,
 *    ip_address, result, error_message). Its resend is a Pro feature of
 *    that plugin and is not part of this slice.
 *
 * Ops: emaillog-list-emails (newest first; filters recipient, subject,
 * status, since, until; page_size and offset), emaillog-email-stats (sent
 * and failed counts over a period, default the last 7 days, and the most
 * recent failure in it, masked like a list) and emaillog-get-email.
 * Recipients and bodies are personal data: a list shows every recipient as
 * j***@example.com (display names dropped), the subject and the error with
 * addresses masked, and never a body, headers or an IP address;
 * emaillog-get-email returns one email with its recipients in full, its
 * headers (Authorization, token, key, secret, password and cookie headers
 * blanked) and NO body by default (body: none), because a body can hold a
 * password, a one-time code or a reset link and the agent transcript would
 * keep it. body: truncated cuts it to 1,000 characters, body: full to
 * 20,000. A body that is returned is redacted on a best effort basis: links
 * with a known token key or word, or an opaque token in the path or query,
 * become [redacted-link], and the value after password, passcode, code, otp,
 * pin, token, secret or api key is replaced by [redacted] when it follows a
 * label (":", "=" or "is") or carries a digit, so wording such as "Password
 * Reset" stays readable. The patterns
 * cannot recognise every secret, so a body can still hold one. The IP
 * address Check and Log Email and Email Log store is never returned, and
 * attachments come back as file names only. Nothing here reads an option,
 * so no SMTP credential can leave through these ops.
 *
 * Presence is filterable (wpmcp_wp_mail_logging_active,
 * wpmcp_check_email_active, wpmcp_email_log_active). Without a plugin the
 * ops stay documented in list-operations (dependency_met:false) and answer
 * plugin_missing or plugin_inactive; with a plugin but no table yet they
 * answer email_log_tables_missing. Several plugins at once need a source.
 */
final class Email_Log_Pack
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads the logging plugins' own tables live; they have no cached read API.

    public const CAPABILITY = 'manage_options';

    public const DEFAULT_PAGE_SIZE = 20;

    public const MAX_PAGE_SIZE = 100;

    public const BODY_DEFAULT = 1000;

    public const BODY_FULL = 20000;

    public const ERROR_LIST = 200;

    public const REDACTED_LINK = '[redacted-link]';

    /**
     * source => [ label, main file under the plugins directory, table suffix,
     * columns (id, time, to, subject, body, headers, attachments) ].
     */
    private const SOURCES = [
        'wp-mail-logging' => [
            'label'   => 'WP Mail Logging',
            'file'    => 'wp-mail-logging/wp-mail-logging.php',
            'table'   => 'wpml_mails',
            'columns' => [ 'id' => 'mail_id', 'time' => 'timestamp', 'to' => 'receiver', 'subject' => 'subject', 'body' => 'message', 'headers' => 'headers', 'attachments' => 'attachments' ],
        ],
        'check-email'     => [
            'label'   => 'Check and Log Email',
            'file'    => 'check-email/check-email.php',
            'table'   => 'check_email_log',
            'columns' => [ 'id' => 'id', 'time' => 'sent_date', 'to' => 'to_email', 'subject' => 'subject', 'body' => 'message', 'headers' => 'headers', 'attachments' => 'attachments' ],
        ],
        'email-log'       => [
            'label'   => 'Email Log',
            'file'    => 'email-log/email-log.php',
            'table'   => 'email_log',
            'columns' => [ 'id' => 'id', 'time' => 'sent_date', 'to' => 'to_email', 'subject' => 'subject', 'body' => 'message', 'headers' => 'headers', 'attachments' => 'attachments' ],
        ],
    ];

    /** Query keys that carry a secret or a one-time grant. */
    private const LINK_KEYS = [
        'key', 'rp_key', 'token', 'access_token', 'auth', 'code', 'nonce', 'hash', 'login', 'sig', 'signature',
        'secret', 'password', 'pass', 'otp', 'verify', 'reset', 'confirm', 'activation_key', 'order_key',
    ];

    /** Headers whose value is never shown. */
    private const SECRET_HEADER = '/authorization|token|secret|api[-_]?key|password|cookie|credential|signature/i';

    /** Whether one of the plugins is loaded (filterable). */
    public static function source_active(string $source): bool
    {
        switch ($source) {
            case 'wp-mail-logging':
                return (bool) apply_filters('wpmcp_wp_mail_logging_active', defined('WP_MAIL_LOGGING_PLUGIN_DIR'));
            case 'check-email':
                return (bool) apply_filters('wpmcp_check_email_active', defined('CK_MAIL_VERSION'));
            default:
                return (bool) apply_filters('wpmcp_email_log_active', defined('EMAIL_LOG_FILE'));
        }
    }

    public static function active(): bool
    {
        return [] !== self::active_sources();
    }

    /** @return string[] */
    public static function active_sources(): array
    {
        return array_values(array_filter(array_keys(self::SOURCES), [ self::class, 'source_active' ]));
    }

    private static function installed(string $source): bool
    {
        return defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . self::SOURCES[ $source ]['file']);
    }

    private static function table(string $source): string
    {
        global $wpdb;
        return $wpdb->prefix . self::SOURCES[ $source ]['table'];
    }

    private static function error_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'check_email_error_logs';
    }

    /**
     * true when one of the plugins is loaded, otherwise plugin_inactive (the
     * files are installed) or plugin_missing, naming the plugins screen.
     *
     * @return true|array{code: string, message: string, data: array}
     */
    public static function presence()
    {
        if (self::active()) {
            return true;
        }
        $installed = false;
        foreach (array_keys(self::SOURCES) as $source) {
            $installed = $installed || self::installed($source);
        }
        $url = admin_url('plugins.php');

        return [
            'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
            'message' => sprintf(
                'WP Mail Logging, Check and Log Email and Email Log are %s on this site. Install and activate one of them (%s), then try again.',
                $installed ? 'installed but not active' : 'not installed',
                $url
            ),
            'data'    => [ 'plugins_url' => $url ],
        ];
    }

    /** @return true|array{code: string, message: string, data?: array} */
    public static function tables_presence()
    {
        $present = self::presence();
        if (true !== $present) {
            return $present;
        }
        foreach (self::active_sources() as $source) {
            $table = self::table($source);
            if (! Plugin_Table_Rows_Snapshot::table_exists($table)) {
                return [
                    'code'    => 'email_log_tables_missing',
                    'message' => sprintf('%s\'s table %s does not exist on this site. Deactivate and reactivate it so it creates its table, then try again.', self::SOURCES[ $source ]['label'], $table),
                    'data'    => [ 'plugins_url' => admin_url('plugins.php') ],
                ];
            }
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Catalog
    // ------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $source = [ 'type' => 'string', 'enum' => array_keys(self::SOURCES) ];
        $base   = [
            'mode'       => 'read',
            'capability' => self::CAPABILITY,
            'requires'   => static fn () => self::tables_presence(),
        ];

        return [
            'emaillog-list-emails' => $base + [
                'description'  => 'List emails logged by WP Mail Logging, Check and Log Email or Email Log, newest first: id, time (site time), recipient, subject, status (sent or failed), error, has_attachments, source. Filters: recipient (matches the stored address, which is masked in the result), subject (substrings), status, since and until (dates, site time), page_size (default 20, max 100), offset. source when several plugins are active. Recipients MASKED (j***@example.com), no bodies or headers; emaillog-get-email returns one email. Personal data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'source'    => $source,
                        'recipient' => [ 'type' => 'string', 'minLength' => 1 ],
                        'subject'   => [ 'type' => 'string', 'minLength' => 1 ],
                        'status'    => [ 'type' => 'string', 'enum' => [ 'sent', 'failed' ] ],
                        'since'     => [ 'type' => 'string' ],
                        'until'     => [ 'type' => 'string' ],
                        'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
                        'offset'    => [ 'type' => 'integer', 'minimum' => 0 ],
                    ],
                ],
                'validate'     => static fn (array $args): ?array => self::list_refusal($args),
                'handler'      => static fn (array $args): array => self::list_emails($args),
            ],
            'emaillog-email-stats' => $base + [
                'description'  => 'Count logged emails sent and failed over a period (since and until, site time; default the last 7 days) and show the most recent failure in it (id, time, masked recipient and subject, error). source when several plugins are active. Personal data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'source' => $source,
                        'since'  => [ 'type' => 'string' ],
                        'until'  => [ 'type' => 'string' ],
                    ],
                ],
                'validate'     => static fn (array $args): ?array => self::list_refusal($args),
                'handler'      => static fn (array $args): array => self::email_stats($args),
            ],
            'emaillog-get-email'   => $base + [
                'description'  => 'One logged email: recipients in full, subject, status, error, headers, attachment names. NO body unless body is truncated (1,000 characters) or full (20,000); a returned body is best-effort redacted (links with tokens, passwords, codes) and may still hold a secret. Credential headers blanked, IP never returned. source when several plugins are active. Personal data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'source' => $source,
                        'id'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'body'   => [ 'type' => 'string', 'enum' => [ 'none', 'truncated', 'full' ] ],
                    ],
                    'required'   => [ 'id' ],
                ],
                'validate'     => static fn (array $args): ?array => self::get_refusal($args),
                'handler'      => static fn (array $args): array => self::get_email($args),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Refusals
    // ------------------------------------------------------------------

    /**
     * The source an op reads, or a refusal naming why none can be chosen.
     *
     * @return string|array{code: string, message: string, data: array}
     */
    private static function resolve_source(array $args)
    {
        $asked = isset($args['source']) ? (string) $args['source'] : '';
        if ('' !== $asked) {
            if (! isset(self::SOURCES[ $asked ])) {
                return [ 'code' => 'invalid_source', 'message' => 'source is wp-mail-logging, check-email or email-log.', 'data' => [] ];
            }
            if (! self::source_active($asked)) {
                $url = admin_url('plugins.php');
                $set = self::installed($asked);
                return [
                    'code'    => $set ? 'plugin_inactive' : 'plugin_missing',
                    'message' => sprintf('%1$s is %2$s on this site. Install and activate %1$s (%3$s), then try again.', self::SOURCES[ $asked ]['label'], $set ? 'installed but not active' : 'not installed', $url),
                    'data'    => [ 'plugins_url' => $url ],
                ];
            }
            return $asked;
        }
        $active = self::active_sources();
        if (1 === count($active)) {
            return $active[0];
        }
        return [
            'code'    => 'source_required',
            'message' => 'More than one email log plugin is active. Pass source: ' . implode(', ', $active) . '.',
            'data'    => [ 'sources' => $active ],
        ];
    }

    /** @return array{code: string, message: string, data: array}|null */
    private static function list_refusal(array $args): ?array
    {
        $source = self::resolve_source($args);
        if (is_array($source)) {
            return $source;
        }
        foreach ([ 'since', 'until' ] as $key) {
            if (isset($args[ $key ]) && null === self::site_time((string) $args[ $key ])) {
                return [ 'code' => 'invalid_date', 'message' => sprintf('%s is not a date WP MCP can read; use 2026-11-15 or 2026-11-15 09:30:00 (site time).', $key), 'data' => [ 'field' => $key ] ];
            }
        }
        return null;
    }

    /** @return array{code: string, message: string, data: array}|null */
    private static function get_refusal(array $args): ?array
    {
        $source = self::resolve_source($args);
        if (is_array($source)) {
            return $source;
        }
        if (null === self::row($source, (int) $args['id'])) {
            return [ 'code' => 'email_not_found', 'message' => sprintf('%s has no logged email %d.', self::SOURCES[ $source ]['label'], (int) $args['id']), 'data' => [ 'id' => (int) $args['id'] ] ];
        }
        return null;
    }

    /** A date as a site-time 'Y-m-d H:i:s' (the zone the plugins store), or null. */
    private static function site_time(string $value): ?string
    {
        $value = trim($value);
        if ('' === $value) {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($value, wp_timezone());
        } catch (\Exception $e) {
            return null;
        }
        return $date->setTimezone(wp_timezone())->format('Y-m-d H:i:s');
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /** Whether a log table has a column (the plugins add some in their own upgrades). */
    private static function has_column(string $table, string $column): bool
    {
        global $wpdb;
        return null !== $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s', $table, $column));
    }

    /**
     * SQL (already prepared) that is true for a failed send of table alias e.
     */
    private static function failed_sql(string $source): string
    {
        global $wpdb;
        $table = self::table($source);
        if ('wp-mail-logging' === $source) {
            return "(e.`error` IS NOT NULL AND e.`error` <> '')";
        }
        $probe = 'check-email' === $source && Plugin_Table_Rows_Snapshot::table_exists(self::error_table())
            ? $wpdb->prepare('EXISTS (SELECT 1 FROM %i x WHERE x.`check_email_log_id` = e.`id`)', self::error_table())
            : '';
        if (self::has_column($table, 'result')) {
            $column = "(e.`result` = 0 OR (e.`error_message` IS NOT NULL AND e.`error_message` <> ''))";
            return '' === $probe ? $column : "({$column} OR {$probe})";
        }
        return '' === $probe ? '0=1' : $probe;
    }

    /** SQL (already prepared) for the error text of alias e. */
    private static function error_sql(string $source): string
    {
        $table = self::table($source);
        if ('wp-mail-logging' === $source) {
            return 'e.`error`';
        }
        return self::has_column($table, 'error_message') ? 'e.`error_message`' : "''";
    }

    /** SQL for the attachment file names of alias e ('' when the table has no such column). */
    private static function names_sql(string $source): string
    {
        return 'wp-mail-logging' !== $source && self::has_column(self::table($source), 'attachment_name') ? 'e.`attachment_name`' : "''";
    }

    /** @return string the SELECT list shared by list and get (columns of alias e) */
    private static function select_sql(string $source, bool $full): string
    {
        global $wpdb;
        $c    = self::SOURCES[ $source ]['columns'];
        $list = $wpdb->prepare('e.%i AS id, e.%i AS ts, e.%i AS recipient, e.%i AS subject, e.%i AS attachments', $c['id'], $c['time'], $c['to'], $c['subject'], $c['attachments']);
        $list .= ', ' . self::failed_sql($source) . ' AS failed, ' . self::error_sql($source) . ' AS error_text, ' . self::names_sql($source) . ' AS attachment_names';
        if ($full) {
            $list .= $wpdb->prepare(', e.%i AS body, e.%i AS headers', $c['body'], $c['headers']);
        }
        return $list;
    }

    /** @return array<string, mixed>|null the logged email, raw */
    private static function row(string $source, int $id): ?array
    {
        global $wpdb;
        $c = self::SOURCES[ $source ]['columns'];
        $select = self::select_sql($source, true);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $select is built with prepare() and fixed fragments.
        $row = $wpdb->get_row($wpdb->prepare("SELECT {$select} FROM %i e WHERE e.%i = %d", self::table($source), $c['id'], $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed> */
    private static function list_emails(array $args): array
    {
        global $wpdb;
        $source = self::resolve_source($args);
        $source = is_string($source) ? $source : 'wp-mail-logging';
        $c      = self::SOURCES[ $source ]['columns'];
        $table  = self::table($source);
        $size   = max(1, min(self::MAX_PAGE_SIZE, (int) ($args['page_size'] ?? self::DEFAULT_PAGE_SIZE)));
        $offset = max(0, (int) ($args['offset'] ?? 0));
        $where  = [ '1=1' ];

        if (isset($args['recipient'])) {
            $where[] = $wpdb->prepare('e.%i LIKE %s', $c['to'], '%' . $wpdb->esc_like((string) $args['recipient']) . '%');
        }
        if (isset($args['subject'])) {
            $where[] = $wpdb->prepare('e.%i LIKE %s', $c['subject'], '%' . $wpdb->esc_like((string) $args['subject']) . '%');
        }
        if (isset($args['status'])) {
            $failed  = self::failed_sql($source);
            $where[] = 'failed' === $args['status'] ? $failed : "NOT ({$failed})";
        }
        $since = isset($args['since']) ? self::site_time((string) $args['since']) : null;
        $until = isset($args['until']) ? self::site_time((string) $args['until']) : null;
        if (null !== $since) {
            $where[] = $wpdb->prepare('e.%i >= %s', $c['time'], $since);
        }
        if (null !== $until) {
            $where[] = $wpdb->prepare('e.%i <= %s', $c['time'], $until);
        }
        $clause = implode(' AND ', $where);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $clause is built above with prepare() and fixed fragments.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i e WHERE {$clause}", $table));
        $select = self::select_sql($source, false);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $select and $clause are built above with prepare() and fixed fragments.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT {$select} FROM %i e WHERE {$clause} ORDER BY e.%i DESC, e.%i DESC LIMIT %d OFFSET %d", $table, $c['time'], $c['id'], $size, $offset), ARRAY_A);

        $emails = [];
        foreach ($rows as $row) {
            $error = (string) $row['error_text'];
            $emails[] = [
                'id'              => (int) $row['id'],
                'source'          => $source,
                'time'            => (string) $row['ts'],
                'recipient'       => self::mask_recipients((string) $row['recipient']),
                'subject'         => Activity_Log_Pack::mask_text(self::redact_links((string) $row['subject'])),
                'status'          => (int) $row['failed'] ? 'failed' : 'sent',
                'error'           => self::cut(Activity_Log_Pack::mask_text(self::redact_links($error)), self::ERROR_LIST),
                'has_attachments' => [] !== self::attachment_names((string) $row['attachments'], (string) $row['attachment_names']),
            ];
        }

        return [
            'source'  => $source,
            'emails'  => $emails,
            'total'   => $total,
            'masked'  => true,
            'offset'  => $offset,
            'hasMore' => $offset + count($emails) < $total,
        ];
    }

    /** @return array<string, mixed> counts of one period and its most recent failure, masked like a list */
    private static function email_stats(array $args): array
    {
        global $wpdb;
        $source = self::resolve_source($args);
        $source = is_string($source) ? $source : 'wp-mail-logging';
        $c      = self::SOURCES[ $source ]['columns'];
        $table  = self::table($source);
        $until  = isset($args['until']) ? self::site_time((string) $args['until']) : null;
        $until  = $until ?? current_time('mysql');
        $since  = isset($args['since']) ? self::site_time((string) $args['since']) : null;
        $since  = $since ?? (new \DateTimeImmutable($until, wp_timezone()))->modify('-7 days')->format('Y-m-d H:i:s');
        $failed = self::failed_sql($source);
        $clause = $wpdb->prepare('e.%i >= %s AND e.%i <= %s', $c['time'], $since, $c['time'], $until);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $failed and $clause are built with prepare() and fixed fragments.
        $counts = (array) $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN {$failed} THEN 1 ELSE 0 END), 0) AS failed FROM %i e WHERE {$clause}", $table), ARRAY_A);
        $select = self::select_sql($source, false);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $select, $failed and $clause are built with prepare() and fixed fragments.
        $row = $wpdb->get_row($wpdb->prepare("SELECT {$select} FROM %i e WHERE {$clause} AND {$failed} ORDER BY e.%i DESC, e.%i DESC LIMIT 1", $table, $c['time'], $c['id']), ARRAY_A);

        $total = (int) ($counts['total'] ?? 0);
        $fails = (int) ($counts['failed'] ?? 0);
        $last  = null;
        if (is_array($row)) {
            $last = [
                'id'        => (int) $row['id'],
                'time'      => (string) $row['ts'],
                'recipient' => self::mask_recipients((string) $row['recipient']),
                'subject'   => Activity_Log_Pack::mask_text(self::redact_links((string) $row['subject'])),
                'error'     => self::cut(Activity_Log_Pack::mask_text(self::redact_links((string) $row['error_text'])), self::ERROR_LIST),
            ];
        }

        return [
            'source'       => $source,
            'since'        => $since,
            'until'        => $until,
            'total'        => $total,
            'sent'         => $total - $fails,
            'failed'       => $fails,
            'last_failure' => $last,
            'masked'       => true,
        ];
    }

    /** @return array<string, mixed> */
    private static function get_email(array $args): array
    {
        $source = self::resolve_source($args);
        $source = is_string($source) ? $source : 'wp-mail-logging';
        $row    = self::row($source, (int) $args['id']) ?? [];
        $mode   = (string) ($args['body'] ?? 'none');
        $limit  = 'full' === $mode ? self::BODY_FULL : self::BODY_DEFAULT;
        $raw    = self::redact_links((string) ($row['body'] ?? ''));
        $body   = 'none' === $mode ? '' : $raw;

        return [
            'source' => $source,
            'email'  => [
                'id'             => (int) ($row['id'] ?? 0),
                'time'           => (string) ($row['ts'] ?? ''),
                'recipient'      => (string) ($row['recipient'] ?? ''),
                'subject'        => self::redact_links((string) ($row['subject'] ?? '')),
                'status'         => (int) ($row['failed'] ?? 0) ? 'failed' : 'sent',
                'error'          => self::cut(self::redact_links((string) ($row['error_text'] ?? '')), 1000),
                'headers'        => self::redact_headers((string) ($row['headers'] ?? '')),
                'attachments'    => self::attachment_names((string) ($row['attachments'] ?? ''), (string) ($row['attachment_names'] ?? '')),
                'body'           => self::cut($body, $limit),
                'body_included'  => 'none' !== $mode,
                'body_length'    => self::length($raw),
                'body_truncated' => 'none' !== $mode && self::length($raw) > $limit,
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Masking and redaction
    // ------------------------------------------------------------------

    private static function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    }

    private static function cut(string $text, int $limit): string
    {
        if (self::length($text) <= $limit) {
            return $text;
        }
        return (function_exists('mb_substr') ? mb_substr($text, 0, $limit) : substr($text, 0, $limit)) . '...';
    }

    /** Recipient list as j***@example.com entries, display names dropped; '***' when no address is found. */
    public static function mask_recipients(string $recipients): string
    {
        if ('' === trim($recipients)) {
            return '';
        }
        preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $recipients, $found);
        $masked = [];
        foreach ($found[0] as $address) {
            $masked[] = Activity_Log_Pack::mask_text($address);
        }
        return [] === $masked ? '***' : implode(', ', array_values(array_unique($masked)));
    }

    /** File names (never paths) of the attachments a row records. @return string[] */
    private static function attachment_names(string $attachments, string $names): array
    {
        $out = [];
        foreach ([ $attachments, $names ] as $value) {
            $value = trim($value);
            if ('' === $value || in_array(strtolower($value), [ '0', 'false', 'true', 'null', '[]', 'a:0:{}' ], true)) {
                continue;
            }
            foreach (preg_split('/\s*,\s*\n?\s*|\n/', $value) ?: [] as $part) {
                $part = trim($part);
                if ('' !== $part) {
                    $out[] = basename(str_replace('\\', '/', $part));
                }
            }
        }
        return array_values(array_unique($out));
    }

    /** Whether a path segment or query value looks like a random token (16+ characters with letters and digits, or 32+ with hyphens). */
    private static function opaque_token(string $value): bool
    {
        if (1 === preg_match('/^[A-Za-z0-9_=]{16,}$/', $value)) {
            return 1 === preg_match('/\d/', $value) && 1 === preg_match('/[A-Za-z]/', $value);
        }
        return 1 === preg_match('/^[A-Za-z0-9_=\-]{32,}$/', $value) && 1 === preg_match('/\d/', $value);
    }

    /** Whether one URL carries a secret or a one-time grant. */
    private static function link_is_sensitive(string $url): bool
    {
        $parts = wp_parse_url(html_entity_decode($url));
        if (! is_array($parts)) {
            return true;
        }
        $path = strtolower((string) ($parts['path'] ?? ''));
        if (1 === preg_match('~/(reset|verify|magic|confirm|activate|unlock|invite|accept|download|unsubscribe)|wp-login\.php|lostpassword|resetpass|[?&]rp_~', $path . '?' . ($parts['query'] ?? ''))) {
            return true;
        }
        foreach (explode('/', $path) as $segment) {
            if (self::opaque_token($segment)) {
                return true;
            }
        }
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        foreach ($query as $name => $value) {
            if (is_string($value) && self::opaque_token($value)) {
                return true;
            }
            $name = strtolower((string) $name);
            if (in_array($name, self::LINK_KEYS, true)) {
                return true;
            }
            if ('action' === $name && is_string($value) && in_array(strtolower($value), [ 'rp', 'resetpass', 'confirm', 'verify', 'activate', 'login' ], true)) {
                return true;
            }
        }
        return false;
    }

    /** Links that carry a reset, login, verification or other one-time token replaced, and "code is 123456" style codes. */
    public static function redact_links(string $text): string
    {
        if ('' === $text) {
            return '';
        }
        $text = (string) preg_replace_callback(
            '~https?://[^\s"\'<>\)\]]+~i',
            static fn (array $m): string => self::link_is_sensitive($m[0]) ? self::REDACTED_LINK : $m[0],
            $text
        );
        // Links go first; the value passes then skip a [redacted-link] they land on (the atomic
        // separator group stops them backtracking onto the ":" or "is" instead).
        // A labelled value ("password: x", "password=x", "code is x") is always a secret.
        $words = '\b(password|passcode|passwd|pwd|passphrase|code|otp|pin|token|secret|api[_ -]?key)\b';
        $text  = (string) preg_replace('/' . $words . '((?>\s+is\b\s*[:=]?\s*|\s*[:=]\s*))(?!\[redacted-link\])(?=[^\s<]*[A-Za-z0-9])[^\s<]+/i', '$1$2[redacted]', $text);
        // Without a label only a value that carries a digit counts ("code 482913"), so plain
        // wording such as "Password Reset" or "your password, visit" stays readable (#953).
        return (string) preg_replace('/' . $words . '(\s+)(?!\[redacted)(?=[^\s<]*[0-9])[^\s<]+/i', '$1$2[redacted]', $text);
    }

    /** The header block with credential headers blanked and secrets in values removed. */
    public static function redact_headers(string $headers): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $headers) ?: [];
        foreach ($lines as $i => $line) {
            if (false === strpos($line, ':')) {
                $lines[ $i ] = self::redact_links($line);
                continue;
            }
            [ $name, $value ] = explode(':', $line, 2);
            $trimmed          = trim($value);
            if (1 === preg_match(self::SECRET_HEADER, $name) || 1 === preg_match('/^[A-Za-z0-9+\/=_\-.]{32,}$/', $trimmed)) {
                $lines[ $i ] = $name . ': [redacted]';
                continue;
            }
            $lines[ $i ] = $name . ':' . self::redact_links($value);
        }
        return implode("\n", $lines);
    }
}
