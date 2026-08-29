<?php

namespace WPMCP\Admin;

use WPMCP\Tools\Redirects\Create_Redirect;
use WPMCP\Tools\Redirects\Redirect_Suggestions;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The human half of suggest-only redirects (issue #128).
 *
 * The Redirects screen lists pending suggestions with Create and Dismiss
 * buttons; both land here. Create does NOT write a row itself - it calls the
 * create-redirect tool, exactly as an agent would, so the confirmed redirect
 * goes through the same validation, chain flattening, loop detection and
 * Safe_Mutation snapshot, and shows up in the operation history as an
 * ordinary, rollback-able create-redirect. There is deliberately no second
 * code path that can produce a redirect.
 *
 * Gated at manage_options with a nonce, matching Restore_Controller: creating
 * a redirect changes site-wide routing.
 */
class Redirect_Suggestion_Controller
{
    public const ACTION = 'wpmcp_redirect_suggestion';
    public const NONCE  = 'wpmcp_redirect_suggestion';

    /**
     * Turn a pending suggestion into a real redirect.
     *
     * @return array<string,mixed>
     */
    public function confirm(string $source, int $target_post_id, string $target_url = ''): array
    {
        $suggestion = Redirect_Suggestions::find($source);
        if (null === $suggestion) {
            throw new \InvalidArgumentException('No pending redirect suggestion for "' . esc_html($source) . '".');
        }

        $args = [
            'source'     => $suggestion['source'],
            'session_id' => 'admin-suggestion',
            'notes'      => sprintf('Confirmed from suggestion (%s).', (string) ($suggestion['reason'] ?? '')),
        ];

        $post_id = $target_post_id > 0 ? $target_post_id : (int) ($suggestion['target_post_id'] ?? 0);
        if ($post_id > 0) {
            $args['target_post_id'] = $post_id;
        } else {
            $args['target'] = $target_url;
        }

        return (new Create_Redirect())->handle($args);
    }

    public function dismiss(string $source): bool
    {
        return Redirect_Suggestions::remove($source);
    }

    public function handle(): void
    {
        if (! current_user_can('manage_options') || ! check_ajax_referer(self::NONCE, 'nonce', false)) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        $op     = sanitize_key(wp_unslash($_POST['op'] ?? ''));
        $source = sanitize_text_field(wp_unslash($_POST['source'] ?? ''));

        if ('dismiss' === $op) {
            wp_send_json_success(['dismissed' => $this->dismiss($source)]);
        }

        try {
            wp_send_json_success($this->confirm(
                $source,
                absint(wp_unslash($_POST['target_post_id'] ?? 0)),
                esc_url_raw(wp_unslash($_POST['target'] ?? ''))
            ));
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 400);
        }
    }
}
