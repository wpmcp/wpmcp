<?php

namespace WPMCP\Tests\Free\Packages;

use WPMCP\Tools\Packages\List_Plugins;

/**
 * list-plugins updates:true (issue #389): one read covering available core,
 * plugin and theme updates plus auto-update state, read from core's update
 * transients only (never a network check).
 */
class ListUpdatesTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        delete_site_transient('update_core');
        delete_site_transient('update_plugins');
        delete_site_transient('update_themes');
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_updates_mode_covers_core_plugins_and_themes(): void
    {
        $theme = get_stylesheet();
        set_site_transient('update_core', (object) [
            'updates' => [
                (object) [ 'response' => 'upgrade', 'current' => '99.0', 'version' => '99.0', 'locale' => get_locale(), 'packages' => (object) [] ],
            ],
        ]);
        set_site_transient('update_plugins', (object) [
            'response' => [ 'akismet/akismet.php' => (object) [ 'slug' => 'akismet', 'plugin' => 'akismet/akismet.php', 'new_version' => '99.1' ] ],
        ]);
        set_site_transient('update_themes', (object) [
            'response' => [ $theme => [ 'theme' => $theme, 'new_version' => '99.2' ] ],
        ]);
        update_option('auto_update_plugins', [ 'akismet/akismet.php' ]);

        $out = (new List_Plugins())->handle(['updates' => true]);

        $this->assertSame(get_bloginfo('version'), $out['core']['version']);
        $this->assertSame(['99.0'], $out['core']['available']);
        $this->assertArrayHasKey('db_upgrade_needed', $out['core']);

        $this->assertSame('akismet/akismet.php', $out['plugins'][0]['plugin']);
        $this->assertSame('99.1', $out['plugins'][0]['new_version']);
        $this->assertTrue($out['plugins'][0]['auto_update']);

        $this->assertSame($theme, $out['themes'][0]['theme']);
        $this->assertSame('99.2', $out['themes'][0]['new_version']);
        $this->assertFalse($out['themes'][0]['auto_update']);
    }

    public function test_updates_mode_is_empty_without_offers(): void
    {
        $out = (new List_Plugins())->handle(['updates' => true]);

        $this->assertSame([], $out['core']['available']);
        $this->assertSame([], $out['plugins']);
        $this->assertSame([], $out['themes']);
    }

    public function test_default_listing_is_unchanged(): void
    {
        $out = (new List_Plugins())->handle([]);

        $this->assertArrayHasKey('plugins', $out);
        $this->assertArrayNotHasKey('core', $out);
    }
}
