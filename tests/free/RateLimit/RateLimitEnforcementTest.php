<?php

namespace WPMCP\Tests\Free\RateLimit;

use WPMCP\RateLimit\Rate_Limiter;
use WPMCP\Safety\Snapshot_Store;

/**
 * Proves Rate_Limiter is actually wired into ability execution via Registrar,
 * not just unit-tested in isolation. Exercises the real, globally-registered
 * wpmcp/get-page ability's wrapped execute_callback end to end, driving the
 * limiter's clock override to control the window deterministically.
 */
class RateLimitEnforcementTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        // See WPMCP\Tests\Free\PluginAbilitiesTest for why this is needed:
        // wp_abilities_api_init fires lazily on first registry access, and
        // the real wpmcp/get-page ability (with its wrapped execute_callback)
        // is only registered once that hook has fired.
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        // Every test gets its own clock bucket so per-client transient
        // counters from other tests (and other RateLimiterTest runs sharing
        // the 'user:<id>' key space) can never bleed into this one.
        Rate_Limiter::set_clock_override(fn() => 1_700_000_000);
    }

    protected function tearDown(): void
    {
        Rate_Limiter::set_clock_override(null);
        remove_all_filters('wpmcp_rate_limit');
        remove_all_filters('wpmcp_rate_limit_window');
        remove_all_filters('wpmcp_rate_limit_enabled');
        parent::tearDown();
    }

    private function get_page_ability(): \WP_Ability
    {
        return wp_get_abilities()['wpmcp/get-page'];
    }

    private function admin_user(): int
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        return $admin;
    }

    public function test_ability_invoked_under_the_limit_runs_normally(): void
    {
        $admin = $this->admin_user();
        add_filter('wpmcp_rate_limit', fn() => 5);

        $id = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Hi']);

        $result = $this->get_page_ability()->execute(['id' => $id]);

        $this->assertIsArray($result);
        $this->assertSame('Hi', $result['title']);
    }

    public function test_calls_over_the_limit_are_throttled_without_running_the_tool(): void
    {
        $this->admin_user();
        add_filter('wpmcp_rate_limit', fn() => 2);
        add_filter('wpmcp_rate_limit_window', fn() => 60);

        $id = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Hi']);
        $ability = $this->get_page_ability();

        // Exhaust the shared per-client budget (2 calls allowed).
        $this->assertIsArray($ability->execute(['id' => $id]));
        $this->assertIsArray($ability->execute(['id' => $id]));

        // Third call in the same window must be throttled, not executed.
        $throttled = $ability->execute(['id' => $id]);

        $this->assertInstanceOf(\WP_Error::class, $throttled);
        $data = $throttled->get_error_data();
        $this->assertIsArray($data);
        $this->assertGreaterThan(0, $data['retry_after']);
        $this->assertSame(0, $data['remaining']);
    }

    /**
     * The limiter budget is global per-client across ALL abilities, not
     * per-ability: exhausting the budget on one ability must throttle a
     * different ability for the same client too.
     */
    public function test_limit_is_shared_across_different_abilities_for_the_same_client(): void
    {
        $this->admin_user();
        add_filter('wpmcp_rate_limit', fn() => 1);
        add_filter('wpmcp_rate_limit_window', fn() => 60);

        $id = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Hi']);

        // Spend the single-call budget on get-page.
        $this->assertIsArray($this->get_page_ability()->execute(['id' => $id]));

        // A completely different ability for the same client is throttled too.
        $list_operations = wp_get_abilities()['wpmcp/list-operations']->execute([]);

        $this->assertInstanceOf(\WP_Error::class, $list_operations);
    }

    public function test_disabling_the_limiter_never_throttles(): void
    {
        $this->admin_user();
        add_filter('wpmcp_rate_limit', fn() => 1);
        add_filter('wpmcp_rate_limit_window', fn() => 60);
        add_filter('wpmcp_rate_limit_enabled', '__return_false');

        $id = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Hi']);
        $ability = $this->get_page_ability();

        // Well past the limit of 1, every call must still run the real tool.
        for ($i = 0; $i < 5; $i++) {
            $result = $ability->execute(['id' => $id]);
            $this->assertIsArray($result, "Call {$i} must not be throttled while the limiter is disabled.");
            $this->assertSame('Hi', $result['title']);
        }
    }

    /**
     * A denied permission_callback (unrelated to rate limiting) must still
     * refuse execution: the rate limit wrapper must not weaken or bypass the
     * existing capability + Governance::is_ability_enabled gate.
     */
    public function test_permission_denial_is_unaffected_by_the_rate_limiter(): void
    {
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        add_filter('wpmcp_rate_limit', fn() => 1000);

        $id = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Hi']);

        $result = $this->get_page_ability()->execute(['id' => $id]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('ability_invalid_permissions', $result->get_error_code());
    }
}
