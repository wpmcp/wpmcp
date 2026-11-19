<?php

namespace WPMCP\Tests\Pro\Export;

use WPMCP\Pro\Gate;
use WPMCP\Tools\Backup\Backup_Job_Store;
use WPMCP\Tools\Backup\Cancel_Backup_Job;
use WPMCP\Tools\Backup\Get_Backup_Status;
use WPMCP\Tools\Backup\Site_Backup_Dir;
use WPMCP\Tools\Backup\Trigger_Backup;
use WPMCP\Tools\Export\Static_Site\Run_Static_Export;
use WPMCP\Tools\Export\Static_Site\Static_Export;
use WPMCP\Tools\Export\Static_Site\Url_Discovery;

/**
 * Static HTML export (issue #854): trigger-backup type=static plans and
 * queues it, Run_Static_Export builds it in resumable steps through
 * loopback requests (served here by pre_http_request), and the finished
 * ZIP holds the pages, their assets and relative links.
 */
class StaticExportTest extends \WP_UnitTestCase
{
    /** @var array<string, array{0: int, 1: string, 2: string, 3?: string}> path => [code, type, body, location] */
    private array $routes = [];

    /** @var string[] */
    private array $fetched = [];

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Backup_Job_Store::OPTION);
        Backup_Job_Store::set_clock_for_tests(null);
        $this->set_permalink_structure('/%postname%/');
        Gate::set_pro_for_tests(true);
        add_filter(Static_Export::FILTER, '__return_true');
        add_filter('pre_http_request', [$this, 'serve'], 10, 3);
        Static_Export::register();

        $this->admin = self::factory()->user->create(['role' => 'administrator']);
        if (is_multisite()) {
            grant_super_admin($this->admin);
        }
        wp_set_current_user($this->admin);
        $this->routes = [];
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'serve'], 10);
        remove_filter(Static_Export::FILTER, '__return_true');
        remove_all_filters('wpmcp_trigger_backup_kind');
        remove_all_filters('wpmcp_backup_job_view');
        remove_all_filters('wpmcp_backup_step_limits');
        remove_all_actions(Run_Static_Export::HOOK);
        Gate::set_pro_for_tests(null);
        Backup_Job_Store::set_clock_for_tests(null);

        foreach ((array) glob(trailingslashit(Site_Backup_Dir::path()) . 'wpmcp-static-*') as $path) {
            if (is_dir((string) $path)) {
                $this->rm_tree((string) $path);
            } elseif (is_file((string) $path)) {
                unlink((string) $path);
            }
        }
        foreach (Backup_Job_Store::list() as $job) {
            wp_clear_scheduled_hook(Run_Static_Export::HOOK, [(int) $job['id']]);
        }
        delete_option(Backup_Job_Store::OPTION);
        parent::tearDown();
    }

    private function rm_tree(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    /** The loopback and asset requests, answered from $this->routes. */
    public function serve($preempt, $args, $url)
    {
        $this->fetched[] = $url;
        $parts = wp_parse_url($url);
        if ('example.org' !== ($parts['host'] ?? '')) {
            return new \WP_Error('http_request_failed', 'off-site request in a test: ' . $url);
        }
        $path  = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $route = $this->routes[ $path ] ?? $this->routes[ $parts['path'] ?? '/' ] ?? [404, 'text/html', 'Not found'];

        $headers = ['content-type' => $route[1]];
        if (isset($route[3])) {
            $headers['location'] = $route[3];
        }

        return [
            'headers'  => $headers,
            'body'     => $route[2],
            'response' => ['code' => $route[0], 'message' => 'X'],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    private function site(): void
    {
        self::factory()->post->create(['post_type' => 'page', 'post_name' => 'about', 'post_title' => 'About', 'post_status' => 'publish']);
        self::factory()->post->create(['post_type' => 'page', 'post_name' => 'contact', 'post_title' => 'Contact', 'post_status' => 'publish']);
        self::factory()->post->create(['post_type' => 'page', 'post_name' => 'secret', 'post_title' => 'Secret', 'post_status' => 'publish', 'post_password' => 'pw']);
        self::factory()->post->create(['post_type' => 'page', 'post_name' => 'draft', 'post_title' => 'Draft', 'post_status' => 'draft']);

        $head = '<link rel="stylesheet" href="http://example.org/wp-content/themes/demo/style.css?ver=1">'
            . '<script src="https://cdn.example.net/lib.js"></script>';

        $this->routes = [
            '/' => [200, 'text/html; charset=UTF-8', "<html><head>$head</head><body><a href=\"/about/\">About</a> <a href=\"/blog/\">Blog</a> <a href=\"/old/\">Old</a> <a href=\"/loop/\">Loop</a> <a href=\"/wp-admin/\">Admin</a><img src=\"/wp-content/uploads/logo.png\"></body></html>"],
            '/about/' => [200, 'text/html', "<html><head>$head</head><body><a href=\"../\">Home</a><a href=\"/contact/\">Contact</a></body></html>"],
            '/contact/' => [200, 'text/html', '<html><body><form action="/wp-admin/admin-post.php" method="post"><input name="email"></form><form role="search"><input name="s"></form></body></html>'],
            '/blog/' => [200, 'text/html', '<html><body>Blog</body></html>'],
            '/old/' => [301, 'text/html', '', 'http://example.org/about/'],
            '/loop/' => [302, 'text/html', '', 'http://example.org/loop/'],
            '/wp-content/themes/demo/style.css' => [200, 'text/css', 'body{background:url(img/bg.png)} @font-face{src:url("fonts/f.woff2")}'],
            '/wp-content/themes/demo/img/bg.png' => [200, 'image/png', 'PNG'],
            '/wp-content/themes/demo/fonts/f.woff2' => [200, 'font/woff2', 'WOFF2'],
            '/wp-content/uploads/logo.png' => [200, 'image/png', 'LOGO'],
        ];
    }

    private function trigger(array $args = []): array
    {
        $out = (new Trigger_Backup())->handle(array_merge(['type' => 'static'], $args));
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        return $out;
    }

    /** Run the job's steps until it leaves running, at most $max steps. */
    private function run_to_end(int $job_id, int $max = 60): array
    {
        $runner = new Run_Static_Export();
        for ($i = 0; $i < $max; $i++) {
            $runner->handle($job_id);
            $job = Backup_Job_Store::get($job_id);
            if (! in_array($job['status'], ['queued', 'running'], true)) {
                return $job;
            }
        }
        $this->fail('The static export did not finish within ' . $max . ' steps.');
    }

    /** @return array<string, string> ZIP entry => contents */
    private function zip_entries(string $file): array
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($file));
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name         = (string) $zip->getNameIndex($i);
            $out[ $name ] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        return $out;
    }

    public function test_is_off_until_the_site_opts_in(): void
    {
        remove_filter(Static_Export::FILTER, '__return_true');

        $out = (new Trigger_Backup())->handle(['type' => 'static']);

        $this->assertWPError($out);
        $this->assertSame('opt_in_required', $out->get_error_code());
        $this->assertStringContainsString(Static_Export::FILTER, $out->get_error_message());
        $this->assertSame([], Backup_Job_Store::list());
    }

    public function test_needs_a_pro_license(): void
    {
        Gate::set_pro_for_tests(false);

        $out = (new Trigger_Backup())->handle(['type' => 'static']);

        $this->assertWPError($out);
        $this->assertSame('pro_required', $out->get_error_code());
        $this->assertSame([], Backup_Job_Store::list());
    }

    public function test_without_the_module_the_type_is_refused_not_run_as_a_wxr_backup(): void
    {
        remove_all_filters('wpmcp_trigger_backup_kind');

        $out = (new Trigger_Backup())->handle(['type' => 'static']);

        $this->assertWPError($out);
        $this->assertSame([], Backup_Job_Store::list());
    }

    public function test_needs_pretty_permalinks(): void
    {
        $this->set_permalink_structure('');

        $out = (new Trigger_Backup())->handle(['type' => 'static']);

        $this->assertWPError($out);
        $this->assertSame('wpmcp_static_export_permalinks', $out->get_error_code());
    }

    public function test_cannot_be_scheduled(): void
    {
        $out = (new Trigger_Backup())->handle(['type' => 'static', 'every' => 'daily']);

        $this->assertWPError($out);
        $this->assertSame('wpmcp_static_export_invalid', $out->get_error_code());
    }

    public function test_plan_lists_public_urls_and_queues_nothing(): void
    {
        $this->site();
        $menu = wp_create_nav_menu('Main');
        wp_update_nav_menu_item($menu, 0, ['menu-item-title' => 'Blog', 'menu-item-url' => 'http://example.org/blog/', 'menu-item-status' => 'publish']);
        wp_update_nav_menu_item($menu, 0, ['menu-item-title' => 'Elsewhere', 'menu-item-url' => 'https://elsewhere.example.com/', 'menu-item-status' => 'publish']);

        $plan = $this->trigger(['plan' => true]);

        $this->assertTrue($plan['plan']);
        $this->assertContains('http://example.org/', $plan['urls']);
        $this->assertContains('http://example.org/about/', $plan['urls']);
        $this->assertContains('http://example.org/blog/', $plan['urls']);
        $this->assertNotContains('http://example.org/secret/', $plan['urls'], 'password-protected pages are not public');
        $this->assertNotContains('https://elsewhere.example.com/', $plan['urls']);
        $this->assertSame(1, $plan['by_source']['menu']);
        $this->assertSame(count($plan['urls']), $plan['page_count']);
        $this->assertSame($plan['page_count'] * Url_Discovery::AVERAGE_PAGE_BYTES, $plan['estimated_bytes']);
        $this->assertTrue($plan['follows_links']);
        $this->assertSame([], Backup_Job_Store::list());
        $this->assertSame([], $this->fetched, 'a plan fetches nothing');
    }

    public function test_plan_narrowed_by_url_list_refuses_off_site_and_dynamic_urls(): void
    {
        $plan = $this->trigger(['plan' => true, 'urls' => ['http://example.org/about/', 'https://other.example.com/x/', 'http://example.org/wp-login.php', 'http://example.org/?s=hi']]);

        $this->assertSame(['http://example.org/about/'], $plan['urls']);
        $this->assertFalse($plan['follows_links']);
        $reasons = array_column($plan['skipped'], 'reason', 'url');
        $this->assertSame('off-site URL', $reasons['https://other.example.com/x/']);
        $this->assertArrayHasKey('http://example.org/wp-login.php', $reasons);
        $this->assertArrayHasKey('http://example.org/?s=hi', $reasons);
    }

    public function test_plan_narrowed_by_post_type(): void
    {
        $this->site();
        $post = self::factory()->post->create(['post_name' => 'news', 'post_status' => 'publish']);

        $plan = $this->trigger(['plan' => true, 'post_types' => 'post']);

        $this->assertContains(get_permalink($post), $plan['urls']);
        $this->assertNotContains('http://example.org/about/', $plan['urls']);

        $bad = (new Trigger_Backup())->handle(['type' => 'static', 'plan' => true, 'post_types' => 'nope']);
        $this->assertWPError($bad);
    }

    public function test_queues_one_job_at_a_time(): void
    {
        $out = $this->trigger();

        $this->assertSame('queued', $out['status']);
        $this->assertNotFalse(wp_next_scheduled(Run_Static_Export::HOOK, [$out['job_id']]));
        $this->assertFalse(wp_next_scheduled('wpmcp_run_backup_job', [$out['job_id']]), 'the backup executor never sees a static job');
        $this->assertSame('static', Backup_Job_Store::get($out['job_id'])['type']);

        $again = (new Trigger_Backup())->handle(['type' => 'static']);
        $this->assertWPError($again);
        $this->assertSame('wpmcp_static_export_busy', $again->get_error_code());
    }

    public function test_builds_a_zip_whose_home_page_opens_offline_with_styles(): void
    {
        $this->site();
        $job_id = $this->trigger()['job_id'];

        $job = $this->run_to_end($job_id);

        $this->assertSame('completed', $job['status'], (string) ($job['error'] ?? ''));
        $result = $job['result'];
        $this->assertFileExists($result['file']);
        $this->assertStringStartsWith(realpath(Site_Backup_Dir::path()), realpath($result['file']));
        $this->assertSame('index.html', $result['home']);

        $zip = $this->zip_entries($result['file']);
        foreach (['index.html', 'about/index.html', 'contact/index.html', 'blog/index.html', 'old/index.html', 'wp-content/themes/demo/style.css', 'wp-content/themes/demo/img/bg.png', 'wp-content/themes/demo/fonts/f.woff2', 'wp-content/uploads/logo.png', 'wpmcp-static-report.json'] as $entry) {
            $this->assertArrayHasKey($entry, $zip, $entry . ' is in the ZIP');
        }

        // Home: styles, links and images are relative; admin and CDN stay absolute.
        $home = $zip['index.html'];
        $this->assertStringContainsString('href="wp-content/themes/demo/style.css"', $home);
        $this->assertStringContainsString('href="about/index.html"', $home);
        $this->assertStringContainsString('src="wp-content/uploads/logo.png"', $home);
        $this->assertStringContainsString('href="http://example.org/wp-admin/"', $home);
        $this->assertStringContainsString('src="https://cdn.example.net/lib.js"', $home);
        $this->assertStringContainsString('href="../index.html"', $zip['about/index.html']);
        $this->assertStringContainsString('href="../wp-content/themes/demo/style.css"', $zip['about/index.html']);

        // The stylesheet's own references stay relative to it.
        $this->assertStringContainsString('url(img/bg.png)', $zip['wp-content/themes/demo/style.css']);

        // A redirect becomes a refresh page; a loop and the off-site asset are reported.
        $this->assertStringContainsString('url=../about/index.html', $zip['old/index.html']);
        $reasons = array_column($result['skipped'], 'reason', 'url');
        $this->assertStringContainsString('redirect loop', $reasons['http://example.org/loop/']);
        $this->assertStringContainsString('off-site asset', $reasons['https://cdn.example.net/lib.js']);
        $this->assertArrayNotHasKey('loop/index.html', $zip);

        // Pages that need the live site are listed.
        $dynamic = array_column($result['dynamic_pages'], 'has', 'url');
        $this->assertSame(['search', 'form'], $dynamic['http://example.org/contact/']);

        // Nothing private was fetched and nothing outside the site was asked for.
        $this->assertNotContains('http://example.org/secret/', $this->fetched);
        $this->assertNotContains('http://example.org/wp-admin/', $this->fetched);
        $this->assertNotContains('https://cdn.example.net/lib.js', $this->fetched);

        // The work folder is gone.
        $this->assertSame([], (array) glob(trailingslashit(Site_Backup_Dir::path()) . 'wpmcp-static-*.work'));
    }

    public function test_survives_a_killed_step_and_resumes_from_the_saved_state(): void
    {
        $this->site();
        add_filter('wpmcp_backup_step_limits', static function (array $limits): array {
            $limits['seconds'] = 0.0;
            return $limits;
        });
        Backup_Job_Store::set_clock_for_tests(1700000000);

        $job_id = $this->trigger()['job_id'];
        $runner = new Run_Static_Export();
        $runner->handle($job_id);
        $runner->handle($job_id);

        $job = Backup_Job_Store::get($job_id);
        $this->assertSame('running', $job['status']);
        $done = (int) $job['progress']['pages_done'] + (int) $job['progress']['assets_done'];
        $this->assertGreaterThan(0, $done);
        $this->assertNotFalse(wp_next_scheduled(Run_Static_Export::HOOK, [$job_id]));

        // The host kills the next step mid-page: the record still says a
        // step is running, and nothing reports for longer than the idle limit.
        $build                 = $job['build'];
        $build['step_running'] = true;
        Backup_Job_Store::update($job_id, ['build' => $build]);
        Backup_Job_Store::set_clock_for_tests(1700000000 + Run_Static_Export::STEP_IDLE_SECONDS + 5);

        $this->fetched = [];
        $runner->handle($job_id);
        $after = Backup_Job_Store::get($job_id);
        $this->assertSame('running', $after['status']);
        $this->assertGreaterThan($done, (int) $after['progress']['pages_done'] + (int) $after['progress']['assets_done'], 'resumed, not restarted');
        $this->assertNotContains('http://example.org/', $this->fetched, 'the home page is not fetched again');

        $final = $this->run_to_end($job_id);
        $this->assertSame('completed', $final['status'], (string) ($final['error'] ?? ''));
        $this->assertArrayHasKey('index.html', $this->zip_entries($final['result']['file']));
    }

    public function test_a_step_still_reporting_is_not_taken_over(): void
    {
        $this->site();
        add_filter('wpmcp_backup_step_limits', static function (array $limits): array {
            $limits['seconds'] = 0.0;
            return $limits;
        });
        Backup_Job_Store::set_clock_for_tests(1700000000);
        $job_id = $this->trigger()['job_id'];
        (new Run_Static_Export())->handle($job_id);

        $job                   = Backup_Job_Store::get($job_id);
        $build                 = $job['build'];
        $build['step_running'] = true;
        Backup_Job_Store::update($job_id, ['build' => $build]);
        $before = $job['progress'];

        (new Run_Static_Export())->handle($job_id);

        $this->assertSame($before, Backup_Job_Store::get($job_id)['progress']);
    }

    public function test_cancel_stops_the_build_and_removes_its_work(): void
    {
        $this->site();
        add_filter('wpmcp_backup_step_limits', static function (array $limits): array {
            $limits['seconds'] = 0.0;
            return $limits;
        });
        $job_id = $this->trigger()['job_id'];
        (new Run_Static_Export())->handle($job_id);
        $this->assertNotSame([], (array) glob(trailingslashit(Site_Backup_Dir::path()) . 'wpmcp-static-*.work'));

        $out = (new Cancel_Backup_Job())->handle(['job_id' => $job_id]);
        $this->assertSame('canceled', $out['status']);

        (new Run_Static_Export())->handle($job_id);

        $this->assertSame([], (array) glob(trailingslashit(Site_Backup_Dir::path()) . 'wpmcp-static-*.work'));
        $this->assertSame('canceled', Backup_Job_Store::get($job_id)['status']);
        $this->assertArrayNotHasKey('build', Backup_Job_Store::get($job_id));
        $this->assertFalse(wp_next_scheduled(Run_Static_Export::HOOK, [$job_id]));
    }

    public function test_a_job_whose_owner_lost_the_capability_is_canceled(): void
    {
        $job_id = $this->trigger()['job_id'];
        if (is_multisite()) {
            revoke_super_admin($this->admin);
        }
        (new \WP_User($this->admin))->set_role('subscriber');

        (new Run_Static_Export())->handle($job_id);

        $job = Backup_Job_Store::get($job_id);
        $this->assertSame('canceled', $job['status']);
        $this->assertStringContainsString('no longer has', $job['error']);
    }

    public function test_status_carries_a_private_short_lived_download_link(): void
    {
        $this->site();
        $job_id = $this->trigger()['job_id'];
        $this->run_to_end($job_id);

        $status = (new Get_Backup_Status())->handle(['job_id' => $job_id]);

        $this->assertArrayHasKey('download_url', $status);
        $this->assertGreaterThan(time(), $status['download_expires_at']);
        $this->assertLessThanOrEqual(time() + Static_Export::DOWNLOAD_TTL, $status['download_expires_at']);
        $this->assertArrayNotHasKey('build', $status);

        parse_str((string) wp_parse_url($status['download_url'], PHP_URL_QUERY), $query);
        $this->assertSame(Static_Export::DOWNLOAD_ACTION, $query['action']);
        $this->assertSame(realpath($status['result']['file']), Static_Export::download_file($query, $this->admin));

        $other = self::factory()->user->create(['role' => 'administrator']);
        if (is_multisite()) {
            grant_super_admin($other);
        }
        $this->assertWPError(Static_Export::download_file($query, $other), 'signed for one user only');
        $this->assertWPError(Static_Export::download_file($query, 0));

        $tampered        = $query;
        $tampered['exp'] = (string) ((int) $query['exp'] + 3600);
        $this->assertWPError(Static_Export::download_file($tampered, $this->admin));

        $expired = wp_parse_url(Static_Export::download_url($job_id, $this->admin, time() - 1), PHP_URL_QUERY);
        parse_str((string) $expired, $old);
        $this->assertSame('wpmcp_static_download_expired', Static_Export::download_file($old, $this->admin)->get_error_code());
    }

    public function test_retention_keeps_the_newest_exports(): void
    {
        add_filter('wpmcp_static_export_keep', static fn(): int => 1);
        $this->routes = ['/' => [200, 'text/html', '<html><body>Home</body></html>']];

        $first = $this->trigger(['urls' => ['http://example.org/']])['job_id'];
        $one   = $this->run_to_end($first);
        $second = $this->trigger(['urls' => ['http://example.org/']])['job_id'];
        $two    = $this->run_to_end($second);
        remove_all_filters('wpmcp_static_export_keep');

        $this->assertSame('completed', $two['status']);
        $this->assertFileExists($two['result']['file']);
        $this->assertFileDoesNotExist($one['result']['file']);
        $this->assertTrue(Backup_Job_Store::get($first)['result']['deleted']);
    }

    public function test_assets_on_disk_are_read_directly_but_never_from_plugin_data_folders(): void
    {
        $uploads = wp_upload_dir();
        $css     = trailingslashit($uploads['basedir']) . 'static-export-test.css';
        file_put_contents($css, 'p{color:red}');
        $private = trailingslashit(Site_Backup_Dir::path()) . 'secret.css';
        Site_Backup_Dir::protect(Site_Backup_Dir::path());
        file_put_contents($private, 'SECRET');

        $css_url     = trailingslashit($uploads['baseurl']) . 'static-export-test.css';
        $private_url = trailingslashit($uploads['baseurl']) . 'wpmcp-site-backups/secret.css';
        $this->routes = ['/' => [200, 'text/html', '<link rel="stylesheet" href="' . $css_url . '"><link rel="stylesheet" href="' . $private_url . '">']];

        try {
            $job = $this->run_to_end($this->trigger(['urls' => ['http://example.org/']])['job_id']);
        } finally {
            unlink($css);
            unlink($private);
        }

        $this->assertSame('completed', $job['status'], (string) ($job['error'] ?? ''));
        $zip = $this->zip_entries($job['result']['file']);
        $this->assertSame('p{color:red}', $zip['wp-content/uploads/static-export-test.css']);
        $this->assertNotContains($css_url, $this->fetched, 'read from disk');
        $this->assertArrayNotHasKey('wp-content/uploads/wpmcp-site-backups/secret.css', $zip);
        $this->assertContains($private_url, $this->fetched, 'left to the web server, which denies it');
    }
}
