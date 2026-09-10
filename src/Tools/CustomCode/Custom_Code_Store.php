<?php

namespace WPMCP\Tools\CustomCode;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Option-backed store for agent-managed custom CSS/JS (issue #63).
 *
 * ONE OPTION PER INDEPENDENTLY-ROLLED-BACK BLOCK. The site-wide JS snippet
 * lives in self::OPTION; each page-scoped CSS block lives in its own
 * self::POST_OPTION_PREFIX . <post_id> option.
 *
 * That split is the whole point of this class. Every write here goes through
 * Safe_Mutation with object_type 'option', and Rollback_Service's option
 * handler restores the ENTIRE option value it snapshotted. With one shared
 * option, the before-image of any single write is a before-image of the whole
 * store, so this sequence:
 *
 *   1. add-scoped-css(post A)   2. add-scoped-css(post B)   3. add-custom-js
 *
 * would let rollback-operation on (1) delete post B's CSS and the JS snippet
 * as collateral, while the tool response for (1) promised a recoverable,
 * post-scoped change. Giving each block its own option makes object_id name
 * the block being written, so a rollback reverts exactly the write it was
 * issued for and nothing else - with no safety-core change.
 *
 * Blocks are stored with autoload=false and read individually: the renderer
 * only ever needs the site option plus, on a singular request, the one option
 * for the post being viewed.
 *
 * Site-wide CSS is deliberately absent. The existing wpmcp/add-custom-css
 * ability writes site-wide CSS through core's Additional CSS storage; a second
 * site-wide slot here would be a competing path an agent could not discover
 * (nothing reads or clears it), so there is one path per scope.
 *
 * TODO(#63): builder-settings CSS (writing into Elementor page settings
 * custom_css) is a separate write path through Update_Builder_Content's
 * snapshot flow; not part of this slice.
 */
class Custom_Code_Store
{
    /** Site-wide, governance-gated JS snippet: [ 'js' => [ 'site' => '<js>' ] ]. */
    public const OPTION = 'wpmcp_custom_code';

    /** Per-post CSS block option prefix; the full name is prefix . post_id. */
    public const POST_OPTION_PREFIX = 'wpmcp_custom_code_post_';

    /**
     * Ceiling on one page's stored block, in bytes. set_css() APPENDS by
     * default, so without a cap an agent retrying a failing call - or looping
     * on one - grows a single autoload=false option without bound, and the
     * only way back is a raw option edit. 256 KB is far beyond any hand-written
     * page stylesheet while still being a number a wp_options row can carry.
     * Hitting it is an error, never a silent truncation: half a stylesheet is
     * worse than none, because it still parses.
     */
    public const MAX_CSS_BYTES = 262144;

    /** The option name holding $post_id's CSS block. */
    public static function post_option(int $post_id): string
    {
        return self::POST_OPTION_PREFIX . $post_id;
    }

    public static function read(): array
    {
        $stored = get_option(self::OPTION, []);

        return is_array($stored) ? $stored : [];
    }

    /** The stored CSS block for $post_id, or '' when there is none. */
    public static function read_css(int $post_id): string
    {
        $stored = get_option(self::post_option($post_id), '');

        return is_string($stored) ? $stored : '';
    }

    /**
     * Write $post_id's CSS block. Appends to the existing block by default,
     * matching the sibling wpmcp/add-custom-css ability; $replace = true
     * overwrites it. An "add-" tool that silently discarded the agent's
     * previous block would give it no way to know it had destroyed work.
     */
    public static function set_css(string $css, int $post_id, bool $replace = false): string
    {
        $current = self::read_css($post_id);
        $next    = ($replace || '' === trim($current)) ? $css : trim($current . "\n" . $css);

        if (strlen($next) > self::MAX_CSS_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'The CSS block for this page would grow to %d bytes, past the %d byte limit. Pass replace=true to overwrite the stored block instead of appending to it.',
                strlen($next),
                self::MAX_CSS_BYTES
            ));
        }

        update_option(self::post_option($post_id), $next, false);

        return $next;
    }

    /**
     * Remove $post_id's CSS block entirely. Wired to 'deleted_post' in
     * Custom_Code_Renderer::boot(), which is the only reason it is not dead
     * code while the remove-scoped-css ability is still outstanding.
     *
     * Post ids are REUSED: after a database restore or a WXR import
     * WordPress hands the same integer to a different post, so an orphaned
     * wpmcp_custom_code_post_<id> option does not sit idle, it re-attaches
     * itself to whatever takes the id next. That makes the cleanup a
     * correctness rule rather than housekeeping.
     */
    public static function delete_css(int $post_id): void
    {
        delete_option(self::post_option($post_id));
    }

    /** Replace the site-wide JS block. Callers must have passed Custom_Js_Guard. */
    public static function set_js(string $js): void
    {
        $data               = self::read();
        $data['js']['site'] = $js;

        update_option(self::OPTION, $data, false);
    }
}
