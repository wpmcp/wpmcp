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
 * EVERY STORED VALUE IS SIGNED, and the renderer prints only values whose
 * signature verifies. The signature is an HMAC keyed on wp_salt('auth') over
 * the option name, the sanitizer rule version and the payload. Two reasons:
 * the generic DB row tools (update-rows on wp_options) never consult
 * Option_Guard, so without it a manage_options caller lacking edit_css or
 * unfiltered_html could write the option directly and have it served to
 * every visitor; and binding the option name means a block cannot be copied
 * from one page to another. The signature travels inside the option value,
 * so a Safe_Mutation snapshot and its rollback restore a still-valid pair.
 * Rotating the auth salt invalidates every stored block, and they stop
 * rendering until they are written again.
 *
 * Page block format: [ 'css' => string, 'rules' => int, 'sig' => string ].
 * Site option format: [ 'js' => [ 'site' => string, 'sig' => string ] ].
 *
 * Site-wide CSS is deliberately absent. The existing wpmcp/add-custom-css
 * ability writes site-wide CSS through core's Additional CSS storage; a second
 * site-wide slot here would be a competing path an agent could not discover
 * (nothing reads or clears it), so there is one path per scope.
 *
 */
class Custom_Code_Store
{
    /** Site-wide, governance-gated JS snippet: [ 'js' => [ 'site' => '<js>', 'sig' => '<hmac>' ] ]. */
    public const OPTION = 'wpmcp_custom_code';

    /** Per-post CSS block option prefix; the full name is prefix . post_id. */
    public const POST_OPTION_PREFIX = 'wpmcp_custom_code_post_';

    /**
     * Ceiling on one page's stored block, in bytes. Blocks APPEND by default,
     * so without a cap an agent retrying a failing call, or looping on one,
     * grows a single autoload=false option without bound, and the only way
     * back is a raw option edit. 256 KB is far beyond any hand-written page
     * stylesheet and still something a wp_options row can hold. Hitting it
     * is an error, never a silent truncation: half a stylesheet is worse
     * than none, because it still parses.
     */
    public const MAX_CSS_BYTES = 262144;

    /** The option name holding $post_id's CSS block. */
    public static function post_option(int $post_id): string
    {
        return self::POST_OPTION_PREFIX . $post_id;
    }

    /**
     * HMAC over the option name, the rule version and the payload. The
     * option name binds a value to where it was written; the rule version
     * tells the renderer whether the current sanitizer already accepted it.
     */
    public static function sign(string $option, int $rules, string $payload): string
    {
        return hash_hmac('sha256', $option . "\n" . $rules . "\n" . $payload, (string) wp_salt('auth'));
    }

    public static function read(): array
    {
        $stored = get_option(self::OPTION, []);

        return is_array($stored) ? $stored : [];
    }

    /**
     * The stored CSS text for $post_id, or '' when there is none. This is
     * NOT a trust decision: an unsigned or forged value is returned too.
     * Anything that prints or builds on the block uses verified_css().
     */
    public static function read_css(int $post_id): string
    {
        $stored = get_option(self::post_option($post_id), '');
        if (is_array($stored)) {
            return is_string($stored['css'] ?? null) ? $stored['css'] : '';
        }

        return is_string($stored) ? $stored : '';
    }

    /** True when an option row exists for $post_id, signed or not. */
    public static function has_css(int $post_id): bool
    {
        return false !== get_option(self::post_option($post_id));
    }

    /**
     * The block for $post_id if and only if this plugin wrote it, or null.
     *
     * @return array{css:string, rules:int, sig:string, current:bool}|null
     */
    public static function verified_css(int $post_id): ?array
    {
        $option = self::post_option($post_id);
        $stored = get_option($option, null);

        if (! is_array($stored) || ! is_string($stored['css'] ?? null) || ! is_string($stored['sig'] ?? null) || ! is_int($stored['rules'] ?? null)) {
            return null;
        }

        if (! hash_equals(self::sign($option, $stored['rules'], $stored['css']), $stored['sig'])) {
            return null;
        }

        return [
            'css'     => $stored['css'],
            'rules'   => $stored['rules'],
            'sig'     => $stored['sig'],
            'current' => Css_Sanitizer::RULES_VERSION === $stored['rules'],
        ];
    }

    /**
     * The block that appending $css to $post_id's block would produce,
     * without storing it, so a caller can validate exactly what it will
     * write. $existing is the verified block text the caller builds on.
     * Enforces the size cap.
     */
    public static function compose_css(string $css, string $existing, bool $replace = false): string
    {
        $next = ($replace || '' === trim($existing)) ? $css : trim($existing . "\n" . $css);

        if (strlen($next) > self::MAX_CSS_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                'The CSS block for this page would grow to %d bytes, past the %d byte limit. Pass replace=true to overwrite the stored block instead of appending to it.',
                (int) strlen($next),
                (int) self::MAX_CSS_BYTES
            ));
        }

        return $next;
    }

    /**
     * Store exactly $css as $post_id's block, signed; '' deletes the block.
     * Does not sanitize: callers decide first (Add_Scoped_Css sanitizes the
     * composed block before it gets here). $rules defaults to the current
     * rule version, meaning "the current sanitizer accepted this".
     */
    public static function write_css(int $post_id, string $css, ?int $rules = null): void
    {
        $option = self::post_option($post_id);

        if ('' === $css) {
            delete_option($option);
            return;
        }

        $rules = $rules ?? Css_Sanitizer::RULES_VERSION;

        update_option($option, ['css' => $css, 'rules' => $rules, 'sig' => self::sign($option, $rules, $css)], false);
    }

    /**
     * Compose and write in one step, building on the VERIFIED block only.
     * A convenience for callers that have already validated $css; returns
     * the stored block.
     */
    public static function set_css(string $css, int $post_id, bool $replace = false): string
    {
        $verified = self::verified_css($post_id);
        $next     = self::compose_css($css, null === $verified ? '' : $verified['css'], $replace);

        self::write_css($post_id, $next);

        return $next;
    }

    /**
     * Remove $post_id's CSS block entirely. Wired to 'deleted_post' in
     * Custom_Code_Renderer::boot().
     *
     * Post ids are REUSED: after a database restore or a WXR import
     * WordPress hands the same integer to a different post. An orphaned
     * wpmcp_custom_code_post_<id> option does not sit idle; it attaches
     * itself to whatever post takes the id next. That makes the cleanup a
     * correctness rule rather than housekeeping.
     */
    public static function delete_css(int $post_id): void
    {
        delete_option(self::post_option($post_id));
    }

    /** The site snippet if and only if this plugin wrote it, else ''. */
    public static function verified_js(): string
    {
        $js = self::read()['js'] ?? null;
        if (! is_array($js) || ! is_string($js['site'] ?? null) || ! is_string($js['sig'] ?? null)) {
            return '';
        }

        return hash_equals(self::sign(self::OPTION, 0, $js['site']), $js['sig']) ? $js['site'] : '';
    }

    /**
     * Replace the site-wide JS block, signed; '' clears it. Callers must
     * have passed Custom_Js_Guard. A legacy scalar under 'js' is replaced,
     * not indexed into: writing $data['js']['site'] onto a string fatals, and
     * inside Safe_Mutation that happens after the snapshot is persisted.
     */
    public static function set_js(string $js): void
    {
        $data = self::read();
        unset($data['js']);

        if ('' !== $js) {
            $data['js'] = ['site' => $js, 'sig' => self::sign(self::OPTION, 0, $js)];
        }

        if ([] === $data) {
            delete_option(self::OPTION);
            return;
        }

        update_option(self::OPTION, $data, false);
    }
}
