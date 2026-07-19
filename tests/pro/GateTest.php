<?php

namespace WPMCP\Tests\Pro;

use WPMCP\Freemius\Bootstrap;
use WPMCP\Pro\Gate;
use WPMCP\MCP\{Registrar, Ability};

class GateTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        Bootstrap::set_fs_for_tests(null);
        parent::tearDown();
    }

    /** Build a Freemius-shaped stub whose license state is fixed. */
    private function fs_stub(bool $premium): object
    {
        return new class ($premium) {
            public function __construct(private bool $premium)
            {
            }

            public function can_use_premium_code(): bool
            {
                return $this->premium;
            }
        };
    }

    public function test_free_defaults(): void
    {
        Gate::set_pro_for_tests(false);
        $this->assertFalse(Gate::is_pro());
        $this->assertSame(20, Gate::history_limit());
    }

    public function test_registrar_skips_pro_when_free(): void
    {
        Gate::set_pro_for_tests(false);
        $r = new Registrar();
        $r->register(new Ability('wpmcp/elementor-deep', 'pro', 'Pro', [], fn($a) => []));
        $this->assertCount(0, $r->all());
    }

    public function test_pro_unlocks(): void
    {
        Gate::set_pro_for_tests(true);
        $this->assertTrue(Gate::is_pro());
        $this->assertGreaterThan(1000000, Gate::history_limit());
    }

    public function test_is_pro_falls_back_safely_without_freemius_sdk(): void
    {
        // Real default path with the SDK forced absent: is_pro() must
        // short-circuit to false, not fatal.
        Bootstrap::set_fs_for_tests(false);

        $this->assertNull(Bootstrap::fs());
        $this->assertFalse(Gate::is_pro());
    }

    public function test_is_pro_false_on_unlicensed_install_via_real_sdk(): void
    {
        // No Gate override and no fs stub: the live SDK is loaded by the
        // harness and this install holds no license, so pro must be off.
        $this->assertTrue(function_exists('wpmcp_fs'));
        $this->assertFalse(Gate::is_pro());
    }

    public function test_is_pro_true_when_freemius_license_state_is_premium(): void
    {
        Bootstrap::set_fs_for_tests($this->fs_stub(true));

        $this->assertTrue(Gate::is_pro());
        $this->assertGreaterThan(1000000, Gate::history_limit());
    }

    public function test_is_pro_false_when_freemius_license_state_is_free(): void
    {
        Bootstrap::set_fs_for_tests($this->fs_stub(false));

        $this->assertFalse(Gate::is_pro());
        $this->assertSame(20, Gate::history_limit());
    }

    public function test_gate_test_seam_wins_over_freemius_state(): void
    {
        // Existing seam preserved: an explicit override beats license state.
        Bootstrap::set_fs_for_tests($this->fs_stub(false));
        Gate::set_pro_for_tests(true);

        $this->assertTrue(Gate::is_pro());
    }

    public function test_registrar_registers_pro_ability_under_simulated_license(): void
    {
        Gate::set_pro_for_tests(null);
        Bootstrap::set_fs_for_tests($this->fs_stub(true));

        $r = new Registrar();
        $r->register(new Ability('wpmcp/elementor-deep', 'pro', 'Pro', [], fn ($a) => []));

        $this->assertCount(1, $r->all());
    }

    public function test_permission_recheck_denies_pro_ability_after_license_lapse(): void
    {
        // Issue #54: license state is re-checked at permission time, not
        // only at registration. Simulate a license that was valid when the
        // ability registered but lapsed before the permission check.
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $ability = new Ability('wpmcp/elementor-deep', 'pro', 'Pro', [], fn ($a) => []);

        $r = new Registrar();
        Gate::set_pro_for_tests(true);
        $r->register($ability);
        $this->assertCount(1, $r->all());

        $this->assertTrue($r->is_permitted($ability));

        Gate::set_pro_for_tests(false);
        $this->assertFalse($r->is_permitted($ability));
    }

    public function test_permission_recheck_leaves_free_abilities_unaffected(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        Gate::set_pro_for_tests(false);

        $ability = new Ability('wpmcp/free-thing', 'free', 'Free', [], fn ($a) => []);

        $r = new Registrar();
        $r->register($ability);

        $this->assertTrue($r->is_permitted($ability));
    }
}
