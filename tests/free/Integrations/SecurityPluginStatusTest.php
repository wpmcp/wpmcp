<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;

/**
 * Read-only status for Sucuri Security, All In One WP Security and Limit
 * Login Attempts Reloaded (issue #695), as free ops on the theme dispatcher
 * pair next to the Solid Security and Wordfence status, so they add no
 * top-level tools.
 *
 * None of the plugins is installed in the shared test core. Presence is
 * driven through each adapter's filter, and the state the adapters read is
 * seeded the way each plugin stores it (versions checked against the
 * wordpress.org builds: Sucuri Security 2.8.1, All In One WP Security 5.5.1,
 * Limit Login Attempts Reloaded 3.4.4):
 *
 *  - Sucuri keeps its settings and caches in flat files under
 *    wp-content/uploads/sucuri (sucuri-settings.php: an exit line and one
 *    JSON line; sucuri-sitecheck.php: "// key=value;" headers and
 *    "scan_results:{json}"; sucuri-failedlogins.php: one JSON line per
 *    attempt), and its firewall key in the
 *    sucuriscan_secret_cloudproxy_apikey(_enc) option. The data folder comes
 *    through wpmcp_sucuri_data_dir here, a temporary folder per test.
 *  - All In One WP Security keeps its settings in the
 *    aio_wp_security_configs option and lockouts, permanent blocks and
 *    failed logins in {prefix}aiowps_* tables (schema copied from its
 *    classes/wp-security-installer.php).
 *  - Limit Login Attempts Reloaded keeps everything in limit_login_*
 *    options: lockouts and retries as IP => expiry maps.
 *
 * Every fixture carries the secrets and personal data each plugin stores
 * next to its status (API and firewall keys, IP addresses, user names,
 * unlock keys, the renamed login slug), and the tests assert none of them
 * comes back.
 */
class SecurityPluginStatusTest extends \WP_UnitTestCase
{
    private const READ_OPS = [
        'get-sucuri-status'                => 'wpmcp_sucuri_active',
        'get-aios-status'                  => 'wpmcp_aios_active',
        'get-limit-login-attempts-status'  => 'wpmcp_limit_login_attempts_active',
    ];

    private string $sucuri_dir = '';

    public static function wpSetUpBeforeClass(): void
    {
        self::drop_aios_tables();
        self::create_aios_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        self::drop_aios_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        foreach (self::READ_OPS as $filter) {
            add_filter($filter, '__return_true');
        }
        $this->sucuri_dir = trailingslashit(get_temp_dir()) . 'wpmcp-sucuri-' . wp_generate_password(8, false);
        wp_mkdir_p($this->sucuri_dir);
        add_filter('wpmcp_sucuri_data_dir', fn (): string => $this->sucuri_dir);
    }

    protected function tearDown(): void
    {
        foreach (array_merge(array_values(self::READ_OPS), [ 'wpmcp_sucuri_data_dir' ]) as $filter) {
            remove_all_filters($filter);
        }
        foreach ((array) glob($this->sucuri_dir . '/*') as $file) {
            if (is_string($file) && is_file($file)) {
                unlink($file); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test scratch folder.
            }
        }
        if (is_dir($this->sucuri_dir)) {
            rmdir($this->sucuri_dir); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test scratch folder.
        }
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private static function create_aios_tables(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture DDL built from literals.
        $wpdb->query("CREATE TABLE `{$wpdb->prefix}aiowps_login_lockdown` (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            user_login VARCHAR(150) NOT NULL,
            lockdown_date datetime NOT NULL DEFAULT '1000-10-10 10:00:00',
            created INTEGER UNSIGNED,
            release_date datetime NOT NULL DEFAULT '1000-10-10 10:00:00',
            released INTEGER UNSIGNED,
            failed_login_ip varchar(100) NOT NULL DEFAULT '',
            lock_reason varchar(128) NOT NULL DEFAULT '',
            unlock_key varchar(128) NOT NULL DEFAULT '',
            is_lockout_email_sent tinyint(1) NOT NULL DEFAULT '1',
            backtrace_log text NOT NULL,
            ip_lookup_result LONGTEXT DEFAULT NULL,
            PRIMARY KEY  (id)
        ) {$charset}");
        $wpdb->query("CREATE TABLE `{$wpdb->prefix}aiowps_permanent_block` (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            blocked_ip varchar(100) NOT NULL DEFAULT '',
            block_reason varchar(128) NOT NULL DEFAULT '',
            country_origin varchar(50) NOT NULL DEFAULT '',
            blocked_date datetime NOT NULL DEFAULT '1000-10-10 10:00:00',
            created INTEGER UNSIGNED,
            unblock tinyint(1) NOT NULL DEFAULT '0',
            PRIMARY KEY  (id)
        ) {$charset}");
        $wpdb->query("CREATE TABLE `{$wpdb->prefix}aiowps_failed_logins` (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            user_login VARCHAR(150) NOT NULL,
            failed_login_date datetime NOT NULL DEFAULT '1000-10-10 10:00:00',
            created INTEGER UNSIGNED,
            login_attempt_ip varchar(100) NOT NULL DEFAULT '',
            PRIMARY KEY  (id)
        ) {$charset}");
        // phpcs:enable
    }

    private static function drop_aios_tables(): void
    {
        global $wpdb;
        foreach ([ 'aiowps_login_lockdown', 'aiowps_permanent_block', 'aiowps_failed_logins' ] as $table) {
            $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture DDL.
        }
    }

    private function catalog(): array
    {
        return array_column((new Theme_Integration())->catalog()['operations'], null, 'name');
    }

    private function read(string $op): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => $op, 'args' => [] ]);
    }

    private function status(string $op): array
    {
        $out = $this->read($op);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out['result'];
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

    public function test_every_op_is_a_free_manage_options_read_on_the_theme_pair(): void
    {
        Gate::set_pro_for_tests(false);
        $ops = $this->catalog();

        foreach (array_keys(self::READ_OPS) as $name) {
            $this->assertArrayHasKey($name, $ops, "{$name} must be on the theme pair without a license");
            $this->assertSame('read', $ops[ $name ]['mode']);
            $this->assertSame('manage_options', $ops[ $name ]['capability']);
            $this->assertTrue($ops[ $name ]['dependency_met']);
        }
    }

    public function test_each_adapter_names_its_plugin_while_inactive(): void
    {
        $expected = [
            'get-sucuri-status'               => [ 'sucuri_inactive', 'Sucuri Security', 'sucuri-scanner' ],
            'get-aios-status'                 => [ 'aios_inactive', 'All In One WP Security', 'all-in-one-wp-security-and-firewall' ],
            'get-limit-login-attempts-status' => [ 'limit_login_attempts_inactive', 'Limit Login Attempts Reloaded', 'limit-login-attempts-reloaded' ],
        ];
        foreach (self::READ_OPS as $op => $filter) {
            remove_all_filters($filter);
            add_filter($filter, '__return_false');
            [ $code, $name, $slug ] = $expected[ $op ];

            $this->assertFalse($this->catalog()[ $op ]['dependency_met'], "{$op} must be flagged while its plugin is inactive");
            $out = $this->read($op);
            $this->assertSame($code, $out['error']['code'] ?? null);
            $this->assertStringContainsString("Install and activate {$name}", $out['error']['message']);
            $this->assertSame($slug, $out['error']['data']['plugin']);
            $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url']);
            $this->assertArrayNotHasKey('result', $out);
        }
    }

    public function test_presence_defaults_to_each_plugins_own_constant(): void
    {
        foreach (self::READ_OPS as $filter) {
            remove_all_filters($filter);
        }
        $ops = $this->catalog();

        $this->assertSame(defined('SUCURISCAN_VERSION'), $ops['get-sucuri-status']['dependency_met']);
        $this->assertSame(defined('AIO_WP_SECURITY_VERSION'), $ops['get-aios-status']['dependency_met']);
        $this->assertSame(defined('LLA_PLUGIN_DIR'), $ops['get-limit-login-attempts-status']['dependency_met']);
    }

    public function test_status_reads_refuse_a_user_without_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));

        foreach (array_keys(self::READ_OPS) as $op) {
            $this->assertSame('operation_denied', $this->read($op)['error']['code'] ?? null, "{$op} runs at manage_options");
        }
    }

    public function test_reads_write_nothing(): void
    {
        $this->seed_sucuri();
        $this->seed_aios();
        $this->seed_llar();
        $before = Snapshot_Store::row_count();
        $files  = $this->sucuri_files();

        foreach (array_keys(self::READ_OPS) as $op) {
            $this->status($op);
        }

        $this->assertSame($before, Snapshot_Store::row_count());
        $this->assertSame($files, $this->sucuri_files(), 'a status read must not create or change any Sucuri file');
    }

    // ---------------------------------------------------------------
    // Sucuri Security
    // ---------------------------------------------------------------

    /** @return array<string,string> file name => md5 */
    private function sucuri_files(): array
    {
        $out = [];
        foreach ((array) glob($this->sucuri_dir . '/*') as $file) {
            $out[ basename((string) $file) ] = (string) md5_file((string) $file);
        }
        ksort($out);
        return $out;
    }

    private function seed_sucuri(): void
    {
        file_put_contents($this->sucuri_dir . '/sucuri-settings.php', "<?php exit(0); ?>\n" . wp_json_encode([
            'sucuriscan_api_key'          => 'SUCURIAPIKEY0123456789abcdef',
            'sucuriscan_runtime'          => 1790000000,
            'sucuriscan_twofactor_mode'   => 'all_users',
            'sucuriscan_hardening_xmlrpc' => 'enabled',
            'sucuriscan_notify_to'        => 'owner@example.test',
            'sucuriscan_plugin_version'   => '2.8.1',
        ]) . "\n");

        $scan = [
            'MALWARE'   => [ 'WARN' => [ [ 'Known javascript malware: evil-payload', 'https://example.test/wp-content/evil.js' ], [ 'Spam seo', 'https://example.test/spam' ] ] ],
            'BLACKLIST' => [ 'INFO' => [ [ 'Domain clean by Google', 'https://transparencyreport.example' ] ], 'WARN' => [ [ 'Blocklisted by SpamVendor', 'https://spamvendor.example' ] ] ],
            'SCAN'      => [ 'SITE' => [ 'https://example.test' ], 'IP' => [ '203.0.113.50' ] ],
        ];
        file_put_contents($this->sucuri_dir . '/sucuri-sitecheck.php', "<?php\n// datastore=sitecheck;\n// created_on=1789990000;\n// updated_on=1790001000;\nexit(0);\n?>\nscan_results:" . wp_json_encode($scan) . "\n");

        file_put_contents($this->sucuri_dir . '/sucuri-failedlogins.php', "<?php exit(0); ?>\n"
            . wp_json_encode([ 'user_login' => 'admin', 'user_password' => 'hunter2', 'attempt_time' => 1790002000, 'remote_addr' => '198.51.100.21' ]) . "\n"
            . wp_json_encode([ 'user_login' => 'editor', 'user_password' => 'letmein', 'attempt_time' => 1790002100, 'remote_addr' => '198.51.100.22' ]) . "\n"
            . wp_json_encode([ 'user_login' => 'root', 'user_password' => 'toor', 'attempt_time' => 1790002200, 'remote_addr' => '198.51.100.23' ]) . "\n");

        update_option('sucuriscan_secret_cloudproxy_apikey_enc', wp_json_encode([ 'v' => 2, 'iv' => 'aXY=', 'ct' => 'Q0lQSEVSVEVYVA==' ]));
    }

    public function test_sucuri_status_reports_firewall_scans_and_failed_logins(): void
    {
        $this->seed_sucuri();

        $out    = $this->read('get-sucuri-status');
        $status = $this->status('get-sucuri-status');

        $this->assertTrue($status['firewall_connected']);
        $this->assertTrue($status['api_registered']);
        $this->assertSame('2026-09-21T14:13:20Z', $status['last_integrity_check']);
        $this->assertSame('2026-09-21T14:30:00Z', $status['last_malware_scan']['time']);
        $this->assertSame(2, $status['last_malware_scan']['malware_findings']);
        $this->assertSame(1, $status['last_malware_scan']['blocklist_warnings']);
        $this->assertSame(3, $status['failed_logins']);
        $this->assertSame('all_users', $status['two_factor_mode']);
        $this->assertTrue($status['xmlrpc_blocked']);

        $this->assertLeaksNothing($out, [
            'SUCURIAPIKEY', 'Q0lQSEVSVEVYVA', 'owner@example.test', 'evil', 'example.test', 'SpamVendor',
            '203.0.113', '198.51.100', 'hunter2', 'letmein', 'admin', 'root',
        ]);
    }

    public function test_sucuri_status_on_a_fresh_install(): void
    {
        $status = $this->status('get-sucuri-status');

        $this->assertFalse($status['firewall_connected']);
        $this->assertFalse($status['api_registered']);
        $this->assertNull($status['last_integrity_check']);
        $this->assertNull($status['last_malware_scan']);
        $this->assertSame(0, $status['failed_logins']);
        $this->assertSame('disabled', $status['two_factor_mode']);
        $this->assertFalse($status['xmlrpc_blocked']);
        $this->assertSame([], $this->sucuri_files(), 'a read on a fresh install must not create Sucuri files');
    }

    public function test_sucuri_firewall_key_in_the_plain_or_legacy_store_counts(): void
    {
        update_option('sucuriscan_secret_cloudproxy_apikey', 'abcdef0123456789abcdef0123456789/0123456789abcdef0123456789abcdef');
        $this->assertTrue($this->status('get-sucuri-status')['firewall_connected']);

        delete_option('sucuriscan_secret_cloudproxy_apikey');
        file_put_contents($this->sucuri_dir . '/sucuri-settings.php', "<?php exit(0); ?>\n" . wp_json_encode([ 'sucuriscan_cloudproxy_apikey' => 'legacy/key' ]) . "\n");
        $out = $this->read('get-sucuri-status');
        $this->assertTrue($out['result']['firewall_connected']);
        $this->assertLeaksNothing($out, [ 'legacy/key' ]);
    }

    // ---------------------------------------------------------------
    // All In One WP Security
    // ---------------------------------------------------------------

    private function seed_aios(): void
    {
        global $wpdb;

        update_option('aio_wp_security_configs', [
            'aiowps_enable_login_lockdown'                => '1',
            'aiowps_enable_brute_force_attack_prevention' => '',
            'aiowps_enable_rename_login_page'             => '1',
            'aiowps_login_page_slug'                      => 'secret-door-42',
            'aiowps_enable_basic_firewall'                => '1',
            'aiowps_enable_6g_firewall'                   => '1',
            'aiowps_enable_pingback_firewall'             => '',
            'aiowps_disable_xmlrpc_pingback_methods'      => '1',
            'aiowps_enable_blacklisting'                  => '1',
            'aiowps_banned_ip_addresses'                  => "192.0.2.77\n192.0.2.78",
            'aiowps_enable_whitelisting'                  => '',
            'aiowps_enable_automated_fcd_scan'            => '1',
            'aiowps_last_fcd_scan_time'                   => 1790003000,
            'aiowps_fcds_change_detected'                 => true,
            'aiowps_fcd_scan_email_address'               => 'scans@example.test',
            'aiowps_recaptcha_secret_key'                 => 'RECAPTCHASECRET',
            'aiowps_unlock_request_secret_key'            => 'UNLOCKSECRET',
        ]);

        $now = time();
        foreach ([ [ '198.51.100.31', $now + 3600 ], [ '198.51.100.32', $now + 600 ], [ '198.51.100.33', $now - 600 ] ] as [ $ip, $released ]) {
            $wpdb->insert($wpdb->prefix . 'aiowps_login_lockdown', [
                'user_id' => 0, 'user_login' => 'lockeduser', 'created' => $now - 900, 'released' => $released,
                'failed_login_ip' => $ip, 'lock_reason' => 'login_fail', 'unlock_key' => 'UNLOCKKEY123', 'backtrace_log' => '',
            ]);
        }
        $wpdb->insert($wpdb->prefix . 'aiowps_permanent_block', [ 'blocked_ip' => '192.0.2.90', 'block_reason' => 'spam', 'created' => $now ]);
        foreach ([ '198.51.100.41', '198.51.100.42' ] as $ip) {
            $wpdb->insert($wpdb->prefix . 'aiowps_failed_logins', [ 'user_id' => 0, 'user_login' => 'guessuser', 'created' => $now, 'login_attempt_ip' => $ip ]);
        }
    }

    public function test_aios_status_reports_features_scan_and_counts(): void
    {
        $this->seed_aios();

        $out    = $this->read('get-aios-status');
        $status = $this->status('get-aios-status');

        $this->assertSame([
            'login_lockout'            => true,
            'brute_force_prevention'   => false,
            'rename_login_page'        => true,
            'basic_firewall'           => true,
            'advanced_firewall'        => true,
            'pingback_firewall'        => false,
            'xmlrpc_pingback_disabled' => true,
            'ip_blocklist'             => true,
            'ip_allowlist'             => false,
            'scheduled_file_scan'      => true,
        ], $status['features']);
        $this->assertSame('2026-09-21T15:03:20Z', $status['last_file_scan']['time']);
        $this->assertTrue($status['last_file_scan']['changes_detected']);
        $this->assertSame(2, $status['active_lockouts']);
        $this->assertSame(1, $status['permanent_blocks']);
        $this->assertSame(2, $status['failed_logins']);

        $this->assertLeaksNothing($out, [
            'secret-door-42', '192.0.2', '198.51.100', 'scans@example.test', 'RECAPTCHASECRET', 'UNLOCKSECRET',
            'UNLOCKKEY123', 'lockeduser', 'guessuser',
        ]);
    }

    public function test_aios_status_reads_a_date_string_scan_time_and_missing_tables(): void
    {
        update_option('aio_wp_security_configs', [ 'aiowps_last_fcd_scan_time' => '2026-09-01 08:00:00' ]);
        $status = $this->status('get-aios-status');
        $this->assertSame('2026-09-01T08:00:00Z', $status['last_file_scan']['time']);
        $this->assertFalse($status['last_file_scan']['changes_detected']);
        $this->assertFalse($status['features']['login_lockout']);

        delete_option('aio_wp_security_configs');
        $status = $this->status('get-aios-status');
        $this->assertNull($status['last_file_scan']);

        global $wpdb;
        $prefix         = $wpdb->prefix;
        $wpdb->prefix   = 'wpmcpnone_';
        try {
            $status = $this->status('get-aios-status');
        } finally {
            $wpdb->prefix = $prefix;
        }
        $this->assertNull($status['active_lockouts']);
        $this->assertNull($status['permanent_blocks']);
        $this->assertNull($status['failed_logins']);
    }

    // ---------------------------------------------------------------
    // Limit Login Attempts Reloaded
    // ---------------------------------------------------------------

    private function seed_llar(): void
    {
        $now = time();
        update_option('limit_login_allowed_retries', 3);
        update_option('limit_login_lockout_duration', 900);
        update_option('limit_login_allowed_lockouts', 2);
        update_option('limit_login_long_duration', 43200);
        update_option('limit_login_valid_duration', 3600);
        update_option('limit_login_lockouts', [ '198.51.100.51' => $now + 600, '198.51.100.52' => $now + 60, '198.51.100.53' => $now - 60 ]);
        update_option('limit_login_retries', [ '198.51.100.54' => 2, '198.51.100.55' => 1 ]);
        update_option('limit_login_retries_valid', [ '198.51.100.54' => $now + 300, '198.51.100.55' => $now - 300 ]);
        update_option('limit_login_lockouts_total', 17);
        update_option('limit_login_whitelist', [ '192.0.2.61' ]);
        update_option('limit_login_whitelist_usernames', [ 'trusted-admin' ]);
        update_option('limit_login_blacklist', [ '192.0.2.62', '192.0.2.63' ]);
        update_option('limit_login_blacklist_usernames', [ 'bad-actor' ]);
        update_option('limit_login_active_app', 'custom');
        update_option('limit_login_app_config', [ 'key' => 'LLARAPPKEY', 'api' => 'https://api.example.test' ]);
        update_option('limit_login_gdpr', 1);
        update_option('limit_login_mfa_enabled', 1);
        update_option('limit_login_logged', [ '198.51.100.51' => [ 'admin' => [ 'counter' => 3, 'date' => $now ] ] ]);
        update_option('limit_login_notify_email_to', 'alerts@example.test');
    }

    public function test_limit_login_attempts_status_reports_policy_and_counts(): void
    {
        $this->seed_llar();

        $out    = $this->read('get-limit-login-attempts-status');
        $status = $this->status('get-limit-login-attempts-status');

        $this->assertSame('cloud', $status['mode']);
        $this->assertSame([
            'allowed_retries'           => 3,
            'lockout_minutes'           => 15,
            'lockouts_before_long'      => 2,
            'long_lockout_hours'        => 12,
            'retries_reset_hours'       => 1,
        ], $status['policy']);
        $this->assertSame(2, $status['active_lockouts']);
        $this->assertSame(1, $status['ips_with_recent_failures']);
        $this->assertSame(17, $status['lockouts_total']);
        $this->assertSame([ 'ips' => 1, 'usernames' => 1 ], $status['allowlist']);
        $this->assertSame([ 'ips' => 2, 'usernames' => 1 ], $status['denylist']);
        $this->assertTrue($status['gdpr']);
        $this->assertTrue($status['mfa_enabled']);

        $this->assertLeaksNothing($out, [
            '198.51.100', '192.0.2', 'trusted-admin', 'bad-actor', 'LLARAPPKEY', 'api.example.test', 'alerts@example.test', 'admin',
        ]);
    }

    public function test_limit_login_attempts_status_uses_the_plugins_defaults(): void
    {
        $status = $this->status('get-limit-login-attempts-status');

        $this->assertSame('local', $status['mode']);
        $this->assertSame([
            'allowed_retries'           => 4,
            'lockout_minutes'           => 20,
            'lockouts_before_long'      => 4,
            'long_lockout_hours'        => 24,
            'retries_reset_hours'       => 24,
        ], $status['policy']);
        $this->assertSame(0, $status['active_lockouts']);
        $this->assertSame(0, $status['ips_with_recent_failures']);
        $this->assertSame(0, $status['lockouts_total']);
        $this->assertSame([ 'ips' => 0, 'usernames' => 0 ], $status['allowlist']);
        $this->assertFalse($status['gdpr']);
        $this->assertFalse($status['mfa_enabled']);
    }
}
