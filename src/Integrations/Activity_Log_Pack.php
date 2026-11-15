<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Plugin_Table_Rows_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Activity logs (issue #698) on the plugin-data pair, free tier, read only,
 * so it adds no top-level tool and writes nothing: the ops answer "who
 * changed this and when" beside WP MCP's own audit log.
 *
 * Two plugins, both free on wordpress.org, each with tables of its own
 * (read in WP Activity Log 5.7.0 and Simple History 5.36.0):
 *  - WP Activity Log (WSAL_VERSION): {base_prefix}wsal_occurrences, one row
 *    per event (alert_id, created_on as a unix time with a fraction,
 *    client_ip, severity, object, event_type, user_agent, user_roles,
 *    username, user_id, session_id, post_status, post_type, post_id, and
 *    site_id on a network) and {base_prefix}wsal_metadata (occurrence_id,
 *    name, value). Its message is built from the alert definition and the
 *    metadata by WSAL\Controllers\Alert::get_message() when that class is
 *    loaded.
 *  - Simple History (SIMPLE_HISTORY_VERSION): {prefix}simple_history, one
 *    row per event (date in GMT, logger, level, message template with
 *    {key} placeholders, initiator) and {prefix}simple_history_contexts
 *    (history_id, key, value) with the values for the placeholders and the
 *    underscore keys for the user and the request.
 *
 * Ops: activitylog-list-activity (newest first; filters user_id, username,
 * object_id, event, since, until; page_size and offset) and
 * activitylog-get-activity. Activity logs hold personal data, so a list
 * leaves out the user agent and the session, shows the IP address with its
 * last part hidden and every email address in a message masked
 * (j***@example.com); get-activity returns one event in full. Both
 * plugins can be active at once, so the source is asked for then.
 *
 * Presence is filterable (wpmcp_wp_activity_log_active,
 * wpmcp_simple_history_active). Without either plugin the ops stay
 * documented in list-operations (dependency_met:false) and answer
 * plugin_missing or plugin_inactive; with a plugin but no tables yet they
 * answer activity_log_tables_missing.
 */
final class Activity_Log_Pack
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads the activity plugins' own tables live; they have no cached read API.

    public const CAPABILITY = 'manage_options';

    public const DEFAULT_PAGE_SIZE = 20;

    public const MAX_PAGE_SIZE = 100;

    /** source => [ label, main files under the plugins directory ] */
    private const SOURCES = [
        'wp-activity-log' => [ 'WP Activity Log', [ 'wp-security-audit-log/wp-security-audit-log.php' ] ],
        'simple-history'  => [ 'Simple History', [ 'simple-history/index.php' ] ],
    ];

    /** Whether one of the plugins is loaded (filterable). */
    public static function source_active(string $source): bool
    {
        if ('wp-activity-log' === $source) {
            return (bool) apply_filters('wpmcp_wp_activity_log_active', defined('WSAL_VERSION'));
        }
        return (bool) apply_filters('wpmcp_simple_history_active', defined('SIMPLE_HISTORY_VERSION'));
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

    /** The tables of a source: key => full table name. @return array<string, string> */
    private static function tables(string $source): array
    {
        global $wpdb;
        if ('wp-activity-log' === $source) {
            return [ 'events' => $wpdb->base_prefix . 'wsal_occurrences', 'meta' => $wpdb->base_prefix . 'wsal_metadata' ];
        }
        return [ 'events' => $wpdb->prefix . 'simple_history', 'meta' => $wpdb->prefix . 'simple_history_contexts' ];
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
        foreach (self::SOURCES as [ , $files ]) {
            foreach ($files as $file) {
                $installed = $installed || (defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . $file));
            }
        }
        $url = admin_url('plugins.php');

        return [
            'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
            'message' => sprintf(
                'WP Activity Log and Simple History are %s on this site. Install and activate WP Activity Log or Simple History (%s), then try again.',
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
            foreach (self::tables($source) as $table) {
                if (! Plugin_Table_Rows_Snapshot::table_exists($table)) {
                    return [
                        'code'    => 'activity_log_tables_missing',
                        'message' => sprintf('%s\'s table %s does not exist on this site. Deactivate and reactivate it so it creates its tables, then try again.', self::SOURCES[ $source ][0], $table),
                        'data'    => [ 'plugins_url' => admin_url('plugins.php') ],
                    ];
                }
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
            'activitylog-list-activity' => $base + [
                'description'  => 'List WP Activity Log or Simple History events newest first: id, time (UTC), user, severity, event, object, message, IP. Filters: user_id, username, object_id (post id), event (WP Activity Log alert id, Simple History logger), since and until (dates), page_size (default 20, max 100), offset. source when both plugins are active. IPs and emails MASKED, activitylog-get-activity returns one in full. Personal data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'source'    => $source,
                        'user_id'   => [ 'type' => 'integer', 'minimum' => 1 ],
                        'username'  => [ 'type' => 'string', 'minLength' => 1 ],
                        'object_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'event'     => [ 'type' => 'string', 'minLength' => 1 ],
                        'since'     => [ 'type' => 'string' ],
                        'until'     => [ 'type' => 'string' ],
                        'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
                        'offset'    => [ 'type' => 'integer', 'minimum' => 0 ],
                    ],
                ],
                'validate'     => static fn (array $args): ?array => self::list_refusal($args),
                'handler'      => static fn (array $args): array => self::list_activity($args),
            ],
            'activitylog-get-activity'  => $base + [
                'description'  => 'One WP Activity Log or Simple History event in full: user, roles, severity, event, object, message, IP address, user agent and every metadata or context value. source when both plugins are active. Personal data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'source' => $source, 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'id' ],
                ],
                'validate'     => static fn (array $args): ?array => self::get_refusal($args),
                'handler'      => static fn (array $args): array => self::get_activity($args),
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
                return [ 'code' => 'invalid_source', 'message' => 'source is wp-activity-log or simple-history.', 'data' => [] ];
            }
            if (! self::source_active($asked)) {
                $url = admin_url('plugins.php');
                $set = false;
                foreach (self::SOURCES[ $asked ][1] as $file) {
                    $set = $set || (defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . $file));
                }
                return [
                    'code'    => $set ? 'plugin_inactive' : 'plugin_missing',
                    'message' => sprintf('%1$s is %2$s on this site. Install and activate %1$s (%3$s), then try again.', self::SOURCES[ $asked ][0], $set ? 'installed but not active' : 'not installed', $url),
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
            'message' => 'Both WP Activity Log and Simple History are active. Pass source: wp-activity-log or simple-history.',
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
            if (isset($args[ $key ]) && null === self::timestamp((string) $args[ $key ])) {
                return [ 'code' => 'invalid_date', 'message' => sprintf('%s is not a date WP MCP can read; use 2026-11-15 or 2026-11-15T09:30:00Z.', $key), 'data' => [ 'field' => $key ] ];
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
            return [ 'code' => 'activity_not_found', 'message' => sprintf('%s has no event %d.', self::SOURCES[ $source ][0], (int) $args['id']), 'data' => [ 'id' => (int) $args['id'] ] ];
        }
        return null;
    }

    /** A date as a unix time (UTC when it names no zone), or null. */
    private static function timestamp(string $value): ?int
    {
        $value = trim($value);
        if ('' === $value) {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            return null;
        }
        return $date->getTimestamp();
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /** @return array<string, mixed>|null the raw event row */
    private static function row(string $source, int $id): ?array
    {
        global $wpdb;
        $tables = self::tables($source);
        $row    = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $tables['events'], $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /**
     * The meta rows (name or key => value) of events, by event id.
     *
     * @param int[] $ids
     * @return array<int, array<string, string>>
     */
    private static function meta_rows(string $source, array $ids): array
    {
        global $wpdb;
        if ([] === $ids) {
            return [];
        }
        $tables = self::tables($source);
        $parent = 'wp-activity-log' === $source ? 'occurrence_id' : 'history_id';
        $name   = 'wp-activity-log' === $source ? 'name' : 'key';
        $in     = implode(',', array_map('intval', $ids));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a list of integers.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT %i AS parent, %i AS k, value FROM %i WHERE %i IN ({$in}) ORDER BY 1, 2", $parent, $name, $tables['meta'], $parent), ARRAY_A);
        $out  = [];
        foreach ($rows as $row) {
            $out[ (int) $row['parent'] ][ (string) $row['k'] ] = (string) $row['value'];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function list_activity(array $args): array
    {
        global $wpdb;
        $source = self::resolve_source($args);
        $source = is_string($source) ? $source : 'wp-activity-log';
        $tables = self::tables($source);
        $size   = max(1, min(self::MAX_PAGE_SIZE, (int) ($args['page_size'] ?? self::DEFAULT_PAGE_SIZE)));
        $offset = max(0, (int) ($args['offset'] ?? 0));
        $since  = isset($args['since']) ? self::timestamp((string) $args['since']) : null;
        $until  = isset($args['until']) ? self::timestamp((string) $args['until']) : null;
        $where  = [ '1=1' ];

        if ('wp-activity-log' === $source) {
            if (is_multisite()) {
                $where[] = $wpdb->prepare('e.site_id = %d', get_current_blog_id());
            }
            if (isset($args['user_id'])) {
                $where[] = $wpdb->prepare('e.user_id = %d', (int) $args['user_id']);
            }
            if (isset($args['username'])) {
                $where[] = $wpdb->prepare('e.username = %s', (string) $args['username']);
            }
            if (isset($args['object_id'])) {
                $where[] = $wpdb->prepare('e.post_id = %d', (int) $args['object_id']);
            }
            if (isset($args['event'])) {
                $where[] = $wpdb->prepare('e.alert_id = %d', (int) $args['event']);
            }
            if (null !== $since) {
                $where[] = $wpdb->prepare('e.created_on >= %f', (float) $since);
            }
            if (null !== $until) {
                $where[] = $wpdb->prepare('e.created_on <= %f', (float) $until);
            }
            $order = 'e.created_on DESC, e.id DESC';
        } else {
            foreach ([ 'user_id' => '_user_id', 'username' => '_user_login', 'object_id' => 'post_id' ] as $arg => $key) {
                if (isset($args[ $arg ])) {
                    $where[] = $wpdb->prepare('EXISTS (SELECT 1 FROM %i c WHERE c.history_id = e.id AND c.`key` = %s AND c.value = %s)', $tables['meta'], $key, (string) $args[ $arg ]);
                }
            }
            if (isset($args['event'])) {
                $where[] = $wpdb->prepare('e.logger = %s', (string) $args['event']);
            }
            if (null !== $since) {
                $where[] = $wpdb->prepare('e.date >= %s', gmdate('Y-m-d H:i:s', $since));
            }
            if (null !== $until) {
                $where[] = $wpdb->prepare('e.date <= %s', gmdate('Y-m-d H:i:s', $until));
            }
            $order = 'e.date DESC, e.id DESC';
        }
        $clause = implode(' AND ', $where);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $clause is built above with prepare().
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i e WHERE {$clause}", $tables['events']));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $clause and $order are built above.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT e.* FROM %i e WHERE {$clause} ORDER BY {$order} LIMIT %d OFFSET %d", $tables['events'], $size, $offset), ARRAY_A);
        $meta = self::meta_rows($source, array_map('intval', array_column($rows, 'id')));

        $events = [];
        foreach ($rows as $row) {
            $event = self::event($source, $row, $meta[ (int) $row['id'] ] ?? []);
            unset($event['user_agent'], $event['user_roles'], $event['details']);
            $event['ip']      = self::mask_ip((string) $event['ip']);
            $event['message'] = self::mask_text((string) $event['message']);
            $events[]         = $event;
        }

        return [
            'source'  => $source,
            'events'  => $events,
            'total'   => $total,
            'masked'  => true,
            'offset'  => $offset,
            'hasMore' => $offset + count($events) < $total,
        ];
    }

    /** @return array<string, mixed> */
    private static function get_activity(array $args): array
    {
        $source = self::resolve_source($args);
        $source = is_string($source) ? $source : 'wp-activity-log';
        $row    = self::row($source, (int) $args['id']) ?? [];
        $meta   = self::meta_rows($source, [ (int) $args['id'] ]);

        return [ 'source' => $source, 'event' => self::event($source, $row, $meta[ (int) $args['id'] ] ?? []) ];
    }

    /**
     * One event as the ops return it (unmasked; the list masks it).
     *
     * @param array<string, mixed>  $row
     * @param array<string, string> $meta
     * @return array<string, mixed>
     */
    private static function event(string $source, array $row, array $meta): array
    {
        if ('wp-activity-log' === $source) {
            return [
                'id'         => (int) $row['id'],
                'time'       => gmdate('Y-m-d\TH:i:s\Z', (int) floor((float) $row['created_on'])),
                'user_id'    => (int) $row['user_id'],
                'username'   => (string) $row['username'],
                'user_roles' => (string) $row['user_roles'],
                'severity'   => (string) $row['severity'],
                'event'      => (string) $row['alert_id'],
                'event_type' => (string) $row['event_type'],
                'object'     => [ 'kind' => (string) $row['object'], 'post_type' => (string) $row['post_type'], 'id' => (int) $row['post_id'] ],
                'message'    => self::wsal_message($row, $meta),
                'ip'         => (string) $row['client_ip'],
                'user_agent' => (string) $row['user_agent'],
                'details'    => array_map([ self::class, 'decode' ], $meta),
            ];
        }

        $message = (string) ($row['message'] ?? '');
        $replace = [];
        foreach ($meta as $key => $value) {
            $replace[ '{' . $key . '}' ] = $value;
        }
        return [
            'id'         => (int) $row['id'],
            'time'       => gmdate('Y-m-d\TH:i:s\Z', (int) strtotime((string) $row['date'] . ' UTC')),
            'user_id'    => (int) ($meta['_user_id'] ?? 0),
            'username'   => (string) ($meta['_user_login'] ?? ''),
            'user_roles' => '',
            'severity'   => (string) $row['level'],
            'event'      => (string) $row['logger'],
            'event_type' => (string) ($meta['_message_key'] ?? ''),
            'object'     => [ 'kind' => (string) ($row['initiator'] ?? ''), 'post_type' => (string) ($meta['post_type'] ?? ''), 'id' => (int) ($meta['post_id'] ?? 0) ],
            'message'    => strtr($message, $replace),
            'ip'         => (string) ($meta['_server_remote_addr'] ?? ''),
            'user_agent' => (string) ($meta['_server_http_user_agent'] ?? ''),
            'details'    => $meta,
        ];
    }

    /** A WP Activity Log metadata value (stored with maybe_serialize), arrays only, never objects. @return mixed */
    private static function decode(string $value)
    {
        if (! is_serialized($value)) {
            return $value;
        }
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged -- the plugin stores its metadata with maybe_serialize(); no class may be instantiated.
        $decoded = @unserialize($value, [ 'allowed_classes' => false ]);
        return false === $decoded && 'b:0;' !== $value ? $value : $decoded;
    }

    /**
     * WP Activity Log's own wording for an event when its classes are
     * loaded, otherwise the event code.
     *
     * @param array<string, mixed>  $row
     * @param array<string, string> $meta
     */
    private static function wsal_message(array $row, array $meta): string
    {
        $class = '\\WSAL\\Controllers\\Alert';
        if (class_exists($class) && method_exists($class, 'get_message')) {
            try {
                $text = $class::get_message($meta, null, (int) $row['alert_id'], (int) $row['id'], 'default');
                if (is_string($text) && '' !== trim($text)) {
                    return trim(wp_specialchars_decode(wp_strip_all_tags($text), ENT_QUOTES));
                }
            } catch (\Throwable $e) {
                unset($e);
            }
        }
        return sprintf('WP Activity Log event %d', (int) $row['alert_id']);
    }

    // ------------------------------------------------------------------
    // Masking
    // ------------------------------------------------------------------

    /** 203.0.113.7 reads 203.0.*.*, an IPv6 address keeps its first two groups. */
    public static function mask_ip(string $ip): string
    {
        $ip = trim($ip);
        if (1 === preg_match('/^(\d{1,3})\.(\d{1,3})\.\d{1,3}\.\d{1,3}$/', $ip, $m)) {
            return $m[1] . '.' . $m[2] . '.*.*';
        }
        if (false !== strpos($ip, ':') && false !== filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $groups = explode(':', $ip);
            return $groups[0] . ':' . ($groups[1] ?? '') . ':*';
        }
        return '' === $ip ? '' : '***';
    }

    /** Every email address and IP address inside a text masked. */
    public static function mask_text(string $text): string
    {
        $text = (string) preg_replace_callback(
            '/([A-Za-z0-9._%+\-])[A-Za-z0-9._%+\-]*@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})/',
            static fn (array $m): string => $m[1] . '***@' . $m[2],
            $text
        );
        $text = (string) preg_replace_callback(
            '/\b(\d{1,3})\.(\d{1,3})\.\d{1,3}\.\d{1,3}\b/',
            static fn (array $m): string => $m[1] . '.' . $m[2] . '.*.*',
            $text
        );
        return $text;
    }
}
