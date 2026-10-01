<?php

namespace WPMCP\Integrations;

use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The Events Calendar (TEC) read integration (wpmcp/tec-read pair).
 *
 * TEC stores each event as a `tribe_events` custom post type, with its schedule
 * in underscore-prefixed `_Event*` postmeta (_EventStartDate, _EventEndDate,
 * _EventVenueID, ... ; verified against TEC source). Those keys are hidden by
 * the generic get-post tool (protected meta), so this integration surfaces
 * them through a curated, TEC-aware read: list events, and read one event's
 * schedule as clean named fields.
 *
 * Read-only: tribe_events is an ordinary CPT, so create/edit is available via
 * the generic content tools (snapshotted); this adds the curated read on top.
 */
class The_Events_Calendar_Integration extends Integration_Dispatcher
{
    public const POST_TYPE = 'tribe_events';

    /** Curated TEC meta, neutral name => meta key. */
    private const EVENT_META = [
        'start_date'     => '_EventStartDate',
        'end_date'       => '_EventEndDate',
        'start_date_utc' => '_EventStartDateUTC',
        'all_day'        => '_EventAllDay',
        'venue_id'       => '_EventVenueID',
        'organizer_id'   => '_EventOrganizerID',
        'cost'           => '_EventCost',
        'url'            => '_EventURL',
    ];

    public function integration(): string
    {
        return 'tec';
    }

    public function is_available(): bool
    {
        return post_type_exists(self::POST_TYPE);
    }

    protected function summary(): string
    {
        return 'The Events Calendar (events and their schedules)';
    }

    private static function schedule(int $post_id): array
    {
        $out = [];
        foreach (self::EVENT_META as $field => $key) {
            $out[ $field ] = get_post_meta($post_id, $key, true);
        }
        return $out;
    }

    protected function operations(): array
    {
        return [
            'list-events' => [
                'mode'         => 'read',
                'description'  => 'List The Events Calendar events with id, title, status, and start/end date, newest first (paged; page_size default 20, max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page_size' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
                        'page'      => [ 'type' => 'integer', 'minimum' => 1 ],
                        'status'    => [ 'type' => 'string' ],
                    ],
                ],
                'handler'      => function (array $args): array {
                    // Only the rows the caller may read (issue #461).
                    $q = Readable_Posts::query([
                        'post_type'      => self::POST_TYPE,
                        'post_status'    => isset($args['status']) ? (string) $args['status'] : 'any',
                        'posts_per_page' => (int) ($args['page_size'] ?? 20),
                        'paged'          => (int) ($args['page'] ?? 1),
                        'orderby'        => 'date',
                        'order'          => 'DESC',
                    ]);
                    $events = [];
                    foreach ($q->posts as $post) {
                        $events[] = [
                            'id'         => (int) $post->ID,
                            'title'      => (string) $post->post_title,
                            'status'     => (string) $post->post_status,
                            'start_date' => get_post_meta($post->ID, '_EventStartDate', true),
                            'end_date'   => get_post_meta($post->ID, '_EventEndDate', true),
                        ];
                    }
                    return [ 'events' => $events, 'total' => (int) $q->found_posts ];
                },
            ],
            'get-event' => [
                'mode'         => 'read',
                'objects'      => [ 'event_id' => [ 'type' => 'post', 'own_type' => true ] ],
                'description'  => 'Read one event: title, content, status, and its curated schedule (start/end date, UTC start, all-day flag, venue and organizer ids, cost, URL)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'event_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'event_id' ],
                ],
                'handler'      => function (array $args): array {
                    $post = get_post((int) $args['event_id']);
                    if (! $post || self::POST_TYPE !== $post->post_type) {
                        return [ 'event' => null ];
                    }
                    return [
                        'event' => [
                            'id'       => (int) $post->ID,
                            'title'    => (string) $post->post_title,
                            'status'   => (string) $post->post_status,
                            'content'  => (string) $post->post_content,
                            'schedule' => self::schedule((int) $post->ID),
                        ],
                    ];
                },
            ],
        ];
    }
}
