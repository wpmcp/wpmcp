<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\Popup_Maker_Pack;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Popup Maker (issue #685): free-tier ops on the plugin-data dispatcher
 * pair, so they add no top-level tools.
 *
 * Popup Maker is not loaded in the shared test core. Presence is driven
 * through wpmcp_popup_maker_active, and its post type is registered here the
 * way Popup Maker 1.25.0 does in classes/Controllers/PostTypes.php: a popup
 * is a `popup` post (post_title is the internal name, post_content the
 * popup's content) whose capabilities all map to the edit_popups permission
 * (edit_others_posts unless the site changed it). Its settings are the
 * popup_settings post meta array (triggers, cookies, conditions, theme_id,
 * size, location and the rest), the title shown to visitors is the
 * popup_title meta, and the on/off switch is the enabled meta (1 or 0,
 * Popup::is_enabled()).
 *
 * The writes are off until the site opts in
 * (wpmcp_enable_popup_maker_write) and each is undone in a test.
 */
class PopupMakerPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'popupmaker-list-popups'       => 'read',
        'popupmaker-get-popup'         => 'read',
        'popupmaker-get-popup-triggers' => 'read',
        'popupmaker-create-popup'      => 'write',
        'popupmaker-update-popup'      => 'write',
        'popupmaker-set-popup-triggers' => 'write',
        'popupmaker-enable-popup'      => 'write',
        'popupmaker-disable-popup'     => 'write',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $cap  = 'edit_others_posts';
        $caps = [
            'edit_post' => 'edit_popup', 'read_post' => 'read_popup', 'delete_post' => 'delete_popup',
            'edit_popup' => $cap, 'read_popup' => $cap, 'delete_popup' => $cap, 'create_posts' => $cap, 'edit_posts' => $cap,
            'edit_others_posts' => $cap, 'edit_published_posts' => $cap, 'edit_private_posts' => $cap, 'publish_posts' => $cap,
            'read_private_posts' => $cap, 'delete_posts' => $cap, 'delete_others_posts' => $cap, 'delete_published_posts' => $cap,
            'delete_private_posts' => $cap,
        ];
        register_post_type('popup_theme', [ 'public' => true, 'show_ui' => true, 'map_meta_cap' => true, 'capabilities' => $caps, 'supports' => [ 'title' ] ]);
        register_post_type('popup', [ 'public' => true, 'publicly_queryable' => false, 'show_ui' => true, 'map_meta_cap' => true, 'capabilities' => $caps, 'supports' => [ 'title', 'excerpt', 'editor', 'revisions', 'author', 'custom-fields' ] ]);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        add_filter('wpmcp_popup_maker_active', '__return_true');
        add_filter('wpmcp_enable_popup_maker_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_popup_maker_active');
        remove_all_filters('wpmcp_enable_popup_maker_write');
        unregister_post_type('popup');
        unregister_post_type('popup_theme');
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

    /** A popup as Popup Maker's editor saves it (settings recorded from 1.25.0's default popup). */
    private function popup(string $title = 'Newsletter signup', array $settings = [], string $status = 'publish', string $enabled = '1'): int
    {
        $id = self::factory()->post->create([ 'post_type' => 'popup', 'post_title' => $title, 'post_status' => $status, 'post_content' => '<p>Join our list</p>[contact-form-7 id="12"]' ]);
        update_post_meta($id, 'popup_title', 'Get 10% off');
        update_post_meta($id, 'enabled', $enabled);
        update_post_meta($id, 'popup_settings', $settings + [
            'triggers'               => [ [ 'type' => 'auto_open', 'settings' => [ 'cookie_name' => [ 'pum-' . $id ], 'delay' => 500 ] ] ],
            'cookies'                => [ [ 'event' => 'on_popup_close', 'settings' => [ 'name' => 'pum-' . $id, 'key' => '', 'session' => false, 'time' => '1 month', 'path' => '1' ] ] ],
            'conditions'             => [ [ [ 'target' => 'is_front_page' ] ] ],
            'theme_id'               => '2085',
            'size'                   => 'medium',
            'location'               => 'center',
            'animation_type'         => 'fade',
            'animation_speed'        => '350',
            'close_on_overlay_click' => false,
            'close_on_esc_press'     => false,
            'disable_on_mobile'      => false,
            'disable_on_tablet'      => false,
            'zindex'                 => '1999999999',
        ]);
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

    public function test_while_popup_maker_is_missing_a_call_answers_plugin_missing_with_the_plugins_link(): void
    {
        remove_all_filters('wpmcp_popup_maker_active');
        add_filter('wpmcp_popup_maker_active', '__return_false');
        $out = $this->read('popupmaker-list-popups');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Popup Maker', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
    }

    public function test_with_the_plugin_files_installed_but_inactive_it_answers_plugin_inactive(): void
    {
        remove_all_filters('wpmcp_popup_maker_active');
        add_filter('wpmcp_popup_maker_active', '__return_false');
        $dir     = WP_PLUGIN_DIR . '/popup-maker';
        $created = ! is_dir($dir);
        if ($created) {
            wp_mkdir_p($dir);
        }
        $file  = $dir . '/popup-maker.php';
        $owned = ! file_exists($file);
        if ($owned) {
            file_put_contents($file, "<?php\n// Plugin Name: Popup Maker\n");
        }
        try {
            $out = $this->read('popupmaker-list-popups');
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
        remove_all_filters('wpmcp_popup_maker_active');
        $this->assertSame(defined('POPMAKE_VERSION'), Popup_Maker_Pack::active());
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $id = $this->popup();
        remove_all_filters('wpmcp_enable_popup_maker_write');
        foreach ([
            [ 'popupmaker-create-popup', [ 'title' => 'x' ] ],
            [ 'popupmaker-update-popup', [ 'id' => $id, 'title' => 'x' ] ],
            [ 'popupmaker-set-popup-triggers', [ 'id' => $id, 'triggers' => [] ] ],
            [ 'popupmaker-enable-popup', [ 'id' => $id ] ],
            [ 'popupmaker-disable-popup', [ 'id' => $id ] ],
        ] as [ $op, $args ]) {
            $out = $this->write($op, $args);
            $this->assertSame('opt_in_required', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString('wpmcp_enable_popup_maker_write', $out['error']['message']);
        }
        $this->assertSame('Newsletter signup', get_post($id)->post_title);
        $this->assertSame('1', get_post_meta($id, 'enabled', true));
    }

    public function test_only_users_with_the_edit_popups_permission_may_read_or_write(): void
    {
        $id = $this->popup();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'author' ]));
        $this->assertSame('operation_denied', $this->read('popupmaker-list-popups')['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->read('popupmaker-get-popup', [ 'id' => $id ])['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->read('popupmaker-get-popup-triggers', [ 'id' => $id ])['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->write('popupmaker-disable-popup', [ 'id' => $id ])['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->write('popupmaker-create-popup', [ 'title' => 'x' ])['error']['code'] ?? null);
        $this->assertSame('1', get_post_meta($id, 'enabled', true));
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_popups_returns_each_popup_with_its_switch_and_trigger_types(): void
    {
        $a = $this->popup('Alpha');
        $b = $this->popup('Beta', [ 'triggers' => [ [ 'type' => 'click_open', 'settings' => [ 'extra_selectors' => '.buy' ] ] ] ], 'publish', '0');
        $c = $this->popup('Gamma', [], 'draft');

        $out = $this->ok($this->read('popupmaker-list-popups'));
        $this->assertSame(3, $out['total']);
        $rows = array_column($out['popups'], null, 'id');
        $this->assertTrue($rows[ $a ]['enabled']);
        $this->assertFalse($rows[ $b ]['enabled']);
        $this->assertFalse($rows[ $c ]['enabled'], 'a draft popup does not show');
        $this->assertSame('Get 10% off', $rows[ $a ]['display_title']);
        $this->assertSame([ 'auto_open' ], $rows[ $a ]['trigger_types']);
        $this->assertSame([ 'click_open' ], $rows[ $b ]['trigger_types']);
        $this->assertSame(2085, $rows[ $a ]['theme_id']);
        $this->assertArrayNotHasKey('content', $rows[ $a ], 'lists leave the content out');

        $this->assertSame([ $b ], array_column($this->read('popupmaker-list-popups', [ 'switched_on' => false ])['popups'], 'id'));
        $this->assertSame([ $c ], array_column($this->read('popupmaker-list-popups', [ 'status' => 'draft' ])['popups'], 'id'));
        $this->assertSame([ $a ], array_column($this->read('popupmaker-list-popups', [ 'search' => 'Alpha' ])['popups'], 'id'));
    }

    public function test_a_popup_without_the_enabled_meta_counts_as_enabled_as_popup_maker_does(): void
    {
        $id = $this->popup('Legacy');
        delete_post_meta($id, 'enabled');
        $this->assertTrue($this->ok($this->read('popupmaker-get-popup', [ 'id' => $id ]))['popup']['enabled']);
    }

    public function test_get_popup_returns_content_settings_triggers_cookies_and_conditions(): void
    {
        $id = $this->popup('Alpha');
        $p  = $this->ok($this->read('popupmaker-get-popup', [ 'id' => $id ]))['popup'];
        $this->assertSame('Alpha', $p['title']);
        $this->assertStringContainsString('Join our list', $p['content']);
        $this->assertSame('medium', $p['settings']['size']);
        $this->assertSame('center', $p['settings']['location']);
        $this->assertSame('auto_open', $p['triggers'][0]['type']);
        $this->assertSame('on_popup_close', $p['cookies'][0]['event']);
        $this->assertSame('is_front_page', $p['conditions'][0][0]['target']);
        $this->assertSame('popup_not_found', $this->read('popupmaker-get-popup', [ 'id' => self::factory()->post->create() ])['error']['code'] ?? null);
    }

    public function test_get_popup_triggers_returns_the_triggers_and_cookies_only(): void
    {
        $id  = $this->popup();
        $out = $this->ok($this->read('popupmaker-get-popup-triggers', [ 'id' => $id ]));
        $this->assertSame($id, $out['id']);
        $this->assertSame(500, $out['triggers'][0]['settings']['delay']);
        $this->assertSame('pum-' . $id, $out['cookies'][0]['settings']['name']);
        $this->assertArrayNotHasKey('content', $out);
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    public function test_create_popup_stores_a_draft_and_rollback_session_trashes_it(): void
    {
        $out = $this->ok($this->write('popupmaker-create-popup', [
            'title'         => 'Exit offer',
            'display_title' => 'Wait!',
            'content'       => '<p>Take 15% off</p>',
            'size'          => 'small',
            'triggers'      => [ [ 'type' => 'auto_open', 'settings' => [ 'delay' => 2000 ] ] ],
        ]));
        $p    = $out['result']['popup'];
        $post = get_post($p['id']);
        $this->assertSame('popup', $post->post_type);
        $this->assertSame('draft', $post->post_status, 'a new popup waits for enable-popup');
        $this->assertSame('<p>Take 15% off</p>', $post->post_content);
        $this->assertSame('Wait!', get_post_meta($post->ID, 'popup_title', true));
        $settings = get_post_meta($post->ID, 'popup_settings', true);
        $this->assertSame('small', $settings['size']);
        $this->assertSame(2000, $settings['triggers'][0]['settings']['delay']);
        $this->assertSame([], $settings['cookies']);
        $this->assertFalse($p['enabled']);

        $this->assertNotEmpty($out['operation_ids'] ?? []);
        foreach ((array) $out['operation_ids'] as $operation) {
            Rollback_Service::restore_operation((string) $operation);
        }
        $this->assertSame('trash', get_post_status($post->ID));
    }

    public function test_content_is_filtered_for_users_without_unfiltered_html(): void
    {
        add_filter('map_meta_cap', $deny = static fn (array $caps, string $cap): array => 'unfiltered_html' === $cap ? [ 'do_not_allow' ] : $caps, 10, 2);
        kses_init();
        try {
            $out = $this->ok($this->write('popupmaker-create-popup', [ 'title' => 'x', 'content' => '<p>Hi</p><script>steal()</script>' ]));
            $this->assertStringNotContainsString('<script', get_post($out['result']['popup']['id'])->post_content);
            $id  = $this->popup();
            $this->ok($this->write('popupmaker-update-popup', [ 'id' => $id, 'content' => '<p>Ok</p><script>steal()</script>' ]));
            $this->assertStringNotContainsString('<script', get_post($id)->post_content);
        } finally {
            remove_filter('map_meta_cap', $deny, 10);
            kses_remove_filters();
        }
    }

    public function test_update_popup_merges_the_settings_and_rolls_back_exactly(): void
    {
        $id     = $this->popup('Alpha');
        $theme  = self::factory()->post->create([ 'post_type' => 'popup_theme', 'post_title' => 'Dark', 'post_status' => 'publish' ]);
        $before = [ get_post($id)->post_title, get_post($id)->post_content, get_post_meta($id, 'popup_settings', true), get_post_meta($id, 'popup_title', true) ];

        $this->assertSame('theme_not_found', $this->write('popupmaker-update-popup', [ 'id' => $id, 'theme_id' => $id ])['error']['code'] ?? null);
        $out = $this->ok($this->write('popupmaker-update-popup', [ 'id' => $id, 'title' => 'Alpha 2', 'display_title' => 'New!', 'content' => '<p>new</p>', 'size' => 'large', 'location' => 'center top', 'close_on_overlay_click' => true, 'theme_id' => $theme ]));
        $this->assertTrue($out['recoverable']);
        $settings = get_post_meta($id, 'popup_settings', true);
        $this->assertSame('Alpha 2', get_post($id)->post_title);
        $this->assertSame('<p>new</p>', get_post($id)->post_content);
        $this->assertSame('New!', get_post_meta($id, 'popup_title', true));
        $this->assertSame('large', $settings['size']);
        $this->assertTrue($settings['close_on_overlay_click']);
        $this->assertSame($theme, $settings['theme_id']);
        $this->assertSame('center top', $settings['location']);
        $this->assertSame('fade', $settings['animation_type'], 'untouched');
        $this->assertSame('1999999999', $settings['zindex'], 'settings the op does not name stay');
        $this->assertSame('auto_open', $settings['triggers'][0]['type']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, [ get_post($id)->post_title, get_post($id)->post_content, get_post_meta($id, 'popup_settings', true), get_post_meta($id, 'popup_title', true) ]);
    }

    public function test_update_popup_refuses_a_non_popup_and_a_trashed_popup(): void
    {
        $id = $this->popup();
        $this->assertSame('popup_not_found', $this->write('popupmaker-update-popup', [ 'id' => self::factory()->post->create(), 'title' => 'x' ])['error']['code'] ?? null);
        wp_trash_post($id);
        $this->assertSame('popup_not_found', $this->write('popupmaker-update-popup', [ 'id' => $id, 'title' => 'x' ])['error']['code'] ?? null);
    }

    public function test_set_popup_triggers_replaces_triggers_and_cookies_and_rolls_back(): void
    {
        $id     = $this->popup();
        $before = get_post_meta($id, 'popup_settings', true);

        $out = $this->ok($this->write('popupmaker-set-popup-triggers', [
            'id'       => $id,
            'triggers' => [
                [ 'type' => 'click_open', 'settings' => [ 'extra_selectors' => '.open-offer, #cta', 'cookie_name' => [ 'offer-seen' ] ] ],
                [ 'type' => 'auto_open', 'settings' => [ 'delay' => 3000 ] ],
            ],
            'cookies'  => [ [ 'event' => 'on_popup_open', 'settings' => [ 'name' => 'offer-seen', 'time' => '7 days', 'session' => false, 'path' => '1', 'key' => '' ] ] ],
        ]));
        $this->assertTrue($out['recoverable']);
        $settings = get_post_meta($id, 'popup_settings', true);
        $this->assertSame([ 'click_open', 'auto_open' ], array_column($settings['triggers'], 'type'));
        $this->assertSame('.open-offer, #cta', $settings['triggers'][0]['settings']['extra_selectors']);
        $this->assertSame([ 'offer-seen' ], $settings['triggers'][0]['settings']['cookie_name']);
        $this->assertSame(3000, $settings['triggers'][1]['settings']['delay']);
        $this->assertSame('on_popup_open', $settings['cookies'][0]['event']);
        $this->assertSame('medium', $settings['size'], 'other settings stay');
        $this->assertSame([ 'click_open', 'auto_open' ], array_column($out['result']['triggers'], 'type'));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, get_post_meta($id, 'popup_settings', true));
    }

    public function test_set_popup_triggers_without_cookies_keeps_the_cookies(): void
    {
        $id = $this->popup();
        $this->ok($this->write('popupmaker-set-popup-triggers', [ 'id' => $id, 'triggers' => [] ]));
        $settings = get_post_meta($id, 'popup_settings', true);
        $this->assertSame([], $settings['triggers']);
        $this->assertSame('on_popup_close', $settings['cookies'][0]['event']);
    }

    public function test_set_popup_triggers_refuses_malformed_triggers(): void
    {
        $id = $this->popup();
        $this->assertSame('trigger_invalid', $this->write('popupmaker-set-popup-triggers', [ 'id' => $id, 'triggers' => [ [ 'type' => 'Bad Type!', 'settings' => [] ] ] ])['error']['code'] ?? null);
        $this->assertSame('trigger_invalid', $this->write('popupmaker-set-popup-triggers', [ 'id' => $id, 'triggers' => [ [ 'type' => 'auto_open', 'settings' => [ 'delay' => [ 'deep' => [ 'x' => 1 ] ] ] ] ] ])['error']['code'] ?? null);
        $this->assertSame('cookie_invalid', $this->write('popupmaker-set-popup-triggers', [ 'id' => $id, 'triggers' => [], 'cookies' => [ [ 'event' => '', 'settings' => [] ] ] ])['error']['code'] ?? null);
        $this->assertSame('auto_open', get_post_meta($id, 'popup_settings', true)['triggers'][0]['type']);
    }

    public function test_disable_and_enable_switch_the_popup_and_each_rolls_back(): void
    {
        $id = $this->popup('Alpha', [], 'draft');

        $on = $this->ok($this->write('popupmaker-enable-popup', [ 'id' => $id ]));
        $this->assertTrue($on['recoverable']);
        $this->assertSame('publish', get_post_status($id), 'enabling a draft publishes it');
        $this->assertSame('1', (string) get_post_meta($id, 'enabled', true));
        $this->assertTrue($on['result']['popup']['enabled']);

        $off = $this->ok($this->write('popupmaker-disable-popup', [ 'id' => $id ]));
        $this->assertSame('0', (string) get_post_meta($id, 'enabled', true));
        $this->assertSame('publish', get_post_status($id), 'disable flips the switch only, as Popup Maker\'s own toggle does');
        $this->assertFalse($off['result']['popup']['enabled']);

        $this->assertTrue(Rollback_Service::restore_operation($off['operation_id']));
        $this->assertSame('1', (string) get_post_meta($id, 'enabled', true));
        $this->assertTrue(Rollback_Service::restore_operation($on['operation_id']));
        $this->assertSame('draft', get_post_status($id));
    }
}
