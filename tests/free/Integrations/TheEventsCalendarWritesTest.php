<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\The_Events_Calendar_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * The Events Calendar writes and lookups on the tec pair (issue #677),
 * against TEC's own storage: tribe_events, tribe_venue and tribe_organizer
 * posts with their _Event*, _Venue* and _Organizer* meta, and the
 * tribe_events_cat taxonomy.
 */
class TheEventsCalendarWritesTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        register_post_type('tribe_events', [ 'public' => true, 'label' => 'Events', 'supports' => [ 'title', 'editor', 'excerpt' ] ]);
        register_post_type('tribe_venue', [ 'public' => true, 'label' => 'Venues' ]);
        register_post_type('tribe_organizer', [ 'public' => true, 'label' => 'Organizers' ]);
        register_taxonomy('tribe_events_cat', 'tribe_events', [ 'public' => true, 'hierarchical' => true ]);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        update_option('timezone_string', 'America/New_York');
        add_filter('wpmcp_enable_tec_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_enable_tec_write');
        unregister_taxonomy('tribe_events_cat');
        unregister_post_type('tribe_organizer');
        unregister_post_type('tribe_venue');
        unregister_post_type('tribe_events');
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new The_Events_Calendar_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args): array
    {
        return (new The_Events_Calendar_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function venue(string $title = 'Civic Hall'): int
    {
        $id = self::factory()->post->create([ 'post_type' => 'tribe_venue', 'post_title' => $title, 'post_status' => 'publish' ]);
        update_post_meta($id, '_VenueCity', 'Grand Rapids');
        update_post_meta($id, '_VenueStateProvince', 'MI');
        return $id;
    }

    private function organizer(string $title = 'Arts Council'): int
    {
        $id = self::factory()->post->create([ 'post_type' => 'tribe_organizer', 'post_title' => $title, 'post_status' => 'publish' ]);
        update_post_meta($id, '_OrganizerEmail', 'jane@example.com');
        update_post_meta($id, '_OrganizerPhone', '555-0100');
        return $id;
    }

    private function event(): int
    {
        $id = self::factory()->post->create([ 'post_type' => 'tribe_events', 'post_title' => 'Gala', 'post_status' => 'publish' ]);
        update_post_meta($id, '_EventStartDate', '2026-09-01 18:00:00');
        update_post_meta($id, '_EventEndDate', '2026-09-01 22:00:00');
        update_post_meta($id, '_EventStartDateUTC', '2026-09-01 22:00:00');
        update_post_meta($id, '_EventEndDateUTC', '2026-09-02 02:00:00');
        update_post_meta($id, '_EventTimezone', 'America/New_York');
        update_post_meta($id, '_EventCost', '25');
        return $id;
    }

    public function test_the_catalog_lists_every_new_op_with_its_mode(): void
    {
        $ops = array_column($this->ok($this->read('list-operations'))['result']['operations'], 'mode', 'name');
        foreach ([ 'list-venues', 'list-organizers', 'list-categories' ] as $op) {
            $this->assertSame('read', $ops[ $op ] ?? null, $op);
        }
        foreach ([ 'create-event', 'update-event', 'cancel-event', 'create-venue', 'create-organizer' ] as $op) {
            $this->assertSame('write', $ops[ $op ] ?? null, $op);
        }
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        remove_all_filters('wpmcp_enable_tec_write');
        $out = $this->write('create-venue', [ 'title' => 'Hall' ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null);
        $this->assertStringContainsString('wpmcp_enable_tec_write', (string) wp_json_encode($out));
    }

    public function test_a_call_while_the_plugin_is_missing_names_it(): void
    {
        unregister_post_type('tribe_events');
        $out = $this->read('list-venues');
        $this->assertContains($out['error']['code'] ?? null, [ 'plugin_missing', 'plugin_inactive' ]);
        $this->assertStringContainsString('The Events Calendar', $out['error']['message']);
    }

    public function test_list_venues_organizers_and_categories(): void
    {
        $this->venue();
        $this->organizer();
        wp_insert_term('Music', 'tribe_events_cat');

        $venues = $this->ok($this->read('list-venues'))['result'];
        $this->assertSame(1, $venues['total']);
        $this->assertSame('Civic Hall', $venues['venues'][0]['title']);
        $this->assertSame('Grand Rapids', $venues['venues'][0]['city']);

        $organizers = $this->ok($this->read('list-organizers'))['result'];
        $this->assertSame('Arts Council', $organizers['organizers'][0]['title']);
        $this->assertSame('j***@example.com', $organizers['organizers'][0]['email'], 'lists mask personal data');
        $this->assertSame('5***', $organizers['organizers'][0]['phone']);
        $this->assertTrue($organizers['masked']);

        $categories = $this->ok($this->read('list-categories'))['result'];
        $this->assertSame([ 'Music' ], array_column($categories['categories'], 'name'));
    }

    public function test_create_event_stores_tec_schedule_meta_as_a_draft_and_rollback_trashes_it(): void
    {
        $venue     = $this->venue();
        $organizer = $this->organizer();
        $term      = (int) wp_insert_term('Music', 'tribe_events_cat')['term_id'];

        $out   = $this->ok($this->write('create-event', [
            'title'         => 'Jazz Night',
            'content'       => 'Live jazz.',
            'start_date'    => '2026-07-04 19:30',
            'end_date'      => '2026-07-04 22:00',
            'venue_id'      => $venue,
            'organizer_ids' => [ $organizer ],
            'category_ids'  => [ $term ],
            'cost'          => '15',
            'url'           => 'https://example.com/jazz',
        ]));
        $event = $out['result']['event'];
        $id    = $event['id'];

        $this->assertSame('tribe_events', get_post_type($id));
        $this->assertSame('draft', get_post_status($id), 'a new event is a draft unless status says otherwise');
        $this->assertSame('2026-07-04 19:30:00', get_post_meta($id, '_EventStartDate', true));
        $this->assertSame('2026-07-04 22:00:00', get_post_meta($id, '_EventEndDate', true));
        $this->assertSame('2026-07-04 23:30:00', get_post_meta($id, '_EventStartDateUTC', true), 'EDT is UTC-4');
        $this->assertSame('2026-07-05 02:00:00', get_post_meta($id, '_EventEndDateUTC', true));
        $this->assertSame('9000', (string) get_post_meta($id, '_EventDuration', true));
        $this->assertSame('America/New_York', get_post_meta($id, '_EventTimezone', true));
        $this->assertSame('EDT', get_post_meta($id, '_EventTimezoneAbbr', true));
        $this->assertSame((string) $venue, (string) get_post_meta($id, '_EventVenueID', true));
        $this->assertSame([ (string) $organizer ], array_map('strval', get_post_meta($id, '_EventOrganizerID')));
        $this->assertSame([ $term ], wp_get_object_terms($id, 'tribe_events_cat', [ 'fields' => 'ids' ]));
        $this->assertSame('15', get_post_meta($id, '_EventCost', true));
        $this->assertSame('https://example.com/jazz', get_post_meta($id, '_EventURL', true));
        $this->assertSame([ $organizer ], $event['organizer_ids']);

        $this->assertNotEmpty($out['operation_ids'] ?? []);
        foreach ((array) $out['operation_ids'] as $operation) {
            Rollback_Service::restore_operation((string) $operation);
        }
        $this->assertSame('trash', get_post_status($id));
    }

    public function test_create_event_all_day_spans_the_whole_day(): void
    {
        $out = $this->ok($this->write('create-event', [ 'title' => 'Fair', 'start_date' => '2026-08-01', 'end_date' => '2026-08-02', 'all_day' => true, 'timezone' => 'UTC' ]));
        $id  = $out['result']['event']['id'];
        $this->assertSame('2026-08-01 00:00:00', get_post_meta($id, '_EventStartDate', true));
        $this->assertSame('2026-08-02 23:59:59', get_post_meta($id, '_EventEndDate', true));
        $this->assertSame('yes', get_post_meta($id, '_EventAllDay', true));
        $this->assertTrue($out['result']['event']['all_day']);
    }

    public function test_create_event_refuses_bad_input_with_no_side_effects(): void
    {
        $before = wp_count_posts('tribe_events')->draft;
        $cases  = [
            'schedule_invalid'    => [ 'start_date' => '2026-07-04 19:00', 'end_date' => '2026-07-04 18:00' ],
            'date_invalid'        => [ 'start_date' => '2026-07-04', 'end_date' => '2026-07-04' ],
            'timezone_invalid'    => [ 'start_date' => '2026-07-04 19:00', 'end_date' => '2026-07-04 20:00', 'timezone' => 'Mars/Olympus' ],
            'venue_not_found'     => [ 'start_date' => '2026-07-04 19:00', 'end_date' => '2026-07-04 20:00', 'venue_id' => self::factory()->post->create() ],
            'organizer_not_found' => [ 'start_date' => '2026-07-04 19:00', 'end_date' => '2026-07-04 20:00', 'organizer_ids' => [ 999999 ] ],
            'url_invalid'         => [ 'start_date' => '2026-07-04 19:00', 'end_date' => '2026-07-04 20:00', 'url' => 'javascript:alert(1)' ],
        ];
        foreach ($cases as $code => $args) {
            $out = $this->write('create-event', [ 'title' => 'x' ] + $args);
            $this->assertSame($code, $out['error']['code'] ?? null, $code . ': ' . wp_json_encode($out));
        }
        $this->assertSame($before, wp_count_posts('tribe_events')->draft);
    }

    public function test_create_event_refuses_to_publish_for_a_caller_who_cannot(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'contributor' ]));
        $out = $this->write('create-event', [ 'title' => 'x', 'start_date' => '2026-07-04 19:00', 'end_date' => '2026-07-04 20:00', 'status' => 'publish' ]);
        $this->assertSame('operation_denied', $out['error']['code'] ?? null, (string) wp_json_encode($out));
    }

    public function test_update_event_moves_the_schedule_and_rollback_puts_everything_back(): void
    {
        $id     = $this->event();
        $before = [ get_post($id)->post_title, get_post_meta($id), wp_get_object_terms($id, 'tribe_events_cat', [ 'fields' => 'ids' ]) ];
        $term   = (int) wp_insert_term('Gala', 'tribe_events_cat')['term_id'];

        $out = $this->ok($this->write('update-event', [ 'event_id' => $id, 'title' => 'Winter Gala', 'start_date' => '2026-12-01 18:00', 'end_date' => '2026-12-01 23:00', 'category_ids' => [ $term ] ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('Winter Gala', get_post($id)->post_title);
        $this->assertSame('2026-12-01 18:00:00', get_post_meta($id, '_EventStartDate', true));
        $this->assertSame('2026-12-01 23:00:00', get_post_meta($id, '_EventStartDateUTC', true), 'EST is UTC-5');
        $this->assertSame('EST', get_post_meta($id, '_EventTimezoneAbbr', true));
        $this->assertSame('25', get_post_meta($id, '_EventCost', true), 'untouched');
        $this->assertSame('publish', get_post_status($id), 'untouched');

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, [ get_post($id)->post_title, get_post_meta($id), wp_get_object_terms($id, 'tribe_events_cat', [ 'fields' => 'ids' ]) ]);
    }

    public function test_update_event_keeps_the_start_when_only_the_end_moves(): void
    {
        $id  = $this->event();
        $out = $this->ok($this->write('update-event', [ 'event_id' => $id, 'end_date' => '2026-09-01 23:30' ]));
        $this->assertSame('2026-09-01 18:00:00', get_post_meta($id, '_EventStartDate', true));
        $this->assertSame('2026-09-01 23:30:00', $out['result']['event']['end_date']);
        $this->assertSame('schedule_invalid', $this->write('update-event', [ 'event_id' => $id, 'end_date' => '2026-08-01 10:00' ])['error']['code'] ?? null);
    }

    public function test_update_and_cancel_refuse_a_non_event(): void
    {
        $page = self::factory()->post->create([ 'post_type' => 'page' ]);
        $this->assertSame('event_not_found', $this->write('update-event', [ 'event_id' => $page, 'title' => 'x' ])['error']['code'] ?? null);
        $this->assertSame('event_not_found', $this->write('cancel-event', [ 'event_id' => $page ])['error']['code'] ?? null);
    }

    public function test_cancel_event_sets_the_tec_event_status_and_rollback_clears_it(): void
    {
        $id  = $this->event();
        $out = $this->ok($this->write('cancel-event', [ 'event_id' => $id, 'reason' => 'Storm warning' ]));
        $this->assertSame('canceled', get_post_meta($id, '_tribe_events_status', true));
        $this->assertSame('Storm warning', get_post_meta($id, '_tribe_events_status_reason', true));
        $this->assertSame('publish', get_post_status($id), 'a canceled event stays listed');
        $this->assertSame('canceled', $out['result']['event']['event_status']);

        $read = $this->ok($this->read('get-event', [ 'event_id' => $id ]));
        $this->assertSame('canceled', $read['result']['event']['event_status']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('', get_post_meta($id, '_tribe_events_status', true));
        $this->assertSame('', get_post_meta($id, '_tribe_events_status_reason', true));
    }

    public function test_create_venue_and_organizer_store_tec_meta_and_rollback_trashes_them(): void
    {
        $venue = $this->ok($this->write('create-venue', [ 'title' => 'Riverfront Park', 'address' => '1 Main St', 'city' => 'Grand Rapids', 'state' => 'MI', 'zip' => '49503', 'country' => 'US', 'url' => 'https://example.com/park' ]));
        $vid   = $venue['result']['venue']['id'];
        $this->assertSame('tribe_venue', get_post_type($vid));
        $this->assertSame('publish', get_post_status($vid));
        $this->assertSame('1 Main St', get_post_meta($vid, '_VenueAddress', true));
        $this->assertSame('MI', get_post_meta($vid, '_VenueStateProvince', true));
        $this->assertSame('49503', $venue['result']['venue']['zip']);

        $org = $this->ok($this->write('create-organizer', [ 'title' => 'City Arts', 'email' => 'info@example.com', 'phone' => '555-0199', 'website' => 'https://example.com' ]));
        $oid = $org['result']['organizer']['id'];
        $this->assertSame('tribe_organizer', get_post_type($oid));
        $this->assertSame('info@example.com', get_post_meta($oid, '_OrganizerEmail', true));
        $this->assertSame('email_invalid', $this->write('create-organizer', [ 'title' => 'x', 'email' => 'not-an-email' ])['error']['code'] ?? null);

        foreach (array_merge($venue['operation_ids'], $org['operation_ids']) as $operation) {
            Rollback_Service::restore_operation((string) $operation);
        }
        $this->assertSame('trash', get_post_status($vid));
        $this->assertSame('trash', get_post_status($oid));
    }
}
