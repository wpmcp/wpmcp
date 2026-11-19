<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Search_Plugin_Support;
use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Search plugin ops (issue #700): Relevanssi and FiboSearch settings, index
 * status, top searches and settings writes, as free ops on the theme
 * dispatcher pair through Ops_Status_Packs.
 *
 * Neither plugin is loaded in the shared test core. Presence is driven
 * through wpmcp_relevanssi_active and wpmcp_fibosearch_active, and the data
 * is kept the way the plugins keep it: Relevanssi 4.28 in relevanssi_*
 * options, its wp_relevanssi index and wp_relevanssi_log tables (columns from
 * its lib/init.php); FiboSearch 1.34 in the dgwt_wcas_settings option and
 * its wp_dgwt_wcas_stats table (columns from includes/Analytics/Database.php).
 *
 * @group search-plugins
 */
class SearchPluginsStatusTest extends \WP_UnitTestCase
{
    private const OPS = [
        'get-relevanssi-settings'      => 'read',
        'get-relevanssi-index-status'  => 'read',
        'list-relevanssi-top-searches' => 'read',
        'rebuild-relevanssi-index'     => 'destructive',
        'update-relevanssi-settings'   => 'write',
        'get-fibosearch-settings'      => 'read',
        'list-fibosearch-top-searches' => 'read',
        'update-fibosearch-settings'   => 'write',
    ];

    private static string $index = '';
    private static string $log   = '';
    private static string $stats = '';

    private int $admin;

    /** @var list<int> */
    private array $builds = [];

    public static function wpSetUpBeforeClass(): void
    {
        global $wpdb;
        self::$index = $wpdb->prefix . 'relevanssi';
        self::$log   = $wpdb->prefix . 'relevanssi_log';
        self::$stats = $wpdb->prefix . 'dgwt_wcas_stats';
        foreach ([ self::$index, self::$log, self::$stats ] as $table) {
            $wpdb->query('DROP TABLE IF EXISTS `' . $table . '`');
        }
        $wpdb->query('CREATE TABLE `' . self::$index . "` (doc bigint(20) NOT NULL DEFAULT '0', term varchar(50) NOT NULL DEFAULT '0', content mediumint(9) NOT NULL DEFAULT '0', title mediumint(9) NOT NULL DEFAULT '0', type varchar(210) NOT NULL DEFAULT 'post', item bigint(20) NOT NULL DEFAULT '0')");
        $wpdb->query('CREATE TABLE `' . self::$log . "` (id bigint(9) NOT NULL AUTO_INCREMENT, query varchar(200) NOT NULL, hits mediumint(9) NOT NULL DEFAULT '0', time timestamp DEFAULT CURRENT_TIMESTAMP, user_id bigint(20) NOT NULL DEFAULT '0', ip varchar(40) NOT NULL DEFAULT '', session_id varchar(32) NOT NULL DEFAULT '', source varchar(32) NOT NULL DEFAULT '', PRIMARY KEY id (id))");
        $wpdb->query('CREATE TABLE `' . self::$stats . "` (id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT, phrase VARCHAR(255) NOT NULL, hits INT NOT NULL, created_at DATETIME NOT NULL DEFAULT '1000-01-01 00:00:00', autocomplete TINYINT(1) NULL DEFAULT 1, solved TINYINT(1) NULL DEFAULT 0, lang VARCHAR(10) NULL, PRIMARY KEY (id))");
    }

    public static function wpTearDownAfterClass(): void
    {
        global $wpdb;
        foreach ([ self::$index, self::$log, self::$stats ] as $table) {
            $wpdb->query('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        global $wpdb;
        foreach ([ self::$index, self::$log, self::$stats ] as $table) {
            $wpdb->query('DELETE FROM `' . $table . '`');
        }

        update_option('relevanssi_implicit_operator', 'OR');
        update_option('relevanssi_fuzzy', 'always');
        update_option('relevanssi_min_word_length', '3');
        update_option('relevanssi_index_post_types', [ 'post', 'page' ]);
        update_option('relevanssi_title_boost', 5);
        update_option('relevanssi_log_queries', 'on');
        update_option('relevanssi_log_queries_with_ip', 'on');
        update_option('relevanssi_indexed', 'done');
        update_option('relevanssi_doc_count', 42);
        update_option('relevanssi_terms_count', 900);
        update_option('dgwt_wcas_settings', [
            'min_chars'          => '3',
            'search_placeholder' => 'Search for products...',
            'analytics_enabled'  => 'on',
            'show_submit_button' => 'off',
            'bg_submit_color'    => '#ffffff',
        ]);

        add_filter('wpmcp_relevanssi_active', '__return_true');
        add_filter('wpmcp_fibosearch_active', '__return_true');
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        wp_set_current_user($this->admin);
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_relevanssi_active');
        remove_all_filters('wpmcp_fibosearch_active');
        remove_all_filters('wpmcp_relevanssi_build_index_callback');
        remove_all_filters(Search_Plugin_Support::ENABLE_FILTER);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function catalog(): array
    {
        return array_column((new Theme_Integration())->catalog()['operations'], null, 'name');
    }

    private function read(string $op, array $args = []): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args = [], bool $confirm = false): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        if ($confirm) {
            $call['confirm'] = true;
        }
        return (new Theme_Integration())->handle_write($call);
    }

    private function enable_writes(): void
    {
        add_filter(Search_Plugin_Support::ENABLE_FILTER, '__return_true');
    }

    /** Stand-in for relevanssi_build_index(), recording each call. */
    public function record_build($extend_offset = false, $verbose = null): array
    {
        $this->builds[] = (int) $extend_offset;
        return [ true, 7 ];
    }

    private function log_search(string $query, int $hits, string $time, string $ip = '203.0.113.9'): void
    {
        global $wpdb;
        $wpdb->insert(self::$log, [ 'query' => $query, 'hits' => $hits, 'time' => $time, 'user_id' => $this->admin, 'ip' => $ip, 'session_id' => 'sess-abc', 'source' => 'search' ]);
    }

    private function stat(string $phrase, int $hits, string $time): void
    {
        global $wpdb;
        $wpdb->insert(self::$stats, [ 'phrase' => $phrase, 'hits' => $hits, 'created_at' => $time, 'autocomplete' => 1, 'solved' => 0, 'lang' => 'en' ]);
    }

    // ---------------------------------------------------------------
    // Catalog and presence
    // ---------------------------------------------------------------

    public function test_every_op_is_a_free_manage_options_op_on_the_theme_pair(): void
    {
        Gate::set_pro_for_tests(false);
        $ops = $this->catalog();

        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops, "{$name} must be on the theme pair without a license");
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertSame('manage_options', $ops[ $name ]['capability'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
        }
        $this->assertTrue($ops['rebuild-relevanssi-index']['requires_confirm']);
    }

    public function test_every_op_is_skipped_cleanly_while_its_plugin_is_inactive(): void
    {
        remove_all_filters('wpmcp_relevanssi_active');
        remove_all_filters('wpmcp_fibosearch_active');
        add_filter('wpmcp_relevanssi_active', '__return_false');
        add_filter('wpmcp_fibosearch_active', '__return_false');
        add_filter('wpmcp_relevanssi_build_index_callback', fn () => [ $this, 'record_build' ]);
        $this->enable_writes();
        $rows = Snapshot_Store::row_count();

        foreach (self::OPS as $op => $mode) {
            $this->assertFalse($this->catalog()[ $op ]['dependency_met'], "{$op} must be flagged while its plugin is inactive");
            $code = str_contains($op, 'relevanssi') ? 'relevanssi_inactive' : 'fibosearch_inactive';
            $args = str_starts_with($op, 'update-') ? [ 'settings' => [ 'min_chars' => '2' ] ] : [];
            $out  = 'read' === $mode ? $this->read($op, $args) : $this->write($op, $args, true);
            $this->assertSame($code, $out['error']['code'] ?? null, "{$op} must answer {$code}");
            $this->assertStringContainsString('Install and activate', (string) $out['error']['message']);
        }
        $this->assertSame([], $this->builds);
        $this->assertSame($rows, Snapshot_Store::row_count());
    }

    public function test_presence_defaults_to_each_plugins_constant(): void
    {
        remove_all_filters('wpmcp_relevanssi_active');
        remove_all_filters('wpmcp_fibosearch_active');

        $this->assertSame(defined('RELEVANSSI_PREMIUM'), $this->catalog()['get-relevanssi-settings']['dependency_met']);
        $this->assertSame(defined('DGWT_WCAS_VERSION'), $this->catalog()['get-fibosearch-settings']['dependency_met']);
    }

    public function test_every_op_refuses_a_user_without_manage_options(): void
    {
        $this->enable_writes();
        add_filter('wpmcp_relevanssi_build_index_callback', fn () => [ $this, 'record_build' ]);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));

        foreach (self::OPS as $op => $mode) {
            $out = 'read' === $mode ? $this->read($op) : $this->write($op, [ 'settings' => [ 'min_chars' => '2' ] ], true);
            $this->assertContains($out['error']['code'] ?? null, [ 'operation_denied', 'forbidden' ], "{$op} runs at manage_options");
        }
        $this->assertSame([], $this->builds);
        $this->assertSame('3', get_option('dgwt_wcas_settings')['min_chars']);
    }

    // ---------------------------------------------------------------
    // Relevanssi reads
    // ---------------------------------------------------------------

    public function test_relevanssi_settings_report_the_search_and_index_settings(): void
    {
        $out = $this->read('get-relevanssi-settings');

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $settings = $out['result']['settings'];
        $this->assertSame('OR', $settings['implicit_operator']);
        $this->assertSame('always', $settings['fuzzy']);
        $this->assertSame('3', $settings['min_word_length']);
        $this->assertSame([ 'post', 'page' ], $settings['index_post_types']);
        $this->assertSame(5, $settings['title_boost']);
        $this->assertArrayNotHasKey('indexed', $settings, 'internal state is reported by the index status read');
    }

    public function test_relevanssi_index_status_reports_counts_and_state(): void
    {
        global $wpdb;
        $wpdb->insert(self::$index, [ 'doc' => 1, 'term' => 'hello', 'content' => 1, 'title' => 1, 'type' => 'post', 'item' => 0 ]);
        $wpdb->insert(self::$index, [ 'doc' => 1, 'term' => 'world', 'content' => 1, 'title' => 0, 'type' => 'post', 'item' => 0 ]);
        $wpdb->insert(self::$index, [ 'doc' => 2, 'term' => 'hello', 'content' => 2, 'title' => 0, 'type' => 'post', 'item' => 0 ]);

        $out = $this->read('get-relevanssi-index-status');

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $status = $out['result'];
        $this->assertTrue($status['indexed']);
        $this->assertSame(42, $status['doc_count']);
        $this->assertSame(900, $status['terms_count']);
        $this->assertSame(2, $status['indexed_documents'], 'counted live from the index table');
        $this->assertSame(3, $status['index_rows']);
        $this->assertSame([ 'post', 'page' ], $status['post_types']);
        $this->assertTrue($status['logging_enabled']);
    }

    public function test_relevanssi_top_searches_are_aggregated_and_carry_no_visitor_data(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->log_search('shoes', 12, $now);
        $this->log_search('shoes', 10, $now);
        $this->log_search('shoes', 11, $now);
        $this->log_search('red hat', 0, $now);
        $this->log_search('red hat', 0, $now);
        $this->log_search('jane.doe@example.com', 0, $now);
        $this->log_search('call 5551234567', 0, $now);
        $this->log_search('old query', 3, gmdate('Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS));

        $out = $this->read('list-relevanssi-top-searches', [ 'days' => 30 ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $rows = $out['result']['searches'];
        $this->assertSame('shoes', $rows[0]['query']);
        $this->assertSame(3, $rows[0]['searches']);
        $this->assertSame(12, $rows[0]['hits']);
        $this->assertSame('red hat', $rows[1]['query']);
        $this->assertNotContains('old query', array_column($rows, 'query'), 'outside the window');
        $this->assertContains('j***@example.com', array_column($rows, 'query'), 'an email typed into search is masked');
        $this->assertContains('c***', array_column($rows, 'query'), 'a phone number typed into search is masked');
        $this->assertTrue($out['result']['masked']);
        $this->assertTrue($out['result']['logging_enabled']);
        $json = (string) wp_json_encode($out);
        $this->assertStringNotContainsString('203.0.113.9', $json);
        $this->assertStringNotContainsString('sess-abc', $json);
        $this->assertStringNotContainsString('jane.doe', $json);
        $this->assertStringNotContainsString('5551234567', $json);

        $zero = $this->read('list-relevanssi-top-searches', [ 'days' => 30, 'zero_hits' => true ])['result']['searches'];
        $this->assertNotContains('shoes', array_column($zero, 'query'));
        $this->assertSame('red hat', $zero[0]['query']);

        $one = $this->read('list-relevanssi-top-searches', [ 'days' => 30, 'limit' => 1 ])['result']['searches'];
        $this->assertCount(1, $one);
    }

    public function test_relevanssi_top_searches_report_logging_off_without_the_log_table(): void
    {
        global $wpdb;
        $wpdb->query('DROP TABLE IF EXISTS `' . self::$log . '`');
        update_option('relevanssi_log_queries', 'off');

        $out = $this->read('list-relevanssi-top-searches');

        self::wpSetUpBeforeClass();
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame([], $out['result']['searches']);
        $this->assertFalse($out['result']['logging_enabled']);
    }

    // ---------------------------------------------------------------
    // rebuild-relevanssi-index
    // ---------------------------------------------------------------

    public function test_rebuild_needs_confirm_runs_relevanssis_own_indexer_and_is_not_undoable(): void
    {
        add_filter('wpmcp_relevanssi_build_index_callback', fn () => [ $this, 'record_build' ]);

        $refused = $this->write('rebuild-relevanssi-index');
        $this->assertSame('confirmation_required', $refused['error']['code']);
        $this->assertSame([], $this->builds);

        $rows = Snapshot_Store::row_count();
        $out  = $this->write('rebuild-relevanssi-index', [], true);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame([ 0 ], $this->builds, 'a full rebuild from offset zero');
        $this->assertTrue($out['result']['complete']);
        $this->assertSame(7, $out['result']['indexed']);
        $this->assertFalse($out['recoverable'], 'the index is derived data; there is no before-image to restore');
        $this->assertSame($rows, Snapshot_Store::row_count());
    }

    public function test_rebuild_refuses_without_relevanssis_indexer(): void
    {
        add_filter('wpmcp_relevanssi_build_index_callback', '__return_null');

        $out = $this->write('rebuild-relevanssi-index', [], true);

        $this->assertSame('relevanssi_index_unavailable', $out['error']['code']);
    }

    // ---------------------------------------------------------------
    // update-relevanssi-settings
    // ---------------------------------------------------------------

    public function test_relevanssi_settings_write_is_off_until_the_site_opts_in(): void
    {
        $this->assertFalse($this->catalog()['update-relevanssi-settings']['enabled']);
        $rows = Snapshot_Store::row_count();

        $out = $this->write('update-relevanssi-settings', [ 'settings' => [ 'implicit_operator' => 'AND' ] ]);

        $this->assertSame('opt_in_required', $out['error']['code']);
        $this->assertStringContainsString(Search_Plugin_Support::ENABLE_FILTER, $out['error']['message']);
        $this->assertSame('OR', get_option('relevanssi_implicit_operator'));
        $this->assertSame($rows, Snapshot_Store::row_count());
    }

    public function test_relevanssi_settings_write_is_snapshotted_and_rolls_back_exactly(): void
    {
        $this->enable_writes();
        delete_option('relevanssi_exact_match_bonus');

        $out = $this->write('update-relevanssi-settings', [ 'settings' => [
            'implicit_operator'  => 'AND',
            'min_word_length'    => 2,
            'index_post_types'   => [ 'post', 'page', 'attachment' ],
            'title_boost'        => 7.5,
            'exact_match_bonus'  => 'off',
        ] ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('AND', get_option('relevanssi_implicit_operator'));
        $this->assertSame('2', get_option('relevanssi_min_word_length'));
        $this->assertSame([ 'post', 'page', 'attachment' ], get_option('relevanssi_index_post_types'));
        $this->assertSame('off', get_option('relevanssi_exact_match_bonus'));
        $this->assertTrue($out['result']['rebuild_needed'], 'post types and word length change what is indexed');
        $this->assertEqualsCanonicalizing([ 'implicit_operator', 'min_word_length', 'index_post_types', 'title_boost', 'exact_match_bonus' ], $out['result']['changed']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame('OR', get_option('relevanssi_implicit_operator'));
        $this->assertSame('3', get_option('relevanssi_min_word_length'));
        $this->assertSame([ 'post', 'page' ], get_option('relevanssi_index_post_types'));
        $this->assertSame(5, (int) get_option('relevanssi_title_boost'));
        $this->assertFalse(get_option('relevanssi_exact_match_bonus'), 'an option the write created is removed again');
    }

    public function test_relevanssi_settings_write_refuses_unknown_or_invalid_values_without_side_effects(): void
    {
        $this->enable_writes();
        $rows = Snapshot_Store::row_count();

        foreach ([
            [ 'indexed' => 'done' ],
            [ 'log_queries_with_ip' => 'on' ],
            [ 'implicit_operator' => 'XOR' ],
            [ 'min_word_length' => 0 ],
            [ 'index_post_types' => [ 'no_such_type' ] ],
            [ 'exclude_posts' => '1, 2; DROP' ],
            [ 'title_boost' => 'lots' ],
        ] as $settings) {
            $out = $this->write('update-relevanssi-settings', [ 'settings' => $settings ]);
            $this->assertSame('invalid_setting', $out['error']['code'] ?? null, (string) wp_json_encode($settings));
        }
        $this->assertSame('OR', get_option('relevanssi_implicit_operator'));
        $this->assertSame($rows, Snapshot_Store::row_count());
    }

    // ---------------------------------------------------------------
    // FiboSearch
    // ---------------------------------------------------------------

    public function test_fibosearch_settings_report_the_stored_settings(): void
    {
        $out = $this->read('get-fibosearch-settings');

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame('3', $out['result']['settings']['min_chars']);
        $this->assertSame('Search for products...', $out['result']['settings']['search_placeholder']);
        $this->assertTrue($out['result']['analytics_enabled']);
    }

    public function test_fibosearch_top_searches_are_aggregated_and_masked(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->stat('hoodie', 4, $now);
        $this->stat('hoodie', 4, $now);
        $this->stat('unicorn', 0, $now);
        $this->stat('bob@example.org', 0, $now);
        $this->stat('ancient', 1, gmdate('Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS));

        $out = $this->read('list-fibosearch-top-searches', [ 'days' => 365 ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $rows = $out['result']['searches'];
        $this->assertSame([ 'query' => 'hoodie', 'searches' => 2, 'hits' => 4 ], array_intersect_key($rows[0], array_flip([ 'query', 'searches', 'hits' ])));
        $this->assertNotContains('ancient', array_column($rows, 'query'));
        $this->assertContains('b***@example.org', array_column($rows, 'query'));
        $this->assertTrue($out['result']['masked']);

        $zero = $this->read('list-fibosearch-top-searches', [ 'zero_hits' => true ])['result']['searches'];
        $this->assertEqualsCanonicalizing([ 'b***@example.org', 'unicorn' ], array_column($zero, 'query'));
        $this->assertNotContains('hoodie', array_column($zero, 'query'));
    }

    public function test_fibosearch_settings_write_is_opt_in_snapshotted_and_rolls_back_exactly(): void
    {
        $before = get_option('dgwt_wcas_settings');

        $refused = $this->write('update-fibosearch-settings', [ 'settings' => [ 'min_chars' => '2' ] ]);
        $this->assertSame('opt_in_required', $refused['error']['code']);
        $this->assertSame($before, get_option('dgwt_wcas_settings'));

        $this->enable_writes();
        $out = $this->write('update-fibosearch-settings', [ 'settings' => [ 'min_chars' => '2', 'search_placeholder' => 'Find a <b>product</b>' ] ]);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $after = get_option('dgwt_wcas_settings');
        $this->assertSame('2', $after['min_chars']);
        $this->assertSame('Find a product', $after['search_placeholder'], 'text is sanitized like the settings screen');
        $this->assertSame('on', $after['analytics_enabled'], 'other settings are kept');

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, get_option('dgwt_wcas_settings'));
    }

    public function test_fibosearch_settings_write_refuses_unknown_keys_and_non_text_values(): void
    {
        $this->enable_writes();
        $rows = Snapshot_Store::row_count();

        foreach ([ [ 'no_such_setting' => 'x' ], [ 'min_chars' => [ 'a' ] ], [] ] as $settings) {
            $out = $this->write('update-fibosearch-settings', [ 'settings' => $settings ]);
            $this->assertArrayHasKey('error', $out, (string) wp_json_encode($settings));
        }
        $this->assertSame('3', get_option('dgwt_wcas_settings')['min_chars']);
        $this->assertSame($rows, Snapshot_Store::row_count());
    }
}
