<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Listings_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/listings-stubs.php';

/**
 * Directory listings (issue #707): WP Job Manager, GeoDirectory and
 * Directorist on the plugin-data pair, free tier, so they add no top-level
 * tools.
 *
 * None of the three plugins is loaded in the shared test core. Presence is
 * driven through wpmcp_wp_job_manager_active, wpmcp_geodirectory_active and
 * wpmcp_directorist_active, their post types and taxonomies are registered
 * as the plugins register them, and GeoDirectory's detail table is built
 * from its own column list (tests/support/listings-stubs.php).
 *
 * The writes are off until the site opts in (wpmcp_enable_listings_write)
 * and each is undone in a test.
 */
class ListingsPackTest extends \WP_UnitTestCase
{
    private const PRESENCE = [ 'wpmcp_wp_job_manager_active', 'wpmcp_geodirectory_active', 'wpmcp_directorist_active' ];

    private const PREFIXES = [ 'jobmanager', 'geodirectory', 'directorist' ];

    private int $admin = 0;

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_geodirectory_table();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_geodirectory_table();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wpmcp_test_register_listing_types();
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        wpmcp_test_grant_listing_caps(get_userdata($this->admin));
        wp_set_current_user($this->admin);
        foreach (self::PRESENCE as $filter) {
            add_filter($filter, '__return_true');
        }
        add_filter(Listings_Pack::OPT_IN_FILTER, '__return_true');
    }

    protected function tearDown(): void
    {
        foreach (self::PRESENCE as $filter) {
            remove_all_filters($filter);
        }
        remove_all_filters(Listings_Pack::OPT_IN_FILTER);
        wpmcp_test_unregister_listing_types();
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
        return $out['result'] ?? $out;
    }

    private function job(array $o = []): int
    {
        $o  += [ 'title' => 'Barista', 'status' => 'publish', 'author' => $this->admin, 'meta' => [] ];
        $id  = self::factory()->post->create([ 'post_type' => 'job_listing', 'post_title' => $o['title'], 'post_status' => $o['status'], 'post_author' => $o['author'] ]);
        $o['meta'] += [ '_job_location' => 'Lahore', '_application' => 'jobs@example.com', '_company_name' => 'Bean Co', '_filled' => 0, '_featured' => 0 ];
        foreach ($o['meta'] as $key => $value) {
            update_post_meta($id, $key, $value);
        }
        return $id;
    }

    private function place(array $o = []): int
    {
        global $wpdb;
        $o  += [ 'title' => 'Cafe One', 'status' => 'publish', 'phone' => '+92 300 1234567', 'email' => 'owner@cafe.example', 'city' => 'Lahore' ];
        $id  = self::factory()->post->create([ 'post_type' => 'gd_place', 'post_title' => $o['title'], 'post_status' => $o['status'], 'post_author' => $this->admin ]);
        $wpdb->insert($wpdb->prefix . 'geodir_gd_place_detail', [ 'post_id' => $id, 'post_title' => $o['title'], '_search_title' => strtolower($o['title']), 'post_status' => $o['status'], 'city' => $o['city'], 'country' => 'Pakistan', 'phone' => $o['phone'], 'email' => $o['email'], 'website' => 'https://cafe.example', 'submit_ip' => '203.0.113.9' ]);
        return $id;
    }

    private function gd_row(int $id): array
    {
        global $wpdb;
        return (array) $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE post_id = %d', $wpdb->prefix . 'geodir_gd_place_detail', $id), ARRAY_A);
    }

    private function biz(array $o = []): int
    {
        $o  += [ 'title' => 'Plumber', 'status' => 'publish', 'author' => $this->admin ];
        $id  = self::factory()->post->create([ 'post_type' => 'at_biz_dir', 'post_title' => $o['title'], 'post_status' => $o['status'], 'post_author' => $o['author'] ]);
        update_post_meta($id, '_email', 'fix@plumb.example');
        update_post_meta($id, '_phone', '0300 7654321');
        update_post_meta($id, '_address', '1 Mall Road');
        return $id;
    }

    // ---------------------------------------------------------------
    // Catalog, presence and opt-in
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_six_ops_per_plugin_with_their_modes(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::PREFIXES as $prefix) {
            foreach ([ 'list-listings' => 'read', 'get-listing' => 'read', 'list-listing-categories' => 'read', 'create-listing' => 'write', 'update-listing' => 'write', 'set-listing-status' => 'write' ] as $op => $mode) {
                $name = $prefix . '-' . $op;
                $this->assertArrayHasKey($name, $ops);
                $this->assertSame($mode, $ops[ $name ]['mode'], $name);
                $this->assertTrue($ops[ $name ]['dependency_met'], $name);
            }
        }
    }

    public function test_a_missing_plugin_answers_plugin_missing_naming_it_with_the_plugins_link(): void
    {
        remove_all_filters('wpmcp_directorist_active');
        add_filter('wpmcp_directorist_active', '__return_false');
        $out = $this->read('directorist-list-listings');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Directorist', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
        $this->ok($this->read('jobmanager-list-listings'));
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $id = $this->job([ 'status' => 'pending' ]);
        remove_all_filters(Listings_Pack::OPT_IN_FILTER);
        foreach ([
            [ 'jobmanager-create-listing', [ 'title' => 'New' ] ],
            [ 'jobmanager-update-listing', [ 'id' => $id, 'title' => 'Changed' ] ],
            [ 'jobmanager-set-listing-status', [ 'id' => $id, 'status' => 'publish' ] ],
        ] as [ $op, $args ]) {
            $out = $this->write($op, $args);
            $this->assertSame('opt_in_required', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString(Listings_Pack::OPT_IN_FILTER, $out['error']['message']);
        }
        $this->assertSame('pending', get_post_status($id));
        $this->assertSame('Barista', get_post($id)->post_title);
    }

    // ---------------------------------------------------------------
    // WP Job Manager
    // ---------------------------------------------------------------

    public function test_job_list_masks_the_application_email_and_filters_by_status_and_category(): void
    {
        $cat = self::factory()->term->create([ 'taxonomy' => 'job_listing_category', 'name' => 'Hospitality' ]);
        $a   = $this->job([ 'title' => 'Barista' ]);
        $b   = $this->job([ 'title' => 'Chef', 'status' => 'expired' ]);
        wp_set_object_terms($a, [ $cat ], 'job_listing_category');

        $out = $this->read('jobmanager-list-listings');
        $this->assertEqualsCanonicalizing([ $a, $b ], array_column($out['listings'], 'id'));
        $this->assertTrue($out['masked']);
        $rows = array_column($out['listings'], null, 'id');
        $this->assertSame('j***@example.com', $rows[ $a ]['fields']['application']);
        $this->assertSame('Lahore', $rows[ $a ]['fields']['location']);
        $this->assertStringNotContainsString('jobs@example.com', (string) wp_json_encode($out));

        $this->assertSame([ $b ], array_column($this->read('jobmanager-list-listings', [ 'status' => 'expired' ])['listings'], 'id'));
        $this->assertSame([ $a ], array_column($this->read('jobmanager-list-listings', [ 'category' => $cat ])['listings'], 'id'));
    }

    public function test_job_get_listing_returns_the_application_in_full_to_an_administrator_and_refuses_another_type(): void
    {
        $id  = $this->job();
        $job = $this->read('jobmanager-get-listing', [ 'id' => $id ])['listing'];
        $this->assertSame('jobs@example.com', $job['fields']['application']);
        $this->assertSame('Bean Co', $job['fields']['company_name']);
        $this->assertFalse($job['fields']['filled']);

        $post = self::factory()->post->create();
        $this->assertSame('listing_not_found', $this->read('jobmanager-get-listing', [ 'id' => $post ])['error']['code'] ?? null);
    }

    public function test_job_get_listing_masks_contact_fields_for_a_reader_who_is_not_its_author(): void
    {
        $id = $this->job();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $out = $this->read('jobmanager-get-listing', [ 'id' => $id ]);
        $this->assertSame('j***@example.com', $out['listing']['fields']['application'] ?? null, (string) wp_json_encode($out));
        $this->assertTrue($out['masked']);
    }

    public function test_job_list_categories_returns_categories_and_job_types(): void
    {
        $cat  = self::factory()->term->create([ 'taxonomy' => 'job_listing_category', 'name' => 'Hospitality' ]);
        $type = self::factory()->term->create([ 'taxonomy' => 'job_listing_type', 'name' => 'Full Time' ]);
        $out  = $this->read('jobmanager-list-listing-categories');
        $this->assertSame([ $cat ], array_column($out['categories'], 'id'));
        $this->assertSame([ $type ], array_column($out['types'], 'id'));
    }

    public function test_job_create_files_it_with_fields_and_rollback_trashes_it(): void
    {
        $cat  = self::factory()->term->create([ 'taxonomy' => 'job_listing_category', 'name' => 'Hospitality' ]);
        $type = self::factory()->term->create([ 'taxonomy' => 'job_listing_type', 'name' => 'Full Time' ]);
        $out  = $this->write('jobmanager-create-listing', [ 'title' => 'Waiter', 'content' => 'Serve tables', 'categories' => [ $cat ], 'types' => [ $type ], 'fields' => [ 'location' => 'Karachi', 'application' => 'hire@example.com', 'remote' => true, 'salary' => '50000' ] ]);
        $job  = $this->ok($out)['listing'];
        $this->assertSame('draft', $job['status']);
        $this->assertSame('Karachi', get_post_meta($job['id'], '_job_location', true));
        $this->assertSame('hire@example.com', get_post_meta($job['id'], '_application', true));
        $this->assertSame('1', get_post_meta($job['id'], '_remote_position', true));
        $this->assertSame('0', get_post_meta($job['id'], '_filled', true));
        $this->assertSame([ $cat ], wp_get_object_terms($job['id'], 'job_listing_category', [ 'fields' => 'ids' ]));
        $this->assertSame([ $type ], wp_get_object_terms($job['id'], 'job_listing_type', [ 'fields' => 'ids' ]));

        $this->assertTrue(Rollback_Service::restore_session((string) $out['session_id']) > 0);
        $this->assertSame('trash', get_post_status($job['id']));
    }

    public function test_job_update_changes_fields_and_terms_and_rollback_puts_them_back(): void
    {
        $old = self::factory()->term->create([ 'taxonomy' => 'job_listing_category', 'name' => 'Old' ]);
        $new = self::factory()->term->create([ 'taxonomy' => 'job_listing_category', 'name' => 'New' ]);
        $id  = $this->job();
        wp_set_object_terms($id, [ $old ], 'job_listing_category');

        $out = $this->write('jobmanager-update-listing', [ 'id' => $id, 'title' => 'Head Barista', 'categories' => [ $new ], 'fields' => [ 'filled' => true, 'location' => 'Remote' ] ]);
        $this->assertSame('Head Barista', $this->ok($out)['listing']['title']);
        $this->assertSame('1', get_post_meta($id, '_filled', true));
        $this->assertSame('Remote', get_post_meta($id, '_job_location', true));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('Barista', get_post($id)->post_title);
        $this->assertSame('0', get_post_meta($id, '_filled', true));
        $this->assertSame('Lahore', get_post_meta($id, '_job_location', true));
        $this->assertSame([ $old ], wp_get_object_terms($id, 'job_listing_category', [ 'fields' => 'ids' ]));
    }

    public function test_job_update_refuses_an_unknown_field_and_featured_without_manage_job_listings(): void
    {
        $id  = $this->job();
        $out = $this->write('jobmanager-update-listing', [ 'id' => $id, 'fields' => [ 'nope' => 'x' ] ]);
        $this->assertSame('unknown_field', $out['error']['code'] ?? null, (string) wp_json_encode($out));

        get_userdata($this->admin)->remove_cap('manage_job_listings');
        wp_set_current_user(0);
        wp_set_current_user($this->admin);
        $out = $this->write('jobmanager-update-listing', [ 'id' => $id, 'fields' => [ 'featured' => true ] ]);
        $this->assertSame('operation_denied', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertSame('0', get_post_meta($id, '_featured', true));
    }

    public function test_job_set_status_expires_it_and_rollback_republishes_it(): void
    {
        $id  = $this->job();
        $out = $this->write('jobmanager-set-listing-status', [ 'id' => $id, 'status' => 'expired' ]);
        $this->ok($out);
        $this->assertSame('expired', get_post_status($id));
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('publish', get_post_status($id));
    }

    public function test_a_user_without_the_job_listing_capabilities_cannot_create_or_edit(): void
    {
        $id = $this->job();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'author' ]));
        $this->assertSame('operation_denied', $this->write('jobmanager-create-listing', [ 'title' => 'X' ])['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->write('jobmanager-update-listing', [ 'id' => $id, 'title' => 'X' ])['error']['code'] ?? null);
        $this->assertSame('Barista', get_post($id)->post_title);
    }

    // ---------------------------------------------------------------
    // GeoDirectory
    // ---------------------------------------------------------------

    public function test_gd_list_reads_the_detail_table_and_masks_phone_and_email_never_the_submit_ip(): void
    {
        $a   = $this->place();
        $out = $this->read('geodirectory-list-listings');
        $this->assertSame([ $a ], array_column($out['listings'], 'id'));
        $row = $out['listings'][0];
        $this->assertSame('Lahore', $row['details']['city']);
        $this->assertSame('o***@cafe.example', $row['details']['email']);
        $this->assertSame('+***', $row['details']['phone']);
        $this->assertArrayNotHasKey('submit_ip', $row['details']);
        $this->assertStringNotContainsString('203.0.113.9', (string) wp_json_encode($out));

        $full = $this->read('geodirectory-get-listing', [ 'id' => $a ])['listing'];
        $this->assertSame('owner@cafe.example', $full['details']['email']);
        $this->assertSame('gd_place', $full['post_type']);
    }

    public function test_gd_update_syncs_the_detail_row_and_rollback_syncs_it_back(): void
    {
        $old = self::factory()->term->create([ 'taxonomy' => 'gd_placecategory', 'name' => 'Cafes' ]);
        $new = self::factory()->term->create([ 'taxonomy' => 'gd_placecategory', 'name' => 'Bakeries' ]);
        $id  = $this->place();
        wp_set_object_terms($id, [ $old ], 'gd_placecategory');

        $out = $this->write('geodirectory-update-listing', [ 'id' => $id, 'title' => 'Cafe Two', 'categories' => [ $new ], 'tags' => [ 'wifi' ] ]);
        $this->ok($out);
        $row = $this->gd_row($id);
        $this->assertSame('Cafe Two', $row['post_title']);
        $this->assertSame(',' . $new . ',', $row['post_category']);
        $this->assertSame((string) $new, (string) $row['default_category']);
        $this->assertSame('wifi', $row['post_tags']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $row = $this->gd_row($id);
        $this->assertSame('Cafe One', get_post($id)->post_title);
        $this->assertSame('Cafe One', $row['post_title']);
        $this->assertSame(',' . $old . ',', $row['post_category']);
        $this->assertSame('', (string) $row['post_tags']);
    }

    public function test_gd_set_status_syncs_the_detail_row_status(): void
    {
        $id  = $this->place();
        $out = $this->write('geodirectory-set-listing-status', [ 'id' => $id, 'status' => 'pending' ]);
        $this->ok($out);
        $this->assertSame('pending', $this->gd_row($id)['post_status']);
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('publish', get_post_status($id));
        $this->assertSame('publish', $this->gd_row($id)['post_status']);
    }

    public function test_gd_create_refuses_a_post_type_that_is_not_a_geodirectory_type(): void
    {
        $out = $this->write('geodirectory-create-listing', [ 'title' => 'X', 'post_type' => 'page' ]);
        $this->assertSame('unknown_post_type', $out['error']['code'] ?? null, (string) wp_json_encode($out));
    }

    public function test_gd_create_files_a_draft_and_rollback_trashes_it(): void
    {
        $cat = self::factory()->term->create([ 'taxonomy' => 'gd_placecategory', 'name' => 'Cafes' ]);
        $out = $this->write('geodirectory-create-listing', [ 'title' => 'Cafe Three', 'categories' => [ $cat ] ]);
        $new = $this->ok($out)['listing'];
        $this->assertSame('gd_place', get_post_type($new['id']));
        $this->assertSame([ $cat ], wp_get_object_terms($new['id'], 'gd_placecategory', [ 'fields' => 'ids' ]));
        $this->assertTrue(Rollback_Service::restore_session((string) $out['session_id']) > 0);
        $this->assertSame('trash', get_post_status($new['id']));
    }

    // ---------------------------------------------------------------
    // Directorist
    // ---------------------------------------------------------------

    public function test_directorist_list_masks_email_and_phone_and_get_returns_them(): void
    {
        $id  = $this->biz();
        $out = $this->read('directorist-list-listings');
        $row = $out['listings'][0];
        $this->assertSame('f***@plumb.example', $row['fields']['email']);
        $this->assertSame('0***', $row['fields']['phone']);
        $this->assertSame('1 Mall Road', $row['fields']['address']);
        $this->assertSame('fix@plumb.example', $this->read('directorist-get-listing', [ 'id' => $id ])['listing']['fields']['email']);
    }

    public function test_directorist_create_sets_the_directory_type_and_locations_and_rollback_trashes_it(): void
    {
        $dir = self::factory()->term->create([ 'taxonomy' => 'atbdp_listing_types', 'name' => 'General' ]);
        $loc = self::factory()->term->create([ 'taxonomy' => 'at_biz_dir-location', 'name' => 'Lahore' ]);
        $out = $this->write('directorist-create-listing', [ 'title' => 'Electrician', 'directory_type' => $dir, 'locations' => [ $loc ], 'tags' => [ 'repairs' ], 'fields' => [ 'phone' => '0300 1112223', 'email' => 'spark@example.com', 'never_expire' => true ] ]);
        $new = $this->ok($out)['listing'];
        $this->assertSame((string) $dir, (string) get_post_meta($new['id'], '_directory_type', true));
        $this->assertSame([ $dir ], wp_get_object_terms($new['id'], 'atbdp_listing_types', [ 'fields' => 'ids' ]));
        $this->assertSame([ $loc ], wp_get_object_terms($new['id'], 'at_biz_dir-location', [ 'fields' => 'ids' ]));
        $this->assertSame('spark@example.com', get_post_meta($new['id'], '_email', true));
        $this->assertSame('1', get_post_meta($new['id'], '_never_expire', true));
        $this->assertSame($dir, $new['directory_type']);

        $this->assertTrue(Rollback_Service::restore_session((string) $out['session_id']) > 0);
        $this->assertSame('trash', get_post_status($new['id']));
    }

    public function test_directorist_create_refuses_an_unknown_directory_type_and_an_invalid_email(): void
    {
        $this->assertSame('unknown_directory_type', $this->write('directorist-create-listing', [ 'title' => 'X', 'directory_type' => 999999 ])['error']['code'] ?? null);
        $this->assertSame('invalid_field', $this->write('directorist-create-listing', [ 'title' => 'X', 'fields' => [ 'email' => 'not an email' ] ])['error']['code'] ?? null);
    }

    public function test_directorist_update_and_rollback_put_the_contact_fields_back(): void
    {
        $id  = $this->biz();
        $out = $this->write('directorist-update-listing', [ 'id' => $id, 'fields' => [ 'phone' => '0311 0000000', 'featured' => true ] ]);
        $this->ok($out);
        $this->assertSame('0311 0000000', get_post_meta($id, '_phone', true));
        $this->assertSame('1', get_post_meta($id, '_featured', true));
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('0300 7654321', get_post_meta($id, '_phone', true));
        $this->assertSame('', get_post_meta($id, '_featured', true));
    }

    public function test_a_trashed_listing_is_refused_as_trashed(): void
    {
        $id = $this->biz([ 'status' => 'trash' ]);
        $this->assertSame('listing_trashed', $this->read('directorist-get-listing', [ 'id' => $id ])['error']['code'] ?? null);
        $this->assertSame('listing_trashed', $this->write('directorist-set-listing-status', [ 'id' => $id, 'status' => 'publish' ])['error']['code'] ?? null);
    }
}
