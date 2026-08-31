<?php

namespace WPMCP\Tests\Free\Governance;

use WPMCP\Governance\Default_Seeder;
use WPMCP\Governance\Governance;
use WPMCP\MCP\Ability;
use WPMCP\MCP\Registrar;

/**
 * Issue #78: the versioned default-disabled seeder.
 *
 * The disabled set is stored with a defaults version; on upgrade, defaults
 * introduced by newer versions apply (newly shipped dangerous abilities
 * arrive OFF) WITHOUT clobbering explicit admin decisions. Enforcement rides
 * the existing Governance stored-toggle layer, so it works in the
 * registration/permission path with no Admin class loaded.
 */
class DefaultSeederTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Governance::reset_for_tests();
        delete_option(Default_Seeder::VERSION_OPTION);
    }

    protected function tearDown(): void
    {
        Default_Seeder::set_versions_for_tests(null);
        Governance::reset_for_tests();
        delete_option(Default_Seeder::VERSION_OPTION);
        parent::tearDown();
    }

    private function ability(string $name, string $domain = 'content'): Ability
    {
        return new Ability($name, 'free', 'desc', [], fn () => [], 'edit_posts', $domain, 'read');
    }

    public function test_fresh_install_applies_every_versions_defaults_and_records_the_version(): void
    {
        Default_Seeder::set_versions_for_tests([
            1 => ['wpmcp/alpha'],
            2 => ['wpmcp/beta'],
        ]);

        Default_Seeder::seed();

        $this->assertFalse(Governance::ability_toggles()['wpmcp/alpha']);
        $this->assertFalse(Governance::ability_toggles()['wpmcp/beta']);
        $this->assertSame(2, Default_Seeder::applied_version());
    }

    public function test_upgrade_applies_new_version_defaults_without_clobbering_admin_decisions(): void
    {
        Default_Seeder::set_versions_for_tests([1 => ['wpmcp/alpha']]);
        Default_Seeder::seed();

        // The admin explicitly re-enables the seeded-off ability.
        Governance::set_ability_toggle('wpmcp/alpha', true);

        // A new plugin version ships more defaults — including one that
        // names the ability the admin already decided about.
        Default_Seeder::set_versions_for_tests([
            1 => ['wpmcp/alpha'],
            2 => ['wpmcp/alpha', 'wpmcp/beta'],
        ]);
        Default_Seeder::seed();

        $this->assertTrue(
            Governance::ability_toggles()['wpmcp/alpha'],
            'The admin\'s explicit enable must survive the upgrade seeding.'
        );
        $this->assertFalse(
            Governance::ability_toggles()['wpmcp/beta'],
            'The newly shipped default-off ability must arrive disabled.'
        );
        $this->assertSame(2, Default_Seeder::applied_version());
    }

    public function test_reseeding_the_same_version_is_a_no_op(): void
    {
        Default_Seeder::set_versions_for_tests([1 => ['wpmcp/alpha']]);
        Default_Seeder::seed();

        Governance::set_ability_toggle('wpmcp/alpha', true);
        Default_Seeder::seed();

        $this->assertTrue(
            Governance::ability_toggles()['wpmcp/alpha'],
            'Re-running the seeder on an already-applied version must not re-disable anything.'
        );
    }

    public function test_seeded_disable_is_enforced_in_the_registration_path_without_any_admin_class(): void
    {
        Default_Seeder::set_versions_for_tests([1 => ['wpmcp/seeded-off']]);
        Default_Seeder::seed();

        // Pure Governance + Registrar: no Admin\* class involved.
        $this->assertFalse(Governance::is_ability_enabled($this->ability('wpmcp/seeded-off')));

        $registrar = new Registrar();
        $registrar->register($this->ability('wpmcp/seeded-off'));
        $this->assertSame([], $registrar->all(), 'A seeded-off ability must not register.');
    }

    public function test_seeder_only_narrows_a_seeded_entry_never_widens_past_another_layer(): void
    {
        // An explicit stored "enabled" (whether admin- or seeder-adjacent)
        // can never override a disable from a broader governance layer.
        Governance::set_ability_toggle('wpmcp/alpha', true);
        Governance::set_domain_toggle('content', false);

        $this->assertFalse(
            Governance::is_ability_enabled($this->ability('wpmcp/alpha')),
            'AND-of-narrowing: layers may only narrow, never widen.'
        );
    }

    public function test_shipped_defaults_disable_gateway_provision_and_nothing_else(): void
    {
        // The execution-gated abilities (wpmcp_enable_db_writes & co.) keep
        // their own opt-in filters as the master gate and are deliberately
        // absent here. The one entry that IS shipped is gateway-provision
        // (issue #142): it mints a credential that is not scope-enforced,
        // so an upgrading site must opt in rather than inherit it enabled.
        // gateway-revoke is NOT in the map on purpose -- revocation must
        // stay reachable without flipping a governance toggle first.
        Default_Seeder::seed();

        $this->assertSame(['wpmcp/gateway-provision' => false], Governance::ability_toggles());
        $this->assertGreaterThanOrEqual(2, Default_Seeder::applied_version());
    }
}
