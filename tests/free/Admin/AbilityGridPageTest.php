<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Admin\Ability_Grid_Page;
use WPMCP\Governance\Governance;
use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\MCP\Registrar;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;
use WPMCP\Tests\Free\Platform\RegisteredAbilities;

/**
 * Issue #78: the per-ability admin toggle grid.
 *
 * A manage_options screen listing the FULL declared ability surface (the
 * Registrar's declared set — never a hardcoded list), grouped by domain,
 * showing tier, risk hints, and the effective state WITH the layer that
 * decides it. Toggles write through the existing Governance mechanism only
 * (no new bypass), every change is audited with the acting user, pro rows
 * whose tier is unavailable have no row at all (issue #161), and default-off dangerous
 * abilities (exec, db writes, fs writes) cannot be enabled from the grid
 * while their execution opt-in filter is absent — the filter stays the
 * master gate.
 */
class AbilityGridPageTest extends \WP_UnitTestCase
{
    private int $admin_id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin_id);
        Governance::reset_for_tests();
        delete_option(Governance_Audit_Log::OPTION);
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        Governance::reset_for_tests();
        delete_option(Governance_Audit_Log::OPTION);
        parent::tearDown();
    }

    private function post(string $action, array $extra = []): array
    {
        return array_merge([
            'wpmcp_grid_action' => $action,
            '_wpnonce'          => wp_create_nonce(Ability_Grid_Page::NONCE_ACTION),
        ], $extra);
    }

    /** @return array<string, array<int, array>> domain => rows */
    private function rows(): array
    {
        return (new Ability_Grid_Page())->rows();
    }

    /** @return string[] every ability name the grid lists, sorted. */
    private function row_names(): array
    {
        $names = [];
        foreach ($this->rows() as $rows) {
            foreach ($rows as $row) {
                $names[] = $row['name'];
            }
        }
        sort($names);
        return $names;
    }

    private function row(string $name): array
    {
        foreach ($this->rows() as $rows) {
            foreach ($rows as $row) {
                if ($name === $row['name']) {
                    return $row;
                }
            }
        }
        $this->fail("No grid row for {$name}.");
    }

    // ---------------------------------------------------------------
    // Registry source of truth
    // ---------------------------------------------------------------

    public function test_grid_rows_equal_the_registered_ability_surface(): void
    {
        // Licensed, so the grid surface matches the full declared manifest;
        // unlicensed installs simply omit the pro rows (issue #161).
        Gate::set_pro_for_tests(true);

        $names = [];
        foreach ($this->rows() as $rows) {
            foreach ($rows as $row) {
                $names[] = $row['name'];
            }
        }
        sort($names);

        $this->assertSame(
            array_keys(RegisteredAbilities::manifest_map()),
            $names,
            'Grid rows must be exactly the Registrar\'s declared ability surface — not a hardcoded list.'
        );
    }

    public function test_rows_are_grouped_by_the_abilities_own_domain(): void
    {
        $rows = $this->rows();

        $this->assertArrayHasKey('database', $rows);
        foreach ($rows['database'] as $row) {
            $this->assertSame('database', $row['domain']);
        }
    }

    // ---------------------------------------------------------------
    // Effective state + deciding layer
    // ---------------------------------------------------------------

    public function test_an_untouched_free_ability_reads_enabled(): void
    {
        $row = $this->row('wpmcp/get-page');

        $this->assertTrue($row['enabled']);
        $this->assertSame('enabled', $row['reason']);
    }

    public function test_a_governance_disabled_ability_names_the_toggle_layer(): void
    {
        Governance::set_ability_toggle('wpmcp/get-page', false);

        $row = $this->row('wpmcp/get-page');

        $this->assertFalse($row['enabled']);
        $this->assertStringContainsString('governance ability toggle', $row['reason']);
    }

    public function test_a_domain_disabled_ability_names_the_domain_layer(): void
    {
        Governance::set_domain_toggle('database', false);

        $row = $this->row('wpmcp/describe-table');

        $this->assertFalse($row['enabled']);
        $this->assertStringContainsString('governance domain toggle', $row['reason']);
    }

    public function test_the_master_switch_reason_is_called_out_distinctly(): void
    {
        update_option(\WPMCP\Connect\Exposure::OPTION, '0');

        $row = $this->row('wpmcp/get-page');

        $this->assertFalse($row['enabled']);
        $this->assertStringContainsString('master switch', $row['reason']);

        delete_option(\WPMCP\Connect\Exposure::OPTION);
    }

    // ---------------------------------------------------------------
    // Pro tier: unavailable abilities are absent, never locked teasers
    // ---------------------------------------------------------------

    public function test_the_unlicensed_grid_is_exactly_the_free_tier_of_the_manifest(): void
    {
        $free = array_keys(array_filter(
            RegisteredAbilities::manifest_map(),
            static fn (string $tier): bool => 'free' === $tier
        ));
        sort($free);

        $this->assertNotEmpty($free, 'The manifest must declare free abilities for this test to mean anything.');
        $this->assertSame(
            $free,
            $this->row_names(),
            'Unlicensed, the grid must be exactly the free tier: nothing withheld is listed, nothing free is lost.'
        );
    }

    public function test_a_named_pro_ability_has_no_row_while_a_named_free_one_does(): void
    {
        $names = $this->row_names();

        $this->assertContains('wpmcp/get-page', $names, 'A free ability must still have a row.');
        $this->assertNotContains(
            'wpmcp/run-php-snippet',
            $names,
            'A withheld pro ability must not render a grid row (issue #161).'
        );
        $this->assertNotContains('wpmcp/run-wp-cli', $names);
    }

    public function test_unlicensed_pro_abilities_have_no_grid_row_at_all(): void
    {
        $grid = $this->rows();
        $this->assertNotEmpty($grid, 'An empty grid would pass the loop below vacuously.');

        foreach ($grid as $rows) {
            foreach ($rows as $row) {
                $this->assertNotSame(
                    'pro',
                    $row['tier'],
                    "Unlicensed pro ability {$row['name']} must not render a grid row (issue #161)."
                );
                $this->assertArrayNotHasKey('pro_locked', $row);
                $this->assertStringNotContainsString('pro license', $row['reason']);
            }
        }
    }

    public function test_pro_rows_appear_with_a_license_without_lock_state(): void
    {
        Gate::set_pro_for_tests(true);

        $row = $this->row('wpmcp/analyze-seo');

        $this->assertSame('pro', $row['tier']);
        $this->assertArrayNotHasKey('pro_locked', $row);
        $this->assertStringNotContainsString('pro license', $row['reason']);
    }

    // ---------------------------------------------------------------
    // Toggling writes through Governance — and is audited
    // ---------------------------------------------------------------

    public function test_disabling_an_ability_writes_the_governance_toggle_and_registration_honors_it(): void
    {
        $result = (new Ability_Grid_Page())->handle_request(
            $this->post('toggle_ability', ['ability' => 'wpmcp/get-page', 'enabled' => '0'])
        );

        $this->assertArrayNotHasKey('error', $result);
        $this->assertFalse(Governance::ability_toggles()['wpmcp/get-page']);

        // The write went through the real governance mechanism: the
        // registration path (no admin class involved) now drops the ability.
        $registrar = new Registrar();
        $registrar->register(Plugin::instance()->registrar()->get('wpmcp/get-page'));
        $this->assertSame([], $registrar->all());
    }

    public function test_every_grid_change_lands_in_the_audit_log_with_the_acting_user(): void
    {
        (new Ability_Grid_Page())->handle_request(
            $this->post('toggle_ability', ['ability' => 'wpmcp/get-page', 'enabled' => '0'])
        );

        $entries = Governance_Audit_Log::list(1);
        $login   = get_userdata($this->admin_id)->user_login;

        $this->assertCount(1, $entries);
        $this->assertSame('wpmcp/get-page', $entries[0]['ability']);
        $this->assertSame('user:' . $login, $entries[0]['identity']);
        $this->assertFalse($entries[0]['allowed']);
    }

    public function test_toggling_requires_a_valid_nonce(): void
    {
        $result = (new Ability_Grid_Page())->handle_request([
            'wpmcp_grid_action' => 'toggle_ability',
            '_wpnonce'          => 'bogus',
            'ability'           => 'wpmcp/get-page',
            'enabled'           => '0',
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('wpmcp/get-page', Governance::ability_toggles());
    }

    public function test_toggling_requires_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $result = (new Ability_Grid_Page())->handle_request(
            $this->post('toggle_ability', ['ability' => 'wpmcp/get-page', 'enabled' => '0'])
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('wpmcp/get-page', Governance::ability_toggles());
    }

    public function test_toggling_an_unknown_ability_is_refused(): void
    {
        $result = (new Ability_Grid_Page())->handle_request(
            $this->post('toggle_ability', ['ability' => 'wpmcp/not-a-thing', 'enabled' => '0'])
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertSame([], Governance::ability_toggles());
    }

    public function test_toggling_a_withheld_pro_ability_is_refused_like_an_unknown_one(): void
    {
        foreach (['0', '1'] as $enabled) {
            $result = (new Ability_Grid_Page())->handle_request(
                $this->post('toggle_ability', ['ability' => 'wpmcp/run-php-snippet', 'enabled' => $enabled])
            );

            $this->assertArrayHasKey(
                'error',
                $result,
                'A hand-crafted POST must not reach an ability the grid does not list.'
            );
        }

        $this->assertSame([], Governance::ability_toggles());
        $this->assertSame([], Governance_Audit_Log::list(10));
    }

    public function test_bulk_domain_enable_never_touches_or_names_a_withheld_pro_ability(): void
    {
        // The 'code' domain holds a free ability (validate-php-snippet) and a
        // pro one (run-php-snippet), so the domain still renders unlicensed.
        $result = (new Ability_Grid_Page())->handle_request(
            $this->post('toggle_domain', ['domain' => 'code', 'enabled' => '1'])
        );

        $this->assertArrayNotHasKey('error', $result);
        $this->assertContains('wpmcp/validate-php-snippet', $result['updated']);
        $this->assertNotContains('wpmcp/run-php-snippet', $result['updated']);
        $this->assertNotContains(
            'wpmcp/run-php-snippet',
            $result['refused'],
            'The refused list is printed verbatim on the screen: it must never name a withheld ability.'
        );
        $this->assertArrayNotHasKey('wpmcp/run-php-snippet', Governance::ability_toggles());
    }

    // ---------------------------------------------------------------
    // Dangerous default-off abilities: the opt-in filter stays master
    // ---------------------------------------------------------------

    /** @param array<string, string> $expected ability name => its opt-in filter. */
    private function assertDangerousRows(array $expected): void
    {
        foreach ($expected as $name => $filter) {
            $row = $this->row($name);
            $this->assertTrue($row['dangerous'], "{$name} must carry the dangerous flag.");
            $this->assertSame($filter, $row['gate_filter']);
            $this->assertFalse($row['gate_open'], "{$name}'s opt-in gate must read closed by default.");
        }
    }

    public function test_dangerous_rows_carry_a_distinct_warning_and_their_gate_filter(): void
    {
        $this->assertDangerousRows([
            'wpmcp/delete-rows' => 'wpmcp_enable_db_writes',
            'wpmcp/write-file'  => 'wpmcp_enable_fs_writes',
        ]);
    }

    public function test_dangerous_pro_rows_carry_the_same_warning_once_licensed(): void
    {
        Gate::set_pro_for_tests(true);

        $this->assertDangerousRows([
            'wpmcp/run-wp-cli'      => 'wpmcp_allow_wp_cli',
            'wpmcp/run-php-snippet' => 'wpmcp_allow_php_exec',
        ]);
    }

    public function test_grid_refuses_to_enable_a_dangerous_ability_while_its_opt_in_filter_is_absent(): void
    {
        $result = (new Ability_Grid_Page())->handle_request(
            $this->post('toggle_ability', ['ability' => 'wpmcp/delete-rows', 'enabled' => '1'])
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('wpmcp_enable_db_writes', $result['error']);
        $this->assertArrayNotHasKey(
            'wpmcp/delete-rows',
            Governance::ability_toggles(),
            'The refused enable must write nothing.'
        );
    }

    public function test_a_dangerous_ability_can_be_enabled_once_its_opt_in_filter_is_present(): void
    {
        add_filter('wpmcp_enable_db_writes', '__return_true');

        $result = (new Ability_Grid_Page())->handle_request(
            $this->post('toggle_ability', ['ability' => 'wpmcp/delete-rows', 'enabled' => '1'])
        );

        $this->assertArrayNotHasKey('error', $result);
        $this->assertTrue(Governance::ability_toggles()['wpmcp/delete-rows']);

        remove_filter('wpmcp_enable_db_writes', '__return_true');
    }

    public function test_disabling_a_dangerous_ability_is_always_allowed(): void
    {
        $result = (new Ability_Grid_Page())->handle_request(
            $this->post('toggle_ability', ['ability' => 'wpmcp/delete-rows', 'enabled' => '0'])
        );

        $this->assertArrayNotHasKey('error', $result);
        $this->assertFalse(Governance::ability_toggles()['wpmcp/delete-rows']);
    }

    // ---------------------------------------------------------------
    // Bulk per-domain enable/disable
    // ---------------------------------------------------------------

    public function test_bulk_domain_disable_writes_the_domain_toggle_and_audits_it(): void
    {
        $result = (new Ability_Grid_Page())->handle_request(
            $this->post('toggle_domain', ['domain' => 'database', 'enabled' => '0'])
        );

        $this->assertArrayNotHasKey('error', $result);
        $this->assertFalse(Governance::domain_toggles()['database']);

        $entries = Governance_Audit_Log::list(1);
        $this->assertSame('domain:database', $entries[0]['ability']);
        $this->assertSame('user:' . get_userdata($this->admin_id)->user_login, $entries[0]['identity']);
        $this->assertFalse($entries[0]['allowed']);
    }

    public function test_bulk_domain_enable_skips_gate_closed_dangerous_abilities(): void
    {
        Governance::set_domain_toggle('database', false);

        $result = (new Ability_Grid_Page())->handle_request(
            $this->post('toggle_domain', ['domain' => 'database', 'enabled' => '1'])
        );

        $this->assertArrayNotHasKey('error', $result);
        $this->assertTrue(Governance::domain_toggles()['database']);

        // The gate-closed db writers were refused, not silently enabled.
        foreach (['wpmcp/insert-row', 'wpmcp/update-rows', 'wpmcp/delete-rows'] as $gated) {
            $this->assertContains($gated, $result['refused']);
            $this->assertNotContains($gated, $result['updated']);
            $this->assertArrayNotHasKey($gated, Governance::ability_toggles());
        }

        // Non-gated database abilities were enabled and audited.
        $this->assertNotEmpty($result['updated']);
        foreach ($result['updated'] as $name) {
            $this->assertTrue(Governance::ability_toggles()[$name]);
        }
    }

    public function test_bulk_domain_toggle_rejects_an_unknown_domain(): void
    {
        $result = (new Ability_Grid_Page())->handle_request(
            $this->post('toggle_domain', ['domain' => 'not-a-domain', 'enabled' => '0'])
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertSame([], Governance::domain_toggles());
    }

    // ---------------------------------------------------------------
    // Menu + rendering
    // ---------------------------------------------------------------

    public function test_abilities_submenu_is_registered_under_manage_options(): void
    {
        global $menu, $submenu;
        $menu    = [];
        $submenu = [];

        Plugin::instance()->register_admin_menu();

        $found = null;
        foreach ($submenu['wpmcp'] ?? [] as $item) {
            if (Ability_Grid_Page::SLUG === $item[2]) {
                $found = $item;
            }
        }

        $this->assertNotNull($found, 'Expected a wpmcp-abilities submenu entry.');
        $this->assertSame('manage_options', $found[1]);
    }

    public function test_render_outputs_every_domain_group_and_marks_dangerous_rows(): void
    {
        ob_start();
        (new Ability_Grid_Page())->render();
        $html = ob_get_clean();

        $this->assertStringContainsString('wpmcp/get-page', $html);
        $this->assertStringContainsString('wpmcp/delete-rows', $html);
        $this->assertStringContainsString('wpmcp_enable_db_writes', $html);
        foreach (array_keys($this->rows()) as $domain) {
            $this->assertStringContainsString($domain, $html);
        }

        // Issue #161: no withheld ability, and no lock or upsell copy.
        $this->assertStringNotContainsString('wpmcp/run-php-snippet', $html);
        $this->assertStringNotContainsString('pro license', $html);
        $this->assertStringNotContainsString('(locked)', $html);
    }
}
