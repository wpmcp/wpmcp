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
        $post_id   = (int) get_queried_object_id();
        $post_type = (string) get_post_type();
        $term_ids  = [];
        if (is_singular() && $post_id > 0) {
            $term_ids = self::post_term_ids($post_id, $post_type);
        } elseif ((is_category() || is_tag() || is_tax()) && $post_id > 0) {
            // On a term archive the queried object is the term itself.
            $term_ids = [$post_id];
        }
        $user = wp_get_current_user();

        return [
            'is_front_page' => is_front_page(),
            'is_404'        => is_404(),
            'is_search'     => is_search(),
            'is_archive'    => is_archive(),
            'is_singular'   => is_singular(),
            'post_type'     => $post_type,
            'post_id'       => $post_id,
            'term_ids'      => $term_ids,
            'user_roles'    => $user->exists() ? array_values(array_map('strval', (array) $user->roles)) : [],
        ];
    }

    /**
     * Every term id attached to a post across its post type's taxonomies,
     * for the term rule. Shared with the resolve tool so the preview and the
     * front end derive terms the same way. get_the_terms() reads the object
     * term cache the main query already primed, so this adds no query on a
     * normal front-end request.
     *
     * @return int[]
     */
    public static function post_term_ids(int $post_id, string $post_type): array
    {
        $ids = [];
        foreach (get_object_taxonomies($post_type) as $taxonomy) {
            $terms = get_the_terms($post_id, $taxonomy);
            if (is_array($terms)) {
                foreach ($terms as $term) {
                    $ids[] = (int) $term->term_id;
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * The stored template that wins this part type for the live request (or
     * the given context), or null. One resolve per call site: callers that
     * need to know whether to replace something and then what to replace it
     * with ask once and render the result with render_template().
     *
     * @return array<string,mixed>|null
     */
    public static function winner(string $part_type, ?array $context = null): ?array
    {
        $winner = Template_Resolver::resolve($part_type, $context ?? self::context_from_query())['winner'];
        if (null === $winner) {
            return null;
        }
        return Template_Store::get((int) $winner['template_id']);
    }

    /**
     * Rendered markup for a stored template. Content was filtered with
     * wp_kses_post() on the way into the store, so do_blocks() renders
     * already-safe markup.
     *
     * @param array<string,mixed> $template a Template_Store::get() row
     */
    public static function render_template(array $template): string
    {
        return do_blocks((string) ($template['content'] ?? ''));
    }

    /**
     * Rendered markup for the winning template of a part type, or '' when no
     * active template matches. Content was filtered with wp_kses_post() on
     * the way into the store, so do_blocks() here is rendering already-safe
     * markup rather than trusting the caller.
     */
    public static function render(string $part_type, ?array $context = null): string
    {
        $template = self::winner($part_type, $context);
        return null === $template ? '' : self::render_template($template);
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
