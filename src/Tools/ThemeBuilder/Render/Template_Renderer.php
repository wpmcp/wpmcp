<?php

namespace WPMCP\Tools\ThemeBuilder\Render;

use WPMCP\Tools\ThemeBuilder\Template_Resolver;
use WPMCP\Tools\ThemeBuilder\Template_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The render side of the theme-builder subsystem (issue #70): turn the live
 * main query into the same normalized context the resolve tool takes, ask the
 * resolver which template wins, and render that template's markup.
 *
 * Kept separate from the adapters so the two questions stay testable apart:
 * "which template wins here" (this class, theme-independent) and "where does
 * the active theme let us put it" (the adapters).
 */
class Template_Renderer
{
    /** The part type the swapped-in document template is currently rendering. */
    private static string $current_part = '';

    /**
     * Normalized description of the live request, matching the `context`
     * argument of wpmcp/resolve-site-part exactly so what an agent previews
     * and what the front end renders cannot drift apart.
     *
     * @return array<string,mixed>
     */
    public static function context_from_query(): array
    {
        return [
            'is_front_page' => is_front_page(),
            'is_404'        => is_404(),
            'is_search'     => is_search(),
            'is_archive'    => is_archive(),
            'is_singular'   => is_singular(),
            'post_type'     => (string) get_post_type(),
            'post_id'       => (int) get_queried_object_id(),
        ];
    }

    /**
     * Rendered markup for the winning template of a part type, or '' when no
     * active template matches. Content was filtered with wp_kses_post() on
     * the way into the store, so do_blocks() here is rendering already-safe
     * markup rather than trusting the caller.
     */
    public static function render(string $part_type, ?array $context = null): string
    {
        $winner = Template_Resolver::resolve($part_type, $context ?? self::context_from_query())['winner'];
        if (null === $winner) {
            return '';
        }
        $template = Template_Store::get((int) $winner['template_id']);
        if (null === $template) {
            return '';
        }
        return do_blocks($template['content']);
    }

    /** True when an active template of this part type wins the current request. */
    public static function has_winner(string $part_type): bool
    {
        return null !== Template_Resolver::resolve($part_type, self::context_from_query())['winner'];
    }

    public static function set_current_part(string $part_type): void
    {
        self::$current_part = $part_type;
    }

    /** Called from document.php, the template the adapters hand to template_include. */
    public static function render_current(): string
    {
        return '' === self::$current_part ? '' : self::render(self::$current_part);
    }

    /** Absolute path of the document template the adapters swap in. */
    public static function document_template(): string
    {
        return __DIR__ . '/document.php';
    }
}
