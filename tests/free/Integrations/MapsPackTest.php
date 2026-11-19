<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Maps_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/maps-doubles.php';

/**
 * Maps and store locators (issue #907): WP Go Maps, WP Store Locator and
 * WP Maps ops on the plugin-data pair, so they add no top-level tools.
 *
 * None of the three plugins is loaded in the shared test core. Presence is
 * driven through wpmcp_wp_go_maps_active, wpmcp_store_locator_active and
 * wpmcp_wp_maps_active; the tables are the plugins' own DDL
 * (tests/support/maps-doubles.php) and WP Store Locator's post type,
 * taxonomy and capabilities are registered here the way its
 * includes/core/post-types/class-register.php does (capability_type store,
 * map_meta_cap). Its geocode service is doubled behind wpsl_get_service().
 *
 * Writes are off until wpmcp_enable_maps_write and each is undone in a test.
 */
class MapsPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'wpgomaps-list-maps'      => 'read',
        'wpgomaps-list-markers'   => 'read',
        'wpgomaps-save-markers'   => 'write',
        'wpgomaps-delete-markers' => 'write',
        'wpsl-list-stores'        => 'read',
        'wpsl-get-store'          => 'read',
        'wpsl-get-settings'       => 'read',
        'wpsl-save-stores'        => 'write',
        'wpsl-delete-stores'      => 'write',
        'wpmaps-list-maps'        => 'read',
        'wpmaps-list-locations'   => 'read',
    ];

    private const STORE_CAPS = [ 'edit_stores', 'edit_others_stores', 'publish_stores', 'read_private_stores', 'delete_stores', 'delete_others_stores', 'delete_published_stores', 'delete_private_stores', 'edit_published_stores', 'edit_private_stores' ];

    private const KEY = 'AIzaSECRET-KEY-0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wpmcp_test_create_map_tables();
        \WPMCP_Test_WPSL_Services::reset();
        register_post_type('wpsl_stores', [ 'public' => true, 'capability_type' => 'store', 'map_meta_cap' => true, 'supports' => [ 'title', 'editor', 'author' ] ]);
        register_taxonomy('wpsl_store_category', 'wpsl_stores', [ 'hierarchical' => true ]);
        $role = get_role('administrator');
        foreach (self::STORE_CAPS as $cap) {
            $role->add_cap($cap);
        }
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        foreach ([ 'wpmcp_wp_go_maps_active', 'wpmcp_store_locator_active', 'wpmcp_wp_maps_active', 'wpmcp_enable_maps_write' ] as $filter) {
            add_filter($filter, '__return_true');
        }
    }

    protected function tearDown(): void
    {
        foreach ([ 'wpmcp_wp_go_maps_active', 'wpmcp_store_locator_active', 'wpmcp_wp_maps_active', 'wpmcp_enable_maps_write', 'pre_http_request' ] as $filter) {
            remove_all_filters($filter);
        }
        $role = get_role('administrator');
        foreach (self::STORE_CAPS as $cap) {
            $role->remove_cap($cap);
        }
        unregister_taxonomy('wpsl_store_category');
        unregister_post_type('wpsl_stores');
        wpmcp_test_drop_map_tables();
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

    /** Answer Google geocoding requests with $body, counting the calls and keeping the URLs. */
    private function google(array $body, array &$urls): void
    {
        add_filter('pre_http_request', static function ($pre, $args, $url) use ($body, &$urls) {
            if (false === strpos((string) $url, 'maps.googleapis.com/maps/api/geocode')) {
                return $pre;
            }
            $urls[] = (string) $url;
            return [ 'headers' => [], 'body' => wp_json_encode($body), 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [] ];
        }, 10, 3);
    }

    /** A store as WP Store Locator's editor saves one. */
    private function store(string $title = 'Amsterdam', array $meta = []): int
    {
        $id = self::factory()->post->create([ 'post_type' => 'wpsl_stores', 'post_title' => $title, 'post_status' => 'publish', 'post_content' => 'Our flagship.' ]);
        foreach ($meta + [ 'address' => 'Dam 1', 'city' => 'Amsterdam', 'zip' => '1012 JS', 'country' => 'Netherlands', 'lat' => '52.373', 'lng' => '4.893', 'phone' => '+31 20 555 0101', 'email' => 'dam@shop.example.com', 'url' => 'https://shop.example.com/dam' ] as $field => $value) {
            update_post_meta($id, 'wpsl_' . $field, $value);
        }
        return $id;
    }

    // ---------------------------------------------------------------
    // Catalog, presence, opt-in, capabilities
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

    public function test_a_missing_plugin_is_named_in_the_refusal(): void
    {
        foreach ([ [ 'wpmcp_wp_go_maps_active', 'wpgomaps-list-maps', 'WP Go Maps' ], [ 'wpmcp_store_locator_active', 'wpsl-list-stores', 'WP Store Locator' ], [ 'wpmcp_wp_maps_active', 'wpmaps-list-maps', 'WP Maps' ] ] as [ $filter, $op, $name ]) {
            remove_all_filters($filter);
            $out = $this->read($op);
            $this->assertArrayHasKey('error', $out, $op);
            $this->assertStringContainsString('Install and activate ' . $name, $out['error']['message'], $op);
            add_filter($filter, '__return_true');
        }
    }

    public function test_presence_defaults_to_the_plugin_constants(): void
    {
        foreach ([ 'wpmcp_wp_go_maps_active', 'wpmcp_store_locator_active', 'wpmcp_wp_maps_active' ] as $filter) {
            remove_all_filters($filter);
        }
        $this->assertSame(defined('WPGMZA_VERSION'), Maps_Pack::go_maps_active());
        $this->assertSame(defined('WPSL_VERSION_NUM'), Maps_Pack::store_locator_active());
        $this->assertSame(defined('WPGMP_VERSION'), Maps_Pack::wp_maps_active());
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $map    = wpmcp_test_go_map();
        $marker = wpmcp_test_go_marker([ 'map_id' => $map ]);
        $store  = $this->store();
        remove_all_filters('wpmcp_enable_maps_write');
        foreach ([
            [ 'wpgomaps-save-markers', [ 'markers' => [ [ 'id' => $marker, 'title' => 'x' ] ] ] ],
            [ 'wpgomaps-delete-markers', [ 'ids' => [ $marker ] ] ],
            [ 'wpsl-save-stores', [ 'stores' => [ [ 'id' => $store, 'title' => 'x' ] ] ] ],
            [ 'wpsl-delete-stores', [ 'ids' => [ $store ] ] ],
        ] as [ $op, $args ]) {
            $out = $this->write($op, $args);
            $this->assertSame('opt_in_required', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString('wpmcp_enable_maps_write', $out['error']['message']);
        }
        $this->assertSame('Head office', wpmcp_test_go_marker_row($marker)['title']);
        $this->assertSame('Amsterdam', get_post($store)->post_title);
    }

    public function test_go_maps_take_manage_options_and_stores_take_edit_stores(): void
    {
        wpmcp_test_go_map();
        $store = $this->store();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertSame('operation_denied', $this->read('wpgomaps-list-markers')['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->read('wpmaps-list-locations')['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->read('wpsl-list-stores')['error']['code'] ?? null, 'an editor lacks edit_stores');
        $this->assertSame('operation_denied', $this->read('wpsl-get-store', [ 'id' => $store ])['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // WP Go Maps
    // ---------------------------------------------------------------

    public function test_list_maps_counts_markers_drops_keys_and_finds_embeds(): void
    {
        $map   = wpmcp_test_go_map();
        $other = wpmcp_test_go_map([ 'map_title' => 'Trashed', 'active' => 1 ]);
        wpmcp_test_go_marker([ 'map_id' => $map ]);
        wpmcp_test_go_marker([ 'map_id' => $map, 'title' => 'Depot' ]);
        $page  = self::factory()->post->create([ 'post_type' => 'page', 'post_title' => 'Find us', 'post_content' => 'Visit [wpgmza id="' . $map . '"] today' ]);
        $block = self::factory()->post->create([ 'post_type' => 'page', 'post_title' => 'Contact', 'post_content' => '<!-- wp:gutenberg-wpgmza/map {"id":"' . $map . '"} /-->' ]);

        $maps = $this->read('wpgomaps-list-maps')['maps'];
        $this->assertSame([ $map ], array_column($maps, 'id'), 'a trashed map (active 1) is left out');
        $this->assertNotContains($other, array_column($maps, 'id'));
        $this->assertSame(2, $maps[0]['marker_count']);
        $this->assertSame('Our locations', $maps[0]['title']);
        $this->assertSame(10, $maps[0]['zoom']);
        $this->assertSame(1, $maps[0]['settings']['store_locator_enabled']);
        $this->assertArrayNotHasKey('wpgmza_api_key', $maps[0]['settings']);
        $this->assertStringNotContainsString('MAP-SECRET', (string) wp_json_encode($maps));
        $this->assertEqualsCanonicalizing([ $page, $block ], array_column($maps[0]['embedded_in'], 'id'));
    }

    public function test_list_markers_filters_by_map_and_search(): void
    {
        $a = wpmcp_test_go_map();
        $b = wpmcp_test_go_map([ 'map_title' => 'Second' ]);
        $one = wpmcp_test_go_marker([ 'map_id' => $a ]);
        wpmcp_test_go_marker([ 'map_id' => $a, 'title' => 'Depot', 'address' => '9 Dock Rd, Leeds' ]);
        wpmcp_test_go_marker([ 'map_id' => $b, 'title' => 'Paris', 'address' => 'Rue 1' ]);

        $out = $this->read('wpgomaps-list-markers', [ 'map_id' => $a ]);
        $this->assertSame(2, $out['total']);
        $this->assertSame($one, $out['markers'][0]['id']);
        $this->assertSame('51.5', $out['markers'][0]['lat']);
        $this->assertArrayNotHasKey('description', $out['markers'][0]);
        $this->assertSame([ 'Depot' ], array_column($this->read('wpgomaps-list-markers', [ 'search' => 'Leeds' ])['markers'], 'title'));
    }

    public function test_save_markers_creates_with_coordinates_and_rollback_session_removes_it(): void
    {
        $map = wpmcp_test_go_map();
        $out = $this->ok($this->write('wpgomaps-save-markers', [ 'markers' => [ [ 'map_id' => $map, 'title' => 'Shop', 'address' => '5 Kings Rd', 'lat' => 51.4875, 'lng' => -0.1687, 'link' => 'https://shop.example.com' ] ] ]));
        $row = $out['result']['results'][0];
        $this->assertTrue($row['ok'], (string) wp_json_encode($row));
        $this->assertTrue($row['created']);
        $this->assertFalse($row['geocoded']);
        $id = $row['marker']['id'];
        $this->assertSame('POINT(51.4875 -0.1687)', wpmcp_test_go_marker_point($id), 'latlng is written as WP Go Maps writes it');
        $this->assertSame('51.4875', wpmcp_test_go_marker_row($id)['lat']);

        $this->assertGreaterThan(0, Rollback_Service::restore_session($out['session_id']));
        $this->assertNull(wpmcp_test_go_marker_row($id));
    }

    public function test_save_markers_updates_only_the_fields_given_and_rolls_back_byte_for_byte(): void
    {
        $map    = wpmcp_test_go_map();
        $id     = wpmcp_test_go_marker([ 'map_id' => $map, 'category' => '3' ]);
        $before = wpmcp_test_go_marker_row($id);

        $out = $this->ok($this->write('wpgomaps-save-markers', [ 'markers' => [ [ 'id' => $id, 'title' => 'Head office (moved)', 'lat' => 51.52, 'lng' => -0.08 ] ] ]));
        $this->assertTrue($out['result']['results'][0]['ok']);
        $after = wpmcp_test_go_marker_row($id);
        $this->assertSame('Head office (moved)', $after['title']);
        $this->assertSame('1 High St, London', $after['address'], 'untouched');
        $this->assertSame('3', $after['category']);
        $this->assertSame('POINT(51.52 -0.08)', wpmcp_test_go_marker_point($id));

        Rollback_Service::restore_session($out['session_id']);
        $this->assertSame($before, wpmcp_test_go_marker_row($id), 'every column, latlng included, back as it was');
    }

    public function test_save_markers_geocodes_an_address_with_the_site_key_and_never_returns_it(): void
    {
        $map = wpmcp_test_go_map();
        update_option('wpgmza_google_maps_api_key', self::KEY);
        $urls = [];
        $this->google([ 'status' => 'OK', 'results' => [ [ 'geometry' => [ 'location' => [ 'lat' => 53.4808, 'lng' => -2.2426 ] ] ] ] ], $urls);

        $out = $this->ok($this->write('wpgomaps-save-markers', [ 'markers' => [ [ 'map_id' => $map, 'title' => 'Manchester', 'address' => '1 Market St, Manchester' ] ] ]));
        $row = $out['result']['results'][0];
        $this->assertTrue($row['ok'], (string) wp_json_encode($row));
        $this->assertTrue($row['geocoded']);
        $this->assertSame('53.4808', $row['marker']['lat']);
        $this->assertSame('POINT(53.4808 -2.2426)', wpmcp_test_go_marker_point($row['marker']['id']));
        $this->assertCount(1, $urls);
        $this->assertStringContainsString('key=' . rawurlencode(self::KEY), $urls[0], 'the site key is used');
        $this->assertStringNotContainsString(self::KEY, (string) wp_json_encode($out));
        delete_option('wpgmza_google_maps_api_key');
    }

    public function test_save_markers_answers_per_row_and_redacts_the_key_from_geocode_errors(): void
    {
        $map = wpmcp_test_go_map();
        update_option('wpgmza_google_maps_api_key', self::KEY);
        $urls = [];
        $this->google([ 'status' => 'REQUEST_DENIED', 'error_message' => 'The provided API key ' . self::KEY . ' is invalid.' ], $urls);

        $out     = $this->ok($this->write('wpgomaps-save-markers', [ 'markers' => [
            [ 'map_id' => $map, 'title' => 'Good', 'lat' => 1.5, 'lng' => 2.5 ],
            [ 'map_id' => $map, 'title' => 'Bad', 'address' => 'Nowhere' ],
            [ 'map_id' => 999, 'title' => 'No map', 'lat' => 1, 'lng' => 1 ],
            [ 'id' => 999999, 'title' => 'Ghost' ],
            [ 'map_id' => $map, 'title' => 'Half', 'lat' => 1 ],
        ] ]));
        $results = $out['result']['results'];
        $this->assertTrue($results[0]['ok']);
        $this->assertSame('geocode_failed', $results[1]['error']['code']);
        $this->assertStringContainsString('REQUEST_DENIED', $results[1]['error']['message']);
        $this->assertSame('map_not_found', $results[2]['error']['code']);
        $this->assertSame('marker_not_found', $results[3]['error']['code']);
        $this->assertSame('coordinates_incomplete', $results[4]['error']['code']);
        $this->assertSame(1, $out['result']['saved']);
        $this->assertSame(4, $out['result']['failed']);
        $this->assertCount(1, $out['operation_ids'], 'only the row that was written has an undo point');
        $this->assertStringNotContainsString(self::KEY, (string) wp_json_encode($out));

        delete_option('wpgmza_google_maps_api_key');
        $none = $this->ok($this->write('wpgomaps-save-markers', [ 'markers' => [ [ 'map_id' => $map, 'address' => 'Somewhere' ] ] ]));
        $this->assertSame('coordinates_required', $none['result']['results'][0]['error']['code'], 'no key: coordinates are needed');
    }

    public function test_delete_markers_removes_rows_and_rollback_puts_them_back(): void
    {
        $map    = wpmcp_test_go_map();
        $a      = wpmcp_test_go_marker([ 'map_id' => $map ]);
        $b      = wpmcp_test_go_marker([ 'map_id' => $map, 'title' => 'Depot', 'lat' => '53.8', 'lng' => '-1.55' ]);
        $before = [ wpmcp_test_go_marker_row($a), wpmcp_test_go_marker_row($b) ];

        $out = $this->ok($this->write('wpgomaps-delete-markers', [ 'ids' => [ $a, $b, 424242 ] ]));
        $this->assertSame(2, $out['result']['deleted']);
        $this->assertSame('marker_not_found', $out['result']['results'][2]['error']['code']);
        $this->assertNull(wpmcp_test_go_marker_row($a));
        $this->assertNull(wpmcp_test_go_marker_row($b));

        Rollback_Service::restore_session($out['session_id']);
        $this->assertSame($before, [ wpmcp_test_go_marker_row($a), wpmcp_test_go_marker_row($b) ]);
        $this->assertSame('POINT(53.8 -1.55)', wpmcp_test_go_marker_point($b));
    }

    // ---------------------------------------------------------------
    // WP Store Locator
    // ---------------------------------------------------------------

    public function test_list_stores_masks_contact_details_and_get_store_shows_them_to_an_admin(): void
    {
        $id = $this->store();
        wp_set_object_terms($id, [ 'Flagship' ], 'wpsl_store_category');

        $list = $this->read('wpsl-list-stores');
        $this->assertSame(1, $list['total']);
        $store = $list['stores'][0];
        $this->assertSame('Dam 1', $store['address']);
        $this->assertSame('52.373', $store['lat']);
        $this->assertSame('d***@shop.example.com', $store['email']);
        $this->assertSame('+***', $store['phone']);
        $this->assertTrue($store['masked']);
        $this->assertSame('flagship', $store['categories'][0]['slug']);
        $this->assertSame([ 'Amsterdam' ], array_column($this->read('wpsl-list-stores', [ 'category' => 'flagship' ])['stores'], 'title'));

        $full = $this->read('wpsl-get-store', [ 'id' => $id ])['store'];
        $this->assertSame('dam@shop.example.com', $full['email']);
        $this->assertSame('+31 20 555 0101', $full['phone']);
        $this->assertSame('Our flagship.', $full['description']);
        $this->assertFalse($full['masked']);

        $manager = self::factory()->user->create([ 'role' => 'editor' ]);
        get_userdata($manager)->add_cap('edit_stores');
        get_userdata($manager)->add_cap('edit_others_stores');
        get_userdata($manager)->add_cap('edit_published_stores');
        wp_set_current_user($manager);
        $masked = $this->read('wpsl-get-store', [ 'id' => $id ]);
        $this->assertSame('d***@shop.example.com', $masked['store']['email'] ?? null, (string) wp_json_encode($masked));
    }

    public function test_get_settings_leaves_every_key_out_and_finds_the_locator_page(): void
    {
        update_option('wpsl_api', [ 'active_map_service' => 'gmaps', 'gmaps_browser_key' => 'BROWSER-KEY-1', 'gmaps_server_key' => self::KEY, 'language' => 'en' ]);
        update_option('wpsl_search', [ 'search_method' => 'geocode', 'radius_options' => '10,25,50' ]);
        $page = self::factory()->post->create([ 'post_type' => 'page', 'post_content' => '[wpsl]' ]);

        $out = $this->read('wpsl-get-settings');
        $this->assertSame('gmaps', $out['settings']['api']['active_map_service']);
        $this->assertSame('geocode', $out['settings']['search']['search_method']);
        $this->assertArrayNotHasKey('gmaps_server_key', $out['settings']['api']);
        $this->assertStringNotContainsString(self::KEY, (string) wp_json_encode($out));
        $this->assertStringNotContainsString('BROWSER-KEY', (string) wp_json_encode($out));
        $this->assertSame([ $page ], array_column($out['embedded_in'], 'id'));
        delete_option('wpsl_api');
        delete_option('wpsl_search');
    }

    public function test_save_stores_creates_geocoded_stores_through_the_plugin_geocoder_and_rollback_trashes_them(): void
    {
        $out = $this->ok($this->write('wpsl-save-stores', [ 'stores' => [
            [ 'title' => 'Utrecht', 'address' => 'Oudegracht 1', 'city' => 'Utrecht', 'country' => 'Netherlands', 'email' => 'utrecht@shop.example.com', 'categories' => [ 'Outlet' ] ],
            [ 'title' => 'No city', 'address' => 'Somewhere 1', 'country' => 'Netherlands' ],
        ] ]));
        $results = $out['result']['results'];
        $this->assertTrue($results[0]['ok'], (string) wp_json_encode($results[0]));
        $this->assertTrue($results[0]['geocoded']);
        $this->assertSame('address_required', $results[1]['error']['code']);
        $id = $results[0]['store']['id'];
        $this->assertSame('draft', get_post_status($id), 'a new store is a draft unless the call asks for publish');
        $this->assertSame('52.370216', get_post_meta($id, 'wpsl_lat', true));
        $this->assertSame('4.895168', get_post_meta($id, 'wpsl_lng', true));
        $this->assertSame('utrecht@shop.example.com', get_post_meta($id, 'wpsl_email', true));
        $this->assertSame([ 'Outlet' ], wp_get_post_terms($id, 'wpsl_store_category', [ 'fields' => 'names' ]));
        $call = \WPMCP_Test_WPSL_Services::$geocode->calls[0];
        $this->assertSame('Oudegracht 1', $call[0]['address']);
        $this->assertTrue($call[2], 'asked as an API call, so the plugin stores no admin notice');
        $this->assertSame(1, \WPMCP_Test_WPSL_Services::$system->flushes, 'the cached locator results are dropped');

        Rollback_Service::restore_session($out['session_id']);
        $this->assertSame('trash', get_post_status($id));
    }

    public function test_save_stores_updates_only_what_is_given_and_rolls_back_exactly(): void
    {
        $id = $this->store();
        wp_set_object_terms($id, [ 'Flagship' ], 'wpsl_store_category');
        $snap = static fn (): array => [ get_post($id)->post_title, get_post_status($id), get_post_meta($id), wp_get_post_terms($id, 'wpsl_store_category', [ 'fields' => 'names' ]) ];
        $before = $snap();

        $out = $this->ok($this->write('wpsl-save-stores', [ 'stores' => [ [ 'id' => $id, 'title' => 'Amsterdam Dam', 'phone' => '+31 20 555 0199', 'categories' => [ 'Outlet' ] ] ] ]));
        $this->assertTrue($out['result']['results'][0]['ok'], (string) wp_json_encode($out));
        $this->assertFalse($out['result']['results'][0]['geocoded'], 'no address part changed, so no geocode');
        $this->assertSame([], \WPMCP_Test_WPSL_Services::$geocode->calls);
        $this->assertSame('Amsterdam Dam', get_post($id)->post_title);
        $this->assertSame('+31 20 555 0199', get_post_meta($id, 'wpsl_phone', true));
        $this->assertSame('Dam 1', get_post_meta($id, 'wpsl_address', true));

        $moved = $this->ok($this->write('wpsl-save-stores', [ 'stores' => [ [ 'id' => $id, 'address' => 'Damrak 70' ] ] ]));
        $this->assertTrue($moved['result']['results'][0]['geocoded'], 'a changed address is geocoded again');
        $this->assertSame('Amsterdam', \WPMCP_Test_WPSL_Services::$geocode->calls[0][0]['city'], 'with the parts it kept');

        Rollback_Service::restore_session($moved['session_id']);
        Rollback_Service::restore_session($out['session_id']);
        $this->assertSame($before, $snap());
    }

    public function test_save_stores_redacts_keys_from_a_geocoder_failure(): void
    {
        update_option('wpsl_api', [ 'gmaps_server_key' => self::KEY ]);
        \WPMCP_Test_WPSL_Services::$geocode->response = [ 'message' => 'Google said: key ' . self::KEY . ' is not authorized' ];
        $out = $this->ok($this->write('wpsl-save-stores', [ 'stores' => [ [ 'title' => 'X', 'address' => 'A 1', 'city' => 'B', 'country' => 'C' ] ] ]));
        $this->assertSame('geocode_failed', $out['result']['results'][0]['error']['code']);
        $this->assertStringNotContainsString(self::KEY, (string) wp_json_encode($out));
        $this->assertSame([], $out['operation_ids']);
        $this->assertSame(0, (int) (new \WP_Query([ 'post_type' => 'wpsl_stores', 'post_status' => 'any', 'title' => 'X' ]))->found_posts, 'nothing created');
        delete_option('wpsl_api');
    }

    public function test_a_new_store_is_published_only_when_asked_and_allowed(): void
    {
        $out = $this->ok($this->write('wpsl-save-stores', [ 'stores' => [
            [ 'title' => 'Asked', 'address' => 'Oudegracht 1', 'city' => 'Utrecht', 'country' => 'Netherlands', 'lat' => '52.1', 'lng' => '5.1', 'status' => 'publish' ],
        ] ]));
        $this->assertTrue($out['result']['results'][0]['ok'], (string) wp_json_encode($out['result']['results'][0]));
        $this->assertSame('publish', get_post_status($out['result']['results'][0]['store']['id']));

        $author = self::factory()->user->create([ 'role' => 'author' ]);
        $user   = get_user_by('id', $author);
        $user->add_cap('edit_stores');
        wp_set_current_user($author);
        $out = $this->write('wpsl-save-stores', [ 'stores' => [
            [ 'title' => 'Not allowed', 'address' => 'A 1', 'city' => 'B', 'country' => 'C', 'lat' => '1', 'lng' => '1', 'status' => 'publish' ],
        ] ]);
        $row = $out['result']['results'][0] ?? $out;
        $this->assertSame('operation_denied', $row['error']['code'] ?? ($out['error']['code'] ?? null), (string) wp_json_encode($out));
    }

    public function test_delete_stores_trashes_and_rollback_restores(): void
    {
        $id  = $this->store();
        $out = $this->ok($this->write('wpsl-delete-stores', [ 'ids' => [ $id ] ]));
        $this->assertSame(1, $out['result']['trashed']);
        $this->assertSame('trash', get_post_status($id));
        Rollback_Service::restore_session($out['session_id']);
        $this->assertSame('publish', get_post_status($id));
    }

    // ---------------------------------------------------------------
    // WP Maps
    // ---------------------------------------------------------------

    public function test_wp_maps_lists_maps_with_their_locations_and_embeds(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'map_locations', [ 'location_title' => 'Clinic North', 'location_address' => '2 North Rd', 'location_latitude' => '40.1', 'location_longitude' => '-74.2', 'location_city' => 'Newark', 'location_country' => 'US' ]);
        $one = (int) $wpdb->insert_id;
        $wpdb->insert($wpdb->prefix . 'map_locations', [ 'location_title' => 'Clinic South', 'location_address' => '9 South Rd', 'location_latitude' => '39.9', 'location_longitude' => '-74.1' ]);
        $two = (int) $wpdb->insert_id;
        $wpdb->insert($wpdb->prefix . 'map_locations', [ 'location_title' => 'Elsewhere' ]);
        $wpdb->insert($wpdb->prefix . 'create_map', [ 'map_title' => 'Clinics', 'map_zoom_level' => '9', 'map_type' => 'ROADMAP', 'map_locations' => serialize([ (string) $one, (string) $two ]) ]);
        $map  = (int) $wpdb->insert_id;
        $page = self::factory()->post->create([ 'post_type' => 'page', 'post_content' => '[put_wpgm id=' . $map . ']' ]);

        $maps = $this->read('wpmaps-list-maps')['maps'];
        $this->assertSame('Clinics', $maps[0]['title']);
        $this->assertSame([ $one, $two ], $maps[0]['location_ids']);
        $this->assertSame([ $page ], array_column($maps[0]['embedded_in'], 'id'));

        $locations = $this->read('wpmaps-list-locations', [ 'map_id' => $map ]);
        $this->assertSame(2, $locations['total']);
        $this->assertSame('Clinic North', $locations['locations'][0]['title']);
        $this->assertSame('40.1', $locations['locations'][0]['lat']);
        $this->assertSame([ 'Clinic South' ], array_column($this->read('wpmaps-list-locations', [ 'search' => 'South' ])['locations'], 'title'));
        $this->assertSame('map_not_found', $this->read('wpmaps-list-locations', [ 'map_id' => 999 ])['error']['code'] ?? null);
    }
}
