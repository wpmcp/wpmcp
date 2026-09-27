<?php

namespace WPMCP\Tools\CustomCode;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Front-end output for the custom code store (issue #63): page-scoped CSS in
 * wp_head, the governance-gated JS snippet in wp_footer.
 *
 * Booted from Plugin::register_builder_runtime_hooks(), NOT from ability
 * registration. Ability registration runs on wp_abilities_api_init, which
 * fires lazily on first registry access and is never reached on a plain
 * front-end page view, so hooking output there meant stored code never
 * rendered for visitors at all. It also gave a pure catalog operation
 * (replayed in wp-admin and against throwaway Registrars in tests) a
 * permanent add_action side effect.
 *
 * Rendering is gated on the ability group being enabled for the flavor, and
 * deliberately NOT on Gate::is_pro(): a lapsed license must not silently
 * strip CSS a site is already relying on, the same reasoning Memory_Store's
 * runtime hooks are registered under. JS output has its own gate below.
 *
 * Only SIGNED values are printed (see Custom_Code_Store): a value that
 * reached the option by another route, such as a direct DB edit or another
 * plugin, is ignored rather than trusted. A block signed under an older
 * sanitizer rule version is re-checked against the current rules, once per
 * block, and a block that now fails is dropped from output AND logged, so an
 * operator chasing "my CSS stopped rendering" after a rule change has
 * something to find.
 *
 * boot() also owns one non-output hook, 'deleted_post', because it is the
 * only wiring in this group that runs on every request. See
 * Custom_Code_Store::delete_css() for why an orphaned block is a correctness
 * problem rather than housekeeping.
 */
class Custom_Code_Renderer
{
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        add_action('wp_head', [self::class, 'print_css'], 101);
        add_action('wp_footer', [self::class, 'print_js'], 101);
        // Store lifecycle, not output, but this is the one place that runs on
        // every request for this group. A page's CSS block must not outlive
        // the page: post ids are reused after a restore or an import, so an
        // orphaned wpmcp_custom_code_post_<id> option would re-attach itself
        // to whatever post takes the id next.
        add_action('deleted_post', [Custom_Code_Store::class, 'delete_css']);
    }

    /** Test seam: let a test re-boot the renderer against fresh hooks. */
    public static function reset_for_tests(): void
    {
        self::$booted = false;
    }

    public static function print_css(): void
    {
        $post_id = self::scoped_post_id();
        if (! $post_id) {
            return;
        }

        // Only a block this plugin wrote is printed. An unsigned or forged
        // value (written straight into wp_options by a DB row tool, or copied
        // from another page's option) is ignored.
        $block = Custom_Code_Store::verified_css($post_id);
        if (null === $block || '' === trim($block['css'])) {
            return;
        }

        if (! self::passes_current_rules($block, $post_id)) {
            return;
        }

        echo "\n<style id=\"wpmcp-custom-css\">\n" . trim($block['css']) . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- signed at write time after Css_Sanitizer accepted it, and re-checked here when the rules changed since; escaping would corrupt CSS.
    }

    /**
     * Whether a verified block passes the CURRENT sanitizer. A block signed
     * under the current rule version passed them at write time, so the
     * signature already answers the question and nothing is re-run. A block
     * signed under an older version is re-checked once, and the verdict is
     * cached against its signature and the current version, so a sanitizer
     * change costs one pass per block rather than one per page view.
     *
     * @param array{css:string, rules:int, sig:string, current:bool} $block
     */
    private static function passes_current_rules(array $block, int $post_id): bool
    {
        if ($block['current']) {
            return true;
        }

        $key     = 'wpmcp_css_verdict_' . substr($block['sig'], 0, 32) . '_' . Css_Sanitizer::RULES_VERSION;
        $verdict = get_transient($key);
        if ('pass' === $verdict) {
            return true;
        }
        if ('fail' === $verdict) {
            return false;
        }

        try {
            Css_Sanitizer::sanitize($block['css']);
            set_transient($key, 'pass', DAY_IN_SECONDS);

            return true;
        } catch (\InvalidArgumentException $e) {
            set_transient($key, 'fail', DAY_IN_SECONDS);
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator signal, logged once per block per rule version (the verdict is cached): stored CSS that no longer passes the sanitizer is dropped, not printed.
            error_log(sprintf(
                '[wpmcp] Stored custom CSS for post %d was dropped at render: %s',
                $post_id,
                $e->getMessage()
            ));

            return false;
        }
    }

    /**
     * The post whose CSS block applies to this request, or 0.
     *
     * get_queried_object_id() alone is wrong here: it returns a term_id on
     * category/tag archives and a user ID on author archives, and those ids
     * share an integer space with the post ids used as store keys, so
     * /category/foo/ (term 12) would print the CSS stored for post 12. So the
     * id is derived per request type, never read off an arbitrary queried
     * object:
     *
     *  - a singular request for a real WP_Post: that post;
     *  - the posts page (page_for_posts), which WordPress serves through
     *    is_home() rather than is_singular();
     *  - the WooCommerce shop page, which is served as the product archive.
     *
     * Add_Scoped_Css refuses every other kind of post, so nothing is stored
     * that this cannot reach.
     */
    private static function scoped_post_id(): int
    {
        if (is_singular()) {
            $object = get_queried_object();

            return ($object instanceof \WP_Post) ? (int) $object->ID : 0;
        }

        if (is_home() && 'page' === get_option('show_on_front')) {
            return (int) get_option('page_for_posts');
        }

        if (function_exists('is_shop') && function_exists('wc_get_page_id') && is_shop()) {
            $shop = (int) wc_get_page_id('shop');

            return $shop > 0 ? $shop : 0;
        }

        return 0;
    }

    public static function print_js(): void
    {
        if (! Custom_Js_Guard::is_enabled()) {
            return;
        }

        // Only a snippet this plugin wrote, behind the gate and the
        // unfiltered_html check, is printed. A value written straight into
        // wp_options (update-rows never consults Option_Guard) is ignored.
        $js = Custom_Code_Store::verified_js();

        if ('' === trim($js) || Custom_Js_Guard::has_breakout($js)) {
            return;
        }

        echo "\n<script id=\"wpmcp-custom-js\">\n" . $js . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- signed at write time, which only Add_Custom_Js does after Custom_Js_Guard and the unfiltered_html check; printed only while the gate is open.
    }
}
