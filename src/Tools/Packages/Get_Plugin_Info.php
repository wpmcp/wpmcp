<?php

namespace WPMCP\Tools\Packages;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Fetch full wordpress.org plugin directory info for a single slug.
 *
 * Read-only: uses core's plugins_api('plugin_information', ...), the same
 * client Install_Plugin uses to resolve a download link. No filesystem or
 * option state is touched, so there is nothing to snapshot or roll back.
 */
class Get_Plugin_Info
{
    public function handle(array $args): array
    {
        $slug = isset($args['slug']) ? trim((string) $args['slug']) : '';
        if ('' === $slug) {
            throw new \InvalidArgumentException('A plugin slug is required.');
        }

        if (! function_exists('plugins_api')) {
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        }

        $info = plugins_api('plugin_information', ['slug' => $slug]);
        if (is_wp_error($info)) {
            throw new \RuntimeException('Could not fetch plugin info for "' . $slug . '": ' . $info->get_error_message());
        }

        $sections         = (array) ($info->sections ?? []);
        $short_description = $info->short_description ?? ($sections['description'] ?? '');

        return [
            'name'              => $info->name ?? '',
            'slug'              => $info->slug ?? $slug,
            'version'           => $info->version ?? '',
            'rating'            => $info->rating ?? 0,
            'num_ratings'       => $info->num_ratings ?? 0,
            'active_installs'   => $info->active_installs ?? 0,
            'homepage'          => $info->homepage ?? '',
            'download_link'     => $info->download_link ?? '',
            'requires'          => $info->requires ?? '',
            'tested'            => $info->tested ?? '',
            'short_description' => $short_description,
        ];
    }
}
