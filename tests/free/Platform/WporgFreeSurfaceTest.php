<?php

namespace WPMCP\Tests\Free\Platform;

use WPMCP\MCP\Registrar;
use WPMCP\Plugin;

/**
 * Build gate: no FREE ability may be registered from a group method the
 * wp.org strip deletes (issue #81).
 *
 * scripts/flavors/wporg/strip.php removes whole ability-group methods from
 * Plugin.php and whole directories from src/. A free-tier ability registered
 * inside one of those methods still passes every test in this suite, because
 * the suite runs the full tree, and still passes the build, because
 * prune_unused_imports() quietly drops the now-orphaned `use` line. It simply
 * never reaches the only build free users install. This test reads the strip
 * script's own REMOVED_METHODS list and asserts that nothing free is
 * registered inside any of them.
 *
 * REMOVED_METHODS is only half of the strip, and only half of the ways this
 * fails. The other half is by PATH: a handler class can survive in a group
 * that ships while its file sits under a directory the build deletes, which
 * is strictly worse than being absent, because Plugin.php then instantiates
 * a class that is not in the zip and the plugin fatals on load. That is what
 * forced Content_Extractor out of src/Tools/Analysis, and it is what the
 * wp.org and the WooCommerce prune lists are both checked for below: every
 * registered ability's handler class, and every WPMCP class it imports
 * transitively, must resolve to a file the build keeps.
 */
class WporgFreeSurfaceTest extends \WP_UnitTestCase
{
    private const PLUGIN = __DIR__ . '/../../../src/Plugin.php';
    private const STRIP  = __DIR__ . '/../../../scripts/flavors/wporg/strip.php';

    public function test_no_free_ability_is_registered_in_a_stripped_group(): void
    {
        $offenders = [];

        foreach ($this->removed_methods() as $method) {
            $body = $this->method_body($method);
            if (null === $body) {
                continue;
            }
            preg_match_all("/new Ability\(\s*'([^']+)',\s*'(free|pro)'/", $body, $m, PREG_SET_ORDER);
            foreach ($m as $match) {
                if ('free' === $match[2]) {
                    $offenders[] = $match[1] . ' (in ' . $method . ')';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These free abilities are registered inside a group the wp.org build deletes, so they would not ship to free users:\n  "
                . implode("\n  ", $offenders)
        );
    }

    public function test_the_strip_list_is_readable_so_this_gate_cannot_pass_vacuously(): void
    {
        $methods = $this->removed_methods();

        $this->assertNotEmpty($methods);
        $this->assertContains('register_analysis_abilities', $methods);
        $this->assertNotNull($this->method_body('register_analysis_abilities'));
    }

    /** @return string[] */
    private function removed_methods(): array
    {
        $source = (string) file_get_contents(self::STRIP);
        if (! preg_match('/const REMOVED_METHODS = \[(.*?)\];/s', $source, $m)) {
            return [];
        }
        preg_match_all("/'([a-z_]+)'/", $m[1], $names);
        return $names[1];
    }

    private function method_body(string $method): ?string
    {
        $source = (string) file_get_contents(self::PLUGIN);
        $start  = strpos($source, 'function ' . $method . '(');
        if (false === $start) {
            return null;
        }
        $open = strpos($source, '{', $start);
        if (false === $open) {
            return null;
        }
        $depth = 0;
        for ($i = $open, $len = strlen($source); $i < $len; $i++) {
            if ('{' === $source[$i]) {
                $depth++;
            } elseif ('}' === $source[$i]) {
                $depth--;
                if (0 === $depth) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }
        return null;
    }

    // ------------------------------------------------------ path gates

    /**
     * src/Pro and src/Freemius are excluded: the strip does not simply delete
     * the references to them, it rewrites every call site with an exact-string
     * edit that fails the build when the string it expects is missing, so
     * those two are already gated harder than this test could gate them.
     */
    private const STRIP_REWRITTEN = ['src/Pro', 'src/Freemius'];

    /**
     * Known, pre-dating this gate: wpmcp/build-page is in the woocommerce
     * flavor's 'compose' group but reads Elementor page data, whose directory
     * that build prunes. It is a latent fatal on the Elementor dialect of one
     * kept tool rather than on every call, so it is recorded here instead of
     * being hidden, and this gate fails on anything NEW.
     */
    private const WOO_KNOWN_DEBT = [
        'wpmcp/build-page -> src/Tools/Elementor/Atomic_Prop_Schema.php',
        'wpmcp/build-page -> src/Tools/Elementor/Elementor_Page_Data.php',
        'wpmcp/build-page -> src/Tools/Elementor/Widget_Catalog.php',
    ];

    public function test_no_free_ability_depends_on_a_path_the_wporg_build_deletes(): void
    {
        $removed   = array_values(array_diff($this->removed_paths(), self::STRIP_REWRITTEN));
        $this->assertNotEmpty($removed, 'the strip path list must be readable or this gate passes vacuously');
        $offenders = [];

        foreach ($this->ability_dependencies(null, 'free') as $ability => $files) {
            foreach ($files as $file) {
                foreach ($removed as $prefix) {
                    if ($file === $prefix || 0 === strpos($file, rtrim($prefix, '/') . '/')) {
                        $offenders[] = $ability . ' -> ' . $file . ' (removed by ' . $prefix . ')';
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These free abilities depend on files the wp.org build deletes, so that build would fatal on load:\n  "
                . implode("\n  ", $offenders)
        );
    }

    public function test_no_woocommerce_ability_depends_on_a_path_that_build_prunes(): void
    {
        $pruned    = $this->woo_pruned_paths();
        $this->assertNotEmpty($pruned, 'the woo prune list must be readable or this gate passes vacuously');

        $offenders = [];
        foreach ($this->ability_dependencies('woocommerce', null) as $ability => $files) {
            foreach ($files as $file) {
                foreach ($pruned as $prefix) {
                    if ($file !== $prefix && 0 !== strpos($file, rtrim($prefix, '/') . '/')) {
                        continue;
                    }
                    if (in_array($ability . ' -> ' . $file, self::WOO_KNOWN_DEBT, true)) {
                        continue;
                    }
                    $offenders[] = $ability . ' -> ' . $file . ' (pruned by ' . $prefix . ')';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These abilities register in the woocommerce flavor but depend on files scripts/build-woo-release.sh prunes, so the vertical zip fatals on the first call:\n  "
                . implode("\n  ", $offenders)
        );
    }

    /**
     * Every src/ file each registered ability needs: its handler class plus
     * the transitive closure of the WPMCP classes that file imports. Keyed by
     * ability name, paths relative to the plugin root.
     *
     * @return array<string,string[]>
     */
    private function ability_dependencies(?string $flavor, ?string $tier): array
    {
        Plugin::set_flavor_for_tests($flavor);
        try {
            $registrar = new Registrar();
            Plugin::instance()->register_abilities_into($registrar);
            $abilities = $registrar->declared();
        } finally {
            Plugin::set_flavor_for_tests(null);
        }

        $out = [];
        foreach ($abilities as $ability) {
            if (null !== $tier && $tier !== $ability->tier) {
                continue;
            }
            $handler = $ability->handler;
            if (! is_array($handler) || ! is_object($handler[0])) {
                continue;
            }
            $out[ $ability->name ] = $this->direct_dependencies_of(get_class($handler[0]));
        }
        return $out;
    }

    /**
     * The handler class's own file plus the files of the WPMCP classes it
     * imports directly. Deliberately one level, not the transitive closure:
     * a `use` line is only a load-time dependency for the file that calls
     * through it, and following the closure through hub classes turns every
     * ability into a dependant of most of the tree, which reports noise
     * rather than the defect.
     *
     * @return string[] plugin-root-relative file paths
     */
    private function direct_dependencies_of(string $class): array
    {
        $root  = dirname(__DIR__, 3);
        $files = [];

        $to_relative = static function (string $fqcn) use ($root): ?string {
            if (0 !== strpos($fqcn, 'WPMCP\\')) {
                return null;
            }
            $relative = 'src/' . str_replace('\\', '/', substr($fqcn, strlen('WPMCP\\'))) . '.php';
            return is_file($root . '/' . $relative) ? $relative : null;
        };

        $own = $to_relative($class);
        if (null === $own) {
            return [];
        }
        $files[] = $own;

        preg_match_all('/^use (WPMCP\\\\[A-Za-z0-9_\\\\]+);/m', (string) file_get_contents($root . '/' . $own), $m);
        foreach ($m[1] as $imported) {
            $relative = $to_relative($imported);
            if (null !== $relative) {
                $files[] = $relative;
            }
        }

        return array_values(array_unique($files));
    }

    /** @return string[] the wp.org strip's REMOVED_PATHS, verbatim. */
    private function removed_paths(): array
    {
        $source = (string) file_get_contents(self::STRIP);
        if (! preg_match('/const REMOVED_PATHS = \[(.*?)\n\];/s', $source, $m)) {
            return [];
        }
        preg_match_all("/^\s*'([^']+)',/m", $m[1], $paths);
        return $paths[1];
    }

    /** @return string[] every src/ path scripts/build-woo-release.sh removes. */
    private function woo_pruned_paths(): array
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/scripts/build-woo-release.sh');
        preg_match_all('/"\$STAGE\/(src\/[^"]+)"/', $source, $m);
        return array_values(array_unique($m[1]));
    }
}
