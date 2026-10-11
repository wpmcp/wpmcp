<?php

namespace WPMCP\Tests\Free\Multisite;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Packages\Install_Theme;
use WPMCP\Tools\Packages\Switch_Theme;
use WPMCP\Tools\Rollback_Operation;

/**
 * On a network a site administrator may only activate a theme the network
 * (or that site) has enabled, as core's Themes screen enforces through
 * WP_Theme::is_allowed() (issue #529). switch-theme and install-theme's
 * activate step must hold the same line.
 *
 * Runs under WP_MULTISITE=1 and skips on a single site.
 */
class SubSiteAllowedThemesTest extends \WP_UnitTestCase
{
    private int $blog = 0;

    private string $target = '';

    public function set_up(): void
    {
        parent::set_up();
        if (! is_multisite()) {
            $this->markTestSkipped('Runs under WP_MULTISITE=1.');
        }

        $this->setExpectedDeprecated('wpmu_new_blog');
        $this->blog = (int) self::factory()->blog->create();
        $admin      = (int) self::factory()->user->create(['role' => 'subscriber']);
        add_user_to_blog($this->blog, $admin, 'administrator');
        switch_to_blog($this->blog);
        Snapshot_Store::install();
        wp_set_current_user($admin);
        $this->assertFalse(is_super_admin($admin));

        $candidates = array_values(array_diff(array_keys(wp_get_themes()), [get_stylesheet()]));
        if ([] === $candidates) {
            restore_current_blog();
            $this->markTestSkipped('Needs a second installed theme.');
        }
        $this->target = $candidates[0];
        // Not enabled for the network or for this site. Through core's
        // filters: WP_Theme caches both stored lists in static variables.
        $target = $this->target;
        $drop   = static fn ($allowed) => array_diff_key((array) $allowed, [$target => true]);
        add_filter('allowed_themes', $drop, 100);
        add_filter('site_allowed_themes', $drop, 100);
    }

    public function tear_down(): void
    {
        if (ms_is_switched()) {
            restore_current_blog();
        }
        parent::tear_down();
    }

    public function test_switch_theme_refuses_a_theme_the_network_has_not_enabled(): void
    {
        $this->assertTrue(wp_get_abilities()['wpmcp/switch-theme']->check_permissions(['stylesheet' => $this->target]));
        $before    = get_stylesheet();
        $snapshots = Snapshot_Store::row_count();

        $error = $this->error_of(fn () => (new Switch_Theme())->handle(['stylesheet' => $this->target]));

        $this->assertNotNull($error, "A sub-site admin activated {$this->target}, which the network has not enabled.");
        $this->assertStringContainsString('not enabled', $error);
        $this->assertSame($before, get_stylesheet());
        $this->assertSame($snapshots, Snapshot_Store::row_count(), 'A refused switch must not leave undo points behind.');
    }

    public function test_switch_theme_activates_a_network_enabled_theme_and_stays_undoable(): void
    {
        $this->enable('allowed_themes');
        $this->assert_switch_works_and_undoes();
    }

    public function test_switch_theme_activates_a_theme_enabled_for_this_site_only(): void
    {
        $this->enable('site_allowed_themes');
        $this->assert_switch_works_and_undoes();
    }

    public function test_install_theme_activate_refuses_a_theme_the_network_has_not_enabled(): void
    {
        $before = get_stylesheet();

        $error = $this->error_of(fn () => (new Install_Theme())->activate_installed($this->target, ['slug' => $this->target, 'activate' => true]));

        $this->assertNotNull($error, "install-theme activated {$this->target}, which the network has not enabled.");
        $this->assertStringContainsString('not enabled', $error);
        $this->assertSame($before, get_stylesheet());
    }

    public function test_install_theme_activate_refuses_before_downloading(): void
    {
        $requests = 0;
        add_filter('pre_http_request', static function () use (&$requests) {
            $requests++;
            return new \WP_Error('blocked', 'No network in tests.');
        });

        $error = $this->error_of(fn () => (new Install_Theme())->handle(['slug' => 'not-enabled-theme', 'activate' => true]));

        $this->assertNotNull($error);
        $this->assertStringContainsString('not enabled', $error);
        $this->assertSame(0, $requests, 'The refusal must come before any download.');
    }

    private function assert_switch_works_and_undoes(): void
    {
        $before = get_stylesheet();

        $out = (new Switch_Theme())->handle(['stylesheet' => $this->target]);
        $this->assertSame($this->target, get_stylesheet());

        foreach ($out['operation_ids'] as $operation_id) {
            $this->assertTrue((new Rollback_Operation())->handle(['operation_id' => $operation_id])['restored']);
        }
        $this->assertSame($before, get_stylesheet());
    }

    /** Enable the target through one of core's allowed-themes filters. */
    private function enable(string $filter): void
    {
        $target = $this->target;
        add_filter($filter, static fn ($allowed) => array_merge((array) $allowed, [$target => true]), 200);
    }

    private function error_of(callable $call): ?string
    {
        try {
            $call();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        return null;
    }
}
