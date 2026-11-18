<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Modern_Events_Calendar_Integration;
use WPMCP\Integrations\Modern_Events_Calendar_Writes;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Modern Events Calendar writes and lookups on the mec pair (issue #677),
 * against MEC's own storage: mec-events posts with mec_* meta, the
 * mec_location, mec_organizer and mec_category taxonomies with their term
 * meta, and its mec_events and mec_dates tables (created here with MEC's
 * columns).
 */
class ModernEventsCalendarWritesTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        Snapshot_Store::install();
        register_post_type('mec-events', [ 'public' => true, 'label' => 'Events', 'supports' => [ 'title', 'editor', 'excerpt' ] ]);
        foreach ([ 'mec_location', 'mec_organizer', 'mec_category' ] as $taxonomy) {
            register_taxonomy($taxonomy, 'mec-events', [ 'public' => true, 'hierarchical' => 'mec_category' === $taxonomy ]);
        }
        // phpcs:disable WordPress.DB.DirectDatabaseQuery
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}mec_events (id int(10) NOT NULL AUTO_INCREMENT, post_id int(10) NOT NULL, start date NOT NULL, end date NOT NULL, `repeat` tinyint(4) NOT NULL DEFAULT 0, rinterval varchar(10) DEFAULT NULL, year varchar(80) DEFAULT NULL, month varchar(80) DEFAULT NULL, day varchar(80) DEFAULT NULL, week varchar(80) DEFAULT NULL, weekday varchar(80) DEFAULT NULL, weekdays varchar(80) DEFAULT NULL, days text NOT NULL, not_in_days text NOT NULL, time_start int(10) NOT NULL DEFAULT 0, time_end int(10) NOT NULL DEFAULT 0, PRIMARY KEY (id), UNIQUE KEY post_id (post_id))");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}mec_dates (id int(10) unsigned NOT NULL AUTO_INCREMENT, post_id int(10) NOT NULL, dstart date NOT NULL, dend date NOT NULL, tstart int(11) unsigned NOT NULL DEFAULT 0, tend int(11) unsigned NOT NULL DEFAULT 0, public int(4) unsigned NOT NULL DEFAULT 1, PRIMARY KEY (id), KEY post_id (post_id))");
        // phpcs:enable
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        add_filter('wpmcp_enable_mec_write', '__return_true');
        add_action('wpmcp_rollback_post_restored', [ Modern_Events_Calendar_Writes::class, 'after_post_restored' ]);
    }

    protected function tearDown(): void
    {
        remove_action('wpmcp_rollback_post_restored', [ Modern_Events_Calendar_Writes::class, 'after_post_restored' ]);
        remove_all_filters('wpmcp_enable_mec_write');
        foreach ([ 'mec_location', 'mec_organizer', 'mec_category' ] as $taxonomy) {
            unregister_taxonomy($taxonomy);
        }
        unregister_post_type('mec-events');
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Modern_Events_Calendar_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args): array
    {
        return (new Modern_Events_Calendar_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    /** @return array<string, mixed> The event's mec_events and mec_dates rows. */
    private function rows(int $id): array
    {
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery
        return [
            'event' => $wpdb->get_row($wpdb->prepare("SELECT start, end, `repeat`, time_start, time_end FROM {$wpdb->prefix}mec_events WHERE post_id = %d", $id), ARRAY_A),
            'dates' => $wpdb->get_results($wpdb->prepare("SELECT dstart, dend, tstart, tend FROM {$wpdb->prefix}mec_dates WHERE post_id = %d", $id), ARRAY_A),
        ];
        // phpcs:enable
    }

    private function create(array $args = []): array
    {
        return $this->ok($this->write('create-event', $args + [ 'title' => 'Jazz Night', 'start_date' => '2026-07-04', 'start_time' => '19:30', 'end_date' => '2026-07-04', 'end_time' => '22:00' ]));
    }

    public function test_the_catalog_lists_every_new_op_with_its_mode(): void
    {
        $ops = array_column($this->ok($this->read('list-operations'))['result']['operations'], 'mode', 'name');
        foreach ([ 'list-locations', 'list-organizers', 'list-categories' ] as $op) {
            $this->assertSame('read', $ops[ $op ] ?? null, $op);
        }
        foreach ([ 'create-event', 'update-event', 'cancel-event', 'create-location', 'create-organizer' ] as $op) {
            $this->assertSame('write', $ops[ $op ] ?? null, $op);
        }
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        remove_all_filters('wpmcp_enable_mec_write');
        $out = $this->write('create-location', [ 'name' => 'Hall' ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null);
        $this->assertStringContainsString('wpmcp_enable_mec_write', (string) wp_json_encode($out));
    }

    public function test_a_call_while_the_plugin_is_missing_names_it(): void
    {
        unregister_post_type('mec-events');
        $out = $this->read('list-locations');
        $this->assertContains($out['error']['code'] ?? null, [ 'plugin_missing', 'plugin_inactive' ]);
        $this->assertStringContainsString('Modern Events Calendar', $out['error']['message']);
    }

    public function test_list_locations_organizers_and_categories(): void
    {
        $loc = (int) wp_insert_term('Civic Hall', 'mec_location')['term_id'];
        update_term_meta($loc, 'address', '1 Main St');
        $org = (int) wp_insert_term('Arts Council', 'mec_organizer')['term_id'];
        update_term_meta($org, 'email', 'jane@example.com');
        update_term_meta($org, 'tel', '555-0100');
        wp_insert_term('Music', 'mec_category');

        $locations = $this->ok($this->read('list-locations'))['result'];
        $this->assertSame('1 Main St', $locations['locations'][0]['address']);
        $organizers = $this->ok($this->read('list-organizers'))['result'];
        $this->assertSame('j***@example.com', $organizers['organizers'][0]['email'], 'lists mask personal data');
        $this->assertSame('5***', $organizers['organizers'][0]['phone']);
        $this->assertTrue($organizers['masked']);
        $this->assertSame([ 'Music' ], array_column($this->ok($this->read('list-categories'))['result']['categories'], 'name'));
    }

    public function test_create_event_writes_mec_meta_and_tables_as_a_draft_and_rollback_trashes_it(): void
    {
        $loc  = (int) wp_insert_term('Civic Hall', 'mec_location')['term_id'];
        $org  = (int) wp_insert_term('Arts Council', 'mec_organizer')['term_id'];
        $cat  = (int) wp_insert_term('Music', 'mec_category')['term_id'];
        $out  = $this->create([ 'location_id' => $loc, 'organizer_id' => $org, 'category_ids' => [ $cat ], 'cost' => '15', 'url' => 'https://example.com/jazz' ]);
        $id   = $out['result']['event']['id'];

        $this->assertSame('mec-events', get_post_type($id));
        $this->assertSame('draft', get_post_status($id));
        $this->assertSame('2026-07-04', get_post_meta($id, 'mec_start_date', true));
        $this->assertSame('7', get_post_meta($id, 'mec_start_time_hour', true));
        $this->assertSame('30', get_post_meta($id, 'mec_start_time_minutes', true));
        $this->assertSame('PM', get_post_meta($id, 'mec_start_time_ampm', true));
        $this->assertSame('70200', (string) get_post_meta($id, 'mec_start_day_seconds', true));
        $this->assertSame('10', get_post_meta($id, 'mec_end_time_hour', true));
        $this->assertSame('2026-07-04 07:30 PM', get_post_meta($id, 'mec_start_datetime', true));
        $this->assertSame('0', (string) get_post_meta($id, 'mec_repeat_status', true));
        $this->assertSame((string) $loc, (string) get_post_meta($id, 'mec_location_id', true));
        $this->assertSame((string) $org, (string) get_post_meta($id, 'mec_organizer_id', true));
        $this->assertSame([ $cat ], wp_get_object_terms($id, 'mec_category', [ 'fields' => 'ids' ]));
        $this->assertSame('15', get_post_meta($id, 'mec_cost', true));
        $this->assertSame('https://example.com/jazz', get_post_meta($id, 'mec_read_more', true));
        $date = get_post_meta($id, 'mec_date', true);
        $this->assertSame('2026-07-04', $date['start']['date']);
        $this->assertSame('PM', $date['end']['ampm']);
        $this->assertSame('19:30', $out['result']['event']['start_time']);

        $rows = $this->rows($id);
        $this->assertSame([ 'start' => '2026-07-04', 'end' => '2026-07-04', 'repeat' => '0', 'time_start' => '70200', 'time_end' => '79200' ], $rows['event']);
        $this->assertCount(1, $rows['dates']);
        $this->assertSame((string) gmmktime(19, 30, 0, 7, 4, 2026), $rows['dates'][0]['tstart']);
        $this->assertSame((string) gmmktime(22, 0, 0, 7, 4, 2026), $rows['dates'][0]['tend']);

        foreach ((array) $out['operation_ids'] as $operation) {
            Rollback_Service::restore_operation((string) $operation);
        }
        $this->assertSame('trash', get_post_status($id));
    }

    public function test_create_event_all_day_and_refusals(): void
    {
        $out = $this->create([ 'title' => 'Fair', 'start_date' => '2026-08-01', 'end_date' => '2026-08-02', 'all_day' => true ]);
        $id  = $out['result']['event']['id'];
        $this->assertSame('1', (string) get_post_meta($id, 'mec_allday', true));
        $this->assertSame((string) (gmmktime(0, 1, 0, 8, 1, 2026)), $this->rows($id)['dates'][0]['tstart']);

        $base  = [ 'title' => 'x', 'start_date' => '2026-07-04', 'start_time' => '19:00', 'end_date' => '2026-07-04', 'end_time' => '20:00' ];
        $cases = [
            'schedule_invalid'    => [ 'end_time' => '18:00' ],
            'date_invalid'        => [ 'start_date' => '2026-02-30' ],
            'location_not_found'  => [ 'location_id' => 999999 ],
            'organizer_not_found' => [ 'organizer_id' => 999999 ],
            'url_invalid'         => [ 'url' => 'javascript:alert(1)' ],
        ];
        foreach ($cases as $code => $args) {
            $out = $this->write('create-event', $args + $base);
            $this->assertSame($code, $out['error']['code'] ?? null, $code . ': ' . wp_json_encode($out));
        }
        $missing = $base;
        unset($missing['start_time']);
        $this->assertSame('date_invalid', $this->write('create-event', $missing)['error']['code'] ?? null, 'a timed event needs its times');
    }

    public function test_update_event_moves_the_schedule_and_rollback_puts_meta_and_tables_back(): void
    {
        $id     = $this->create([ 'cost' => '10' ])['result']['event']['id'];
        wp_publish_post($id);
        $before = [ get_post($id)->post_title, get_post_meta($id), $this->rows($id) ];

        $out = $this->ok($this->write('update-event', [ 'event_id' => $id, 'title' => 'Late Jazz', 'start_time' => '21:00', 'end_date' => '2026-07-05', 'end_time' => '01:00' ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('Late Jazz', get_post($id)->post_title);
        $this->assertSame('9', get_post_meta($id, 'mec_start_time_hour', true));
        $this->assertSame('2026-07-04', get_post_meta($id, 'mec_start_date', true), 'kept');
        $this->assertSame('AM', get_post_meta($id, 'mec_end_time_ampm', true));
        $this->assertSame('10', get_post_meta($id, 'mec_cost', true), 'kept');
        $this->assertSame('2026-07-05', $this->rows($id)['event']['end']);
        $this->assertSame((string) gmmktime(1, 0, 0, 7, 5, 2026), $this->rows($id)['dates'][0]['tend']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, [ get_post($id)->post_title, get_post_meta($id), $this->rows($id) ], 'the undo rebuilds MEC tables from the restored meta');
    }

    public function test_update_event_refuses_to_move_a_repeating_event_but_edits_its_other_fields(): void
    {
        $id = $this->create()['result']['event']['id'];
        update_post_meta($id, 'mec_repeat_status', '1');
        $this->assertSame('recurring_event', $this->write('update-event', [ 'event_id' => $id, 'start_date' => '2026-07-10' ])['error']['code'] ?? null);
        $this->ok($this->write('update-event', [ 'event_id' => $id, 'cost' => '20' ]));
        $this->assertSame('20', get_post_meta($id, 'mec_cost', true));
    }

    public function test_cancel_event_sets_the_mec_event_status_and_rollback_clears_it(): void
    {
        $id  = $this->create()['result']['event']['id'];
        $out = $this->ok($this->write('cancel-event', [ 'event_id' => $id, 'reason' => 'Storm warning' ]));
        $this->assertSame('EventCancelled', get_post_meta($id, 'mec_event_status', true));
        $this->assertSame('Storm warning', get_post_meta($id, 'mec_cancelled_reason', true));
        $this->assertSame('EventCancelled', $this->ok($this->read('get-event', [ 'event_id' => $id ]))['result']['event']['event_status']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('', get_post_meta($id, 'mec_event_status', true));
        $this->assertSame('event_not_found', $this->write('cancel-event', [ 'event_id' => self::factory()->post->create() ])['error']['code'] ?? null);
    }

    public function test_create_location_and_organizer_store_term_meta_and_rollback_deletes_them(): void
    {
        $loc = $this->ok($this->write('create-location', [ 'name' => 'Riverfront Park', 'address' => '1 Main St', 'latitude' => 42.96, 'longitude' => -85.67, 'url' => 'https://example.com/park' ]));
        $lid = $loc['result']['location']['id'];
        $this->assertSame('1 Main St', get_term_meta($lid, 'address', true));
        $this->assertSame('42.96', get_term_meta($lid, 'latitude', true));
        $this->assertSame('location_exists', $this->write('create-location', [ 'name' => 'Riverfront Park' ])['error']['code'] ?? null);

        $org = $this->ok($this->write('create-organizer', [ 'name' => 'City Arts', 'email' => 'info@example.com', 'phone' => '555-0199' ]));
        $oid = $org['result']['organizer']['id'];
        $this->assertSame('info@example.com', get_term_meta($oid, 'email', true));
        $this->assertSame('555-0199', get_term_meta($oid, 'tel', true));
        $this->assertSame('email_invalid', $this->write('create-organizer', [ 'name' => 'Other', 'email' => 'nope' ])['error']['code'] ?? null);

        $this->assertTrue(Rollback_Service::restore_operation($loc['operation_id']));
        $this->assertTrue(Rollback_Service::restore_operation($org['operation_id']));
        $this->assertNull(get_term($lid, 'mec_location'));
        $this->assertNull(get_term($oid, 'mec_organizer'));
    }
}
