<?php

namespace WPMCP\Tests\Free;

/**
 * Two WP MCP builds (the full plugin and a wp.org vertical such as
 * wpmcp-for-woocommerce) share the WPMCP_* constants, the \WPMCP\ namespace
 * and every option, table and hook name. Only one of them may boot per
 * request, and which one must not depend on WordPress's load order: core
 * sorts active_plugins by basename, so 'wpmcp-for-woocommerce/...' loads
 * before 'wpmcp/wpmcp.php' and a plain defined('WPMCP_VERSION') guard in the
 * vertical never fires. src/flavor-guard.php decides from the active plugin
 * list instead, which is load-order independent.
 */
class FlavorGuardTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/src/flavor-guard.php';
    }

    protected function tearDown(): void
    {
        update_option('active_plugins', []);
        parent::tearDown();
    }

    public function test_vertical_defers_when_the_full_plugin_is_active_but_has_not_loaded_yet(): void
    {
        update_option('active_plugins', ['wpmcp-for-woocommerce/wpmcp-for-woocommerce.php', 'wpmcp/wpmcp.php']);

        // $already_loaded is false: this is the load-order case the old
        // defined('WPMCP_VERSION') guard missed entirely.
        $this->assertTrue(
            wpmcp_flavor_should_defer('wpmcp-for-woocommerce.php', ['wpmcp.php'], false)
        );
    }

    public function test_vertical_boots_when_it_is_the_only_wpmcp_build_installed(): void
    {
        update_option('active_plugins', ['wpmcp-for-woocommerce/wpmcp-for-woocommerce.php', 'woocommerce/woocommerce.php']);

        $this->assertFalse(
            wpmcp_flavor_should_defer('wpmcp-for-woocommerce.php', ['wpmcp.php'], false)
        );
    }

    public function test_any_flavor_defers_once_another_copy_has_already_loaded(): void
    {
        update_option('active_plugins', []);

        // The full plugin outranks nothing, so this is its only guard: it
        // must never redefine WPMCP_VERSION or boot a second Plugin instance.
        $this->assertTrue(wpmcp_flavor_should_defer('wpmcp.php', [], true));
        $this->assertTrue(wpmcp_flavor_should_defer('wpmcp-for-woocommerce.php', ['wpmcp.php'], true));
    }

    public function test_a_plugin_never_defers_to_itself(): void
    {
        update_option('active_plugins', ['wpmcp/wpmcp.php']);

        // Same basename in the outranks list and in active_plugins: the full
        // plugin must still boot rather than stand down for its own entry.
        $this->assertFalse(wpmcp_flavor_should_defer('wpmcp.php', ['wpmcp.php'], false));
    }

    public function test_an_unrelated_plugin_whose_path_contains_the_name_does_not_trigger_a_defer(): void
    {
        update_option('active_plugins', ['wpmcp-companion/wpmcp-companion.php', 'some-wpmcp.php-theme/init.php']);

        $this->assertFalse(
            wpmcp_flavor_should_defer('wpmcp-for-woocommerce.php', ['wpmcp.php'], false)
        );
    }

    public function test_network_activated_full_plugin_is_seen_by_the_vertical(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('network activation only exists on multisite');
        }

        update_site_option('active_sitewide_plugins', ['wpmcp/wpmcp.php' => time()]);
        $this->assertTrue(
            wpmcp_flavor_should_defer('wpmcp-for-woocommerce.php', ['wpmcp.php'], false)
        );
        update_site_option('active_sitewide_plugins', []);
    }

    /**
     * Every shipped main file must actually call the guard before it defines
     * the shared constants or registers its Composer autoloader; a guard that
     * runs after either of those has already caused the collision.
     */
    public function test_all_three_main_files_call_the_guard_before_defining_constants(): void
    {
        $root  = dirname(__DIR__, 2);
        $files = [
            $root . '/wpmcp.php',
            $root . '/scripts/flavors/wporg/wpmcp.php',
            $root . '/scripts/flavors/woocommerce/wpmcp-for-woocommerce.php',
        ];

        foreach ($files as $file) {
            $source = file_get_contents($file);
            $this->assertNotFalse($source, "unreadable main file: {$file}");

            $guard      = strpos($source, 'wpmcp_flavor_should_defer(');
            $define     = strpos($source, "define( 'WPMCP_VERSION'");
            $autoloader = strpos($source, 'vendor/autoload.php');

            $this->assertNotFalse($guard, "no coexistence guard in {$file}");
            $this->assertNotFalse($define, "no WPMCP_VERSION define in {$file}");
            $this->assertNotFalse($autoloader, "no autoloader require in {$file}");
            $this->assertLessThan($define, $guard, "guard runs after the constants in {$file}");
            $this->assertLessThan($autoloader, $guard, "guard runs after the autoloader in {$file}");
        }
    }
}
