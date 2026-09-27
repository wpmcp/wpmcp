<?php

namespace WPMCP\Tests\Free\Integrations;

/**
 * The ACF dispatcher pair registers unconditionally (availability is a
 * call-time concern for dispatchers: the ability must exist to report
 * "unavailable" cleanly), so both halves must be present in the live
 * abilities registry regardless of whether ACF is active.
 */
class IntegrationAbilitiesRegistrationTest extends \WP_UnitTestCase
{
    private const TOOLS = [
        'wpmcp/acf-read',
        'wpmcp/acf-write',
        'wpmcp/gravityforms-read',
        'wpmcp/gravityforms-write',
        'wpmcp/formidable-read',
        'wpmcp/formidable-write',
        'wpmcp/contactform7-read',
        'wpmcp/contactform7-write',
        'wpmcp/wpforms-read',
        'wpmcp/wpforms-write',
        'wpmcp/gravitytables-read',
        'wpmcp/gravitytables-write',
        'wpmcp/mec-read',
        'wpmcp/mec-write',
        'wpmcp/tec-read',
        'wpmcp/tec-write',
        'wpmcp/give-read',
        'wpmcp/give-write',
        'wpmcp/pmpro-read',
        'wpmcp/pmpro-write',
        'wpmcp/forminator-read',
        'wpmcp/forminator-write',
        'wpmcp/sureforms-read',
        'wpmcp/sureforms-write',
        'wpmcp/metform-read',
        'wpmcp/metform-write',
        'wpmcp/theme-read',
        'wpmcp/theme-write',
    ];

    public function test_dispatcher_pair_is_registered_as_free_abilities(): void
    {
        $names = array_keys(wp_get_abilities());

        foreach (self::TOOLS as $name) {
            $this->assertContains($name, $names, "Expected {$name} to be registered");
        }
    }

    public function test_dispatcher_abilities_have_description_and_category(): void
    {
        $abilities = wp_get_abilities();

        foreach (self::TOOLS as $name) {
            $ability = $abilities[ $name ];
            $this->assertNotEmpty($ability->get_description(), "Expected {$name} to have a description");
            $this->assertSame('wpmcp', $ability->get_category());
        }
    }
}
