<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Ops_Status_Packs;
use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/ops-status-tables.php';

/**
 * The operations status adapters (issue #300, second slice): read-only
 * status for UpdraftPlus, Duplicator, Solid Security, MonsterInsights and
 * W3 Total Cache, plus a W3 Total Cache purge, as free ops on the theme
 * dispatcher pair so they add no top-level tools.
 *
 * None of the plugins is installed in the shared test core. Presence is
 * driven through each adapter's filter, and the state the adapters read is
 * seeded the way each plugin stores it: UpdraftPlus and MonsterInsights
 * options, Duplicator's backups table, Solid Security's site options and log,
 * lockout and ban tables (tests/support/ops-status-tables.php). W3 Total
 * Cache keeps its settings in a config file read through w3tc_config(), so
 * its values arrive through wpmcp_w3tc_config and its purge through
 * wpmcp_w3tc_purge_callback.
 *
 * Every fixture carries the secrets each plugin really stores next to its
 * status (remote storage keys, OAuth tokens, cache server passwords, scan
 * site keys, lockout IPs), and the tests assert none of them comes back.
 */
class OpsStatusPacksTest extends \WP_UnitTestCase
{
    private const PRESENCE = [
        'wpmcp_updraftplus_active',
        'wpmcp_duplicator_active',
        'wpmcp_solid_security_active',
        'wpmcp_monsterinsights_active',
        'wpmcp_w3tc_active',
    ];

    private const READ_OPS = [
        'get-updraftplus-status'     => 'wpmcp_updraftplus_active',
        'get-duplicator-status'      => 'wpmcp_duplicator_active',
        'get-solid-security-status'  => 'wpmcp_solid_security_active',
        'get-monsterinsights-status' => 'wpmcp_monsterinsights_active',
        'get-w3tc-status'            => 'wpmcp_w3tc_active',
    ];

    /** @var array<int, mixed> */
    private array $purges = [];

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_ops_status_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_ops_status_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        foreach (self::PRESENCE as $filter) {
            add_filter($filter, '__return_true');
        }
        $this->purges = [];
    }

    protected function tearDown(): void
    {
        foreach (array_merge(self::PRESENCE, [ 'wpmcp_w3tc_config', 'wpmcp_w3tc_purge_callback' ]) as $filter) {
            remove_all_filters($filter);
        }
        wp_clear_scheduled_hook('updraft_backup');
        wp_clear_scheduled_hook('updraft_backup_database');
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

    private function deactivate(string $filter): void
    {
        remove_all_filters($filter);
        add_filter($filter, '__return_false');
    }

    /** Assert none of $needles appears anywhere in the JSON of $payload. */
    private function assertLeaksNothing(array $payload, array $needles): void
    {
        $json = (string) wp_json_encode($payload);
        foreach ($needles as $needle) {
            $this->assertStringNotContainsString($needle, $json, "status output must not carry {$needle}");
        }
    }

    // ---------------------------------------------------------------
    // Catalog and presence
    // ---------------------------------------------------------------

    public function test_every_op_is_a_free_manage_options_op_on_the_theme_pair(): void
    {
        Gate::set_pro_for_tests(false);
        $ops = $this->catalog();

        foreach (array_keys(self::READ_OPS) as $name) {
            $this->assertArrayHasKey($name, $ops, "{$name} must be on the theme pair without a license");
            $this->assertSame('read', $ops[ $name ]['mode']);
            $this->assertSame('manage_options', $ops[ $name ]['capability']);
            $this->assertTrue($ops[ $name ]['dependency_met']);
        }

        $this->assertArrayHasKey('purge-w3tc-cache', $ops);
        $this->assertSame('destructive', $ops['purge-w3tc-cache']['mode']);
        $this->assertTrue($ops['purge-w3tc-cache']['requires_confirm']);
        $this->assertSame('manage_options', $ops['purge-w3tc-cache']['capability']);
    }

    public function test_each_adapter_is_skipped_cleanly_while_its_plugin_is_inactive(): void
    {
        $codes = [
            'get-updraftplus-status'     => 'updraftplus_inactive',
            'get-duplicator-status'      => 'duplicator_inactive',
            'get-solid-security-status'  => 'solid_security_inactive',
            'get-monsterinsights-status' => 'monsterinsights_inactive',
            'get-w3tc-status'            => 'w3tc_inactive',
        ];
        foreach (self::READ_OPS as $op => $filter) {
            $this->deactivate($filter);
            $this->assertFalse($this->catalog()[ $op ]['dependency_met'], "{$op} must be flagged while its plugin is inactive");
            $out = $this->read($op);
            $this->assertSame($codes[ $op ], $out['error']['code'] ?? null, "{$op} must answer {$codes[ $op ]}");
            $this->assertArrayNotHasKey('result', $out);
        }

        $calls = 0;
        add_filter('wpmcp_w3tc_purge_callback', fn () => function () use (&$calls) {
            $calls++;
        });
        $before = Snapshot_Store::row_count();
        $out    = $this->write('purge-w3tc-cache', [], true);
        $this->assertSame('w3tc_inactive', $out['error']['code']);
        $this->assertSame(0, $calls, 'an inactive W3 Total Cache must never be purged');
        $this->assertSame($before, Snapshot_Store::row_count());
    }

    public function test_presence_defaults_to_each_plugins_own_marker(): void
    {
        foreach (self::PRESENCE as $filter) {
            remove_all_filters($filter);
        }
        $ops = $this->catalog();

        $this->assertSame(defined('UPDRAFTPLUS_DIR'), $ops['get-updraftplus-status']['dependency_met']);
        $this->assertSame(defined('DUPLICATOR_VERSION'), $ops['get-duplicator-status']['dependency_met']);
        $this->assertSame(class_exists('ITSEC_Core', false), $ops['get-solid-security-status']['dependency_met']);
        $this->assertSame(defined('MONSTERINSIGHTS_VERSION'), $ops['get-monsterinsights-status']['dependency_met']);
        $this->assertSame(defined('W3TC_VERSION'), $ops['get-w3tc-status']['dependency_met']);
    }

    public function test_status_reads_refuse_a_user_without_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));

        foreach (array_keys(self::READ_OPS) as $op) {
            $out = $this->read($op);
            $this->assertSame('operation_denied', $out['error']['code'] ?? null, "{$op} runs at manage_options");
        }
    }

    // ---------------------------------------------------------------
    // UpdraftPlus
    // ---------------------------------------------------------------

    private function seed_updraftplus(): int
    {
        $time = 1790500000;
        update_option('updraft_last_backup', [
            'nonincremental_backup_time' => $time,
            'backup_time'                => $time,
            'backup_array'               => [
                'db'           => 'backup_2026-09-27-0906_My_Site_4f1a2b3c4d5e-db.gz',
                'db-size'      => 5120334,
                'plugins'      => [ 'backup_2026-09-27-0906_My_Site_4f1a2b3c4d5e-plugins.zip' ],
                'plugins-size' => 40331221,
                'themes'       => [ 'backup_2026-09-27-0906_My_Site_4f1a2b3c4d5e-themes.zip' ],
                'uploads'      => [ 'backup_2026-09-27-0906_My_Site_4f1a2b3c4d5e-uploads.zip' ],
                'others'       => [ 'backup_2026-09-27-0906_My_Site_4f1a2b3c4d5e-others.zip' ],
            ],
            'success'                    => 1,
            'errors'                     => [ 'Could not read /var/www/html/wp-content/private-dir' ],
            'backup_nonce'               => '4f1a2b3c4d5e',
        ]);
        update_option('updraft_interval', 'daily');
        update_option('updraft_interval_database', 'every4hours');
        update_option('updraft_retain', 2);
        update_option('updraft_retain_db', 6);
        update_option('updraft_service', [ 's3', 'dropbox' ]);
        update_option('updraft_s3', [
            'version'  => '1',
            'settings' => [ 's-1a2b' => [ 'accesskey' => 'AKIAEXAMPLEACCESS', 'secretkey' => 'wJalrXUtnFEMIsecretEXAMPLEKEY', 'path' => 'my-bucket/site' ] ],
        ]);
        update_option('updraft_dropbox', [
            'version'  => '1',
            'settings' => [ 's-3c4d' => [ 'tk_access_token' => 'sl.dropboxTOKENexample', 'folder' => 'site' ] ],
        ]);
        update_option('updraft_backup_history', [
            $time        => [ 'db' => 'backup_a-db.gz', 'nonce' => '4f1a2b3c4d5e' ],
            $time - 3600 => [ 'db' => 'backup_b-db.gz', 'nonce' => '9a8b7c6d5e4f' ],
        ]);

        return $time;
    }

    public function test_updraftplus_status_reports_last_backup_schedule_and_storage(): void
    {
        $time = $this->seed_updraftplus();
        $next = time() + 7200;
        wp_schedule_event($next, 'daily', 'updraft_backup');

        $out = $this->read('get-updraftplus-status');
        $this->assertArrayNotHasKey('error', $out);
        $status = $out['result'];

        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', $time), $status['last_backup']['time']);
        $this->assertSame('success', $status['last_backup']['status']);
        $this->assertSame(1, $status['last_backup']['error_count']);
        $this->assertSame([ 'db', 'plugins', 'themes', 'uploads', 'others' ], $status['last_backup']['components']);

        $this->assertSame('daily', $status['schedule']['files']['interval']);
        $this->assertSame(2, $status['schedule']['files']['retain']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', $next), $status['schedule']['files']['next_run']);
        $this->assertSame('every4hours', $status['schedule']['database']['interval']);
        $this->assertSame(6, $status['schedule']['database']['retain']);
        $this->assertNull($status['schedule']['database']['next_run']);

        $this->assertSame([ 's3', 'dropbox' ], $status['storage']);
        $this->assertSame(2, $status['backup_sets']);

        $this->assertLeaksNothing($out, [
            'AKIAEXAMPLEACCESS', 'wJalrXUtnFEMIsecret', 'sl.dropboxTOKEN', 'my-bucket',
            '4f1a2b3c4d5e', 'backup_2026', '/var/www', 'private-dir',
        ]);
    }

    public function test_updraftplus_status_before_any_backup_and_with_a_failed_one(): void
    {
        update_option('updraft_service', 'none');
        $status = $this->read('get-updraftplus-status')['result'];

        $this->assertNull($status['last_backup']);
        $this->assertSame([], $status['storage']);
        $this->assertSame('manual', $status['schedule']['files']['interval']);
        $this->assertSame(0, $status['backup_sets']);

        update_option('updraft_service', 'googledrive');
        update_option('updraft_last_backup', [
            'backup_time'  => 1790400000,
            'backup_array' => [ 'db' => 'backup_x-db.gz' ],
            'success'      => 0,
            'errors'       => [ 'one', 'two' ],
        ]);
        $status = $this->read('get-updraftplus-status')['result'];
        $this->assertSame('failed', $status['last_backup']['status']);
        $this->assertSame(2, $status['last_backup']['error_count']);
        $this->assertSame([ 'googledrive' ], $status['storage']);
    }

    // ---------------------------------------------------------------
    // Duplicator
    // ---------------------------------------------------------------

    public function test_duplicator_status_lists_packages_and_the_last_build(): void
    {
        $old    = wpmcp_test_duplicator_backup('20260901_mysite', 100, '2026-09-01 12:00:00');
        $failed = wpmcp_test_duplicator_backup('20260915_mysite', -1, '2026-09-15 08:30:00', [ 'FULL_BACKUP' ]);
        $build  = wpmcp_test_duplicator_backup('20260920_mysite', 40, '2026-09-20 10:00:00');
        $good   = wpmcp_test_duplicator_backup('20260918_mysite', 100, '2026-09-18 09:15:00', [ 'DB_ONLY', 'HAVE_LOCAL' ]);

        $out = $this->read('get-duplicator-status');
        $this->assertArrayNotHasKey('error', $out);
        $status = $out['result'];

        $this->assertSame(4, $status['total']);
        $this->assertSame([ $build, $good, $failed, $old ], array_column($status['packages'], 'id'), 'newest first');

        $by_id = array_column($status['packages'], null, 'id');
        $this->assertSame('complete', $by_id[ $good ]['status']);
        $this->assertSame(100, $by_id[ $good ]['status_code']);
        $this->assertSame('error', $by_id[ $failed ]['status']);
        $this->assertSame('building', $by_id[ $build ]['status']);
        $this->assertSame('2026-09-18T09:15:00Z', $by_id[ $good ]['created']);
        $this->assertSame([ 'DB_ONLY', 'HAVE_LOCAL' ], $by_id[ $good ]['flags']);
        $this->assertSame('20260918_mysite', $by_id[ $good ]['name']);

        $this->assertSame($good, $status['last_build']['id'], 'the newest completed build');

        $this->assertLeaksNothing($out, [ 'b2f1c9d4e7a80361', '_archive.zip' ]);

        $limited = $this->read('get-duplicator-status', [ 'limit' => 1 ])['result'];
        $this->assertCount(1, $limited['packages']);
        $this->assertSame(4, $limited['total']);
        $this->assertSame($good, $limited['last_build']['id'], 'the last build does not depend on the page');

        $this->assertSame('invalid_args', $this->read('get-duplicator-status', [ 'limit' => 0 ])['error']['code']);
    }

    public function test_duplicator_status_with_no_packages(): void
    {
        $status = $this->read('get-duplicator-status')['result'];
        $this->assertSame([], $status['packages']);
        $this->assertSame(0, $status['total']);
        $this->assertNull($status['last_build']);
    }

    /**
     * Run $fn with the table prefixes pointed at names no table uses, so a
     * table reads as missing without DDL (which would commit the test's
     * transaction). Options keep their already resolved table names.
     */
    private function with_prefixes(?string $base_prefix, ?string $prefix, callable $fn): void
    {
        global $wpdb;
        $saved = [ $wpdb->base_prefix, $wpdb->prefix ];
        $wpdb->base_prefix = $base_prefix ?? $saved[0];
        $wpdb->prefix      = $prefix ?? $saved[1];
        try {
            $fn();
        } finally {
            [ $wpdb->base_prefix, $wpdb->prefix ] = $saved;
        }
    }

    public function test_duplicator_status_reads_the_legacy_packages_table(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'duplicator_packages', [
            'name'    => '20250110_legacy',
            'hash'    => 'aa11bb22cc33dd44_20250110101010',
            'status'  => 100,
            'created' => '2025-01-10 10:10:10',
            'owner'   => 'admin',
            'package' => 'O:11:"DUP_Package":0:{}',
        ]);
        $id = (int) $wpdb->insert_id;

        // Duplicator before 5 kept its packages in {prefix}duplicator_packages.
        $this->with_prefixes('wpmcpnone_', null, function () use ($id): void {
            $out    = $this->read('get-duplicator-status');
            $status = $out['result'];
            $this->assertSame(1, $status['total']);
            $this->assertSame($id, $status['last_build']['id']);
            $this->assertSame('complete', $status['packages'][0]['status']);
            $this->assertSame('2025-01-10T10:10:10Z', $status['packages'][0]['created']);
            $this->assertSame([], $status['packages'][0]['flags']);
            $this->assertLeaksNothing($out, [ 'aa11bb22cc33dd44', 'DUP_Package' ]);
        });

        // Neither table: the plugin is loaded but has never been set up.
        $this->with_prefixes('wpmcpnone_', 'wpmcpnone_', function (): void {
            $this->assertSame('duplicator_not_installed', $this->read('get-duplicator-status')['error']['code']);
            $this->assertFalse($this->catalog()['get-duplicator-status']['dependency_met']);
        });
    }

    // ---------------------------------------------------------------
    // Solid Security
    // ---------------------------------------------------------------

    private function seed_solid_security(): void
    {
        global $wpdb;

        update_site_option('itsec_active_modules', [
            'brute-force'        => true,
            'two-factor'         => true,
            'file-change'        => false,
            'network-brute-force' => true,
        ]);
        update_site_option('itsec-storage', [
            'site-scanner' => [
                'vulnerabilities'  => [
                    [ 'type' => 'plugin', 'software' => [ 'slug' => 'old-plugin' ], 'issues' => [ [ 'title' => 'XSS', 'fixed_in' => '2.0' ] ] ],
                    [ 'type' => 'theme', 'software' => [ 'slug' => 'old-theme' ], 'issues' => [ [ 'title' => 'CSRF', 'fixed_in' => '1.1' ] ] ],
                ],
                'registered_sites' => [ 'site-1' => [ 'public' => 'pk_live_EXAMPLEPUBLIC', 'secret' => 'sk_live_EXAMPLESECRET' ] ],
            ],
            'network-brute-force' => [ 'api_key' => 'nbf-EXAMPLEAPIKEY', 'api_secret' => 'nbf-EXAMPLEAPISECRET' ],
        ]);

        $logs = $wpdb->base_prefix . 'itsec_logs';
        $wpdb->insert($logs, [ 'module' => 'site-scanner', 'code' => 'clean', 'type' => 'notice', 'timestamp' => '2026-09-20 03:00:00', 'data' => serialize([ 'results' => [ 'url' => 'https://example.org' ] ]), 'remote_ip' => '203.0.113.9' ]); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
        $wpdb->insert($logs, [ 'module' => 'site-scanner', 'code' => 'found-malware--vulnerable-software', 'type' => 'critical-issue', 'timestamp' => '2026-09-27 03:00:00', 'data' => serialize([ 'results' => [ 'entries' => [ 'malware' => [ [ 'location' => '/wp-content/evil.php' ] ] ] ] ]), 'remote_ip' => '203.0.113.10' ]); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
        $wpdb->insert($logs, [ 'module' => 'site-scanner', 'code' => 'scan', 'type' => 'process-stop', 'timestamp' => '2026-09-28 03:00:00', 'data' => '' ]);
        $wpdb->insert($logs, [ 'module' => 'brute_force', 'code' => 'invalid-login', 'type' => 'action', 'timestamp' => '2026-09-28 04:00:00', 'data' => '', 'remote_ip' => '198.51.100.7' ]);

        $lockouts = $wpdb->base_prefix . 'itsec_lockouts';
        $future   = gmdate('Y-m-d H:i:s', time() + 3600);
        $past     = gmdate('Y-m-d H:i:s', time() - 3600);
        foreach ([ [ '198.51.100.7', 1, $future ], [ '198.51.100.8', 1, $future ], [ '198.51.100.9', 1, $past ], [ '198.51.100.10', 0, $future ] ] as [ $host, $active, $expire ]) {
            $wpdb->insert($lockouts, [
                'lockout_type'       => 'brute_force',
                'lockout_start'      => $past,
                'lockout_start_gmt'  => $past,
                'lockout_expire'     => $expire,
                'lockout_expire_gmt' => $expire,
                'lockout_host'       => $host,
                'lockout_active'     => $active,
            ]);
        }
        $wpdb->insert($wpdb->base_prefix . 'itsec_bans', [ 'host' => '192.0.2.44', 'type' => 'ip', 'created_at' => $past, 'comment' => 'spam' ]);
    }

    public function test_solid_security_status_reports_modules_scan_and_counts(): void
    {
        $this->seed_solid_security();

        $out = $this->read('get-solid-security-status');
        $this->assertArrayNotHasKey('error', $out);
        $status = $out['result'];

        $this->assertSame([ 'brute-force', 'network-brute-force', 'two-factor' ], $status['enabled_modules']);

        $this->assertSame('2026-09-27T03:00:00Z', $status['last_scan']['time']);
        $this->assertSame('issues', $status['last_scan']['result']);
        $this->assertSame([ 'found-malware', 'vulnerable-software' ], $status['last_scan']['findings']);
        $this->assertSame(2, $status['known_vulnerabilities']);
        $this->assertSame(2, $status['active_lockouts']);
        $this->assertSame(1, $status['bans']);

        $this->assertLeaksNothing($out, [
            'sk_live_', 'pk_live_', 'nbf-EXAMPLE', '203.0.113', '198.51.100', '192.0.2.44',
            'evil.php', 'example.org', 'old-plugin',
        ]);
    }

    public function test_solid_security_status_scan_result_shapes(): void
    {
        global $wpdb;
        $logs = $wpdb->base_prefix . 'itsec_logs';

        update_site_option('itsec_active_modules', [ 'ban-users', 'backup' ]);
        $status = $this->read('get-solid-security-status')['result'];
        $this->assertSame([ 'backup', 'ban-users' ], $status['enabled_modules'], 'the old list format still reads');
        $this->assertNull($status['last_scan']);
        $this->assertSame(0, $status['known_vulnerabilities']);
        $this->assertSame(0, $status['active_lockouts']);

        $wpdb->insert($logs, [ 'module' => 'site-scanner', 'code' => 'clean', 'type' => 'notice', 'timestamp' => '2026-09-20 03:00:00', 'data' => '' ]);
        $scan = $this->read('get-solid-security-status')['result']['last_scan'];
        $this->assertSame('clean', $scan['result']);
        $this->assertSame([], $scan['findings']);

        $wpdb->insert($logs, [ 'module' => 'site-scanner', 'code' => 'scan-failure-server-error', 'type' => 'warning', 'timestamp' => '2026-09-21 03:00:00', 'data' => '' ]);
        $scan = $this->read('get-solid-security-status')['result']['last_scan'];
        $this->assertSame('error', $scan['result']);
        $this->assertSame([ 'scan-failure-server-error' ], $scan['findings']);
    }

    public function test_solid_security_status_without_its_tables(): void
    {
        update_site_option('itsec_active_modules', [ 'two-factor' => true ]);

        // A site logging to files only, or one whose tables were never made.
        $this->with_prefixes('wpmcpnone_', null, function (): void {
            $status = $this->read('get-solid-security-status')['result'];
            $this->assertSame([ 'two-factor' ], $status['enabled_modules']);
            $this->assertNull($status['last_scan']);
            $this->assertNull($status['active_lockouts']);
            $this->assertNull($status['bans']);
        });
    }

    // ---------------------------------------------------------------
    // MonsterInsights
    // ---------------------------------------------------------------

    public function test_monsterinsights_status_reports_connection_and_tracking_settings(): void
    {
        update_option('monsterinsights_site_profile', [
            'key'                         => 'mi-site-key-EXAMPLE',
            'token'                       => 'mi-site-token-EXAMPLE',
            'v4'                          => 'G-ABC123XYZ9',
            'viewname'                    => 'My Site - GA4',
            'a'                           => '123456789',
            'w'                           => '987654321',
            'p'                           => 'properties/987654321',
            'site_hash'                   => 'mi-hash-EXAMPLE',
            'measurement_protocol_secret' => 'mp-secret-EXAMPLE',
        ]);
        update_option('monsterinsights_settings', [
            'tracking_mode'             => 'gtag',
            'events_mode'               => 'js',
            'demographics'              => 1,
            'anonymize_ips'             => 0,
            'link_attribution'          => true,
            'ignore_users'              => [ 'administrator', 'editor' ],
            'extensions_of_files'       => 'doc,pdf,zip',
            'userid'                    => 0,
            'summaries_email_addresses' => [ [ 'email' => 'owner@example.com' ] ],
            'license_key'               => 'mi-license-EXAMPLE',
        ]);

        $out = $this->read('get-monsterinsights-status');
        $this->assertArrayNotHasKey('error', $out);
        $status = $out['result'];

        $this->assertTrue($status['connection']['connected']);
        $this->assertSame('authenticated', $status['connection']['mode']);
        $this->assertSame('G-ABC123XYZ9', $status['connection']['measurement_id']);
        $this->assertSame('My Site - GA4', $status['connection']['view_name']);

        $this->assertSame('gtag', $status['tracking']['tracking_mode']);
        $this->assertTrue($status['tracking']['demographics']);
        $this->assertFalse($status['tracking']['anonymize_ips']);
        $this->assertSame([ 'administrator', 'editor' ], $status['tracking']['ignore_users']);
        $this->assertSame('doc,pdf,zip', $status['tracking']['extensions_of_files']);
        $this->assertFalse($status['tracking_disabled']);

        $this->assertLeaksNothing($out, [
            'mi-site-key', 'mi-site-token', 'mi-hash', 'mp-secret', 'mi-license', 'owner@example.com', '123456789',
        ]);
    }

    public function test_monsterinsights_status_manual_and_unconnected(): void
    {
        update_option('monsterinsights_site_profile', [ 'manual_v4' => 'G-MANUAL42' ]);
        $status = $this->read('get-monsterinsights-status')['result'];
        $this->assertFalse($status['connection']['connected']);
        $this->assertSame('manual', $status['connection']['mode']);
        $this->assertSame('G-MANUAL42', $status['connection']['measurement_id']);
        $this->assertSame([], $status['tracking']);

        update_option('monsterinsights_site_profile', [ 'manual_v4' => '<script>' ]);
        $status = $this->read('get-monsterinsights-status')['result'];
        $this->assertSame('none', $status['connection']['mode']);
        $this->assertNull($status['connection']['measurement_id'], 'a value that is not a measurement id is never echoed');
    }

    // ---------------------------------------------------------------
    // W3 Total Cache
    // ---------------------------------------------------------------

    /** A W3 Total Cache master config, as w3tc_config() would answer for these keys. */
    private function w3tc_fixture(): void
    {
        add_filter('wpmcp_w3tc_config', static fn (): array => [
            'pgcache.enabled'            => true,
            'pgcache.engine'             => 'file_generic',
            'dbcache.enabled'            => false,
            'dbcache.engine'             => 'file',
            'objectcache.enabled'        => true,
            'objectcache.engine'         => 'redis',
            'objectcache.redis.servers'  => [ '10.0.0.5:6379' ],
            'objectcache.redis.password' => 'redis-pass-EXAMPLE',
            'minify.enabled'             => true,
            'minify.engine'              => 'file',
            'browsercache.enabled'       => true,
            'cdn.enabled'                => true,
            'cdn.engine'                 => 's3',
            'cdn.s3.key'                 => 'AKIAW3TCEXAMPLE',
            'cdn.s3.secret'              => 'w3tc-s3-secret-EXAMPLE',
            'cdnfsd.enabled'             => false,
            'varnish.enabled'            => false,
            'pgcache.memcached.password' => 'memcached-pass-EXAMPLE',
        ]);
    }

    public function test_w3tc_status_reports_enabled_caches(): void
    {
        $this->w3tc_fixture();
        add_filter('wpmcp_w3tc_purge_callback', static fn () => static function (): void {
        });

        $out = $this->read('get-w3tc-status');
        $this->assertArrayNotHasKey('error', $out);
        $status = $out['result'];

        $this->assertSame([ 'enabled' => true, 'engine' => 'file_generic' ], $status['caches']['page']);
        $this->assertSame([ 'enabled' => false, 'engine' => 'file' ], $status['caches']['database']);
        $this->assertSame([ 'enabled' => true, 'engine' => 'redis' ], $status['caches']['object']);
        $this->assertSame([ 'enabled' => true, 'engine' => 'file' ], $status['caches']['minify']);
        $this->assertSame([ 'enabled' => true ], $status['caches']['browser']);
        $this->assertSame([ 'enabled' => true, 'engine' => 's3' ], $status['caches']['cdn']);
        $this->assertSame([ 'enabled' => false ], $status['caches']['varnish']);
        $this->assertSame([ 'page', 'object', 'minify', 'browser', 'cdn' ], $status['enabled']);
        $this->assertTrue($status['purge_supported']);

        $this->assertLeaksNothing($out, [ 'redis-pass', 'memcached-pass', 'AKIAW3TC', 'w3tc-s3-secret', '10.0.0.5' ]);
    }

    public function test_w3tc_status_without_its_config_api(): void
    {
        $status = $this->read('get-w3tc-status')['result'];
        $this->assertSame([], $status['enabled']);
        $this->assertFalse($status['caches']['page']['enabled']);
        $this->assertNull($status['caches']['page']['engine']);
        $this->assertSame(function_exists('w3tc_flush_all'), $status['purge_supported']);
    }

    public function test_w3tc_purge_needs_confirm_and_is_not_snapshotted(): void
    {
        $this->w3tc_fixture();
        $calls = 0;
        add_filter('wpmcp_w3tc_purge_callback', fn () => function () use (&$calls): void {
            $calls++;
        });

        $refused = $this->write('purge-w3tc-cache');
        $this->assertSame('confirmation_required', $refused['error']['code']);
        $this->assertSame(0, $calls);

        $before = Snapshot_Store::row_count();
        $out    = $this->write('purge-w3tc-cache', [], true);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame(1, $calls);
        $this->assertTrue($out['result']['purged']);
        $this->assertSame([ 'page', 'object', 'minify', 'browser', 'cdn' ], $out['result']['caches']);
        $this->assertFalse($out['recoverable'], 'a cache purge has no before-image to restore');
        $this->assertSame($before, Snapshot_Store::row_count());
    }

    public function test_w3tc_purge_refuses_without_manage_options_or_a_purge_api(): void
    {
        $calls = 0;
        add_filter('wpmcp_w3tc_purge_callback', fn () => function () use (&$calls): void {
            $calls++;
        });

        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $denied = $this->write('purge-w3tc-cache', [], true);
        $this->assertSame('operation_denied', $denied['error']['code']);
        $this->assertSame(0, $calls);

        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        remove_all_filters('wpmcp_w3tc_purge_callback');
        add_filter('wpmcp_w3tc_purge_callback', '__return_null');
        $out = $this->write('purge-w3tc-cache', [], true);
        $this->assertSame('w3tc_purge_unavailable', $out['error']['code']);
    }

    // ---------------------------------------------------------------
    // Defensive redaction
    // ---------------------------------------------------------------

    public function test_redact_masks_secret_shaped_keys_at_any_depth(): void
    {
        $out = Ops_Status_Packs::redact([
            'engine'   => 'redis',
            'apiKey'   => 'abc',
            'nested'   => [ 'client_secret' => 'def', 'access_token' => 'ghi', 'folder' => 'site', 'list' => [ 'password' => 'x' ] ],
            'enabled'  => true,
            'keywords' => 'fine',
            'nonce'    => 'n',
            7          => 'numeric keys stay',
        ]);

        $this->assertSame('redis', $out['engine']);
        $this->assertSame('[redacted]', $out['apiKey']);
        $this->assertSame('[redacted]', $out['nested']['client_secret']);
        $this->assertSame('[redacted]', $out['nested']['access_token']);
        $this->assertSame('site', $out['nested']['folder']);
        $this->assertSame('[redacted]', $out['nested']['list']['password']);
        $this->assertTrue($out['enabled']);
        $this->assertSame('fine', $out['keywords']);
        $this->assertSame('[redacted]', $out['nonce']);
        $this->assertSame('numeric keys stay', $out[7]);
    }
}
