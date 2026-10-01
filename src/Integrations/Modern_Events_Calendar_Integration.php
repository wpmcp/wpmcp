<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Modern Events Calendar (MEC) read integration (wpmcp/mec-read pair).
 *
 * MEC stores each event as a `mec-events` custom post type, with its schedule
 * and details in `mec_*` postmeta (mec_start_date, mec_end_date, mec_start_time,
 * mec_location_id, mec_organizer_id, ... ; verified against MEC's source). This
 * integration lists events and reads one event's curated schedule fields, so an
 * agent can answer "what is on next week?" without the caller having to know
 * MEC's meta-key names.
 *
 * Read-only: creating and editing events is possible through the generic
 * content tools (mec-events is an ordinary CPT), which snapshot every write;
 * this integration adds a curated, MEC-aware read surface on top.
 */
class Modern_Events_Calendar_Integration extends Integration_Dispatcher
{
    public const POST_TYPE = 'mec-events';

    /** Curated MEC meta keys surfaced by get-event, neutral name => meta key. */
    private const EVENT_META = [
        'start_date'   => 'mec_start_date',
        'end_date'     => 'mec_end_date',
        'start_time'   => 'mec_start_time',
        'end_time'     => 'mec_end_time',
        'all_day'      => 'mec_allday',
        'location_id'  => 'mec_location_id',
        'organizer_id' => 'mec_organizer_id',
        'cost'         => 'mec_cost',
    ];

    public function integration(): string
    {
        return 'mec';
    }

    public function is_available(): bool
    {
        return post_type_exists(self::POST_TYPE);
    }

    protected function summary(): string
    {
        return 'Modern Events Calendar (events and their schedules)';
    }

    private static function event_dates(int $post_id): array
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
                'description'  => 'List Modern Events Calendar events with id, title, status, and start/end date, newest first (paged; page_size default 20, max 100)',
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
                    $q = self::readable_query([
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
                            'start_date' => get_post_meta($post->ID, 'mec_start_date', true),
                            'end_date'   => get_post_meta($post->ID, 'mec_end_date', true),
                        ];
                    }
                    return [ 'events' => $events, 'total' => (int) $q->found_posts ];
                },
            ],
            'get-event' => [
                'mode'         => 'read',
                'objects'      => [ 'event_id' => [ 'type' => 'post', 'own_type' => true ] ],
                'description'  => 'Read one event: title, content, status, and its curated MEC schedule (start/end date and time, all-day flag, location and organizer ids, cost)',
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
                            'schedule' => self::event_dates((int) $post->ID),
                        ],
                    ];
                },
            ],
        ];
    }
}
