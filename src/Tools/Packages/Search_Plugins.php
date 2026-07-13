<?php

namespace WPMCP\Tools\Packages;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Search the wordpress.org plugin directory.
 *
 * Read-only: uses core's plugins_api('query_plugins', ...), which is
 * WordPress's own client for the wordpress.org plugin repository API. No
 * filesystem or option state is touched, so there is nothing to snapshot
 * or roll back.
 */
class Search_Plugins
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
                'short_description' => true,
                'active_installs'   => true,
                'tested'            => true,
                'requires'          => true,
                'author'            => true,
                'rating'            => true,
            ],
        ];

        if (! empty($args['tag'])) {
            $request['tag'] = (string) $args['tag'];
        }
        if (! empty($args['author'])) {
            $request['author'] = (string) $args['author'];
        }

        if (! function_exists('plugins_api')) {
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        }

        $result = plugins_api('query_plugins', $request);
        if (is_wp_error($result)) {
            throw new \RuntimeException('Plugin search failed: ' . $result->get_error_message());
        }

        $plugins = [];
        foreach ((array) ($result->plugins ?? []) as $plugin) {
            $plugins[] = [
                'name'              => $plugin->name ?? '',
                'slug'              => $plugin->slug ?? '',
                'version'           => $plugin->version ?? '',
                'rating'            => $plugin->rating ?? 0,
                'num_ratings'       => $plugin->num_ratings ?? 0,
                'active_installs'   => $plugin->active_installs ?? 0,
                'short_description' => $plugin->short_description ?? '',
                'author'            => $plugin->author ?? '',
                'requires'          => $plugin->requires ?? '',
                'tested'            => $plugin->tested ?? '',
            ];
        }

        return ['plugins' => $plugins];
    }
}
