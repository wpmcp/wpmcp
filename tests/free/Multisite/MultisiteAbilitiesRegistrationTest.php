<?php

namespace WPMCP\Tests\Free\Multisite;

/**
 * Verifies the conditional-registration split for the multisite tool group:
 * is-multisite is always registered; the network-gated tools are registered
 * only when is_multisite() is true, following the same pattern used for the
 * ACF/SEO/i18n tool groups (see I18nAbilitiesRegistrationTest).
 *
 * This harness boots WordPress single-site, so only the "no network" side of
 * that split can actually be exercised here; the "on a network" assertion
 * would always be skipped in this environment (there is no
 * wpmcp_multisite_active()-style test helper because nothing installs a real
 * network in CI), so it is intentionally omitted rather than faked.
 */
class MultisiteAbilitiesRegistrationTest extends \WP_UnitTestCase
{
    private const NETWORK_GATED_TOOLS = [
        'wpmcp/get-network-info',
    ];

    public function test_is_multisite_is_always_registered(): void
    {
        $names = array_keys(wp_get_abilities());

        $this->assertContains('wpmcp/is-multisite', $names);
    }

    public function test_network_gated_tools_are_not_registered_on_this_single_site_harness(): void
    {
        if (is_multisite()) {
            $this->markTestSkipped('This harness is unexpectedly running as a network.');
        }

        $names = array_keys(wp_get_abilities());

        foreach (self::NETWORK_GATED_TOOLS as $name) {
            $this->assertNotContains($name, $names, "Did not expect {$name} to be registered outside a network");
        }
    }

    public function test_is_multisite_ability_has_description_and_category(): void
    {
        $abilities = wp_get_abilities();
        $ability   = $abilities['wpmcp/is-multisite'];

        $this->assertNotEmpty($ability->get_description());
        $this->assertSame('wpmcp', $ability->get_category());
    }
}
