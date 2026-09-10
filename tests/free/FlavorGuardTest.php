<?php

namespace WPMCP\Tests\Free;

/**
 * Two WP MCP builds (the full plugin, the wp.org directory build, a vertical
 * such as wpmcp-for-woocommerce) share the WPMCP_* constants, the \WPMCP\
 * namespace and every option, table and hook name. Only one of them may boot
 * per request, and which one must not depend on WordPress's load order: core
 * sorts active_plugins by basename, so 'wpmcp-for-woocommerce/...' loads
 * before 'wpmcp/wpmcp.php' and a plain defined('WPMCP_VERSION') guard in the
 * vertical never fires. Nor may it depend on directory names: the full plugin
 * and the directory build are both wpmcp.php, and the full plugin's directory
 * is whatever the installer chose. src/flavor-guard.php decides from the
 * active plugin list and the `WPMCP Flavor:` header each main file declares.
 */
class FlavorGuardTest extends \WP_UnitTestCase
{
    private const FULL  = 'full';
    private const WPORG = 'wporg';
    private const WOO   = 'woocommerce';

    /** @var string[] absolute paths of fixture main files written this test */
    private array $fixture_files = [];

    /** @var string[] fixture directories created this test, deepest first */
    private array $fixture_dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/src/flavor-guard.php';
    }

    protected function tearDown(): void
    {
        foreach ($this->fixture_files as $file) {
            @unlink($file);
        }
        foreach ($this->fixture_dirs as $dir) {
            @rmdir($dir);
        }
        $this->fixture_files = [];
        $this->fixture_dirs  = [];
        update_option('active_plugins', []);
        parent::tearDown();
    }

    /**
     * Write a plugin main file under WP_PLUGIN_DIR. With a flavor it is a WP
     * MCP build; without one it is an unrelated plugin that happens to share
     * part of the name. Refuses to overwrite a real plugin.
     */
    private function install(string $plugin, ?string $flavor = null): string
    {
        $path = WP_PLUGIN_DIR . '/' . $plugin;
        $this->assertFileDoesNotExist($path, "fixture would clobber a real plugin: {$plugin}");

        $dir = dirname($path);
        if (! is_dir($dir)) {
            $this->assertTrue(mkdir($dir, 0777, true), "could not create {$dir}");
            $this->fixture_dirs[] = $dir;
        }

        $header = "<?php\n/**\n * Plugin Name: fixture {$plugin}\n";
        if (null !== $flavor) {
            $header .= " * WPMCP Flavor: {$flavor}\n";
        }
        $header .= " */\n";

        $this->assertNotFalse(file_put_contents($path, $header));
        $this->fixture_files[] = $path;

        return $path;
    }

    public function test_vertical_defers_when_the_full_plugin_is_active_but_has_not_loaded_yet(): void
    {
        $self = $this->install('wpmcp-for-woocommerce/wpmcp-for-woocommerce.php', self::WOO);
        $this->install('wpmcp/wpmcp.php', self::FULL);
        update_option('active_plugins', ['wpmcp-for-woocommerce/wpmcp-for-woocommerce.php', 'wpmcp/wpmcp.php']);

        // $already_loaded is false: this is the load-order case the old
        // defined('WPMCP_VERSION') guard missed entirely.
        $this->assertTrue(wpmcp_flavor_should_defer($self, self::WOO, false));
    }

    public function test_vertical_defers_to_the_directory_build_too(): void
    {
        $self = $this->install('wpmcp-for-woocommerce/wpmcp-for-woocommerce.php', self::WOO);
        $this->install('wpmcp/wpmcp.php', self::WPORG);
        update_option('active_plugins', ['wpmcp-for-woocommerce/wpmcp-for-woocommerce.php', 'wpmcp/wpmcp.php']);

        $this->assertTrue(wpmcp_flavor_should_defer($self, self::WOO, false));
    }

    public function test_vertical_boots_when_it_is_the_only_wpmcp_build_installed(): void
    {
        $self = $this->install('wpmcp-for-woocommerce/wpmcp-for-woocommerce.php', self::WOO);
        update_option('active_plugins', ['wpmcp-for-woocommerce/wpmcp-for-woocommerce.php', 'woocommerce/woocommerce.php']);

        $this->assertFalse(wpmcp_flavor_should_defer($self, self::WOO, false));
    }

    public function test_the_full_plugin_wins_whatever_directory_it_is_installed_in(): void
    {
        // The directory build owns the wp.org slug, so it always lives in
        // wpmcp/. The full plugin's directory sorts after it here, so core
        // loads the directory build first: exactly the case a path- or
        // load-order-based rule gets wrong.
        $wporg = $this->install('wpmcp/wpmcp.php', self::WPORG);
        $full  = $this->install('zz-wpmcp-main/wpmcp.php', self::FULL);
        update_option('active_plugins', ['wpmcp/wpmcp.php', 'zz-wpmcp-main/wpmcp.php']);

        $this->assertTrue(wpmcp_flavor_should_defer($wporg, self::WPORG, false), 'the directory build must stand down');
        $this->assertFalse(wpmcp_flavor_should_defer($full, self::FULL, false), 'the full plugin must boot');

        // And with the directories the licensing SDK's premium slug produces.
        $pro = $this->install('wpmcp-pro/wpmcp.php', self::FULL);
        update_option('active_plugins', ['wpmcp-pro/wpmcp.php', 'wpmcp/wpmcp.php']);

        $this->assertTrue(wpmcp_flavor_should_defer($wporg, self::WPORG, false));
        $this->assertFalse(wpmcp_flavor_should_defer($pro, self::FULL, false));
    }

    public function test_any_flavor_defers_once_another_copy_has_already_loaded(): void
    {
        update_option('active_plugins', []);

        // Nothing outranks the full plugin, so this is its only guard: it
        // must never redefine WPMCP_VERSION or boot a second Plugin instance.
        $this->assertTrue(wpmcp_flavor_should_defer(WPMCP_FILE, self::FULL, true));
        $this->assertTrue(wpmcp_flavor_should_defer(WP_PLUGIN_DIR . '/wpmcp/wpmcp.php', self::WPORG, true));
        $this->assertTrue(wpmcp_flavor_should_defer(WP_PLUGIN_DIR . '/x/wpmcp-for-woocommerce.php', self::WOO, true));
    }

    public function test_a_plugin_never_defers_to_itself(): void
    {
        $self = $this->install('wpmcp/wpmcp.php', self::FULL);
        update_option('active_plugins', ['wpmcp/wpmcp.php']);

        $this->assertFalse(wpmcp_flavor_should_defer($self, self::FULL, false));
    }

    public function test_two_copies_of_the_same_flavor_fall_back_to_load_order(): void
    {
        // Equal rank: neither outranks the other, so neither stands down on
        // the active list. The second one to load sees $already_loaded.
        $a = $this->install('wpmcp/wpmcp.php', self::FULL);
        $b = $this->install('wpmcp-pro/wpmcp.php', self::FULL);
        update_option('active_plugins', ['wpmcp/wpmcp.php', 'wpmcp-pro/wpmcp.php']);

        $this->assertFalse(wpmcp_flavor_should_defer($a, self::FULL, false));
        $this->assertFalse(wpmcp_flavor_should_defer($b, self::FULL, false));
        $this->assertTrue(wpmcp_flavor_should_defer($b, self::FULL, true));
    }

    public function test_an_unrelated_plugin_whose_path_contains_the_name_does_not_trigger_a_defer(): void
    {
        $self = $this->install('wpmcp-for-woocommerce/wpmcp-for-woocommerce.php', self::WOO);
        // Same directory prefix, and the exact main-file basename in another
        // directory: neither declares a flavor, so neither is a WP MCP build.
        $this->install('wpmcp-companion/wpmcp-companion.php');
        $this->install('other-dir/wpmcp.php');
        update_option('active_plugins', [
            'wpmcp-for-woocommerce/wpmcp-for-woocommerce.php',
            'wpmcp-companion/wpmcp-companion.php',
            'other-dir/wpmcp.php',
            'some-wpmcp.php-theme/init.php',
        ]);

        $this->assertFalse(wpmcp_flavor_should_defer($self, self::WOO, false));
    }

    public function test_a_stale_active_entry_whose_file_is_gone_does_not_keep_the_vertical_down(): void
    {
        // Core skips a listed plugin whose file is missing but leaves the
        // entry until the Plugins screen prunes it. Deferring to it would
        // leave no build booted at all.
        $self = $this->install('wpmcp-for-woocommerce/wpmcp-for-woocommerce.php', self::WOO);
        update_option('active_plugins', ['wpmcp-for-woocommerce/wpmcp-for-woocommerce.php', 'wpmcp/wpmcp.php']);
        $this->assertFileDoesNotExist(WP_PLUGIN_DIR . '/wpmcp/wpmcp.php');

        $this->assertFalse(wpmcp_flavor_should_defer($self, self::WOO, false));
    }

    public function test_an_unknown_flavor_id_ranks_below_every_known_build(): void
    {
        $self  = $this->install('wpmcp-for-woocommerce/wpmcp-for-woocommerce.php', self::WOO);
        $other = $this->install('wpmcp-for-future/wpmcp-for-future.php', 'future');
        update_option('active_plugins', ['wpmcp-for-woocommerce/wpmcp-for-woocommerce.php', 'wpmcp-for-future/wpmcp-for-future.php']);

        $this->assertFalse(wpmcp_flavor_should_defer($self, self::WOO, false));
        $this->assertTrue(wpmcp_flavor_should_defer($other, 'future', false));
    }

    public function test_network_activated_full_plugin_is_seen_by_the_vertical(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('network activation only exists on multisite');
        }

        $self = $this->install('wpmcp-for-woocommerce/wpmcp-for-woocommerce.php', self::WOO);
        $this->install('wpmcp/wpmcp.php', self::FULL);
        update_site_option('active_sitewide_plugins', ['wpmcp/wpmcp.php' => time()]);
        $this->assertTrue(wpmcp_flavor_should_defer($self, self::WOO, false));
        update_site_option('active_sitewide_plugins', []);
    }

    public function test_the_rank_order_is_full_then_wporg_then_woocommerce(): void
    {
        $this->assertGreaterThan(wpmcp_flavor_rank(self::WPORG), wpmcp_flavor_rank(self::FULL));
        $this->assertGreaterThan(wpmcp_flavor_rank(self::WOO), wpmcp_flavor_rank(self::WPORG));
        $this->assertGreaterThan(wpmcp_flavor_rank('anything-else'), wpmcp_flavor_rank(self::WOO));
    }

    /**
     * Every shipped main file must call the guard before it defines the
     * shared constants or registers its Composer autoloader (a guard that
     * runs after either has already caused the collision), and the flavor id
     * it passes must be the one its own header declares, because that header
     * is what the other builds rank it by.
     */
    public function test_all_three_main_files_call_the_guard_before_defining_constants(): void
    {
        $root  = dirname(__DIR__, 2);
        $files = [
            $root . '/wpmcp.php'                                                => self::FULL,
            $root . '/scripts/flavors/wporg/wpmcp.php'                          => self::WPORG,
            $root . '/scripts/flavors/woocommerce/wpmcp-for-woocommerce.php'    => self::WOO,
        ];

        foreach ($files as $file => $expected_flavor) {
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

            $this->assertSame(1, preg_match("/wpmcp_flavor_should_defer\(\s*__FILE__,\s*'([a-z]+)'/", $source, $m), "guard call in {$file} does not pass __FILE__ and a literal flavor id");
            $this->assertSame($expected_flavor, $m[1], "wrong flavor id passed in {$file}");
            $this->assertSame($expected_flavor, wpmcp_flavor_of($file), "WPMCP Flavor header in {$file} does not match the id it passes");
            $this->assertStringContainsString('admin_notices', $source, "the losing build shows no notice in {$file}");
        }
    }
}
