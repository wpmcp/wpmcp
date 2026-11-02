<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Theme_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;

/**
 * Wordfence support on the theme dispatcher pair: status, scan issues, blocked
 * attacks, active blocks and login failures read through a fake adapter
 * (wpmcp_wordfence_api) and seeded wfHits / wfBlocks7 / wfLogins tables, and
 * the three writes (block, unblock, ignore issue) behind confirm:true.
 * Wordfence is not installed in the test core; presence comes from
 * wpmcp_wordfence_active.
 */
class WordfenceIntegrationTest extends \WP_UnitTestCase
{
    private const TABLES = [ 'wfHits', 'wfBlocks7', 'wfLogins' ];

    /** @var object */
    private $fake;

    public static function wpSetUpBeforeClass(): void
    {
        global $wpdb;
        $p = $wpdb->base_prefix;
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}wfHits (id BIGINT AUTO_INCREMENT PRIMARY KEY, ctime DOUBLE, IP BINARY(16), statusCode INT, action VARCHAR(64), actionDescription TEXT, URL TEXT)");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}wfBlocks7 (id BIGINT AUTO_INCREMENT PRIMARY KEY, type INT, IP BINARY(16), blockedTime BIGINT, reason TEXT, expiration BIGINT, blockedHits INT)");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}wfLogins (id BIGINT AUTO_INCREMENT PRIMARY KEY, ctime DOUBLE, fail TINYINT, IP BINARY(16), username VARCHAR(255), action VARCHAR(64))");
    }

    public static function wpTearDownAfterClass(): void
    {
        global $wpdb;
        foreach (self::TABLES as $t) {
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->base_prefix}{$t}");
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        foreach (self::TABLES as $t) {
            $wpdb->query("DELETE FROM {$wpdb->base_prefix}{$t}");
        }
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
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

    private function make_fake(): object
    {
        return new class {
            public array $config = [
                'wf_scanRunning'          => 0,
                'scanTime'                => 1700000000,
                'lastScanCompleted'       => 'ok',
                'firewallEnabled'         => 1,
                'loginSecurityEnabled'    => 1,
                'loginSec_maxFailures'    => 5,
                'loginSec_maxForgotPasswd' => 3,
                'loginSec_countFailMins'  => 240,
                'loginSec_lockoutMins'    => 240,
                'loginSec_lockInvalidUsers' => 1,
                'loginSec_blockAdminReg'  => 1,
                'loginSec_strongPasswds_enabled' => 1,
                'apiKey'                  => 'SECRET-API-KEY-123',
                'alertEmails'             => 'owner@example.com',
            ];
            public array $calls = [];
            public array $rows  = [
                [ 'id' => 7, 'time' => 1700000000, 'type' => 'file', 'severity' => 100, 'status' => 'new', 'shortMsg' => 'Modified <b>core</b> file', 'data' => [ 'file' => 'wp-includes/x.php' ] ],
                [ 'id' => 8, 'time' => 1700000001, 'type' => 'wfPluginAbandoned', 'severity' => 50, 'status' => 'new', 'shortMsg' => 'Abandoned plugin', 'data' => [] ],
            ];
            public function version(): string { return '7.11.0'; }
            public function config(string $k) { return $this->config[ $k ] ?? null; }
            public function waf_status(): string { return 'enabled'; }
            public function issue_summary(): array { return [ 'by_severity' => [ '100' => 1, '50' => 1 ], 'new' => 2, 'ignored' => 0 ]; }
            public function issues(?string $status, int $limit): array { return array_slice($this->rows, 0, $limit); }
            public function block_ip(string $ip, string $reason, int $d): void { $this->calls[] = [ 'block', $ip, $reason, $d ]; }
            public function unblock_ip(string $ip): int { $this->calls[] = [ 'unblock', $ip ]; return 1; }
            public function ignore_issue(int $id, string $how): bool { $this->calls[] = [ 'ignore', $id, $how ]; return 7 === $id; }
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

    public function test_ops_are_free_manage_options_ops_on_the_theme_pair(): void
    {
        Gate::set_pro_for_tests(false);
        $ops = $this->catalog();
        foreach ([ 'get-wordfence-status', 'list-wordfence-scan-issues', 'list-wordfence-blocked-attacks', 'list-wordfence-blocks', 'list-wordfence-login-failures' ] as $n) {
            $this->assertArrayHasKey($n, $ops);
            $this->assertSame('read', $ops[ $n ]['mode']);
            $this->assertSame('manage_options', $ops[ $n ]['capability']);
        }
        foreach ([ 'block-wordfence-ip', 'unblock-wordfence-ip', 'ignore-wordfence-scan-issue' ] as $n) {
            $this->assertArrayHasKey($n, $ops);
            $this->assertSame('destructive', $ops[ $n ]['mode']);
        }
    }

    public function test_inactive_wordfence_answers_wordfence_inactive(): void
    {
        remove_all_filters('wpmcp_wordfence_active');
        add_filter('wpmcp_wordfence_active', '__return_false');
        $r = $this->read('get-wordfence-status');
        $this->assertSame('wordfence_inactive', $r['error']['code']);
    }

    public function test_status_reads_scan_firewall_and_brute_force_without_keys(): void
    {
        $r = $this->read('get-wordfence-status')['result'];
        $this->assertArrayNotHasKey('error', $r);
        $this->assertSame('2023-11-14T22:13:20Z', $r['scan']['last_scan_time']);
        $this->assertSame('ok', $r['scan']['last_scan_result']);
        $this->assertSame([ '100' => 1, '50' => 1 ], $r['scan']['issues_by_severity']);
        $this->assertSame('enabled', $r['firewall']['mode']);
        $this->assertSame(5, $r['brute_force']['max_login_failures']);
        $json = (string) wp_json_encode($r);
        $this->assertStringNotContainsString('SECRET-API-KEY', $json);
        $this->assertStringNotContainsString('owner@example.com', $json);
    }

    public function test_scan_issues_list_type_severity_and_file(): void
    {
        $r = $this->read('list-wordfence-scan-issues', [ 'limit' => 1 ])["result"];
        $this->assertSame(1, $r['count']);
        $this->assertSame('file', $r['issues'][0]['type']);
        $this->assertSame(100, $r['issues'][0]['severity']);
        $this->assertSame('wp-includes/x.php', $r['issues'][0]['file']);
        $this->assertSame('Modified core file', $r['issues'][0]['message']);
    }

    public function test_blocked_attacks_blocks_and_login_failures_read_the_tables_with_limit(): void
    {
        global $wpdb;
        $p = $wpdb->base_prefix;
        for ($i = 0; $i < 3; $i++) {
            $wpdb->query($wpdb->prepare("INSERT INTO {$p}wfHits (ctime, IP, statusCode, action, actionDescription, URL) VALUES (%f, INET6_ATON(CONCAT('::ffff:', %s)), 403, 'blocked:waf', 'SQLi', %s)", 1700000000 + $i, '203.0.113.' . $i, '/?id=1'));
        }
        $wpdb->query($wpdb->prepare("INSERT INTO {$p}wfHits (ctime, IP, statusCode, action, actionDescription, URL) VALUES (%f, INET6_ATON(CONCAT('::ffff:', %s)), 200, '', '', '/')", 1700000009, '203.0.113.9'));
        $wpdb->query($wpdb->prepare("INSERT INTO {$p}wfBlocks7 (type, IP, blockedTime, reason, expiration, blockedHits) VALUES (1, INET6_ATON(CONCAT('::ffff:', %s)), 1700000000, 'Manual', 0, 4)", '198.51.100.1'));
        $wpdb->query($wpdb->prepare("INSERT INTO {$p}wfBlocks7 (type, IP, blockedTime, reason, expiration, blockedHits) VALUES (1, INET6_ATON(CONCAT('::ffff:', %s)), 1600000000, 'Expired', 1600000001, 0)", '198.51.100.2'));
        $wpdb->query($wpdb->prepare("INSERT INTO {$p}wfLogins (ctime, fail, IP, username, action) VALUES (%f, 1, INET6_ATON(CONCAT('::ffff:', %s)), 'admin', 'loginFailInvalidUsername')", 1700000000, '192.0.2.1'));
        $wpdb->query($wpdb->prepare("INSERT INTO {$p}wfLogins (ctime, fail, IP, username, action) VALUES (%f, 0, INET6_ATON(CONCAT('::ffff:', %s)), 'editor', 'loginOK')", 1700000001, '192.0.2.2'));

        $attacks = $this->read('list-wordfence-blocked-attacks', [ 'limit' => 2 ])["result"];
        $this->assertSame(2, $attacks['count']);
        $this->assertSame('203.0.113.2', $attacks['attacks'][0]['ip']);
        $this->assertSame('blocked:waf', $attacks['attacks'][0]['action']);

        $blocks = $this->read('list-wordfence-blocks')["result"];
        $this->assertSame(1, $blocks['count']);
        $this->assertSame('198.51.100.1', $blocks['blocks'][0]['ip']);
        $this->assertNull($blocks['blocks'][0]['expiration']);

        $fails = $this->read('list-wordfence-login-failures')["result"];
        $this->assertSame(1, $fails['count']);
        $this->assertSame('admin', $fails['failures'][0]['username']);
        $this->assertSame('192.0.2.1', $fails['failures'][0]['ip']);
    }

    public function test_block_ip_requires_confirmation_then_blocks(): void
    {
        $r = $this->write('block-wordfence-ip', [ 'ip' => '203.0.113.5' ]);
        $this->assertSame('confirmation_required', $r['error']['code']);
        $this->assertSame([], $this->fake->calls);

        $r = $this->write('block-wordfence-ip', [ 'ip' => '203.0.113.5', 'duration' => 3600 ], true);
        $this->assertArrayNotHasKey('error', $r);
        $this->assertSame([ 'block', '203.0.113.5', 'Blocked via WP MCP', 3600 ], $this->fake->calls[0]);
    }

    public function test_block_ip_refuses_an_invalid_address_without_side_effects(): void
    {
        $r = $this->write('block-wordfence-ip', [ 'ip' => 'not-an-ip' ], true);
        $this->assertSame('invalid_ip', $r['error']['code']);
        $this->assertSame([], $this->fake->calls);
    }

    public function test_unblock_and_ignore_issue(): void
    {
        $r = $this->write('unblock-wordfence-ip', [ 'ip' => '203.0.113.5' ], true);
        $this->assertArrayNotHasKey('error', $r);
        $this->assertSame([ 'unblock', '203.0.113.5' ], $this->fake->calls[0]);

        $r = $this->write('ignore-wordfence-scan-issue', [ 'issue_id' => 7, 'how' => 'ignoreP' ], true);
        $this->assertArrayNotHasKey('error', $r);
        $this->assertSame([ 'ignore', 7, 'ignoreP' ], $this->fake->calls[1]);

        $r = $this->write('ignore-wordfence-scan-issue', [ 'issue_id' => 99 ], true);
        $this->assertSame('wordfence_issue_not_found', $r['error']['code']);
    }
}
