<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Safety\Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Modern Events Calendar writes and lookups on the mec pair (issue #677).
 *
 * MEC keeps an event as a mec-events post with its schedule in mec_* postmeta
 * (mec_start_date, mec_start_time_hour/_minutes/_ampm, mec_start_day_seconds,
 * mec_date, ...), and its locations, organizers and categories as terms of
 * mec_location, mec_organizer and mec_category, with a location's address
 * and an organizer's phone, email and URL in term meta (verified against
 * MEC's source: MEC_main::save_event(), save_location(), save_organizer() and
 * the event editor's save). Its calendar views read two tables of its own,
 * mec_events (one row per event) and mec_dates (one row per occurrence).
 *
 * These writes cover one-time events: a create writes the meta and both
 * table rows the way MEC's editor does for an event that does not repeat.
 * Changing the schedule of a repeating event is refused (it belongs in MEC's
 * editor, which rebuilds every occurrence); its other fields can change.
 * The tables are not snapshotted: they are derived from the meta, so after a
 * rollback restores an event post, after_post_restored() rebuilds them from
 * the restored meta, the way the write built them.
 *
 * Cancelling sets MEC's event status (mec_event_status = EventCancelled plus
 * an optional reason). The event stays published and MEC labels it canceled.
 *
 * Reads: list-locations, list-organizers (phone and email masked),
 * list-categories. Writes, off until wpmcp_enable_mec_write: create-event (a
 * draft unless status says otherwise; undo trashes it), update-event and
 * cancel-event (under a post snapshot), create-location and
 * create-organizer (under a term snapshot: undo deletes the new term).
 */
final class Modern_Events_Calendar_Writes
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const EVENT     = 'mec-events';
    public const LOCATION  = 'mec_location';
    public const ORGANIZER = 'mec_organizer';
    public const CATEGORY  = 'mec_category';
    public const OPT_IN    = 'wpmcp_enable_mec_write';

    private const PLUGIN_FILES = [ 'modern-events-calendar-lite/modern-events-calendar-lite.php', 'modern-events-calendar/mec.php' ];

    private const SCHEDULE_ARGS = [ 'start_date', 'start_time', 'end_date', 'end_time', 'all_day' ];

    /** @return true|array{code: string, message: string, data: array<string, mixed>} */
    public static function presence()
    {
        return Event_Calendar_Writes::presence(post_type_exists(self::EVENT), self::PLUGIN_FILES, 'Modern Events Calendar', 'modern-events-calendar-lite');
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id      = [ 'type' => 'integer', 'minimum' => 1 ];
        $text    = [ 'type' => 'string', 'maxLength' => 500 ];
        $date    = [ 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$', 'description' => 'YYYY-MM-DD' ];
        $time    = [ 'type' => 'string', 'pattern' => '^\d{2}:\d{2}$', 'description' => 'HH:MM, 24-hour; ignored with all_day' ];
        $fields  = [
            'title'        => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 500 ],
            'content'      => [ 'type' => 'string', 'maxLength' => 200000 ],
            'excerpt'      => [ 'type' => 'string', 'maxLength' => 5000 ],
            'status'       => [ 'type' => 'string', 'enum' => Event_Calendar_Writes::STATUSES ],
            'start_date'   => $date,
            'start_time'   => $time,
            'end_date'     => $date,
            'end_time'     => $time,
            'all_day'      => [ 'type' => 'boolean' ],
            'location_id'  => [ 'type' => 'integer', 'minimum' => 0, 'description' => '0 removes the location' ],
            'organizer_id' => [ 'type' => 'integer', 'minimum' => 0, 'description' => '0 removes the organizer' ],
            'category_ids' => [ 'type' => 'array', 'items' => $id, 'maxItems' => 50, 'uniqueItems' => true ],
            'cost'         => [ 'type' => 'string', 'maxLength' => 100 ],
            'url'          => [ 'type' => 'string', 'maxLength' => 2000, 'description' => 'The event\'s read more link' ],
        ];
        $objects = [
            'location_id'  => [ 'type' => 'term', 'access' => 'read' ],
            'organizer_id' => [ 'type' => 'term', 'access' => 'read' ],
            'category_ids' => [ 'type' => 'term', 'access' => 'read' ],
        ];
        $base    = [ 'requires' => static fn () => self::presence() ];
        $write   = $base + [
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_mec_write', false),
            'opt_in_filter'      => 'wpmcp_enable_mec_write',
        ];
        $snap    = static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['event_id'] ];
        $terms   = static fn (string $taxonomy): array => [
            'capability' => self::term_cap($taxonomy),
            'snapshot'   => static fn (array $args): array => [ 'object_type' => 'term', 'object_id' => Snapshot::term_key($taxonomy, sanitize_title((string) $args['name'])) ],
        ];

        return [
            'list-locations'  => $base + [
                'mode'         => 'read',
                'description'  => 'List Modern Events Calendar locations (id, name, slug, event count, address, latitude, longitude, URL); paged, optional search',
                'input_schema' => Event_Calendar_Writes::list_schema(false, 200),
                'handler'      => static fn (array $args): array => Event_Calendar_Writes::list_terms(self::LOCATION, 'locations', $args, [ 'address' => 'address', 'latitude' => 'latitude', 'longitude' => 'longitude', 'url' => 'url' ]),
            ],
            'list-organizers' => $base + [
                'mode'         => 'read',
                'description'  => 'List Modern Events Calendar organizers (id, name, slug, event count, URL; phone and email masked); paged, optional search',
                'input_schema' => Event_Calendar_Writes::list_schema(false, 200),
                'handler'      => static fn (array $args): array => Event_Calendar_Writes::list_terms(self::ORGANIZER, 'organizers', $args, [ 'tel' => 'phone', 'email' => 'email', 'url' => 'url' ], [ 'phone', 'email' ]),
            ],
            'list-categories' => $base + [
                'mode'         => 'read',
                'description'  => 'List Modern Events Calendar categories (id, name, slug, parent, event count); paged, optional search',
                'input_schema' => Event_Calendar_Writes::list_schema(false, 200),
                'handler'      => static fn (array $args): array => Event_Calendar_Writes::list_terms(self::CATEGORY, 'categories', $args),
            ],
            'create-event'    => $write + [
                'capability'        => Event_Calendar_Writes::type_cap(self::EVENT, 'create_posts'),
                'objects'           => $objects,
                'self_snapshotting' => true,
                'description'       => 'Create a one-time Modern Events Calendar event (dates, times or all-day, location, organizer, categories, cost, read more URL). Saved as a draft unless status is given. Recorded as a creation: rollback-operation trashes it',
                'input_schema'      => [ 'type' => 'object', 'properties' => $fields, 'required' => [ 'title', 'start_date', 'end_date' ] ],
                'validate'          => static fn (array $args): ?array => self::check_event($args, null),
                'handler'           => static fn (array $args, array $context): array => self::create_event($args, $context),
            ],
            'update-event'    => $write + [
                'capability'   => Event_Calendar_Writes::type_cap(self::EVENT, 'edit_posts'),
                'objects'      => [ 'event_id' => [ 'type' => 'post', 'own_type' => true ] ] + $objects,
                'description'  => 'Change a Modern Events Calendar event: any of title, content, excerpt, status, dates and times (one-time events only), location, organizer, categories, cost, URL; omitted fields are kept. Snapshotted and reversible',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'event_id' => $id ] + $fields, 'required' => [ 'event_id' ], 'minProperties' => 2 ],
                'validate'     => static fn (array $args): ?array => self::check_event($args, (int) $args['event_id']),
                'snapshot'     => $snap,
                'handler'      => static fn (array $args): array => self::update_event($args),
            ],
            'cancel-event'    => $write + [
                'capability'   => Event_Calendar_Writes::type_cap(self::EVENT, 'edit_posts'),
                'objects'      => [ 'event_id' => [ 'type' => 'post', 'own_type' => true ] ],
                'description'  => 'Mark a Modern Events Calendar event canceled (MEC event status) with an optional reason shown on the event; the event stays listed. Snapshotted and reversible',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'event_id' => $id, 'reason' => $text ], 'required' => [ 'event_id' ] ],
                'validate'     => static fn (array $args): ?array => self::event_refusal((int) $args['event_id']),
                'snapshot'     => $snap,
                'handler'      => static fn (array $args): array => self::cancel_event($args),
            ],
            'create-location' => $write + $terms(self::LOCATION) + [
                'description'  => 'Create a Modern Events Calendar location (name, address, latitude, longitude, URL). Snapshotted: rollback-operation deletes it',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'name'      => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ],
                        'address'   => $text,
                        'latitude'  => [ 'type' => 'number', 'minimum' => -90, 'maximum' => 90 ],
                        'longitude' => [ 'type' => 'number', 'minimum' => -180, 'maximum' => 180 ],
                        'url'       => $fields['url'],
                    ],
                    'required'   => [ 'name' ],
                ],
                'validate'     => static fn (array $args): ?array => self::check_term($args, self::LOCATION, 'location'),
                'handler'      => static fn (array $args): array => self::create_term($args, self::LOCATION, 'location', [
                    'address'   => [ 'address', sanitize_text_field((string) ($args['address'] ?? '')) ],
                    'latitude'  => [ 'latitude', isset($args['latitude']) ? (string) (float) $args['latitude'] : '' ],
                    'longitude' => [ 'longitude', isset($args['longitude']) ? (string) (float) $args['longitude'] : '' ],
                    'url'       => [ 'url', (string) Event_Calendar_Writes::url((string) ($args['url'] ?? '')) ],
                ]),
            ],
            'create-organizer' => $write + $terms(self::ORGANIZER) + [
                'description'  => 'Create a Modern Events Calendar organizer (name, phone, email, URL). Snapshotted: rollback-operation deletes it',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'name'  => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ],
                        'phone' => [ 'type' => 'string', 'maxLength' => 60 ],
                        'email' => [ 'type' => 'string', 'maxLength' => 200 ],
                        'url'   => $fields['url'],
                    ],
                    'required'   => [ 'name' ],
                ],
                'validate'     => static fn (array $args): ?array => self::check_term($args, self::ORGANIZER, 'organizer'),
                'handler'      => static fn (array $args): array => self::create_term($args, self::ORGANIZER, 'organizer', [
                    'phone' => [ 'tel', sanitize_text_field((string) ($args['phone'] ?? '')) ],
                    'email' => [ 'email', sanitize_email((string) ($args['email'] ?? '')) ],
                    'url'   => [ 'url', (string) Event_Calendar_Writes::url((string) ($args['url'] ?? '')) ],
                ]),
            ],
        ];
    }

    /** Extra get-event fields: categories and the MEC event status. */
    public static function details(int $id): array
    {
        return [
            'category_ids'  => array_values(array_map('intval', (array) wp_get_object_terms($id, self::CATEGORY, [ 'fields' => 'ids' ]))),
            'repeating'     => '1' === (string) get_post_meta($id, 'mec_repeat_status', true),
            'event_status'  => (string) get_post_meta($id, 'mec_event_status', true),
            'status_reason' => (string) get_post_meta($id, 'mec_cancelled_reason', true),
            'url'           => (string) get_post_meta($id, 'mec_read_more', true),
        ];
    }

    /**
     * After a rollback put an event post back (wpmcp_rollback_post_restored),
     * rebuild its mec_events and mec_dates rows from the restored meta, as
     * the write built them. Repeating events are left alone: no write here
     * changes their schedule.
     */
    public static function after_post_restored($post_id): void
    {
        $post_id = (int) $post_id;
        if (self::EVENT === get_post_type($post_id)) {
            self::sync_tables($post_id);
        }
    }

    private static function term_cap(string $taxonomy): string
    {
        $tax = get_taxonomy($taxonomy);
        return $tax && isset($tax->cap->manage_terms) ? (string) $tax->cap->manage_terms : 'manage_categories';
    }

    private static function event_refusal(int $id): ?array
    {
        return null === Event_Calendar_Writes::live_post($id, self::EVENT)
            ? Event_Calendar_Writes::refusal('event_not_found', sprintf('%d is not a Modern Events Calendar event.', $id), [ 'id' => $id ])
            : null;
    }

    private static function check_event(array $args, ?int $id): ?array
    {
        if (null !== $id) {
            $missing = self::event_refusal($id);
            if (null !== $missing) {
                return $missing;
            }
            if ([] !== array_intersect(self::SCHEDULE_ARGS, array_keys($args)) && '1' === (string) get_post_meta($id, 'mec_repeat_status', true)) {
                return Event_Calendar_Writes::refusal('recurring_event', 'This event repeats; change its dates in the Modern Events Calendar editor so every occurrence moves together.', [ 'id' => $id ]);
            }
        }
        $schedule = self::schedule_for($args, $id);
        if (isset($schedule['code'])) {
            return $schedule;
        }
        $location  = (int) ($args['location_id'] ?? 0);
        $organizer = (int) ($args['organizer_id'] ?? 0);
        return Event_Calendar_Writes::publish_refusal(self::EVENT, isset($args['status']) ? (string) $args['status'] : null)
            ?? Event_Calendar_Writes::terms_refusal($location > 0 ? [ $location ] : [], self::LOCATION, 'location_not_found', 'Modern Events Calendar location')
            ?? Event_Calendar_Writes::terms_refusal($organizer > 0 ? [ $organizer ] : [], self::ORGANIZER, 'organizer_not_found', 'Modern Events Calendar organizer')
            ?? Event_Calendar_Writes::terms_refusal((array) ($args['category_ids'] ?? []), self::CATEGORY, 'category_not_found', 'Modern Events Calendar category')
            ?? (isset($args['url']) && null === Event_Calendar_Writes::url((string) $args['url']) ? Event_Calendar_Writes::refusal('url_invalid', 'url must be an http or https address.') : null);
    }

    private static function check_term(array $args, string $taxonomy, string $label): ?array
    {
        $slug = sanitize_title((string) $args['name']);
        if ('' === $slug) {
            return Event_Calendar_Writes::refusal('name_invalid', 'name has no letters or digits to make a slug from.');
        }
        if (get_term_by('slug', $slug, $taxonomy) instanceof \WP_Term) {
            return Event_Calendar_Writes::refusal($label . '_exists', sprintf('A Modern Events Calendar %s named "%s" already exists.', $label, (string) $args['name']), [ 'slug' => $slug ]);
        }
        if (isset($args['url']) && null === Event_Calendar_Writes::url((string) $args['url'])) {
            return Event_Calendar_Writes::refusal('url_invalid', 'url must be an http or https address.');
        }
        if (isset($args['email']) && '' !== trim((string) $args['email']) && ! is_email(trim((string) $args['email']))) {
            return Event_Calendar_Writes::refusal('email_invalid', 'email is not a valid address.');
        }
        return null;
    }

    /**
     * The schedule meta the call sets (null when an update names no schedule
     * arg), or a refusal.
     *
     * @return array<string, mixed>|array{code: string, message: string, data: array}|null
     */
    private static function schedule_for(array $args, ?int $id): ?array
    {
        if (null !== $id && [] === array_intersect(self::SCHEDULE_ARGS, array_keys($args))) {
            return null;
        }
        $old     = static fn (string $key): string => null === $id ? '' : (string) get_post_meta($id, $key, true);
        $old_at  = static fn (string $side): string => null === $id ? '' : sprintf('%02d:%02d', self::hour24((int) $old("mec_{$side}_time_hour"), $old("mec_{$side}_time_ampm")), (int) $old("mec_{$side}_time_minutes"));
        $all_day = array_key_exists('all_day', $args) ? (bool) $args['all_day'] : '1' === $old('mec_allday');
        $start   = (string) ($args['start_date'] ?? $old('mec_start_date'));
        $end     = (string) ($args['end_date'] ?? $old('mec_end_date'));
        // MEC's editor stores 8:00 AM to 6:00 PM for an all-day event.
        $from    = $all_day ? '08:00' : (string) ($args['start_time'] ?? $old_at('start'));
        $to      = $all_day ? '18:00' : (string) ($args['end_time'] ?? $old_at('end'));

        $starts = self::moment($start, $from);
        $ends   = self::moment($end, $to);
        if (null === $starts || null === $ends) {
            return Event_Calendar_Writes::refusal('date_invalid', 'start_date and end_date must be YYYY-MM-DD and start_time and end_time HH:MM (24-hour); a new event that is not all_day needs both times.');
        }
        if ($ends < $starts) {
            return Event_Calendar_Writes::refusal('schedule_invalid', 'The event ends before it starts.', [ 'start' => $starts->format('Y-m-d H:i'), 'end' => $ends->format('Y-m-d H:i') ]);
        }

        $meta = [ 'mec_allday' => $all_day ? 1 : 0 ];
        $date = [ 'allday' => $all_day ? 1 : 0 ];
        foreach ([ 'start' => $starts, 'end' => $ends ] as $side => $moment) {
            $hour24 = (int) $moment->format('G');
            $hour   = 0 === $hour24 % 12 ? 12 : $hour24 % 12;
            $ampm   = $hour24 < 12 ? 'AM' : 'PM';
            $minute = $moment->format('i');
            $meta[ "mec_{$side}_date" ]         = $moment->format('Y-m-d');
            $meta[ "mec_{$side}_time_hour" ]    = (string) $hour;
            $meta[ "mec_{$side}_time_minutes" ] = $minute;
            $meta[ "mec_{$side}_time_ampm" ]    = $ampm;
            $meta[ "mec_{$side}_day_seconds" ]  = $hour24 * 3600 + (int) $minute * 60;
            $meta[ "mec_{$side}_datetime" ]     = sprintf('%s %02d:%s %s', $moment->format('Y-m-d'), $hour, $minute, $ampm);
            $date[ $side ]                      = [ 'date' => $moment->format('Y-m-d'), 'hour' => (string) $hour, 'minutes' => $minute, 'ampm' => $ampm ];
        }
        $existing          = null === $id ? [] : (array) get_post_meta($id, 'mec_date', true);
        $meta['mec_date']  = $date + $existing + [ 'repeat' => [], 'hide_time' => 0, 'hide_end_time' => 0, 'comment' => '' ];
        return $meta;
    }

    private static function hour24(int $hour, string $ampm): int
    {
        $hour = $hour % 12;
        return 'PM' === strtoupper($ampm) ? $hour + 12 : $hour;
    }

    private static function moment(string $date, string $time): ?\DateTimeImmutable
    {
        $text   = $date . ' ' . $time;
        $moment = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $text, new \DateTimeZone('UTC'));
        return false !== $moment && $moment->format('Y-m-d H:i') === $text ? $moment : null;
    }

    /** Write the event meta and terms the call names, then MEC's tables. */
    private static function write_event(int $id, array $args, bool $new): void
    {
        $meta = (array) self::schedule_for($args, $new ? null : $id);
        if ($new) {
            $meta += [ 'mec_repeat_status' => 0, 'mec_repeat_type' => '', 'mec_public' => 1, 'mec_hide_time' => 0, 'mec_hide_end_time' => 0, 'mec_location_id' => 0, 'mec_organizer_id' => 0 ];
        }
        foreach ([ 'location_id' => [ 'mec_location_id', self::LOCATION ], 'organizer_id' => [ 'mec_organizer_id', self::ORGANIZER ] ] as $arg => [ $key, $taxonomy ]) {
            if (array_key_exists($arg, $args)) {
                $term        = (int) $args[ $arg ];
                $meta[ $key ] = $term;
                $done        = wp_set_object_terms($id, $term > 0 ? [ $term ] : [], $taxonomy);
                if (is_wp_error($done)) {
                    throw new Operation_Refused('term_not_set', $done->get_error_message());
                }
            }
        }
        if (array_key_exists('cost', $args)) {
            $meta['mec_cost'] = sanitize_text_field((string) $args['cost']);
        }
        if (array_key_exists('url', $args)) {
            $meta['mec_read_more'] = (string) Event_Calendar_Writes::url((string) $args['url']);
        }
        foreach ($meta as $key => $value) {
            update_post_meta($id, $key, $value);
        }
        if (array_key_exists('category_ids', $args)) {
            $done = wp_set_object_terms($id, array_map('intval', (array) $args['category_ids']), self::CATEGORY);
            if (is_wp_error($done)) {
                throw new Operation_Refused('category_not_set', $done->get_error_message());
            }
        }
        self::sync_tables($id);
    }

    /**
     * Rebuild the mec_events row and the mec_dates row of a one-time event
     * from its meta, the way MEC's editor writes them for an event that does
     * not repeat. Does nothing while MEC's tables are absent.
     */
    private static function sync_tables(int $id): void
    {
        global $wpdb;
        if ('1' === (string) get_post_meta($id, 'mec_repeat_status', true)) {
            return;
        }
        $events = $wpdb->prefix . 'mec_events';
        $dates  = $wpdb->prefix . 'mec_dates';
        if (! Plugin_Table_Rows_Snapshot::table_exists($events) || ! Plugin_Table_Rows_Snapshot::table_exists($dates)) {
            return;
        }
        $start = (string) get_post_meta($id, 'mec_start_date', true);
        $end   = (string) get_post_meta($id, 'mec_end_date', true);
        if ('' === $start || '' === $end) {
            return;
        }
        $row = [
            'post_id'     => $id,
            'start'       => $start,
            'end'         => $end,
            'repeat'      => 0,
            'time_start'  => (int) get_post_meta($id, 'mec_start_day_seconds', true),
            'time_end'    => (int) get_post_meta($id, 'mec_end_day_seconds', true),
            'days'        => '',
            'not_in_days' => '',
        ];
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MEC's own tables, written as its editor writes them
        $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE post_id = %d', $events, $id));
        null === $existing ? $wpdb->insert($events, $row) : $wpdb->update($events, $row, [ 'id' => (int) $existing ]);

        // MEC times an all-day occurrence 12:01 AM to 11:59 PM, and reads
        // these as wall-clock times (strtotime under WordPress's UTC).
        $all_day = '1' === (string) get_post_meta($id, 'mec_allday', true);
        $utc     = new \DateTimeZone('UTC');
        $tstart  = (new \DateTimeImmutable($start . ' 00:00:00', $utc))->getTimestamp() + ($all_day ? 60 : (int) $row['time_start']);
        $tend    = (new \DateTimeImmutable($end . ' 00:00:00', $utc))->getTimestamp() + ($all_day ? 86340 : (int) $row['time_end']);
        $public  = get_post_meta($id, 'mec_public', true);
        $wpdb->delete($dates, [ 'post_id' => $id ]);
        $wpdb->insert($dates, [ 'post_id' => $id, 'dstart' => $start, 'dend' => $end, 'tstart' => $tstart, 'tend' => $tend, 'public' => '' === (string) $public ? 1 : (int) $public ]);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    }

    /** @return array{result: array<string, mixed>, operation_ids: string[]} */
    private static function create_event(array $args, array $context): array
    {
        [ $id, $operation_id ] = Event_Calendar_Writes::insert([
            'post_type'    => self::EVENT,
            'post_title'   => (string) $args['title'],
            'post_content' => (string) ($args['content'] ?? ''),
            'post_excerpt' => (string) ($args['excerpt'] ?? ''),
            'post_status'  => (string) ($args['status'] ?? 'draft'),
        ], static fn (int $id) => self::write_event($id, $args, true), $args, $context);

        return [ 'result' => [ 'event' => self::view($id) ], 'operation_ids' => [ $operation_id ] ];
    }

    /** @return array<string, mixed> */
    private static function update_event(array $args): array
    {
        $id = (int) $args['event_id'];
        Event_Calendar_Writes::update_fields($id, $args);
        self::write_event($id, $args, false);
        return [ 'event' => self::view($id) ];
    }

    /** @return array<string, mixed> */
    private static function cancel_event(array $args): array
    {
        $id     = (int) $args['event_id'];
        $reason = sanitize_text_field((string) ($args['reason'] ?? ''));
        update_post_meta($id, 'mec_event_status', 'EventCancelled');
        update_post_meta($id, 'mec_cancelled_reason', $reason);
        update_post_meta($id, 'mec_display_cancellation_reason_in_single_page', '' !== $reason);
        return [ 'event' => self::view($id) ];
    }

    /**
     * @param array<string, array{0: string, 1: string}> $meta output field => [term meta key, value]
     * @return array<string, mixed>
     */
    private static function create_term(array $args, string $taxonomy, string $key, array $meta): array
    {
        $created = wp_insert_term(wp_slash((string) $args['name']), $taxonomy, [ 'slug' => sanitize_title((string) $args['name']) ]);
        if (is_wp_error($created)) {
            throw new Operation_Refused($key . '_not_created', $created->get_error_message());
        }
        $term_id = (int) $created['term_id'];
        $out     = [ 'id' => $term_id, 'name' => (string) get_term($term_id)->name ];
        foreach ($meta as $field => [ $meta_key, $value ]) {
            update_term_meta($term_id, $meta_key, $value);
            $out[ $field ] = $value;
        }
        return [ $key => $out ];
    }

    /** @return array<string, mixed> */
    private static function view(int $id): array
    {
        $post = get_post($id);
        $meta = static fn (string $key): string => (string) get_post_meta($id, $key, true);
        return [
            'id'           => $id,
            'title'        => (string) $post->post_title,
            'status'       => (string) $post->post_status,
            'start_date'   => $meta('mec_start_date'),
            'start_time'   => sprintf('%02d:%02d', self::hour24((int) $meta('mec_start_time_hour'), $meta('mec_start_time_ampm')), (int) $meta('mec_start_time_minutes')),
            'end_date'     => $meta('mec_end_date'),
            'end_time'     => sprintf('%02d:%02d', self::hour24((int) $meta('mec_end_time_hour'), $meta('mec_end_time_ampm')), (int) $meta('mec_end_time_minutes')),
            'all_day'      => '1' === $meta('mec_allday'),
            'location_id'  => (int) $meta('mec_location_id'),
            'organizer_id' => (int) $meta('mec_organizer_id'),
            'cost'         => $meta('mec_cost'),
        ] + self::details($id);
    }
}
