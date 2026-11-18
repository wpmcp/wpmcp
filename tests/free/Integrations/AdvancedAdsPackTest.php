<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Advanced_Ads_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Advanced Ads (issue #688): free-tier ops on the plugin-data dispatcher
 * pair, so they add no top-level tools.
 *
 * Advanced Ads is not loaded in the shared test core (wordpress.org closed
 * it on 2026-11-11, pending a review, so no live leg can download it).
 * Presence is driven through wpmcp_advanced_ads_active, and its post types
 * and group taxonomy are registered here the way Advanced Ads 2.0.31 does
 * in includes/class-entities.php: an ad is an `advanced_ads` post behind
 * advanced_ads_edit_ads, a placement an `advanced_ads_plcmnt` post behind
 * advanced_ads_manage_placements, and its activation grants both to the
 * administrator role (includes/installation/class-capabilities.php). An
 * ad's settings are the advanced_ads_ad_options array (Ad_Repository), a
 * placement's are the type, item ("ad_<id>" or "group_<id>") and options
 * post meta (Placement_Repository).
 *
 * The writes are off until the site opts in
 * (wpmcp_enable_advanced_ads_write) and each is undone in a test.
 */
class AdvancedAdsPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'advancedads-list-ads'         => 'read',
        'advancedads-get-ad'           => 'read',
        'advancedads-list-placements'  => 'read',
        'advancedads-create-ad'        => 'write',
        'advancedads-update-ad'        => 'write',
        'advancedads-enable-ad'        => 'write',
        'advancedads-disable-ad'       => 'write',
        'advancedads-assign-placement' => 'write',
    ];

    private const CAPS = [ 'advanced_ads_edit_ads', 'advanced_ads_manage_placements' ];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $caps = static fn (string $cap): array => [
            'edit_post' => $cap, 'read_post' => $cap, 'delete_post' => $cap, 'edit_posts' => $cap, 'publish_posts' => $cap,
            'read_private_posts' => $cap, 'read' => $cap, 'delete_posts' => $cap, 'delete_private_posts' => $cap,
            'delete_published_posts' => $cap, 'edit_private_posts' => $cap, 'edit_published_posts' => $cap, 'create_posts' => $cap,
        ];
        register_post_type('advanced_ads', [ 'public' => false, 'show_ui' => true, 'capabilities' => $caps('advanced_ads_edit_ads'), 'supports' => [ 'title', 'author' ] ]);
        register_post_type('advanced_ads_plcmnt', [ 'public' => false, 'show_ui' => true, 'capabilities' => $caps('advanced_ads_manage_placements'), 'supports' => [ 'title' ] ]);
        register_taxonomy('advanced_ads_groups', 'advanced_ads', [ 'public' => false, 'hierarchical' => true, 'capabilities' => [ 'manage_terms' => 'advanced_ads_edit_ads', 'edit_terms' => 'advanced_ads_edit_ads', 'delete_terms' => 'advanced_ads_edit_ads', 'assign_terms' => 'advanced_ads_edit_ads' ] ]);
        $role = get_role('administrator');
        foreach (self::CAPS as $cap) {
            $role->add_cap($cap);
        }
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        add_filter('wpmcp_advanced_ads_active', '__return_true');
        add_filter('wpmcp_enable_advanced_ads_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_advanced_ads_active');
        remove_all_filters('wpmcp_enable_advanced_ads_write');
        $role = get_role('administrator');
        foreach (self::CAPS as $cap) {
            $role->remove_cap($cap);
        }
        unregister_taxonomy('advanced_ads_groups');
        unregister_post_type('advanced_ads_plcmnt');
        unregister_post_type('advanced_ads');
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

    /** An ad as Ad_Repository::create() stores it. */
    private function ad(string $title = 'Sidebar banner', array $options = [], string $status = 'publish', string $content = '<a href="https://shop.example.com"><img src="https://cdn.example.com/b.png"></a>'): int
    {
        $id = self::factory()->post->create([ 'post_type' => 'advanced_ads', 'post_title' => $title, 'post_status' => $status, 'post_content' => $content ]);
        update_post_meta($id, 'advanced_ads_ad_options', $options + [ 'type' => 'plain', 'width' => 300, 'height' => 250, 'url' => '', 'description' => 'Q4 campaign', 'allow_php' => '', 'position' => 'none', 'expiry_date' => 0 ]);
        return $id;
    }

    /** A placement as Placement_Repository::create() stores it. */
    private function placement(string $title = 'Before content', string $type = 'post_top', string $item = ''): int
    {
        $id = self::factory()->post->create([ 'post_type' => 'advanced_ads_plcmnt', 'post_title' => $title, 'post_status' => 'publish', 'post_name' => sanitize_title($title) ]);
        update_post_meta($id, 'type', $type);
        update_post_meta($id, 'item', $item);
        update_post_meta($id, 'options', [ 'index' => 1 ]);
        return $id;
    }

    // ---------------------------------------------------------------
    // Catalog and availability
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

    public function test_while_advanced_ads_is_missing_a_call_answers_plugin_missing_with_the_plugins_link(): void
    {
        remove_all_filters('wpmcp_advanced_ads_active');
        add_filter('wpmcp_advanced_ads_active', '__return_false');
        $out = $this->read('advancedads-list-ads');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Advanced Ads', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
    }

    public function test_with_the_plugin_files_installed_but_inactive_it_answers_plugin_inactive(): void
    {
        remove_all_filters('wpmcp_advanced_ads_active');
        add_filter('wpmcp_advanced_ads_active', '__return_false');
        $dir     = WP_PLUGIN_DIR . '/advanced-ads';
        $created = ! is_dir($dir);
        if ($created) {
            wp_mkdir_p($dir);
        }
        $file  = $dir . '/advanced-ads.php';
        $owned = ! file_exists($file);
        if ($owned) {
            file_put_contents($file, "<?php\n// Plugin Name: Advanced Ads\n");
        }
        try {
            $out = $this->read('advancedads-list-placements');
        } finally {
            if ($owned) {
                unlink($file);
            }
            if ($created) {
                rmdir($dir);
            }
        }
        $this->assertSame('plugin_inactive', $out['error']['code'] ?? null);
    }

    public function test_presence_defaults_to_the_plugin_constant(): void
    {
        remove_all_filters('wpmcp_advanced_ads_active');
        $this->assertSame(defined('ADVADS_VERSION'), Advanced_Ads_Pack::active());
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $id    = $this->ad();
        $place = $this->placement();
        remove_all_filters('wpmcp_enable_advanced_ads_write');
        foreach ([
            [ 'advancedads-create-ad', [ 'title' => 'x', 'type' => 'dummy' ] ],
            [ 'advancedads-update-ad', [ 'id' => $id, 'title' => 'x' ] ],
            [ 'advancedads-enable-ad', [ 'id' => $id ] ],
            [ 'advancedads-disable-ad', [ 'id' => $id ] ],
            [ 'advancedads-assign-placement', [ 'id' => $place, 'ad_id' => $id ] ],
        ] as [ $op, $args ]) {
            $out = $this->write($op, $args);
            $this->assertSame('opt_in_required', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString('wpmcp_enable_advanced_ads_write', $out['error']['message']);
        }
        $this->assertSame('Sidebar banner', get_post($id)->post_title);
        $this->assertSame('', get_post_meta($place, 'item', true));
    }

    public function test_only_users_with_the_advanced_ads_capabilities_may_read_or_write(): void
    {
        $id    = $this->ad();
        $place = $this->placement();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertSame('operation_denied', $this->read('advancedads-get-ad', [ 'id' => $id ])['error']['code'] ?? null, 'an editor lacks advanced_ads_edit_ads');
        $this->assertSame('operation_denied', $this->read('advancedads-list-ads')['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->read('advancedads-list-placements')['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->write('advancedads-disable-ad', [ 'id' => $id ])['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->write('advancedads-create-ad', [ 'title' => 'x', 'type' => 'dummy' ])['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->write('advancedads-assign-placement', [ 'id' => $place, 'ad_id' => $id ])['error']['code'] ?? null);
        $this->assertSame('publish', get_post_status($id));
        $this->assertSame('', get_post_meta($place, 'item', true));
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_ads_returns_ads_with_their_settings_and_filters(): void
    {
        $group = self::factory()->term->create([ 'taxonomy' => 'advanced_ads_groups', 'name' => 'Sidebar rotation' ]);
        $a     = $this->ad('Alpha');
        wp_set_object_terms($a, [ $group ], 'advanced_ads_groups');
        $b = $this->ad('Beta', [ 'type' => 'image', 'image_id' => 7 ], 'draft', '');

        $out = $this->ok($this->read('advancedads-list-ads'));
        $this->assertSame(2, $out['total']);
        $ads = array_column($out['ads'], null, 'id');
        $this->assertSame('plain', $ads[ $a ]['type']);
        $this->assertSame(300, $ads[ $a ]['width']);
        $this->assertTrue($ads[ $a ]['enabled']);
        $this->assertFalse($ads[ $b ]['enabled']);
        $this->assertSame('Sidebar rotation', $ads[ $a ]['groups'][0]['name']);
        $this->assertArrayNotHasKey('content', $ads[ $a ], 'lists leave the ad code out');
        $this->assertSame([ $b ], array_column($this->read('advancedads-list-ads', [ 'type' => 'image' ])['ads'], 'id'));
        $this->assertSame([ $b ], array_column($this->read('advancedads-list-ads', [ 'status' => 'draft' ])['ads'], 'id'));
        $this->assertSame([ $a ], array_column($this->read('advancedads-list-ads', [ 'search' => 'Alpha' ])['ads'], 'id'));
        $this->assertSame([ $a ], array_column($this->read('advancedads-list-ads', [ 'group' => $group ])['ads'], 'id'));
    }

    public function test_get_ad_returns_the_code_settings_and_the_placements_that_show_it(): void
    {
        $id    = $this->ad('Alpha');
        $place = $this->placement('Footer', 'footer', 'ad_' . $id);
        $this->placement('Header', 'header', 'ad_' . ($id + 1000));

        $ad = $this->ok($this->read('advancedads-get-ad', [ 'id' => $id ]))['ad'];
        $this->assertSame('Alpha', $ad['title']);
        $this->assertStringContainsString('cdn.example.com/b.png', $ad['content']);
        $this->assertSame('Q4 campaign', $ad['description']);
        $this->assertFalse($ad['allow_php']);
        $this->assertSame([ $place ], array_column($ad['placements'], 'id'));
        $this->assertSame('ad_not_found', $this->read('advancedads-get-ad', [ 'id' => self::factory()->post->create() ])['error']['code'] ?? null);
    }

    public function test_list_placements_names_the_ad_or_group_each_one_shows(): void
    {
        $ad    = $this->ad('Alpha');
        $group = self::factory()->term->create([ 'taxonomy' => 'advanced_ads_groups', 'name' => 'Rotation' ]);
        $p1    = $this->placement('Footer', 'footer', 'ad_' . $ad);
        $p2    = $this->placement('Sidebar', 'sidebar_widget', 'group_' . $group);
        $p3    = $this->placement('Header', 'header');

        $out = $this->ok($this->read('advancedads-list-placements'));
        $this->assertSame(3, $out['total']);
        $rows = array_column($out['placements'], null, 'id');
        $this->assertSame([ 'kind' => 'ad', 'id' => $ad, 'title' => 'Alpha' ], $rows[ $p1 ]['item']);
        $this->assertSame([ 'kind' => 'group', 'id' => $group, 'title' => 'Rotation' ], $rows[ $p2 ]['item']);
        $this->assertNull($rows[ $p3 ]['item']);
        $this->assertSame('sidebar_widget', $rows[ $p2 ]['type']);
        $this->assertSame([ $p2 ], array_column($this->read('advancedads-list-placements', [ 'type' => 'sidebar_widget' ])['placements'], 'id'));
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    public function test_create_ad_stores_a_draft_with_its_options_and_rollback_session_trashes_it(): void
    {
        $out = $this->ok($this->write('advancedads-create-ad', [ 'title' => 'New banner', 'type' => 'plain', 'content' => '<div class="promo">Sale</div>', 'width' => 728, 'height' => 90, 'url' => 'https://shop.example.com/sale', 'description' => 'Black Friday' ]));
        $ad   = $out['result']['ad'];
        $post = get_post($ad['id']);
        $this->assertSame('advanced_ads', $post->post_type);
        $this->assertSame('draft', $post->post_status, 'a new ad waits for enable-ad');
        $this->assertSame('<div class="promo">Sale</div>', $post->post_content);
        $options = get_post_meta($post->ID, 'advanced_ads_ad_options', true);
        $this->assertSame('plain', $options['type']);
        $this->assertSame(728, $options['width']);
        $this->assertSame(90, $options['height']);
        $this->assertSame('https://shop.example.com/sale', $options['url']);
        $this->assertSame('Black Friday', $options['description']);
        $this->assertFalse($ad['enabled']);

        $this->assertNotEmpty($out['operation_ids'] ?? []);
        foreach ((array) $out['operation_ids'] as $operation) {
            Rollback_Service::restore_operation((string) $operation);
        }
        $this->assertSame('trash', get_post_status($post->ID));
    }

    public function test_create_ad_refuses_plain_code_without_unfiltered_html_a_bad_url_and_a_missing_image(): void
    {
        $this->assertSame('url_invalid', $this->write('advancedads-create-ad', [ 'title' => 'x', 'type' => 'dummy', 'url' => 'javascript:alert(1)' ])['error']['code'] ?? null);
        $this->assertSame('image_not_found', $this->write('advancedads-create-ad', [ 'title' => 'x', 'type' => 'image', 'image_id' => 999999 ])['error']['code'] ?? null);
        $this->assertSame('image_not_found', $this->write('advancedads-create-ad', [ 'title' => 'x', 'type' => 'image' ])['error']['code'] ?? null);

        add_filter('map_meta_cap', $deny = static fn (array $caps, string $cap): array => 'unfiltered_html' === $cap ? [ 'do_not_allow' ] : $caps, 10, 2);
        try {
            $out = $this->write('advancedads-create-ad', [ 'title' => 'x', 'type' => 'plain', 'content' => '<script>x()</script>' ]);
            $this->assertSame('operation_denied', $out['error']['code'] ?? null, (string) wp_json_encode($out));
            $this->assertStringContainsString('unfiltered_html', $out['error']['message']);
        } finally {
            remove_filter('map_meta_cap', $deny, 10);
        }
    }

    public function test_create_ad_never_accepts_allow_php(): void
    {
        $out = $this->write('advancedads-create-ad', [ 'title' => 'x', 'type' => 'plain', 'content' => '<?php echo 1; ?>', 'allow_php' => true ]);
        $this->assertArrayHasKey('error', $out, 'allow_php is not an argument');
    }

    public function test_update_ad_merges_the_options_keeps_allow_php_and_rolls_back_exactly(): void
    {
        $id     = $this->ad('Alpha', [ 'allow_php' => '1', 'position' => 'left_float' ]);
        $before = [ get_post($id)->post_title, get_post($id)->post_content, get_post_meta($id, 'advanced_ads_ad_options', true) ];

        $out = $this->ok($this->write('advancedads-update-ad', [ 'id' => $id, 'title' => 'Alpha 2', 'content' => '<b>new</b>', 'width' => 160, 'url' => 'https://example.com/b' ]));
        $this->assertTrue($out['recoverable']);
        $options = get_post_meta($id, 'advanced_ads_ad_options', true);
        $this->assertSame('Alpha 2', get_post($id)->post_title);
        $this->assertSame('<b>new</b>', get_post($id)->post_content);
        $this->assertSame(160, $options['width']);
        $this->assertSame(250, $options['height'], 'untouched');
        $this->assertSame('1', $options['allow_php'], 'kept as the site set it');
        $this->assertSame('left_float', $options['position']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, [ get_post($id)->post_title, get_post($id)->post_content, get_post_meta($id, 'advanced_ads_ad_options', true) ]);
    }

    public function test_update_ad_refuses_a_non_ad_and_a_trashed_ad(): void
    {
        $id = $this->ad();
        $this->assertSame('ad_not_found', $this->write('advancedads-update-ad', [ 'id' => self::factory()->post->create(), 'title' => 'x' ])['error']['code'] ?? null);
        wp_trash_post($id);
        $this->assertSame('ad_not_found', $this->write('advancedads-update-ad', [ 'id' => $id, 'title' => 'x' ])['error']['code'] ?? null);
    }

    public function test_disable_and_enable_switch_the_status_and_each_rolls_back(): void
    {
        $id = $this->ad();

        $off = $this->ok($this->write('advancedads-disable-ad', [ 'id' => $id ]));
        $this->assertTrue($off['recoverable']);
        $this->assertSame('draft', get_post_status($id));
        $this->assertFalse($off['result']['ad']['enabled']);

        $on = $this->ok($this->write('advancedads-enable-ad', [ 'id' => $id ]));
        $this->assertSame('publish', get_post_status($id));
        $this->assertTrue($on['result']['ad']['enabled']);

        $this->assertTrue(Rollback_Service::restore_operation($on['operation_id']));
        $this->assertSame('draft', get_post_status($id));
        $this->assertTrue(Rollback_Service::restore_operation($off['operation_id']));
        $this->assertSame('publish', get_post_status($id));
    }

    public function test_assign_placement_sets_an_ad_a_group_or_nothing_and_rolls_back(): void
    {
        $ad    = $this->ad('Alpha');
        $group = self::factory()->term->create([ 'taxonomy' => 'advanced_ads_groups', 'name' => 'Rotation' ]);
        $place = $this->placement('Footer', 'footer', 'ad_123');

        $out = $this->ok($this->write('advancedads-assign-placement', [ 'id' => $place, 'ad_id' => $ad ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('ad_' . $ad, get_post_meta($place, 'item', true));
        $this->assertSame([ 'kind' => 'ad', 'id' => $ad, 'title' => 'Alpha' ], $out['result']['placement']['item']);
        $this->assertSame([ 'index' => 1 ], get_post_meta($place, 'options', true), 'options untouched');

        $grp = $this->ok($this->write('advancedads-assign-placement', [ 'id' => $place, 'group_id' => $group ]));
        $this->assertSame('group_' . $group, get_post_meta($place, 'item', true));

        $none = $this->ok($this->write('advancedads-assign-placement', [ 'id' => $place, 'clear' => true ]));
        $this->assertSame('', get_post_meta($place, 'item', true));

        $this->assertTrue(Rollback_Service::restore_operation($none['operation_id']));
        $this->assertSame('group_' . $group, get_post_meta($place, 'item', true));
        $this->assertTrue(Rollback_Service::restore_operation($grp['operation_id']));
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('ad_123', get_post_meta($place, 'item', true));
    }

    public function test_assign_placement_refuses_bad_targets(): void
    {
        $ad    = $this->ad();
        $place = $this->placement();
        $this->assertSame('placement_not_found', $this->write('advancedads-assign-placement', [ 'id' => $ad, 'ad_id' => $ad ])['error']['code'] ?? null);
        $this->assertSame('ad_not_found', $this->write('advancedads-assign-placement', [ 'id' => $place, 'ad_id' => $place ])['error']['code'] ?? null);
        $this->assertSame('group_not_found', $this->write('advancedads-assign-placement', [ 'id' => $place, 'group_id' => 999999 ])['error']['code'] ?? null);
        $this->assertSame('item_required', $this->write('advancedads-assign-placement', [ 'id' => $place ])['error']['code'] ?? null);
        $this->assertSame('item_required', $this->write('advancedads-assign-placement', [ 'id' => $place, 'ad_id' => $ad, 'clear' => true ])['error']['code'] ?? null);
        $this->assertSame('', get_post_meta($place, 'item', true));
    }
}
