<?php

namespace WPMCP\Tests\Free\Platform;

use WPMCP\MCP\Registrar;
use WPMCP\Pro\Gate;

/**
 * Live-registry smoke test (issue #55): every ability in the committed
 * manifest must actually resolve in the WordPress Abilities API registry,
 * not merely be constructed by Plugin::register_abilities(). This catches
 * failures the drift guard cannot see — e.g. a bad category value or invalid
 * input schema that makes wp_register_ability() reject an ability at the
 * registry boundary while our own Registrar still lists it.
 *
 * Free tier resolves through the plugin's real boot registration (the
 * wp_abilities_api_init hook fired by the registry's lazy init). Pro tier is
 * skipped at boot because the Gate is closed under test, so this test drives
 * the exact same Ability objects through a Registrar inside a real
 * wp_abilities_api_init action window, then removes them again so the shared
 * registry is left as it was found.
 */
class AbilityRegistrySmokeTest extends \WP_UnitTestCase
{
    private const MANIFEST_PATH = __DIR__ . '/../../support/ability-manifest.php';

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    public function test_every_manifest_ability_resolves_in_the_live_registry(): void
    {
        $this->assertFileExists(
            self::MANIFEST_PATH,
            'Ability manifest missing. Run `composer manifest:regenerate` and commit the result.'
        );
        $manifest = require self::MANIFEST_PATH;

        // Trigger the registry's lazy init through the real API path: the
        // first registry access fires wp_abilities_api_init, which is where
        // Plugin::boot() registers the (free-tier) abilities.
        wp_get_abilities();

        $missing_free = [];
        foreach ($manifest['abilities'] as $name => $tier) {
            if ('free' === $tier && ! wp_has_ability($name)) {
                $missing_free[] = $name;
            }
        }
        $this->assertSame(
            [],
            $missing_free,
            'Free-tier manifest abilities did not resolve in the live abilities registry.'
        );

        // Pro tier: replay the same Ability objects the plugin builds through
        // the same Registrar registration path, inside a real
        // wp_abilities_api_init window so Registrar forwards them to
        // wp_register_ability(). Only this test's callback is on the hook
        // (WP_UnitTestCase restores the original hooks in tearDown), so no
        // other plugin re-registers and no duplicate-registration notices fire.
        $pro_abilities = [];
        foreach (RegisteredAbilities::all() as $ability) {
            if ('pro' === $ability->tier && ! wp_has_ability($ability->name)) {
                $pro_abilities[] = $ability;
            }
        }

        Gate::set_pro_for_tests(true);
        $registrar = new Registrar();
        remove_all_actions('wp_abilities_api_init');
        add_action('wp_abilities_api_init', static function () use ($registrar, $pro_abilities): void {
            foreach ($pro_abilities as $ability) {
                $registrar->register($ability);
            }
        });
        do_action('wp_abilities_api_init');

        $added       = [];
        $missing_pro = [];
        foreach ($manifest['abilities'] as $name => $tier) {
            if ('pro' !== $tier) {
                continue;
            }
            if (wp_has_ability($name)) {
                $added[] = $name;
            } else {
                $missing_pro[] = $name;
            }
        }

        // Leave the shared registry exactly as this test found it before
        // asserting, so a failure cannot leak pro abilities into later tests.
        foreach ($added as $name) {
            wp_unregister_ability($name);
        }

        $this->assertSame(
            [],
            $missing_pro,
            'Pro-tier manifest abilities did not resolve in the live abilities registry when driven through Registrar.'
        );
    }
}
