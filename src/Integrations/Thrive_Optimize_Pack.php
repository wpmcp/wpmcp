<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;
use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Tools\Database\Database_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Thrive Optimize (issue #827) on the plugin-data pair, paid-tier, so it adds
 * no top-level tools.
 *
 * Thrive Optimize 10.9.2.1 keeps a test as a row of {prefix}tab_tests (its
 * TD_DB_Migration prefix is "tab") and each variation's results as a row of
 * {prefix}tab_test_items. A variation is a child post of the tested page
 * with the post_status tab_variation, and the page itself is the control.
 * Its share of the traffic is the post meta thrive_ab_traffic, read by the
 * plugin's variation manager on every visit while the test's row says
 * running; the page's thrive_ab_running_test_id meta names that test
 * (Thrive_AB_Meta, Thrive_AB_Test::start() and stop()).
 *
 * Reads: list-tests and get-test (variations with traffic, impressions,
 * conversions, revenue, conversion rate, improvement and chance to beat the
 * original, computed the way Thrive_AB_Test_Item does). No personal data is
 * kept in these tables: they hold counts only.
 *
 * Writes (off until a site opts in with wpmcp_enable_thrive_optimize_write):
 *  - update-traffic-split writes thrive_ab_traffic on each variation of a
 *    running test, under a post snapshot per variation, one session per call.
 *  - stop-test ends a running test without a winner the way
 *    Thrive_AB_Test::stop() does (status completed, date_completed, the
 *    page's running test id cleared), so visitors see the original page.
 *    Its row is a db_rows image and the page a post snapshot, so
 *    rollback-session starts the test again. It changes live traffic, so it
 *    needs confirm:true.
 *  - pick-winner runs the plugin's own Thrive_AB_Ajax::set_winner(), which
 *    copies the winner's design over the page, archives the other
 *    variations and completes the test. That cannot be restored as a whole:
 *    recoverable:false, confirm:true in the call's own arguments.
 * Creating and starting a test is left to Thrive Optimize's own screen:
 * a test needs variations designed in Thrive Architect first.
 *
 * Every op takes manage_options. Presence is filterable
 * (wpmcp_thrive_optimize_active, default: THRIVE_AB_PLUGIN_FILE is defined,
 * the one constant the plugin's main file defines); while Thrive Optimize is
 * not loaded the ops stay documented in list-operations
 * (dependency_met:false) and answer thrive_optimize_inactive.
 */
final class Thrive_Optimize_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads and writes Thrive Optimize's own tables live; there is no read API to cache behind.

    public const OPT_IN_FILTER = 'wpmcp_enable_thrive_optimize_write';

    public const MAX_PER_PAGE = 100;

    private const STATUSES = [ 'running', 'completed', 'archived' ];

    /** MySQL's zero datetime, which Thrive Optimize stores for "not yet". */
    private const ZERO_DATE = '0000-00-00 00:00:00';

    /** Whether Thrive Optimize is loaded (filterable) and this install may run the paid tier. */
    public static function active(): bool
    {
        return Registrar::tier_permitted('pro')
            && (bool) apply_filters('wpmcp_thrive_optimize_active', defined('THRIVE_AB_PLUGIN_FILE'));
    }

    private static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'tab_' . $name;
    }

    // ------------------------------------------------------------------
    // Catalog
    // ------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id      = [ 'type' => 'integer', 'minimum' => 1 ];
        $test    = [ 'type' => 'object', 'properties' => [ 'test_id' => $id ], 'required' => [ 'test_id' ] ];
        $base    = [
            'tier'       => 'pro',
            'capability' => 'manage_options',
            'requires'   => static fn () => Ops_Status_Packs::presence(self::active(), 'thrive_optimize_inactive', 'Thrive Optimize'),
        ];
        $write   = $base + [
            'self_snapshotting'  => true,
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_thrive_optimize_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];

        return [
            'thrive-optimize-list-tests'           => $base + [
                'mode'         => 'read',
                'description'  => 'List Thrive Optimize A/B tests newest first: id, title, page, goal (monetary, visits, optins), status, dates, variation count and total unique visitors and conversions. Filters: status, page_id; page and per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status'   => [ 'type' => 'string', 'enum' => self::STATUSES ],
                        'page_id'  => $id,
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
                    ],
                ],
                'handler'      => static fn (array $args): array => self::list_tests($args),
            ],
            'thrive-optimize-get-test'             => $base + [
                'mode'         => 'read',
                'description'  => 'One Thrive Optimize test: goal, goal pages, auto-winner settings, and per variation (control first) its traffic share, status, visitors, conversions, revenue, conversion rate, improvement and chance to beat the original',
                'input_schema' => $test,
                'validate'     => static fn (array $args): ?array => self::test_refusal((int) $args['test_id'], false),
                'handler'      => static fn (array $args): array => [ 'test' => self::test((int) $args['test_id']) ],
            ],
            'thrive-optimize-update-traffic-split' => $write + [
                'mode'         => 'write',
                'description'  => 'Set how a running Thrive Optimize test splits visitors: traffic maps every active variation id (the page id is the control) to a whole percent, summing to 100. Undo: rollback-session',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'test_id' => $id,
                        'traffic' => [
                            'type'                 => 'object',
                            'minProperties'        => 1,
                            'additionalProperties' => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 100 ],
                        ],
                    ],
                    'required'   => [ 'test_id', 'traffic' ],
                ],
                'validate'     => static fn (array $args): ?array => self::traffic_refusal((int) $args['test_id'], (array) $args['traffic']),
                'handler'      => static fn (array $args, array $context): array => self::update_traffic($args, $context),
            ],
            'thrive-optimize-stop-test'            => $write + [
                'mode'            => 'destructive',
                'description'     => 'Stop a running Thrive Optimize test without a winner: visitors see the original page again and results stop counting. Needs confirm:true. Undo: rollback-session restarts it',
                'input_schema'    => $test,
                'validate'        => static fn (array $args): ?array => self::test_refusal((int) $args['test_id'], true),
                'confirm_preview' => static fn (array $args): array => self::stop_preview((int) $args['test_id']),
                'handler'         => static fn (array $args, array $context): array => self::stop($args, $context),
            ],
            'thrive-optimize-pick-winner'          => $base + [
                'mode'                 => 'destructive',
                'undoable'             => false,
                'enabled_by_default'   => (bool) apply_filters('wpmcp_enable_thrive_optimize_write', false),
                'opt_in_filter'        => self::OPT_IN_FILTER,
                'description'          => 'End a running Thrive Optimize test with a winner through Thrive Optimize itself: the winning variation\'s design replaces the page, the others are archived and the test completes. CANNOT be undone (recoverable:false); needs confirm:true',
                'input_schema'         => [
                    'type'       => 'object',
                    'properties' => [ 'test_id' => $id, 'variation_id' => $id ],
                    'required'   => [ 'test_id', 'variation_id' ],
                ],
                'validate'             => static fn (array $args): ?array => self::winner_refusal((int) $args['test_id'], (int) $args['variation_id']),
                'confirm_preview'      => static fn (array $args): array => self::winner_preview((int) $args['test_id'], (int) $args['variation_id']),
                'handler'              => static fn (array $args): array => self::pick_winner((int) $args['test_id'], (int) $args['variation_id']),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Refusals
    // ------------------------------------------------------------------

    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    /** Refusal for a test that does not exist, or (when $running) is not running. */
    private static function test_refusal(int $id, bool $running): ?array
    {
        if (! Plugin_Table_Rows_Snapshot::table_exists(self::table('tests'))) {
            return self::refusal('thrive_optimize_unavailable', 'Thrive Optimize\'s tests table is missing. Open Thrive Optimize once in wp-admin so it creates its tables.');
        }
        $row = self::row($id);
        if (null === $row) {
            return self::refusal('test_not_found', sprintf('Thrive Optimize test %d does not exist. Use thrive-optimize-list-tests to find one.', $id), [ 'test_id' => $id ]);
        }
        if ($running && 'running' !== $row['status']) {
            return self::refusal('test_not_running', sprintf('Thrive Optimize test %d is %s, not running.', $id, (string) $row['status']), [ 'test_id' => $id, 'status' => (string) $row['status'] ]);
        }
        return null;
    }

    /** @param array<string, mixed> $traffic variation id => percent */
    private static function traffic_refusal(int $id, array $traffic): ?array
    {
        $refusal = self::test_refusal($id, true);
        if (null !== $refusal) {
            return $refusal;
        }
        $active = array_map(static fn (array $item): int => (int) $item['variation_id'], self::items($id, true));
        $given  = array_map('intval', array_keys($traffic));
        sort($active);
        sort($given);
        if ($active !== $given) {
            return self::refusal(
                'variations_mismatch',
                sprintf('traffic must name every active variation of test %d and nothing else: %s.', $id, implode(', ', $active)),
                [ 'variation_ids' => $active ]
            );
        }
        $sum = array_sum(array_map('intval', $traffic));
        if (100 !== $sum) {
            return self::refusal('traffic_sum', sprintf('The traffic shares add up to %d; they must add up to 100.', $sum), [ 'sum' => $sum ]);
        }
        return null;
    }

    private static function winner_refusal(int $id, int $variation): ?array
    {
        $refusal = self::test_refusal($id, true);
        if (null !== $refusal) {
            return $refusal;
        }
        if (null === self::item_of($id, $variation)) {
            return self::refusal('variation_not_in_test', sprintf('Post %d is not an active variation of Thrive Optimize test %d. Use thrive-optimize-get-test to list them.', $variation, $id), [ 'test_id' => $id, 'variation_id' => $variation ]);
        }
        if (! class_exists('Thrive_AB_Ajax') || ! is_callable([ 'Thrive_AB_Ajax', 'set_winner' ])) {
            return self::refusal('thrive_optimize_unavailable', 'Thrive Optimize\'s winner handler is not loaded, so a winner cannot be picked. Check that Thrive Optimize and Thrive Architect are active.');
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /** @return array<string, string|null>|null */
    private static function row(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::table('tests'), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @return array<int, array<string, string|null>> the test's items, control first */
    private static function items(int $id, bool $active_only = false): array
    {
        global $wpdb;
        $sql = 'SELECT * FROM %i WHERE test_id = %d' . ($active_only ? ' AND active = 1' : '') . ' ORDER BY is_control DESC, id ASC';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql holds only literals and placeholders.
        return (array) $wpdb->get_results($wpdb->prepare($sql, self::table('test_items'), $id), ARRAY_A);
    }

    private static function item_of(int $id, int $variation): ?array
    {
        foreach (self::items($id, true) as $item) {
            if ((int) $item['variation_id'] === $variation) {
                return $item;
            }
        }
        return null;
    }

    private static function date(?string $value): ?string
    {
        return (null === $value || '' === $value || self::ZERO_DATE === $value) ? null : $value;
    }

    /** @return array<string, mixed> */
    private static function list_tests(array $args): array
    {
        global $wpdb;

        $table = self::table('tests');
        if (! Plugin_Table_Rows_Snapshot::table_exists($table)) {
            return [ 'tests' => [], 'total' => 0, 'note' => 'Thrive Optimize has not created its tables yet.' ];
        }
        $where  = [ '1=1' ];
        $params = [ $table ];
        if (isset($args['status'])) {
            $where[]  = 'status = %s';
            $params[] = (string) $args['status'];
        }
        if (isset($args['page_id'])) {
            $where[]  = 'page_id = %d';
            $params[] = (int) $args['page_id'];
        }
        $per_page = (int) ($args['per_page'] ?? 20);
        $page     = (int) ($args['page'] ?? 1);
        $sql      = 'SELECT * FROM %i WHERE ' . implode(' AND ', $where);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $sql holds only literals and placeholders, one per entry of $params.
        $total = (int) $wpdb->get_var($wpdb->prepare(str_replace('SELECT *', 'SELECT COUNT(*)', $sql), ...$params));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $sql holds only literals and placeholders, one per entry of $params.
        $rows = (array) $wpdb->get_results($wpdb->prepare($sql . ' ORDER BY date_added DESC, id DESC LIMIT %d OFFSET %d', ...array_merge($params, [ $per_page, ($page - 1) * $per_page ])), ARRAY_A);

        $tests = [];
        foreach ($rows as $row) {
            $items   = self::items((int) $row['id']);
            $tests[] = self::summary($row) + [
                'variations'         => count($items),
                'unique_impressions' => array_sum(array_map(static fn (array $i): int => (int) $i['unique_impressions'], $items)),
                'conversions'        => array_sum(array_map(static fn (array $i): int => (int) $i['conversions'], $items)),
            ];
        }
        return [ 'tests' => $tests, 'total' => $total, 'page' => $page, 'per_page' => $per_page ];
    }

    /** @return array<string, mixed> */
    private static function summary(array $row): array
    {
        $page = (int) $row['page_id'];
        return [
            'id'             => (int) $row['id'],
            'title'          => (string) $row['title'],
            'page_id'        => $page,
            'page_title'     => get_the_title($page),
            'goal'           => (string) $row['type'],
            'status'         => (string) $row['status'],
            'date_started'   => self::date($row['date_started'] ?? null),
            'date_completed' => self::date($row['date_completed'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private static function test(int $id): array
    {
        $row     = (array) self::row($id);
        $items   = self::items($id);
        $control = $items[0] ?? null;
        foreach ($items as $item) {
            if (1 === (int) $item['is_control']) {
                $control = $item;
                break;
            }
        }

        $variations = [];
        foreach ($items as $item) {
            $variations[] = self::variation($item, $control);
        }
        $active = array_filter($variations, static fn (array $v): bool => $v['active']);

        return self::summary($row) + [
            'notes'         => (string) ($row['notes'] ?? ''),
            'goal_pages'    => self::goal_pages($row),
            'auto_win'      => [
                'enabled'                 => (bool) (int) $row['auto_win_enabled'],
                'min_conversions'         => (int) $row['auto_win_min_conversions'],
                'min_duration_days'       => (int) $row['auto_win_min_duration'],
                'chance_to_beat_original' => (float) $row['auto_win_chance_original'],
            ],
            'traffic_total' => array_sum(array_column($active, 'traffic')),
            'variations'    => $variations,
        ];
    }

    /**
     * Goal pages (visits goal: post id => details) or the revenue service a
     * monetary test stores in the same column.
     *
     * @return array<int, array<string, mixed>>|string|null
     */
    private static function goal_pages(array $row)
    {
        $raw = $row['goal_pages'] ?? null;
        if (null === $raw || '' === $raw) {
            return null;
        }
        $pages = maybe_unserialize((string) $raw);
        if (! is_array($pages)) {
            return (string) $raw;
        }
        $out = [];
        foreach (array_keys($pages) as $post_id) {
            $out[] = [ 'post_id' => (int) $post_id, 'title' => get_the_title((int) $post_id) ];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function variation(array $item, ?array $control): array
    {
        $id      = (int) $item['variation_id'];
        $rate    = self::conversion_rate($item);
        $control_rate = null === $control ? 0.0 : self::conversion_rate($control);
        $stopped = self::date($item['stopped_date'] ?? null);

        return [
            'item_id'                 => (int) $item['id'],
            'variation_id'            => $id,
            'title'                   => (string) $item['title'],
            'is_control'              => 1 === (int) $item['is_control'],
            'is_winner'               => 1 === (int) $item['is_winner'],
            'active'                  => 1 === (int) $item['active'],
            'status'                  => (string) get_post_meta($id, 'thrive_ab_status', true),
            'traffic'                 => (int) get_post_meta($id, 'thrive_ab_traffic', true),
            'impressions'             => (int) $item['impressions'],
            'unique_impressions'      => (int) $item['unique_impressions'],
            'conversions'             => (int) $item['conversions'],
            'revenue'                 => (float) $item['revenue'],
            'revenue_per_visitor'     => (int) $item['unique_impressions'] > 0 ? round((float) $item['revenue'] / (int) $item['unique_impressions'], 2) : 0.0,
            'conversion_rate'         => $rate,
            'improvement'             => 0.0 == $control_rate ? 0.0 : round((($rate - $control_rate) * 100) / $control_rate, 2),
            'chance_to_beat_original' => null === $control || 1 === (int) $item['is_control'] ? 0.0 : self::chance($item, $control),
            'stopped_date'            => 1 === (int) $item['active'] ? null : $stopped,
        ];
    }

    /** Thrive_AB_Test_Item::conversion_rate(): percent, two decimals. */
    private static function conversion_rate(array $item): float
    {
        $conversions = (int) $item['conversions'];
        $unique      = (int) $item['unique_impressions'];
        return (0 === $conversions || 0 === $unique) ? 0.0 : round(100 * ($conversions / $unique), 2);
    }

    /** Thrive_AB_Test_Item::chance(): the percent chance a variation beats the control. */
    private static function chance(array $item, array $control): float
    {
        $n  = (int) $item['unique_impressions'];
        $cn = (int) $control['unique_impressions'];
        if (0 === $n || 0 === $cn) {
            return 0.0;
        }
        $v   = self::conversion_rate($item) / 100;
        $c   = self::conversion_rate($control) / 100;
        $vsd = sqrt($v * (1 - $v) / $n);
        $csd = sqrt($c * (1 - $c) / $cn);
        if (0.0 == $vsd && 0.0 == $csd) {
            return 0.0;
        }
        $z = ($c - $v) / sqrt($csd ** 2 + $vsd ** 2);
        return round((1 - self::normal($z)) * 100, 2);
    }

    /** Thrive_AB_Test_Item::normal_distribution(): the cumulative normal at $x. */
    private static function normal(float $x): float
    {
        $b = [ 0.319381530, -0.356563782, 1.781477937, -1.821255978, 1.330274429 ];
        $t = 1.0 / (1.0 + 0.2316419 * abs($x));
        $d = 0.39894228 * exp(-$x * $x / 2.0) * $t * ($t * ($t * ($t * ($t * $b[4] + $b[3]) + $b[2]) + $b[1]) + $b[0]);
        return $x >= 0.0 ? 1.0 - $d : $d;
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /** Run $write under a snapshot of one object; returns the operation id. */
    private static function snapshotted(array $target, array $context, array $args, callable $write): string
    {
        $out = Safe_Mutation::run(
            $target + [
                'session_id' => (string) $context['session_id'],
                'tool_name'  => (string) $context['tool_name'],
                'args'       => [ 'operation' => (string) $context['operation'], 'args' => $args ],
            ],
            static function () use ($write): bool {
                $write();
                return true;
            }
        );
        return (string) $out['operation_id'];
    }

    /** @return array{result: array, operation_ids: string[]} */
    private static function update_traffic(array $args, array $context): array
    {
        $id  = (int) $args['test_id'];
        $ops = [];
        foreach ((array) $args['traffic'] as $variation => $share) {
            $variation = (int) $variation;
            $share     = (int) $share;
            if ((string) $share === (string) get_post_meta($variation, 'thrive_ab_traffic', true)) {
                continue;
            }
            $ops[] = self::snapshotted(
                [ 'object_type' => 'post', 'object_id' => $variation ],
                $context,
                $args,
                static fn () => update_post_meta($variation, 'thrive_ab_traffic', $share)
            );
        }
        return [ 'result' => [ 'test' => self::test($id) ], 'operation_ids' => $ops ];
    }

    /** @return array{message: string, data: array} */
    private static function stop_preview(int $id): array
    {
        $row = (array) self::row($id);
        return [
            'message' => sprintf(
                'It stops Thrive Optimize test %d ("%s") on page %d without a winner: every visitor sees the original page again and results stop counting. Undo: rollback-session restarts it. Call again with confirm:true to stop it.',
                $id,
                (string) $row['title'],
                (int) $row['page_id']
            ),
            'data'    => [ 'test_id' => $id, 'page_id' => (int) $row['page_id'] ],
        ];
    }

    /** Thrive_AB_Test::stop(): the row completed, the page's running test id cleared. */
    private static function stop(array $args, array $context): array
    {
        $id    = (int) $args['test_id'];
        $row   = (array) self::row($id);
        $page  = (int) $row['page_id'];
        $table = Database_Guard::valid_table(self::table('tests'));
        if (is_wp_error($table)) {
            throw new Operation_Refused('thrive_optimize_unavailable', 'Thrive Optimize\'s tests table is missing.');
        }
        $where = [ 'id' => $id ];
        $set   = [ 'status' => 'completed', 'date_completed' => current_time('mysql', true) ];

        $ops   = [];
        $ops[] = self::snapshotted(
            [
                'object_type'         => 'db_rows',
                'object_id'           => $table,
                'extra_snapshot_data' => [
                    'table'       => $table,
                    'operation'   => 'update',
                    'primary_key' => [ 'id' ],
                    'where'       => $where,
                    'set'         => $set,
                    'rows'        => Database_Guard::before_image($table, $where, 1),
                ],
            ],
            $context,
            $args,
            static function () use ($table, $set, $where, $id): void {
                global $wpdb;
                if (false === $wpdb->update($table, $set, $where)) {
                    throw new Operation_Refused('update_failed', sprintf('Could not stop Thrive Optimize test %d: %s', $id, $wpdb->last_error));
                }
                Database_Guard::invalidate_caches($table, [ 'where' => $where ]);
            }
        );
        if ($page > 0 && get_post($page) instanceof \WP_Post) {
            $ops[] = self::snapshotted(
                [ 'object_type' => 'post', 'object_id' => $page ],
                $context,
                $args,
                static fn () => update_post_meta($page, 'thrive_ab_running_test_id', false)
            );
            self::flush_page_cache($page);
        }

        return [ 'result' => [ 'test' => self::test($id) ], 'operation_ids' => $ops ];
    }

    /** @return array{message: string, data: array} */
    private static function winner_preview(int $id, int $variation): array
    {
        $row  = (array) self::row($id);
        $item = (array) self::item_of($id, $variation);
        return [
            'message' => sprintf(
                'It ends Thrive Optimize test %d ("%s") with "%s" as the winner: its design replaces page %d, the other variations are archived and the test completes. This cannot be undone. Call again with confirm:true to pick it.',
                $id,
                (string) $row['title'],
                (string) $item['title'],
                (int) $row['page_id']
            ),
            'data'    => [ 'test_id' => $id, 'variation_id' => $variation, 'page_id' => (int) $row['page_id'] ],
        ];
    }

    /** Thrive Optimize's own winner path, as its "set winner" button and auto-winner call it. */
    private static function pick_winner(int $id, int $variation): array
    {
        $row  = (array) self::row($id);
        $item = (array) self::item_of($id, $variation);
        $done = call_user_func([ 'Thrive_AB_Ajax', 'set_winner' ], [
            'id'           => (int) $item['id'],
            'page_id'      => (int) $row['page_id'],
            'variation_id' => $variation,
            'test_id'      => $id,
        ]);
        if (null === $done) {
            throw new Operation_Refused('winner_failed', sprintf('Thrive Optimize did not pick a winner for test %d: it found no running test on page %d.', $id, (int) $row['page_id']));
        }
        self::flush_page_cache((int) $row['page_id']);
        return [ 'test' => self::test($id), 'note' => 'Not undoable: the page now carries the winner\'s design.' ];
    }

    /** Thrive Architect's page cache purge, as Thrive_AB_Test::start() calls it; a no-op without it. */
    private static function flush_page_cache(int $page): void
    {
        if (function_exists('tve_flush_cache')) {
            tve_flush_cache($page);
        }
    }
}
