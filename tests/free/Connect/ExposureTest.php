<?php

namespace WPMCP\Tests\Free\Connect;

use WPMCP\Connect\Exposure;
use WPMCP\Governance\Governance;
use WPMCP\MCP\Ability;

/**
 * Issue #76: the master exposure switch. A single on/off option that, when
 * off, kills the entire MCP surface instantly — enforced through the existing
 * governance layer (the wpmcp_ability_enabled filter, layer 2 of the
 * AND-of-narrowing chain) so every already-registered ability's
 * permission_callback starts denying on the very next request. Being a
 * narrowing layer it can only take away: it can never re-enable an ability
 * some other governance layer disabled.
 */
class ExposureTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Exposure::OPTION);
        Governance::reset_for_tests();
    }

    protected function tearDown(): void
    {
        delete_option(Exposure::OPTION);
        Governance::reset_for_tests();
        parent::tearDown();
    }

    private function ability(): Ability
    {
        return new Ability(
            'wpmcp/get-page',
            'free',
            'Read a page',
            ['type' => 'object', 'properties' => []],
            static fn () => null
        );
    }

    public function test_exposure_defaults_to_enabled(): void
    {
        $this->assertTrue(Exposure::is_enabled());
        $this->assertTrue(Governance::is_ability_enabled($this->ability()));
    }

    public function test_disabling_exposure_kills_every_ability_through_governance(): void
    {
        Exposure::set_enabled(false);

        $this->assertFalse(Exposure::is_enabled());
        $this->assertFalse(Governance::is_ability_enabled($this->ability()));

        Exposure::set_enabled(true);

        $this->assertTrue(Exposure::is_enabled());
        $this->assertTrue(Governance::is_ability_enabled($this->ability()));
    }

    public function test_exposure_on_cannot_widen_a_governance_disable(): void
    {
        Governance::set_ability_toggle('wpmcp/get-page', false);
        Exposure::set_enabled(true);

        $this->assertFalse(Governance::is_ability_enabled($this->ability()));
    }

    public function test_boot_wires_the_governance_filter_and_the_admin_bar_hook(): void
    {
        $this->assertNotFalse(
            has_filter('wpmcp_ability_enabled', [Exposure::class, 'filter_ability_enabled']),
            'Exposure must narrow through the existing wpmcp_ability_enabled governance filter.'
        );
        $this->assertNotFalse(
            has_action('admin_bar_menu', [Exposure::class, 'admin_bar']),
            'Exposure state must be visible in the admin bar.'
        );
    }

    public function test_admin_bar_node_is_admin_only_and_reflects_state(): void
    {
        require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $bar = new \WP_Admin_Bar();
        Exposure::admin_bar($bar);
        $this->assertEmpty($bar->get_node('wpmcp-exposure'), 'Non-admins must not see the exposure node.');

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $bar = new \WP_Admin_Bar();
        Exposure::admin_bar($bar);
        $node = $bar->get_node('wpmcp-exposure');
        $this->assertNotEmpty($node);
        $this->assertStringContainsString('On', $node->title);
        $this->assertStringContainsString('page=wpmcp-connection', $node->href);

        Exposure::set_enabled(false);
        $bar = new \WP_Admin_Bar();
        Exposure::admin_bar($bar);
        $this->assertStringContainsString('Off', $bar->get_node('wpmcp-exposure')->title);
    }
}
