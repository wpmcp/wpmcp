<?php

namespace WPMCP\Tests\Free\Admin;

use WPMCP\Admin\Audit_Log_Page;
use WPMCP\Admin\Pro_Banner;
use WPMCP\Memory\Memory_Store;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;

/**
 * The in-admin Pro banner (docs/superpowers/specs/2026-11-01-pro-upgrade-banner.md).
 *
 * A dismissible notice for manage_options users on free installs, shown on
 * the Dashboard, the Plugins list and the plugin's own screens, that links
 * to the public pricing page with a coupon and UTM parameters. Dismissal
 * hides it for 30 days; the third dismissal is permanent. A Pro licence hides
 * it entirely.
 */
class ProBannerTest extends \WP_UnitTestCase
{
    private int $admin_id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin_id);
        set_current_screen('dashboard');
        Gate::set_pro_for_tests(false);
        Pro_Banner::set_now_for_tests(null);
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        Pro_Banner::set_now_for_tests(null);
        unset($_GET['_wpnonce'], $_GET['redirect_to']);
        remove_all_filters('wpmcp_pro_banner_offer');
        remove_all_filters('wpmcp_pro_banner_screens');
        parent::tearDown();
    }

    private function rendered(): string
    {
        ob_start();
        (new Pro_Banner())->render();
        return (string) ob_get_clean();
    }

    /** Runs the dismiss handler as the current user with a valid nonce. */
    private function dismiss_with_nonce(string $redirect_to = ''): ?string
    {
        $_GET['_wpnonce'] = wp_create_nonce(Pro_Banner::NONCE_ACTION);
        if ('' !== $redirect_to) {
            $_GET['redirect_to'] = rawurlencode($redirect_to);
        }
        $captured = null;
        $result   = (new Pro_Banner())->handle_dismiss(static function (string $url) use (&$captured): void {
            $captured = $url;
        });
        $this->assertNull($result, 'a valid dismissal must not return an error');
        return $captured;
    }

    public function test_renders_the_offer_for_an_administrator_on_the_dashboard(): void
    {
        $html = $this->rendered();

        $this->assertStringContainsString('wpmcp-pro-banner', $html);
        $this->assertStringContainsString('https://wpmcp-pro.com/pricing.html', $html);
        $this->assertStringContainsString('coupon=WPMCP20', $html);
        $this->assertStringContainsString('utm_source=plugin', $html);
        $this->assertStringContainsString('utm_medium=admin-banner', $html);
        $this->assertStringContainsString('WPMCP20', $html);
        $this->assertStringContainsString('20%', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringContainsString('action=' . Pro_Banner::DISMISS_ACTION, $html);
        $this->assertStringContainsString('_wpnonce=', $html);
        $this->assertStringContainsString('class="notice', $html, 'it is a standard WordPress admin notice');
    }

    public function test_renders_nothing_with_an_active_pro_licence(): void
    {
        Gate::set_pro_for_tests(true);

        $this->assertSame('', $this->rendered());
    }

    public function test_renders_nothing_below_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $this->assertSame('', $this->rendered());
    }

    public function test_renders_only_on_the_allowed_screens(): void
    {
        $expectations = [
            'dashboard'                   => true,
            'plugins'                     => true,
            'toplevel_page_wpmcp'         => true,
            'wpmcp_page_wpmcp-audit-log'  => true,
            'edit-post'                   => false,
            'options-general'             => false,
            'post'                        => false,
        ];

        foreach ($expectations as $screen => $shown) {
            set_current_screen($screen);
            $this->assertSame(
                $shown,
                '' !== $this->rendered(),
                "screen {$screen}: expected " . ($shown ? 'shown' : 'hidden')
            );
        }
    }

    public function test_the_screen_list_is_filterable(): void
    {
        add_filter('wpmcp_pro_banner_screens', static fn (array $screens): array => ['edit-post']);

        set_current_screen('edit-post');
        $this->assertNotSame('', $this->rendered(), 'a screen added by the filter shows the banner');

        set_current_screen('dashboard');
        $this->assertSame('', $this->rendered(), 'a screen removed by the filter hides it');
    }

    public function test_dismiss_refuses_a_missing_nonce(): void
    {
        $result = (new Pro_Banner())->handle_dismiss(static function (): void {
        });

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wpmcp_forbidden', $result->get_error_code());
        $this->assertNotSame('', $this->rendered(), 'a refused dismissal changes nothing');
    }

    public function test_dismiss_refuses_a_user_below_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $_GET['_wpnonce'] = wp_create_nonce(Pro_Banner::NONCE_ACTION);

        $result = (new Pro_Banner())->handle_dismiss(static function (): void {
        });

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_dismiss_hides_the_banner_for_30_days_then_it_returns(): void
    {
        $now = 1_800_000_000;
        Pro_Banner::set_now_for_tests($now);

        $redirect = $this->dismiss_with_nonce('http://example.org/wp-admin/plugins.php');
        $this->assertSame('http://example.org/wp-admin/plugins.php', $redirect);
        $this->assertSame('', $this->rendered(), 'hidden right after dismissal');

        Pro_Banner::set_now_for_tests($now + 29 * DAY_IN_SECONDS);
        $this->assertSame('', $this->rendered(), 'still hidden on day 29');

        Pro_Banner::set_now_for_tests($now + 30 * DAY_IN_SECONDS + 1);
        $this->assertNotSame('', $this->rendered(), 'back after the 30-day snooze');
    }

    public function test_dismissal_is_per_user(): void
    {
        $this->dismiss_with_nonce();
        $this->assertSame('', $this->rendered());

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->assertNotSame('', $this->rendered(), 'another administrator still sees it');
    }

    public function test_the_third_dismissal_is_permanent(): void
    {
        $now = 1_800_000_000;
        foreach ([0, 1, 2] as $round) {
            Pro_Banner::set_now_for_tests($now + $round * 31 * DAY_IN_SECONDS);
            $this->assertNotSame('', $this->rendered(), "visible before dismissal {$round}");
            $this->dismiss_with_nonce();
        }

        Pro_Banner::set_now_for_tests($now + 10 * YEAR_IN_SECONDS);
        $this->assertSame('', $this->rendered(), 'three dismissals mean never again');
    }

    public function test_dismiss_falls_back_to_the_plugin_screen_without_a_redirect(): void
    {
        $redirect = $this->dismiss_with_nonce();

        $this->assertStringContainsString('admin.php?page=wpmcp', (string) $redirect);
    }

    public function test_the_offer_is_filterable_and_sanitized(): void
    {
        add_filter('wpmcp_pro_banner_offer', static fn (array $offer): array => [
            'coupon'  => '<b>BLACKFRIDAY40</b>',
            'percent' => 40,
            'url'     => 'javascript:alert(1)',
        ]);

        $html = $this->rendered();

        $this->assertStringContainsString('BLACKFRIDAY40', $html);
        $this->assertStringContainsString('40%', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('https://wpmcp-pro.com/pricing.html', $html, 'an unusable URL falls back to the pricing page');
    }

    public function test_copy_avoids_pay_to_unlock_phrases_and_dashes(): void
    {
        $html = strtolower($this->rendered());

        foreach (['upgrade to unlock', 'unlock this feature', 'pro feature', 'premium feature', 'available in pro', 'only in pro', 'upgrade now', 'go pro', 'buy now', 'upgrade to pro'] as $phrase) {
            $this->assertStringNotContainsString($phrase, $html, "compliance phrase present: {$phrase}");
        }
        $this->assertStringNotContainsString("\u{2014}", $html, 'em dash');
        $this->assertStringNotContainsString("\u{2013}", $html, 'en dash');
    }

    /**
     * Core derives a submenu's screen id from the SANITIZED MENU TITLE of its
     * parent (wp-admin/includes/plugin.php, add_menu_page writes
     * $admin_page_hooks[$slug] = sanitize_title($menu_title)), and our title
     * carries the pending-proposals badge, so with proposals waiting the
     * Audit Log screen is "wpmcp-N_page_wpmcp-audit-log", not "wpmcp_page_".
     * The id is taken from core here, never typed by hand.
     */
    public function test_shows_on_the_real_submenu_screen_id_while_the_menu_carries_a_badge(): void
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
            $this->assertNotSame('', $this->rendered(), 'the banner must follow the screen id core really produces');
        } finally {
            [$GLOBALS['admin_page_hooks'], $GLOBALS['menu'], $GLOBALS['submenu'], $GLOBALS['_registered_pages']] = $saved;
        }
    }

    public function test_the_last_allowed_dismissal_is_labelled_as_permanent(): void
    {
        $now = 1_800_000_000;
        Pro_Banner::set_now_for_tests($now);
        $this->assertStringContainsString('Hide for 30 days', $this->rendered());
        $this->dismiss_with_nonce();

        Pro_Banner::set_now_for_tests($now + 31 * DAY_IN_SECONDS);
        $this->assertStringContainsString('Hide for 30 days', $this->rendered(), 'the second dismissal is still a snooze');
        $this->dismiss_with_nonce();

        Pro_Banner::set_now_for_tests($now + 62 * DAY_IN_SECONDS);
        $html = $this->rendered();
        $this->assertStringContainsString('Do not show this again', $html, 'the third click is permanent and must say so');
        $this->assertStringNotContainsString('Hide for 30 days', $html);
    }

    public function test_dismissing_while_already_hidden_does_not_use_up_the_budget(): void
    {
        $now = 1_800_000_000;
        Pro_Banner::set_now_for_tests($now);
        $this->dismiss_with_nonce();
        Pro_Banner::set_now_for_tests($now + 60);
        $this->dismiss_with_nonce();
        $this->dismiss_with_nonce();

        $state = get_user_meta($this->admin_id, Pro_Banner::META_KEY, true);
        $this->assertSame(1, $state['dismissals'], 'stale tabs clicking Hide must not make the hide permanent');
        $this->assertSame($now, $state['dismissed_at'], 'the snooze clock is not restarted either');
    }

    public function test_a_refused_dismissal_writes_nothing(): void
    {
        $editor = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($editor);
        $_GET['_wpnonce'] = wp_create_nonce(Pro_Banner::NONCE_ACTION);

        (new Pro_Banner())->handle_dismiss(static function (): void {
        });

        $this->assertSame('', get_user_meta($editor, Pro_Banner::META_KEY, true));
        $this->assertSame('', get_user_meta($this->admin_id, Pro_Banner::META_KEY, true));
    }

    public function test_the_snooze_ends_one_second_after_30_days(): void
    {
        $now = 1_800_000_000;
        Pro_Banner::set_now_for_tests($now);
        $this->dismiss_with_nonce();

        Pro_Banner::set_now_for_tests($now + 30 * DAY_IN_SECONDS);
        $this->assertSame('', $this->rendered(), 'exactly 30 days: still hidden');

        Pro_Banner::set_now_for_tests($now + 30 * DAY_IN_SECONDS + 1);
        $this->assertNotSame('', $this->rendered());
    }

    public function test_corrupt_meta_counts_as_never_dismissed(): void
    {
        update_user_meta($this->admin_id, Pro_Banner::META_KEY, 'garbage');
        $this->assertNotSame('', $this->rendered());

        update_user_meta($this->admin_id, Pro_Banner::META_KEY, ['dismissed_at' => 'soon', 'dismissals' => -5]);
        $this->assertNotSame('', $this->rendered());

        $this->dismiss_with_nonce();
        $state = get_user_meta($this->admin_id, Pro_Banner::META_KEY, true);
        $this->assertSame(1, $state['dismissals'], 'a negative count never lets a user exceed the budget');
    }

    public function test_register_hooks_the_notice_and_the_dismiss_handler(): void
    {
        $banner = Pro_Banner::register();

        $this->assertNotFalse(has_action('admin_notices', [$banner, 'render']));
        $this->assertNotFalse(has_action('admin_post_' . Pro_Banner::DISMISS_ACTION, [$banner, 'handle_dismiss']));

        remove_action('admin_notices', [$banner, 'render']);
        remove_action('admin_post_' . Pro_Banner::DISMISS_ACTION, [$banner, 'handle_dismiss']);
    }
}
