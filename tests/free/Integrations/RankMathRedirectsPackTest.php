<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Rank_Math_Redirects_Pack;
use WPMCP\Integrations\Theme_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/rank-math-tables.php';

/**
 * Rank Math redirections and 404 log (issue #681, first slice) as ops on the
 * theme pair. Presence is driven through wpmcp_rank_math_active and the
 * rank_math_modules option, and the tables come from the plugin's own schema.
 */
class RankMathRedirectsPackTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_rank_math_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_rank_math_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_rank_math_active', '__return_true');
        add_filter(Rank_Math_Redirects_Pack::ENABLE_FILTER, '__return_true');
        update_option('rank_math_modules', [ 'redirections', '404-monitor' ]);
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_rank_math_active');
        remove_all_filters(Rank_Math_Redirects_Pack::ENABLE_FILTER);
        delete_option('rank_math_modules');
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args, bool $confirm = false): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        if ($confirm) {
            $call['confirm'] = true;
        }
        return (new Theme_Integration())->handle_write($call);
    }

    private function row(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'rank_math_redirections', $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private function seed(string $pattern, string $to, string $status = 'active', string $comparison = 'exact'): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'rank_math_redirections', [
            'sources'       => maybe_serialize([ [ 'pattern' => $pattern, 'comparison' => $comparison, 'ignore' => '' ] ]),
            'url_to'        => $to,
            'header_code'   => 301,
            'hits'          => 7,
            'status'        => $status,
            'created'       => '2026-01-01 00:00:00',
            'updated'       => '2026-01-01 00:00:00',
            'last_accessed' => '2026-01-02 00:00:00',
        ]);
        return (int) $wpdb->insert_id;
    }

    private function seed_404(string $uri, int $times, string $referer = '', string $ua = ''): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'rank_math_404_logs', [ 'uri' => $uri, 'accessed' => '2026-02-01 00:00:00', 'times_accessed' => $times, 'referer' => $referer, 'user_agent' => $ua ]);
    }

    private function row_count(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $wpdb->prefix . 'rank_math_redirections'));
    }

    public function test_reads_list_and_get_decode_sources(): void
    {
        $id  = $this->seed('old-page', '/new-page');
        $out = $this->read('rankmath-list-redirections');
        $this->assertSame(1, $out['result']['total'] ?? $out['total'] ?? null, (string) wp_json_encode($out));

        $get = $this->read('rankmath-get-redirection', [ 'id' => $id ]);
        $red = $get['result']['redirection'] ?? $get['redirection'];
        $this->assertSame('old-page', $red['sources'][0]['pattern']);
        $this->assertSame('/new-page', $red['target']);
        $this->assertSame(7, $red['hits']);

        $missing = $this->read('rankmath-get-redirection', [ 'id' => 9999 ]);
        $this->assertSame('redirection_not_found', $missing['error']['code']);
    }

    public function test_a_never_hit_redirection_reports_null_last_access(): void
    {
        // Issue #1051: Rank Math stores 1000-01-01 for a redirection that was never hit.
        $out = $this->write('rankmath-create-redirection', [ 'source' => '/old-1051', 'target' => '/new-1051' ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $id = (int) $out['result']['redirection']['id'];
        $this->assertSame('1000-01-01 00:00:00', $this->row($id)['last_accessed'], 'the stored sentinel is left as Rank Math writes it');
        $this->assertArrayHasKey('last_access', $out['result']['redirection']);
        $this->assertNull($out['result']['redirection']['last_access'], 'the create reply says never hit too');

        $get = $this->read('rankmath-get-redirection', [ 'id' => $id ]);
        $red = $get['result']['redirection'] ?? $get['redirection'];
        $this->assertSame(0, $red['hits']);
        $this->assertNull($red['last_access']);

        $list = $this->read('rankmath-list-redirections', [ 'search' => '1051' ]);
        $rows = ($list['result'] ?? $list)['redirections'];
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['last_access']);

        // A zero date (the column default) is not a date either; a real one is kept.
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'rank_math_redirections', [ 'last_accessed' => '0000-00-00 00:00:00' ], [ 'id' => $id ]);
        $get = $this->read('rankmath-get-redirection', [ 'id' => $id ]);
        $this->assertNull(($get['result']['redirection'] ?? $get['redirection'])['last_access']);

        $hit = $this->seed('hit-page', '/x');
        $get = $this->read('rankmath-get-redirection', [ 'id' => $hit ]);
        $this->assertSame('2026-01-02 00:00:00', ($get['result']['redirection'] ?? $get['redirection'])['last_access']);
    }

    public function test_404_log_sorts_masks_and_flags_covered_uris(): void
    {
        $this->seed('gone-page', '/home');
        $this->seed_404('rare', 1);
        $this->seed_404('gone-page', 50, 'https://ref.example.com/secret?token=1', str_repeat('a', 250));
        $out = $this->read('rankmath-list-404-log');
        $res = $out['result'] ?? $out;
        $this->assertSame('gone-page', $res['entries'][0]['uri']);
        $this->assertTrue($res['entries'][0]['already_redirected']);
        $this->assertFalse($res['entries'][1]['already_redirected']);
        $this->assertSame('ref.example.com', $res['entries'][0]['referer_host']);
        $this->assertSame(120, strlen($res['entries'][0]['user_agent']));
    }

    public function test_refusals_name_rank_math_and_module(): void
    {
        update_option('rank_math_modules', [ '404-monitor' ]);
        $this->assertSame('rank_math_module_off', $this->read('rankmath-list-redirections')['error']['code']);

        remove_all_filters('wpmcp_rank_math_active');
        add_filter('wpmcp_rank_math_active', '__return_false');
        $out = $this->read('rankmath-list-404-log');
        $this->assertSame('rank_math_inactive', $out['error']['code']);
        $this->assertStringContainsString('Rank Math', $out['error']['message']);

        // Issue #951: every Rank Math op gives the next step and the plugins link.
        foreach ([ 'rankmath-list-redirections', 'rankmath-list-404-log' ] as $op) {
            $out = $this->read($op);
            $this->assertSame('rank_math_inactive', $out['error']['code'], $op);
            $this->assertStringContainsString('Install and activate Rank Math SEO (' . admin_url('plugins.php') . ')', $out['error']['message'], $op);
            $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null, $op);
        }
    }

    public function test_writes_are_off_until_opted_in(): void
    {
        remove_all_filters(Rank_Math_Redirects_Pack::ENABLE_FILTER);
        $out = $this->write('rankmath-create-redirection', [ 'source' => '/a', 'target' => '/b' ]);
        $this->assertSame('opt_in_required', $out['error']['code']);
        $this->assertStringContainsString(Rank_Math_Redirects_Pack::ENABLE_FILTER, $out['error']['message']);
        $this->assertSame(0, $this->row_count());
    }

    public function test_create_is_snapshotted_and_rollback_removes_it(): void
    {
        $out = $this->write('rankmath-create-redirection', [ 'source' => '/Old-Page/', 'target' => '/new-page' ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $id  = (int) $out['result']['redirection']['id'];
        $row = $this->row($id);
        $this->assertSame(home_url('/new-page'), $row['url_to'], 'a relative target is stored as a full home URL, as Rank Math does');
        $this->assertSame('301', (string) $row['header_code']);
        $this->assertSame('active', $row['status']);
        $this->assertSame('Old-Page', maybe_unserialize($row['sources'])[0]['pattern'], 'Rank Math stores an exact source without outer slashes, case kept');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertNull($this->row($id));
    }

    public function test_create_gone_needs_no_target_and_duplicates_and_loops_are_refused(): void
    {
        $gone = $this->write('rankmath-create-redirection', [ 'source' => 'dead', 'header_code' => 410 ]);
        $this->assertArrayNotHasKey('error', $gone, (string) wp_json_encode($gone));
        $this->assertSame('', $this->row((int) $gone['result']['redirection']['id'])['url_to']);

        $dup = $this->write('rankmath-create-redirection', [ 'source' => '/dead/', 'header_code' => 410 ]);
        $this->assertSame('duplicate_source', $dup['error']['code']);

        $this->seed('b-page', '/a-page');
        $loop = $this->write('rankmath-create-redirection', [ 'source' => '/a-page', 'target' => '/b-page' ]);
        $this->assertSame('redirect_loop', $loop['error']['code']);

        $bad = $this->write('rankmath-create-redirection', [ 'source' => '/x', 'target' => 'javascript:alert(1)' ]);
        $this->assertSame('invalid_target', $bad['error']['code']);
        $this->assertSame(2, $this->row_count());
    }

    public function test_update_changes_only_passed_fields_and_rollback_restores(): void
    {
        $id     = $this->seed('old', '/new');
        $before = $this->row($id);
        $out    = $this->write('rankmath-update-redirection', [ 'id' => $id, 'status' => 'inactive', 'header_code' => 302 ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $after = $this->row($id);
        $this->assertSame('inactive', $after['status']);
        $this->assertSame('302', (string) $after['header_code']);
        $this->assertSame($before['sources'], $after['sources']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $restored = $this->row($id);
        $this->assertSame($before['status'], $restored['status']);
        $this->assertSame($before['header_code'], $restored['header_code']);
        $this->assertSame($before['updated'], $restored['updated']);
    }

    public function test_delete_then_rollback_resurrects_the_row(): void
    {
        $id     = $this->seed('old', '/new');
        $before = $this->row($id);

        $out = $this->write('rankmath-delete-redirection', [ 'id' => $id ], true);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertNull($this->row($id));

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, $this->row($id));
    }

    public function test_multi_source_rows_refuse_update(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'rank_math_redirections', [
            'sources' => maybe_serialize([ [ 'pattern' => 'a', 'comparison' => 'exact', 'ignore' => '' ], [ 'pattern' => 'b', 'comparison' => 'exact', 'ignore' => '' ] ]),
            'url_to' => '/c', 'header_code' => 301, 'status' => 'active', 'created' => '2026-01-01 00:00:00', 'updated' => '2026-01-01 00:00:00', 'last_accessed' => '2026-01-01 00:00:00',
        ]);
        $out = $this->write('rankmath-update-redirection', [ 'id' => (int) $wpdb->insert_id, 'status' => 'inactive' ]);
        $this->assertSame('unsupported_redirection', $out['error']['code']);
    }
}
