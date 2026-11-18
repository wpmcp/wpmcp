<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The Events Calendar writes and lookups on the tec pair (issue #677).
 *
 * TEC keeps an event as a tribe_events post with its schedule in _Event*
 * postmeta, a venue as a tribe_venue post with _Venue* meta, an organizer
 * as a tribe_organizer post with _Organizer* meta and categories in the
 * tribe_events_cat taxonomy (verified against TEC's source: Tribe__Events__API,
 * Tribe__Events__Venue, Tribe__Events__Organizer). Writes set exactly those
 * keys, the way TEC's own API saves them: local start and end, their UTC
 * copies, the duration, the time zone and its abbreviation, so TEC's custom
 * tables (which watch those meta keys) rebuild the event's occurrence.
 *
 * Cancelling uses TEC's event status (_tribe_events_status = canceled plus
 * an optional reason): the event stays published and TEC shows it as
 * canceled, as its editor's Event status control does.
 *
 * Reads: list-venues, list-organizers (phone and email masked),
 * list-categories. Writes, off until wpmcp_enable_tec_write: create-event
 * (a draft unless status says otherwise; undo trashes it), update-event,
 * cancel-event (each under a post snapshot, so undo puts back the post, its
 * meta and its categories), create-venue and create-organizer (undo trashes).
 */
final class The_Events_Calendar_Writes
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const EVENT     = 'tribe_events';
    public const VENUE     = 'tribe_venue';
    public const ORGANIZER = 'tribe_organizer';
    public const CATEGORY  = 'tribe_events_cat';
    public const OPT_IN    = 'wpmcp_enable_tec_write';

    private const PLUGIN_FILES = [ 'the-events-calendar/the-events-calendar.php' ];

    /** Schedule args: a change to any of them recomputes the stored schedule. */
    private const SCHEDULE_ARGS = [ 'start_date', 'end_date', 'all_day', 'timezone' ];

    /** @return true|array{code: string, message: string, data: array<string, mixed>} */
    public static function presence()
    {
        return Event_Calendar_Writes::presence(post_type_exists(self::EVENT), self::PLUGIN_FILES, 'The Events Calendar', 'the-events-calendar');
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id      = [ 'type' => 'integer', 'minimum' => 1 ];
        $ids     = [ 'type' => 'array', 'items' => $id, 'maxItems' => 50, 'uniqueItems' => true ];
        $text    = [ 'type' => 'string', 'maxLength' => 500 ];
        $date    = [ 'type' => 'string', 'maxLength' => 19, 'description' => 'Local time YYYY-MM-DD HH:MM[:SS] in the event time zone; YYYY-MM-DD alone with all_day' ];
        $status  = [ 'type' => 'string', 'enum' => Event_Calendar_Writes::STATUSES ];
        $fields  = [
            'title'        => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 500 ],
            'content'      => [ 'type' => 'string', 'maxLength' => 200000 ],
            'excerpt'      => [ 'type' => 'string', 'maxLength' => 5000 ],
            'status'       => $status,
            'start_date'   => $date,
            'end_date'     => $date,
            'all_day'      => [ 'type' => 'boolean' ],
            'timezone'     => [ 'type' => 'string', 'maxLength' => 64, 'description' => 'A time zone name such as America/New_York, or UTC+5; defaults to the site time zone' ],
            'venue_id'     => [ 'type' => 'integer', 'minimum' => 0, 'description' => '0 removes the venue' ],
            'organizer_ids' => $ids,
            'category_ids' => $ids,
            'cost'         => [ 'type' => 'string', 'maxLength' => 100 ],
            'url'          => [ 'type' => 'string', 'maxLength' => 2000 ],
        ];
        $objects = [
            'venue_id'      => [ 'type' => 'post', 'access' => 'read', 'own_type' => true ],
            'organizer_ids' => [ 'type' => 'post', 'access' => 'read', 'own_type' => true ],
            'category_ids'  => [ 'type' => 'term', 'access' => 'read' ],
        ];
        $base    = [ 'requires' => static fn () => self::presence() ];
        $write   = $base + [
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_tec_write', false),
            'opt_in_filter'      => 'wpmcp_enable_tec_write',
        ];
        $create  = [ 'type' => 'object', 'properties' => $fields, 'required' => [ 'title', 'start_date', 'end_date' ] ];
        $update  = [ 'type' => 'object', 'properties' => [ 'event_id' => $id ] + $fields, 'required' => [ 'event_id' ], 'minProperties' => 2 ];
        $snap    = static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['event_id'] ];

        return [
            'list-venues'      => $base + [
                'mode'         => 'read',
                'description'  => 'List The Events Calendar venues (id, title, status, address, city, state or province, zip, country, phone, URL), by title; paged, optional search',
                'input_schema' => Event_Calendar_Writes::list_schema(true),
                'handler'      => static fn (array $args): array => Event_Calendar_Writes::list_posts(self::VENUE, 'venues', $args, [
                    '_VenueAddress' => 'address', '_VenueCity' => 'city', '_VenueStateProvince' => 'state_province', '_VenueZip' => 'zip',
                    '_VenueCountry' => 'country', '_VenuePhone' => 'phone', '_VenueURL' => 'url',
                ]),
            ],
            'list-organizers'  => $base + [
                'mode'         => 'read',
                'description'  => 'List The Events Calendar organizers (id, title, status, website; phone and email masked), by title; paged, optional search',
                'input_schema' => Event_Calendar_Writes::list_schema(true),
                'handler'      => static fn (array $args): array => Event_Calendar_Writes::list_posts(self::ORGANIZER, 'organizers', $args, [
                    '_OrganizerPhone' => 'phone', '_OrganizerEmail' => 'email', '_OrganizerWebsite' => 'website',
                ], [ 'phone', 'email' ]),
            ],
            'list-categories'  => $base + [
                'mode'         => 'read',
                'description'  => 'List The Events Calendar event categories (id, name, slug, parent, event count); paged, optional search',
                'input_schema' => Event_Calendar_Writes::list_schema(false, 200),
                'handler'      => static fn (array $args): array => Event_Calendar_Writes::list_terms(self::CATEGORY, 'categories', $args),
            ],
            'create-event'     => $write + [
                'capability'        => Event_Calendar_Writes::type_cap(self::EVENT, 'create_posts'),
                'objects'           => $objects,
                'self_snapshotting' => true,
                'description'       => 'Create a The Events Calendar event with its schedule, venue, organizers, categories, cost and URL. Saved as a draft unless status is given. Recorded as a creation: rollback-operation trashes it',
                'input_schema'      => $create,
                'validate'          => static fn (array $args): ?array => self::check_event($args, null),
                'handler'           => static fn (array $args, array $context): array => self::create_event($args, $context),
            ],
            'update-event'     => $write + [
                'capability'   => Event_Calendar_Writes::type_cap(self::EVENT, 'edit_posts'),
                'objects'      => [ 'event_id' => [ 'type' => 'post', 'own_type' => true ] ] + $objects,
                'description'  => 'Change a The Events Calendar event: any of title, content, excerpt, status, schedule (start/end, all-day, time zone), venue, organizers, categories, cost, URL; omitted fields are kept. Snapshotted and reversible',
                'input_schema' => $update,
                'validate'     => static fn (array $args): ?array => self::check_event($args, (int) $args['event_id']),
                'snapshot'     => $snap,
                'handler'      => static fn (array $args): array => self::update_event($args),
            ],
            'cancel-event'     => $write + [
                'capability'   => Event_Calendar_Writes::type_cap(self::EVENT, 'edit_posts'),
                'objects'      => [ 'event_id' => [ 'type' => 'post', 'own_type' => true ] ],
                'description'  => 'Mark a The Events Calendar event canceled (TEC event status) with an optional reason shown on the event; the event stays listed. Snapshotted and reversible',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'event_id' => $id, 'reason' => $text ], 'required' => [ 'event_id' ] ],
                'validate'     => static fn (array $args): ?array => self::event_refusal((int) $args['event_id']),
                'snapshot'     => $snap,
                'handler'      => static fn (array $args): array => self::cancel_event($args),
            ],
            'create-venue'     => $write + [
                'capability'        => Event_Calendar_Writes::type_cap(self::VENUE, 'create_posts'),
                'self_snapshotting' => true,
                'description'       => 'Create a The Events Calendar venue (published unless status is given). Recorded as a creation: rollback-operation trashes it',
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'title'    => $fields['title'],
                        'status'   => $status,
                        'address'  => $text,
                        'city'     => $text,
                        'state'    => $text,
                        'province' => $text,
                        'zip'      => [ 'type' => 'string', 'maxLength' => 40 ],
                        'country'  => $text,
                        'phone'    => [ 'type' => 'string', 'maxLength' => 60 ],
                        'url'      => $fields['url'],
                    ],
                    'required'   => [ 'title' ],
                ],
                'validate'          => static fn (array $args): ?array => self::check_linked($args, self::VENUE),
                'handler'           => static fn (array $args, array $context): array => self::create_venue($args, $context),
            ],
            'create-organizer' => $write + [
                'capability'        => Event_Calendar_Writes::type_cap(self::ORGANIZER, 'create_posts'),
                'self_snapshotting' => true,
                'description'       => 'Create a The Events Calendar organizer (published unless status is given). Recorded as a creation: rollback-operation trashes it',
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'title'   => $fields['title'],
                        'status'  => $status,
                        'phone'   => [ 'type' => 'string', 'maxLength' => 60 ],
                        'email'   => [ 'type' => 'string', 'maxLength' => 200 ],
                        'website' => $fields['url'],
                    ],
                    'required'   => [ 'title' ],
                ],
                'validate'          => static fn (array $args): ?array => self::check_linked($args, self::ORGANIZER),
                'handler'           => static fn (array $args, array $context): array => self::create_organizer($args, $context),
            ],
        ];
    }

    /** Extra get-event fields: organizers, categories and the TEC event status. */
    public static function details(int $id): array
    {
        return [
            'organizer_ids' => array_values(array_map('intval', (array) get_post_meta($id, '_EventOrganizerID'))),
            'category_ids'  => array_values(array_map('intval', (array) wp_get_object_terms($id, self::CATEGORY, [ 'fields' => 'ids' ]))),
            'event_status'  => (string) get_post_meta($id, '_tribe_events_status', true),
            'status_reason' => (string) get_post_meta($id, '_tribe_events_status_reason', true),
        ];
    }

    private static function event_refusal(int $id): ?array
    {
        return null === Event_Calendar_Writes::live_post($id, self::EVENT)
            ? Event_Calendar_Writes::refusal('event_not_found', sprintf('%d is not a The Events Calendar event.', $id), [ 'id' => $id ])
            : null;
    }

    /**
     * Every precondition of create-event ($id null) or update-event, with no
     * side effect.
     */
    private static function check_event(array $args, ?int $id): ?array
    {
        if (null !== $id) {
            $missing = self::event_refusal($id);
            if (null !== $missing) {
                return $missing;
            }
            if ([] !== array_intersect(self::SCHEDULE_ARGS, array_keys($args)) && '' !== (string) get_post_meta($id, '_EventRecurrence', true)) {
                return Event_Calendar_Writes::refusal('recurring_event', 'This event repeats; change its schedule in The Events Calendar editor so every occurrence moves together.', [ 'id' => $id ]);
            }
        }
        $schedule = self::schedule_for($args, $id);
        if (isset($schedule['code'])) {
            return $schedule;
        }
        $venue = (int) ($args['venue_id'] ?? 0);
        return Event_Calendar_Writes::publish_refusal(self::EVENT, isset($args['status']) ? (string) $args['status'] : null)
            ?? ($venue > 0 ? Event_Calendar_Writes::posts_refusal([ $venue ], self::VENUE, 'venue_not_found', 'The Events Calendar venue') : null)
            ?? Event_Calendar_Writes::posts_refusal((array) ($args['organizer_ids'] ?? []), self::ORGANIZER, 'organizer_not_found', 'The Events Calendar organizer')
            ?? Event_Calendar_Writes::terms_refusal((array) ($args['category_ids'] ?? []), self::CATEGORY, 'category_not_found', 'The Events Calendar event category')
            ?? (isset($args['url']) && null === Event_Calendar_Writes::url((string) $args['url']) ? Event_Calendar_Writes::refusal('url_invalid', 'url must be an http or https address.') : null);
    }

    /** Preconditions of create-venue and create-organizer. */
    private static function check_linked(array $args, string $type): ?array
    {
        $refusal = Event_Calendar_Writes::publish_refusal($type, (string) ($args['status'] ?? 'publish'));
        if (null !== $refusal) {
            return $refusal;
        }
        foreach ([ 'url', 'website' ] as $key) {
            if (isset($args[ $key ]) && null === Event_Calendar_Writes::url((string) $args[ $key ])) {
                return Event_Calendar_Writes::refusal('url_invalid', sprintf('%s must be an http or https address.', $key));
            }
        }
        if (isset($args['email']) && '' !== trim((string) $args['email']) && ! is_email(trim((string) $args['email']))) {
            return Event_Calendar_Writes::refusal('email_invalid', 'email is not a valid address.');
        }
        return null;
    }

    /**
     * The schedule meta the call sets (null when it names no schedule arg on
     * an update), or a refusal.
     *
     * @return array<string, string>|array{code: string, message: string, data: array}|null
     */
    private static function schedule_for(array $args, ?int $id): ?array
    {
        if (null !== $id && [] === array_intersect(self::SCHEDULE_ARGS, array_keys($args))) {
            return null;
        }
        $all_day = array_key_exists('all_day', $args)
            ? (bool) $args['all_day']
            : (null !== $id && 'yes' === strtolower((string) get_post_meta($id, '_EventAllDay', true)));
        $start   = isset($args['start_date']) ? (string) $args['start_date'] : (null !== $id ? (string) get_post_meta($id, '_EventStartDate', true) : '');
        $end     = isset($args['end_date']) ? (string) $args['end_date'] : (null !== $id ? (string) get_post_meta($id, '_EventEndDate', true) : '');
        $zone    = isset($args['timezone']) ? trim((string) $args['timezone']) : (null !== $id ? (string) get_post_meta($id, '_EventTimezone', true) : '');
        if ('' === $zone) {
            $zone = self::site_timezone();
        }

        return self::schedule($start, $end, $all_day, $zone);
    }

    /** The site time zone the way TEC names it: a zone name, or UTC+5 for a bare offset. */
    private static function site_timezone(): string
    {
        $name = (string) get_option('timezone_string');
        if ('' !== $name) {
            return $name;
        }
        $offset = (float) get_option('gmt_offset');
        return 0.0 === $offset ? 'UTC' : 'UTC' . ($offset > 0 ? '+' : '') . rtrim(rtrim(number_format($offset, 2, '.', ''), '0'), '.');
    }

    private static function zone(string $name): ?\DateTimeZone
    {
        if ('UTC' === $name || in_array($name, timezone_identifiers_list(), true)) {
            return new \DateTimeZone($name);
        }
        if (1 === preg_match('/^UTC([+-])(\d{1,2})(?:([.:])(\d{1,2}))?$/', $name, $m)) {
            $minutes = 0;
            if (isset($m[4])) {
                $minutes = ':' === $m[3] ? (int) $m[4] : (int) round(60 * (float) ('0.' . $m[4]));
            }
            if ((int) $m[2] > 14 || $minutes > 59) {
                return null;
            }
            return new \DateTimeZone(sprintf('%s%02d:%02d', $m[1], (int) $m[2], $minutes));
        }
        return null;
    }

    /**
     * TEC's schedule meta for a start and end in a zone, or a refusal.
     *
     * @return array<string, string>|array{code: string, message: string, data: array}
     */
    private static function schedule(string $start, string $end, bool $all_day, string $zone_name): array
    {
        $zone = self::zone($zone_name);
        if (null === $zone) {
            return Event_Calendar_Writes::refusal('timezone_invalid', sprintf('"%s" is not a time zone name (such as America/New_York) or a UTC offset (such as UTC+5).', $zone_name));
        }
        $from = self::moment($start, $all_day, '00:00:00', $zone);
        $to   = self::moment($end, $all_day, '23:59:59', $zone);
        if (null === $from || null === $to) {
            return Event_Calendar_Writes::refusal('date_invalid', $all_day
                ? 'start_date and end_date must be dates as YYYY-MM-DD.'
                : 'start_date and end_date must be YYYY-MM-DD HH:MM in the event time zone (a date alone needs all_day:true).');
        }
        if ($to < $from) {
            return Event_Calendar_Writes::refusal('schedule_invalid', 'end_date is before start_date.', [ 'start_date' => $from->format('Y-m-d H:i:s'), 'end_date' => $to->format('Y-m-d H:i:s') ]);
        }
        $utc = new \DateTimeZone('UTC');
        return [
            '_EventStartDate'    => $from->format('Y-m-d H:i:s'),
            '_EventEndDate'      => $to->format('Y-m-d H:i:s'),
            '_EventStartDateUTC' => $from->setTimezone($utc)->format('Y-m-d H:i:s'),
            '_EventEndDateUTC'   => $to->setTimezone($utc)->format('Y-m-d H:i:s'),
            '_EventDuration'     => (string) ($to->getTimestamp() - $from->getTimestamp()),
            '_EventTimezone'     => $zone_name,
            '_EventTimezoneAbbr' => $from->format('T'),
            '_EventAllDay'       => $all_day ? 'yes' : '',
        ];
    }

    private static function moment(string $value, bool $all_day, string $day_time, \DateTimeZone $zone): ?\DateTimeImmutable
    {
        if (1 !== preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2})(:\d{2})?)?$/', trim($value), $m)) {
            return null;
        }
        if ($all_day) {
            $text = $m[1] . ' ' . $day_time;
        } elseif (! isset($m[2]) || '' === $m[2]) {
            return null;
        } else {
            $text = $m[1] . ' ' . $m[2] . (isset($m[3]) && '' !== $m[3] ? $m[3] : ':00');
        }
        $moment = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $text, $zone);
        return false !== $moment && $moment->format('Y-m-d H:i:s') === $text ? $moment : null;
    }

    /** Write the event meta and categories the call names. */
    private static function write_event(int $id, array $args): void
    {
        $schedule = self::schedule_for($args, $id);
        foreach ((array) $schedule as $key => $value) {
            if ('_EventAllDay' === $key && '' === $value) {
                delete_post_meta($id, $key);
                continue;
            }
            update_post_meta($id, $key, $value);
        }
        if (array_key_exists('venue_id', $args)) {
            (int) $args['venue_id'] > 0 ? update_post_meta($id, '_EventVenueID', (int) $args['venue_id']) : delete_post_meta($id, '_EventVenueID');
        }
        if (array_key_exists('organizer_ids', $args)) {
            delete_post_meta($id, '_EventOrganizerID');
            foreach ((array) $args['organizer_ids'] as $organizer) {
                add_post_meta($id, '_EventOrganizerID', (int) $organizer);
            }
        }
        if (array_key_exists('cost', $args)) {
            update_post_meta($id, '_EventCost', sanitize_text_field((string) $args['cost']));
        }
        if (array_key_exists('url', $args)) {
            update_post_meta($id, '_EventURL', (string) Event_Calendar_Writes::url((string) $args['url']));
        }
        if (array_key_exists('category_ids', $args)) {
            $done = wp_set_object_terms($id, array_map('intval', (array) $args['category_ids']), self::CATEGORY);
            if (is_wp_error($done)) {
                throw new Operation_Refused('category_not_set', $done->get_error_message());
            }
        }
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
        ], static function (int $id) use ($args): void {
            update_post_meta($id, '_EventOrigin', 'events-calendar');
            self::write_event($id, $args);
        }, $args, $context);

        return [ 'result' => [ 'event' => self::view($id) ], 'operation_ids' => [ $operation_id ] ];
    }

    /** @return array<string, mixed> */
    private static function update_event(array $args): array
    {
        $id = (int) $args['event_id'];
        Event_Calendar_Writes::update_fields($id, $args);
        self::write_event($id, $args);
        return [ 'event' => self::view($id) ];
    }

    /** @return array<string, mixed> */
    private static function cancel_event(array $args): array
    {
        $id = (int) $args['event_id'];
        update_post_meta($id, '_tribe_events_status', 'canceled');
        $reason = sanitize_text_field((string) ($args['reason'] ?? ''));
        '' === $reason ? delete_post_meta($id, '_tribe_events_status_reason') : update_post_meta($id, '_tribe_events_status_reason', $reason);
        return [ 'event' => self::view($id) ];
    }

    /** @return array{result: array<string, mixed>, operation_ids: string[]} */
    private static function create_venue(array $args, array $context): array
    {
        $country = sanitize_text_field((string) ($args['country'] ?? ''));
        $state   = sanitize_text_field((string) ($args['state'] ?? ''));
        $prov    = sanitize_text_field((string) ($args['province'] ?? ''));
        // TEC's own rule (Tribe__Events__Venue::save_meta): a US venue shows its state, any other its province.
        $either  = '' !== $state && ('' === $country || 'US' === $country || 'United States' === $country) ? $state : ('' !== $prov ? $prov : $state);
        $meta    = [
            'address'        => [ '_VenueAddress', sanitize_text_field((string) ($args['address'] ?? '')) ],
            'city'           => [ '_VenueCity', sanitize_text_field((string) ($args['city'] ?? '')) ],
            'country'        => [ '_VenueCountry', $country ],
            'state'          => [ '_VenueState', $state ],
            'province'       => [ '_VenueProvince', $prov ],
            'state_province' => [ '_VenueStateProvince', $either ],
            'zip'            => [ '_VenueZip', sanitize_text_field((string) ($args['zip'] ?? '')) ],
            'phone'          => [ '_VenuePhone', sanitize_text_field((string) ($args['phone'] ?? '')) ],
            'url'            => [ '_VenueURL', (string) Event_Calendar_Writes::url((string) ($args['url'] ?? '')) ],
        ];
        return self::create_linked(self::VENUE, 'venue', $meta, $args, $context);
    }

    /** @return array{result: array<string, mixed>, operation_ids: string[]} */
    private static function create_organizer(array $args, array $context): array
    {
        $meta = [
            'phone'   => [ '_OrganizerPhone', sanitize_text_field((string) ($args['phone'] ?? '')) ],
            'email'   => [ '_OrganizerEmail', sanitize_email((string) ($args['email'] ?? '')) ],
            'website' => [ '_OrganizerWebsite', (string) Event_Calendar_Writes::url((string) ($args['website'] ?? '')) ],
        ];
        return self::create_linked(self::ORGANIZER, 'organizer', $meta, $args, $context);
    }

    /**
     * @param array<string, array{0: string, 1: string}> $meta output field => [meta key, value]
     * @return array{result: array<string, mixed>, operation_ids: string[]}
     */
    private static function create_linked(string $type, string $key, array $meta, array $args, array $context): array
    {
        $origin = self::VENUE === $type ? '_VenueOrigin' : '_OrganizerOrigin';
        [ $id, $operation_id ] = Event_Calendar_Writes::insert([
            'post_type'   => $type,
            'post_title'  => (string) $args['title'],
            'post_status' => (string) ($args['status'] ?? 'publish'),
        ], static function (int $id) use ($meta, $origin): void {
            update_post_meta($id, $origin, 'events-calendar');
            foreach ($meta as [ $meta_key, $value ]) {
                if ('' !== $value) {
                    update_post_meta($id, $meta_key, $value);
                }
            }
        }, $args, $context);

        $post = get_post($id);
        $out  = [ 'id' => $id, 'title' => (string) $post->post_title, 'status' => (string) $post->post_status ];
        foreach ($meta as $field => [ $meta_key ]) {
            $out[ $field ] = (string) get_post_meta($id, $meta_key, true);
        }
        return [ 'result' => [ $key => $out ], 'operation_ids' => [ $operation_id ] ];
    }

    /** @return array<string, mixed> */
    private static function view(int $id): array
    {
        $post = get_post($id);
        $meta = static fn (string $key): string => (string) get_post_meta($id, $key, true);
        return [
            'id'         => $id,
            'title'      => (string) $post->post_title,
            'status'     => (string) $post->post_status,
            'start_date' => $meta('_EventStartDate'),
            'end_date'   => $meta('_EventEndDate'),
            'start_date_utc' => $meta('_EventStartDateUTC'),
            'end_date_utc'   => $meta('_EventEndDateUTC'),
            'all_day'    => 'yes' === strtolower($meta('_EventAllDay')),
            'timezone'   => $meta('_EventTimezone'),
            'venue_id'   => (int) $meta('_EventVenueID'),
            'cost'       => $meta('_EventCost'),
            'url'        => $meta('_EventURL'),
        ] + self::details($id);
    }
}
