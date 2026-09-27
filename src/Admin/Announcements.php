<?php

namespace WPMCP\Admin;

use WPMCP\Cloud\Cloud_Client;
use WPMCP\Cloud\Cloud_Config;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Admin announcements feed (issue #138): a small, unobtrusive notifications
 * surface fed from WP MCP Cloud.
 *
 * Design constraints, deliberately narrower than the bell-and-drawer chrome
 * some plugins ship:
 *  - Announcements render ONLY on wpmcp admin screens, never site-wide, so
 *    the feed can never read as adware on unrelated wp-admin pages.
 *  - The feed is fetched through Cloud_Client (the single cloud seam) and
 *    cached in a transient for 24 hours; a fetch failure is cached for one
 *    hour so a down endpoint is not hammered on every admin page load but
 *    still self-heals quickly.
 *  - Everything fails silent: cloud unreachable, non-JSON, malformed feed,
 *    or cloud simply not configured all render nothing at all.
 *  - Dismissal is per user (user meta), nonce-protected, and permanent for
 *    that announcement id.
 */
class Announcements
{
    public const TRANSIENT       = 'wpmcp_announcements';
    public const META_DISMISSED  = 'wpmcp_dismissed_announcements';
    public const NONCE_ACTION    = 'wpmcp_dismiss_announcement';
    public const DISMISS_ACTION  = 'wpmcp_dismiss_announcement';

    /** Cache lifetime for a successful fetch. */
    private const CACHE_OK = DAY_IN_SECONDS;

    /** Cache lifetime for a failed fetch (empty result): retry sooner. */
    private const CACHE_FAIL = HOUR_IN_SECONDS;

    /** Hard cap on rendered announcements; the feed is a whisper, not a wall. */
    private const MAX_ITEMS = 10;

    /** Cap on remembered dismissed ids per user (oldest dropped first). */
    private const MAX_DISMISSED = 100;

    private Cloud_Client $client;

    public function __construct(?Cloud_Client $client = null)
    {
        $this->client = $client ?? new Cloud_Client();
    }

    /**
     * Hooks the notice renderer and the dismiss handler. Called once from
     * Plugin boot; both callbacks are self-gating (screen, capability,
     * nonce), so registration itself is always safe.
     */
    public static function register(): void
    {
        $instance = new self();
        add_action('admin_notices', [$instance, 'render_notices']);
        add_action('admin_post_' . self::DISMISS_ACTION, [$instance, 'handle_dismiss']);
    }

    /**
     * The current announcement list: transient-cached, fetched from the
     * cloud on a miss. Always an array; never throws, never surfaces an
     * error.
     *
     * @return array<int, array{id: string, title: string, body: string, url: string, date: string}>
     */
    public function get(): array
    {
        // No cloud connection means no feed. Checked before the transient so
        // an unconfigured site never caches (or later serves) a stale list.
        if (! Cloud_Config::is_configured()) {
            return [];
        }

        $cached = get_transient(self::TRANSIENT);
        if (is_array($cached)) {
            return $cached;
        }

        $items = $this->fetch();
        set_transient(self::TRANSIENT, $items, [] === $items ? self::CACHE_FAIL : self::CACHE_OK);

        return $items;
    }

    /**
     * Fetches and sanitizes the feed through Cloud_Client. Any failure
     * (unreachable, HTTP error, malformed body) yields an empty array.
     *
     * @return array<int, array{id: string, title: string, body: string, url: string, date: string}>
     */
    private function fetch(): array
    {
        $response = $this->client->get('/announcements');
        if (is_wp_error($response) || ! isset($response['announcements']) || ! is_array($response['announcements'])) {
            return [];
        }

        $items = [];
        foreach ($response['announcements'] as $item) {
            if (! is_array($item) || ! isset($item['id']) || ! is_scalar($item['id']) || '' === (string) $item['id']) {
                continue;
            }
            $items[] = [
                'id'    => sanitize_key((string) $item['id']),
                'title' => isset($item['title']) && is_scalar($item['title']) ? sanitize_text_field((string) $item['title']) : '',
                'body'  => isset($item['body']) && is_scalar($item['body']) ? sanitize_text_field((string) $item['body']) : '',
                'url'   => isset($item['url']) && is_scalar($item['url']) ? esc_url_raw((string) $item['url']) : '',
                'date'  => isset($item['date']) && is_scalar($item['date']) ? sanitize_text_field((string) $item['date']) : '',
            ];
            if (count($items) >= self::MAX_ITEMS) {
                break;
            }
        }

        return $items;
    }

    /**
     * The announcements a given user has not dismissed.
     *
     * @return array<int, array{id: string, title: string, body: string, url: string, date: string}>
     */
    public function visible_for(int $user_id): array
    {
        $dismissed = $this->dismissed_ids($user_id);

        return array_values(array_filter(
            $this->get(),
            static fn (array $item): bool => ! in_array($item['id'], $dismissed, true)
        ));
    }

    /** Marks one announcement id as dismissed for a user, permanently. */
    public function dismiss(int $user_id, string $id): void
    {
        $id = sanitize_key($id);
        if ($user_id <= 0 || '' === $id) {
            return;
        }

        $dismissed = $this->dismissed_ids($user_id);
        if (in_array($id, $dismissed, true)) {
            return;
        }

        $dismissed[] = $id;
        if (count($dismissed) > self::MAX_DISMISSED) {
            $dismissed = array_slice($dismissed, -self::MAX_DISMISSED);
        }

        update_user_meta($user_id, self::META_DISMISSED, array_values($dismissed));
    }

    public function is_dismissed(int $user_id, string $id): bool
    {
        return in_array(sanitize_key($id), $this->dismissed_ids($user_id), true);
    }

    /** @return string[] */
    private function dismissed_ids(int $user_id): array
    {
        if ($user_id <= 0) {
            return [];
        }
        $dismissed = get_user_meta($user_id, self::META_DISMISSED, true);
        return is_array($dismissed) ? array_values(array_map('strval', $dismissed)) : [];
    }

    /**
     * Whether the current admin screen is one of wpmcp's own. Announcements
     * never appear anywhere else in wp-admin.
     */
    private function is_wpmcp_screen(): bool
    {
        if (! function_exists('get_current_screen')) {
            return false;
        }
        $screen = get_current_screen();
        if (null === $screen) {
            return false;
        }

        return 'toplevel_page_wpmcp' === $screen->id || 0 === strpos($screen->id, 'wpmcp_page_');
    }

    /**
     * admin_notices callback: renders each undismissed announcement as a
     * plain dated notice with an inline dismiss link. Renders nothing off
     * wpmcp screens, for users below manage_options, and on an empty feed.
     */
    public function render_notices(): void
    {
        if (! $this->is_wpmcp_screen() || ! current_user_can('manage_options')) {
            return;
        }

        $items = $this->visible_for(get_current_user_id());
        if ([] === $items) {
            return;
        }

        foreach ($items as $item) {
            $dismiss_url = wp_nonce_url(
                add_query_arg(
                    [
                        'action'          => self::DISMISS_ACTION,
                        'announcement_id' => $item['id'],
                        'redirect_to'     => rawurlencode(self::current_url()),
                    ],
                    admin_url('admin-post.php')
                ),
                self::NONCE_ACTION
            );
            ?>
            <div class="notice notice-info wpmcp-announcement">
                <p>
                    <?php if ('' !== $item['date']) : ?>
                        <strong><?php echo esc_html($item['date']); ?></strong> &middot;
                    <?php endif; ?>
                    <?php if ('' !== $item['title']) : ?>
                        <strong><?php echo esc_html($item['title']); ?></strong>
                    <?php endif; ?>
                    <?php echo esc_html($item['body']); ?>
                    <?php if ('' !== $item['url']) : ?>
                        <a href="<?php echo esc_url($item['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Read more', 'wpmcp'); ?></a>
                    <?php endif; ?>
                    <a href="<?php echo esc_url($dismiss_url); ?>"><?php echo esc_html__('Dismiss', 'wpmcp'); ?></a>
                </p>
            </div>
            <?php
        }
    }

    /**
     * admin_post handler for dismissals: manage_options + nonce, then a
     * redirect back to the referring wpmcp screen.
     *
     * @param callable|null $redirector Test seam: receives the redirect URL
     *                                  instead of wp_safe_redirect + exit.
     * @return \WP_Error|null WP_Error on refusal when a $redirector is
     *                        injected; otherwise redirects and exits.
     */
    public function handle_dismiss(?callable $redirector = null)
    {
        $nonce      = is_string($_GET['_wpnonce'] ?? null) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        $authorized = current_user_can('manage_options')
            && '' !== $nonce
            && wp_verify_nonce($nonce, self::NONCE_ACTION);

        if (! $authorized) {
            if (null !== $redirector) {
                return new \WP_Error('wpmcp_forbidden', __('You are not allowed to dismiss wpmcp announcements.', 'wpmcp'));
            }
            wp_die(esc_html__('You are not allowed to dismiss wpmcp announcements.', 'wpmcp'), 403);
        }

        $announcement_id = is_string($_GET['announcement_id'] ?? null) ? sanitize_text_field(wp_unslash($_GET['announcement_id'])) : '';
        $this->dismiss(get_current_user_id(), $announcement_id);

        // Sanitized twice on purpose: wp_sanitize_redirect() on the read while
        // it is still rawurlencoded (it preserves %XX octets), esc_url_raw()
        // on the decoded URL that is actually redirected to.
        $redirect = is_string($_GET['redirect_to'] ?? null) ? esc_url_raw(rawurldecode(wp_sanitize_redirect(wp_unslash($_GET['redirect_to'])))) : '';
        $fallback = admin_url('admin.php?page=wpmcp');
        $target   = '' !== $redirect ? $redirect : $fallback;

        if (null !== $redirector) {
            $redirector($target);
            return null;
        }

        wp_safe_redirect($target, 302, 'wpmcp');
        exit;
    }

    /** The current wp-admin URL, for the post-dismiss redirect. */
    private static function current_url(): string
    {
        $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        return '' !== $uri ? $uri : admin_url('admin.php?page=wpmcp');
    }
}
