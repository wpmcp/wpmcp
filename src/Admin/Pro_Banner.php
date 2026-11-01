<?php

namespace WPMCP\Admin;

use WPMCP\Pro\Gate;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The in-admin Pro banner (docs/superpowers/specs/2026-11-01-pro-upgrade-banner.md).
 *
 * A free install has no path from wp-admin to the pricing page except the
 * licensing SDK's submenu. This notice is that path: it names what Pro keeps
 * and adds, carries a coupon, and links to the public pricing page with the
 * coupon and UTM parameters so the sale can be attributed to this surface.
 *
 * Deliberately narrow, like Announcements:
 *  - users with the management capability only (issue #479), and never while the licence allows premium
 *    code, so a paying customer never sees it;
 *  - only on the Dashboard, the Plugins list and the plugin's own screens,
 *    never on content editors or other plugins' settings;
 *  - dismissible without JavaScript through a nonce-protected admin-post
 *    link; a dismissal hides it for SNOOZE_DAYS and the MAX_DISMISSALS-th
 *    dismissal hides it for good, per user.
 *
 * The off-directory flavors strip this file (scripts/flavors/wporg/policy.php):
 * a build with no paid tier has nothing to link to.
 */
class Pro_Banner
{
    /** User meta: ['dismissed_at' => int, 'dismissals' => int]. */
    public const META_KEY = 'wpmcp_pro_banner';

    public const DISMISS_ACTION = 'wpmcp_dismiss_pro_banner';
    public const NONCE_ACTION   = 'wpmcp_dismiss_pro_banner';

    /** How long one dismissal hides the banner. */
    public const SNOOZE_DAYS = 30;

    /** The dismissal that makes the hide permanent for that user. */
    public const MAX_DISMISSALS = 3;

    /** The default offer. The owner confirms the code exists in Freemius before a release. */
    public const COUPON  = 'WPMCP20';
    public const PERCENT = 20;

    public const PRICING_URL = 'https://wpmcp-pro.com/pricing.html';

    /** Exact screen ids the banner renders on, before the filter. */
    private const SCREENS = ['dashboard', 'plugins', 'toplevel_page_wpmcp'];

    /** Every WP MCP submenu screen id starts with this. */
    private const SCREEN_PREFIX = 'wpmcp_page_';

    /** Test seam: the clock. Guarded by WPMCP_TESTING. */
    private static ?int $now_override = null;

    public static function set_now_for_tests(?int $now): void
    {
        if (! defined('WPMCP_TESTING') || ! WPMCP_TESTING) {
            return;
        }
        self::$now_override = $now;
    }

    /**
     * Hooks the notice and the dismiss handler. Both callbacks self-gate
     * (capability, licence, screen, nonce), so registration is always safe.
     * The dismiss handler sits behind the management check like every WP
     * MCP admin-post handler (issue #479), so the banner is shown only to
     * users who can also dismiss it.
     */
    public static function register(): self
    {
        $instance = new self();
        add_action('admin_notices', [$instance, 'render']);
        Management_Access::add_handler('admin_post_' . self::DISMISS_ACTION, [$instance, 'handle_dismiss']);

        return $instance;
    }

    /**
     * The offer shown: coupon code, percent off, and the final pricing URL.
     *
     * The wpmcp_pro_banner_offer filter receives coupon, percent and the
     * BASE url; the coupon and attribution parameters are appended after
     * filtering so a changed coupon is always the one the link carries.
     * Every filtered value is sanitized: the coupon to [A-Za-z0-9_-], the
     * percent to 1..99, the URL through esc_url_raw with the pricing page as
     * the fallback.
     *
     * @return array{coupon: string, percent: int, url: string}
     */
    public static function offer(): array
    {
        $defaults = [
            'coupon'  => self::COUPON,
            'percent' => self::PERCENT,
            'url'     => self::PRICING_URL,
        ];
        $offer = apply_filters('wpmcp_pro_banner_offer', $defaults);
        if (! is_array($offer)) {
            $offer = $defaults;
        }

        $coupon = isset($offer['coupon']) && is_scalar($offer['coupon'])
            ? (string) preg_replace('/[^A-Za-z0-9_-]/', '', sanitize_text_field((string) $offer['coupon']))
            : '';
        if ('' === $coupon || strlen($coupon) > 32) {
            $coupon = self::COUPON;
        }

        $percent = isset($offer['percent']) && is_numeric($offer['percent']) ? (int) $offer['percent'] : self::PERCENT;
        if ($percent < 1 || $percent > 99) {
            $percent = self::PERCENT;
        }

        $base = isset($offer['url']) && is_scalar($offer['url']) ? esc_url_raw((string) $offer['url'], ['http', 'https']) : '';
        if ('' === $base) {
            $base = self::PRICING_URL;
        }

        return [
            'coupon'  => $coupon,
            'percent' => $percent,
            'url'     => add_query_arg(
                [
                    'coupon'       => $coupon,
                    'utm_source'   => 'plugin',
                    'utm_medium'   => 'admin-banner',
                    'utm_campaign' => 'pro-banner',
                ],
                $base
            ),
        ];
    }

    /**
     * Whether the banner belongs on a screen. Exact ids come from the
     * filterable list; every WP MCP submenu screen matches by prefix.
     *
     * Core builds a submenu's screen id from the parent's SANITIZED MENU
     * TITLE, not its slug (add_menu_page writes $admin_page_hooks[$slug] =
     * sanitize_title($menu_title)), and our title carries the pending
     * memory-proposal badge, so with proposals waiting the prefix is
     * "wpmcp-3_page_". The real hook is read back from that global when the
     * menu has been registered; the plain prefix stays as the fallback.
     */
    public static function screen_allowed(string $screen_id): bool
    {
        if ('' === $screen_id) {
            return false;
        }
        $screens = apply_filters('wpmcp_pro_banner_screens', self::SCREENS);
        $screens = is_array($screens) ? array_map('strval', $screens) : self::SCREENS;
        if (in_array($screen_id, $screens, true)) {
            return true;
        }

        $hook = $GLOBALS['admin_page_hooks']['wpmcp'] ?? '';
        $real = is_string($hook) && '' !== $hook ? $hook . '_page_' : self::SCREEN_PREFIX;

        return 0 === strpos($screen_id, self::SCREEN_PREFIX) || 0 === strpos($screen_id, $real);
    }

    /**
     * The state rules, with the capability left to render(): licence,
     * screen, snooze and the permanent cap.
     */
    public function should_show(int $user_id, string $screen_id): bool
    {
        if (Gate::is_pro() || ! self::screen_allowed($screen_id)) {
            return false;
        }

        return ! $this->is_hidden_by($this->state($user_id));
    }

    /**
     * Records one dismissal for a user at the current time. A dismissal that
     * arrives while the banner is already hidden (a stale tab, a double
     * click) changes nothing: it must not restart the snooze clock or use up
     * one of the MAX_DISMISSALS.
     */
    public function dismiss(int $user_id): void
    {
        if ($user_id <= 0) {
            return;
        }
        $state = $this->state($user_id);
        if ($this->is_hidden_by($state)) {
            return;
        }
        update_user_meta($user_id, self::META_KEY, [
            'dismissed_at' => $this->now(),
            'dismissals'   => $state['dismissals'] + 1,
        ]);
    }

    /** Whether this is the dismissal that hides the banner for good. */
    private function next_dismissal_is_permanent(int $user_id): bool
    {
        return $this->state($user_id)['dismissals'] >= self::MAX_DISMISSALS - 1;
    }

    /** @param array{dismissed_at: int, dismissals: int} $state */
    private function is_hidden_by(array $state): bool
    {
        return $state['dismissals'] >= self::MAX_DISMISSALS
            || $state['dismissed_at'] + self::SNOOZE_DAYS * DAY_IN_SECONDS >= $this->now();
    }

    /** admin_notices callback. */
    public function render(): void
    {
        if (! current_user_can(Management_Access::CAPABILITY) || ! function_exists('get_current_screen')) {
            return;
        }
        $screen = get_current_screen();
        if (null === $screen || ! $this->should_show(get_current_user_id(), (string) $screen->id)) {
            return;
        }

        $offer       = self::offer();
        $dismiss_url = wp_nonce_url(
            add_query_arg(
                [
                    'action'      => self::DISMISS_ACTION,
                    'redirect_to' => rawurlencode(self::current_url()),
                ],
                admin_url('admin-post.php')
            ),
            self::NONCE_ACTION
        );
        ?>
        <div class="notice notice-info wpmcp-pro-banner">
            <style>
                .wpmcp-pro-banner { padding: 16px 20px 14px; border-left-color: #1d4ed8; }
                .wpmcp-pro-banner__eyebrow { margin: 0 0 4px; font-size: 11px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: #1d4ed8; }
                .wpmcp-pro-banner h2 { margin: 0 0 8px; padding: 0; font-size: 17px; line-height: 1.3; }
                .wpmcp-pro-banner ul { margin: 0 0 12px; padding: 0; display: flex; flex-wrap: wrap; gap: 4px 24px; list-style: none; }
                .wpmcp-pro-banner li { margin: 0; padding-left: 16px; position: relative; }
                .wpmcp-pro-banner li::before { content: ""; position: absolute; left: 0; top: 9px; width: 7px; height: 7px; border-radius: 50%; background: #1d4ed8; }
                .wpmcp-pro-banner__actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 16px; margin: 0; }
                .wpmcp-pro-banner__actions code { padding: 2px 6px; }
            </style>
            <p class="wpmcp-pro-banner__eyebrow"><?php esc_html_e('WP MCP Pro', 'wpmcp'); ?></p>
            <h2><?php esc_html_e('Pro keeps every snapshot, and builds Elementor pages too.', 'wpmcp'); ?></h2>
            <ul>
                <li><?php esc_html_e('Unlimited snapshot history and session restore', 'wpmcp'); ?></li>
                <li><?php esc_html_e('Deep Elementor editing and page building', 'wpmcp'); ?></li>
                <li><?php esc_html_e('Custom widget and block builders, forms adapter pack', 'wpmcp'); ?></li>
                <li><?php esc_html_e('WP MCP Cloud sync and priority support', 'wpmcp'); ?></li>
            </ul>
            <p class="wpmcp-pro-banner__actions">
                <a class="button button-primary" href="<?php echo esc_url($offer['url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('See Pro pricing', 'wpmcp'); ?></a>
                <span>
                    <?php
                    printf(
                        /* translators: 1: coupon code, 2: percent off. */
                        esc_html__('Code %1$s takes %2$d%% off at checkout.', 'wpmcp'),
                        '<code>' . esc_html($offer['coupon']) . '</code>',
                        (int) $offer['percent']
                    );
                    ?>
                </span>
                <a href="<?php echo esc_url($dismiss_url); ?>">
                    <?php
                    if ($this->next_dismissal_is_permanent(get_current_user_id())) {
                        esc_html_e('Do not show this again', 'wpmcp');
                    } else {
                        printf(
                            /* translators: %d: number of days the banner stays hidden. */
                            esc_html__('Hide for %d days', 'wpmcp'),
                            (int) self::SNOOZE_DAYS
                        );
                    }
                    ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * admin_post handler for dismissals: management capability + nonce, then a
     * redirect back to the referring screen.
     *
     * @param callable|null $redirector Test seam: receives the redirect URL
     *                                  instead of wp_safe_redirect + exit.
     * @return \WP_Error|null WP_Error on refusal when a $redirector is
     *                        injected; otherwise redirects and exits.
     */
    public function handle_dismiss(?callable $redirector = null)
    {
        $nonce      = is_string($_GET['_wpnonce'] ?? null) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        $authorized = current_user_can(Management_Access::CAPABILITY)
            && '' !== $nonce
            && wp_verify_nonce($nonce, self::NONCE_ACTION);

        if (! $authorized) {
            if (null !== $redirector) {
                return new \WP_Error('wpmcp_forbidden', __('You are not allowed to dismiss this notice.', 'wpmcp'));
            }
            wp_die(esc_html__('You are not allowed to dismiss this notice.', 'wpmcp'), 403);
        }

        $this->dismiss(get_current_user_id());

        // Sanitized twice on purpose, as Announcements does: wp_sanitize_redirect()
        // while still rawurlencoded, esc_url_raw() on the decoded URL.
        $redirect = is_string($_GET['redirect_to'] ?? null) ? esc_url_raw(rawurldecode(wp_sanitize_redirect(wp_unslash($_GET['redirect_to'])))) : '';
        $target   = '' !== $redirect ? $redirect : admin_url('admin.php?page=wpmcp');

        if (null !== $redirector) {
            $redirector($target);
            return null;
        }

        wp_safe_redirect($target, 302, 'wpmcp');
        exit;
    }

    /** @return array{dismissed_at: int, dismissals: int} */
    private function state(int $user_id): array
    {
        $raw = $user_id > 0 ? get_user_meta($user_id, self::META_KEY, true) : [];
        $raw = is_array($raw) ? $raw : [];

        return [
            'dismissed_at' => isset($raw['dismissed_at']) && is_numeric($raw['dismissed_at']) ? (int) $raw['dismissed_at'] : 0,
            'dismissals'   => isset($raw['dismissals']) && is_numeric($raw['dismissals']) ? max(0, (int) $raw['dismissals']) : 0,
        ];
    }

    private function now(): int
    {
        return self::$now_override ?? time();
    }

    /** The current wp-admin URL, for the post-dismiss redirect. */
    private static function current_url(): string
    {
        $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';

        return '' !== $uri ? $uri : admin_url('admin.php?page=wpmcp');
    }
}
