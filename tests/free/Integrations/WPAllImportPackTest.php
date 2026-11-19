<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\WP_All_Import_Pack;

require_once __DIR__ . '/../../support/wp-all-import-stubs.php';

/**
 * WP All Import and WP All Export (issue #909): read-only free-tier ops on
 * the plugin-data dispatcher pair, so they add no top-level tool.
 *
 * Neither plugin is loaded in the shared test core. Presence is driven
 * through wpmcp_wp_all_import_active and wpmcp_wp_all_export_active, and the
 * storage is built the way the plugins create it
 * (tests/support/wp-all-import-stubs.php).
 */
class WPAllImportPackTest extends \WP_UnitTestCase
{
    private const SECRET = 'SENTINEL-FTP-PASSWORD';

    private const KEY = 'SENTINEL-PRIVATE-KEY';

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_wp_all_import_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_wp_all_import_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        global $wpdb;
        foreach (wpmcp_test_wp_all_import_tables() as $table) {
            $wpdb->query("DELETE FROM `{$table}`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
        add_filter('wpmcp_wp_all_import_active', '__return_true');
        add_filter('wpmcp_wp_all_export_active', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_wp_all_import_active');
        remove_all_filters('wpmcp_wp_all_export_active');
        $this->remove_logs();
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out['result'] ?? $out;
    }

    private function code(array $out): ?string
    {
        return $out['error']['code'] ?? $out['code'] ?? null;
    }

    private function logs_dir(): string
    {
        $uploads = wp_upload_dir();
        return $uploads['basedir'] . '/wpallimport/logs';
    }

    private function remove_logs(): void
    {
        $dir = $this->logs_dir();
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
        @rmdir(dirname($dir)); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
    }

    public function test_the_ops_are_free_tier_reads_that_need_manage_options(): void
    {
        $ops = WP_All_Import_Pack::operations();
        $this->assertSame(
            [ 'wpallimport-list-imports', 'wpallimport-get-import', 'wpallimport-list-runs', 'wpallimport-get-run', 'wpallexport-list-exports', 'wpallexport-get-export' ],
            array_keys($ops)
        );
        foreach ($ops as $def) {
            $this->assertSame('read', $def['mode']);
            $this->assertSame('manage_options', $def['capability']);
            $this->assertArrayNotHasKey('tier', $def);
        }
    }

    public function test_a_lower_role_is_refused_every_op(): void
    {
        $import = wpmcp_test_pmxi_import();
        $run    = wpmcp_test_pmxi_history($import);
        $export = wpmcp_test_pmxe_export();
        $calls  = [
            [ 'wpallimport-list-imports', [] ],
            [ 'wpallimport-get-import', [ 'import_id' => $import ] ],
            [ 'wpallimport-list-runs', [ 'import_id' => $import ] ],
            [ 'wpallimport-get-run', [ 'import_id' => $import, 'run_id' => $run ] ],
            [ 'wpallexport-list-exports', [] ],
            [ 'wpallexport-get-export', [ 'export_id' => $export ] ],
        ];
        foreach ([ 'editor', 'author', 'subscriber' ] as $role) {
            wp_set_current_user(self::factory()->user->create([ 'role' => $role ]));
            foreach ($calls as [ $op, $args ]) {
                $this->assertSame('operation_denied', $this->code($this->read($op, $args)), $role . ' ' . $op);
            }
        }
    }

    public function test_each_plugin_gates_only_its_own_ops(): void
    {
        remove_all_filters('wpmcp_wp_all_import_active');
        add_filter('wpmcp_wp_all_import_active', '__return_false');
        $out = $this->read('wpallimport-list-imports');
        $this->assertContains($this->code($out), [ 'plugin_missing', 'plugin_inactive' ]);
        $this->assertStringContainsString('WP All Import', (string) wp_json_encode($out));
        $this->ok($this->read('wpallexport-list-exports'));

        remove_all_filters('wpmcp_wp_all_import_active');
        remove_all_filters('wpmcp_wp_all_export_active');
        add_filter('wpmcp_wp_all_import_active', '__return_true');
        add_filter('wpmcp_wp_all_export_active', '__return_false');
        $out = $this->read('wpallexport-list-exports');
        $this->assertContains($this->code($out), [ 'plugin_missing', 'plugin_inactive' ]);
        $this->assertStringContainsString('WP All Export', (string) wp_json_encode($out));
        $this->ok($this->read('wpallimport-list-imports'));
    }

    public function test_list_imports_shows_source_post_type_schedule_and_last_run_with_credentials_masked(): void
    {
        $url = wpmcp_test_pmxi_import(
            [ 'friendly_name' => 'Feed', 'type' => 'url', 'path' => 'https://ada:' . self::SECRET . '@feeds.example.com/p.xml?api_key=SENTINEL-QUERY-KEY&lang=en', 'feed_type' => 'xml' ],
            [ 'custom_type' => 'product', 'scheduling_enable' => 1, 'scheduling_run_on' => 'weekly', 'scheduling_weekly_days' => '0,3', 'scheduling_times' => [ '9:00 AM' ], 'scheduling_timezone' => 'Europe/London' ]
        );
        $ftp = wpmcp_test_pmxi_import(
            [ 'friendly_name' => 'Supplier FTP', 'type' => 'ftp', 'path' => 'sftp://bob:' . self::SECRET . '@ftp.example.com/stock.csv', 'last_activity' => '2026-11-14 08:00:00' ],
            [ 'custom_type' => 'post', 'ftp_host' => 'sftp://ftp.example.com', 'ftp_path' => '/stock.csv', 'ftp_port' => 22, 'ftp_username' => 'bob', 'ftp_password' => self::SECRET, 'ftp_private_key' => self::KEY ]
        );
        wpmcp_test_pmxi_history($url, [ 'date' => '2026-11-10 08:00:00', 'summary' => 'old run' ]);
        wpmcp_test_pmxi_history($url, [ 'date' => '2026-11-15 08:00:00', 'summary' => '100 products created 20 updated 0 skipped', 'time_run' => '42', 'type' => 'trigger' ]);

        $list = $this->ok($this->read('wpallimport-list-imports'));
        $json = (string) wp_json_encode($list);
        $this->assertSame(2, $list['total']);
        $this->assertStringNotContainsString(self::SECRET, $json);
        $this->assertStringNotContainsString(self::KEY, $json);
        $this->assertStringNotContainsString('SENTINEL-QUERY-KEY', $json);

        $by = array_column($list['imports'], null, 'id');
        $this->assertSame('Feed', $by[ $url ]['name']);
        $this->assertSame('url', $by[ $url ]['source_type']);
        $this->assertSame('xml', $by[ $url ]['feed_type']);
        $this->assertSame('product', $by[ $url ]['post_type']);
        $this->assertStringContainsString('feeds.example.com/p.xml', $by[ $url ]['source']);
        $this->assertStringContainsString('lang=en', $by[ $url ]['source']);
        $this->assertStringNotContainsString('ada', $by[ $url ]['source']);
        $this->assertTrue($by[ $url ]['schedule']['enabled']);
        $this->assertSame('weekly', $by[ $url ]['schedule']['run_on']);
        $this->assertSame([ '9:00 AM' ], $by[ $url ]['schedule']['times']);
        $this->assertSame('Europe/London', $by[ $url ]['schedule']['timezone']);
        $this->assertSame('2026-11-15 08:00:00', $by[ $url ]['last_run']['date']);
        $this->assertSame('100 products created 20 updated 0 skipped', $by[ $url ]['last_run']['summary']);
        $this->assertSame('trigger', $by[ $url ]['last_run']['type']);
        $this->assertSame(42, $by[ $url ]['last_run']['seconds']);
        $this->assertSame([ 'records' => 120, 'imported' => 120, 'created' => 100, 'updated' => 20, 'skipped' => 0, 'deleted' => 0 ], $by[ $url ]['counts']);
        $this->assertSame('idle', $by[ $url ]['status']);

        $this->assertSame('ftp', $by[ $ftp ]['source_type']);
        $this->assertSame('post', $by[ $ftp ]['post_type']);
        $this->assertNull($by[ $ftp ]['last_run']);
        $this->assertStringContainsString('ftp.example.com/stock.csv', $by[ $ftp ]['source']);
        $this->assertSame([ $url, $ftp ], array_column($list['imports'], 'id'), 'most recent activity first');
    }

    public function test_get_import_returns_mapping_and_settings_and_never_ftp_secrets(): void
    {
        $id = wpmcp_test_pmxi_import(
            [ 'type' => 'ftp', 'path' => 'ftp.example.com/stock.csv' ],
            [ 'ftp_host' => 'ftp://carol:' . self::SECRET . '@ftp.example.com', 'ftp_path' => '/stock.csv', 'ftp_port' => 21, 'ftp_username' => 'carol', 'ftp_password' => self::SECRET, 'ftp_private_key' => self::KEY, 'is_delete_missing' => 1 ]
        );

        $out  = $this->ok($this->read('wpallimport-get-import', [ 'import_id' => $id ]));
        $json = (string) wp_json_encode($out);
        $this->assertStringNotContainsString(self::SECRET, $json);
        $this->assertStringNotContainsString(self::KEY, $json);
        $this->assertStringNotContainsString('carol', $json);

        $import = $out['import'];
        $this->assertSame($id, $import['id']);
        $this->assertSame('ftp', $import['source_type']);
        $this->assertSame('ftp.example.com', wp_parse_url($import['ftp']['host'], PHP_URL_HOST));
        $this->assertSame('/stock.csv', $import['ftp']['path']);
        $this->assertSame(21, $import['ftp']['port']);
        $this->assertTrue($import['ftp']['has_username']);
        $this->assertTrue($import['ftp']['has_password']);
        $this->assertTrue($import['ftp']['has_private_key']);

        $this->assertSame('{title[1]}', $import['mapping']['title']);
        $this->assertSame('{description[1]}', $import['mapping']['content']);
        $this->assertSame([ '_sku' => '{sku[1]}', '_price' => '{price[1]}' ], $import['mapping']['custom_fields']);
        $this->assertSame('/node', $import['xpath']);
        $this->assertSame('{sku[1]}', $import['settings']['unique_key']);
        $this->assertSame('publish', $import['settings']['status']);
        $this->assertTrue($import['settings']['delete_missing']);
        $this->assertTrue($import['settings']['create_new_records']);
        $this->assertSame('yes', $import['settings']['update_all_data']);
        $this->assertTrue($import['settings']['update']['title']);
        $this->assertArrayNotHasKey('options', $import, 'the raw options are never returned');
    }

    public function test_get_import_refuses_an_unknown_id(): void
    {
        $this->assertSame('import_not_found', $this->code($this->read('wpallimport-get-import', [ 'import_id' => 999999 ])));
        $this->assertSame('import_not_found', $this->code($this->read('wpallimport-list-runs', [ 'import_id' => 999999 ])));
    }

    public function test_runs_are_listed_newest_first_and_the_last_run_log_shows_errors_masked(): void
    {
        $id  = wpmcp_test_pmxi_import();
        $old = wpmcp_test_pmxi_history($id, [ 'date' => '2026-11-10 08:00:00', 'summary' => 'old run' ]);
        $new = wpmcp_test_pmxi_history($id, [ 'date' => '2026-11-15 08:00:00', 'summary' => 'new run' ]);

        $runs = $this->ok($this->read('wpallimport-list-runs', [ 'import_id' => $id ]));
        $this->assertSame([ $new, $old ], array_column($runs['runs'], 'id'));
        $this->assertSame(2, $runs['total']);

        // The plain log path, as WP All Import writes it when its secure mode is off.
        wp_mkdir_p($this->logs_dir());
        file_put_contents(
            $this->logs_dir() . '/' . $new . '.html',
            "<div class='progress-msg'>[08:00:01] Starting import</div>\n"
            . "<div class='progress-msg'>[08:00:02] <b>CREATING</b> `Blue shirt` `product`</div>\n"
            . "<div class='progress-msg'>[08:00:03] <b>WARNING</b>: Image https://cdn.example.com/a.jpg?token=SENTINEL-LOG-TOKEN0123456789abcdef not found</div>\n"
            . "<div class='progress-msg'>[08:00:04] <b>ERROR</b>: Could not email dave@example.com about record 7</div>\n"
        );

        $run = $this->ok($this->read('wpallimport-get-run', [ 'import_id' => $id ]));
        $this->assertSame($new, $run['run']['id'], 'latest run by default');
        $this->assertSame('new run', $run['run']['summary']);
        $this->assertTrue($run['log']['found']);
        $this->assertSame(1, $run['log']['errors']);
        $this->assertSame(1, $run['log']['warnings']);
        $json = (string) wp_json_encode($run);
        $this->assertStringNotContainsString('SENTINEL-LOG-TOKEN', $json);
        $this->assertStringNotContainsString('dave@example.com', $json);
        $this->assertCount(2, $run['log']['issues']);
        $this->assertSame('warning', $run['log']['issues'][0]['level']);
        $this->assertSame('08:00:03', $run['log']['issues'][0]['time']);
        $this->assertSame('error', $run['log']['issues'][1]['level']);
        $this->assertStringContainsString('Could not email', $run['log']['issues'][1]['message']);
        $this->assertStringNotContainsString('<b>', $run['log']['issues'][1]['message']);

        $older = $this->ok($this->read('wpallimport-get-run', [ 'import_id' => $id, 'run_id' => $old ]));
        $this->assertSame($old, $older['run']['id']);
        $this->assertFalse($older['log']['found']);

        $this->assertSame('run_not_found', $this->code($this->read('wpallimport-get-run', [ 'import_id' => $id, 'run_id' => 999999 ])));
    }

    public function test_the_secure_log_path_is_read_too(): void
    {
        $id  = wpmcp_test_pmxi_import();
        $run = wpmcp_test_pmxi_history($id);
        $dir = $this->logs_dir() . '/' . md5($run . (defined('NONCE_SALT') ? NONCE_SALT : wp_salt('nonce')));
        wp_mkdir_p($dir);
        file_put_contents($dir . '/' . $run . '.html', "<div class='progress-msg'>[08:00:04] <b>ERROR</b>: broken row</div>\n");

        $out = $this->ok($this->read('wpallimport-get-run', [ 'import_id' => $id ]));
        $this->assertTrue($out['log']['found']);
        $this->assertSame(1, $out['log']['errors']);
    }

    public function test_exports_are_listed_and_read_without_custom_code(): void
    {
        $plain = wpmcp_test_pmxe_export([ 'last_activity' => '2026-11-12 07:00:00' ]);
        $coded = wpmcp_test_pmxe_export(
            [ 'friendly_name' => 'Orders nightly', 'exported' => 7 ],
            [
                'cpt'                   => [ 'shop_order' ],
                'export_to'             => 'xml',
                'cc_label'              => [ 'ID', 'Email' ],
                'cc_type'               => [ 'id', 'cf' ],
                'cc_name'               => [ 'ID', 'Billing email' ],
                'cc_php'                => [ 0, 1 ],
                'cc_code'               => [ '', 'SENTINEL_PHP_CODE()' ],
                'cc_sql'                => [ '', 'SELECT SENTINEL_SQL' ],
                'whereclause'           => ' AND SENTINEL_WHERE',
                'scheduled_email'       => 'erin@example.com',
                'is_scheduled'          => 1,
                'scheduled_period'      => 'daily',
                'export_only_new_stuff' => 1,
            ]
        );

        $list = $this->ok($this->read('wpallexport-list-exports'));
        $this->assertSame(2, $list['total']);
        $this->assertSame([ $coded, $plain ], array_column($list['exports'], 'id'));
        $by = array_column($list['exports'], null, 'id');
        $this->assertSame('Orders nightly', $by[ $coded ]['name']);
        $this->assertSame([ 'shop_order' ], $by[ $coded ]['post_types']);
        $this->assertSame('xml', $by[ $coded ]['format']);
        $this->assertSame(7, $by[ $coded ]['exported']);
        $this->assertSame('daily', $by[ $coded ]['schedule']['period']);
        $this->assertSame('products.csv', $by[ $plain ]['file']);
        $this->assertStringNotContainsString('erin@example.com', (string) wp_json_encode($list), 'a list never shows the address');

        $out  = $this->ok($this->read('wpallexport-get-export', [ 'export_id' => $coded ]));
        $json = (string) wp_json_encode($out);
        foreach ([ 'SENTINEL_PHP_CODE', 'SENTINEL_SQL', 'SENTINEL_WHERE' ] as $secret) {
            $this->assertStringNotContainsString($secret, $json, $secret);
        }
        $export = $out['export'];
        $this->assertSame([ [ 'label' => 'ID', 'type' => 'id', 'name' => 'ID', 'php' => false ], [ 'label' => 'Email', 'type' => 'cf', 'name' => 'Billing email', 'php' => true ] ], $export['fields']);
        $this->assertTrue($export['settings']['only_new']);
        $this->assertTrue($export['settings']['has_custom_where']);
        $this->assertSame('erin@example.com', $export['schedule']['email'], 'a single read is full at manage_options');

        $this->assertSame('export_not_found', $this->code($this->read('wpallexport-get-export', [ 'export_id' => 999999 ])));
    }

    public function test_missing_tables_are_named(): void
    {
        // The test case turns DROP TABLE into DROP TEMPORARY TABLE; these tables are real.
        remove_filter('query', [ $this, '_drop_temporary_tables' ]);
        remove_filter('query', [ $this, '_create_temporary_tables' ]);
        wpmcp_test_drop_wp_all_import_tables();
        try {
            $this->assertSame('wp_all_import_tables_missing', $this->code($this->read('wpallimport-list-imports')));
            $this->assertSame('wp_all_import_tables_missing', $this->code($this->read('wpallexport-list-exports')));
        } finally {
            wpmcp_test_create_wp_all_import_tables();
            add_filter('query', [ $this, '_create_temporary_tables' ]);
            add_filter('query', [ $this, '_drop_temporary_tables' ]);
        }
    }
}
