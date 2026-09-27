<?php

namespace WPMCP\Tests\Free\Migration;

use WPMCP\Plugin;

class MigrationAbilitiesRegistrationTest extends \WP_UnitTestCase
{
    private const NAMES = [
        'wpmcp/rewrite-site-urls',
        'wpmcp/push-site-archive',
        'wpmcp/receive-site-archive',
    ];

    public function test_migration_tools_are_registered_as_free_abilities(): void
    {
        $names = array_keys(wp_get_abilities());

        foreach (self::NAMES as $name) {
            $this->assertContains($name, $names, "Expected {$name} to be registered");
        }
    }

    public function test_migration_abilities_have_description_and_category(): void
    {
        $abilities = wp_get_abilities();

        foreach (self::NAMES as $name) {
            $ability = $abilities[ $name ];
            $this->assertNotEmpty($ability->get_description(), "Expected {$name} to have a description");
            $this->assertSame('wpmcp', $ability->get_category());
        }
    }

    public function test_migration_abilities_deny_subscriber_and_allow_administrator(): void
    {
        $abilities = wp_get_abilities();

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        foreach (self::NAMES as $name) {
            $this->assertFalse($abilities[ $name ]->check_permissions(), "{$name} must deny a subscriber");
        }

        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        foreach (self::NAMES as $name) {
            $this->assertTrue($abilities[ $name ]->check_permissions(), "{$name} must allow an administrator");
        }
    }

    /**
     * An applied rewrite is an in-place write to six core tables, backed by
     * a database safety archive rather than a per-object snapshot. The MCP annotations must say so rather than inherit the
     * 'update' defaults (destructive false, idempotent true).
     */
    public function test_rewrite_site_urls_is_annotated_as_destructive_and_not_idempotent(): void
    {
        $ability = Plugin::instance()->registrar()->get('wpmcp/rewrite-site-urls');

        $this->assertNotNull($ability);
        $this->assertFalse($ability->read_only_hint);
        $this->assertTrue($ability->destructive_hint);
        $this->assertFalse($ability->idempotent_hint);
        $this->assertStringContainsString('safety archive', $ability->description);
    }

    /**
     * Both push-pair abilities are closed by default-off opt-in gates that
     * only code can open, so the ability grid must treat them as gated.
     */
    public function test_push_pair_is_behind_default_off_opt_in_gates(): void
    {
        $this->assertTrue(\WPMCP\Governance\Opt_In_Gates::is_gated('wpmcp/push-site-archive'));
        $this->assertTrue(\WPMCP\Governance\Opt_In_Gates::is_gated('wpmcp/receive-site-archive'));
        $this->assertFalse(\WPMCP\Governance\Opt_In_Gates::is_open('wpmcp/push-site-archive'));
        $this->assertFalse(\WPMCP\Governance\Opt_In_Gates::is_open('wpmcp/receive-site-archive'));

        foreach (['wpmcp/push-site-archive', 'wpmcp/receive-site-archive'] as $name) {
            $ability = Plugin::instance()->registrar()->get($name);
            $this->assertTrue($ability->destructive_hint, "{$name} can end in a database replace");
            $this->assertFalse($ability->idempotent_hint);
        }
    }
}
