<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;

/**
 * Exercises Registrar::register()'s real permission_callback (via
 * check_permissions() on the already-registered wp_get_abilities() entries),
 * proving identity scope is enforced per-request in addition to, never
 * instead of, capability + Governance, and that every decision is recorded
 * to Governance_Audit_Log.
 */
class RegistrarIdentityScopeAndAuditTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        // See WPMCP\Tests\Free\PluginAbilitiesTest for why this is needed:
        // wp_abilities_api_init fires lazily on first registry access, and
        // the real wpmcp/get-post and wpmcp/query abilities (with their
        // wrapped permission_callback) are only registered once that hook
        // has fired.
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Identity_Store::OPTION);
        delete_option(Governance_Audit_Log::OPTION);
        Governance_Audit_Log::set_clock_for_tests(null);
    }

    protected function tearDown(): void
    {
        Identity_Context::set_current_for_tests(null);
        delete_option(Identity_Store::OPTION);
        delete_option(Governance_Audit_Log::OPTION);
        Governance_Audit_Log::set_clock_for_tests(null);
        parent::tearDown();
    }

    public function test_domain_scoped_identity_blocks_a_different_domain_at_the_permission_callback(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);

        Identity_Store::create('content-only-bot', ['domains' => ['content']]);
        Identity_Context::set_current_for_tests('content-only-bot');

        $abilities = wp_get_abilities();

        $this->assertTrue($abilities['wpmcp/get-post']->check_permissions());
        $this->assertFalse($abilities['wpmcp/query']->check_permissions());
    }

    public function test_identity_scope_cannot_escalate_past_a_failing_capability_check(): void
    {
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);

        // Explicitly scope the identity to include wpmcp/query: even though
        // the identity's allowlist covers it, the subscriber lacks
        // manage_options, so the ability must still be denied.
        Identity_Store::create('database-bot', ['domains' => ['database']]);
        Identity_Context::set_current_for_tests('database-bot');

        $abilities = wp_get_abilities();

        $this->assertFalse($abilities['wpmcp/query']->check_permissions());
    }

    public function test_audit_log_records_an_allowed_and_a_denied_decision(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);

        Identity_Store::create('content-only-bot', ['domains' => ['content']]);
        Identity_Context::set_current_for_tests('content-only-bot');

        Governance_Audit_Log::set_clock_for_tests(1700000000);

        $abilities = wp_get_abilities();
        $abilities['wpmcp/get-post']->check_permissions();
        $abilities['wpmcp/query']->check_permissions();

        $entries = Governance_Audit_Log::list();

        $get_post_entry = current(array_filter($entries, fn($e) => 'wpmcp/get-post' === $e['ability']));
        $query_entry    = current(array_filter($entries, fn($e) => 'wpmcp/query' === $e['ability']));

        $this->assertNotFalse($get_post_entry);
        $this->assertNotFalse($query_entry);
        $this->assertSame('content-only-bot', $get_post_entry['identity']);
        $this->assertTrue($get_post_entry['allowed']);
        $this->assertSame('content-only-bot', $query_entry['identity']);
        $this->assertFalse($query_entry['allowed']);
    }

    public function test_audit_log_records_none_when_no_identity_is_active(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);

        $abilities = wp_get_abilities();
        $abilities['wpmcp/get-post']->check_permissions();

        $entries     = Governance_Audit_Log::list();
        $matching    = current(array_filter($entries, fn($e) => 'wpmcp/get-post' === $e['ability']));

        $this->assertNotFalse($matching);
        $this->assertSame('none', $matching['identity']);
        $this->assertTrue($matching['allowed']);
    }
}
