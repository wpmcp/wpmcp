<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Admin\Announcements;
use WPMCP\Admin\Audit_Log_Page;
use WPMCP\Memory\Memory_Store;
use WPMCP\Plugin;

/**
 * Issue #138: the admin announcements feed. Fetched from WP MCP Cloud
 * through Cloud_Client, cached 24 hours in a transient (failures cached
 * one hour so a down endpoint self-heals), rendered as dismissible dated
 * notices on wpmcp admin screens ONLY, with per-user dismissal state in
 * user meta. Every failure mode (cloud unconfigured, unreachable, HTTP
 * error, malformed feed, empty feed) renders nothing at all.
 */
class AnnouncementsTest extends \WP_UnitTestCase
{
    private int $admin_id;

    /** @var array<int, string> URLs of outbound HTTP requests made. */
    private array $requests = [];

    /** @var mixed The canned pre_http_request response. */
    private $canned_response;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin_id);
        update_option('wpmcp_cloud_url', 'https://cloud.example');
        update_option('wpmcp_cloud_key', 'secret-key');
        delete_transient(Announcements::TRANSIENT);
        $this->requests        = [];
        $this->canned_response = $this->feed_response([
            ['id' => 'launch-1', 'title' => 'Launch', 'body' => 'v1 is out.', 'url' => 'https://wpmcp-pro.com/launch', 'date' => '2026-08-01'],
            ['id' => 'tip-2', 'title' => 'Tip', 'body' => 'Try governance.', 'url' => '', 'date' => '2026-08-05'],
        ]);
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        delete_transient(Announcements::TRANSIENT);
        delete_option('wpmcp_cloud_url');
        delete_option('wpmcp_cloud_key');
        unset($_GET['_wpnonce'], $_GET['announcement_id'], $_GET['redirect_to']);
        parent::tearDown();
    }

    /** Serves the canned response and records every outbound request. */
    public function fake_http($pre, $args, $url)
    {
        $this->requests[] = $url;
        return $this->canned_response;
    }

    /** @param array<int, array<string, string>> $items */
    private function feed_response(array $items): array
    {
        return [
            'headers'  => [],
            'response' => ['code' => 200, 'message' => 'OK'],
            'body'     => (string) wp_json_encode(['announcements' => $items]),
            'cookies'  => [],
            'filename' => null,
        ];
    }

    private function rendered_notices(): string
    {
        ob_start();
        (new Announcements())->render_notices();
        return (string) ob_get_clean();
    }

    private function transient_timeout(): int
    {
        return (int) get_option('_transient_timeout_' . Announcements::TRANSIENT, 0);
    }

    public function test_feed_is_fetched_once_and_cached_for_24_hours(): void
    {
        $announcements = new Announcements();

        $first  = $announcements->get();
        $second = $announcements->get();

        $this->assertCount(2, $first);
        $this->assertSame($first, $second);
        $this->assertCount(1, $this->requests, 'The second get() must be served from the transient.');
        $this->assertStringContainsString('/wpmcp-cloud/v1/announcements', $this->requests[0]);

        $this->assertEqualsWithDelta(time() + DAY_IN_SECONDS, $this->transient_timeout(), 5.0);
    }

    public function test_unconfigured_cloud_yields_nothing_and_never_touches_the_network(): void
    {
        delete_option('wpmcp_cloud_url');
        delete_option('wpmcp_cloud_key');

        $this->assertSame([], (new Announcements())->get());
        $this->assertSame([], $this->requests);
        $this->assertFalse(get_transient(Announcements::TRANSIENT));
    }

    public function test_a_failed_fetch_is_silent_and_cached_for_a_shorter_retry_window(): void
    {
        $this->canned_response = new \WP_Error('http_request_failed', 'no route to host');

        $announcements = new Announcements();
        $this->assertSame([], $announcements->get());
        $this->assertSame([], $announcements->get());
        $this->assertCount(1, $this->requests, 'A cached failure must not be re-fetched on every page load.');

        $this->assertEqualsWithDelta(time() + HOUR_IN_SECONDS, $this->transient_timeout(), 5.0);
    }

    public function test_malformed_items_are_skipped_and_fields_are_sanitized(): void
    {
        $this->canned_response = $this->feed_response([
            ['title' => 'No id, dropped'],
            [
                'id'    => 'Sneaky One!',
                'title' => '<script>alert(1)</script>Hello',
                'body'  => "Line\nbreaks <b>and tags</b>",
                'url'   => 'javascript:alert(1)',
                'date'  => '2026-08-09',
            ],
        ]);

        $items = (new Announcements())->get();

        $this->assertCount(1, $items);
        $this->assertSame('sneakyone', $items[0]['id']);
        $this->assertStringNotContainsString('<script>', $items[0]['title']);
        $this->assertStringNotContainsString('<b>', $items[0]['body']);
        $this->assertSame('', $items[0]['url'], 'A javascript: URL must be rejected.');
    }

    public function test_a_non_array_feed_body_yields_an_empty_list(): void
    {
        $this->canned_response = [
            'headers'  => [],
            'response' => ['code' => 200, 'message' => 'OK'],
            'body'     => '"just a string"',
            'cookies'  => [],
            'filename' => null,
        ];

        $this->assertSame([], (new Announcements())->get());
    }

    public function test_the_feed_is_capped_at_ten_items(): void
    {
        $items = [];
        for ($i = 1; $i <= 12; $i++) {
            $items[] = ['id' => "item-{$i}", 'title' => "Item {$i}", 'body' => 'x'];
        }
        $this->canned_response = $this->feed_response($items);

        $this->assertCount(10, (new Announcements())->get());
    }

    public function test_dismissal_is_per_user_and_persisted_in_user_meta(): void
    {
        $other_id      = self::factory()->user->create(['role' => 'administrator']);
        $announcements = new Announcements();

        $announcements->dismiss($this->admin_id, 'launch-1');

        $this->assertTrue($announcements->is_dismissed($this->admin_id, 'launch-1'));
        $this->assertFalse($announcements->is_dismissed($other_id, 'launch-1'));

        $mine = wp_list_pluck($announcements->visible_for($this->admin_id), 'id');
        $this->assertSame(['tip-2'], $mine);

        $theirs = wp_list_pluck($announcements->visible_for($other_id), 'id');
        $this->assertSame(['launch-1', 'tip-2'], $theirs);

        $this->assertSame(['launch-1'], get_user_meta($this->admin_id, Announcements::META_DISMISSED, true));
    }

    public function test_dismissing_twice_stores_the_id_once(): void
    {
        $announcements = new Announcements();
        $announcements->dismiss($this->admin_id, 'launch-1');
        $announcements->dismiss($this->admin_id, 'launch-1');

        $this->assertSame(['launch-1'], get_user_meta($this->admin_id, Announcements::META_DISMISSED, true));
    }

    public function test_notices_render_on_wpmcp_screens_only(): void
    {
        set_current_screen('dashboard');
        $this->assertSame('', $this->rendered_notices(), 'Announcements must never render outside wpmcp screens.');

        set_current_screen('toplevel_page_wpmcp');
        $html = $this->rendered_notices();
        $this->assertStringContainsString('wpmcp-announcement', $html);
        $this->assertStringContainsString('Launch', $html);
        $this->assertStringContainsString('2026-08-01', $html);
        $this->assertStringContainsString('https://wpmcp-pro.com/launch', $html);
        $this->assertStringContainsString(Announcements::DISMISS_ACTION, $html, 'Each notice must carry a dismiss link.');
    }

    public function test_notices_render_on_wpmcp_submenu_screens(): void
    {
        set_current_screen('wpmcp_page_wpmcp-connection');
        $this->assertStringContainsString('Launch', $this->rendered_notices());
    }

    /**
     * Core derives a submenu's screen id from the SANITIZED MENU TITLE of its
     * parent (wp-admin/includes/plugin.php, add_menu_page writes
     * $admin_page_hooks[$slug] = sanitize_title($menu_title)), and our title
     * carries the pending-proposals badge, so with proposals waiting the
     * Audit Log screen is "wpmcp-N_page_wpmcp-audit-log", not "wpmcp_page_".
     * The id is taken from core here, never typed by hand.
     */
    public function test_notices_render_on_the_real_submenu_screen_id_while_the_menu_carries_a_badge(): void
    {
        if (! post_type_exists(Memory_Store::POST_TYPE)) {
            $this->markTestSkipped('memory group off in this flavor');
        }
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        self::factory()->post->create(['post_type' => Memory_Store::POST_TYPE, 'post_status' => 'pending']);
        self::factory()->post->create(['post_type' => Memory_Store::POST_TYPE, 'post_status' => 'pending']);
        self::factory()->post->create(['post_type' => Memory_Store::POST_TYPE, 'post_status' => 'pending']);

        $saved = [$GLOBALS['admin_page_hooks'] ?? null, $GLOBALS['menu'] ?? null, $GLOBALS['submenu'] ?? null, $GLOBALS['_registered_pages'] ?? null];
        try {
            Plugin::instance()->register_admin_menu();
            $screen_id = get_plugin_page_hookname(Audit_Log_Page::SLUG, 'wpmcp');

            $this->assertStringStartsWith('wpmcp-3_page_', $screen_id, 'the badge changes the hook prefix; if core stopped doing that, this test needs a new premise');

            set_current_screen($screen_id);
            $this->assertStringContainsString('Launch', $this->rendered_notices(), 'announcements must follow the screen id core really produces');
        } finally {
            [$GLOBALS['admin_page_hooks'], $GLOBALS['menu'], $GLOBALS['submenu'], $GLOBALS['_registered_pages']] = $saved;
        }
    }

    public function test_an_empty_feed_renders_nothing(): void
    {
        $this->canned_response = $this->feed_response([]);
        set_current_screen('toplevel_page_wpmcp');

        $this->assertSame('', $this->rendered_notices());
    }

    public function test_dismissed_announcements_disappear_from_the_rendered_notices(): void
    {
        set_current_screen('toplevel_page_wpmcp');
        (new Announcements())->dismiss($this->admin_id, 'launch-1');

        $html = $this->rendered_notices();
        $this->assertStringNotContainsString('Launch', $html);
        $this->assertStringContainsString('Tip', $html);
    }

    public function test_notices_require_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        set_current_screen('toplevel_page_wpmcp');

        $this->assertSame('', $this->rendered_notices());
    }

    public function test_dismiss_handler_rejects_a_missing_or_bad_nonce(): void
    {
        $_GET['_wpnonce']        = 'bogus';
        $_GET['announcement_id'] = 'launch-1';

        $result = (new Announcements())->handle_dismiss(static function (): void {
        });

        $this->assertWPError($result);
        $this->assertSame('wpmcp_forbidden', $result->get_error_code());
        $this->assertFalse((new Announcements())->is_dismissed($this->admin_id, 'launch-1'));
    }

    public function test_dismiss_handler_rejects_users_below_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $_GET['_wpnonce']        = wp_create_nonce(Announcements::NONCE_ACTION);
        $_GET['announcement_id'] = 'launch-1';

        $result = (new Announcements())->handle_dismiss(static function (): void {
        });

        $this->assertWPError($result);
    }

    public function test_dismiss_handler_dismisses_and_redirects_back(): void
    {
        $_GET['_wpnonce']        = wp_create_nonce(Announcements::NONCE_ACTION);
        $_GET['announcement_id'] = 'launch-1';
        $_GET['redirect_to']     = rawurlencode(admin_url('admin.php?page=wpmcp-connection'));

        $redirected_to = null;
        $result        = (new Announcements())->handle_dismiss(static function (string $url) use (&$redirected_to): void {
            $redirected_to = $url;
        });

        $this->assertNull($result);
        $this->assertTrue((new Announcements())->is_dismissed($this->admin_id, 'launch-1'));
        $this->assertSame(admin_url('admin.php?page=wpmcp-connection'), $redirected_to);
    }

    public function test_register_hooks_the_notice_renderer_and_dismiss_handler(): void
    {
        Announcements::register();
        set_current_screen('toplevel_page_wpmcp');

        ob_start();
        do_action('admin_notices');
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('wpmcp-announcement', $html);
        $this->assertNotFalse(has_action('admin_post_' . Announcements::DISMISS_ACTION));
    }
}
