<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #316: rolling back a framework pack write refreshes the theme's
 * generated CSS the same way the forward write did, so the front end stops
 * serving the undone styles. Only the ACTIVE family's refresh runs.
 */
class ThemePackRollbackRefreshTest extends \WP_UnitTestCase
{
    /** @var callable|null */
    private $template = null;

    /** @var string[] */
    private array $refreshed = [];

    /** @var callable */
    private $spy;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_enable_theme_write', '__return_true');
        require_once __DIR__ . '/../../support/astra-stubs.php';
        \Astra_Cache_Base::$calls = [];
        $this->spy = function (string $family): void {
            $this->refreshed[] = $family;
        };
        add_action('wpmcp_theme_framework_cache_refresh', $this->spy);
    }

    protected function tearDown(): void
    {
        $this->deactivate();
        remove_action('wpmcp_theme_framework_cache_refresh', $this->spy);
        remove_filter('wpmcp_enable_theme_write', '__return_true');
        delete_option('astra-settings');
        delete_option('kadence_global_palette');
        delete_option('theme_mods_' . get_option('stylesheet'));
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
        \Astra_Cache_Base::$calls = [];
        $this->refreshed          = [];
        return $out;
    }

    public function test_astra_rollback_refreshes_the_compiled_css(): void
    {
        update_option('astra-settings', [ 'theme-color' => '#111111' ]);
        $this->activate('astra');
        $out = $this->write('astra', [ 'theme-color' => '#222222' ]);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));

        $this->assertSame([ 'theme-color' => '#111111' ], get_option('astra-settings'));
        $this->assertSame([ 'astra' ], \Astra_Cache_Base::$calls, 'Astra rebuilds its CSS from the restored settings');
        $this->assertSame([ 'astra' ], $this->refreshed);
    }

    public function test_astra_session_rollback_refreshes_the_compiled_css(): void
    {
        update_option('astra-settings', [ 'theme-color' => '#111111' ]);
        $this->activate('astra');
        $out = $this->write('astra', [ 'theme-color' => '#222222' ]);
        $session = (string) Snapshot_Store::get_by_operation((string) $out['operation_id'])['session_id'];

        $this->assertGreaterThan(0, Rollback_Service::restore_session($session));

        $this->assertSame([ 'theme-color' => '#111111' ], get_option('astra-settings'));
        $this->assertContains('astra', \Astra_Cache_Base::$calls);
    }

    public function test_astra_rollback_does_not_refresh_when_astra_is_not_active(): void
    {
        update_option('astra-settings', [ 'theme-color' => '#111111' ]);
        $this->activate('astra');
        $out = $this->write('astra', [ 'theme-color' => '#222222' ]);
        $this->deactivate();

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));

        $this->assertSame([ 'theme-color' => '#111111' ], get_option('astra-settings'));
        $this->assertSame([], \Astra_Cache_Base::$calls);
        $this->assertSame([], $this->refreshed);
    }

    public function test_kadence_rollback_of_a_two_store_write_refreshes_once(): void
    {
        $this->activate('kadence');
        $out = $this->write('kadence', [ 'palette2' => '#123456', 'header_sticky' => 'top_main' ]);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));

        $this->assertFalse(get_option('kadence_global_palette'));
        $this->assertSame([ 'kadence' ], $this->refreshed);
    }

    public function test_kadence_rollback_does_not_refresh_while_another_family_is_active(): void
    {
        $this->activate('kadence');
        $out = $this->write('kadence', [ 'palette2' => '#123456' ]);
        $this->activate('astra');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));

        $this->assertSame([], $this->refreshed);
        $this->assertSame([], \Astra_Cache_Base::$calls);
    }

    public function test_unrelated_option_rollback_refreshes_nothing(): void
    {
        $this->activate('astra');
        update_option('blogname', 'Before');
        $snapshot = Snapshot::capture('option', 'blogname');
        update_option('blogname', 'After');

        Rollback_Service::apply_snapshot($snapshot);

        $this->assertSame('Before', get_option('blogname'));
        $this->assertSame([], \Astra_Cache_Base::$calls);
        $this->assertSame([], $this->refreshed);
    }

    public function test_option_restores_announce_the_restored_option_names(): void
    {
        $seen = [];
        $spy  = static function (array $names) use (&$seen): void {
            $seen[] = $names;
        };
        add_action('wpmcp_rollback_options_restored', $spy);
        update_option('wpmcp_t316_a', 'a');
        $single = Snapshot::capture('option', 'wpmcp_t316_a');
        $set    = Snapshot::capture('option_set', Snapshot::option_set_id([ 'wpmcp_t316_a', 'wpmcp_t316_b' ]));

        Rollback_Service::apply_snapshot($single);
        Rollback_Service::apply_snapshot($set);
        remove_action('wpmcp_rollback_options_restored', $spy);
        delete_option('wpmcp_t316_a');

        $this->assertSame([ [ 'wpmcp_t316_a' ], [ 'wpmcp_t316_a', 'wpmcp_t316_b' ] ], $seen);
    }
}
