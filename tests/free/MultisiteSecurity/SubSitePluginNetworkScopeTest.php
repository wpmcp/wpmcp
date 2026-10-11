<?php

namespace WPMCP\Tests\Free\MultisiteSecurity;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Packages\Activate_Plugin;
use WPMCP\Tools\Packages\Deactivate_Plugin;
use WPMCP\Tools\Packages\Install_Plugin;
use WPMCP\Tools\Rollback_Operation;

/**
 * activate-plugin, deactivate-plugin and install-plugin's activate step on a
 * network (issue #557).
 *
 * Core's site-level Plugins screen refuses a network-only plugin's
 * activation and a network-active plugin's deactivation, and only the
 * network screen (manage_network_plugins) changes active_sitewide_plugins.
 * The tools follow the same rules: a site write stays on the site, and a
 * network write takes an explicit network_wide: true from a network admin.
 * Whenever a write can touch active_sitewide_plugins, its snapshot carries
 * that option too, so rollback-operation restores both lists.
 */
class SubSitePluginNetworkScopeTest extends \WP_UnitTestCase
{
    private int $blog = 0;
    private int $admin = 0;
    private int $super = 0;
    private string $network_only = '';
    private string $regular = '';

    public function set_up(): void
    {
        parent::set_up();
        if (! is_multisite()) {
            $this->markTestSkipped('Runs under WP_MULTISITE=1.');
        }
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $suffix             = wp_generate_password(6, false, false);
        $this->network_only = 'wpmcp-net-only-' . $suffix . '.php';
        $this->regular      = 'wpmcp-net-regular-' . $suffix . '.php';
        file_put_contents(
            WP_PLUGIN_DIR . '/' . $this->network_only,
            "<?php\n/*\nPlugin Name: WPMCP Network Only Fixture\nNetwork: true\n*/\n"
        );
        file_put_contents(
            WP_PLUGIN_DIR . '/' . $this->regular,
            "<?php\n/*\nPlugin Name: WPMCP Regular Fixture\n*/\n"
        );
        wp_clean_plugins_cache(false);

        // Let site admins see the Plugins menu, as core's network setting does.
        update_site_option('menu_items', ['plugins' => '1']);

        $this->setExpectedDeprecated('wpmu_new_blog');
        $this->blog  = (int) self::factory()->blog->create();
        $this->admin = (int) self::factory()->user->create(['role' => 'subscriber']);
        $this->super = (int) self::factory()->user->create();
        grant_super_admin($this->super);
        add_user_to_blog($this->blog, $this->admin, 'administrator');
        switch_to_blog($this->blog);
        Snapshot_Store::install();
        wp_set_current_user($this->admin);
        $this->assertFalse(is_super_admin($this->admin));
        $this->assertTrue(current_user_can('activate_plugins'));
        $this->assertFalse(current_user_can('manage_network_plugins'));
    }

    public function tear_down(): void
    {
        if (is_multisite()) {
            $network = (array) get_site_option('active_sitewide_plugins', []);
            unset($network[ $this->network_only ], $network[ $this->regular ]);
            update_site_option('active_sitewide_plugins', $network);
            delete_site_option('menu_items');
            if ($this->super > 0) {
                revoke_super_admin($this->super);
            }
            if (ms_is_switched()) {
                restore_current_blog();
            }
        }
        foreach ([ $this->network_only, $this->regular ] as $file) {
            if ('' !== $file && is_file(WP_PLUGIN_DIR . '/' . $file)) {
                unlink(WP_PLUGIN_DIR . '/' . $file);
            }
        }
        wp_clean_plugins_cache(false);
        parent::tear_down();
    }

    private function network_activate(string $file): void
    {
        $network          = (array) get_site_option('active_sitewide_plugins', []);
        $network[ $file ] = time();
        update_site_option('active_sitewide_plugins', $network);
        $this->assertTrue(is_plugin_active_for_network($file));
    }

    /** Runs $call, returning the refusal message, or null when it did not throw. */
    private function refusal(callable $call): ?string
    {
        try {
            $call();
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
        return null;
    }

    public function test_site_admin_is_refused_a_network_only_plugin_before_any_write(): void
    {
        $this->assertTrue(is_network_only_plugin($this->network_only));
        $rows = Snapshot_Store::row_count();

        $error = $this->refusal(fn () => (new Activate_Plugin())->handle(['plugin' => $this->network_only]));

        $this->assertNotNull($error, 'activate-plugin must refuse a network-only plugin on a site.');
        $this->assertStringContainsString('network', $error);
        $this->assertFalse(is_plugin_active_for_network($this->network_only));
        $this->assertNotContains($this->network_only, (array) get_option('active_plugins', []));
        $this->assertSame($rows, Snapshot_Store::row_count(), 'A refused activation must leave no snapshot.');
    }

    public function test_site_admin_is_refused_network_wide_activation(): void
    {
        $rows = Snapshot_Store::row_count();

        $error = $this->refusal(fn () => (new Activate_Plugin())->handle(['plugin' => $this->regular, 'network_wide' => true]));

        $this->assertNotNull($error, 'network_wide: true must take manage_network_plugins.');
        $this->assertStringContainsString('manage_network_plugins', $error);
        $this->assertFalse(is_plugin_active_for_network($this->regular));
        $this->assertSame($rows, Snapshot_Store::row_count());
    }

    public function test_site_admin_is_refused_deactivating_a_network_active_plugin(): void
    {
        $this->network_activate($this->network_only);
        $rows = Snapshot_Store::row_count();

        $error = $this->refusal(fn () => (new Deactivate_Plugin())->handle(['plugin' => $this->network_only]));

        $this->assertNotNull($error, 'deactivate-plugin must refuse a plugin active for the network on a site.');
        $this->assertTrue(is_plugin_active_for_network($this->network_only), 'A site admin deactivated a plugin for every site on the network.');
        $this->assertSame($rows, Snapshot_Store::row_count(), 'A refused deactivation must leave no snapshot.');
    }

    public function test_install_activate_step_never_network_activates_for_a_site_admin(): void
    {
        $rows = Snapshot_Store::row_count();

        $error = $this->refusal(fn () => (new Install_Plugin())->activate_installed($this->network_only, ['activate' => true]));

        $this->assertNotNull($error, 'install-plugin activate: true must refuse a network-only plugin on a site.');
        $this->assertFalse(is_plugin_active_for_network($this->network_only), 'install-plugin activate: true network-activated a Network: true plugin.');
        $this->assertSame($rows, Snapshot_Store::row_count());
    }

    public function test_site_admin_still_activates_a_regular_plugin_on_the_site_only(): void
    {
        $before = (array) get_site_option('active_sitewide_plugins', []);

        $out = (new Activate_Plugin())->handle(['plugin' => $this->regular]);

        $this->assertContains($this->regular, (array) get_option('active_plugins', []));
        $this->assertFalse(is_plugin_active_for_network($this->regular));
        $this->assertSame($before, (array) get_site_option('active_sitewide_plugins', []));

        $restored = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($restored['restored']);
        $this->assertNotContains($this->regular, (array) get_option('active_plugins', []));
    }

    public function test_network_only_plugin_needs_network_wide_even_for_a_network_admin(): void
    {
        wp_set_current_user($this->super);
        $rows = Snapshot_Store::row_count();

        $error = $this->refusal(fn () => (new Activate_Plugin())->handle(['plugin' => $this->network_only]));

        $this->assertNotNull($error, 'Core only network-activates a network-only plugin from the network screen.');
        $this->assertStringContainsString('network_wide', $error);
        $this->assertFalse(is_plugin_active_for_network($this->network_only));
        $this->assertSame($rows, Snapshot_Store::row_count());
    }

    public function test_network_admin_network_activation_is_fully_rolled_back(): void
    {
        wp_set_current_user($this->super);

        $out = (new Activate_Plugin())->handle(['plugin' => $this->network_only, 'network_wide' => true]);
        $this->assertTrue(is_plugin_active_for_network($this->network_only));

        $restored = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $this->assertTrue($restored['restored']);
        $this->assertFalse(
            is_plugin_active_for_network($this->network_only),
            'rollback-operation reported restored but the plugin is still network-active.'
        );
    }

    public function test_network_admin_network_deactivation_is_fully_rolled_back(): void
    {
        wp_set_current_user($this->super);
        $this->network_activate($this->regular);

        $out = (new Deactivate_Plugin())->handle(['plugin' => $this->regular, 'network_wide' => true]);
        $this->assertFalse(is_plugin_active_for_network($this->regular));

        $restored = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $this->assertTrue($restored['restored']);
        $this->assertTrue(
            is_plugin_active_for_network($this->regular),
            'rollback-operation reported restored but the network deactivation was not undone.'
        );
    }

    public function test_network_active_plugin_needs_network_wide_to_deactivate_even_for_a_network_admin(): void
    {
        wp_set_current_user($this->super);
        $this->network_activate($this->regular);
        $rows = Snapshot_Store::row_count();

        $error = $this->refusal(fn () => (new Deactivate_Plugin())->handle(['plugin' => $this->regular]));

        $this->assertNotNull($error);
        $this->assertStringContainsString('network_wide', $error);
        $this->assertTrue(is_plugin_active_for_network($this->regular));
        $this->assertSame($rows, Snapshot_Store::row_count());
    }

    public function test_network_admin_install_activation_is_fully_rolled_back(): void
    {
        wp_set_current_user($this->super);

        $out = (new Install_Plugin())->activate_installed($this->regular, ['activate' => true, 'network_wide' => true]);
        $this->assertTrue($out['activated']);
        $this->assertTrue(is_plugin_active_for_network($this->regular));

        $restored = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $this->assertTrue($restored['restored']);
        $this->assertFalse(
            is_plugin_active_for_network($this->regular),
            'rollback-operation reported restored but install-plugin\'s network activation was not undone.'
        );
    }

    public function test_site_admin_cannot_roll_back_a_network_write(): void
    {
        wp_set_current_user($this->super);
        $out = (new Activate_Plugin())->handle(['plugin' => $this->regular, 'network_wide' => true]);

        wp_set_current_user($this->admin);
        $restored = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $this->assertFalse($restored['restored'], 'Restoring active_sitewide_plugins must take manage_network_plugins.');
        $this->assertStringContainsString('manage_network_plugins', implode(' ', (array) ($restored['warnings'] ?? [])));
        $this->assertTrue(is_plugin_active_for_network($this->regular));
    }
}
