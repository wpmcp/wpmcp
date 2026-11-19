<?php

namespace WPMCP\Tests\Free\Skills;

use WPMCP\Pro\Gate;
use WPMCP\Skills\Skill_Library;
use WPMCP\Tests\Free\Platform\RegisteredAbilities;

/**
 * Bundled skills cannot drift from the tool surface (issue #850): every tool
 * a bundled SKILL.md names is a registered ability, every operation it names
 * (in backticks, or as a `requires_any` entry) is an operation some
 * dispatcher pair really answers, and the playbooks stay short.
 */
class SkillLintTest extends \WP_UnitTestCase
{
    /** Integration playbooks are kept well under the 64 KB serving cap. */
    private const MAX_SKILL_BYTES = 12288;

    /** @var array<string, array<string, true>>|null ability => op names, every tier. */
    private static ?array $operations = null;

    /** @return array<string, array<string, true>> */
    private static function operations(): array
    {
        if (null !== self::$operations) {
            return self::$operations;
        }

        $operations = [];
        foreach (RegisteredAbilities::all() as $ability) {
            $handler = $ability->handler;
            if (! is_array($handler) || ! isset($handler[0]) || ! is_object($handler[0]) || ! method_exists($handler[0], 'catalog')) {
                continue;
            }
            Gate::set_pro_for_tests(true);
            try {
                $catalog = $handler[0]->catalog();
            } finally {
                Gate::set_pro_for_tests(null);
            }
            foreach ($catalog['operations'] as $op) {
                $operations[ $ability->name ][ (string) $op['name'] ] = true;
            }
            $operations[ $ability->name ]['list-operations'] = true;
        }

        return self::$operations = $operations;
    }

    /** @return array<string, string> slug => raw document, every bundled skill. */
    private static function bundled(): array
    {
        $root  = Skill_Library::bundled_dir();
        $files = array_merge((array) glob($root . '/*/SKILL.md'), (array) glob($root . '/*/*/SKILL.md'));
        $docs  = [];
        foreach ($files as $file) {
            $slug          = str_replace($root . '/', '', dirname((string) $file));
            $docs[ $slug ] = (string) file_get_contents((string) $file);
        }
        ksort($docs);

        return $docs;
    }

    public function test_every_tool_a_bundled_skill_names_is_registered(): void
    {
        $manifest = (require dirname(__DIR__, 2) . '/support/ability-manifest.php')['abilities'];
        $bad      = [];
        foreach (self::bundled() as $slug => $raw) {
            preg_match_all('~wpmcp/[a-z0-9-]+~', $raw, $m);
            foreach (array_unique($m[0]) as $tool) {
                if (! isset($manifest[ $tool ])) {
                    $bad[] = $slug . ' names ' . $tool;
                }
            }
        }

        $this->assertSame([], $bad, 'A bundled skill names a tool that does not exist.');
    }

    public function test_every_operation_a_bundled_skill_names_exists(): void
    {
        $all_ops = [];
        foreach (self::operations() as $ops) {
            $all_ops += $ops;
        }
        $slugs = array_fill_keys(array_keys(self::bundled()), true);

        $bad = [];
        foreach (self::bundled() as $slug => $raw) {
            $parsed = Skill_Library::parse($raw);
            $this->assertNotNull($parsed, $slug . ' has no frontmatter');

            foreach ((array) ($parsed['frontmatter']['requires_any'] ?? []) as $entry) {
                if (false === strpos((string) $entry, '#')) {
                    continue;
                }
                [ $ability, $op ] = explode('#', (string) $entry, 2);
                if (! isset(self::operations()[ $ability ][ $op ])) {
                    $bad[] = $slug . ' requires_any ' . $entry . ', which ' . $ability . ' does not answer';
                }
            }

            preg_match_all('~`([a-z0-9]+(?:-[a-z0-9]+)+(?:/[a-z0-9]+(?:-[a-z0-9]+)*)?)`~', $parsed['body'], $m);
            foreach (array_unique($m[1]) as $token) {
                if (! isset($all_ops[ $token ]) && ! isset($slugs[ $token ])) {
                    $bad[] = $slug . ' names `' . $token . '`, which is neither an operation nor a bundled skill';
                }
            }
        }

        $this->assertSame([], $bad, 'A bundled skill names an operation that does not exist.');
    }

    public function test_integration_skills_stay_short(): void
    {
        foreach (self::bundled() as $slug => $raw) {
            if (0 !== strpos($slug, 'integrations') && ! in_array($slug, [ 'wpmcp-block-editing', 'wpmcp-security-incident', 'wpmcp-performance-caching' ], true)) {
                continue;
            }
            $this->assertLessThanOrEqual(self::MAX_SKILL_BYTES, strlen($raw), $slug . ' is longer than an integration playbook should be.');
        }
    }

    public function test_the_integrations_router_names_every_area_skill(): void
    {
        $router = self::bundled()['integrations'] ?? '';
        $this->assertNotSame('', $router, 'The integrations router skill is missing.');

        foreach (array_keys(self::bundled()) as $slug) {
            if (0 === strpos($slug, 'integrations/')) {
                $this->assertStringContainsString('`' . $slug . '`', $router, 'The router does not point at ' . $slug);
            }
        }
    }
}
