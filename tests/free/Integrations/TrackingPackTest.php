<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\Tracking_Pack;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #899: tracking and tag plugin IDs and events on the plugin-data
 * pair. None of the plugins is loaded in the shared test core; presence is
 * driven through wpmcp_gtm4wp_active, wpmcp_pixelyoursite_active,
 * wpmcp_meta_pixel_active and wpmcp_clarity_active, and each plugin's
 * storage is seeded in the shape the real plugin writes it:
 *
 *  - GTM4WP 2.1.1: one 'gtm4wp-options' option, containers as
 *    'gtm-containers' rows (id, gtm_auth, gtm_preview, domain, path, no_id),
 *    excluded roles as a comma separated 'gtm-no-gtm-for-logged-in';
 *  - PixelYourSite 11.4.5: rows of {prefix}pys_options named pys_<module>,
 *    each a serialized array (pixel_id, tracking_id and gtm_id are lists);
 *  - Meta Pixel for WordPress 5.2.2: 'facebook_business_extension_config'
 *    with facebook_pixel_id and facebook_access_token;
 *  - Microsoft Clarity 0.10.38: 'clarity_project_id'.
 */
class TrackingPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'tracking-get-settings'    => 'read',
        'tracking-find-duplicates' => 'read',
        'gtm4wp-set-settings'      => 'write',
        'clarity-set-project-id'   => 'write',
    ];

    private const PRESENCE = [ 'wpmcp_gtm4wp_active', 'wpmcp_pixelyoursite_active', 'wpmcp_meta_pixel_active', 'wpmcp_clarity_active' ];

    private const GTM_AUTH   = 'aBcDeFgHiJkLmNoP_secret';
    private const META_TOKEN = 'EAAGm0PX4ZCpsBAMetaToken0123456789';
    private const CAPI_TOKEN = 'EAAPysServerToken9876543210abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        foreach (self::PRESENCE as $filter) {
            add_filter($filter, '__return_true');
        }
        add_filter(Tracking_Pack::ENABLE_FILTER, '__return_true');

        update_option('gtm4wp-options', [
            'gtm-containers'              => [
                [ 'id' => 'GTM-ABC123', 'gtm_auth' => self::GTM_AUTH, 'gtm_preview' => 'env-5', 'domain' => '', 'path' => '', 'no_id' => '' ],
            ],
            'gtm-code-placement'          => 1,
            'gtm-datalayer-variable-name' => 'dataLayer',
            'gtm-no-gtm-for-logged-in'    => 'administrator,editor',
            'gtm-production-only'         => false,
            'event-form-move'             => true,
            'event-vimeo'                 => false,
            'include-posttype'            => true,
            'include-useremail'           => false,
            'integrate-wpcf7'             => true,
            'integrate-woocommerce-track-enhanced-ecommerce' => false,
            'integrate-woocommerce-business-vertical' => 'retail',
        ]);
        update_option('facebook_business_extension_config', [
            'facebook_pixel_id'          => '111222333444555',
            'facebook_access_token'      => self::META_TOKEN,
            'facebook_is_fbe_installed'  => '1',
        ]);
        update_option('clarity_project_id', 'abcde12345');

        self::pys_table();
        self::pys_row('pys_core', [ 'do_not_track_user_roles' => [ 'administrator' ], 'automatic_events_enabled' => true ]);
        self::pys_row('pys_facebook', [ 'enabled' => true, 'pixel_id' => [ '111222333444555' ], 'server_access_api_token' => self::CAPI_TOKEN, 'woo_purchase_enabled' => true, 'woo_add_to_cart_enabled' => false ]);
        self::pys_row('pys_gtm', [ 'enabled' => true, 'gtm_id' => [ 'GTM-ABC123' ] ]);
        self::pys_row('pys_ga', [ 'enabled' => false, 'tracking_id' => [ 'G-ZZZ999XYZ' ] ]);
    }

    protected function tearDown(): void
    {
        foreach (array_merge(self::PRESENCE, [ Tracking_Pack::ENABLE_FILTER, 'wpmcp_tracking_theme_dirs' ]) as $hook) {
            remove_all_filters($hook);
        }
        foreach ([ 'gtm4wp-options', 'facebook_business_extension_config', 'clarity_project_id' ] as $option) {
            delete_option($option);
        }
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}pys_options"); // phpcs:ignore WordPress.DB
        parent::tearDown();
    }

    private static function pys_table(): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}pys_options (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, option_name VARCHAR(191) NOT NULL, option_value LONGTEXT NOT NULL, migrated TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY (id), UNIQUE KEY option_name (option_name))");
    }

    private static function pys_row(string $name, array $value): void
    {
        global $wpdb;
        $wpdb->replace($wpdb->prefix . 'pys_options', [ 'option_name' => $name, 'option_value' => maybe_serialize($value) ]);
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    public function test_the_catalog_lists_every_op_at_manage_options(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
            $this->assertSame('manage_options', $ops[ $name ]['capability'], $name);
        }
    }

    public function test_with_no_tracking_plugin_the_reads_answer_plugin_missing(): void
    {
        foreach (self::PRESENCE as $filter) {
            remove_all_filters($filter);
            add_filter($filter, '__return_false');
        }
        $out = $this->read('tracking-get-settings');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('GTM4WP', $out['error']['message']);

        $out = $this->write('clarity-set-project-id', [ 'project_id' => 'zzzzz99999' ]);
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null);
        $this->assertStringContainsString('Microsoft Clarity', $out['error']['message']);
        $this->assertSame('abcde12345', get_option('clarity_project_id'));
    }

    public function test_asking_for_one_inactive_plugin_names_it(): void
    {
        remove_all_filters('wpmcp_pixelyoursite_active');
        add_filter('wpmcp_pixelyoursite_active', '__return_false');
        $out = $this->read('tracking-get-settings', [ 'plugin' => 'pixelyoursite' ]);
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('PixelYourSite', $out['error']['message']);
    }

    public function test_the_read_reports_ids_events_and_roles_and_never_a_token(): void
    {
        $out = $this->ok($this->read('tracking-get-settings'))['result'];

        $gtm = $out['plugins']['gtm4wp'];
        $this->assertSame([ [ 'id' => 'GTM-ABC123', 'environment' => [ 'preview' => 'env-5', 'auth_set' => true ], 'domain' => '', 'path' => '', 'no_id' => false ] ], $gtm['containers']);
        $this->assertSame('body_open', $gtm['placement']);
        $this->assertSame('dataLayer', $gtm['datalayer_name']);
        $this->assertSame([ 'administrator', 'editor' ], $gtm['excluded_roles']);
        $this->assertTrue($gtm['events']['event-form-move']);
        $this->assertFalse($gtm['events']['event-vimeo']);
        $this->assertTrue($gtm['datalayer']['include-posttype']);
        $this->assertFalse($gtm['datalayer']['include-useremail']);
        $this->assertTrue($gtm['integrations']['integrate-wpcf7']);
        $this->assertFalse($gtm['hardcoded']);

        $pys = $out['plugins']['pixelyoursite'];
        $this->assertSame([ '111222333444555' ], $pys['networks']['facebook']['ids']);
        $this->assertTrue($pys['networks']['facebook']['enabled']);
        $this->assertTrue($pys['networks']['facebook']['server_api_token_set']);
        $this->assertTrue($pys['networks']['facebook']['events']['woo_purchase_enabled']);
        $this->assertFalse($pys['networks']['facebook']['events']['woo_add_to_cart_enabled']);
        $this->assertSame([ 'GTM-ABC123' ], $pys['networks']['gtm']['ids']);
        $this->assertSame([ 'G-ZZZ999XYZ' ], $pys['networks']['ga']['ids']);
        $this->assertFalse($pys['networks']['ga']['enabled']);
        $this->assertSame([ 'administrator' ], $pys['excluded_roles']);

        $meta = $out['plugins']['meta-pixel'];
        $this->assertSame('111222333444555', $meta['pixel_id']);
        $this->assertTrue($meta['access_token_set']);
        $this->assertTrue($meta['connected']);

        $this->assertSame('abcde12345', $out['plugins']['clarity']['project_id']);
        $this->assertTrue($out['writes_enabled']);

        $json = (string) wp_json_encode($out);
        foreach ([ self::GTM_AUTH, self::META_TOKEN, self::CAPI_TOKEN ] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_the_read_can_ask_for_one_plugin(): void
    {
        $out = $this->ok($this->read('tracking-get-settings', [ 'plugin' => 'clarity' ]))['result'];
        $this->assertSame([ 'clarity' ], array_keys($out['plugins']));
    }

    public function test_find_duplicates_reports_ids_loaded_twice_and_theme_snippets(): void
    {
        $dir = get_temp_dir() . 'wpmcp-tracking-theme-' . wp_generate_password(6, false);
        wp_mkdir_p($dir);
        file_put_contents($dir . '/header.php', "<script>(function(w,d,s,l,i){})(window,document,'script','dataLayer','GTM-ABC123');</script>\n<script>fbq('init', '999888777666555');</script>\n");
        add_filter('wpmcp_tracking_theme_dirs', static fn (): array => [ $dir ]);
        try {
            $out = $this->ok($this->read('tracking-find-duplicates'))['result'];
        } finally {
            unlink($dir . '/header.php');
            rmdir($dir);
        }

        $dupes = array_column($out['duplicates'], null, 'id');
        $this->assertArrayHasKey('GTM-ABC123', $dupes);
        $this->assertEqualsCanonicalizing([ 'gtm4wp', 'pixelyoursite', 'theme' ], $dupes['GTM-ABC123']['sources']);
        $this->assertArrayHasKey('111222333444555', $dupes);
        $this->assertEqualsCanonicalizing([ 'meta-pixel', 'pixelyoursite' ], $dupes['111222333444555']['sources']);
        $this->assertArrayNotHasKey('abcde12345', $dupes);

        $theme = array_column(array_filter($out['ids'], static fn (array $id): bool => 'theme' === $id['source']), 'id');
        $this->assertContains('999888777666555', $theme);
        $this->assertSame('header.php', $out['ids'][ array_search('999888777666555', array_column($out['ids'], 'id'), true) ]['file']);
        $this->assertNotEmpty($out['warnings']);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        remove_all_filters(Tracking_Pack::ENABLE_FILTER);
        $out = $this->write('clarity-set-project-id', [ 'project_id' => 'zzzzz99999' ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString(Tracking_Pack::ENABLE_FILTER, $out['error']['message']);
        $this->assertSame('abcde12345', get_option('clarity_project_id'));
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_gtm4wp_set_settings_writes_the_option_and_rolls_back_exactly(): void
    {
        $before = get_option('gtm4wp-options');
        $out    = $this->ok($this->write('gtm4wp-set-settings', [
            'container_ids'  => [ 'gtm-abc123', 'GTM-NEW9' ],
            'excluded_roles' => [ 'administrator' ],
            'toggles'        => [ 'event-vimeo' => true, 'integrate-wpcf7' => false ],
        ]));
        $this->assertTrue($out['recoverable']);

        $stored = get_option('gtm4wp-options');
        $this->assertSame([ 'GTM-ABC123', 'GTM-NEW9' ], array_column($stored['gtm-containers'], 'id'));
        $this->assertSame(self::GTM_AUTH, $stored['gtm-containers'][0]['gtm_auth'], 'a kept container keeps its environment');
        $this->assertSame('', $stored['gtm-containers'][1]['gtm_auth']);
        $this->assertSame('administrator', $stored['gtm-no-gtm-for-logged-in']);
        $this->assertTrue($stored['event-vimeo']);
        $this->assertFalse($stored['integrate-wpcf7']);
        $this->assertSame('retail', $stored['integrate-woocommerce-business-vertical'], 'other settings are untouched');
        $this->assertStringNotContainsString(self::GTM_AUTH, (string) wp_json_encode($out));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, get_option('gtm4wp-options'));
    }

    public function test_gtm4wp_set_settings_refuses_bad_input_and_no_change(): void
    {
        $cases = [
            'invalid_gtm_id'          => [ 'container_ids' => [ 'UA-12345-1' ] ],
            'unknown_role'            => [ 'excluded_roles' => [ 'no_such_role' ] ],
            'setting_not_writable'    => [ 'toggles' => [ 'include-useremail' => true ] ],
            'invalid_datalayer_name'  => [ 'datalayer_name' => 'data-layer' ],
            'no_change'               => [ 'toggles' => [ 'event-form-move' => true ] ],
            'invalid_args'            => [],
        ];
        foreach ($cases as $code => $args) {
            $out = $this->write('gtm4wp-set-settings', $args);
            $this->assertSame($code, $out['error']['code'] ?? null, $code . ': ' . wp_json_encode($out));
        }
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_clarity_set_project_id_validates_and_rolls_back(): void
    {
        $out = $this->write('clarity-set-project-id', [ 'project_id' => 'bad id!' ]);
        $this->assertSame('invalid_project_id', $out['error']['code'] ?? null);

        $out = $this->ok($this->write('clarity-set-project-id', [ 'project_id' => 'zzzzz99999' ]));
        $this->assertSame('zzzzz99999', get_option('clarity_project_id'));
        $this->assertSame('abcde12345', $out['result']['before']);
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('abcde12345', get_option('clarity_project_id'));

        delete_option('clarity_project_id');
        $out = $this->ok($this->write('clarity-set-project-id', [ 'project_id' => 'fresh00001' ]));
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertFalse(get_option('clarity_project_id'), 'an option the write created is removed on undo');
    }

    public function test_an_editor_is_refused(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertArrayHasKey('error', $this->read('tracking-get-settings'));
        $this->assertArrayHasKey('error', $this->write('clarity-set-project-id', [ 'project_id' => 'zzzzz99999' ]));
        $this->assertSame('abcde12345', get_option('clarity_project_id'));
    }
}
