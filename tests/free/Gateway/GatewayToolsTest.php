<?php

namespace WPMCP\Tests\Free\Gateway;

use WPMCP\Auth\Client_Store;
use WPMCP\Auth\Refresh_Token_Store;
use WPMCP\Auth\Token_Store;
use WPMCP\Gateway\Gateway_Credential;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Gateway\Gateway_Provision;
use WPMCP\Tools\Gateway\Gateway_Revoke;
use WPMCP\Tools\Gateway\Gateway_Status;

/**
 * The three gateway MCP tools (issue #142): the confirm gates on both
 * mutating tools, the once-only credential payload, the read-only status
 * tool's refusal to leak token material, and revoke reporting the state it
 * actually converged to rather than the one it hoped for.
 */
class GatewayToolsTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        remove_all_filters('wpmcp_oauth_enabled');
        // Provisioning refuses outright when the OAuth subsystem is off,
        // which is the default, so every test that expects a credential
        // opts in first. The one that asserts the refusal removes it.
        add_filter('wpmcp_oauth_enabled', '__return_true');
        $this->reset();
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_oauth_enabled');
        wp_set_current_user(0);
        $this->reset();
        parent::tearDown();
    }

    private function reset(): void
    {
        foreach ([Client_Store::OPTION, Token_Store::OPTION, Refresh_Token_Store::OPTION, Gateway_Credential::OPTION] as $option) {
            delete_option($option);
        }
    }

    private function as_admin(): int
    {
        $user_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($user_id);

        return $user_id;
    }

    public function test_provision_requires_confirm(): void
    {
        $this->as_admin();

        // InvalidArgumentException, matching every other confirm gate in
        // the repo (Delete_Post, Delete_Plugin, Delete_File): one refusal
        // shape, one Request_Log outcome.
        try {
            (new Gateway_Provision())->handle([]);
            $this->fail('the confirm gate should have refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('confirm:true', $e->getMessage());
        }

        $this->assertSame(0, Client_Store::count(), 'a refused call provisions nothing');
    }

    public function test_provision_refuses_when_oauth_is_disabled(): void
    {
        // The credential would be structurally unredeemable: with OAuth off
        // there is no token endpoint and Bearer_Auth accepts nothing.
        remove_all_filters('wpmcp_oauth_enabled');
        $this->as_admin();

        $result = (new Gateway_Provision())->handle(['confirm' => true]);

        $this->assertWPError($result);
        $this->assertSame('oauth_disabled', $result->get_error_code());
        $this->assertSame(0, Client_Store::count(), 'nothing is provisioned by a refused call');
        $this->assertFalse(Gateway_Credential::is_provisioned());
    }

    public function test_status_reports_oauth_state_so_provisioned_is_not_read_alone(): void
    {
        $this->as_admin();
        (new Gateway_Provision())->handle(['confirm' => true]);

        $enabled = (new Gateway_Status())->handle([]);
        $this->assertTrue($enabled['oauth_enabled']);
        $this->assertTrue($enabled['usable']);

        remove_all_filters('wpmcp_oauth_enabled');
        $disabled = (new Gateway_Status())->handle([]);
        $this->assertTrue($disabled['provisioned'], 'turning OAuth off does not delete the rows');
        $this->assertFalse($disabled['oauth_enabled']);
        $this->assertFalse($disabled['usable']);
    }

    public function test_provision_warns_that_the_scope_is_not_enforced(): void
    {
        $this->as_admin();

        $result = (new Gateway_Provision())->handle(['confirm' => true]);

        $this->assertFalse($result['scope_enforced']);
        $this->assertStringContainsString('NOT enforced', $result['note']);
    }

    public function test_revoke_works_with_oauth_disabled(): void
    {
        // Revocation must never require the subsystem to be on: a site
        // owner reacting to a leak has to be able to kill the credential.
        $this->as_admin();
        (new Gateway_Provision())->handle(['confirm' => true]);
        remove_all_filters('wpmcp_oauth_enabled');

        $result = (new Gateway_Revoke())->handle(['confirm' => true]);

        $this->assertTrue($result['revoked']);
        $this->assertFalse($result['provisioned']);
    }

    public function test_provision_returns_the_credential_once(): void
    {
        $this->as_admin();

        $result = (new Gateway_Provision())->handle(['confirm' => true]);

        $this->assertIsArray($result);
        $this->assertTrue($result['provisioned']);
        $this->assertNotEmpty($result['client_id']);
        $this->assertNotEmpty($result['client_secret']);
        $this->assertNotEmpty($result['refresh_token']);
        $this->assertSame(Gateway_Credential::SCOPE, $result['scope']);
    }

    public function test_provision_without_a_user_is_refused(): void
    {
        wp_set_current_user(0);

        $result = (new Gateway_Provision())->handle(['confirm' => true]);

        $this->assertWPError($result);
        $this->assertSame('no_user', $result->get_error_code());
    }

    public function test_status_reports_unprovisioned_then_provisioned_without_token_material(): void
    {
        $this->as_admin();
        $status = new Gateway_Status();

        $before = $status->handle([]);
        $this->assertFalse($before['provisioned']);
        $this->assertNull($before['client_id']);

        $credential = (new Gateway_Provision())->handle(['confirm' => true]);

        $after = $status->handle([]);
        $this->assertTrue($after['provisioned']);
        $this->assertSame($credential['client_id'], $after['client_id']);
        $this->assertSame(['provisioned', 'client_id', 'oauth_enabled', 'usable'], array_keys($after));
        $this->assertStringNotContainsString($credential['refresh_token'], (string) wp_json_encode($after));
        $this->assertStringNotContainsString($credential['client_secret'], (string) wp_json_encode($after));
    }

    public function test_revoke_requires_confirm(): void
    {
        $this->as_admin();
        (new Gateway_Provision())->handle(['confirm' => true]);

        try {
            (new Gateway_Revoke())->handle([]);
            $this->fail('the confirm gate should have refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('confirm:true', $e->getMessage());
        }

        $this->assertTrue(Gateway_Credential::is_provisioned(), 'a refused revoke kills nothing');
    }

    public function test_revoke_kills_the_credential_and_is_idempotent(): void
    {
        $this->as_admin();
        (new Gateway_Provision())->handle(['confirm' => true]);
        $revoke = new Gateway_Revoke();

        $first = $revoke->handle(['confirm' => true]);
        $this->assertTrue($first['revoked']);
        $this->assertFalse($first['provisioned']);

        $second = $revoke->handle(['confirm' => true]);
        $this->assertFalse($second['revoked']);
        $this->assertFalse($second['provisioned']);
    }

    public function test_revoke_reports_the_state_it_actually_converged_to(): void
    {
        $this->as_admin();
        $credential = (new Gateway_Provision())->handle(['confirm' => true]);

        // A second row carrying the same gateway registration fingerprint,
        // which create()'s dedup permits once the first row holds tokens.
        $clients = get_option(Client_Store::OPTION);
        $twin    = $clients[ $credential['client_id'] ];
        $twin['client_id']  = 'client_twin';
        $clients['client_twin'] = $twin;
        update_option(Client_Store::OPTION, $clients);

        $result = (new Gateway_Revoke())->handle(['confirm' => true]);

        $this->assertTrue($result['revoked']);
        $this->assertFalse($result['provisioned'], 'revoke must not claim a state it did not reach');
        $this->assertSame(0, Client_Store::count());
    }

    public function test_provision_runs_through_safe_mutation_without_snapshotting_secrets(): void
    {
        // Hard rule: no mutating ability skips Safe_Mutation. The snapshot
        // is the gateway bookkeeping pointer only, so the undo point that
        // exists for the call can never carry or resurrect credential
        // material.
        $this->as_admin();
        $out = (new Gateway_Provision())->handle(['confirm' => true]);

        $this->assertIsString($out['operation_id'] ?? null);
        $row = Snapshot_Store::get_by_operation($out['operation_id']);
        $this->assertNotNull($row, 'the snapshot is persisted before the write');

        $serialized = (string) wp_json_encode($row);
        $this->assertStringNotContainsString($out['client_secret'], $serialized);
        $this->assertStringNotContainsString($out['refresh_token'], $serialized);
        $this->assertStringNotContainsString('client_secret_hash', $serialized);
    }

    public function test_revoke_runs_through_safe_mutation(): void
    {
        $this->as_admin();
        (new Gateway_Provision())->handle(['confirm' => true]);

        $out = (new Gateway_Revoke())->handle(['confirm' => true]);

        $this->assertIsString($out['operation_id'] ?? null);
        $this->assertNotNull(Snapshot_Store::get_by_operation($out['operation_id']));
    }

    public function test_rolling_back_a_revoke_does_not_resurrect_the_credential(): void
    {
        $this->as_admin();
        $credential = (new Gateway_Provision())->handle(['confirm' => true]);
        $out        = (new Gateway_Revoke())->handle(['confirm' => true]);

        $row = Snapshot_Store::get_by_operation($out['operation_id']);
        \WPMCP\Safety\Safe_Mutation::restore($row['snapshot']);

        $this->assertFalse(Gateway_Credential::is_provisioned(), 'restoring the pointer must not bring the client back');
        $this->assertFalse(Client_Store::verify_secret($credential['client_id'], $credential['client_secret']));
        $this->assertFalse(Refresh_Token_Store::has_tokens_for_client($credential['client_id']));
    }

    public function test_revoke_still_kills_the_credential_when_the_undo_point_cannot_be_saved(): void
    {
        // The kill switch must not depend on the snapshot table. Its undo
        // point is inert by design (pointer only), so refusing to revoke a
        // leaked credential because that row could not be written would
        // trade a real security outcome for a no-op restore.
        global $wpdb;
        $this->as_admin();
        $credential = (new Gateway_Provision())->handle(['confirm' => true]);

        $table  = Snapshot_Store::table_name();
        $filter = static function ($query) use ($table) {
            if (str_starts_with(strtoupper(ltrim((string) $query)), 'INSERT') && str_contains((string) $query, $table)) {
                return str_replace($table, $table . '_does_not_exist', (string) $query);
            }
            return $query;
        };
        add_filter('query', $filter);
        $suppress = $wpdb->suppress_errors(true);

        try {
            $out = (new Gateway_Revoke())->handle(['confirm' => true]);
        } finally {
            remove_filter('query', $filter);
            $wpdb->suppress_errors($suppress);
        }

        $this->assertTrue($out['revoked']);
        $this->assertNull($out['operation_id']);
        $this->assertFalse($out['undo_point']);
        $this->assertFalse(Gateway_Credential::is_provisioned());
        $this->assertFalse(Client_Store::verify_secret($credential['client_id'], $credential['client_secret']));
    }
}
