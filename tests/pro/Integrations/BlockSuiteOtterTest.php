<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Block_Suites_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #647: Otter Blocks' settings, generated page styles and pattern
 * inspection on the block-suites pair.
 *
 * Otter is not installed in the test core: its presence is toggled through
 * the wpmcp_block_suite_active filter, and its settings are the plain
 * options (names and defaults) its register_settings() declares. The real
 * plugin is exercised in BlockSuitesLiveTest (WPMCP_LIVE_BLOCKS=1).
 */
class BlockSuiteOtterTest extends \WP_UnitTestCase
{
    /** @var array<string,bool> */
    private array $active = [ 'otter-blocks' => true ];

    /** @var callable */
    private $presence;

    private bool $opt_in = false;

    /** @var callable */
    private $opt_in_filter;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        wpmcp_test_grant_network_admin(get_current_user_id());
        $this->presence = fn ($default, string $suite): bool => $this->active[ $suite ] ?? false;
        add_filter('wpmcp_block_suite_active', $this->presence, 10, 2);
        $this->opt_in_filter = fn (): bool => $this->opt_in;
        add_filter('wpmcp_enable_otter_settings_write', $this->opt_in_filter);
    }

    protected function tearDown(): void
    {
        remove_filter('wpmcp_block_suite_active', $this->presence, 10);
        remove_filter('wpmcp_enable_otter_settings_write', $this->opt_in_filter);
        if (\WP_Block_Patterns_Registry::get_instance()->is_registered('wpmcp-test/otter-hero')) {
            unregister_block_pattern('wpmcp-test/otter-hero');
        }
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        $out = ( new Block_Suites_Integration() )->handle_read([ 'operation' => $op, 'args' => $args ]);
        return isset($out['result']) && ! isset($out['error']) ? $out['result'] : $out;
    }

    private function write(string $op, array $args): array
    {
        return ( new Block_Suites_Integration() )->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    // ------------------------------------------------------------ settings

    public function test_settings_read_otters_defaults_and_stored_values_without_secrets(): void
    {
        add_option('themeisle_blocks_settings_css_module', false);
        update_option('themeisle_blocks_settings_default_block', '1');
        update_option('themeisle_disabled_blocks', [ 'themeisle-blocks/accordion' ]);
        update_option('themeisle_google_map_block_api_key', 'AIzaSECRETVALUE');

        $out = $this->read('get-otter-settings');
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertFalse($out['features']['css_module']);
        $this->assertTrue($out['features']['blocks_animation'], 'unset: Otter\'s default applies');
        $this->assertFalse($out['features']['atomic_wind_blocks']);
        $this->assertTrue($out['settings']['default_block']);
        $this->assertSame([ 'themeisle-blocks/accordion' ], $out['settings']['disabled_blocks']);
        $this->assertSame([ 'set' => true ], $out['api_keys']['google_maps_api_key']);
        $this->assertSame([ 'set' => false ], $out['api_keys']['stripe_api_key']);
        $this->assertStringNotContainsString('SECRETVALUE', (string) wp_json_encode($out));
    }

    public function test_settings_write_is_off_until_the_site_opts_in(): void
    {
        $out = $this->write('update-otter-settings', [ 'settings' => [ 'css_module' => false ] ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertFalse(get_option('themeisle_blocks_settings_css_module', 'unset') === false);
    }

    public function test_settings_write_and_rollback_restore_every_option(): void
    {
        $this->opt_in = true;
        update_option('themeisle_blocks_settings_blocks_animation', true);

        $out = $this->write('update-otter-settings', [ 'settings' => [
            'blocks_animation' => false,
            'patterns_library' => false,
            'review_scale'     => true,
            'disabled_blocks'  => [ 'themeisle-blocks/countdown', 'themeisle-blocks/countdown' ],
        ] ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertNotEmpty($out['operation_id']);
        $this->assertFalse($out['result']['features']['blocks_animation']);
        $this->assertFalse($out['result']['features']['patterns_library'], 'false sticks on an option Otter never stored');
        $this->assertTrue($out['result']['settings']['review_scale']);
        $this->assertSame([ 'themeisle-blocks/countdown' ], get_option('themeisle_disabled_blocks'));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertTrue((bool) get_option('themeisle_blocks_settings_blocks_animation'));
        $this->assertSame('unset', get_option('themeisle_blocks_settings_review_scale', 'unset'), 'an option the write added is removed again');
        $this->assertSame('unset', get_option('themeisle_disabled_blocks', 'unset'));
        $this->assertSame('unset', get_option('themeisle_blocks_settings_patterns_library', 'unset'));
    }

    public function test_one_bad_key_or_value_refuses_the_batch_before_any_write(): void
    {
        $this->opt_in = true;
        $bad = [
            [ 'css_module' => false, 'google_maps_api_key' => true ],
            [ 'css_module' => false, 'redirect' => false ],
            [ 'css_module' => 'no' ],
            [ 'disabled_blocks' => [ 'not a block' ] ],
            [ 'disabled_blocks' => [ 'a' => 'themeisle-blocks/accordion' ] ],
        ];
        foreach ($bad as $settings) {
            $out = $this->write('update-otter-settings', [ 'settings' => $settings ]);
            $this->assertArrayHasKey('error', $out, (string) wp_json_encode($settings));
            $this->assertArrayNotHasKey('operation_id', $out);
        }
        $this->assertSame('unset', get_option('themeisle_blocks_settings_css_module', 'unset'));
        $this->assertSame('unset', get_option('themeisle_disabled_blocks', 'unset'));
    }

    public function test_otter_ops_refuse_while_otter_is_inactive(): void
    {
        $this->active = [ 'kadence-blocks' => true ];
        $id           = self::factory()->post->create();
        foreach ([ [ 'get-otter-settings', [] ], [ 'get-otter-page-styles', [ 'id' => $id ] ] ] as [ $op, $args ]) {
            $out = $this->read($op, $args);
            $this->assertSame('suite_unavailable', $out['error']['code'] ?? null, $op . ' ' . wp_json_encode($out));
        }
        $out = $this->write('rebuild-otter-page-styles', [ 'id' => $id ]);
        $this->assertSame('suite_unavailable', $out['error']['code'] ?? null);
    }

    // ------------------------------------------------------------ page styles

    public function test_page_styles_report_the_cached_stylesheet(): void
    {
        $id = self::factory()->post->create([ 'post_type' => 'page' ]);
        $out = $this->read('get-otter-page-styles', [ 'id' => $id ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertFalse($out['cached']);
        $this->assertNull($out['file']);

        $file = $this->seed_stylesheet($id);
        $out  = $this->read('get-otter-page-styles', [ 'id' => $id ]);
        $this->assertTrue($out['cached']);
        $this->assertTrue($out['file_exists']);
        $this->assertSame(basename($file), $out['file']);
        $this->assertStringEndsWith('/themeisle-gutenberg/' . basename($file), $out['url']);
        $this->assertSame('#x{color:red}', $out['css']);
        $this->assertSame(13, $out['css_bytes']);
    }

    public function test_rebuild_drops_the_stale_stylesheet_and_is_undoable(): void
    {
        $id   = self::factory()->post->create([ 'post_type' => 'page', 'post_content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ]);
        $file = $this->seed_stylesheet($id);

        $out = $this->write('rebuild-otter-page-styles', [ 'id' => $id ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertNotEmpty($out['operation_id']);
        $this->assertFalse($out['result']['rebuilt'], 'without Otter loaded the cache rebuilds on the next view');
        $this->assertTrue($out['result']['rebuilds_on_view']);
        $this->assertFalse($out['result']['cached']);
        $this->assertFileDoesNotExist($file);
        $this->assertSame('', get_post_meta($id, '_themeisle_gutenberg_block_styles', true));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->', get_post($id)->post_content);
    }

    private function seed_stylesheet(int $id): string
    {
        $name = 'post-v2-' . $id . '-' . wp_rand(1000, 9999);
        $dir  = wp_upload_dir(null, false)['basedir'] . '/themeisle-gutenberg';
        wp_mkdir_p($dir);
        $file = $dir . '/' . $name . '.css';
        file_put_contents($file, '#x{color:red}');
        update_post_meta($id, '_themeisle_gutenberg_block_stylesheet', $name);
        update_post_meta($id, '_themeisle_gutenberg_block_styles', '#x{color:red}');
        return $file;
    }

    // ------------------------------------------------------------ patterns

    public function test_get_pattern_returns_its_markup_and_suites(): void
    {
        $markup = '<!-- wp:themeisle-blocks/advanced-heading {"id":"wp-block-themeisle-blocks-advanced-heading-1a2b3c4d"} --><h2>Hi</h2><!-- /wp:themeisle-blocks/advanced-heading -->';
        register_block_pattern('wpmcp-test/otter-hero', [ 'title' => 'Otter hero', 'categories' => [ 'otter-blocks' ], 'content' => $markup ]);

        $out = $this->read('get-pattern', [ 'name' => 'wpmcp-test/otter-hero' ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame('Otter hero', $out['title']);
        $this->assertSame([ 'otter-blocks' ], $out['categories']);
        $this->assertSame([ 'otter-blocks' ], $out['suites']);
        $this->assertSame($markup, $out['content']);

        $out = $this->read('get-pattern', [ 'name' => 'wpmcp-test/missing' ]);
        $this->assertSame('unknown_pattern', $out['error']['code'] ?? null);
    }
}
