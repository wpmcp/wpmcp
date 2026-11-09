<?php

namespace WPMCP\Tests\Free\Governance;

use WPMCP\Governance\Governance;
use WPMCP\Governance\Site_Wide_Governance;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Governance\Update_Governance_Settings;
use WPMCP\Tools\Rollback_Operation;

class UpdateGovernanceSettingsTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Governance::reset_for_tests();
    }

    protected function tearDown(): void
    {
        Governance::reset_for_tests();
        parent::tearDown();
    }

    public function test_applies_entries_across_all_three_dimensions(): void
    {
        $out = (new Update_Governance_Settings())->handle([
            'ability'   => ['wpmcp/delete-post' => false],
            'domain'    => ['database' => false],
            'operation' => ['delete' => false],
        ]);

        $this->assertSame(['wpmcp/delete-post' => false], $out['updated']['ability']);
        $this->assertSame(['database' => false], $out['updated']['domain']);
        $this->assertSame(['delete' => false], $out['updated']['operation']);
        $this->assertSame([], $out['skipped']);

        $this->assertFalse(Governance::ability_toggles()['wpmcp/delete-post']);
        $this->assertFalse(Governance::domain_toggles()['database']);
        $this->assertFalse(Governance::operation_toggles()['delete']);
    }

    public function test_throws_when_entirely_empty_input_is_given(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Update_Governance_Settings())->handle([]);
    }

    public function test_skips_non_boolean_entries_without_throwing(): void
    {
        $out = (new Update_Governance_Settings())->handle([
            'ability' => ['wpmcp/delete-post' => 'not-a-bool'],
        ]);

        $this->assertSame([], $out['updated']['ability']);
        $this->assertCount(1, $out['skipped']);
        $this->assertSame('wpmcp/delete-post', $out['skipped'][0]['key']);
    }

    public function test_skips_entries_for_an_unknown_dimension(): void
    {
        $out = (new Update_Governance_Settings())->handle([
            'unknown_dimension' => ['x' => false],
        ]);

        $this->assertSame([], $out['updated']['ability'] ?? []);
        $this->assertNotEmpty($out['skipped']);
    }

    /** Issue #773: a governance write is an undo point like any other write. */
    public function test_a_write_returns_an_operation_id_whose_undo_restores_the_previous_toggles(): void
    {
        Governance::set_ability_toggle('wpmcp/delete-post', true);
        Site_Wide_Governance::set_mode('audit');

        $out = (new Update_Governance_Settings())->handle([
            'ability'   => ['wpmcp/delete-post' => false, 'wpmcp/list-posts' => false],
            'domain'    => ['database' => false],
            'site_wide' => 'enforce',
        ]);

        $this->assertIsString($out['operation_id']);
        $this->assertNotNull(Snapshot_Store::get_by_operation($out['operation_id']));
        $this->assertFalse(Governance::ability_toggles()['wpmcp/delete-post']);
        $this->assertFalse(Governance::ability_toggles()['wpmcp/list-posts']);
        $this->assertFalse(Governance::domain_toggles()['database']);
        $this->assertSame('enforce', Site_Wide_Governance::mode());

        $rolled_back = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled_back['restored']);
        wp_cache_delete(Governance::OPTION, 'options');
        wp_cache_delete(Site_Wide_Governance::OPTION, 'options');

        $this->assertSame(['wpmcp/delete-post' => true], Governance::ability_toggles());
        $this->assertSame([], Governance::domain_toggles());
        $this->assertSame('audit', Site_Wide_Governance::mode());
    }

    /** Issue #773: a first write on a site with no stored toggles undoes back to "no stored toggles". */
    public function test_undoing_the_first_write_removes_the_stored_toggles_again(): void
    {
        $out = (new Update_Governance_Settings())->handle([
            'operation' => ['delete' => false],
        ]);

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        wp_cache_delete(Governance::OPTION, 'options');

        $this->assertSame([], Governance::operation_toggles());
        $this->assertFalse(get_option(Governance::OPTION));
    }

    public function test_a_call_that_changes_nothing_takes_no_undo_point(): void
    {
        Governance::set_ability_toggle('wpmcp/delete-post', false);

        $out = (new Update_Governance_Settings())->handle([
            'ability' => ['wpmcp/delete-post' => false],
        ]);

        $this->assertSame(['wpmcp/delete-post' => false], $out['updated']['ability']);
        $this->assertNull($out['operation_id']);
    }

    /**
     * Issue #773: the tool may not switch off itself, the read of the stored
     * toggles, or the undo tool, by any dimension. Once any of them is off no
     * MCP client can switch it back on; that takes wp-admin.
     *
     * @dataProvider lockout_payloads
     */
    public function test_refuses_to_disable_the_governance_and_undo_tools(array $payload, string $named): void
    {
        $result = (new Update_Governance_Settings())->handle($payload);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wpmcp_governance_lockout', $result->get_error_code());
        $this->assertStringContainsString($named, $result->get_error_message());
        $this->assertStringContainsString('Nothing was written', $result->get_error_message());

        // The valid entry sent alongside is not written either.
        $this->assertSame([], Governance::ability_toggles());
        $this->assertSame([], Governance::domain_toggles());
        $this->assertSame([], Governance::operation_toggles());
    }

    public static function lockout_payloads(): array
    {
        return [
            'itself by ability'            => [ ['ability' => ['wpmcp/delete-post' => false, 'wpmcp/update-governance-settings' => false]], 'wpmcp/update-governance-settings' ],
            'the read tool by ability'     => [ ['ability' => ['wpmcp/get-governance-settings' => false]], 'wpmcp/get-governance-settings' ],
            'the undo tool by ability'     => [ ['ability' => ['wpmcp/rollback-operation' => false]], 'wpmcp/rollback-operation' ],
            'its domain'                   => [ ['domain' => ['governance' => false]], 'wpmcp/update-governance-settings' ],
            'the undo tool domain'         => [ ['domain' => ['core' => false]], 'wpmcp/rollback-operation' ],
            'its operation'                => [ ['operation' => ['update' => false]], 'wpmcp/update-governance-settings' ],
            'the read operation'           => [ ['operation' => ['read' => false]], 'wpmcp/get-governance-settings' ],
        ];
    }

    public function test_re_enabling_the_governance_tools_is_allowed(): void
    {
        $out = (new Update_Governance_Settings())->handle([
            'ability' => ['wpmcp/update-governance-settings' => true, 'wpmcp/rollback-operation' => true],
            'domain'  => ['governance' => true],
        ]);

        $this->assertIsArray($out);
        $this->assertTrue(Governance::ability_toggles()['wpmcp/update-governance-settings']);
        $this->assertTrue(Governance::domain_toggles()['governance']);
    }

    /**
     * Issue #774: a name no registered ability carries is a mistake to
     * report, not a toggle to store, and the call as a whole is refused so a
     * typo cannot be read as "it worked".
     *
     * @dataProvider unknown_name_payloads
     */
    public function test_an_unknown_name_is_refused_naming_it_and_nothing_is_written(array $payload, string $named): void
    {
        $result = (new Update_Governance_Settings())->handle($payload);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wpmcp_governance_unknown_name', $result->get_error_code());
        $this->assertStringContainsString($named, $result->get_error_message());
        $this->assertStringContainsString('Nothing was written', $result->get_error_message());

        $this->assertSame([], Governance::ability_toggles());
        $this->assertSame([], Governance::domain_toggles());
        $this->assertSame([], Governance::operation_toggles());
        $this->assertFalse(get_option(Governance::OPTION));
    }

    public static function unknown_name_payloads(): array
    {
        return [
            'a typo in an ability name, beside a valid one' => [ ['ability' => ['wpmcp/delete-posts' => false, 'wpmcp/delete-post' => false]], '"wpmcp/delete-posts"' ],
            'an ability from nowhere'                      => [ ['ability' => ['core/nope' => false]], '"core/nope"' ],
            'an unknown domain'                             => [ ['domain' => ['databse' => false]], '"databse"' ],
            'an unknown operation'                          => [ ['operation' => ['destroy' => false]], '"destroy"' ],
        ];
    }

    public function test_the_bridge_domain_is_a_known_name(): void
    {
        $out = (new Update_Governance_Settings())->handle(['domain' => ['bridge' => false]]);

        $this->assertIsArray($out);
        $this->assertFalse(Governance::domain_toggles()['bridge']);
    }

    public function test_a_governance_disabled_ability_is_still_a_known_name(): void
    {
        // The ability is declared but not registered: re-enabling it by name must work.
        Governance::set_ability_toggle('wpmcp/delete-post', false);

        $out = (new Update_Governance_Settings())->handle([
            'ability' => ['wpmcp/delete-post' => true],
        ]);

        $this->assertIsArray($out);
        $this->assertTrue(Governance::ability_toggles()['wpmcp/delete-post']);
    }
}
