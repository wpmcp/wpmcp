<?php

namespace WPMCP\Tests\Free\Platform;

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
}
