<?php

namespace WPMCP\Tests\Free\Analytics;

use WPMCP\Tools\Analytics\Analytics_Adapter;
use WPMCP\Tools\Analytics\Analytics_Plugin_Sources;
use WPMCP\Tools\Analytics\Get_Analytics_Connection_Status;
use WPMCP\Tools\Analytics\Get_Analytics_Summary;
use WPMCP\Tools\Analytics\Get_Top_Pages;

/**
 * Issue #699: get-analytics-summary and get-top-pages read from the
 * analytics plugin the site runs (MonsterInsights, Independent Analytics,
 * Burst Statistics, WP Statistics, Jetpack Stats) when Site Kit is not
 * connected, or from the one named in `source`.
 *
 * The three plugins that keep their numbers on the site are read from their
 * own tables, created here with the columns their current releases define
 * (Independent Analytics 2.16 migrations and Public_API queries, Burst 3.7
 * get_schema_definition(), WP Statistics 14.16 Schema\Manager). The two that
 * fetch numbers from a remote service (MonsterInsights' overview report and
 * Jetpack's WPCOM_Stats) are fed through the wpmcp_analytics_remote_report
 * seam with payloads shaped as the plugins' own code reads them.
 */
class AnalyticsPluginSourcesTest extends \WP_UnitTestCase
{
    /** @var array<string,bool> */
    private array $active = [];

    /** @var array<string,mixed> */
    private array $remote = [];

    /** @var array<int,array<string,mixed>> */
    private array $remote_calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        add_filter('wpmcp_analytics_site_kit_active', '__return_false');
        add_filter('wpmcp_analytics_source_active', [ $this, 'source_active' ], 10, 2);
        add_filter('wpmcp_analytics_remote_report', [ $this, 'remote_report' ], 10, 4);
    }

    protected function tearDown(): void
    {
        global $wpdb;
        remove_filter('wpmcp_analytics_site_kit_active', '__return_false');
        remove_filter('wpmcp_analytics_source_active', [ $this, 'source_active' ], 10);
        remove_filter('wpmcp_analytics_remote_report', [ $this, 'remote_report' ], 10);
        foreach ([ 'independent_analytics_views', 'independent_analytics_sessions', 'independent_analytics_resources', 'burst_statistics', 'statistics_pages', 'statistics_visitor' ] as $table) {
            $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture DDL.
        }
        delete_option('wpmcp_analytics_config');
        parent::tearDown();
    }

    public function source_active($active, string $source): bool
    {
        return $this->active[ $source ] ?? false;
    }

    /** @return mixed */
    public function remote_report($pre, string $source, string $kind, array $args)
    {
        $this->remote_calls[] = [ 'source' => $source, 'kind' => $kind, 'args' => $args ];
        return $this->remote[ $source . ':' . $kind ] ?? $pre;
    }

    // ------------------------------------------------------------- fixtures

    private function seed_independent_analytics(): void
    {
        global $wpdb;
        $p = $wpdb->prefix . 'independent_analytics_';
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture DDL.
        $wpdb->query("CREATE TABLE `{$p}resources` (id bigint(20) UNSIGNED AUTO_INCREMENT, resource varchar(256) NOT NULL, singular_id bigint(20) UNSIGNED, cached_title varchar(256), cached_url varchar(256), cached_type varchar(256), PRIMARY KEY (id))");
        $wpdb->query("CREATE TABLE `{$p}sessions` (session_id bigint(20) UNSIGNED AUTO_INCREMENT, visitor_id bigint(20) UNSIGNED, created_at datetime NOT NULL, total_views int, PRIMARY KEY (session_id))");
        $wpdb->query("CREATE TABLE `{$p}views` (id bigint(20) UNSIGNED AUTO_INCREMENT, resource_id bigint(20) UNSIGNED NOT NULL, viewed_at datetime, page bigint(20) UNSIGNED NOT NULL DEFAULT 1, session_id bigint(20) UNSIGNED, PRIMARY KEY (id))");
        $wpdb->query("INSERT INTO `{$p}resources` (id, resource, singular_id, cached_title, cached_url, cached_type) VALUES (1, 'singular', 10, 'About us', 'https://example.org/about/', 'page'), (2, 'singular', 11, 'Pricing', 'https://example.org/pricing/?ref=mail', 'page')");
        // Two visitors, three sessions; the last view falls outside the range.
        $wpdb->query("INSERT INTO `{$p}sessions` (session_id, visitor_id, created_at) VALUES (1, 100, '2026-03-02 09:00:00'), (2, 100, '2026-03-03 09:00:00'), (3, 200, '2026-03-03 10:00:00'), (4, 300, '2026-04-20 10:00:00')");
        $wpdb->query("INSERT INTO `{$p}views` (resource_id, viewed_at, session_id) VALUES (1, '2026-03-02 09:00:00', 1), (2, '2026-03-02 09:01:00', 1), (2, '2026-03-03 09:00:00', 2), (2, '2026-03-03 10:00:00', 3), (1, '2026-04-20 10:00:00', 4)");
        // phpcs:enable
    }

    private function seed_burst(): void
    {
        global $wpdb;
        $t = $wpdb->prefix . 'burst_statistics';
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture DDL.
        $wpdb->query("CREATE TABLE `{$t}` (`ID` int NOT NULL AUTO_INCREMENT, `page_url` varchar(191) NOT NULL, `page_id` int(11) NOT NULL, `page_type` varchar(191) NOT NULL, `time` int NOT NULL, `uid_id` int unsigned NOT NULL DEFAULT 0, `time_on_page` int, `parameters` TEXT NOT NULL, `fragment` varchar(255) NOT NULL, `session_id` int, PRIMARY KEY (ID))");
        $in  = (new \DateTimeImmutable('2026-03-02 12:00:00', wp_timezone()))->getTimestamp();
        $out = (new \DateTimeImmutable('2026-04-20 12:00:00', wp_timezone()))->getTimestamp();
        $rows = [
            [ '/shop/', 5, $in, 1, 1 ],
            [ '/shop/', 5, $in + 60, 1, 1 ],
            [ '/contact/', 6, $in + 120, 2, 2 ],
            [ '/shop/', 5, $in + 180, 2, 3 ],
            [ '/shop/', 5, $out, 3, 4 ],
        ];
        foreach ($rows as [ $url, $page_id, $time, $uid, $session ]) {
            $wpdb->query($wpdb->prepare("INSERT INTO `{$t}` (page_url, page_id, page_type, time, uid_id, parameters, fragment, session_id) VALUES (%s, %d, 'page', %d, %d, '', '', %d)", $url, $page_id, $time, $uid, $session));
        }
        // phpcs:enable
    }

    private function seed_wp_statistics(): void
    {
        global $wpdb;
        $p = $wpdb->prefix . 'statistics_';
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture DDL.
        $wpdb->query("CREATE TABLE `{$p}pages` (page_id BIGINT(20) NOT NULL AUTO_INCREMENT, uri varchar(190) NOT NULL, type varchar(180) NOT NULL, date date NOT NULL, count int(11) NOT NULL, id int(11) NOT NULL, PRIMARY KEY (page_id))");
        $wpdb->query("CREATE TABLE `{$p}visitor` (ID bigint(20) NOT NULL AUTO_INCREMENT, last_counter date NOT NULL, referred text NOT NULL, agent varchar(180) NOT NULL, ip varchar(60) NOT NULL, user_id BIGINT(40) NOT NULL, hits int(11), PRIMARY KEY (ID))");
        $wpdb->query("INSERT INTO `{$p}pages` (uri, type, date, count, id) VALUES ('/blog/hello/', 'post', '2026-03-02', 7, 1), ('/blog/hello/?utm_source=x', 'post', '2026-03-03', 2, 1), ('/', 'home', '2026-03-03', 4, 0), ('/', 'home', '2026-04-20', 50, 0)");
        $wpdb->query("INSERT INTO `{$p}visitor` (last_counter, referred, agent, ip, user_id, hits) VALUES ('2026-03-02', '', 'Chrome', '#hash#a', 0, 3), ('2026-03-03', '', 'Firefox', '#hash#b', 0, 2), ('2026-04-20', '', 'Chrome', '#hash#c', 0, 1)");
        // phpcs:enable
    }

    // ----------------------------------------------------------- summaries

    public function test_independent_analytics_summary_counts_views_visitors_and_sessions_in_range(): void
    {
        $this->active['independent-analytics'] = true;
        $this->seed_independent_analytics();

        $out = Analytics_Adapter::analytics_summary('2026-03-01', '2026-03-31', 'independent-analytics');

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame('independent-analytics', $out['source']);
        $this->assertSame(4, $out['pageviews']);
        $this->assertSame(2, $out['users']);
        $this->assertSame(3, $out['sessions']);
    }

    public function test_burst_summary_counts_hits_uids_and_sessions_in_range(): void
    {
        $this->active['burst'] = true;
        $this->seed_burst();

        $out = Analytics_Adapter::analytics_summary('2026-03-01', '2026-03-31', 'burst');

        $this->assertSame('burst', $out['source']);
        $this->assertSame(4, $out['pageviews']);
        $this->assertSame(2, $out['users']);
        $this->assertSame(3, $out['sessions']);
    }

    public function test_wp_statistics_summary_sums_page_counts_and_counts_visitors_without_sessions(): void
    {
        $this->active['wp-statistics'] = true;
        $this->seed_wp_statistics();

        $out = Analytics_Adapter::analytics_summary('2026-03-01', '2026-03-31', 'wp-statistics');

        $this->assertSame('wp-statistics', $out['source']);
        $this->assertSame(13, $out['pageviews']);
        $this->assertSame(2, $out['users']);
        $this->assertNull($out['sessions']);
    }

    public function test_monsterinsights_summary_reads_the_overview_report_infobox(): void
    {
        $this->active['monsterinsights'] = true;
        $this->remote['monsterinsights:overview'] = [
            'success' => true,
            'data'    => [
                'infobox'  => [
                    'sessions'   => [ 'value' => '1520', 'prev' => 4 ],
                    'pageviews'  => [ 'value' => 3010, 'prev' => -2 ],
                    'totalusers' => [ 'value' => '1201', 'prev' => 0 ],
                ],
                'toppages' => [],
            ],
        ];

        $out = Analytics_Adapter::analytics_summary('2026-03-01', '2026-03-31', 'monsterinsights');

        $this->assertSame([ 'start' => '2026-03-01', 'end' => '2026-03-31' ], $this->remote_calls[0]['args']);
        $this->assertSame('monsterinsights', $out['source']);
        $this->assertSame(1520, $out['sessions']);
        $this->assertSame(1201, $out['users']);
        $this->assertSame(3010, $out['pageviews']);
    }

    public function test_monsterinsights_report_failure_is_returned_as_an_error(): void
    {
        $this->active['monsterinsights'] = true;
        $this->remote['monsterinsights:overview'] = [ 'success' => false, 'error' => '<b>You must authenticate</b> with MonsterInsights.', 'data' => [] ];

        $out = Analytics_Adapter::analytics_summary('2026-03-01', '2026-03-31', 'monsterinsights');

        $this->assertWPError($out);
        $this->assertSame('wpmcp_analytics_source_error', $out->get_error_code());
        $this->assertStringContainsString('You must authenticate with MonsterInsights.', $out->get_error_message());
    }

    public function test_jetpack_summary_sums_the_daily_visits_series(): void
    {
        $this->active['jetpack'] = true;
        $this->remote['jetpack:visits'] = [
            'unit'   => 'day',
            'fields' => [ 'period', 'views', 'visitors' ],
            'data'   => [ [ '2026-03-30', 10, 4 ], [ '2026-03-31', 15, 6 ] ],
        ];

        $out = Analytics_Adapter::analytics_summary('2026-03-30', '2026-03-31', 'jetpack');

        $this->assertSame([ 'unit' => 'day', 'quantity' => 2, 'date' => '2026-03-31', 'stat_fields' => 'views,visitors' ], $this->remote_calls[0]['args']);
        $this->assertSame('jetpack', $out['source']);
        $this->assertSame(25, $out['pageviews']);
        $this->assertSame(10, $out['users']);
        $this->assertNull($out['sessions']);
    }

    public function test_jetpack_error_passes_through(): void
    {
        $this->active['jetpack'] = true;
        $this->remote['jetpack:visits'] = new \WP_Error('stats_error', 'Site not connected.');

        $out = Analytics_Adapter::analytics_summary('2026-03-30', '2026-03-31', 'jetpack');

        $this->assertWPError($out);
        $this->assertSame('wpmcp_analytics_source_error', $out->get_error_code());
    }

    // ----------------------------------------------------------- top pages

    public function test_independent_analytics_top_pages_ranks_resources_and_drops_query_strings(): void
    {
        $this->active['independent-analytics'] = true;
        $this->seed_independent_analytics();

        $out = Analytics_Adapter::top_pages('2026-03-01', '2026-03-31', 10, 'independent-analytics');

        $this->assertSame([
            [ 'path' => '/pricing/', 'pageviews' => 3, 'title' => 'Pricing' ],
            [ 'path' => '/about/', 'pageviews' => 1, 'title' => 'About us' ],
        ], $out);
    }

    public function test_burst_top_pages_ranks_page_urls_and_respects_limit(): void
    {
        $this->active['burst'] = true;
        $this->seed_burst();

        $this->assertSame(
            [ [ 'path' => '/shop/', 'pageviews' => 3 ], [ 'path' => '/contact/', 'pageviews' => 1 ] ],
            Analytics_Adapter::top_pages('2026-03-01', '2026-03-31', 10, 'burst')
        );
        $this->assertSame(
            [ [ 'path' => '/shop/', 'pageviews' => 3 ] ],
            Analytics_Adapter::top_pages('2026-03-01', '2026-03-31', 1, 'burst')
        );
    }

    public function test_wp_statistics_top_pages_merges_query_string_variants(): void
    {
        $this->active['wp-statistics'] = true;
        $this->seed_wp_statistics();

        $this->assertSame(
            [ [ 'path' => '/blog/hello/', 'pageviews' => 9 ], [ 'path' => '/', 'pageviews' => 4 ] ],
            Analytics_Adapter::top_pages('2026-03-01', '2026-03-31', 10, 'wp-statistics')
        );
    }

    public function test_monsterinsights_top_pages_reads_the_overview_toppages(): void
    {
        $this->active['monsterinsights'] = true;
        $this->remote['monsterinsights:overview'] = [
            'success' => true,
            'data'    => [
                'infobox'  => [],
                'toppages' => [
                    [ 'url' => '/guide/?a=1', 'title' => 'Guide', 'hostname' => 'https://example.org', 'sessions' => '90' ],
                    [ 'url' => '/', 'title' => 'Home', 'hostname' => 'https://example.org', 'sessions' => 40 ],
                    [ 'url' => '/x/', 'title' => 'X', 'hostname' => 'https://example.org', 'sessions' => 5 ],
                ],
            ],
        ];

        $this->assertSame(
            [ [ 'path' => '/guide/', 'pageviews' => 90, 'title' => 'Guide' ], [ 'path' => '/', 'pageviews' => 40, 'title' => 'Home' ] ],
            Analytics_Adapter::top_pages('2026-03-01', '2026-03-31', 2, 'monsterinsights')
        );
    }

    public function test_jetpack_top_pages_reads_the_summarized_postviews(): void
    {
        $this->active['jetpack'] = true;
        $this->remote['jetpack:top-posts'] = [
            'summary' => [
                'postviews' => [
                    [ 'id' => 12, 'href' => 'https://example.org/hello-world/', 'title' => 'Hello world', 'views' => 33 ],
                    [ 'id' => 0, 'href' => 'https://example.org/', 'title' => 'Home page / Archives', 'views' => 20 ],
                ],
            ],
        ];

        $out = Analytics_Adapter::top_pages('2026-03-01', '2026-03-31', 5, 'jetpack');

        $this->assertSame([ 'period' => 'day', 'date' => '2026-03-31', 'num' => 31, 'max' => 5, 'summarize' => 1 ], $this->remote_calls[0]['args']);
        $this->assertSame([
            [ 'path' => '/hello-world/', 'pageviews' => 33, 'title' => 'Hello world' ],
            [ 'path' => '/', 'pageviews' => 20, 'title' => 'Home page / Archives' ],
        ], $out);
    }

    // ------------------------------------------------- routing and refusals

    public function test_without_a_source_the_first_active_plugin_is_used_when_site_kit_is_off(): void
    {
        $this->active['burst'] = true;
        $this->seed_burst();

        $out = ( new Get_Analytics_Summary() )->handle([ 'start_date' => '2026-03-01', 'end_date' => '2026-03-31' ]);

        $this->assertSame('burst', $out['source']);
        $this->assertSame(4, $out['pageviews']);
    }

    public function test_local_plugins_are_preferred_over_remote_ones_when_no_source_is_named(): void
    {
        $this->active['jetpack']       = true;
        $this->active['wp-statistics'] = true;

        $this->assertSame([ 'wp-statistics', 'jetpack' ], Analytics_Plugin_Sources::active());
    }

    public function test_the_tool_passes_source_and_limit_through(): void
    {
        $this->active['burst']         = true;
        $this->active['wp-statistics'] = true;
        $this->seed_wp_statistics();

        $out = ( new Get_Top_Pages() )->handle([ 'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'limit' => 1, 'source' => 'wp-statistics' ]);

        $this->assertSame([ [ 'path' => '/blog/hello/', 'pageviews' => 9 ] ], $out);
    }

    public function test_a_named_inactive_plugin_is_refused_with_the_plugin_named(): void
    {
        $out = Analytics_Adapter::analytics_summary('2026-03-01', '2026-03-31', 'burst');

        $this->assertWPError($out);
        $this->assertSame('plugin_missing', $out->get_error_code());
        $this->assertStringContainsString('Install and activate Burst Statistics', $out->get_error_message());
        $data = $out->get_error_data();
        $this->assertSame('Burst Statistics', $data['plugin_name']);
        $this->assertSame(admin_url('plugins.php'), $data['plugins_url']);
    }

    public function test_an_unknown_source_is_refused(): void
    {
        $out = Analytics_Adapter::top_pages('2026-03-01', '2026-03-31', 10, 'matomo');

        $this->assertWPError($out);
        $this->assertSame('wpmcp_invalid_source', $out->get_error_code());
    }

    public function test_site_kit_stays_the_default_when_it_is_active(): void
    {
        $this->active['burst'] = true;
        update_option('wpmcp_analytics_config', [ 'property_id' => '1', 'site_url' => 'https://example.org' ]);

        $out = Analytics_Adapter::analytics_summary('2026-03-01', '2026-03-31');

        $this->assertArrayNotHasKey('source', $out);
    }

    public function test_nothing_connected_names_the_supported_plugins(): void
    {
        $out = Analytics_Adapter::analytics_summary('2026-03-01', '2026-03-31');

        $this->assertWPError($out);
        $this->assertSame('wpmcp_analytics_not_connected', $out->get_error_code());
        $this->assertStringContainsString('Independent Analytics', $out->get_error_message());
    }

    public function test_connection_status_reports_an_active_plugin_source(): void
    {
        $this->active['independent-analytics'] = true;
        $this->active['jetpack']               = true;

        $status = ( new Get_Analytics_Connection_Status() )->handle([]);

        $this->assertSame('independent-analytics', $status['provider']);
        $this->assertTrue($status['connected']);
        $this->assertSame([ 'independent-analytics', 'jetpack' ], $status['sources']);
    }

    public function test_a_missing_plugin_table_is_an_error_not_a_fatal(): void
    {
        $this->active['independent-analytics'] = true;

        $out = Analytics_Adapter::analytics_summary('2026-03-01', '2026-03-31', 'independent-analytics');

        $this->assertWPError($out);
        $this->assertSame('wpmcp_analytics_source_error', $out->get_error_code());
    }
}
