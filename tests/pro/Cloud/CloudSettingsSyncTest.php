<?php

namespace WPMCP\Tests\Pro\Cloud;

use WPMCP\Cloud\Settings_Sync;
use WPMCP\Connect\Exposure;
use WPMCP\Governance\Governance;
use WPMCP\Identity\Identity_Store;
use WPMCP\MCP\Tool_Exposure;
use WPMCP\Pro\Gate;
use WPMCP\Skills\Skills_Module;
use WPMCP\Tools\Cloud\Cloud_Apply_Settings;
use WPMCP\Tools\Cloud\Cloud_Push_Settings;
use WPMCP\Tools\Cloud\Cloud_Sync_Settings;
use WPMCP\Tools\Meta\Get_Option;
use WPMCP\Tools\Meta\Option_Guard;

/**
 * Cloud phase B (issue #135): settings sync over the governance-option
 * allowlist, its cloud transport, and the option guard on the cloud API key.
 *
 * HTTP is faked through FakesCloudHttp, so no live network is involved.
 */
class CloudSettingsSyncTest extends \WP_UnitTestCase
{
    use FakesCloudHttp;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->start_fake_cloud();
    }

    protected function tearDown(): void
    {
        $this->stop_fake_cloud();
        delete_option(Governance::OPTION);
        delete_option(Tool_Exposure::OPTION);
        delete_option(Skills_Module::OPTION);
        delete_option(Exposure::OPTION);
        delete_option(Identity_Store::OPTION);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    // ---- Settings_Sync ------------------------------------------------------

    public function test_allowlist_holds_only_real_option_names(): void
    {
        $expected = [
            Governance::OPTION,
            Tool_Exposure::OPTION,
            Exposure::OPTION,
            Skills_Module::OPTION,
            Identity_Store::OPTION,
        ];
        sort($expected);
        $actual = Settings_Sync::allowlist();
        sort($actual);
        $this->assertSame($expected, $actual);
    }

    public function test_export_omits_unset_options(): void
    {
        delete_option(Governance::OPTION);
        delete_option(Tool_Exposure::OPTION);
        delete_option(Skills_Module::OPTION);
        delete_option(Exposure::OPTION);
        delete_option(Identity_Store::OPTION);

        $this->assertSame([], Settings_Sync::export());
    }

    public function test_export_returns_the_stored_governance_posture(): void
    {
        Governance::set_domain_toggle('database', false);
        update_option(Tool_Exposure::OPTION, 'compact');

        $payload = Settings_Sync::export();

        $this->assertSame('compact', $payload[ Tool_Exposure::OPTION ]);
        $this->assertFalse($payload[ Governance::OPTION ]['domain']['database']);
        $this->assertArrayNotHasKey(Skills_Module::OPTION, $payload);
    }

    public function test_apply_writes_allowlisted_options_and_drops_everything_else(): void
    {
        $out = Settings_Sync::apply([
            Tool_Exposure::OPTION => 'compact',
            'active_plugins'      => ['evil/evil.php'],
            'wpmcp_cloud_key'     => 'stolen',
            'wpmcp_allow_php_exec' => true,
        ]);

        $this->assertSame([Tool_Exposure::OPTION], $out['applied']);
        $this->assertSame('compact', get_option(Tool_Exposure::OPTION));
        $this->assertFalse(get_option('wpmcp_allow_php_exec', false));
        $this->assertNotSame('stolen', get_option('wpmcp_cloud_key'));
        $reasons = array_column($out['skipped'], 'reason', 'key');
        $this->assertSame('not allowlisted', $reasons['active_plugins']);
    }

    public function test_apply_rejects_a_value_of_the_wrong_shape(): void
    {
        update_option(Tool_Exposure::OPTION, 'full');

        $out = Settings_Sync::apply([
            Tool_Exposure::OPTION => ['not', 'a', 'mode'],
            Governance::OPTION    => 'not-a-toggle-map',
        ]);

        $this->assertSame([], $out['applied']);
        $this->assertSame('full', get_option(Tool_Exposure::OPTION));
        $this->assertCount(2, $out['skipped']);
    }

    public function test_apply_coerces_the_governance_toggle_map(): void
    {
        $out = Settings_Sync::apply([
            Governance::OPTION => [
                'domain'    => ['database' => false, 'media' => 1],
                'operation' => ['delete' => false],
                'junk'      => ['x' => true],
            ],
        ]);

        $this->assertSame([Governance::OPTION], $out['applied']);
        $stored = get_option(Governance::OPTION);
        $this->assertSame(['ability', 'domain', 'operation'], array_keys($stored));
        $this->assertFalse($stored['domain']['database']);
        $this->assertTrue($stored['domain']['media']);
        $this->assertArrayNotHasKey('junk', $stored);
    }

    public function test_apply_is_rollback_able_through_safe_mutation(): void
    {
        update_option(Tool_Exposure::OPTION, 'full');

        $out = Settings_Sync::apply([Tool_Exposure::OPTION => 'compact']);

        $this->assertCount(1, $out['operation_ids']);
        $this->assertSame('compact', get_option(Tool_Exposure::OPTION));

        $rolled = (new \WPMCP\Tools\Rollback_Operation())->handle([
            'operation_id' => $out['operation_ids'][0],
        ]);
        $this->assertNotInstanceOf(\WP_Error::class, $rolled);
        $this->assertSame('full', get_option(Tool_Exposure::OPTION));
    }

    public function test_apply_requires_the_paid_cloud_entitlement(): void
    {
        Gate::set_pro_for_tests(false);
        update_option(Tool_Exposure::OPTION, 'full');

        $out = Settings_Sync::apply([Tool_Exposure::OPTION => 'compact']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('cloud_settings_sync_pro_only', $out->get_error_code());
        $this->assertSame('full', get_option(Tool_Exposure::OPTION));
    }

    public function test_apply_requires_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        update_option(Tool_Exposure::OPTION, 'full');

        $out = Settings_Sync::apply([Tool_Exposure::OPTION => 'compact']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('cloud_settings_sync_forbidden', $out->get_error_code());
        $this->assertSame('full', get_option(Tool_Exposure::OPTION));
    }

    // ---- cloud-sync-settings tool ------------------------------------------

    public function test_preview_tool_reports_the_real_posture(): void
    {
        update_option(Tool_Exposure::OPTION, 'compact');

        $out = (new Cloud_Sync_Settings())->handle([]);

        $this->assertSame(1, $out['count']);
        $this->assertSame('compact', $out['payload'][ Tool_Exposure::OPTION ]);
        $this->assertContains(Governance::OPTION, $out['allowlist']);
    }
    // ---- Review fixes: settings sync semantics -----------------------------

    /**
     * A payload carrying one dimension must not wipe the others. Replacing
     * would re-enable abilities the target's operator turned off, which is the
     * one direction the narrowing governance model forbids.
     */
    public function test_apply_merges_governance_dimensions_instead_of_replacing_them(): void
    {
        update_option(Governance::OPTION, [
            'ability'   => ['wpmcp/delete-post' => false],
            'domain'    => ['database' => false],
            'operation' => ['delete' => false],
        ]);

        $out = Settings_Sync::apply([
            Governance::OPTION => ['domain' => ['media' => false]],
        ]);

        $this->assertNotInstanceOf(\WP_Error::class, $out);
        $stored = get_option(Governance::OPTION);
        $this->assertFalse($stored['ability']['wpmcp/delete-post'], 'ability disables survive');
        $this->assertFalse($stored['operation']['delete'], 'operation disables survive');
        $this->assertFalse($stored['domain']['database'], 'untouched domain survives');
        $this->assertFalse($stored['domain']['media'], 'the payload still applies');
    }

    /**
     * Off would deny every ability over MCP, rollback-operation included, so
     * the write could not be undone the way every other synced write can.
     */
    public function test_apply_never_switches_mcp_exposure_off(): void
    {
        update_option(Exposure::OPTION, '1');

        $out = Settings_Sync::apply([Exposure::OPTION => '0']);

        $this->assertSame([], $out['applied']);
        $this->assertSame(Exposure::OPTION, $out['skipped'][0]['key']);
        $this->assertStringContainsString('rollback-operation', $out['skipped'][0]['reason']);
        $this->assertSame('1', get_option(Exposure::OPTION));
    }

    public function test_apply_reports_a_matching_mcp_exposure_as_unchanged(): void
    {
        $out = Settings_Sync::apply([Exposure::OPTION => '1']);

        $this->assertSame([], $out['applied']);
        $this->assertSame([Exposure::OPTION], $out['unchanged']);
        $this->assertFalse(get_option(Exposure::OPTION, false), 'nothing is written for a no-op');
    }

    public function test_apply_may_not_switch_mcp_exposure_back_on(): void
    {
        update_option(Exposure::OPTION, '0');

        $out = Settings_Sync::apply([Exposure::OPTION => '1']);

        $this->assertSame([], $out['applied']);
        $this->assertSame(Exposure::OPTION, $out['skipped'][0]['key']);
        $this->assertSame('0', get_option(Exposure::OPTION), 'the kill switch stays off');
    }

    /**
     * The snapshot has to say who wrote and what was written, or History
     * attributes an option write to cloud-sync-settings, a read-only tool.
     */
    public function test_apply_attributes_the_write_to_the_applier_and_the_session(): void
    {
        update_option(Tool_Exposure::OPTION, 'full');

        $out = Settings_Sync::apply([Tool_Exposure::OPTION => 'compact'], 'session-42');

        $row = \WPMCP\Safety\Snapshot_Store::get_by_operation($out['operation_ids'][0]);
        $this->assertSame('cloud-apply-settings', $row['tool_name']);
        $this->assertSame('session-42', $row['session_id']);
        $this->assertSame(
            hash('sha256', wp_json_encode([Tool_Exposure::OPTION => 'compact'])),
            $row['args_hash'],
            'the recorded args must describe what was actually stored'
        );
    }

    public function test_checkbox_flag_matches_the_owning_module_normalizer(): void
    {
        update_option(Skills_Module::OPTION, '1');

        Settings_Sync::apply([Skills_Module::OPTION => 'false']);

        $this->assertSame(Skills_Module::sanitize('false'), get_option(Skills_Module::OPTION));
        $this->assertFalse(Skills_Module::is_enabled());
    }

    public function test_the_apply_ability_drives_settings_sync(): void
    {
        update_option(Tool_Exposure::OPTION, 'full');

        $out = (new Cloud_Apply_Settings())->handle([
            'settings'   => [Tool_Exposure::OPTION => 'compact'],
            'session_id' => 'agent-session',
        ]);

        $this->assertNotInstanceOf(\WP_Error::class, $out);
        $this->assertSame([Tool_Exposure::OPTION], $out['applied']);
        $this->assertSame('compact', get_option(Tool_Exposure::OPTION));
    }

    public function test_the_apply_ability_requires_a_settings_map(): void
    {
        $out = (new Cloud_Apply_Settings())->handle(['settings' => []]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('missing_settings', $out->get_error_code());
    }

    // ---- Option guard: the phase A API key ---------------------------------

    public function test_option_guard_refuses_the_cloud_api_key(): void
    {
        $this->assertTrue(Option_Guard::is_denylisted('wpmcp_cloud_key'));
    }

    public function test_get_option_refuses_to_read_the_cloud_api_key(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Get_Option())->handle(['name' => 'wpmcp_cloud_key']);
    }

    // ---- Identities minus secrets ------------------------------------------

    private static function identity(string $name, array $extra = []): array
    {
        return array_merge([
            'name'       => $name,
            'domains'    => ['content'],
            'operations' => ['read'],
            'abilities'  => [],
            'mode'       => 'allow',
            'exposure'   => '',
        ], $extra);
    }

    public function test_export_projects_identities_onto_their_non_secret_fields(): void
    {
        update_option(Identity_Store::OPTION, [
            'editor-bot' => self::identity('editor-bot', ['app_password' => 'hunter2', 'token' => 'abc']),
        ]);

        $payload = Settings_Sync::export();

        $this->assertSame(
            ['name', 'domains', 'operations', 'abilities', 'mode', 'exposure', 'allowed_ips'],
            array_keys($payload[ Identity_Store::OPTION ]['editor-bot'])
        );
        $this->assertStringNotContainsString('hunter2', wp_json_encode($payload));
    }

    public function test_apply_merges_identities_by_name_and_strips_unknown_fields(): void
    {
        update_option(Identity_Store::OPTION, [
            'local-only' => self::identity('local-only'),
            'shared'     => self::identity('shared', ['operations' => ['read', 'update']]),
        ]);

        $out = Settings_Sync::apply([
            Identity_Store::OPTION => [
                'shared'   => self::identity('shared', ['secret' => 'x']),
                'incoming' => self::identity('ignored-name', ['mode' => 'deny', 'exposure' => 'compact', 'password' => 'p']),
            ],
        ]);

        $this->assertSame([Identity_Store::OPTION], $out['applied']);
        $stored = get_option(Identity_Store::OPTION);
        $this->assertSame(['local-only', 'shared', 'incoming'], array_keys($stored), 'local identities survive');
        $this->assertSame(['read'], $stored['shared']['operations'], 'a synced name wins for that name');
        $this->assertArrayNotHasKey('secret', $stored['shared']);
        $this->assertArrayNotHasKey('password', $stored['incoming']);
        $this->assertSame('incoming', $stored['incoming']['name'], 'the record name follows its key');
        $this->assertSame('deny', $stored['incoming']['mode']);
        $this->assertSame('compact', $stored['incoming']['exposure']);
        $this->assertSame(Identity_Store::get('incoming'), $stored['incoming']);
    }

    public function test_apply_normalizes_identity_enums_and_scope_lists(): void
    {
        $out = Settings_Sync::apply([
            Identity_Store::OPTION => [
                'bot' => ['domains' => ['content', 7], 'mode' => 'root', 'exposure' => 'everything'],
            ],
        ]);

        $this->assertSame([Identity_Store::OPTION], $out['applied']);
        $bot = get_option(Identity_Store::OPTION)['bot'];
        $this->assertSame('allow', $bot['mode']);
        $this->assertSame('', $bot['exposure']);
        $this->assertSame(['content', '7'], $bot['domains']);
        $this->assertSame([], $bot['operations']);
    }

    public function test_apply_refuses_a_malformed_identity_map(): void
    {
        update_option(Identity_Store::OPTION, ['keep' => self::identity('keep')]);

        $out = Settings_Sync::apply([
            Identity_Store::OPTION => ['bad' => 'not-a-record'],
        ]);

        $this->assertSame([], $out['applied']);
        $this->assertSame(Identity_Store::OPTION, $out['skipped'][0]['key']);
        $this->assertSame(['keep'], array_keys(get_option(Identity_Store::OPTION)));
    }

    public function test_identity_apply_is_rollback_able(): void
    {
        update_option(Identity_Store::OPTION, ['keep' => self::identity('keep')]);

        $out = Settings_Sync::apply([Identity_Store::OPTION => ['new' => self::identity('new')]]);
        (new \WPMCP\Tools\Rollback_Operation())->handle(['operation_id' => $out['operation_ids'][0]]);

        $this->assertSame(['keep'], array_keys(get_option(Identity_Store::OPTION)));
    }

    // ---- Cloud transport: push and pull ------------------------------------

    public function test_push_sends_the_allowlisted_export_to_the_cloud(): void
    {
        update_option(Tool_Exposure::OPTION, 'compact');
        update_option('some_other_option', 'nope');
        $this->responder = static fn () => self::cloud_json(['updated_at' => '2026-09-28T00:00:00Z']);

        $out = (new Cloud_Push_Settings())->handle([]);

        $this->assertNotInstanceOf(\WP_Error::class, $out);
        $this->assertSame([Tool_Exposure::OPTION], $out['pushed']);
        $this->assertCount(1, $this->requests);
        $this->assertSame('POST', $this->requests[0]['method']);
        $this->assertStringEndsWith('/wpmcp-cloud/v1/settings', $this->requests[0]['url']);
        $this->assertSame([Tool_Exposure::OPTION => 'compact'], $this->requests[0]['body']['settings']);
        $this->assertSame('Bearer secret-key', $this->requests[0]['headers']['Authorization']);
        delete_option('some_other_option');
    }

    public function test_push_requires_the_paid_cloud_entitlement(): void
    {
        Gate::set_pro_for_tests(false);

        $out = (new Cloud_Push_Settings())->handle([]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('cloud_settings_sync_pro_only', $out->get_error_code());
        $this->assertSame([], $this->requests, 'nothing leaves the site');
    }

    public function test_push_requires_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $out = (new Cloud_Push_Settings())->handle([]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('cloud_settings_sync_forbidden', $out->get_error_code());
        $this->assertSame([], $this->requests);
    }

    public function test_push_refuses_an_empty_posture(): void
    {
        $out = (new Cloud_Push_Settings())->handle([]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('cloud_settings_empty', $out->get_error_code());
        $this->assertSame([], $this->requests);
    }

    public function test_apply_without_a_payload_pulls_the_posture_from_the_cloud(): void
    {
        update_option(Tool_Exposure::OPTION, 'full');
        $this->responder = static fn () => self::cloud_json([
            'settings' => [
                Tool_Exposure::OPTION => 'compact',
                'active_plugins'      => ['evil/evil.php'],
            ],
        ]);

        $out = (new Cloud_Apply_Settings())->handle(['session_id' => 's1']);

        $this->assertNotInstanceOf(\WP_Error::class, $out);
        $this->assertSame('cloud', $out['source']);
        $this->assertSame('GET', $this->requests[0]['method']);
        $this->assertStringEndsWith('/wpmcp-cloud/v1/settings', $this->requests[0]['url']);
        $this->assertSame([Tool_Exposure::OPTION], $out['applied']);
        $this->assertSame('compact', get_option(Tool_Exposure::OPTION));
        $this->assertNotContains('evil/evil.php', (array) get_option('active_plugins', []));
    }

    public function test_apply_with_a_null_settings_argument_pulls(): void
    {
        $this->responder = static fn () => self::cloud_json(['settings' => [Tool_Exposure::OPTION => 'compact']]);

        $out = (new Cloud_Apply_Settings())->handle(['settings' => null]);

        $this->assertNotInstanceOf(\WP_Error::class, $out);
        $this->assertSame('cloud', $out['source']);
        $this->assertSame('compact', get_option(Tool_Exposure::OPTION));
    }

    public function test_pull_reports_a_cloud_with_no_stored_posture(): void
    {
        $this->responder = static fn () => self::cloud_json(['settings' => []]);

        $out = (new Cloud_Apply_Settings())->handle([]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('cloud_settings_empty', $out->get_error_code());
    }

    public function test_pull_is_gated_before_any_request(): void
    {
        Gate::set_pro_for_tests(false);

        $out = (new Cloud_Apply_Settings())->handle([]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('cloud_settings_sync_pro_only', $out->get_error_code());
        $this->assertSame([], $this->requests);
    }

    public function test_pull_surfaces_a_cloud_error(): void
    {
        $this->responder = static fn () => self::cloud_json(['message' => 'down'], 503);

        $out = (new Cloud_Apply_Settings())->handle([]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('cloud_error', $out->get_error_code());
    }

    // ---- Coordinator review fixes -------------------------------------------

    public function test_apply_never_disables_rollback_operation_through_governance(): void
    {
        $out = Settings_Sync::apply([
            Governance::OPTION => [
                'ability'   => ['wpmcp/rollback-operation' => false, 'wpmcp/delete-post' => false],
                'domain'    => ['core' => false, 'media' => false],
                'operation' => ['update' => false, 'delete' => false],
            ],
        ]);

        $this->assertSame([Governance::OPTION], $out['applied']);
        $stored = get_option(Governance::OPTION);
        $this->assertArrayNotHasKey('wpmcp/rollback-operation', $stored['ability']);
        $this->assertArrayNotHasKey('core', $stored['domain']);
        $this->assertArrayNotHasKey('update', $stored['operation']);
        $this->assertFalse($stored['ability']['wpmcp/delete-post']);
        $this->assertFalse($stored['domain']['media']);
        $this->assertFalse($stored['operation']['delete']);
        $this->assertSame(
            [
                Governance::OPTION . '.ability.wpmcp/rollback-operation',
                Governance::OPTION . '.domain.core',
                Governance::OPTION . '.operation.update',
            ],
            array_column($out['skipped'], 'key')
        );
    }

    public function test_applied_and_operation_ids_are_parallel_and_no_ops_are_unchanged(): void
    {
        update_option(Tool_Exposure::OPTION, 'compact');
        update_option(Skills_Module::OPTION, '1');

        $out = Settings_Sync::apply([
            Tool_Exposure::OPTION => 'compact',
            Skills_Module::OPTION => 'false',
        ]);

        $this->assertSame([Skills_Module::OPTION], $out['applied']);
        $this->assertCount(1, $out['operation_ids']);
        $this->assertSame([Tool_Exposure::OPTION], $out['unchanged']);
        (new \WPMCP\Tools\Rollback_Operation())->handle(['operation_id' => $out['operation_ids'][0]]);
        $this->assertSame('1', get_option(Skills_Module::OPTION), 'operation_ids[0] is the snapshot of applied[0]');
    }

    public function test_applying_a_sites_own_export_writes_nothing(): void
    {
        update_option(Governance::OPTION, [
            'operation' => ['delete' => false],
            'domain'    => ['media' => false, 'database' => false],
            'ability'   => ['wpmcp/delete-post' => false],
        ]);
        update_option(Identity_Store::OPTION, ['bot' => Identity_Store::normalize('bot', ['domains' => ['content']])]);
        update_option(Tool_Exposure::OPTION, 'full');

        $out = Settings_Sync::apply(Settings_Sync::export());

        $this->assertSame([], $out['applied'], 'key order alone must not count as a change');
        $this->assertSame([], $out['operation_ids']);
        $this->assertEqualsCanonicalizing(
            [Governance::OPTION, Identity_Store::OPTION, Tool_Exposure::OPTION],
            $out['unchanged']
        );
    }

    /**
     * create-identity name=2024 stores an int key, and json_decode() turns a
     * "42" key into an int too. Neither may be dropped or refused.
     */
    public function test_digits_only_identity_names_survive_export_and_apply(): void
    {
        Identity_Store::create('2024', ['domains' => ['content']]);

        $this->assertArrayHasKey('2024', Settings_Sync::export()[ Identity_Store::OPTION ]);

        $incoming = json_decode('{"42":{"domains":["media"]}}', true);
        $out      = Settings_Sync::apply([Identity_Store::OPTION => $incoming]);

        $this->assertSame([Identity_Store::OPTION], $out['applied']);
        $this->assertSame(['content'], Identity_Store::get('2024')['domains'], 'the local digits-only identity survives');
        $this->assertSame('42', Identity_Store::get('42')['name']);
        $this->assertSame(['media'], Identity_Store::get('42')['domains']);
        $this->assertCount(2, get_option(Identity_Store::OPTION));
    }

    public function test_synced_identities_match_what_create_identity_stores(): void
    {
        $fields = ['domains' => ['content', 3], 'mode' => 'deny', 'exposure' => 'compact', 'extra' => 'x'];

        Settings_Sync::apply([Identity_Store::OPTION => ['synced' => $fields]]);
        $created = Identity_Store::create('created', $fields);

        $synced = Identity_Store::get('synced');
        $synced['name'] = 'created';
        $this->assertSame($created, $synced);
    }
}
