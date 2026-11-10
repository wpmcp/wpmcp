<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Integrations\Code_Snippets_Activation;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Code_Snippet_Snapshot;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Code\Php_Snippet_Guard;

require_once __DIR__ . '/../../support/code-snippets-double.php';

/**
 * The Code Snippets activation op (issue #576), paid-tier, clears the same
 * gates as activate-php-snippet (issue #85): PHP execution enabled, an
 * environment that permits it, the stored code re-checked, every attempt
 * audited. Its rollback switches the snippet off and puts the row back
 * exactly.
 */
class CodeSnippetsActivationTest extends \WP_UnitTestCase
{
    private const NOOP = "// wpmcp test snippet: does nothing.\n\$wpmcp_cs_noop = 1;";

    public static function wpSetUpBeforeClass(): void
    {
        \Code_Snippets\wpmcp_test_create_table();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_code_snippets_table();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (defined('CODE_SNIPPETS_VERSION')) {
            $this->markTestSkipped('The real Code Snippets is loaded; see CodeSnippetsLiveTest.');
        }
        Snapshot_Store::install();
        delete_option(Governance_Audit_Log::OPTION);
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_code_snippets_active', '__return_true');
        add_filter('wpmcp_allow_php_exec', '__return_true');
        Php_Snippet_Guard::set_environment_override('local');
        $GLOBALS['wpmcp_test_code_snippets_executed'] = 0;
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_code_snippets_active');
        remove_all_filters('wpmcp_allow_php_exec');
        remove_all_filters('code_snippets/activate_snippet');
        Php_Snippet_Guard::set_environment_override(null);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function activate(int $id): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => 'code-snippets-activate-snippet', 'args' => [ 'id' => $id ] ]);
    }

    private function seed(array $fields = []): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'snippets', $fields + [
            'name' => 'Seeded', 'description' => '', 'code' => self::NOOP, 'tags' => '',
            'scope' => 'global', 'priority' => 10, 'active' => 0, 'modified' => '2026-01-02 03:04:05', 'revision' => 1,
        ]);
        \Code_Snippets\clean_snippets_cache($wpdb->prefix . 'snippets');
        return (int) $wpdb->insert_id;
    }

    /** @return array<int, array<string, mixed>> */
    private function audit(): array
    {
        return array_values(array_filter(Governance_Audit_Log::list(200), static fn (array $r): bool => Code_Snippets_Activation::AUDIT_NAME === ($r['ability'] ?? '')));
    }

    public function test_activation_runs_through_code_snippets_is_audited_and_rollback_deactivates_restoring_the_row_exactly(): void
    {
        $id     = $this->seed();
        $before = Code_Snippet_Snapshot::row($id);
        $out    = (new Plugin_Data_Integration())->handle_write([ 'operation' => 'code-snippets-activate-snippet', 'args' => [ 'id' => $id ] ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('active', $out['result']['snippet']['status']);
        $this->assertSame('1', (string) Code_Snippet_Snapshot::row($id)['active']);
        $this->assertSame(0, $GLOBALS['wpmcp_test_code_snippets_executed'], 'activation runs no code itself');
        $this->assertTrue((bool) ($this->audit()[0]['allowed'] ?? false));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, Code_Snippet_Snapshot::row($id));
        $this->assertSame([], get_option('recently_active_snippets', []), 'the deactivation stamp the undo made is undone too');
    }

    public function test_activation_is_refused_while_php_execution_is_disabled_and_the_refusal_is_audited(): void
    {
        remove_all_filters('wpmcp_allow_php_exec');
        add_filter('wpmcp_allow_php_exec', '__return_false');
        $id  = $this->seed();
        $out = $this->activate($id);

        $this->assertSame('php_execution_disabled', $out['error']['code'] ?? null);
        $this->assertSame('0', (string) Code_Snippet_Snapshot::row($id)['active']);
        $this->assertSame([], Snapshot_Store::recent(5));
        $this->assertFalse((bool) $this->audit()[0]['allowed']);
    }

    public function test_activation_is_refused_on_production_without_the_override(): void
    {
        Php_Snippet_Guard::set_environment_override('production');
        $id = $this->seed();
        $this->assertSame('php_execution_disabled', $this->activate($id)['error']['code'] ?? null);
        $this->assertSame('0', (string) Code_Snippet_Snapshot::row($id)['active']);
    }

    public function test_activation_re_checks_the_stored_code_and_refuses_non_php_trashed_and_active_snippets(): void
    {
        $bad     = $this->seed([ 'code' => 'eval($_GET["x"]);' ]);
        $html    = $this->seed([ 'scope' => 'head-content', 'code' => '<script>1</script>' ]);
        $trashed = $this->seed([ 'active' => -1 ]);
        $active  = $this->seed([ 'active' => 1 ]);

        $this->assertSame('validation_failed', $this->activate($bad)['error']['code'] ?? null);
        $this->assertSame('unsupported_snippet_type', $this->activate($html)['error']['code'] ?? null);
        $this->assertSame('snippet_trashed', $this->activate($trashed)['error']['code'] ?? null);
        $this->assertSame('snippet_active', $this->activate($active)['error']['code'] ?? null);
        $this->assertSame([], Snapshot_Store::recent(5));
        $this->assertCount(4, $this->audit());
    }

    public function test_a_refusal_from_code_snippets_own_validator_voids_the_undo_point(): void
    {
        $id  = $this->seed([ 'code' => "\$x = 1; // WPMCP_CS_INVALID" ]);
        $out = $this->activate($id);
        $this->assertSame('activation_failed', $out['error']['code'] ?? null);
        $this->assertSame('0', (string) Code_Snippet_Snapshot::row($id)['active']);
        $this->assertSame([], Snapshot_Store::recent(5));
    }

    public function test_code_swapped_in_while_activating_is_switched_straight_off(): void
    {
        global $wpdb;
        $id = $this->seed();
        add_action('code_snippets/activate_snippet', static function () use ($wpdb, $id): void {
            $wpdb->update($wpdb->prefix . 'snippets', [ 'code' => '$swapped = 1;' ], [ 'id' => $id ]);
        });
        $out = $this->activate($id);
        $this->assertSame('code_moved', $out['error']['code'] ?? null);
        $this->assertSame('0', (string) Code_Snippet_Snapshot::row($id)['active']);
    }

    public function test_without_pro_the_activation_op_is_not_in_the_catalog(): void
    {
        Gate::set_pro_for_tests(false);
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], 'name');
        $this->assertNotContains('code-snippets-activate-snippet', $ops);
        $this->assertContains('code-snippets-deactivate-snippet', $ops);
    }
}
