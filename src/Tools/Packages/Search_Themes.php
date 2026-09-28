<?php

namespace WPMCP\Tools\Packages;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Search the wordpress.org theme directory (issue #282).
 *
 * The theme twin of Search_Plugins, and read-only for the same reason: it
 * goes through core's themes_api('query_themes', ...), WordPress's own
 * client for the wordpress.org theme repository API. No filesystem or
 * option state is touched, so there is nothing to snapshot or roll back.
 */
class Search_Themes
{
    private const DEFAULT_PER_PAGE = 10;
    private const MAX_PER_PAGE     = 50;

    public function handle(array $args): array
    {
        $query = isset($args['query']) ? trim((string) $args['query']) : '';
        if ('' === $query) {
            throw new \InvalidArgumentException('A search query is required.');
        }

        $per_page = isset($args['per_page']) ? (int) $args['per_page'] : self::DEFAULT_PER_PAGE;
        if ($per_page < 1) {
            $per_page = self::DEFAULT_PER_PAGE;
        }
        if ($per_page > self::MAX_PER_PAGE) {
            $per_page = self::MAX_PER_PAGE;
        }

        $request = [
            'search'   => $query,
            'per_page' => $per_page,
            'fields'   => [
                'description'     => false,
                'sections'        => false,
                'rating'          => true,
                'ratings'         => false,
                'active_installs' => true,
                'requires'        => true,
                'requires_php'    => true,
            ],
        ];

        if (! empty($args['tag'])) {
            $request['tag'] = [(string) $args['tag']];
        }
        if (! empty($args['author'])) {
            $request['author'] = (string) $args['author'];
        }

        if (! function_exists('themes_api')) {
            require_once ABSPATH . 'wp-admin/includes/theme.php';
        }

        $result = themes_api('query_themes', $request);
        if (is_wp_error($result)) {
            throw new \RuntimeException('Theme search failed: ' . esc_html($result->get_error_message()));
        }

        $themes = [];
        foreach ((array) ($result->themes ?? []) as $theme) {
            $theme    = (object) $theme;
            $author   = $theme->author ?? '';
            $themes[] = [
                'name'            => (string) ($theme->name ?? ''),
                'slug'            => (string) ($theme->slug ?? ''),
                'version'         => (string) ($theme->version ?? ''),
                'rating'          => $theme->rating ?? 0,
                'num_ratings'     => $theme->num_ratings ?? 0,
                'active_installs' => $theme->active_installs ?? 0,
                'author'          => is_array($author) || is_object($author)
                    ? (string) (((array) $author)['display_name'] ?? ((array) $author)['user_nicename'] ?? '')
                    : (string) $author,
                'requires'        => $theme->requires ?? '',
                'requires_php'    => $theme->requires_php ?? '',
            ];
        }

        return ['themes' => $themes];
    }
}
