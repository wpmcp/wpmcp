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
 * Defense in depth: CSS is re-run through Css_Sanitizer at print time, so a
 * payload that reached the option by some other route (direct DB edit,
 * another plugin) still cannot break out of the <style> element. A block the
 * sanitizer now rejects is dropped from output AND logged, so an operator
 * chasing "my CSS stopped rendering" after a sanitizer rule change has
 * something to find.
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

        $block = Custom_Code_Store::read_css($post_id);
        if ('' === trim($block)) {
            return;
        }

        try {
            $safe = Css_Sanitizer::sanitize($block);
        } catch (\InvalidArgumentException $e) {
            error_log(sprintf(
                '[wpmcp] Stored custom CSS for post %d was dropped at render: %s',
                $post_id,
                $e->getMessage()
            ));
            return;
        }

        if ('' === $safe) {
            return;
        }

        echo "\n<style id=\"wpmcp-custom-css\">\n" . $safe . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized above; escaping would corrupt CSS.
    }

    /**
     * The post whose CSS block applies to this request, or 0.
     *
     * get_queried_object_id() alone is wrong here: it returns a term_id on
     * category/tag archives and a user ID on author archives, and those ids
     * share an integer space with the post ids used as store keys, so
     * /category/foo/ (term 12) would print the CSS stored for post 12. The
     * ability promises "renders only on that page", so only a singular
     * request for a real WP_Post qualifies.
     */
    private static function scoped_post_id(): int
    {
        if (! is_singular()) {
            return 0;
        }

        $object = get_queried_object();

        return ($object instanceof \WP_Post) ? (int) $object->ID : 0;
    }

    public static function print_js(): void
    {
        if (! Custom_Js_Guard::is_enabled()) {
            return;
        }

        $data = Custom_Code_Store::read();
        $js   = isset($data['js']['site']) ? (string) $data['js']['site'] : '';

        if ('' === trim($js) || preg_match('#</\s*script#i', $js)) {
            return;
        }

        echo "\n<script id=\"wpmcp-custom-js\">\n" . $js . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- gated by Custom_Js_Guard; stored via unfiltered_html holders only.
    }
}
