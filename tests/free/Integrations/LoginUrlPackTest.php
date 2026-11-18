<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Integrations\Login_Url_Pack;
use WPMCP\Integrations\Theme_Integration;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

/**
 * Issue #896: WPS Hide Login's custom login URL on the theme pair. The read
 * gives the login and redirect slugs and URLs (a missing whl_page means the
 * slug is login); the write is off until the site opts in, asks for
 * confirmation with the new URL, refuses a slug that collides with a post,
 * a query var or a reserved path (the REST prefix the MCP connection uses
 * included), probes the new login URL and the REST root after the write and
 * puts the settings back when either fails, and is one option_set snapshot.
 */
class LoginUrlPackTest extends \WP_UnitTestCase
{
    /** @var array<string,int> url fragment => status the loopback answers. */
    private array $status = [];

    /** @var list<string> */
    private array $requested = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $GLOBALS['wpdb']->query('DELETE FROM ' . Snapshot_Store::table_name());
        add_filter('pre_http_request', [ $this, 'serve' ], 1, 3);
        $this->set_permalink_structure('/%postname%/');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [ $this, 'serve' ], 1);
        remove_all_filters(Login_Url_Pack::ENABLE_FILTER);
        remove_all_filters(Login_Url_Pack::ACTIVE_FILTER);
        foreach ([ 'whl_page', 'whl_redirect_admin', Governance_Audit_Log::OPTION ] as $option) {
            delete_option($option);
        }
        parent::tearDown();
    }

    public function serve($pre, $args, $url)
    {
        $this->requested[] = (string) $url;
        $code = 200;
        foreach ($this->status as $fragment => $status) {
            if (false !== strpos($url, $fragment)) {
                $code = $status;
            }
        }
        return [ 'headers' => [], 'body' => '', 'response' => [ 'code' => $code, 'message' => '' ], 'cookies' => [], 'filename' => null ];
    }

    private function activate(): void
    {
        add_filter(Login_Url_Pack::ACTIVE_FILTER, '__return_true');
    }

    private function opt_in(): void
    {
        add_filter(Login_Url_Pack::ENABLE_FILTER, '__return_true');
    }

    private function read(): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => 'get-login-url', 'args' => [] ]);
    }

    private function write(array $args, bool $confirm = false): array
    {
        $call = [ 'operation' => 'set-login-url', 'args' => $args ];
        if ($confirm) {
            $call['confirm'] = true;
        }
        return (new Theme_Integration())->handle_write($call);
    }

    private function code(array $out): string
    {
        return (string) ($out['error']['code'] ?? '');
    }

    public function test_the_ops_are_on_the_theme_pair_and_the_write_is_off_until_opt_in(): void
    {
        $ops = array_column((new Theme_Integration())->catalog()['operations'], null, 'name');

        $this->assertSame('read', $ops['get-login-url']['mode']);
        $this->assertSame('destructive', $ops['set-login-url']['mode']);
        $this->assertFalse($ops['set-login-url']['enabled']);

        $this->activate();
        $out = $this->write([ 'login_slug' => 'team-door' ], true);
        $this->assertSame('opt_in_required', $this->code($out), (string) wp_json_encode($out));
        $this->assertStringContainsString(Login_Url_Pack::ENABLE_FILTER, $out['error']['message']);
        $this->assertFalse(get_option('whl_page'), 'a refused write changes nothing');
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_without_the_plugin_both_ops_name_wps_hide_login(): void
    {
        $out = $this->read();
        $this->assertSame('wps_hide_login_inactive', $this->code($out), (string) wp_json_encode($out));
        $this->assertStringContainsString('WPS Hide Login', $out['error']['message']);

        $this->opt_in();
        $out = $this->write([ 'login_slug' => 'team-door' ], true);
        $this->assertSame('wps_hide_login_inactive', $this->code($out));
        $this->assertFalse(get_option('whl_page'));
    }

    public function test_a_missing_option_reads_as_the_login_default(): void
    {
        $this->activate();

        $out = $this->read()['result'];

        $this->assertSame('login', $out['login_slug']);
        $this->assertSame(home_url('/login/'), $out['login_url']);
        $this->assertSame('404', $out['redirect_slug']);
        $this->assertTrue($out['wp_login_php_blocked']);
        $this->assertSame('default', $out['source']);
    }

    public function test_the_read_reports_the_custom_slugs_and_plain_permalink_urls(): void
    {
        $this->activate();
        update_option('whl_page', 'team-door');
        update_option('whl_redirect_admin', 'nothing-here');

        $out = $this->read()['result'];
        $this->assertSame('team-door', $out['login_slug']);
        $this->assertSame(home_url('/team-door/'), $out['login_url']);
        $this->assertSame(home_url('/nothing-here/'), $out['redirect_url']);
        $this->assertSame('site', $out['source']);

        $this->set_permalink_structure('');
        $out = $this->read()['result'];
        $this->assertSame(home_url('/') . '?team-door', $out['login_url']);
    }

    public function test_setting_the_slug_asks_for_confirmation_then_writes_one_snapshot_audits_and_undoes(): void
    {
        $this->activate();
        $this->opt_in();
        update_option('whl_redirect_admin', 'keep-me');

        $ask = $this->write([ 'login_slug' => 'team-door' ]);
        $this->assertSame('confirmation_required', $this->code($ask), (string) wp_json_encode($ask));
        $this->assertStringContainsString(home_url('/team-door/'), $ask['error']['message']);
        $this->assertSame(home_url('/team-door/'), $ask['error']['data']['login_url']);
        $this->assertFalse(get_option('whl_page'), 'the preview writes nothing');

        $out = $this->write([ 'login_slug' => 'team-door' ], true);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertSame('team-door', get_option('whl_page'));
        $this->assertSame('keep-me', get_option('whl_redirect_admin'), 'the redirect is untouched');
        $this->assertSame(home_url('/team-door/'), $out['result']['login_url']);
        $this->assertSame(home_url('/login/'), $out['result']['previous']['login_url']);
        $this->assertTrue((bool) array_filter($this->requested, static fn (string $url): bool => false !== strpos($url, '/team-door/')), 'the new login URL is probed');

        $audit = Governance_Audit_Log::list();
        $this->assertNotEmpty($audit);
        $this->assertStringContainsString('/team-door/', $audit[0]['reason']);

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
        $this->assertFalse(get_option('whl_page'), 'undo restores the missing option, so the slug is login again');
        $this->assertSame('keep-me', get_option('whl_redirect_admin'));
    }

    public function test_the_redirect_slug_can_be_set_alone(): void
    {
        $this->activate();
        $this->opt_in();
        update_option('whl_page', 'team-door');

        $out = $this->write([ 'redirect_slug' => 'go-away' ], true);

        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        $this->assertSame('go-away', get_option('whl_redirect_admin'));
        $this->assertSame('team-door', get_option('whl_page'));
        $this->assertSame(home_url('/go-away/'), $out['result']['redirect_url']);
    }

    public function test_slugs_that_collide_or_are_reserved_are_refused(): void
    {
        $this->activate();
        $this->opt_in();
        self::factory()->post->create([ 'post_type' => 'page', 'post_name' => 'about-us', 'post_status' => 'publish' ]);

        $cases = [
            'about-us'           => 'login_slug_collision',
            rest_get_url_prefix() => 'login_slug_reserved',
            'wp-admin'           => 'login_slug_reserved',
            'my-wp-login'        => 'login_slug_reserved',
            'author'             => 'login_slug_reserved',
            'rest_route'         => 'login_slug_reserved',
            'Team Door'          => 'invalid_login_slug',
            ''                   => 'invalid_login_slug',
        ];
        foreach ($cases as $slug => $code) {
            $out = $this->write([ 'login_slug' => (string) $slug ], true);
            $this->assertSame($code, $this->code($out), $slug . ': ' . wp_json_encode($out));
        }

        $out = $this->write([ 'login_slug' => 'same', 'redirect_slug' => 'same' ], true);
        $this->assertSame('redirect_slug_invalid', $this->code($out));

        $out = $this->write([], true);
        $this->assertSame('nothing_to_change', $this->code($out));

        $this->assertFalse(get_option('whl_page'));
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_the_current_value_is_no_change(): void
    {
        $this->activate();
        $this->opt_in();
        update_option('whl_page', 'team-door');

        $out = $this->write([ 'login_slug' => 'team-door' ], true);

        $this->assertSame('no_change', $this->code($out));
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_a_new_login_url_that_does_not_answer_puts_the_settings_back(): void
    {
        $this->activate();
        $this->opt_in();
        update_option('whl_page', 'old-door');
        $this->status = [ '/team-door/' => 404 ];

        $out = $this->write([ 'login_slug' => 'team-door' ], true);

        $this->assertSame('login_url_unreachable', $this->code($out), (string) wp_json_encode($out));
        $this->assertSame('old-door', get_option('whl_page'), 'the old slug is back');
        $this->assertSame([], Snapshot_Store::list_by_session('default'), 'no undo point is left behind');
    }

    public function test_a_rest_root_that_fails_after_the_write_puts_the_settings_back(): void
    {
        $this->activate();
        $this->opt_in();
        $this->status = [ rest_get_url_prefix() => 503 ];

        $out = $this->write([ 'login_slug' => 'team-door' ], true);

        $this->assertSame('login_url_blocked_rest', $this->code($out), (string) wp_json_encode($out));
        $this->assertFalse(get_option('whl_page'));
    }
}
