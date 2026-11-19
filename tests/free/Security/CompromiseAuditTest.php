<?php

namespace WPMCP\Tests\Free\Security;

use WPMCP\Tools\Security\Compromise_Audit;

/**
 * Issue #845: scan-security checks:["compromise"] looks for the traces an
 * attacker leaves behind: back-dated files, look-alike folders and files,
 * unexpected must-use plugins and drop-ins, orphan or misplaced cron
 * callbacks, downloadable dumps, crawler-only content and suspicious
 * administrators. Read-only: nothing scanned is executed or included.
 */
class CompromiseAuditTest extends \WP_UnitTestCase
{
    /** @var string[] absolute files to remove */
    private array $files = [];

    /** @var string[] absolute dirs to remove (deepest last) */
    private array $dirs = [];

    /** @var array<string, array{code:int, body:string, location?:string}> keyed by "ua-kind|url" */
    private array $responses = [];

    /** @var array<int, array{url:string, method:string}> */
    private array $requests = [];

    public function setUp(): void
    {
        parent::setUp();
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
    }

    public function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        foreach ($this->files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        foreach (array_reverse($this->dirs) as $dir) {
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }
        wp_clear_scheduled_hook('wpmcp_test_orphan_hook_845');
        wp_clear_scheduled_hook('wpmcp_test_planted_hook_845');
        parent::tearDown();
    }

    /**
     * Default loopback: the same page for every visitor, HEAD answers 404.
     *
     * @param mixed $pre
     * @return array<string, mixed>
     */
    public function fake_http($pre, array $args, string $url): array
    {
        $method           = strtoupper((string) ($args['method'] ?? 'GET'));
        $this->requests[] = ['url' => $url, 'method' => $method];
        $ua               = (string) ($args['user-agent'] ?? '');
        $kind             = false !== stripos($ua, 'Googlebot') ? 'crawler' : 'browser';
        if ('browser' === $kind && ! empty($args['headers']['Referer'])) {
            $kind = 'referred';
        }
        if ('HEAD' === $method) {
            $kind = 'head';
        }
        $reply = $this->responses[ $kind . '|' . $url ]
            ?? $this->responses[ $kind . '|*' ]
            ?? ('head' === $kind ? ['code' => 404, 'body' => ''] : ['code' => 200, 'body' => '<html><body><a href="https://wordpress.org/">WP</a></body></html>']);
        $headers = [];
        if (! empty($reply['location'])) {
            $headers['location'] = $reply['location'];
        }
        return [
            'headers'  => $headers,
            'body'     => $reply['body'],
            'response' => ['code' => $reply['code'], 'message' => ''],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    private function mkdir(string $dir): string
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
            $this->dirs[] = $dir;
        }
        return $dir;
    }

    private function put(string $file, string $content): string
    {
        $this->mkdir(dirname($file));
        file_put_contents($file, $content);
        $this->files[] = $file;
        return $file;
    }

    /** @return array<int, array<string, mixed>> findings with this id */
    private function with_id(array $findings, string $id): array
    {
        return array_values(array_filter($findings, static fn ($f) => $id === $f['id']));
    }

    /** @return array<int, array<string, mixed>> warnings and criticals */
    private function problems(array $findings): array
    {
        return array_values(array_filter($findings, static fn ($f) => in_array($f['status'], ['warning', 'critical'], true)));
    }

    // Pure helpers.

    public function test_backdated_needs_an_old_mtime_and_a_ctime_newer_than_its_siblings(): void
    {
        $audit = new Compromise_Audit();
        $day   = 86400;
        $now   = 1700000000;

        // Planted later than its siblings, mtime set back to look old.
        $this->assertTrue($audit->is_backdated($now - 400 * $day, $now, $now - 90 * $day));
        // Installed from a zip with the archive's old mtime, like its siblings.
        $this->assertFalse($audit->is_backdated($now - 400 * $day, $now - 90 * $day, $now - 90 * $day));
        // Edited in place: mtime and ctime move together.
        $this->assertFalse($audit->is_backdated($now, $now, $now - 90 * $day));
    }

    public function test_lookalike_pairs_catch_near_duplicates_but_not_add_ons(): void
    {
        $audit = new Compromise_Audit();
        $pairs = $audit->lookalike_pairs(['woocommerce', 'woocomerce', 'elementor', 'elementor-pro', 'wp-rocket', 'wprocket', 'akismet', 'wpforms-lite', 'wpforms']);

        $this->assertContains(['woocomerce', 'woocommerce'], $pairs);
        $this->assertContains(['wp-rocket', 'wprocket'], $pairs);
        $this->assertCount(2, $pairs);
    }

    public function test_unusual_login_reasons(): void
    {
        $audit = new Compromise_Audit();
        $this->assertSame('generic_name', $audit->unusual_login_reason('wpsupport'));
        $this->assertSame('random_looking', $audit->unusual_login_reason('a3f9c2e81b'));
        $this->assertNull($audit->unusual_login_reason('jane.doe'));
        // "admin" is the hardening audit's finding; not repeated here.
        $this->assertNull($audit->unusual_login_reason('admin'));
    }

    public function test_exposed_names(): void
    {
        $audit = new Compromise_Audit();
        $this->assertTrue($audit->is_exposed_name('backup.sql', false));
        $this->assertTrue($audit->is_exposed_name('db.sql.gz', false));
        $this->assertTrue($audit->is_exposed_name('wp-config.php.bak', false));
        $this->assertTrue($audit->is_exposed_name('site.zip', true));
        $this->assertFalse($audit->is_exposed_name('site.zip', false));
        $this->assertFalse($audit->is_exposed_name('photo.jpg', true));
        $this->assertFalse($audit->is_exposed_name('wp-config-sample.php', true));
    }

    public function test_compare_fetches_flags_crawler_only_links_and_referer_redirects(): void
    {
        $audit   = new Compromise_Audit();
        $page    = '<a href="https://example.org/">x</a>';
        $browser = ['code' => 200, 'location' => '', 'body' => $page];

        $same = $audit->compare_fetches($browser, $browser, $browser, 'example.org');
        $this->assertSame([], $same);

        $spam = $audit->compare_fetches($browser, ['code' => 200, 'location' => '', 'body' => $page . '<a href="https://cheap-pills.example/">p</a>'], $browser, 'example.org');
        $this->assertSame(['cheap-pills.example'], $spam['crawler_only_links']);

        $redirect = $audit->compare_fetches($browser, $browser, ['code' => 302, 'location' => 'https://bad.example/x', 'body' => ''], 'example.org');
        $this->assertSame('bad.example', $redirect['referer_redirect']);
    }

    // Live checks.

    public function test_backdated_plugin_file_is_reported(): void
    {
        $dir    = WP_PLUGIN_DIR . '/wpmcp-test-backdate-845';
        $old    = 1600000000;
        $real   = [];
        foreach (['a.php', 'b.php', 'c.php'] as $name) {
            $real[] = $this->put($dir . '/' . $name, "<?php\n// plugin file\n");
        }
        $planted = $this->put($dir . '/class-cache.php', "<?php\n// planted\n");
        $this->dirs[] = $dir;

        $audit = new class ($planted, $old) extends Compromise_Audit {
            private string $planted;
            private int $old;

            public function __construct(string $planted, int $old)
            {
                $this->planted = $planted;
                $this->old     = $old;
            }

            protected function file_times(string $path): array
            {
                if (realpath($path) === realpath($this->planted)) {
                    return [$this->old, time()];
                }
                if (false !== strpos($path, 'wpmcp-test-backdate-845')) {
                    return [$this->old, $this->old + 86400];
                }
                return parent::file_times($path);
            }
        };

        $report = $audit->run(20000, 60);
        $found  = $this->with_id($report['findings'], 'compromise_backdated_files');

        $this->assertCount(1, $found);
        $this->assertSame('warning', $found[0]['status']);
        $locations = array_column($found[0]['value']['files'], 'location');
        $this->assertContains('wp-content/plugins/wpmcp-test-backdate-845/class-cache.php', array_map(static fn ($l) => substr($l, strpos($l, 'wp-content/')), $locations));
        $this->assertCount(1, $locations);
    }

    public function test_planted_mu_plugin_without_header_is_reported_with_its_hash(): void
    {
        $file = $this->put(WPMU_PLUGIN_DIR . '/0-wp-cache-loader.php', "<?php\n// no header\n");
        if (! is_dir(WPMU_PLUGIN_DIR)) {
            $this->fail('mu-plugins dir missing');
        }

        $report = (new Compromise_Audit())->run(2000, 60);
        $found  = $this->with_id($report['findings'], 'compromise_unexpected_mu_plugin');

        $this->assertCount(1, $found);
        $this->assertSame('warning', $found[0]['status']);
        $this->assertSame('0-wp-cache-loader.php', basename($found[0]['value']['location']));
        $this->assertSame(hash_file('sha256', $file), $found[0]['value']['sha256']);
    }

    public function test_orphan_cron_hook_is_reported_and_points_at_unschedule_event(): void
    {
        wp_schedule_single_event(time() + 3600, 'wpmcp_test_orphan_hook_845');

        $report = (new Compromise_Audit())->run(2000, 60);
        $found  = $this->with_id($report['findings'], 'compromise_orphan_cron');

        $this->assertCount(1, $found);
        $this->assertContains('wpmcp_test_orphan_hook_845', $found[0]['value']['hooks']);
        $this->assertSame('wpmcp/unschedule-event', $found[0]['value']['next_step']);
    }

    public function test_cron_callback_living_under_uploads_is_critical(): void
    {
        $uploads = wp_upload_dir(null, false)['basedir'];
        $file    = $this->put($uploads . '/wpmcp-test-845/cb.php', "<?php\nfunction wpmcp_test_845_planted_cb() {}\n");
        $this->dirs[] = $uploads . '/wpmcp-test-845';
        require_once $file; // The fixture only; the audit itself never includes anything.
        add_action('wpmcp_test_planted_hook_845', 'wpmcp_test_845_planted_cb');
        wp_schedule_single_event(time() + 3600, 'wpmcp_test_planted_hook_845');

        $report = (new Compromise_Audit())->run(2000, 60);
        $found  = $this->with_id($report['findings'], 'compromise_cron_callback_location');

        $this->assertCount(1, $found);
        $this->assertSame('critical', $found[0]['status']);
        $this->assertSame('wpmcp_test_planted_hook_845', $found[0]['value']['callbacks'][0]['hook']);
    }

    public function test_downloadable_sql_dump_is_critical_and_blocked_one_is_info(): void
    {
        $uploads = wp_upload_dir(null, false);
        $this->put($uploads['basedir'] . '/wpmcp-test-845/site.sql', "-- dump\n");
        $this->dirs[] = $uploads['basedir'] . '/wpmcp-test-845';

        $this->responses['head|*'] = ['code' => 200, 'body' => ''];
        $report = (new Compromise_Audit())->run(20000, 60);
        $found  = $this->with_id($report['findings'], 'compromise_public_dump');
        $this->assertCount(1, $found);
        $this->assertSame('critical', $found[0]['status']);
        $this->assertStringEndsWith('wpmcp-test-845/site.sql', $found[0]['value']['files'][0]['url']);

        $this->responses['head|*'] = ['code' => 403, 'body' => ''];
        $report = (new Compromise_Audit())->run(20000, 60);
        $this->assertCount(0, $this->with_id($report['findings'], 'compromise_public_dump'));
        $this->assertCount(1, $this->with_id($report['findings'], 'compromise_dump_present'));
        $this->assertSame('info', $this->with_id($report['findings'], 'compromise_dump_present')[0]['status']);
    }

    public function test_cloaked_crawler_output_is_critical(): void
    {
        $this->responses['crawler|*'] = [
            'code' => 200,
            'body' => '<html><body><a href="https://wordpress.org/">WP</a><a href="https://casino-spam.example/">x</a></body></html>',
        ];

        $report = (new Compromise_Audit())->run(2000, 60);
        $found  = $this->with_id($report['findings'], 'compromise_cloaking');

        $this->assertCount(1, $found);
        $this->assertSame('critical', $found[0]['status']);
        $this->assertContains('casino-spam.example', $found[0]['value']['pages'][0]['crawler_only_links']);
    }

    public function test_recent_admin_with_unusual_login_is_reported_masked(): void
    {
        $id = self::factory()->user->create(['role' => 'administrator', 'user_login' => 'wpsupport']);
        wp_update_user(['ID' => $id, 'user_registered' => gmdate('Y-m-d H:i:s')]);
        // The site owner's first admin is older, so the new one stands out.
        global $wpdb;
        $wpdb->update($wpdb->users, ['user_registered' => '2020-01-01 00:00:00'], ['ID' => 1]);
        clean_user_cache(1);

        $report = (new Compromise_Audit())->run(2000, 60);
        $found  = $this->with_id($report['findings'], 'compromise_suspicious_admins');

        $this->assertCount(1, $found);
        $this->assertTrue($found[0]['value']['masked']);
        $user = $found[0]['value']['users'][0];
        $this->assertSame($id, $user['user_id']);
        $this->assertSame('w***', $user['login']);
        $this->assertContains('generic_name', $user['reasons']);
        $this->assertContains('created_recently', $user['reasons']);
    }

    public function test_clean_test_site_has_no_warnings_or_criticals(): void
    {
        global $wpdb;
        $wpdb->update($wpdb->users, ['user_registered' => '2020-01-01 00:00:00'], ['ID' => 1]);
        clean_user_cache(1);

        $report   = (new Compromise_Audit())->run(2000, 60);
        $problems = $this->problems($report['findings']);

        $this->assertSame([], $problems, wp_json_encode($problems));
        $this->assertArrayHasKey('files_checked', $report['stats']);
    }

    public function test_never_requests_another_host(): void
    {
        (new Compromise_Audit())->run(2000, 60);
        $home = wp_parse_url(home_url(), PHP_URL_HOST);
        foreach ($this->requests as $request) {
            $this->assertSame($home, wp_parse_url($request['url'], PHP_URL_HOST));
        }
        $this->assertNotEmpty($this->requests);
    }
}
