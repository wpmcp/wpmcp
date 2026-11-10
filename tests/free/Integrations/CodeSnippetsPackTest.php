<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Code_Snippet_Snapshot;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/code-snippets-double.php';

/**
 * Code Snippets (issue #576) on the plugin-data pair, against the stand-in in
 * tests/support/code-snippets-double.php, kept the way Code Snippets 4.1
 * keeps its {prefix}snippets table.
 *
 * Snippets are created inactive and never saved active, so Code Snippets
 * never runs their code for us; code failing the static check is refused
 * before anything is written; an active snippet is not edited; deactivation
 * is never gated; and every write is a 'code_snippet' row image whose
 * rollback puts the row back exactly, never as active.
 */
class CodeSnippetsPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'code-snippets-list-snippets'      => 'read',
        'code-snippets-get-snippet'        => 'read',
        'code-snippets-create-snippet'     => 'write',
        'code-snippets-update-snippet'     => 'write',
        'code-snippets-deactivate-snippet' => 'write',
    ];

    private const NOOP = "// wpmcp test snippet: does nothing.\n\$wpmcp_cs_noop = 1;";

    private int $admin;

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
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        wp_set_current_user($this->admin);
        add_filter('wpmcp_code_snippets_active', '__return_true');
        $GLOBALS['wpmcp_test_code_snippets_executed'] = 0;
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_code_snippets_active');
        remove_all_filters('code_snippets_cap');
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    /** A snippet row written the way Code Snippets writes one; returns its id. */
    private function seed(array $fields = []): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'snippets', $fields + [
            'name'        => 'Seeded',
            'description' => 'seeded description',
            'code'        => self::NOOP,
            'tags'        => 'alpha, beta',
            'scope'       => 'global',
            'priority'    => 10,
            'active'      => 0,
            'modified'    => '2026-01-02 03:04:05',
            'revision'    => 3,
        ]);
        \Code_Snippets\clean_snippets_cache($wpdb->prefix . 'snippets');
        return (int) $wpdb->insert_id;
    }

    private function row(int $id): ?array
    {
        return Code_Snippet_Snapshot::row($id);
    }

    // ---------------------------------------------------------------
    // Catalog and availability
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_code_snippets_op_on_the_plugin_data_pair(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
            $this->assertSame('manage_options', $ops[ $name ]['capability'], $name);
        }
        $this->assertArrayNotHasKey('code-snippets-activate-snippet', $ops, 'activation is a paid-tier op');
        $this->assertTrue((new Plugin_Data_Integration())->is_available());
    }

    public function test_while_code_snippets_is_inactive_its_ops_are_documented_and_answer_code_snippets_inactive_writing_nothing(): void
    {
        remove_all_filters('wpmcp_code_snippets_active');
        add_filter('wpmcp_code_snippets_active', '__return_false');
        add_filter('wpmcp_buddypress_active', '__return_true');
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        $out = $this->write('code-snippets-create-snippet', [ 'name' => 'X', 'code' => self::NOOP ]);
        remove_all_filters('wpmcp_buddypress_active');

        $this->assertFalse($ops['code-snippets-create-snippet']['dependency_met']);
        $this->assertSame('code_snippets_inactive', $out['error']['code'] ?? null);
        global $wpdb;
        $this->assertSame('0', (string) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}snippets"));
        $this->assertSame([], Snapshot_Store::recent(5));
    }

    public function test_presence_defaults_to_the_code_snippets_version_constant(): void
    {
        remove_all_filters('wpmcp_code_snippets_active');
        $this->assertFalse(\WPMCP\Integrations\Code_Snippets_Pack::active());
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_returns_summaries_without_code_and_filters_by_status_and_tag(): void
    {
        $a = $this->seed([ 'name' => 'A', 'tags' => 'alpha' ]);
        $b = $this->seed([ 'name' => 'B', 'tags' => 'beta', 'active' => 1 ]);
        $t = $this->seed([ 'name' => 'T', 'active' => -1 ]);

        $all = $this->ok($this->read('code-snippets-list-snippets'))['result'];
        $this->assertSame([ $a, $b ], array_column($all['snippets'], 'id'), 'trashed snippets only on request');
        $this->assertArrayNotHasKey('code', $all['snippets'][0]);
        $this->assertSame([ $b ], array_column($this->ok($this->read('code-snippets-list-snippets', [ 'status' => 'active' ]))['result']['snippets'], 'id'));
        $this->assertSame([ $t ], array_column($this->ok($this->read('code-snippets-list-snippets', [ 'status' => 'trashed' ]))['result']['snippets'], 'id'));
        $this->assertSame([ $a ], array_column($this->ok($this->read('code-snippets-list-snippets', [ 'tag' => 'alpha' ]))['result']['snippets'], 'id'));
    }

    public function test_get_returns_code_and_a_static_check_report(): void
    {
        $id  = $this->seed();
        $out = $this->ok($this->read('code-snippets-get-snippet', [ 'id' => $id ]))['result'];
        $this->assertSame(self::NOOP, $out['snippet']['code']);
        $this->assertSame('php', $out['snippet']['type']);
        $this->assertTrue($out['validation']['syntax_valid']);
        $this->assertSame('snippet_not_found', $this->read('code-snippets-get-snippet', [ 'id' => 999999 ])['error']['code'] ?? null);
    }

    public function test_a_caller_code_snippets_does_not_let_manage_snippets_is_refused(): void
    {
        add_filter('code_snippets_cap', static fn () => 'manage_network_options');
        $out = $this->read('code-snippets-list-snippets');
        $this->assertSame('operation_denied', $out['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // Create
    // ---------------------------------------------------------------

    public function test_create_stores_an_inactive_php_snippet_runs_nothing_and_rollback_deletes_it(): void
    {
        $out = $this->ok($this->write('code-snippets-create-snippet', [
            'name'        => 'Footer note',
            'code'        => self::NOOP,
            'description' => 'desc',
            'tags'        => [ 'ops' ],
            'scope'       => 'front-end',
        ]));
        $id  = (int) $out['result']['snippet']['id'];
        $row = $this->row($id);

        $this->assertTrue($out['recoverable']);
        $this->assertSame('inactive', $out['result']['snippet']['status']);
        $this->assertSame('0', (string) $row['active']);
        $this->assertSame('front-end', $row['scope']);
        $this->assertSame('ops', $row['tags']);
        $this->assertSame(0, $GLOBALS['wpmcp_test_code_snippets_executed'], 'Code Snippets never ran the code');

        $ledger = Snapshot_Store::get_by_operation((string) $out['operation_id']);
        $this->assertSame($id, (int) $ledger['snapshot']['object_id'], 'the undo point is keyed by the new id');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertNull($this->row($id));
    }

    public function test_create_refuses_code_that_fails_the_static_check_and_writes_nothing(): void
    {
        global $wpdb;
        foreach ([ 'eval($_GET["x"]);', 'function (' ] as $code) {
            $out = $this->write('code-snippets-create-snippet', [ 'name' => 'Bad', 'code' => $code ]);
            $this->assertSame('validation_failed', $out['error']['code'] ?? null, $code);
        }
        $this->assertSame('0', (string) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}snippets"));
        $this->assertSame([], Snapshot_Store::recent(5));
    }

    public function test_create_only_takes_php_scopes(): void
    {
        $out = $this->write('code-snippets-create-snippet', [ 'name' => 'Head', 'code' => '<script></script>', 'scope' => 'head-content' ]);
        $this->assertArrayHasKey('error', $out);
    }

    // ---------------------------------------------------------------
    // Update
    // ---------------------------------------------------------------

    public function test_update_changes_an_inactive_snippet_and_rollback_restores_the_row_exactly(): void
    {
        $id     = $this->seed();
        $before = $this->row($id);
        $out    = $this->ok($this->write('code-snippets-update-snippet', [ 'id' => $id, 'name' => 'Renamed', 'code' => '$wpmcp_cs_noop = 2;', 'tags' => [ 'gamma' ] ]));

        $after = $this->row($id);
        $this->assertSame('Renamed', $after['name']);
        $this->assertSame('$wpmcp_cs_noop = 2;', $after['code']);
        $this->assertSame('0', (string) $after['active']);
        $this->assertSame(0, $GLOBALS['wpmcp_test_code_snippets_executed']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, $this->row($id));
    }

    public function test_update_refuses_an_active_snippet_a_locked_one_a_non_php_one_and_failing_code(): void
    {
        $active = $this->seed([ 'active' => 1 ]);
        $html   = $this->seed([ 'scope' => 'footer-content', 'code' => '<p>hi</p>' ]);
        $locked = $this->seed();
        update_option('code_snippets_locked', [ $locked => true ]);
        $plain  = $this->seed();

        $this->assertSame('snippet_active', $this->write('code-snippets-update-snippet', [ 'id' => $active, 'name' => 'N' ])['error']['code'] ?? null);
        $this->assertSame('unsupported_snippet_type', $this->write('code-snippets-update-snippet', [ 'id' => $html, 'name' => 'N' ])['error']['code'] ?? null);
        $this->assertSame('snippet_locked', $this->write('code-snippets-update-snippet', [ 'id' => $locked, 'name' => 'N' ])['error']['code'] ?? null);
        $this->assertSame('validation_failed', $this->write('code-snippets-update-snippet', [ 'id' => $plain, 'code' => 'eval($_POST["x"]);' ])['error']['code'] ?? null);
        $this->assertSame(0, $GLOBALS['wpmcp_test_code_snippets_executed']);
        $this->assertSame([], Snapshot_Store::recent(5));
    }

    // ---------------------------------------------------------------
    // Deactivate
    // ---------------------------------------------------------------

    public function test_deactivate_is_not_gated_and_rollback_leaves_the_snippet_off_with_every_other_column_restored(): void
    {
        add_filter('wpmcp_allow_php_exec', '__return_false');
        $id     = $this->seed([ 'active' => 1 ]);
        $before = $this->row($id);
        $out    = $this->ok($this->write('code-snippets-deactivate-snippet', [ 'id' => $id ]));
        remove_all_filters('wpmcp_allow_php_exec');

        $this->assertSame('0', (string) $this->row($id)['active']);
        $this->assertArrayHasKey($id, get_option('recently_active_snippets', []));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $restored = $this->row($id);
        $this->assertSame('0', (string) $restored['active'], 'an undo never switches a snippet on');
        $before['active'] = '0';
        $this->assertSame($before, $restored);
        $this->assertArrayNotHasKey($id, get_option('recently_active_snippets', []), 'its recently-active stamp is undone too');
    }

    public function test_deactivating_an_inactive_snippet_is_refused(): void
    {
        $id = $this->seed();
        $this->assertSame('snippet_inactive', $this->write('code-snippets-deactivate-snippet', [ 'id' => $id ])['error']['code'] ?? null);
    }

    public function test_rollback_switches_off_a_snippet_activated_after_the_write(): void
    {
        global $wpdb;
        $id  = $this->seed();
        $out = $this->ok($this->write('code-snippets-update-snippet', [ 'id' => $id, 'name' => 'Later on' ]));
        $wpdb->update($wpdb->prefix . 'snippets', [ 'active' => 1 ], [ 'id' => $id ]);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('0', (string) $this->row($id)['active']);
        $this->assertSame('Seeded', $this->row($id)['name']);
    }
}
