<?php

namespace WPMCP\Tests\Free\Filesystem;

use WPMCP\Tools\Filesystem\{Edit_File, Filesystem_Guard, Php_Edit_Guard, Write_File};

/**
 * PHP edits through edit-file and write-file (issue #453): a file that does
 * not parse is refused before anything is written, and a saved PHP file is
 * checked with core's scrape-key loopback (the technique the theme and
 * plugin file editor uses) on the front end and in admin. A fatal on either
 * restores the previous version inside the same call.
 *
 * No network: every loopback is answered by a pre_http_request mock that
 * checks the scrape key and nonce core would check, then decides from the
 * file on disk, so the "site" breaks exactly when the edited file says so.
 * Like a real site, the mock answers an admin request that carries no valid
 * auth cookie with the login page (the redirect followed: HTTP 200, no check
 * result), so the admin leg passes only when the check signs in (issue #601).
 */
class PhpEditGuardTest extends \WP_UnitTestCase
{
    private string $rel_dir;

    /** @var array<int, array{url: string, nonce_ok: bool, cookies: array<string, string>}> */
    private array $loopbacks = [];

    private int $http_calls = 0;

    /** @var callable|null returns a WP_Error, or [status, result|null] (null = no scrape output). */
    private $responder = null;

    public function setUp(): void
    {
        parent::setUp();
        $this->rel_dir = 'wp-content/wpmcp-php-guard-' . getmypid();
        wp_mkdir_p(ABSPATH . $this->rel_dir);
        file_put_contents(ABSPATH . $this->rel_dir . '/functions.php', "<?php\n\nfunction wpmcp_fixture() {\n    return 'ok';\n}\n");
        file_put_contents(ABSPATH . $this->rel_dir . '/notes.txt', "hello world\n");

        add_filter('wpmcp_enable_fs_writes', '__return_true');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }

        $this->loopbacks  = [];
        $this->http_calls = 0;
        $this->responder  = [$this, 'site_from_disk'];
        add_filter('pre_http_request', [$this, 'mock_http'], 10, 3);
        Php_Edit_Guard::release_lock();
    }

    public function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'mock_http'], 10);
        Php_Edit_Guard::release_lock();
        foreach ((array) glob(ABSPATH . $this->rel_dir . '/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir(ABSPATH . $this->rel_dir);
        parent::tearDown();
    }

    /** @return mixed */
    public function mock_http($pre, $args, $url)
    {
        ++$this->http_calls;
        parse_str((string) wp_parse_url((string) $url, PHP_URL_QUERY), $query);
        if (empty($query['wp_scrape_key'])) {
            return new \WP_Error('http_request_failed', 'Unexpected request in PhpEditGuardTest: ' . $url);
        }
        $key               = (string) $query['wp_scrape_key'];
        $cookies           = self::cookies_of($args, (string) $url);
        $this->loopbacks[] = [
            'url'      => (string) $url,
            'nonce_ok' => get_transient('scrape_key_' . $key) === (string) ($query['wp_scrape_nonce'] ?? ''),
            'cookies'  => $cookies,
        ];
        // wp-admin sends a request with no valid auth cookie to wp-login.php;
        // with redirects followed that is a 200 page with no check result.
        $answer = self::is_admin_url((string) $url) && ! self::signed_in((string) $url, $cookies)
            ? [200, null]
            : call_user_func($this->responder, (string) $url);
        if (is_wp_error($answer)) {
            return $answer;
        }
        [$status, $result] = $answer;
        $body = '<html>page</html>';
        if (null !== $result) {
            $body .= "\n###### wp_scraping_result_start:$key ######\n" . wp_json_encode($result)
                . "\n###### wp_scraping_result_end:$key ######\n";
        }
        return [
            'headers'  => [],
            'body'     => $body,
            'response' => ['code' => $status, 'message' => 200 === $status ? 'OK' : 'Internal Server Error'],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    /**
     * The cookies the request would send to $url, by name, applying the
     * domain and path rules WP_Http applies.
     *
     * @return array<string, string>
     */
    private static function cookies_of($args, string $url): array
    {
        $out = [];
        foreach ((array) ($args['cookies'] ?? []) as $name => $cookie) {
            if ($cookie instanceof \WP_Http_Cookie) {
                if ($cookie->test($url)) {
                    $out[$cookie->name] = (string) $cookie->value;
                }
            } else {
                $out[(string) $name] = (string) $cookie;
            }
        }
        return $out;
    }

    private static function is_admin_url(string $url): bool
    {
        return 0 === strpos($url, admin_url());
    }

    /** What auth_redirect() checks: the auth cookie for the scheme of the admin URL. */
    private static function signed_in(string $url, array $cookies): bool
    {
        $secure = 'https' === wp_parse_url($url, PHP_URL_SCHEME);
        $name   = $secure ? SECURE_AUTH_COOKIE : AUTH_COOKIE;
        if (empty($cookies[$name])) {
            return false;
        }
        return false !== wp_validate_auth_cookie($cookies[$name], $secure ? 'secure_auth' : 'auth');
    }

    /** The site fatals whenever the fixture directory holds a file calling wpmcp_missing(). */
    public function site_from_disk(string $url): array
    {
        foreach ((array) glob(ABSPATH . $this->rel_dir . '/*.php') as $file) {
            $code = (string) file_get_contents((string) $file);
            if (false !== strpos($code, 'wpmcp_missing(')) {
                return [500, [
                    'type'    => E_ERROR,
                    'message' => 'Uncaught Error: Call to undefined function wpmcp_missing()',
                    'file'    => $this->rel_dir . '/' . basename((string) $file),
                    'line'    => 4,
                ]];
            }
        }
        return [200, true];
    }

    private function functions_php(): string
    {
        return (string) file_get_contents(ABSPATH . $this->rel_dir . '/functions.php');
    }

    public function test_lint_reports_the_parser_message_and_line(): void
    {
        $this->assertNull(Php_Edit_Guard::lint("<?php\necho 'fine';\n"));

        $error = Php_Edit_Guard::lint("<?php\n\nfunction broken( {\n");
        $this->assertIsArray($error);
        $this->assertSame(3, $error['line']);
        $this->assertStringContainsString('syntax error', $error['message']);
    }

    public function test_edit_that_does_not_parse_is_refused_and_nothing_is_written(): void
    {
        $before = $this->functions_php();
        try {
            (new Edit_File())->handle([
                'path'       => $this->rel_dir . '/functions.php',
                'old_string' => "return 'ok';",
                'new_string' => "return 'ok'",
            ]);
            $this->fail('A PHP edit that does not parse must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('syntax error', $e->getMessage());
            $this->assertMatchesRegularExpression('/line 5\b/', $e->getMessage());
        }
        $this->assertSame($before, $this->functions_php());
        $this->assertSame([], $this->loopbacks, 'A refused edit must not run the site check.');
    }

    public function test_write_that_does_not_parse_is_refused_and_no_file_is_created(): void
    {
        try {
            (new Write_File())->handle(['path' => $this->rel_dir . '/new.php', 'content' => "<?php\nif (true) {\n"]);
            $this->fail('A PHP write that does not parse must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('syntax error', $e->getMessage());
            $this->assertMatchesRegularExpression('/line \d+/', $e->getMessage());
        }
        $this->assertFileDoesNotExist(ABSPATH . $this->rel_dir . '/new.php');
    }

    public function test_edit_that_fatals_is_reverted_in_the_same_call(): void
    {
        $before = $this->functions_php();
        try {
            (new Edit_File())->handle([
                'path'       => $this->rel_dir . '/functions.php',
                'old_string' => "return 'ok';",
                'new_string' => "return wpmcp_missing();",
            ]);
            $this->fail('An edit that fatals must be reported as an error.');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('Call to undefined function wpmcp_missing()', $message);
            $this->assertStringContainsString('500', $message);
            $this->assertStringContainsString('functions.php', $message);
            $this->assertMatchesRegularExpression('/revert|restor/i', $message);
        }
        $this->assertSame($before, $this->functions_php(), 'The previous version must be back on disk.');
        foreach ($this->loopbacks as $loopback) {
            $this->assertTrue($loopback['nonce_ok'], 'Each loopback carries the scrape key and nonce core checks.');
        }
    }

    public function test_admin_only_fatal_is_also_reverted(): void
    {
        $this->responder = function (string $url) {
            $broken = false !== strpos($this->functions_php(), 'wpmcp_admin_break');
            if ($broken && false !== strpos($url, '/wp-admin/')) {
                return [500, ['type' => E_ERROR, 'message' => 'Admin fatal', 'file' => 'x.php', 'line' => 1]];
            }
            return [200, true];
        };
        $before = $this->functions_php();
        try {
            (new Edit_File())->handle([
                'path'       => $this->rel_dir . '/functions.php',
                'old_string' => "return 'ok';",
                'new_string' => "return 'wpmcp_admin_break';",
            ]);
            $this->fail('An edit that fatals in admin must be reported as an error.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Admin fatal', $e->getMessage());
        }
        $this->assertSame($before, $this->functions_php());
    }

    public function test_passing_edit_is_saved_checked_on_front_end_and_admin_and_restorable(): void
    {
        $result = (new Edit_File())->handle([
            'path'       => $this->rel_dir . '/functions.php',
            'old_string' => "return 'ok';",
            'new_string' => "return 'changed';",
        ]);

        $this->assertStringContainsString("return 'changed';", $this->functions_php());
        $this->assertSame('passed', $result['php_check']);
        $this->assertTrue($result['recoverable']);

        $urls = implode(' ', array_column($this->loopbacks, 'url'));
        $this->assertStringContainsString(untrailingslashit(home_url()), $urls);
        $this->assertStringContainsString('/wp-admin/', $urls);

        $backup = ABSPATH . $result['backup'];
        $this->assertTrue(Filesystem_Guard::restore($backup, ABSPATH . $this->rel_dir . '/functions.php'));
        $this->assertStringContainsString("return 'ok';", $this->functions_php());
        @unlink($backup);
    }

    public function test_new_php_file_that_fatals_is_removed(): void
    {
        try {
            (new Write_File())->handle([
                'path'    => $this->rel_dir . '/extra.php',
                'content' => "<?php\n\nwpmcp_missing();\n",
            ]);
            $this->fail('A new PHP file that fatals must be reported as an error.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('wpmcp_missing', $e->getMessage());
        }
        $this->assertFileDoesNotExist(ABSPATH . $this->rel_dir . '/extra.php');
    }

    public function test_write_that_breaks_the_loopback_without_output_is_reverted(): void
    {
        $this->responder = function (string $url) {
            if (false !== strpos($this->functions_php(), 'white_screen')) {
                return [500, null];
            }
            return [200, true];
        };
        $before = $this->functions_php();
        try {
            (new Write_File())->handle([
                'path'    => $this->rel_dir . '/functions.php',
                'content' => "<?php\n// white_screen\n",
            ]);
            $this->fail('A write after which the site stops answering must be reverted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('500', $e->getMessage());
        }
        $this->assertSame($before, $this->functions_php());
    }

    public function test_unreachable_loopback_refuses_the_write_unless_overridden(): void
    {
        $this->responder = static fn () => new \WP_Error('http_request_failed', 'cURL error 7: Failed to connect');
        $before          = $this->functions_php();
        try {
            (new Edit_File())->handle([
                'path'       => $this->rel_dir . '/functions.php',
                'old_string' => "return 'ok';",
                'new_string' => "return 'changed';",
            ]);
            $this->fail('A PHP edit that cannot be checked must be refused without the override.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Failed to connect', $e->getMessage());
            $this->assertStringContainsString('unchecked', $e->getMessage());
        }
        $this->assertSame($before, $this->functions_php());

        $result = (new Edit_File())->handle([
            'path'       => $this->rel_dir . '/functions.php',
            'old_string' => "return 'ok';",
            'new_string' => "return 'changed';",
            'unchecked'  => true,
        ]);
        $this->assertStringContainsString("return 'changed';", $this->functions_php());
        $this->assertStringStartsWith('unchecked', $result['php_check']);
        $this->assertStringContainsString('Failed to connect', $result['php_check']);
        $this->assertTrue($result['recoverable']);
        @unlink(ABSPATH . $result['backup']);
    }

    public function test_only_one_php_edit_runs_at_a_time(): void
    {
        $this->assertTrue(Php_Edit_Guard::acquire_lock());
        $before = $this->functions_php();
        try {
            (new Edit_File())->handle([
                'path'       => $this->rel_dir . '/functions.php',
                'old_string' => "return 'ok';",
                'new_string' => "return 'changed';",
            ]);
            $this->fail('A second concurrent PHP edit must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('in progress', $e->getMessage());
        }
        $this->assertSame($before, $this->functions_php());
        Php_Edit_Guard::release_lock();

        (new Edit_File())->handle([
            'path'       => $this->rel_dir . '/functions.php',
            'old_string' => "return 'ok';",
            'new_string' => "return 'changed';",
        ]);
        $this->assertTrue(Php_Edit_Guard::acquire_lock(), 'The lock is released after an edit.');
    }

    public function test_active_theme_functions_php_is_checked_and_reverted(): void
    {
        $slug  = 'wpmcp-guard-theme-' . getmypid();
        $dir   = get_theme_root() . '/' . $slug;
        wp_mkdir_p($dir);
        file_put_contents($dir . '/style.css', "/*\nTheme Name: Guard Fixture\n*/\n");
        file_put_contents($dir . '/functions.php', "<?php\n\nadd_action('init', '__return_true');\n");
        $filter = static fn () => $slug;
        add_filter('pre_option_stylesheet', $filter);
        add_filter('pre_option_template', $filter);
        $this->responder = function (string $url) use ($dir) {
            return false !== strpos((string) file_get_contents($dir . '/functions.php'), 'wpmcp_missing(')
                ? [500, ['type' => E_ERROR, 'message' => 'Theme fatal', 'file' => 'functions.php', 'line' => 3]]
                : [200, true];
        };

        try {
            (new Edit_File())->handle([
                'path'       => Filesystem_Guard::to_relative($dir . '/functions.php'),
                'old_string' => "add_action('init', '__return_true');",
                'new_string' => 'wpmcp_missing();',
            ]);
            $this->fail('A fatal in the active theme must be reverted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Theme fatal', $e->getMessage());
        } finally {
            $after = (string) file_get_contents($dir . '/functions.php');
            @unlink($dir . '/functions.php');
            @unlink($dir . '/style.css');
            @rmdir($dir);
        }
        $this->assertStringContainsString("add_action('init', '__return_true');", $after);
        $this->assertNotEmpty($this->loopbacks);
    }

    public function test_inactive_plugin_file_is_syntax_checked_only(): void
    {
        if (is_multisite()) {
            $this->markTestSkipped('Single-site by nature: on a network another site may run the plugin, so Php_Edit_Guard::is_loaded() treats every file as loaded.');
        }
        $slug = 'wpmcp-guard-inactive-' . getmypid();
        $dir  = WP_PLUGIN_DIR . '/' . $slug;
        wp_mkdir_p($dir);
        file_put_contents($dir . '/plugin.php', "<?php\n/* Plugin Name: Guard Inactive */\n");
        try {
            $result = (new Write_File())->handle([
                'path'    => Filesystem_Guard::to_relative($dir . '/plugin.php'),
                'content' => "<?php\n/* Plugin Name: Guard Inactive */\necho 1;\n",
            ]);
            $this->assertStringStartsWith('syntax only', $result['php_check']);
            $this->assertSame([], $this->loopbacks, 'An inactive plugin is not loaded, so no loopback is needed.');
            if (! empty($result['backup'])) {
                @unlink(ABSPATH . $result['backup']);
            }
        } finally {
            @unlink($dir . '/plugin.php');
            @rmdir($dir);
        }
    }

    public function test_wpmcp_own_files_are_recognized(): void
    {
        // wpmcp's own directory is outside ABSPATH in this suite, so the
        // path guard would refuse it first; the ownership rule is pinned
        // directly instead, including a symlinked or relative spelling.
        $this->assertTrue(Php_Edit_Guard::is_own_file((string) realpath(WPMCP_FILE)));
        $this->assertTrue(Php_Edit_Guard::is_own_file(WPMCP_DIR . 'src/Plugin.php'));
        $this->assertFalse(Php_Edit_Guard::is_own_file(ABSPATH . $this->rel_dir . '/functions.php'));
        $this->assertFalse(Php_Edit_Guard::is_own_file(rtrim(WPMCP_DIR, '/') . '-other/x.php'));
    }

    public function test_non_php_files_make_no_site_check(): void
    {
        $result = (new Edit_File())->handle([
            'path'       => $this->rel_dir . '/notes.txt',
            'old_string' => 'world',
            'new_string' => 'there',
        ]);
        $this->assertSame("hello there\n", file_get_contents(ABSPATH . $this->rel_dir . '/notes.txt'));
        $this->assertArrayNotHasKey('php_check', $result);
        $this->assertSame(0, $this->http_calls);
        @unlink(ABSPATH . $result['backup']);

        $written = (new Write_File())->handle(['path' => $this->rel_dir . '/broken.txt', 'content' => '<?php if (']);
        $this->assertSame('created', $written['action']);
        $this->assertArrayNotHasKey('php_check', $written);
        $this->assertSame(0, $this->http_calls);
    }

    public function test_admin_leg_signs_in_without_a_browser_session_and_leaves_no_session_behind(): void
    {
        // An MCP call arrives over REST with an Application Password: there
        // is no browser session and $_COOKIE holds no auth cookie (issue #601).
        $_COOKIE  = [];
        $user_id  = get_current_user_id();
        $sessions = count(\WP_Session_Tokens::get_instance($user_id)->get_all());

        $result = (new Write_File())->handle([
            'path'    => $this->rel_dir . '/plain.php',
            'content' => "<?php\n// harmless, valid\n",
        ]);

        $this->assertSame('passed', $result['php_check']);
        $this->assertFileExists(ABSPATH . $this->rel_dir . '/plain.php');

        $admin = array_values(array_filter($this->loopbacks, static fn ($l) => self::is_admin_url($l['url'])));
        $front = array_values(array_filter($this->loopbacks, static fn ($l) => ! self::is_admin_url($l['url'])));
        $this->assertNotEmpty($admin, 'The admin leg must run.');
        $this->assertNotEmpty($front, 'The front end leg must run.');
        foreach ($front as $loopback) {
            $this->assertSame([], $loopback['cookies'], 'The front end leg checks the site as a visitor sees it.');
        }

        // The sign-in lasts for the check only: its session is destroyed, so
        // the cookie it used no longer authenticates anyone.
        $this->assertCount($sessions, \WP_Session_Tokens::get_instance($user_id)->get_all());
        foreach ($admin as $loopback) {
            foreach ($loopback['cookies'] as $value) {
                $this->assertFalse(wp_validate_auth_cookie($value, 'auth'));
                $this->assertFalse(wp_validate_auth_cookie($value, 'secure_auth'));
                $this->assertStringNotContainsString($value, (string) wp_json_encode($result));
            }
        }
        if (! empty($result['backup'])) {
            @unlink(ABSPATH . $result['backup']);
        }
    }

    public function test_admin_sign_in_cookies_are_bound_to_the_admin_host(): void
    {
        $_COOKIE = [];
        $sent    = [];
        $spy     = static function ($pre, $args, $url) use (&$sent) {
            if (0 === strpos((string) $url, admin_url())) {
                $sent = (array) ($args['cookies'] ?? []);
            }
            return $pre;
        };
        add_filter('pre_http_request', $spy, 5, 3);
        try {
            $this->assertSame('ok', Php_Edit_Guard::probe()['state']);
        } finally {
            remove_filter('pre_http_request', $spy, 5);
        }

        $this->assertNotEmpty($sent);
        $host = (string) wp_parse_url(admin_url(), PHP_URL_HOST);
        foreach ($sent as $cookie) {
            $this->assertInstanceOf(\WP_Http_Cookie::class, $cookie, 'A plain name and value would follow a redirect to any host.');
            $this->assertSame($host, $cookie->domain);
            $this->assertTrue($cookie->host_only);
            $this->assertFalse($cookie->test('http://elsewhere.example/wp-admin/'), 'The sign-in cookies must not be sent to another host.');
        }
    }

    public function test_runtime_fatal_is_reverted_without_a_browser_session(): void
    {
        $_COOKIE = [];
        $this->responder = function (string $url) {
            $broken = false !== strpos($this->functions_php(), 'wpmcp_admin_break');
            if ($broken && self::is_admin_url($url)) {
                return [500, ['type' => E_ERROR, 'message' => 'Admin fatal', 'file' => 'x.php', 'line' => 1]];
            }
            return [200, true];
        };
        $before = $this->functions_php();
        try {
            (new Edit_File())->handle([
                'path'       => $this->rel_dir . '/functions.php',
                'old_string' => "return 'ok';",
                'new_string' => "return 'wpmcp_admin_break';",
            ]);
            $this->fail('A fatal in admin must be reverted over an Application Password connection.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Admin fatal', $e->getMessage());
            $this->assertStringNotContainsString(AUTH_COOKIE, $e->getMessage());
        }
        $this->assertSame($before, $this->functions_php());
    }

    public function test_without_a_user_the_admin_leg_cannot_pass_and_the_write_is_refused(): void
    {
        $_COOKIE = [];
        wp_set_current_user(0);
        $before = $this->functions_php();
        try {
            Php_Edit_Guard::write(ABSPATH . $this->rel_dir . '/functions.php', "<?php\n// changed\n", false);
            $this->fail('With no user to sign in as, the admin leg cannot run, so the write must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('admin', $e->getMessage());
            $this->assertStringContainsString('unchecked', $e->getMessage());
        }
        $this->assertSame($before, $this->functions_php());
    }
}
