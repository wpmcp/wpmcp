<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Governance\Governance;
use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;
use WPMCP\MCP\Handshake_Instructions;

/**
 * The handshake auto-summary must respect the same authorization layers as
 * the get-site-context tool it is derived from: a connecting identity (or
 * an under-capable/anonymous user, or a governance disable) that could not
 * call the tool itself must not receive the site-derived context in the
 * initialize instructions - only the generic safety one-liner.
 */
class HandshakeInstructionsScopeTest extends \WP_UnitTestCase
{
    private const SITE_NAME = 'Scope Gated Site';

    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Handshake_Instructions::OPTION);
        delete_option(Identity_Store::OPTION);
        Governance::reset_for_tests();
        update_option('blogname', self::SITE_NAME);
    }

    protected function tearDown(): void
    {
        Identity_Context::set_current_for_tests(null);
        delete_option(Handshake_Instructions::OPTION);
        delete_option(Identity_Store::OPTION);
        Governance::reset_for_tests();
        parent::tearDown();
    }

    public function test_an_identity_scoped_away_from_the_context_domain_gets_no_site_data(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);

        Identity_Store::create('content-only-bot', ['domains' => ['content']]);
        Identity_Context::set_current_for_tests('content-only-bot');

        $instructions = new Handshake_Instructions();

        $this->assertFalse($instructions->can_view_site_context());

        $built = $instructions->build();
        $this->assertStringNotContainsString(self::SITE_NAME, $built);
        $this->assertStringNotContainsString('Active builder', $built);
        $this->assertStringContainsString('snapshotted', $built, 'The generic safety line must survive.');
    }

    public function test_a_context_scoped_identity_still_gets_the_full_summary(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);

        Identity_Store::create('context-bot', ['domains' => ['context']]);
        Identity_Context::set_current_for_tests('context-bot');

        $instructions = new Handshake_Instructions();

        $this->assertTrue($instructions->can_view_site_context());
        $this->assertStringContainsString(self::SITE_NAME, $instructions->build());
    }

    public function test_an_anonymous_connection_gets_no_site_data(): void
    {
        wp_set_current_user(0);

        $built = (new Handshake_Instructions())->build();

        $this->assertStringNotContainsString(self::SITE_NAME, $built);
        $this->assertStringContainsString('snapshotted', $built);
    }

    public function test_a_governance_disable_of_get_site_context_degrades_the_summary(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);

        Governance::set_ability_toggle('wpmcp/get-site-context', false);

        $built = (new Handshake_Instructions())->build();

        $this->assertStringNotContainsString(self::SITE_NAME, $built);
        $this->assertStringContainsString('snapshotted', $built);
    }

    public function test_admin_text_is_still_served_when_the_summary_is_gated(): void
    {
        // The admin field's contract is "served to every connecting agent";
        // gating applies to the site-derived summary, not to the operator's
        // own authored guidance.
        wp_set_current_user(0);
        update_option(Handshake_Instructions::OPTION, 'Always ask before publishing.');

        $built = (new Handshake_Instructions())->build();

        $this->assertStringContainsString('Always ask before publishing.', $built);
        $this->assertStringNotContainsString(self::SITE_NAME, $built);
    }
}
