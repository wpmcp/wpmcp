<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Code_Snippet_Snapshot;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Code\Php_Snippet_Guard;

/**
 * Issue #576: the Code Snippets ops run through the real Code Snippets, so
 * its own Snippet model, validator, caches and hooks run, and
 * rollback-operation puts its table row back exactly.
 *
 * It runs only in the local gate's live Code Snippets leg
 * (bin/test-local.sh, or bin/test-local.sh --live-code-snippets alone),
 * which sets WPMCP_LIVE_CODE_SNIPPETS=1, installs Code Snippets from
 * wordpress.org on a separate WordPress install, loads it in
 * tests/bootstrap.php and builds its table with its own DB class.
 *
 * The only code ever activated is a no-op that bumps a counter, so the test
 * can also prove that nothing here makes Code Snippets run a snippet: it
 * stays 0 through create, update, activate, deactivate and every rollback.
 * Every write is rolled back.
 *
 * Everywhere else Code Snippets is absent and the test is skipped; the live
 * leg runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group code-snippets-live
 */
class CodeSnippetsLiveTest extends \WP_UnitTestCase
{
    private const NOOP = "// wpmcp live test snippet: counts its runs and does nothing else.\n\$GLOBALS['wpmcp_cs_live_runs'] = (int) (\$GLOBALS['wpmcp_cs_live_runs'] ?? 0) + 1;";

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('CODE_SNIPPETS_VERSION') || ! function_exists('Code_Snippets\save_snippet')) {
            $this->markTestSkipped('Needs the real Code Snippets (bin/test-local.sh --live-code-snippets, WPMCP_LIVE_CODE_SNIPPETS=1).');
        }
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_allow_php_exec', '__return_true');
        Php_Snippet_Guard::set_environment_override('local');
        $GLOBALS['wpmcp_cs_live_runs'] = 0;
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_allow_php_exec');
        Php_Snippet_Guard::set_environment_override(null);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function call(string $half, string $op, array $args): array
    {
        $integration = new Plugin_Data_Integration();
        $out         = 'read' === $half
            ? $integration->handle_read([ 'operation' => $op, 'args' => $args ])
            : $integration->handle_write([ 'operation' => $op, 'args' => $args ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function create(string $name, string $code = self::NOOP): array
    {
        return $this->call('write', 'code-snippets-create-snippet', [ 'name' => $name, 'code' => $code, 'tags' => [ 'wpmcp-live' ], 'scope' => 'front-end' ]);
    }

    /** The snippet as Code Snippets itself reads it, over a cleared cache. */
    private function snippet(int $id): object
    {
        \Code_Snippets\clean_snippets_cache(Code_Snippet_Snapshot::table());
        return \Code_Snippets\get_snippet($id, false);
    }

    public function test_the_table_helper_names_code_snippets_own_table(): void
    {
        global $wpdb;
        $this->assertSame($wpdb->prefix . 'snippets', Code_Snippet_Snapshot::table());
        $this->assertTrue((new Plugin_Data_Integration())->is_available());
    }

    public function test_create_stores_an_inactive_snippet_code_snippets_reads_back_and_rollback_deletes_it(): void
    {
        $out = $this->create('wpmcp live create');
        $id  = (int) $out['result']['snippet']['id'];
        $s   = $this->snippet($id);

        $this->assertSame('wpmcp live create', $s->name);
        $this->assertSame(self::NOOP, $s->code);
        $this->assertSame('front-end', $s->scope);
        $this->assertSame([ 'wpmcp-live' ], $s->tags);
        $this->assertFalse((bool) $s->active);
        $this->assertSame('php', $s->type);

        $listed = $this->call('read', 'code-snippets-list-snippets', [ 'tag' => 'wpmcp-live' ])['result']['snippets'];
        $this->assertSame([ $id ], array_column($listed, 'id'));
        $got = $this->call('read', 'code-snippets-get-snippet', [ 'id' => $id ])['result'];
        $this->assertSame(self::NOOP, $got['snippet']['code']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertNull(Code_Snippet_Snapshot::row($id));
        $this->assertSame(0, (int) $this->snippet($id)->id);
        $this->assertSame(0, $GLOBALS['wpmcp_cs_live_runs']);
    }

    public function test_update_goes_through_save_snippet_and_rollback_restores_the_row_exactly(): void
    {
        $id     = (int) $this->create('wpmcp live update')['result']['snippet']['id'];
        $before = Code_Snippet_Snapshot::row($id);
        sleep(1); // Code Snippets stamps modified to the second.
        $out = $this->call('write', 'code-snippets-update-snippet', [ 'id' => $id, 'name' => 'wpmcp live renamed', 'priority' => 5, 'code' => self::NOOP . "\n// edited" ]);

        $s = $this->snippet($id);
        $this->assertSame('wpmcp live renamed', $s->name);
        $this->assertSame(5, $s->priority);
        $this->assertStringEndsWith('// edited', $s->code);
        $this->assertNotSame($before['modified'], Code_Snippet_Snapshot::row($id)['modified']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, Code_Snippet_Snapshot::row($id));
        $this->assertSame('wpmcp live update', $this->snippet($id)->name, 'Code Snippets reads the restored row, not a cached one');
        $this->assertSame(0, $GLOBALS['wpmcp_cs_live_runs']);
    }

    public function test_activation_through_code_snippets_and_its_rollback_switches_the_snippet_off_exactly(): void
    {
        $id     = (int) $this->create('wpmcp live activate')['result']['snippet']['id'];
        $before = Code_Snippet_Snapshot::row($id);
        $out    = $this->call('write', 'code-snippets-activate-snippet', [ 'id' => $id ]);

        $this->assertSame('active', $out['result']['snippet']['status']);
        $this->assertTrue((bool) $this->snippet($id)->active);
        $active_ids = array_map('intval', array_column(\Code_Snippets\code_snippets()->db->fetch_active_snippets([ 'global', 'single-use', 'front-end' ]), 'id'));
        $this->assertContains($id, $active_ids, 'Code Snippets would run it from the next request');
        $this->assertSame(0, $GLOBALS['wpmcp_cs_live_runs'], 'activating runs nothing now');

        $this->assertSame('snippet_active', (new Plugin_Data_Integration())->handle_write([ 'operation' => 'code-snippets-update-snippet', 'args' => [ 'id' => $id, 'name' => 'x' ] ])['error']['code'] ?? null);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, Code_Snippet_Snapshot::row($id));
        $this->assertFalse((bool) $this->snippet($id)->active);
        $this->assertArrayNotHasKey($id, (array) get_option('recently_active_snippets', []));
        $this->assertSame(0, $GLOBALS['wpmcp_cs_live_runs']);
    }

    public function test_deactivation_through_code_snippets_and_its_rollback_leaves_the_snippet_off(): void
    {
        $id = (int) $this->create('wpmcp live deactivate')['result']['snippet']['id'];
        $this->call('write', 'code-snippets-activate-snippet', [ 'id' => $id ]);
        $before = Code_Snippet_Snapshot::row($id);
        $out    = $this->call('write', 'code-snippets-deactivate-snippet', [ 'id' => $id ]);

        $this->assertFalse((bool) $this->snippet($id)->active);
        $this->assertArrayHasKey($id, (array) get_option('recently_active_snippets', []), 'Code Snippets stamped it');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $restored         = Code_Snippet_Snapshot::row($id);
        $before['active'] = '0';
        $this->assertSame($before, $restored, 'every column back, the snippet left off');
        $this->assertArrayNotHasKey($id, (array) get_option('recently_active_snippets', []));
        $this->assertSame(0, $GLOBALS['wpmcp_cs_live_runs']);
    }

    public function test_code_snippets_own_validator_refusal_surfaces_and_leaves_no_undo_point(): void
    {
        // Passes our static check, but redeclares a WordPress function,
        // which Code Snippets' own validator refuses to activate.
        $id   = (int) $this->create('wpmcp live redeclare', "function wp_die() {}")['result']['snippet']['id'];
        $seen = count(Snapshot_Store::recent(50));
        $out  = (new Plugin_Data_Integration())->handle_write([ 'operation' => 'code-snippets-activate-snippet', 'args' => [ 'id' => $id ] ]);

        $this->assertSame('activation_failed', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertFalse((bool) $this->snippet($id)->active);
        $this->assertCount($seen, Snapshot_Store::recent(50));
    }

    public function test_activation_is_refused_while_php_execution_is_disabled(): void
    {
        $id = (int) $this->create('wpmcp live gated')['result']['snippet']['id'];
        remove_all_filters('wpmcp_allow_php_exec');
        add_filter('wpmcp_allow_php_exec', '__return_false');
        $out = (new Plugin_Data_Integration())->handle_write([ 'operation' => 'code-snippets-activate-snippet', 'args' => [ 'id' => $id ] ]);
        $this->assertSame('php_execution_disabled', $out['error']['code'] ?? null);
        $this->assertFalse((bool) $this->snippet($id)->active);
    }
}
