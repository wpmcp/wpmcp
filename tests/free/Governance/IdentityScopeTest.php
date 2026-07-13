<?php

namespace WPMCP\Tests\Free\Governance;

use WPMCP\Governance\Governance;
use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;
use WPMCP\MCP\Ability;

/**
 * Identity scope is an additional narrowing layer on top of capability and
 * Governance: it can only take an otherwise-allowed ability away, never
 * grant one back. See Registrar::register()'s permission_callback for the
 * enforcement point, and Governance::is_within_identity_scope() for the
 * exact allowlist-matching semantics.
 */
class IdentityScopeTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Identity_Store::OPTION);
    }

    protected function tearDown(): void
    {
        Identity_Context::set_current_for_tests(null);
        delete_option(Identity_Store::OPTION);
        parent::tearDown();
    }

    private function ability(string $name = 'wpmcp/get-post', string $domain = 'content', string $operation = 'read'): Ability
    {
        return new Ability($name, 'free', 'desc', [], fn() => [], 'edit_posts', $domain, $operation);
    }

    public function test_no_active_identity_means_no_additional_restriction(): void
    {
        $this->assertTrue(Governance::is_within_identity_scope($this->ability()));
    }

    public function test_an_unknown_identity_name_results_in_default_deny(): void
    {
        Identity_Context::set_current_for_tests('never-registered');

        $this->assertFalse(Governance::is_within_identity_scope($this->ability()));
    }

    public function test_domain_scoped_identity_blocks_a_different_domain_and_allows_the_scoped_one(): void
    {
        Identity_Store::create('content-only-bot', ['domains' => ['content']]);
        Identity_Context::set_current_for_tests('content-only-bot');

        $content_ability  = $this->ability('wpmcp/get-post', 'content', 'read');
        $database_ability = $this->ability('wpmcp/list-rows', 'database', 'read');

        $this->assertTrue(Governance::is_within_identity_scope($content_ability));
        $this->assertFalse(Governance::is_within_identity_scope($database_ability));
    }

    public function test_identity_with_empty_scope_arrays_is_unrestricted(): void
    {
        Identity_Store::create('unrestricted-bot', []);
        Identity_Context::set_current_for_tests('unrestricted-bot');

        $this->assertTrue(Governance::is_within_identity_scope($this->ability('wpmcp/get-post', 'content', 'read')));
        $this->assertTrue(Governance::is_within_identity_scope($this->ability('wpmcp/delete-rows', 'database', 'delete')));
    }

    public function test_identity_scope_ands_multiple_dimensions(): void
    {
        Identity_Store::create('content-reader-bot', [
            'domains'    => ['content'],
            'operations' => ['read'],
        ]);
        Identity_Context::set_current_for_tests('content-reader-bot');

        $content_read   = $this->ability('wpmcp/get-post', 'content', 'read');
        $content_delete = $this->ability('wpmcp/delete-post', 'content', 'delete');

        $this->assertTrue(Governance::is_within_identity_scope($content_read));
        $this->assertFalse(Governance::is_within_identity_scope($content_delete));
    }
}
