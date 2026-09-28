<?php

namespace WPMCP\Tests\Free\MCP;

use Composer\Autoload\ClassLoader;

/**
 * The bundled MCP Adapter must never shadow another copy (issue #386).
 *
 * Adapter 0.7.0 deprecates bundling in favour of the canonical plugin, and
 * its internals differ enough from the 0.6.x we bundle that classes from two
 * copies must never mix. Composer prepends its loader, so without the guard
 * our bundle answers first for every adapter class another copy has not
 * loaded yet. src/adapter-guard.php moves our loader to the end of the stack
 * and keeps it from serving the shared namespaces before plugins_loaded.
 */
class AdapterGuardTest extends \WP_UnitTestCase
{
    private string $dir = '';

    /** @var list<callable> */
    private array $registered = [];

    private ?ClassLoader $fixture = null;

    protected function tearDown(): void
    {
        foreach ($this->registered as $callback) {
            spl_autoload_unregister($callback);
        }
        // Also drops the fixture from Composer's static loader registry.
        $this->fixture?->unregister();
        if ('' !== $this->dir) {
            foreach ((array) glob($this->dir . '/src/*/*.php') as $file) {
                unlink((string) $file);
            }
            foreach ((array) glob($this->dir . '/src/*', GLOB_ONLYDIR) as $sub) {
                rmdir((string) $sub);
            }
            @rmdir($this->dir . '/src');
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    public function test_every_main_file_applies_the_guard_after_its_autoloader(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['wpmcp.php', 'scripts/flavors/wporg/wpmcp.php', 'scripts/flavors/woocommerce/wpmcp-for-woocommerce.php'] as $main) {
            $source   = (string) file_get_contents($root . '/' . $main);
            $autoload = strpos($source, "require_once __DIR__ . '/vendor/autoload.php';");
            $guard    = strpos($source, "wpmcp_prefer_shared_mcp_adapter( __DIR__ . '/vendor' );");

            $this->assertNotFalse($autoload, "$main must load its Composer autoloader");
            $this->assertNotFalse($guard, "$main must apply the adapter guard");
            $this->assertGreaterThan($autoload, $guard, "$main must apply the guard after registering the autoloader");
        }
    }

    /** The plugin under test booted through wpmcp.php, so its own loader is already moved. */
    public function test_the_plugins_own_loader_is_no_longer_prepended(): void
    {
        $loaders = ClassLoader::getRegisteredLoaders();
        $vendor  = dirname(__DIR__, 3) . '/vendor';
        $this->assertArrayHasKey($vendor, $loaders, 'Composer must still know the vendor directory.');

        $this->assertNotContains(
            [ $loaders[ $vendor ], 'loadClass' ],
            spl_autoload_functions(),
            'The bundled loader is still registered directly, so it can shadow the canonical adapter.'
        );
        $this->assertTrue(class_exists(\WPMCP\Plugin::class), 'Our own classes must still resolve.');
    }

    public function test_the_guard_defers_shared_namespaces_and_goes_last(): void
    {
        $suffix    = wp_generate_password(8, false, false);
        $shared    = 'WP\\MCP\\GuardProbe' . $suffix;
        $unrelated = 'Wpmcp_Guard_Probe\\Probe' . $suffix;
        $loader    = $this->fixture_loader($shared, $unrelated);

        $this->assertTrue(wpmcp_prefer_shared_mcp_adapter($this->dir . '/vendor'));
        $this->assertFalse(wpmcp_prefer_shared_mcp_adapter($this->dir . '/vendor'), 'The guard must be idempotent.');

        $functions = spl_autoload_functions();
        $this->assertNotContains([ $loader, 'loadClass' ], $functions);
        $wrapper = end($functions);
        $this->assertInstanceOf(\Closure::class, $wrapper, 'The gated loader must be appended, not prepended.');
        $this->registered[] = $wrapper;

        // Before plugins_loaded the shared namespace is someone else's to serve.
        global $wp_actions;
        $saved = $wp_actions['plugins_loaded'] ?? null;
        unset($wp_actions['plugins_loaded']);
        try {
            $this->assertFalse(class_exists($shared));
            $this->assertTrue(class_exists($unrelated), 'Only the shared namespaces are deferred.');
        } finally {
            if (null !== $saved) {
                $wp_actions['plugins_loaded'] = $saved;
            }
        }

        // Once plugins have loaded, the bundle is the fallback again.
        $this->assertTrue(class_exists($shared));
    }

    public function test_shared_namespaces_are_exactly_the_adapter_and_its_schema(): void
    {
        $this->assertTrue(wpmcp_is_shared_adapter_class('WP\\MCP\\Core\\McpAdapter'));
        $this->assertTrue(wpmcp_is_shared_adapter_class('WP\\McpSchema\\Schemas'));
        $this->assertFalse(wpmcp_is_shared_adapter_class('WPMCP\\Plugin'));
        $this->assertFalse(wpmcp_is_shared_adapter_class('WP_MCP_Other'));
    }

    /** A throwaway Composer loader over two fixture classes, registered prepended like the real one. */
    private function fixture_loader(string $shared, string $unrelated): ClassLoader
    {
        $this->dir = get_temp_dir() . 'wpmcp-guard-' . wp_generate_password(8, false, false);
        wp_mkdir_p($this->dir . '/src/shared');
        wp_mkdir_p($this->dir . '/src/unrelated');

        $write = function (string $class, string $sub): void {
            $pos = strrpos($class, '\\');
            file_put_contents(
                $this->dir . '/src/' . $sub . '/' . substr($class, $pos + 1) . '.php',
                '<?php namespace ' . substr($class, 0, $pos) . '; class ' . substr($class, $pos + 1) . ' {}'
            );
        };
        $write($shared, 'shared');
        $write($unrelated, 'unrelated');

        $loader = new ClassLoader($this->dir . '/vendor');
        $loader->addClassMap([
            $shared    => $this->dir . '/src/shared/' . substr($shared, strrpos($shared, '\\') + 1) . '.php',
            $unrelated => $this->dir . '/src/unrelated/' . substr($unrelated, strrpos($unrelated, '\\') + 1) . '.php',
        ]);
        $loader->register(true);
        $this->fixture = $loader;

        return $loader;
    }
}
