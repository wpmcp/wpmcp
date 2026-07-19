<?php

namespace WPMCP\Tests\Free\Platform;

/**
 * Drift guard for the registered-ability surface (issue #55).
 *
 * With 160+ registered abilities, a single bad category value or registration
 * typo can silently drop tools without any test noticing. This suite pins the
 * exact set of registered ability names, each ability's tier, and the
 * total / free / pro counts against a committed manifest
 * (tests/support/ability-manifest.php). Any silently added, removed, renamed,
 * or re-tiered ability fails CI with a diff against the manifest.
 *
 * Deliberate changes to the ability surface are made by regenerating the
 * manifest — `composer manifest:regenerate` — and committing the diff, which
 * makes the change visible in review instead of invisible in registration
 * code.
 */
class AbilityManifestTest extends \WP_UnitTestCase
{
    private const MANIFEST_PATH = __DIR__ . '/../../support/ability-manifest.php';

    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();
        if (getenv('WPMCP_REGENERATE_MANIFEST')) {
            self::write_manifest();
        }
    }

    /** @return array{total:int,free:int,pro:int,abilities:array<string,string>} */
    private static function load_manifest(): array
    {
        if (! file_exists(self::MANIFEST_PATH)) {
            self::fail(
                'Ability manifest missing at tests/support/ability-manifest.php. ' .
                'Run `composer manifest:regenerate` and commit the result.'
            );
        }
        return require self::MANIFEST_PATH;
    }

    public function test_manifest_is_well_formed_and_internally_consistent(): void
    {
        $manifest = self::load_manifest();

        foreach (['total', 'free', 'pro', 'abilities'] as $key) {
            $this->assertArrayHasKey($key, $manifest, "Manifest is missing the '{$key}' key.");
        }

        $tiers = array_count_values($manifest['abilities']);
        $this->assertSame(
            $manifest['total'],
            count($manifest['abilities']),
            'Manifest total does not match the number of listed abilities; regenerate instead of hand-editing.'
        );
        $this->assertSame(
            $manifest['free'],
            $tiers['free'] ?? 0,
            'Manifest free count does not match the listed free-tier abilities.'
        );
        $this->assertSame(
            $manifest['pro'],
            $tiers['pro'] ?? 0,
            'Manifest pro count does not match the listed pro-tier abilities.'
        );
        $this->assertSame(
            $manifest['total'],
            $manifest['free'] + $manifest['pro'],
            'Manifest free + pro must sum to total.'
        );
    }

    public function test_registered_abilities_match_manifest_names_and_tiers(): void
    {
        $manifest = self::load_manifest();

        $this->assertSame(
            $manifest['abilities'],
            RegisteredAbilities::manifest_map(),
            'Registered ability surface drifted from tests/support/ability-manifest.php. ' .
            'If the change is intentional, run `composer manifest:regenerate` and commit the diff.'
        );
    }

    public function test_total_free_and_pro_counts_match_manifest(): void
    {
        $manifest = self::load_manifest();
        $actual   = RegisteredAbilities::manifest_map();
        $tiers    = array_count_values($actual);

        $this->assertSame($manifest['total'], count($actual), 'Total registered-ability count drifted.');
        $this->assertSame($manifest['free'], $tiers['free'] ?? 0, 'Free-tier ability count drifted.');
        $this->assertSame($manifest['pro'], $tiers['pro'] ?? 0, 'Pro-tier ability count drifted.');
    }

    /**
     * Rewrites tests/support/ability-manifest.php from the live registration
     * path. Only runs under WPMCP_REGENERATE_MANIFEST=1 (see the
     * `manifest:regenerate` composer script); the suite then re-asserts
     * against the freshly written file, so a broken registration path still
     * fails even during regeneration.
     */
    private static function write_manifest(): void
    {
        $abilities = RegisteredAbilities::manifest_map();
        $tiers     = array_count_values($abilities);

        $lines = [];
        foreach ($abilities as $name => $tier) {
            $lines[] = sprintf("        '%s' => '%s',", $name, $tier);
        }

        $contents = sprintf(
            "<?php\n\n/**\n * Registered-ability manifest — the drift guard for the plugin's MCP surface.\n *\n * GENERATED FILE: do not hand-edit. Regenerate deliberately with\n * `composer manifest:regenerate` after intentionally adding, removing,\n * renaming, or re-tiering an ability, and commit the diff.\n * Asserted by tests/free/Platform/AbilityManifestTest.php.\n *\n * Pins the canonical test environment: single-site WordPress with the\n * optional test plugins from bin/install-test-plugins.sh present (parts of\n * the registration path are conditional on ACF / SEO / i18n plugins and on\n * multisite), which is exactly the environment CI runs.\n */\n\nreturn [\n    'total'     => %d,\n    'free'      => %d,\n    'pro'       => %d,\n    'abilities' => [\n%s\n    ],\n];\n",
            count($abilities),
            $tiers['free'] ?? 0,
            $tiers['pro'] ?? 0,
            implode("\n", $lines)
        );

        file_put_contents(self::MANIFEST_PATH, $contents);
    }
}
