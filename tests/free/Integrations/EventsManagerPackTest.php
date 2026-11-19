<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Events_Manager_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/events-manager-stubs.php';

/**
 * Events Manager (issue #678): free-tier ops on the plugin-data dispatcher
 * pair, so they add no top-level tools. Op names after the prefix match the
 * TEC pair's.
 *
 * Events Manager is not loaded in the shared test core. Presence is driven
 * through wpmcp_events_manager_active, and its tables, post type and the
 * EM_Event save path are stood in by tests/support/events-manager-stubs.php,
 * taken from Events Manager 7.5's source.
 *
 * The writes are off until the site opts in
 * (wpmcp_enable_events_manager_write) and each is undone in a test.
 */
class EventsManagerPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'eventsmanager-list-events'   => 'read',
        'eventsmanager-get-event'     => 'read',
        'eventsmanager-list-bookings' => 'read',
        'eventsmanager-create-event'  => 'write',
        'eventsmanager-update-event'  => 'write',
    ];

    /** The capabilities Events Manager's activation grants administrators, which EM_Event::save() checks. */
    private const CAPS = [ 'edit_events', 'edit_others_events', 'publish_events' ];

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_events_manager_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_events_manager_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wpmcp_test_register_events_manager_types();
        foreach (self::CAPS as $cap) {
            get_role('administrator')->add_cap($cap);
        }
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        add_filter('wpmcp_events_manager_active', '__return_true');
        add_filter('wpmcp_enable_events_manager_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_events_manager_active');
        remove_all_filters('wpmcp_enable_events_manager_write');
        foreach (self::CAPS as $cap) {
            get_role('administrator')->remove_cap($cap);
        }
        unregister_post_type('event');
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        return isset($out['result']) && ! isset($out['error']) ? $out['result'] : $out;
    }

    private function write(string $op, array $args): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    /** An event saved the way EM_Event::save() leaves it: post, `_event_*` meta and its em_events row. */
    private function event(string $title, string $start_date, string $start_time = '18:00:00', string $end_time = '21:00:00', string $status = 'publish', int $location = 0): int
    {
        $e                   = new \EM_Event();
        $e->event_name       = $title;
        $e->post_content     = 'About ' . $title;
        $e->event_start_date = $start_date;
        $e->event_end_date   = $start_date;
        $e->event_start_time = $start_time;
        $e->event_end_time   = $end_time;
        $e->event_timezone   = 'Europe/London';
        $e->location_id      = $location;
        $e->force_status     = $status;
        $this->assertTrue($e->save());
        return (int) $e->post_id;
    }

    private function em_row(int $post_id): array
    {
        global $wpdb;
        return (array) $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE post_id = %d', $wpdb->prefix . 'em_events', $post_id), ARRAY_A);
    }

    private function booking(int $post_id, int $person, int $status, string $comment, array $registration = []): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'em_bookings', [
            'booking_uuid'    => md5(uniqid('', true)),
            'event_id'        => (int) get_post_meta($post_id, '_event_id', true),
            'person_id'       => $person,
            'booking_spaces'  => 2,
            'booking_comment' => $comment,
            'booking_status'  => $status,
            'booking_price'   => '30.0000',
        ]);
        $id = (int) $wpdb->insert_id;
        foreach ($registration as $key => $value) {
            $wpdb->insert($wpdb->prefix . 'em_bookings_meta', [ 'booking_id' => $id, 'meta_key' => '_registration|' . $key, 'meta_value' => $value ]);
        }
        return $id;
    }

    // ---------------------------------------------------------------
    // Catalog, availability, opt-in and capability
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_op_with_its_mode(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
        }
    }

    public function test_while_events_manager_is_missing_a_call_answers_plugin_missing_with_the_plugins_link(): void
    {
        remove_all_filters('wpmcp_events_manager_active');
        add_filter('wpmcp_events_manager_active', '__return_false');
        $out = $this->read('eventsmanager-list-events');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Events Manager', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
    }

    public function test_presence_defaults_to_the_plugin_constant(): void
    {
        remove_all_filters('wpmcp_events_manager_active');
        $this->assertSame(defined('EM_VERSION'), Events_Manager_Pack::active());
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $id = $this->event('Gala', '2030-09-01');
        remove_all_filters('wpmcp_enable_events_manager_write');
        foreach ([
            [ 'eventsmanager-create-event', [ 'title' => 'x', 'start_date' => '2030-01-01', 'all_day' => true ] ],
            [ 'eventsmanager-update-event', [ 'id' => $id, 'title' => 'x' ] ],
        ] as [ $op, $args ]) {
            $out = $this->write($op, $args);
            $this->assertSame('opt_in_required', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString('wpmcp_enable_events_manager_write', $out['error']['message']);
        }
        $this->assertSame('Gala', get_post($id)->post_title);
    }

    public function test_writes_and_bookings_need_more_than_an_editor_has(): void
    {
        $id = $this->event('Gala', '2030-09-01');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertSame('operation_denied', $this->write('eventsmanager-update-event', [ 'id' => $id, 'title' => 'x' ])['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->write('eventsmanager-create-event', [ 'title' => 'x', 'start_date' => '2030-01-01', 'all_day' => true ])['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->read('eventsmanager-list-bookings')['error']['code'] ?? null, 'no manage_others_bookings');
        $this->assertSame('Gala', $this->read('eventsmanager-get-event', [ 'id' => $id ])['event']['title'], 'an editor still reads events');
    }

    public function test_an_administrator_without_the_events_manager_capabilities_is_told_which_one(): void
    {
        $id = $this->event('Gala', '2030-09-01');
        get_role('administrator')->remove_cap('edit_others_events');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        $out = $this->write('eventsmanager-update-event', [ 'id' => $id, 'title' => 'x' ]);
        $this->assertSame('operation_denied', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('edit_others_events', $out['error']['message']);

        get_role('administrator')->remove_cap('edit_events');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        $out = $this->write('eventsmanager-create-event', [ 'title' => 'x', 'start_date' => '2030-01-01', 'all_day' => true ]);
        $this->assertStringContainsString('edit_events', $out['error']['message'] ?? '');
        $this->assertSame('Gala', get_post($id)->post_title);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_events_orders_by_start_and_filters_by_scope_status_and_search(): void
    {
        $late  = $this->event('Late gala', '2030-09-10');
        $early = $this->event('Early fair', '2030-09-01');
        $past  = $this->event('Old market', '2001-05-05');
        $draft = $this->event('Draft talk', '2030-10-01', '10:00:00', '11:00:00', 'draft');

        $all = $this->read('eventsmanager-list-events');
        $this->assertSame(4, $all['total']);
        $this->assertSame([ $past, $early, $late, $draft ], array_column($all['events'], 'id'));
        $first = $all['events'][1];
        $this->assertSame('2030-09-01 18:00:00', $first['start']);
        $this->assertSame('2030-09-01 21:00:00', $first['end']);
        $this->assertFalse($first['all_day']);
        $this->assertSame((int) get_post_meta($early, '_event_id', true), $first['event_id']);

        $this->assertSame([ $early, $late, $draft ], array_column($this->read('eventsmanager-list-events', [ 'scope' => 'future' ])['events'], 'id'));
        $this->assertSame([ $past ], array_column($this->read('eventsmanager-list-events', [ 'scope' => 'past' ])['events'], 'id'));
        $this->assertSame([ $draft ], array_column($this->read('eventsmanager-list-events', [ 'status' => 'draft' ])['events'], 'id'));
        $this->assertSame([ $late ], array_column($this->read('eventsmanager-list-events', [ 'search' => 'gala' ])['events'], 'id'));
    }

    public function test_get_event_returns_the_schedule_venue_and_content(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'em_locations', [ 'post_id' => 0, 'location_name' => 'Civic Hall', 'location_address' => '1 Main St', 'location_town' => 'Leeds', 'location_postcode' => 'LS1', 'location_country' => 'GB' ]);
        $loc = (int) $wpdb->insert_id;
        $id  = $this->event('Gala', '2030-09-01', '18:00:00', '21:00:00', 'publish', $loc);

        $event = $this->read('eventsmanager-get-event', [ 'id' => $id ])['event'];
        $this->assertSame('Gala', $event['title']);
        $this->assertSame('About Gala', $event['content']);
        $this->assertSame('Europe/London', $event['timezone']);
        $this->assertSame($loc, $event['location_id']);
        $this->assertSame('Civic Hall', $event['location']['name']);
        $this->assertSame('Leeds', $event['location']['town']);
        $this->assertFalse($event['bookings']);
        $this->assertSame(get_permalink($id), $event['url']);
    }

    public function test_get_event_refuses_a_post_that_is_not_an_event(): void
    {
        $page = self::factory()->post->create([ 'post_type' => 'page' ]);
        $out  = $this->read('eventsmanager-get-event', [ 'id' => $page ]);
        $this->assertArrayHasKey('error', $out);
    }

    public function test_list_bookings_masks_the_booker_and_filters_by_event_and_status(): void
    {
        $gala  = $this->event('Gala', '2030-09-01');
        $fair  = $this->event('Fair', '2030-09-02');
        $user  = self::factory()->user->create([ 'display_name' => 'Jane Booker', 'user_email' => 'jane@example.com' ]);
        $one   = $this->booking($gala, $user, 1, 'Wheelchair access please');
        $guest = $this->booking($gala, 0, 0, '', [ 'user_name' => 'Guest Person', 'user_email' => 'guest@example.org' ]);
        $this->booking($fair, $user, 3, '');

        $out = $this->read('eventsmanager-list-bookings', [ 'event_id' => $gala ]);
        $this->assertTrue($out['masked']);
        $this->assertSame(2, $out['total']);
        $rows = array_column($out['bookings'], null, 'id');
        $this->assertSame('J***', $rows[ $one ]['name']);
        $this->assertSame('j***@example.com', $rows[ $one ]['email']);
        $this->assertSame('W***', $rows[ $one ]['comment']);
        $this->assertSame('approved', $rows[ $one ]['status']);
        $this->assertSame($gala, $rows[ $one ]['event']['id']);
        $this->assertSame('G***', $rows[ $guest ]['name']);
        $this->assertSame('g***@example.org', $rows[ $guest ]['email']);
        $this->assertSame('pending', $rows[ $guest ]['status']);
        $this->assertStringNotContainsString('jane@example.com', (string) wp_json_encode($out));

        $cancelled = $this->read('eventsmanager-list-bookings', [ 'status' => 'cancelled' ]);
        $this->assertSame(1, $cancelled['total']);
        $this->assertSame($fair, $cancelled['bookings'][0]['event']['id']);
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    public function test_create_event_saves_a_draft_through_events_manager_and_rollback_session_trashes_it(): void
    {
        $out   = $this->ok($this->write('eventsmanager-create-event', [ 'title' => 'Launch party', 'start_date' => '2030-11-05', 'start_time' => '19:30', 'end_time' => '22:00', 'timezone' => 'Europe/Paris', 'content' => 'Drinks' ]));
        $event = $out['result']['event'];
        $post  = get_post($event['id']);
        $this->assertSame('event', $post->post_type);
        $this->assertSame('draft', $post->post_status, 'a new event waits until published');
        $this->assertSame('Drinks', $post->post_content);
        $this->assertSame('2030-11-05 19:30:00', $event['start']);
        $this->assertSame('2030-11-05 22:00:00', $event['end'], 'end_date defaults to start_date');
        $row = $this->em_row($post->ID);
        $this->assertSame('Launch party', $row['event_name']);
        $this->assertSame('19:30:00', $row['event_start_time']);
        $this->assertSame('Europe/Paris', $row['event_timezone']);
        $this->assertSame('0', (string) $row['location_id'], 'no venue, and no empty one created');

        $this->assertNotEmpty($out['operation_ids']);
        $this->assertGreaterThan(0, Rollback_Service::restore_session($out['session_id']));
        $this->assertSame('trash', get_post_status($post->ID));
    }

    public function test_create_event_all_day_runs_the_whole_day(): void
    {
        $event = $this->ok($this->write('eventsmanager-create-event', [ 'title' => 'Fair', 'start_date' => '2030-06-01', 'end_date' => '2030-06-02', 'all_day' => true, 'status' => 'publish' ]))['result']['event'];
        $this->assertTrue($event['all_day']);
        $this->assertSame('2030-06-01 00:00:00', $event['start']);
        $this->assertSame('2030-06-02 23:59:59', $event['end']);
        $this->assertSame('publish', $event['status']);
    }

    public function test_create_event_refuses_a_bad_schedule_before_writing(): void
    {
        $before = wp_count_posts('event');
        foreach ([
            'time_required'      => [ 'title' => 'x', 'start_date' => '2030-01-01' ],
            'date_invalid'       => [ 'title' => 'x', 'start_date' => '2030-02-30', 'all_day' => true ],
            'schedule_invalid'   => [ 'title' => 'x', 'start_date' => '2030-01-02', 'end_date' => '2030-01-01', 'all_day' => true ],
            'time_invalid'       => [ 'title' => 'x', 'start_date' => '2030-01-01', 'start_time' => '25:00' ],
            'timezone_invalid'   => [ 'title' => 'x', 'start_date' => '2030-01-01', 'all_day' => true, 'timezone' => 'Mars/Base' ],
            'location_not_found' => [ 'title' => 'x', 'start_date' => '2030-01-01', 'all_day' => true, 'location_id' => 999999 ],
        ] as $code => $args) {
            $this->assertSame($code, $this->write('eventsmanager-create-event', $args)['error']['code'] ?? null, $code);
        }
        $this->assertEquals($before, wp_count_posts('event'));
    }

    public function test_update_event_changes_only_the_given_fields_and_rollback_session_restores_post_meta_and_row(): void
    {
        $id     = $this->event('Gala', '2030-09-01');
        $post   = get_post($id);
        $meta   = get_post_meta($id);
        $row    = $this->em_row($id);

        $out = $this->ok($this->write('eventsmanager-update-event', [ 'id' => $id, 'title' => 'Winter gala', 'start_date' => '2030-12-12', 'start_time' => '17:00' ]));
        $this->assertTrue($out['recoverable']);
        $this->assertCount(2, $out['operation_ids'], 'the post and its em_events row');
        $event = $out['result']['event'];
        $this->assertSame('Winter gala', $event['title']);
        $this->assertSame('2030-12-12 17:00:00', $event['start']);
        $this->assertSame('2030-12-12 21:00:00', $event['end'], 'end moved with the start date, its time kept');
        $this->assertSame('publish', $event['status'], 'the status is kept');
        $this->assertSame('About Gala', get_post($id)->post_content, 'untouched');
        $this->assertSame('Winter gala', $this->em_row($id)['event_name']);
        $this->assertSame('2030-12-12', $this->em_row($id)['event_start_date']);

        Rollback_Service::restore_session($out['session_id']);
        $this->assertSame($post->post_title, get_post($id)->post_title);
        $this->assertSame($meta['_event_start_date'], get_post_meta($id)['_event_start_date']);
        $this->assertSame($meta['_event_start_time'], get_post_meta($id)['_event_start_time']);
        $this->assertSame($row, $this->em_row($id));
    }

    public function test_update_event_refuses_a_recurring_event_and_a_non_event(): void
    {
        $id = $this->event('Weekly class', '2030-09-01');
        update_post_meta($id, '_event_type', 'recurring');
        $this->assertSame('recurring_event', $this->write('eventsmanager-update-event', [ 'id' => $id, 'title' => 'x' ])['error']['code'] ?? null);
        $this->assertSame('Weekly class', get_post($id)->post_title);

        $page = self::factory()->post->create([ 'post_type' => 'page' ]);
        $this->assertArrayHasKey('error', $this->write('eventsmanager-update-event', [ 'id' => $page, 'title' => 'x' ]));
    }
}
