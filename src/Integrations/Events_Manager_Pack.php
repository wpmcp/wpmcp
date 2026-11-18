<?php

namespace WPMCP\Integrations;

use WPMCP\Privacy\Masking;
use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Content\Readable_Posts;
use WPMCP\Tools\Database\Database_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Events Manager (issue #678) on the plugin-data pair, free tier, so it adds
 * no top-level tools. Ops are prefixed eventsmanager- because the pair is
 * shared; the names after the prefix match the TEC pair's.
 *
 * Events Manager 7.5 keeps an event as an `event` post whose schedule is
 * mirrored twice: in `_event_*` post meta (_event_id, _event_start_date,
 * _event_start_time, _event_end_date, _event_end_time, _event_all_day,
 * _event_timezone, _location_id, _event_rsvp, ...) and in a row of its own
 * {prefix}em_events table (keyed by event_id, with the post_id), which its
 * listings and calendars query. Bookings are rows of {prefix}em_bookings
 * keyed by the event_id, the booker a user (person_id) or, for a guest, the
 * _registration|user_name and _registration|user_email rows of
 * {prefix}em_bookings_meta. Venues are rows of {prefix}em_locations.
 *
 * Reads: eventsmanager-list-events, -get-event (from the post and its
 * meta, filtered through Readable_Posts) and -list-bookings (names, emails
 * and comments masked, as every list is).
 * Writes go through Events Manager's own EM_Event::save(), which writes the
 * post, its meta and the em_events row together, and are off until a site
 * opts in with wpmcp_enable_events_manager_write:
 *  - create-event saves a new event as a draft unless status says
 *    otherwise, recorded for rollback (Post_Creation_Snapshot: the undo
 *    trashes it, and Events Manager's trash hook marks its row trashed);
 *  - update-event changes only the given fields under a post snapshot and
 *    a db_rows before-image of its em_events row, one session, so
 *    rollback-session restores both. Recurring events are refused, since a
 *    save regenerates their recurrences.
 * Writes take manage_options, the capability a db_rows restore needs, so
 * whoever made the change can undo it.
 *
 * Presence is filterable (wpmcp_events_manager_active, default: EM_VERSION
 * is defined).
 */
final class Events_Manager_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const POST_TYPE = 'event';

    public const OPT_IN_FILTER = 'wpmcp_enable_events_manager_write';

    public const MAX_PAGE_SIZE = 100;

    public const PLUGIN_FILES = [ 'events-manager/events-manager.php' ];

    private const STATUSES = [ 'publish', 'draft', 'pending', 'future', 'private' ];

    /** Events Manager's booking statuses (EM_Booking::$status_array). */
    private const BOOKING_STATUSES = [
        0  => 'pending',
        1  => 'approved',
        2  => 'rejected',
        3  => 'cancelled',
        4  => 'awaiting_online_payment',
        5  => 'awaiting_payment',
        6  => 'waitlist',
        7  => 'waitlist_approved',
        8  => 'waitlist_expired',
        10 => 'booking_intent',
    ];

    private const DATE = '^\d{4}-\d{2}-\d{2}$';

    private const TIME = '^\d{2}:\d{2}(:\d{2})?$';

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_events_manager_active', defined('EM_VERSION'));
    }

    /** @return true|array{code: string, message: string, data: array} */
    public static function presence()
    {
        if (self::active()) {
            return true;
        }
        $installed = false;
        foreach (self::PLUGIN_FILES as $file) {
            $installed = $installed || (defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . $file));
        }
        $url = admin_url('plugins.php');

        return [
            'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
            'message' => sprintf(
                'Events Manager is %s on this site. Install and activate Events Manager (%s), then try again.',
                $installed ? 'installed but not active' : 'not installed',
                $url
            ),
            'data'    => [ 'plugin' => 'events-manager', 'plugins_url' => $url ],
        ];
    }

    /** {prefix}em_<name>, or Events Manager's own constant for it when loaded (multisite global tables). */
    public static function table(string $name): string
    {
        global $wpdb;

        $constant = 'EM_' . strtoupper($name) . '_TABLE';
        return defined($constant) ? (string) constant($constant) : $wpdb->prefix . 'em_' . $name;
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id     = [ 'type' => 'integer', 'minimum' => 1 ];
        $page   = [
            'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
            'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
        ];
        $fields = [
            'title'       => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 500 ],
            'content'     => [ 'type' => 'string', 'maxLength' => 200000 ],
            'start_date'  => [ 'type' => 'string', 'pattern' => self::DATE ],
            'end_date'    => [ 'type' => 'string', 'pattern' => self::DATE ],
            'start_time'  => [ 'type' => 'string', 'pattern' => self::TIME ],
            'end_time'    => [ 'type' => 'string', 'pattern' => self::TIME ],
            'all_day'     => [ 'type' => 'boolean' ],
            'timezone'    => [ 'type' => 'string', 'maxLength' => 64 ],
            'location_id' => [ 'type' => 'integer', 'minimum' => 0 ],
            'status'      => [ 'type' => 'string', 'enum' => [ 'draft', 'pending', 'publish' ] ],
        ];
        $base   = [ 'requires' => static fn () => self::presence() ];
        $write  = $base + [
            'mode'               => 'write',
            'capability'         => 'manage_options',
            'self_snapshotting'  => true,
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_events_manager_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];

        return [
            'eventsmanager-list-events'   => $base + [
                'mode'         => 'read',
                'description'  => 'List Events Manager events by start: id, event_id, title, status, start, end, all_day, location_id. Filter by status, scope (future, past, all), search. page, per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status' => [ 'type' => 'string', 'enum' => self::STATUSES ],
                        'scope'  => [ 'type' => 'string', 'enum' => [ 'future', 'past', 'all' ] ],
                        'search' => [ 'type' => 'string', 'maxLength' => 200 ],
                    ] + $page,
                ],
                'handler'      => static fn (array $args): array => self::list_events($args),
            ],
            'eventsmanager-get-event'     => $base + [
                'mode'         => 'read',
                'objects'      => [ 'id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'read' ] ],
                'description'  => 'Read one Events Manager event: content, schedule, timezone, venue, bookings on/off, spaces, categories, URL',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ],
                'validate'     => static fn (array $args): ?array => self::event_refusal((int) $args['id']),
                'handler'      => static fn (array $args): array => [ 'event' => self::view_event(get_post((int) $args['id']), true) ],
            ],
            'eventsmanager-list-bookings' => $base + [
                'mode'         => 'read',
                'objects'      => [ 'event_id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'read' ] ],
                'description'  => 'List Events Manager bookings, newest first: id, event, spaces, status, price, date, booker name and email (masked). Filter by event_id (event post id), status. page, per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'event_id' => $id,
                        'status'   => [ 'type' => 'string', 'enum' => array_values(self::BOOKING_STATUSES) ],
                    ] + $page,
                ],
                'validate'     => static fn (array $args): ?array => self::bookings_refusal($args),
                'handler'      => static fn (array $args): array => self::list_bookings($args),
            ],
            'eventsmanager-create-event'  => $write + [
                'description'  => 'Create an Events Manager event through its own save: title and start_date required; end_date (default start_date), start_time and end_time (HH:MM, needed unless all_day), timezone, location_id (0 none), content, status (default draft). Undo: rollback-session trashes it',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => $fields,
                    'required'             => [ 'title', 'start_date' ],
                    'additionalProperties' => false,
                ],
                'validate'     => static fn (array $args): ?array => self::create_refusal($args),
                'handler'      => static fn (array $args, array $context): array => self::create($args, $context),
            ],
            'eventsmanager-update-event'  => $write + [
                'objects'      => [ 'id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'write' ] ],
                'description'  => 'Change an Events Manager event through its own save; only the fields given change. Recurring events are refused. Undo: rollback-session',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [ 'id' => $id ] + $fields,
                    'required'             => [ 'id' ],
                    'minProperties'        => 2,
                    'additionalProperties' => false,
                ],
                'validate'     => static fn (array $args): ?array => self::update_refusal($args),
                'handler'      => static fn (array $args, array $context): array => self::update($args, $context),
            ],
        ];
    }

    // ------------------------------------------------------------ refusals

    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    private static function is_event($post): bool
    {
        return $post instanceof \WP_Post && self::POST_TYPE === $post->post_type && 'trash' !== $post->post_status;
    }

    private static function event_refusal(int $id): ?array
    {
        return self::is_event(get_post($id)) ? null : self::refusal('event_not_found', sprintf('%d is not an Events Manager event.', $id), [ 'id' => $id ]);
    }

    private static function bookings_refusal(array $args): ?array
    {
        if (! current_user_can('manage_options') && ! current_user_can('manage_others_bookings')) {
            return self::refusal('operation_denied', 'Reading bookings needs the manage_others_bookings capability (Events Manager grants it to administrators and editors).', [ 'reason' => 'capability', 'capability' => 'manage_others_bookings' ]);
        }
        if (isset($args['event_id'])) {
            return self::event_refusal((int) $args['event_id']);
        }
        return null;
    }

    /**
     * EM_Event::save() quietly declines a user without Events Manager's own
     * capabilities (can_manage('edit_events', 'edit_others_events')), so
     * they are asked for here, by name.
     */
    private static function api_refusal(int $post_id = 0): ?array
    {
        if (! class_exists('EM_Event')) {
            return self::refusal('events_manager_unavailable', 'Events Manager\'s EM_Event class is not loaded, so events cannot be saved.');
        }
        $caps = [ 'edit_events' ];
        if ($post_id > 0 && (int) get_post_field('post_author', $post_id) !== get_current_user_id()) {
            $caps[] = 'edit_others_events';
        }
        foreach ($caps as $cap) {
            if (! current_user_can($cap)) {
                return self::refusal('operation_denied', sprintf('This needs the %s capability (Events Manager grants it to administrators).', $cap), [ 'reason' => 'capability', 'capability' => $cap ]);
            }
        }
        return null;
    }

    /**
     * The schedule a write would leave, checked the way Events Manager's
     * validate_meta() does (dates, start not after end), plus the time and
     * timezone shapes it expects.
     */
    private static function schedule_refusal(array $s): ?array
    {
        foreach ([ 'start_date', 'end_date' ] as $key) {
            if (! self::valid_date((string) $s[ $key ])) {
                return self::refusal('date_invalid', sprintf('%s must be a real date, YYYY-MM-DD.', $key), [ $key => $s[ $key ] ]);
            }
        }
        if (! $s['all_day'] && '' === (string) $s['start_time']) {
            return self::refusal('time_required', 'Give start_time (HH:MM), or all_day:true.');
        }
        foreach ([ 'start_time', 'end_time' ] as $key) {
            if ('' !== (string) $s[ $key ] && 1 !== preg_match('/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', (string) $s[ $key ])) {
                return self::refusal('time_invalid', sprintf('%s must be a time of day, HH:MM.', $key), [ $key => $s[ $key ] ]);
            }
        }
        if ($s['start_date'] . ' ' . $s['start_time'] > $s['end_date'] . ' ' . $s['end_time']) {
            return self::refusal('schedule_invalid', 'The event cannot end before it starts.', [ 'start' => $s['start_date'] . ' ' . $s['start_time'], 'end' => $s['end_date'] . ' ' . $s['end_time'] ]);
        }
        if ('' !== (string) $s['timezone'] && ! in_array($s['timezone'], timezone_identifiers_list(), true) && 1 !== preg_match('/^UTC([+-]\d{1,2}(\.\d+)?)?$/', (string) $s['timezone'])) {
            return self::refusal('timezone_invalid', 'timezone must be a PHP timezone such as Europe/London, or UTC+2.', [ 'timezone' => $s['timezone'] ]);
        }
        if (! empty($s['location_id']) && ! self::location((int) $s['location_id'])) {
            return self::refusal('location_not_found', sprintf('%d is not an Events Manager location.', (int) $s['location_id']), [ 'location_id' => (int) $s['location_id'] ]);
        }
        return null;
    }

    private static function valid_date(string $date): bool
    {
        return 1 === preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function create_refusal(array $args): ?array
    {
        return self::api_refusal() ?? self::schedule_refusal(self::schedule([], $args));
    }

    private static function update_refusal(array $args): ?array
    {
        $id      = (int) $args['id'];
        $refused = self::event_refusal($id) ?? self::api_refusal($id);
        if (null !== $refused) {
            return $refused;
        }
        $type = (string) get_post_meta($id, '_event_type', true);
        if (in_array($type, [ 'recurring', 'repeating' ], true) || '' !== (string) get_post_meta($id, '_recurrence_id', true)) {
            return self::refusal('recurring_event', 'This is a recurring event or one of its dates; change it in Events Manager, since a save there rebuilds the recurrences.', [ 'id' => $id ]);
        }
        if ([] === self::event_rows($id)) {
            return self::refusal('event_not_indexed', 'This event has no row in Events Manager\'s events table yet; save it once in wp-admin first.', [ 'id' => $id ]);
        }
        return self::schedule_refusal(self::schedule(self::meta_schedule($id), $args));
    }

    // ------------------------------------------------------------ reads

    /** @return array<string, mixed> The event's schedule as Events Manager keeps it in post meta. */
    private static function meta_schedule(int $id): array
    {
        $m = static fn (string $key): string => (string) get_post_meta($id, '_' . $key, true);
        return [
            'start_date'  => $m('event_start_date'),
            'start_time'  => $m('event_start_time'),
            'end_date'    => $m('event_end_date'),
            'end_time'    => $m('event_end_time'),
            'all_day'     => '' !== $m('event_all_day') && '0' !== $m('event_all_day'),
            'timezone'    => $m('event_timezone'),
            'location_id' => (int) $m('location_id'),
        ];
    }

    /**
     * The schedule a write leaves: $current with the given args over it,
     * times as HH:MM:SS, the end date defaulting to the start date, and an
     * all-day event running 00:00:00 to 23:59:59 the way Events Manager
     * stores it.
     *
     * @return array<string, mixed>
     */
    private static function schedule(array $current, array $args): array
    {
        $s = $current + [ 'start_date' => '', 'start_time' => '', 'end_date' => '', 'end_time' => '', 'all_day' => false, 'timezone' => '', 'location_id' => 0 ];
        foreach ([ 'start_date', 'end_date', 'start_time', 'end_time', 'timezone' ] as $key) {
            if (array_key_exists($key, $args)) {
                $s[ $key ] = trim((string) $args[ $key ]);
            }
        }
        if (array_key_exists('all_day', $args)) {
            $s['all_day'] = (bool) $args['all_day'];
        }
        if (array_key_exists('location_id', $args)) {
            $s['location_id'] = (int) $args['location_id'];
        }
        if ('' === $s['end_date'] || (array_key_exists('start_date', $args) && ! array_key_exists('end_date', $args) && $s['end_date'] < $s['start_date'])) {
            $s['end_date'] = $s['start_date'];
        }
        foreach ([ 'start_time', 'end_time' ] as $key) {
            if (1 === preg_match('/^\d{2}:\d{2}$/', (string) $s[ $key ])) {
                $s[ $key ] .= ':00';
            }
        }
        if ($s['all_day']) {
            $s['start_time'] = '00:00:00';
            $s['end_time']   = '23:59:59';
        } elseif ('' === $s['end_time'] || (! array_key_exists('end_time', $args) && $s['start_date'] === $s['end_date'] && $s['end_time'] < $s['start_time'])) {
            $s['end_time'] = $s['start_time'];
        }
        return $s;
    }

    /** @return array<int, array<string, string|null>> The event's em_events rows (one, normally). */
    private static function event_rows(int $post_id): array
    {
        $table = Database_Guard::valid_table(self::table('events'));
        return is_wp_error($table) ? [] : Database_Guard::before_image($table, [ 'post_id' => $post_id ]);
    }

    /** @return array<string, mixed>|null A venue row of em_locations. */
    private static function location(int $location_id): ?array
    {
        global $wpdb;

        $table = Database_Guard::valid_table(self::table('locations'));
        if ($location_id < 1 || is_wp_error($table)) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Events Manager's own venue table has no WP API.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE location_id = %d', $table, $location_id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed> */
    private static function view_event(\WP_Post $post, bool $full): array
    {
        $id  = (int) $post->ID;
        $s   = self::meta_schedule($id);
        $out = [
            'id'          => $id,
            'event_id'    => (int) get_post_meta($id, '_event_id', true),
            'title'       => (string) $post->post_title,
            'status'      => (string) $post->post_status,
            'start'       => trim($s['start_date'] . ' ' . $s['start_time']),
            'end'         => trim($s['end_date'] . ' ' . $s['end_time']),
            'all_day'     => $s['all_day'],
            'location_id' => $s['location_id'],
        ];
        if (! $full) {
            return $out;
        }
        $venue = self::location($s['location_id']);
        $terms = taxonomy_exists('event-categories') ? wp_get_post_terms($id, 'event-categories') : [];

        return $out + [
            'timezone'   => $s['timezone'],
            'content'    => (string) $post->post_content,
            'excerpt'    => (string) $post->post_excerpt,
            'location'   => null === $venue ? null : [
                'name'     => (string) ($venue['location_name'] ?? ''),
                'address'  => (string) ($venue['location_address'] ?? ''),
                'town'     => (string) ($venue['location_town'] ?? ''),
                'postcode' => (string) ($venue['location_postcode'] ?? ''),
                'country'  => (string) ($venue['location_country'] ?? ''),
            ],
            'bookings'   => '1' === (string) get_post_meta($id, '_event_rsvp', true),
            'spaces'     => (int) get_post_meta($id, '_event_spaces', true),
            'categories' => is_array($terms) ? array_map(static fn (\WP_Term $t): array => [ 'id' => (int) $t->term_id, 'name' => (string) $t->name ], $terms) : [],
            'url'        => (string) get_permalink($id),
        ];
    }

    /** @return array<string, mixed> */
    private static function list_events(array $args): array
    {
        $query = [
            'post_type'      => self::POST_TYPE,
            'post_status'    => isset($args['status']) ? [ (string) $args['status'] ] : self::STATUSES,
            'posts_per_page' => (int) ($args['per_page'] ?? 20),
            'paged'          => (int) ($args['page'] ?? 1),
            'meta_key'       => '_event_start_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- events are listed by their start, which Events Manager keeps in meta.
            'orderby'        => [ 'meta_value' => 'ASC', 'ID' => 'ASC' ], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- ordering by the start date meta named above.
        ];
        $scope = (string) ($args['scope'] ?? 'all');
        if ('all' !== $scope) {
            $query['meta_query'] = [ [ 'key' => '_event_end_date', 'value' => current_time('Y-m-d'), 'compare' => 'future' === $scope ? '>=' : '<', 'type' => 'DATE' ] ]; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the scope is the event's end date, kept in meta.
        }
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $query['s'] = (string) $args['search'];
        }
        $q = Readable_Posts::query($query);
        return [ 'events' => array_map(static fn (\WP_Post $p): array => self::view_event($p, false), $q->posts), 'total' => (int) $q->found_posts ];
    }

    /** @return array<string, mixed> */
    private static function list_bookings(array $args): array
    {
        global $wpdb;

        $bookings = Database_Guard::valid_table(self::table('bookings'));
        $events   = Database_Guard::valid_table(self::table('events'));
        if (is_wp_error($bookings) || is_wp_error($events)) {
            return [ 'bookings' => [], 'total' => 0, 'masked' => true ];
        }
        $where  = [ '1=1' ];
        $params = [];
        if (isset($args['event_id'])) {
            $where[]  = 'e.post_id = %d';
            $params[] = (int) $args['event_id'];
        }
        if (isset($args['status'])) {
            $where[]  = 'b.booking_status = %d';
            $params[] = (int) array_search((string) $args['status'], self::BOOKING_STATUSES, true);
        }
        $per_page = (int) ($args['per_page'] ?? 20);
        $offset   = ((int) ($args['page'] ?? 1) - 1) * $per_page;
        $sql      = 'FROM %i b LEFT JOIN %i e ON e.event_id = b.event_id WHERE ' . implode(' AND ', $where);

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Events Manager's own tables have no WP API; $sql holds only %i and %d placeholders.
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) ' . $sql, $bookings, $events, ...$params));
        $rows  = (array) $wpdb->get_results($wpdb->prepare('SELECT b.*, e.post_id AS event_post_id, e.event_name ' . $sql . ' ORDER BY b.booking_id DESC LIMIT %d OFFSET %d', $bookings, $events, ...array_merge($params, [ $per_page, $offset ])), ARRAY_A);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        $out = [];
        foreach ($rows as $row) {
            if (! empty($row['event_post_id']) && ! current_user_can('read_post', (int) $row['event_post_id'])) {
                continue;
            }
            [$name, $email] = self::booker($row);
            $out[]          = Masking::keys([
                'id'       => (int) $row['booking_id'],
                'event'    => [ 'id' => (int) $row['event_post_id'], 'event_id' => (int) $row['event_id'], 'title' => (string) $row['event_name'] ],
                'spaces'   => (int) $row['booking_spaces'],
                'status'   => self::BOOKING_STATUSES[ (int) $row['booking_status'] ] ?? (string) $row['booking_status'],
                'price'    => (string) $row['booking_price'],
                'date'     => (string) $row['booking_date'],
                'user_id'  => (int) $row['person_id'],
                'name'     => $name,
                'email'    => $email,
                'comment'  => (string) ($row['booking_comment'] ?? ''),
            ], [ 'name', 'email', 'comment' ]);
        }
        return [ 'bookings' => $out, 'total' => $total, 'masked' => true ];
    }

    /**
     * The booker's name and email: the registered user, overlaid by the
     * guest registration rows Events Manager keeps in em_bookings_meta.
     *
     * @return array{0: string, 1: string}
     */
    private static function booker(array $row): array
    {
        global $wpdb;

        $name  = '';
        $email = '';
        $user  = (int) $row['person_id'] > 0 ? get_userdata((int) $row['person_id']) : false;
        if ($user instanceof \WP_User) {
            $name  = (string) $user->display_name;
            $email = (string) $user->user_email;
        }
        $meta = Database_Guard::valid_table(self::table('bookings_meta'));
        if (! is_wp_error($meta)) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the booking meta table of Events Manager has no WP API.
            $reg = (array) $wpdb->get_results($wpdb->prepare('SELECT meta_key, meta_value FROM %i WHERE booking_id = %d AND meta_key IN (%s, %s)', $meta, (int) $row['booking_id'], '_registration|user_name', '_registration|user_email'), ARRAY_A);
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            foreach ($reg as $r) {
                if ('' === (string) $r['meta_value']) {
                    continue;
                }
                if ('_registration|user_name' === $r['meta_key']) {
                    $name = (string) $r['meta_value'];
                } else {
                    $email = (string) $r['meta_value'];
                }
            }
        }
        return [ $name, $email ];
    }

    // ------------------------------------------------------------ writes

    /** Put the given fields and the schedule on an EM_Event, the properties EM_Event::save() reads. */
    private static function apply(object $event, array $args, array $s): void
    {
        if (array_key_exists('title', $args)) {
            $event->event_name = (string) $args['title'];
        }
        if (array_key_exists('content', $args)) {
            $event->post_content = (string) $args['content'];
        }
        $event->event_start_date = $s['start_date'];
        $event->event_end_date   = $s['end_date'];
        $event->event_start_time = $s['start_time'];
        $event->event_end_time   = $s['end_time'];
        $event->event_all_day    = $s['all_day'] ? 1 : 0;
        if ('' !== (string) $s['timezone']) {
            // EM_Event::set_timezone() also drops the cached start and end,
            // so save() works out the UTC times in the new zone.
            $event->set_timezone((string) $s['timezone']);
        }
        // 0, not null: with no location Events Manager would try to save a new, empty one.
        $event->location_id = (int) $s['location_id'];
    }

    /** Run EM_Event::save() and turn its refusal into an error naming Events Manager's own messages. */
    private static function save(object $event): void
    {
        $errors = [];
        $ok     = $event->validate() && $event->save();
        if (! $ok) {
            array_walk_recursive($event->errors, static function ($e) use (&$errors): void {
                $errors[] = wp_strip_all_tags((string) $e);
            });
            throw new Operation_Refused('save_failed', 'Events Manager did not save the event' . ([] === $errors ? '.' : ': ' . implode(' ', $errors)), [ 'errors' => $errors ]);
        }
    }

    /** @return array<string, mixed> */
    private static function create(array $args, array $context): array
    {
        $event = new \EM_Event();
        self::apply($event, $args, self::schedule([], $args));
        $event->force_status = (string) ($args['status'] ?? 'draft');
        self::save($event);

        $id = (int) $event->post_id;
        $op = Post_Creation_Snapshot::record((string) $context['tool_name'], [ $id ], [ 'operation' => $context['operation'], 'args' => $args ], (string) $context['session_id']);
        clean_post_cache($id);

        return [ 'result' => [ 'event' => self::view_event(get_post($id), true) ], 'operation_ids' => [ $op ] ];
    }

    /**
     * The event's post (fields, meta and terms) under a post snapshot, and
     * its em_events row under a db_rows before-image taken inside it, so
     * rollback-session restores the row first and the post after it.
     *
     * @return array<string, mixed>
     */
    private static function update(array $args, array $context): array
    {
        $id    = (int) $args['id'];
        $table = (string) Database_Guard::valid_table(self::table('events'));
        $rows  = self::event_rows($id);
        $base  = [
            'session_id' => (string) $context['session_id'],
            'tool_name'  => (string) $context['tool_name'],
            'args'       => [ 'operation' => (string) $context['operation'], 'args' => $args ],
        ];
        $ids   = [];

        $post_op = Safe_Mutation::run([ 'object_type' => 'post', 'object_id' => $id ] + $base, static function () use ($id, $args, $table, $rows, $base, &$ids): string {
            $row_op = Safe_Mutation::run(
                [
                    'object_type'         => 'db_rows',
                    'object_id'           => $table,
                    'extra_snapshot_data' => [
                        'table'       => $table,
                        'operation'   => 'update',
                        'primary_key' => [ 'event_id' ],
                        'where'       => [ 'post_id' => $id ],
                        'set'         => [],
                        'rows'        => $rows,
                    ],
                ] + $base,
                static function () use ($id, $args): bool {
                    $event = new \EM_Event($id, 'post_id');
                    self::apply($event, $args, self::schedule(self::meta_schedule($id), $args));
                    $event->force_status = (string) ($args['status'] ?? get_post_status($id));
                    self::save($event);
                    return true;
                }
            );
            $ids[] = (string) $row_op['operation_id'];
            self::record_row_change((string) $row_op['operation_id'], $id);
            return (string) $row_op['operation_id'];
        });
        array_unshift($ids, (string) $post_op['operation_id']);
        clean_post_cache($id);

        return [ 'result' => [ 'event' => self::view_event(get_post($id), true) ], 'operation_ids' => $ids ];
    }

    /**
     * Note what the save left in the em_events row as the db_rows 'set', so
     * a rollback that finds the row as the write left it restores it
     * without a conflict warning, and warns only when something changed it
     * since.
     */
    private static function record_row_change(string $operation_id, int $post_id): void
    {
        $row      = Snapshot_Store::get_by_operation($operation_id);
        $snapshot = is_array($row) ? (array) ($row['snapshot'] ?? []) : [];
        $after    = self::event_rows($post_id)[0] ?? null;
        if ([] === $snapshot || null === $after) {
            return;
        }
        unset($after['event_id']);
        $snapshot['data']['set'] = $after;
        Snapshot_Store::update_snapshot($operation_id, $snapshot);
    }
}
