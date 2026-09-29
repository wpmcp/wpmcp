<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Safety).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Safety).

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The undo point for a write to a block theme template or template part
 * (issue #378), keyed by what the site editor keys it by rather than by
 * post ID: "<post type>|<theme>//<slug>", for example
 * "wp_template_part|twentytwentyfour//header".
 *
 * Core keeps two layers. The theme's file (templates/*.html, parts/*.html)
 * is never written; a user customization is a wp_template or
 * wp_template_part post named after the slug and tagged with the theme in
 * the wp_theme taxonomy, and it wins over the file while it exists. So the
 * state a write can change is "which customization post exists for this
 * key, and what it holds", and that is what this captures: the whole post
 * (row, meta, terms) through the ordinary post capture, or its absence.
 *
 * The key is known before the write, the post ID of a first customization
 * is not, which is why this is not a plain 'post' snapshot. The restore
 * works from the key:
 *  - captured absent (the write created the customization): the post that
 *    now holds the key is deleted, so the template falls back to its theme
 *    file again. Nothing else is touched.
 *  - captured present (the write updated or reverted it): any other post
 *    that holds the key now is deleted, and the captured post is put back
 *    through the post restore, in place or resurrected at its original ID
 *    with its terms, so a reverted customization comes back as it was.
 *
 * The user global styles post (wp_global_styles, issue #379) is the same
 * shape, one post per theme tagged in wp_theme, so it is captured and
 * restored through this too, under the fixed slug GLOBAL_STYLES_SLUG.
 *
 * Restoring takes edit_theme_options, the capability core requires to edit
 * templates, so rollback-operation (edit_posts) cannot become a way around
 * it (see Rollback_Service::restore_capabilities()).
 */
class Site_Template_Snapshot
{
    public const TYPE = 'site_template';

    /** The post types a key may name. */
    public const POST_TYPES = ['wp_template', 'wp_template_part', 'wp_global_styles'];

    /**
     * The user global styles post (issue #379) is keyed the same way, with
     * a fixed slug: core looks it up by theme alone, never by post name, so
     * the key's slug is only a label.
     */
    public const GLOBAL_STYLES_SLUG = 'global-styles';

    public static function key(string $post_type, string $theme, string $slug): string
    {
        return $post_type . '|' . $theme . '//' . $slug;
    }

    /**
     * @return array{0:string,1:string,2:string}|null [post type, theme, slug], or null for a malformed key.
     */
    public static function parse_key(string $key): ?array
    {
        $parts = explode('|', $key, 2);
        if (2 !== count($parts) || ! in_array($parts[0], self::POST_TYPES, true)) {
            return null;
        }
        $id = explode('//', $parts[1], 2);
        if (2 !== count($id) || '' === $id[0] || '' === $id[1]) {
            return null;
        }
        return [$parts[0], $id[0], $id[1]];
    }

    /**
     * Every customization post that holds the key, oldest first. Core reads
     * the same statuses (auto-draft, draft, publish) and ignores the trash.
     *
     * @return int[]
     */
    public static function customization_ids(string $post_type, string $theme, string $slug): array
    {
        $args = [
            'post_type'              => $post_type,
            'post_status'            => ['auto-draft', 'draft', 'publish'],
            'name'                   => $slug,
            'posts_per_page'         => -1,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the wp_theme term is how core itself scopes a template to its theme.
            'tax_query'              => [[
                'taxonomy' => 'wp_theme',
                'field'    => 'name',
                'terms'    => $theme,
            ]],
        ];
        if ('wp_global_styles' === $post_type) {
            unset($args['name']);
        }
        $query = new \WP_Query($args);
        return array_map('intval', $query->posts);
    }

    public static function capture(string $key): array
    {
        $parsed = self::parse_key($key);
        $ids    = null === $parsed ? [] : self::customization_ids(...$parsed);
        $post   = null;
        if ([] !== $ids) {
            // The newest one is what core renders; capture that.
            $post = Snapshot::capture('post', $ids[ count($ids) - 1 ]);
        }

        return [
            'object_type' => self::TYPE,
            'object_id'   => $key,
            'data'        => [
                'key'     => $key,
                'existed' => null !== $post,
                'post'    => $post,
            ],
        ];
    }

    public static function restore(array $snapshot): void
    {
        $data   = (array) ($snapshot['data'] ?? []);
        $parsed = self::parse_key((string) ($data['key'] ?? $snapshot['object_id'] ?? ''));
        if (null === $parsed) {
            return;
        }

        $captured    = is_array($data['post'] ?? null) ? $data['post'] : null;
        $captured_id = null === $captured ? 0 : (int) $captured['object_id'];

        foreach (self::customization_ids(...$parsed) as $id) {
            if ($id !== $captured_id) {
                wp_delete_post($id, true);
            }
        }

        if (null !== $captured) {
            Rollback_Service::apply_snapshot($captured);
            clean_post_cache($captured_id);
        }

        if ('wp_global_styles' === $parsed[0]) {
            // Core memoizes the resolved theme.json and the stylesheet built
            // from it; without this the restored styles would not show until
            // the next request.
            \WP_Theme_JSON_Resolver::clean_cached_data();
            wp_clean_theme_json_cache();
        }
    }
}
