<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Integrations\Wordfence_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Wordfence support on the theme dispatcher pair (issue #577): status, scan
 * issues, blocked attacks, active blocks and login failures, and the three
 * snapshotted writes (block, unblock, ignore issue) behind confirm:true, each
 * rolled back exactly.
 *
 * Wordfence is not in the shared test core: presence comes from
 * wpmcp_wordfence_active, its tables are built here from Wordfence 9.1's own
 * schema (wfSchema), and a fake adapter (wpmcp_wordfence_api) stands in for
 * its classes, writing those tables the way wfBlock::createIP(),
 * wfBlock::unblockIP() and wfIssues::updateIssue() do. The real plugin runs
 * the same ops in WordfenceLiveTest (bin/test-local.sh --live-wordfence).
 */
class WordfenceIntegrationTest extends \WP_UnitTestCase
{
    /** @var object */
    private $fake;

    public static function wpSetUpBeforeClass(): void
    {
        global $wpdb;
        $t = static fn (string $n): string => Plugin_Table_Rows_Snapshot::wordfence_table($n);
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$t('wfBlocks7')} (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `type` int(10) unsigned NOT NULL DEFAULT '0',
            `IP` binary(16) NOT NULL DEFAULT '\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0\\0',
            `blockedTime` bigint(20) NOT NULL,
            `reason` varchar(255) NOT NULL,
            `lastAttempt` int(10) unsigned DEFAULT '0',
            `blockedHits` int(10) unsigned DEFAULT '0',
            `expiration` bigint(20) unsigned NOT NULL DEFAULT '0',
            `parameters` text,
            PRIMARY KEY (`id`), KEY `type` (`type`), KEY `IP` (`IP`), KEY `expiration` (`expiration`)
        ) DEFAULT CHARSET=utf8");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$t('wfIssues')} (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `time` int(10) unsigned NOT NULL,
            `lastUpdated` int(10) unsigned NOT NULL,
            `status` varchar(10) NOT NULL,
            `type` varchar(20) NOT NULL,
            `severity` tinyint(3) unsigned NOT NULL,
            `ignoreP` char(32) NOT NULL,
            `ignoreC` char(32) NOT NULL,
            `shortMsg` varchar(255) NOT NULL,
            `longMsg` text,
            `data` text,
            PRIMARY KEY (`id`)
        ) DEFAULT CHARSET=utf8");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$t('wfHits')} (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `attackLogTime` double(17,6) unsigned NOT NULL DEFAULT 0,
            `ctime` double(17,6) unsigned NOT NULL,
            `IP` binary(16) DEFAULT NULL,
            `statusCode` int(11) NOT NULL DEFAULT '200',
            `URL` text,
            `action` varchar(64) NOT NULL DEFAULT '',
            `actionDescription` text,
            PRIMARY KEY (`id`)
        ) DEFAULT CHARSET=utf8");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$t('wfLogins')} (
            `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
            `ctime` double(17,6) unsigned NOT NULL,
            `fail` tinyint(3) unsigned NOT NULL,
            `action` varchar(40) NOT NULL,
            `username` varchar(255) NOT NULL,
            `IP` binary(16) DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) DEFAULT CHARSET=utf8");
    }

    public static function wpTearDownAfterClass(): void
    {
        global $wpdb;
        foreach ([ 'wfBlocks7', 'wfIssues', 'wfHits', 'wfLogins' ] as $n) {
            $wpdb->query('DROP TABLE IF EXISTS ' . Plugin_Table_Rows_Snapshot::wordfence_table($n));
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::admin());
        add_filter('wpmcp_wordfence_active', '__return_true');
        $this->fake = $this->make_fake();
        add_filter('wpmcp_wordfence_api', fn () => $this->fake);
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_wordfence_active');
        remove_all_filters('wpmcp_wordfence_api');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private static function admin(): int
    {
        $id = self::factory()->user->create([ 'role' => 'administrator' ]);
        if (is_multisite()) {
            grant_super_admin($id);
        }
        return $id;
    }

    private static function t(string $name): string
    {
        return Plugin_Table_Rows_Snapshot::wordfence_table($name);
    }

    private function make_fake(): object
    {
        return new class {
            public array $config = [
                'wf_scanRunning'                 => 0,
                'scanTime'                       => 1700000000.25,
                'lastScanCompleted'              => 'ok',
                'firewallEnabled'                => 1,
                'loginSecurityEnabled'           => 1,
                'loginSec_maxFailures'           => 5,
                'loginSec_maxForgotPasswd'       => 3,
                'loginSec_countFailMins'         => 240,
                'loginSec_lockoutMins'           => 240,
                'loginSec_lockInvalidUsers'      => 1,
                'loginSec_blockAdminReg'         => 1,
                'loginSec_strongPasswds_enabled' => 1,
                'apiKey'                         => 'SECRET-API-KEY-123',
                'alertEmails'                    => 'owner@example.com',
            ];
            public array $calls     = [];
            public array $allowlist = [ '192.0.2.200' ];
            public ?string $own     = '192.0.2.100';
            public function version(): string { return '9.1.0'; }
            public function config(string $k) { return $this->config[ $k ] ?? null; }
            public function firewall_mode(): string { return 'learning-mode'; }
            public function scan_running(): bool { return false; }
            public function is_allowlisted(string $ip): bool { return in_array($ip, $this->allowlist, true); }
            public function own_ip(): ?string { return $this->own; }
            public function block_ip(string $ip, string $reason, int $d): void
            {
                global $wpdb;
                $this->calls[] = [ 'block', $ip, $reason, $d ];
                $wpdb->query($wpdb->prepare('INSERT INTO %i (type, IP, blockedTime, reason, expiration, parameters) VALUES (1, UNHEX(%s), %d, %s, %d, NULL)', Plugin_Table_Rows_Snapshot::wordfence_table('wfBlocks7'), bin2hex(Wordfence_Integration::ip_binary($ip)), time(), $reason, $d ? time() + $d : 0));
            }
            public function unblock_ip(string $ip): void
            {
                global $wpdb;
                $this->calls[] = [ 'unblock', $ip ];
                $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE IP = UNHEX(%s)', Plugin_Table_Rows_Snapshot::wordfence_table('wfBlocks7'), bin2hex(Wordfence_Integration::ip_binary($ip))));
            }
            public function ignore_issue(int $id, string $how): void
            {
                global $wpdb;
                $this->calls[] = [ 'ignore', $id, $how ];
                $wpdb->query($wpdb->prepare('UPDATE %i SET status = %s WHERE id = %d', Plugin_Table_Rows_Snapshot::wordfence_table('wfIssues'), $how, $id));
            }
        };
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

    private function block_row(string $ip, int $type, string $reason, int $expiration, ?string $parameters = null): int
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('INSERT INTO %i (type, IP, blockedTime, reason, lastAttempt, blockedHits, expiration, parameters) VALUES (%d, UNHEX(%s), %d, %s, 0, 4, %d, %s)', self::t('wfBlocks7'), $type, bin2hex(Wordfence_Integration::ip_binary($ip)), 1700000000, $reason, $expiration, $parameters));
        return (int) $wpdb->insert_id;
    }

    private function issue_row(string $status, int $severity, string $type, string $msg, array $data): int
    {
        global $wpdb;
        $wpdb->insert(self::t('wfIssues'), [
            'time' => 1700000000, 'lastUpdated' => 1700000000, 'status' => $status, 'type' => $type, 'severity' => $severity,
            'ignoreP' => md5($msg), 'ignoreC' => md5($msg . 'c'), 'shortMsg' => $msg, 'longMsg' => null, 'data' => serialize($data),
        ]);
        return (int) $wpdb->insert_id;
    }

    /** @return array<int, array<string, mixed>> every wfBlocks7 row, raw bytes and all */
    private function all_blocks(): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY id', self::t('wfBlocks7')), ARRAY_A);
    }

    private function all_issues(): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY id', self::t('wfIssues')), ARRAY_A);
    }

    // ---------------------------------------------------------------
    // Catalog and availability
    // ---------------------------------------------------------------

    public function test_ops_are_free_ops_on_the_theme_pair_at_wordfences_own_capability(): void
    {
        Gate::set_pro_for_tests(false);
        $ops = $this->catalog();
        $cap = is_multisite() ? 'manage_network' : 'manage_options';
        foreach ([ 'get-wordfence-status', 'list-wordfence-scan-issues', 'list-wordfence-blocked-attacks', 'list-wordfence-blocks', 'list-wordfence-login-failures' ] as $n) {
            $this->assertArrayHasKey($n, $ops);
            $this->assertSame('read', $ops[ $n ]['mode']);
            $this->assertSame($cap, $ops[ $n ]['capability']);
            $this->assertTrue($ops[ $n ]['dependency_met']);
        }
        foreach ([ 'block-wordfence-ip', 'unblock-wordfence-ip', 'ignore-wordfence-scan-issue' ] as $n) {
            $this->assertSame('destructive', $ops[ $n ]['mode']);
            $this->assertSame($cap, $ops[ $n ]['capability']);
            $this->assertTrue($ops[ $n ]['requires_confirm']);
        }
    }

    public function test_inactive_wordfence_is_documented_and_answers_wordfence_inactive_writing_nothing(): void
    {
        $this->issue_row('new', 100, 'file', 'x', []);
        remove_all_filters('wpmcp_wordfence_active');
        add_filter('wpmcp_wordfence_active', '__return_false');

        $ops = $this->catalog();
        $this->assertFalse($ops['get-wordfence-status']['dependency_met']);
        $this->assertFalse($ops['block-wordfence-ip']['dependency_met']);
        $this->assertSame('wordfence_inactive', $this->read('get-wordfence-status')['error']['code']);

        $before = Snapshot_Store::row_count();
        $this->assertSame('wordfence_inactive', $this->write('block-wordfence-ip', [ 'ip' => '203.0.113.5' ], true)['error']['code']);
        $this->assertSame('wordfence_inactive', $this->write('ignore-wordfence-scan-issue', [ 'issue_id' => 1 ], true)['error']['code']);
        $this->assertSame([], $this->fake->calls);
        $this->assertSame($before, Snapshot_Store::row_count());
    }

    public function test_presence_defaults_to_wordfences_own_classes(): void
    {
        remove_all_filters('wpmcp_wordfence_active');
        $this->assertSame(class_exists('wordfence', false) && class_exists('wfConfig', false), Wordfence_Integration::is_active());
    }

    public function test_reads_and_writes_refuse_a_user_below_wordfences_capability(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertSame('operation_denied', $this->read('get-wordfence-status')['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->read('list-wordfence-blocks')['error']['code'] ?? null);
        $this->assertNotSame([], $this->write('block-wordfence-ip', [ 'ip' => '203.0.113.5' ], true)['error'] ?? []);
        $this->assertSame([], $this->fake->calls);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_status_reads_scan_firewall_and_brute_force_without_secrets(): void
    {
        $this->issue_row('new', 100, 'file', 'a', []);
        $this->issue_row('new', 100, 'file', 'b', []);
        $this->issue_row('new', 50, 'wfPluginAbandoned', 'c', []);
        $this->issue_row('ignoreP', 25, 'file', 'd', []);

        $r = $this->read('get-wordfence-status')['result'];
        $this->assertSame('9.1.0', $r['version']);
        $this->assertSame('2023-11-14T22:13:20Z', $r['scan']['last_scan_time']);
        $this->assertSame('ok', $r['scan']['last_scan_result']);
        $this->assertSame([ 'critical' => 2, 'medium' => 1 ], $r['scan']['issues_by_severity']);
        $this->assertSame(3, $r['scan']['new_issues']);
        $this->assertSame(1, $r['scan']['ignored_issues']);
        $this->assertSame('learning-mode', $r['firewall']['mode']);
        $this->assertSame(5, $r['brute_force']['max_login_failures']);
        $json = (string) wp_json_encode($r);
        $this->assertStringNotContainsString('SECRET-API-KEY', $json);
        $this->assertStringNotContainsString('owner@example.com', $json);

        $this->fake->config['lastScanCompleted'] = 'Scan can\'t continue';
        $this->assertSame('failed', $this->read('get-wordfence-status')['result']['scan']['last_scan_result']);
    }

    public function test_scan_issues_filter_by_status_with_type_severity_and_file(): void
    {
        $a = $this->issue_row('new', 100, 'file', 'Modified <b>core</b> file', [ 'file' => 'wp-includes/x.php' ]);
        $this->issue_row('new', 50, 'wfPluginAbandoned', 'Abandoned plugin', []);
        $this->issue_row('ignoreC', 75, 'file', 'Ignored one', [ 'file' => 'y.php' ]);

        $r = $this->read('list-wordfence-scan-issues', [ 'limit' => 1 ])['result'];
        $this->assertSame(1, $r['count']);
        $this->assertSame($a, $r['issues'][0]['id']);
        $this->assertSame('file', $r['issues'][0]['type']);
        $this->assertSame('critical', $r['issues'][0]['severity']);
        $this->assertSame('wp-includes/x.php', $r['issues'][0]['file']);
        $this->assertSame('Modified core file', $r['issues'][0]['message']);

        $this->assertSame(2, $this->read('list-wordfence-scan-issues')['result']['count']);
        $this->assertSame('ignoreC', $this->read('list-wordfence-scan-issues', [ 'status' => 'ignoreC' ])['result']['issues'][0]['status']);
        $this->assertSame(3, $this->read('list-wordfence-scan-issues', [ 'status' => 'all' ])['result']['count']);
    }

    public function test_attacks_blocks_and_login_failures_read_the_tables_with_limit(): void
    {
        global $wpdb;
        for ($i = 0; $i < 3; $i++) {
            $wpdb->query($wpdb->prepare('INSERT INTO %i (ctime, IP, statusCode, action, actionDescription, URL) VALUES (%f, UNHEX(%s), 403, %s, %s, %s)', self::t('wfHits'), 1700000000 + $i, bin2hex(Wordfence_Integration::ip_binary('203.0.113.' . $i)), 'blocked:waf', 'SQLi', 'https://example.org/?id=1&api_key=TOPSECRET'));
        }
        $wpdb->query($wpdb->prepare('INSERT INTO %i (ctime, IP, statusCode, action, actionDescription, URL) VALUES (%f, UNHEX(%s), 200, %s, %s, %s)', self::t('wfHits'), 1700000009, bin2hex(Wordfence_Integration::ip_binary('203.0.113.9')), '', '', '/'));
        $this->block_row('198.51.100.1', 1, 'Manual', 0);
        $this->block_row('2001:db8::1', 8, 'Auto', time() + 3600);
        $this->block_row('198.51.100.2', 1, 'Expired', 1600000001);
        $this->block_row('0.0.0.0', 4, 'Pattern', 0, '{"ipRange":"1.2.3.4"}');
        $wpdb->query($wpdb->prepare('INSERT INTO %i (ctime, fail, IP, username, action) VALUES (%f, 1, UNHEX(%s), %s, %s)', self::t('wfLogins'), 1700000000, bin2hex(Wordfence_Integration::ip_binary('192.0.2.1')), 'admin', 'loginFailInvalidUsername'));
        $wpdb->query($wpdb->prepare('INSERT INTO %i (ctime, fail, IP, username, action) VALUES (%f, 0, UNHEX(%s), %s, %s)', self::t('wfLogins'), 1700000001, bin2hex(Wordfence_Integration::ip_binary('192.0.2.2')), 'editor', 'loginOK'));

        $attacks = $this->read('list-wordfence-blocked-attacks', [ 'limit' => 2 ])['result'];
        $this->assertSame(2, $attacks['count']);
        $this->assertSame('203.0.113.2', $attacks['attacks'][0]['ip']);
        $this->assertSame('blocked:waf', $attacks['attacks'][0]['action']);
        $this->assertStringContainsString('id=1', $attacks['attacks'][0]['url']);
        $this->assertStringNotContainsString('TOPSECRET', (string) wp_json_encode($attacks));

        $blocks = $this->read('list-wordfence-blocks')['result'];
        $this->assertSame(2, $blocks['count'], 'expired and pattern rows are not IP blocks in force');
        $ips = array_column($blocks['blocks'], 'ip');
        sort($ips);
        $this->assertSame([ '198.51.100.1', '2001:db8::1' ], $ips);

        $fails = $this->read('list-wordfence-login-failures')['result'];
        $this->assertSame(1, $fails['count']);
        $this->assertSame('admin', $fails['failures'][0]['username']);
        $this->assertSame('192.0.2.1', $fails['failures'][0]['ip']);
    }

    // ---------------------------------------------------------------
    // Writes, each rolled back exactly
    // ---------------------------------------------------------------

    public function test_block_ip_needs_confirmation_then_blocks_and_rolls_back_exactly(): void
    {
        $this->block_row('198.51.100.1', 1, 'Other', 0);
        $this->block_row('203.0.113.5', 7, 'Lockout', time() + 600);
        $before = $this->all_blocks();

        $r = $this->write('block-wordfence-ip', [ 'ip' => '203.0.113.5' ]);
        $this->assertSame('confirmation_required', $r['error']['code']);
        $this->assertSame([], $this->fake->calls);

        $r = $this->write('block-wordfence-ip', [ 'ip' => '203.0.113.5', 'duration' => 3600, 'reason' => 'Bad bot' ], true);
        $this->assertArrayNotHasKey('error', $r);
        $this->assertTrue($r['recoverable']);
        $this->assertSame([ 'block', '203.0.113.5', 'Bad bot', 3600 ], $this->fake->calls[0]);
        $this->assertCount(2, $r['result']['blocks']);
        $this->assertCount(3, $this->all_blocks());

        $this->assertTrue(Rollback_Service::restore_operation($r['operation_id']));
        $this->assertSame($before, $this->all_blocks(), 'the inserted block is gone and every other row is byte for byte as it was');
    }

    public function test_block_refuses_invalid_own_and_allowlisted_ips_without_side_effects(): void
    {
        $before = Snapshot_Store::row_count();
        $this->assertSame('invalid_ip', $this->write('block-wordfence-ip', [ 'ip' => 'not-an-ip' ], true)['error']['code']);
        $this->assertSame('wordfence_own_ip', $this->write('block-wordfence-ip', [ 'ip' => '::ffff:192.0.2.100' ], true)['error']['code']);
        $this->assertSame('wordfence_ip_allowlisted', $this->write('block-wordfence-ip', [ 'ip' => '192.0.2.200' ], true)['error']['code']);
        $this->assertSame([], $this->fake->calls);
        $this->assertSame($before, Snapshot_Store::row_count());
        $this->assertSame([], $this->all_blocks());
    }

    public function test_unblock_removes_every_block_on_the_ip_and_rolls_back_exactly(): void
    {
        $this->block_row('203.0.113.5', 1, 'Manual', 0);
        $this->block_row('203.0.113.5', 7, 'Lockout', time() + 600);
        $this->block_row('2001:db8::5', 1, 'Other', 0);
        $before = $this->all_blocks();

        $this->assertSame('wordfence_ip_not_blocked', $this->write('unblock-wordfence-ip', [ 'ip' => '203.0.113.6' ], true)['error']['code']);

        $preview = $this->write('unblock-wordfence-ip', [ 'ip' => '203.0.113.5' ]);
        $this->assertSame(2, $preview['error']['data']['blocks'] ?? null);

        $r = $this->write('unblock-wordfence-ip', [ 'ip' => '203.0.113.5' ], true);
        $this->assertArrayNotHasKey('error', $r);
        $this->assertTrue($r['recoverable']);
        $this->assertSame(2, $r['result']['removed']);
        $this->assertCount(1, $this->all_blocks());

        $this->assertTrue(Rollback_Service::restore_operation($r['operation_id']));
        $this->assertSame($before, $this->all_blocks(), 'both blocks return at their own ids with their own bytes');
    }

    public function test_ignore_issue_rolls_back_exactly_and_refuses_an_unknown_issue(): void
    {
        $a      = $this->issue_row('new', 100, 'file', 'Modified file', [ 'file' => 'x.php' ]);
        $this->issue_row('new', 50, 'file', 'Other', []);
        $before = $this->all_issues();

        $snapshots = Snapshot_Store::row_count();
        $this->assertSame('wordfence_issue_not_found', $this->write('ignore-wordfence-scan-issue', [ 'issue_id' => $a + 99 ], true)['error']['code']);
        $this->assertSame($snapshots, Snapshot_Store::row_count());

        $r = $this->write('ignore-wordfence-scan-issue', [ 'issue_id' => $a, 'how' => 'ignoreP' ], true);
        $this->assertArrayNotHasKey('error', $r);
        $this->assertTrue($r['recoverable']);
        $this->assertSame('ignoreP', $r['result']['status']);
        $this->assertSame([ 'ignore', $a, 'ignoreP' ], $this->fake->calls[0]);

        $this->assertTrue(Rollback_Service::restore_operation($r['operation_id']));
        $this->assertSame($before, $this->all_issues());
    }

    public function test_rollback_of_a_wordfence_write_needs_wordfences_capability(): void
    {
        $this->block_row('203.0.113.5', 1, 'Manual', 0);
        $r = $this->write('unblock-wordfence-ip', [ 'ip' => '203.0.113.5' ], true);

        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $snapshot = Snapshot_Store::get_by_operation($r['operation_id'])['snapshot'] ?? null;
        $this->assertIsArray($snapshot);
        $this->expectException(\WPMCP\Safety\Mutation_Failed::class);
        Rollback_Service::apply_snapshot($snapshot);
    }

    public function test_snapshot_keys_for_wordfence_never_reach_another_table(): void
    {
        $this->assertNull(Plugin_Table_Rows_Snapshot::table('wordfence_blocks', 'other'));
        $this->assertSame(self::t('wfBlocks7'), Plugin_Table_Rows_Snapshot::table('wordfence_blocks', 'network'));
        $this->assertSame(self::t('wfIssues'), Plugin_Table_Rows_Snapshot::table('wordfence_issues', 'network'));
        $this->assertSame([], Plugin_Table_Rows_Snapshot::parse('wordfence_blocks:network:zz,1) OR 1=1')['ids']);
    }
}
