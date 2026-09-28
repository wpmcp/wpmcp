<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #316 for the paid packs: rolling back a GeneratePress or Blocksy
 * write refreshes that theme's generated CSS, and only while it is active.
 */
class ThemeProPackRollbackRefreshTest extends \WP_UnitTestCase
{
    /** @var callable|null */
    private $template = null;

    private int $blocksy = 0;

    /** @var callable */
    private $spy;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_theme_write', '__return_true');
        $this->spy = function (): void {
            $this->blocksy++;
        };
        add_action('blocksy:dynamic-css:refresh-caches', $this->spy);
    }

    protected function tearDown(): void
    {
        $this->deactivate();
        remove_action('blocksy:dynamic-css:refresh-caches', $this->spy);
        remove_filter('wpmcp_enable_theme_write', '__return_true');
        delete_option('generate_settings');
        delete_option('generate_dynamic_css_output');
        delete_option('generate_dynamic_css_cached_version');
        delete_option('theme_mods_' . get_option('stylesheet'));
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function activate(string $family): void
    {
        $this->deactivate();
        $this->template = static fn () => $family;
        add_filter('template', $this->template);
    }

    private function deactivate(): void
    {
        if (null !== $this->template) {
            remove_filter('template', $this->template);
            $this->template = null;
        }
    }

    private function write(string $family, array $settings): array
    {
        $out = (new Theme_Integration())->handle_write([ 'operation' => "set-{$family}-settings", 'args' => [ 'settings' => $settings ] ]);
        $this->assertArrayNotHasKey('error', $out);
        return $out;
    }

    /** GeneratePress rebuilt its cached CSS from the written settings on the next page view. */
    private function theme_rebuilt_css(): void
    {
        update_option('generate_dynamic_css_output', 'a{color:#ff0000}');
        update_option('generate_dynamic_css_cached_version', '3.4.0');
    }

    public function test_generatepress_rollback_drops_the_cached_css(): void
    {
        update_option('generate_settings', [ 'container_width' => 1200 ]);
        $this->activate('generatepress');
        $out = $this->write('generatepress', [ 'container_width' => 1000 ]);
        $this->theme_rebuilt_css();

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));

        $this->assertSame([ 'container_width' => 1200 ], get_option('generate_settings'));
        $this->assertFalse(get_option('generate_dynamic_css_output'), 'The CSS built from the undone settings is dropped');
        $this->assertFalse(get_option('generate_dynamic_css_cached_version'));
    }

    public function test_generatepress_rollback_leaves_the_cache_alone_when_not_active(): void
    {
        update_option('generate_settings', [ 'container_width' => 1200 ]);
        $this->activate('generatepress');
        $out = $this->write('generatepress', [ 'container_width' => 1000 ]);
        $this->theme_rebuilt_css();
        $this->activate('blocksy');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));

        $this->assertSame([ 'container_width' => 1200 ], get_option('generate_settings'));
        $this->assertSame('a{color:#ff0000}', get_option('generate_dynamic_css_output'));
        $this->assertSame(0, $this->blocksy, 'The active family did not own the restored option');
    }

    public function test_blocksy_rollback_refreshes_its_dynamic_css(): void
    {
        $this->activate('blocksy');
        $out = $this->write('blocksy', [ 'maxSiteWidth' => 1400 ]);
        $this->blocksy = 0;

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));

        $this->assertSame(1, $this->blocksy);
    }

    public function test_blocksy_rollback_does_not_refresh_when_not_active(): void
    {
        $this->activate('blocksy');
        $out = $this->write('blocksy', [ 'maxSiteWidth' => 1400 ]);
        $this->blocksy = 0;
        $this->deactivate();

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));

        $this->assertSame(0, $this->blocksy);
    }
}
