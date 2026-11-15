<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Rank_Math_Redirects_Pack;
use WPMCP\Integrations\Theme_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #681: the Rank Math redirects and 404 log ops against the real Rank
 * Math SEO plugin.
 *
 * It runs only in the local gate's live Rank Math leg (bin/test-local.sh, or
 * bin/test-local.sh --live-rank-math alone), which sets WPMCP_LIVE_RANK_MATH=1,
 * installs Rank Math from wordpress.org on a separate WordPress install and
 * loads it in tests/bootstrap.php. The tables are created by Rank Math's own
 * installer, rows are made with its own Redirection and Monitor classes, and
 * a pack write must be matched by Rank Math's own redirect lookup.
 *
 * Everywhere else Rank Math is absent and the test is skipped; the live leg
 * runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group rank-math-live
 */
class RankMathLiveTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        if (defined('RANK_MATH_VERSION') && class_exists('RankMath\\Installer')) {
            \RankMath\Installer::create_tables([ 'redirections', '404-monitor' ]);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('RANK_MATH_VERSION') || ! class_exists('RankMath\\Redirections\\DB')) {
            $this->markTestSkipped('Needs the real Rank Math SEO (bin/test-local.sh --live-rank-math, WPMCP_LIVE_RANK_MATH=1).');
        }
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter(Rank_Math_Redirects_Pack::ENABLE_FILTER, '__return_true');
        update_option('rank_math_modules', [ 'redirections', '404-monitor' ]);
    }

    protected function tearDown(): void
    {
        remove_all_filters(Rank_Math_Redirects_Pack::ENABLE_FILTER);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Theme_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out['result'] ?? $out;
    }

    private function write(string $op, array $args): array
    {
        $out = (new Theme_Integration())->handle_write([ 'operation' => $op, 'args' => $args, 'confirm' => true ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function real(string $pattern, string $to): int
    {
        $redirection = \RankMath\Redirections\Redirection::create();
        $redirection->add_source($pattern, 'exact');
        $redirection->add_destination($to);
        return (int) $redirection->save();
    }

    public function test_pack_reads_rows_rank_math_wrote(): void
    {
        $id  = $this->real('real-old', '/real-new');
        $get = $this->read('rankmath-get-redirection', [ 'id' => $id ])['redirection'];
        $this->assertSame('real-old', $get['sources'][0]['pattern']);
        $this->assertSame('exact', $get['sources'][0]['comparison']);
        $this->assertSame(301, $get['header_code']);
        $this->assertSame('active', $get['status']);
        $this->assertNotSame('', $get['target']);

        $list = $this->read('rankmath-list-redirections', [ 'search' => 'real-old' ]);
        $this->assertSame(1, $list['total']);
    }

    public function test_404_log_reads_rank_math_monitor_rows(): void
    {
        \RankMath\Monitor\DB::update([ 'uri' => 'missing-page', 'referer' => 'https://ref.example.com/a?b=1', 'user_agent' => 'UA' ]);
        \RankMath\Monitor\DB::update([ 'uri' => 'missing-page' ]);
        $this->real('missing-page', '/found');

        $log = $this->read('rankmath-list-404-log');
        $this->assertSame('missing-page', $log['entries'][0]['uri']);
        $this->assertSame(2, $log['entries'][0]['times_accessed']);
        $this->assertTrue($log['entries'][0]['already_redirected']);
        $this->assertSame('ref.example.com', $log['entries'][0]['referer_host']);
    }

    public function test_a_pack_create_is_matched_by_rank_math_and_rolls_back(): void
    {
        $out = $this->write('rankmath-create-redirection', [ 'source' => '/Pack-Source/', 'target' => '/pack-target' ]);
        $id  = (int) $out['result']['redirection']['id'];

        $match = \RankMath\Redirections\DB::match_redirections('Pack-Source');
        $this->assertNotEmpty($match, 'Rank Math must find the row the pack wrote');
        $this->assertSame($id, (int) $match['id']);

        // The row has the shape Rank Math itself writes.
        $real = \RankMath\Redirections\DB::get_redirection_by_id($this->real('shape-check', '/pack-target'));
        $mine = \RankMath\Redirections\DB::get_redirection_by_id($id);
        $this->assertSame(array_keys($real['sources'][0]), array_keys($mine['sources'][0]), 'same source keys in the same order');
        $this->assertSame($real['url_to'], $mine['url_to']);

        $dup = (new Theme_Integration())->handle_write([ 'operation' => 'rankmath-create-redirection', 'args' => [ 'source' => 'Pack-Source', 'target' => '/x' ] ]);
        $this->assertSame('duplicate_source', $dup['error']['code'], 'a source Rank Math already redirects is refused');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertFalse(\RankMath\Redirections\DB::get_redirection_by_id($id));
    }

    public function test_update_delete_and_rollback_against_real_rows_and_cache(): void
    {
        global $wpdb;
        $id = $this->real('live-edit', '/live-target');
        \RankMath\Redirections\Cache::add([ 'from_url' => 'live-edit', 'redirection_id' => $id, 'object_id' => 5, 'object_type' => 'post' ]);
        $before = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'rank_math_redirections', $id), ARRAY_A);

        $upd = $this->write('rankmath-update-redirection', [ 'id' => $id, 'status' => 'inactive', 'header_code' => 302 ]);
        $row = \RankMath\Redirections\DB::get_redirection_by_id($id);
        $this->assertSame('inactive', $row['status']);
        $this->assertSame(302, (int) $row['header_code']);
        $this->assertTrue(Rollback_Service::restore_operation((string) $upd['operation_id']));
        $this->assertSame($before, $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'rank_math_redirections', $id), ARRAY_A));

        $del = $this->write('rankmath-delete-redirection', [ 'id' => $id ]);
        $this->assertFalse(\RankMath\Redirections\DB::get_redirection_by_id($id));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE redirection_id = %d', $wpdb->prefix . 'rank_math_redirections_cache', $id)), 'cache rows are purged');
        $this->assertTrue(Rollback_Service::restore_operation((string) $del['operation_id']));
        $this->assertSame($before, $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $wpdb->prefix . 'rank_math_redirections', $id), ARRAY_A));
    }
}
