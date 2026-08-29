<?php

namespace WPMCP\Tools\Packages;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Install a theme from wordpress.org by slug.
 *
 * Safe_Mutation exemption: install only ever adds new files (and, if
 * activate:true, switches to a theme that didn't exist a moment ago); there
 * is no prior state to snapshot or roll back, the same reasoning
 * Install_Plugin uses.
 *
 * wordpress.org-only: the slug must be a bare theme directory slug (letters,
 * digits, dashes, underscores), never a URL or filesystem path. themes_api()
 * is WordPress core's own client for the wordpress.org theme repository API,
 * so passing it a slug never reaches outside that repository.
 */
class Install_Theme
{
    private const SLUG_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    public function handle(array $args): array
    {
        $slug = isset($args['slug']) ? (string) $args['slug'] : '';
        if ('' === $slug) {
            throw new \InvalidArgumentException('A theme slug is required.');
        }
        if (! preg_match(self::SLUG_PATTERN, $slug)) {
            throw new \InvalidArgumentException('Invalid theme slug: only wordpress.org-style slugs (letters, digits, dashes) are allowed.');
        }

        if (! Package_Guard::filesystem_ready()) {
            throw new \RuntimeException('Direct filesystem access is required to install themes.');
        }

        if (! function_exists('themes_api')) {
            require_once ABSPATH . 'wp-admin/includes/theme.php';
        }
        if (! class_exists('Theme_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }

        $info = themes_api('theme_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
        if (is_wp_error($info)) {
            throw new \RuntimeException(
                'Could not find theme "' . esc_html($slug) . '" on wordpress.org: ' . esc_html($info->get_error_message())
            );
        }

        $upgrader = new \Theme_Upgrader(new \Automatic_Upgrader_Skin());
        $result   = $upgrader->install($info->download_link);
        if (is_wp_error($result) || ! $result) {
            $message = is_wp_error($result) ? $result->get_error_message() : 'unknown error';
            throw new \RuntimeException('Theme install failed: ' . esc_html($message));
        }

        $activated = false;
        if (! empty($args['activate'])) {
            switch_theme($slug);
            $activated = get_stylesheet() === $slug;
        }

        return ['installed' => true, 'slug' => $slug, 'activated' => $activated];
    }
}
