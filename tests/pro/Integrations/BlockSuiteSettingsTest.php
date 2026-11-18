<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Block_Suites_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Kadence Blocks and Spectra plugin settings and generated page CSS on the
 * block-suites pair (issue #648): get-settings, update-settings,
 * get-page-styles and rebuild-page-styles.
 *
 * Neither plugin is installed in the test core. The options are seeded in
 * the shapes the plugins store them (Kadence: JSON strings registered with
 * register_setting(); Spectra: one plain option per setting, and the
 * _uag_page_assets post meta its UAGB_Post_Assets::update_page_assets()
 * writes), and presence is toggled through wpmcp_block_suite_active.
 */
class BlockSuiteSettingsTest extends \WP_UnitTestCase
{
    /** @var callable */
    private $presence;

    /** @var array<string,bool> */
    private array $active = [ 'kadence-blocks' => true, 'spectra' => true ];

    private const OPT_IN = 'wpmcp_enable_block_suite_settings_write';

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        wpmcp_test_grant_network_admin(get_current_user_id());
        $this->presence = fn ($default, string $suite): bool => $this->active[ $suite ] ?? false;
        add_filter('wpmcp_block_suite_active', $this->presence, 10, 2);
    }

    protected function tearDown(): void
    {
        remove_filter('wpmcp_block_suite_active', $this->presence, 10);
        remove_all_filters(self::OPT_IN);
        foreach ([ 'kadence_blocks_settings', 'kadence_blocks_colors', 'kadence_blocks_settings_blocks', 'kadence_blocks_config_blocks', 'kadence_blocks_recaptcha_secret_key', '_uagb_allow_file_generation', 'uag_enable_templates_button', 'uag_content_width', 'uag_recaptcha_secret_key_v2' ] as $option) {
            delete_option($option);
        }
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function integration(): Block_Suites_Integration
    {
        return new Block_Suites_Integration();
    }

    private function read(string $op, array $args = []): array
    {
        return $this->integration()->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args): array
    {
        return $this->integration()->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function opt_in(): void
    {
        add_filter(self::OPT_IN, '__return_true');
    }

    private function undo(array $out): void
    {
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']), (string) wp_json_encode(Rollback_Service::take_warnings()));
    }

    // ------------------------------------------------------------ settings

    public function test_kadence_settings_are_read_decoded_and_secrets_are_never_returned(): void
    {
        update_option('kadence_blocks_settings', wp_json_encode([ 'performance_optimizer_enabled' => false, 'enable_image_picker' => true ]));
        update_option('kadence_blocks_colors', wp_json_encode([ 'palette' => [ [ 'color' => '#2B6CB0', 'name' => 'Blue', 'slug' => 'palette1' ] ], 'override' => false ]));
        update_option('kadence_blocks_recaptcha_secret_key', 'top-secret');

        $out = $this->read('get-settings', [ 'suite' => 'kadence-blocks' ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $settings = $out['result']['settings'];
        $this->assertSame([ 'performance_optimizer_enabled' => false, 'enable_image_picker' => true ], $settings['settings']);
        $this->assertSame('#2B6CB0', $settings['colors']['palette'][0]['color']);
        $this->assertNull($settings['block_defaults'], 'an unsaved store reads as null, so the plugin default applies');
        $this->assertSame([ 'settings', 'colors', 'block_visibility', 'block_defaults' ], $out['result']['writable']);
        $this->assertStringNotContainsString('top-secret', (string) wp_json_encode($out));
    }

    public function test_spectra_settings_read_with_the_plugin_defaults(): void
    {
        update_option('_uagb_allow_file_generation', 'disabled');
        update_option('uag_recaptcha_secret_key_v2', 'top-secret');

        $out = $this->read('get-settings', [ 'suite' => 'spectra' ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $settings = $out['result']['settings'];
        $this->assertSame('disabled', $settings['enable_file_generation']);
        $this->assertSame('yes', $settings['enable_templates_button'], 'Spectra\'s own default when unsaved');
        $this->assertContains('enable_file_generation', $out['result']['writable']);
        $this->assertStringNotContainsString('top-secret', (string) wp_json_encode($out));
    }

    public function test_settings_ops_refuse_an_inactive_suite(): void
    {
        $this->active['spectra'] = false;
        $out = $this->read('get-settings', [ 'suite' => 'spectra' ]);
        $this->assertSame('suite_unavailable', $out['error']['code'] ?? null);
    }

    public function test_update_settings_is_off_until_the_site_opts_in(): void
    {
        $out = $this->write('update-settings', [ 'suite' => 'spectra', 'settings' => [ 'enable_file_generation' => 'disabled' ] ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null);
        $this->assertStringContainsString(self::OPT_IN, (string) wp_json_encode($out['error']));
        $this->assertFalse(get_option('_uagb_allow_file_generation'));
    }

    public function test_spectra_update_writes_the_option_and_rollback_restores_absence(): void
    {
        $this->opt_in();
        update_option('uag_enable_templates_button', 'yes');

        $out = $this->write('update-settings', [ 'suite' => 'spectra', 'settings' => [ 'enable_file_generation' => 'disabled', 'enable_templates_button' => 'no', 'content_width' => 1200 ] ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('disabled', get_option('_uagb_allow_file_generation'));
        $this->assertSame('no', get_option('uag_enable_templates_button'));
        $this->assertSame(1200, (int) get_option('uag_content_width'));

        $this->undo($out);
        $this->assertFalse(get_option('_uagb_allow_file_generation'), 'an option the write created is deleted again');
        $this->assertSame('yes', get_option('uag_enable_templates_button'));
        $this->assertFalse(get_option('uag_content_width'));
    }

    public function test_spectra_update_refuses_the_whole_batch_on_one_bad_value(): void
    {
        $this->opt_in();
        $out = $this->write('update-settings', [ 'suite' => 'spectra', 'settings' => [ 'enable_file_generation' => 'disabled', 'enable_templates_button' => 'maybe' ] ]);
        $this->assertSame('invalid_setting_value', $out['error']['code'] ?? null);
        $this->assertFalse(get_option('_uagb_allow_file_generation'), 'nothing written');

        $out = $this->write('update-settings', [ 'suite' => 'spectra', 'settings' => [ 'recaptcha_secret_key_v2' => 'x' ] ]);
        $this->assertSame('key_not_allowlisted', $out['error']['code'] ?? null);
    }

    public function test_kadence_update_merges_top_level_keys_and_rollback_restores_bytes(): void
    {
        $this->opt_in();
        $before = wp_json_encode([ 'performance_optimizer_enabled' => false, 'enable_image_picker' => true ]);
        update_option('kadence_blocks_settings', $before);

        $out = $this->write('update-settings', [ 'suite' => 'kadence-blocks', 'settings' => [
            'settings' => [ 'performance_optimizer_enabled' => true, 'enable_image_picker' => null ],
            'colors'   => [ 'palette' => [ [ 'color' => '#111111', 'name' => 'Ink', 'slug' => 'palette1' ] ] ],
        ] ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame([ 'performance_optimizer_enabled' => true ], json_decode((string) get_option('kadence_blocks_settings'), true));
        $this->assertSame('#111111', json_decode((string) get_option('kadence_blocks_colors'), true)['palette'][0]['color']);

        $this->undo($out);
        $this->assertSame($before, get_option('kadence_blocks_settings'));
        $this->assertFalse(get_option('kadence_blocks_colors'));
    }

    public function test_kadence_update_refuses_markup_unknown_stores_and_unreadable_stores(): void
    {
        $this->opt_in();
        $out = $this->write('update-settings', [ 'suite' => 'kadence-blocks', 'settings' => [ 'colors' => [ 'palette' => [ [ 'color' => '</style><script>x</script>' ] ] ] ] ]);
        $this->assertSame('invalid_setting_value', $out['error']['code'] ?? null);

        $out = $this->write('update-settings', [ 'suite' => 'kadence-blocks', 'settings' => [ 'cloud' => [ 'key' => 'x' ] ] ]);
        $this->assertSame('key_not_allowlisted', $out['error']['code'] ?? null);

        update_option('kadence_blocks_settings', 'not json');
        $out = $this->write('update-settings', [ 'suite' => 'kadence-blocks', 'settings' => [ 'settings' => [ 'a' => true ] ] ]);
        $this->assertSame('setting_unreadable', $out['error']['code'] ?? null);
        $this->assertSame('not json', get_option('kadence_blocks_settings'), 'a store the plugin cannot read is never overwritten');
    }

    // ------------------------------------------------------------ page styles

    private function spectra_post(): int
    {
        $id = self::factory()->post->create([ 'post_type' => 'page', 'post_content' => '<!-- wp:uagb/advanced-heading {"block_id":"1a2b3c4d"} /-->' ]);
        update_post_meta($id, '_uag_page_assets', [ 'css' => '.uagb-block-1a2b3c4d{color:red}', 'js' => '', 'uag_version' => '1700000000', 'gfonts' => [ 'Roboto' => [] ] ]);
        update_post_meta($id, '_uag_css_file_name', 'uag-css-' . $id . '.css');
        return $id;
    }

    public function test_spectra_page_styles_read_the_stored_css(): void
    {
        $id  = $this->spectra_post();
        $out = $this->read('get-page-styles', [ 'suite' => 'spectra', 'id' => $id ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $result = $out['result'];
        $this->assertSame('per_post_cache', $result['model']);
        $this->assertTrue($result['cached']);
        $this->assertSame('.uagb-block-1a2b3c4d{color:red}', $result['css']);
        $this->assertSame('1700000000', $result['asset_version']);
        $this->assertSame([ 'Roboto' ], $result['google_fonts']);
        $this->assertSame('uag-css-' . $id . '.css', $result['css_file']);
    }

    public function test_kadence_page_styles_report_render_time_css(): void
    {
        $id  = self::factory()->post->create([ 'post_type' => 'page', 'post_content' => '<!-- wp:kadence/advancedheading {"uniqueID":"5_ab12cd-ef"} /-->' ]);
        $out = $this->read('get-page-styles', [ 'suite' => 'kadence-blocks', 'id' => $id ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame('render_time', $out['result']['model']);
        $this->assertFalse($out['result']['cached']);
        $this->assertNull($out['result']['css']);
        $this->assertSame([ '5_ab12cd-ef' ], $out['result']['unique_ids']);
    }

    public function test_spectra_rebuild_drops_the_cache_and_is_undoable(): void
    {
        $id  = $this->spectra_post();
        $out = $this->write('rebuild-page-styles', [ 'suite' => 'spectra', 'id' => $id ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertTrue($out['result']['refreshed']);
        $this->assertSame('', get_post_meta($id, '_uag_page_assets', true));
        $this->assertSame('', get_post_meta($id, '_uag_css_file_name', true));
        $this->undo($out);
    }

    public function test_kadence_rebuild_is_refused_because_nothing_is_cached(): void
    {
        $id  = self::factory()->post->create([ 'post_type' => 'page' ]);
        $out = $this->write('rebuild-page-styles', [ 'suite' => 'kadence-blocks', 'id' => $id ]);
        $this->assertSame('no_page_style_cache', $out['error']['code'] ?? null);
    }

    public function test_page_styles_need_access_to_the_post(): void
    {
        $id      = $this->spectra_post();
        $private = self::factory()->post->create([ 'post_type' => 'page', 'post_status' => 'private' ]);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'subscriber' ]));
        $out = $this->write('rebuild-page-styles', [ 'suite' => 'spectra', 'id' => $id ]);
        $this->assertSame('operation_denied', $out['error']['code'] ?? null);
        $this->assertNotSame('', get_post_meta($id, '_uag_page_assets', true));

        $out = $this->read('get-page-styles', [ 'suite' => 'spectra', 'id' => $private ]);
        $this->assertSame('operation_denied', $out['error']['code'] ?? null);
    }
}
