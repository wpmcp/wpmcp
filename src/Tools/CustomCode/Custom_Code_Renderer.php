<?php

namespace WPMCP\Tools\CustomCode;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Front-end output for the custom code store (issue #63): site-wide and
 * page-scoped CSS in wp_head, the governance-gated JS snippet in wp_footer.
 *
 * Defense in depth: CSS is re-run through Css_Sanitizer at print time, so a
 * payload that reached the option by some other route (direct DB edit,
 * another plugin) still cannot break out of the <style> element; anything
 * the sanitizer rejects is silently dropped from output. JS is printed ONLY
 * while Custom_Js_Guard::is_enabled() holds, so disabling the governance
 * gate also stops rendering of previously stored snippets.
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

    public static function print_css(): void
    {
        $data = Custom_Code_Store::read();

        $blocks = [];
        if (! empty($data['css']['site'])) {
            $blocks[] = (string) $data['css']['site'];
        }
        $post_id = get_queried_object_id();
        if ($post_id && ! empty($data['css']['posts'][ $post_id ])) {
            $blocks[] = (string) $data['css']['posts'][ $post_id ];
        }

        $safe = [];
        foreach ($blocks as $block) {
            try {
                $safe[] = Css_Sanitizer::sanitize($block);
            } catch (\InvalidArgumentException $e) {
                // Stored value no longer passes the sanitizer: drop it.
            }
        }

        if ($safe) {
            echo "\n<style id=\"wpmcp-custom-css\">\n" . implode("\n", $safe) . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized above; escaping would corrupt CSS.
        }
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
