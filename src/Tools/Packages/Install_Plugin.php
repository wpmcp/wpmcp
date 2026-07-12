<?php

namespace WPMCP\Tools\Packages;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Install a plugin from wordpress.org by slug.
 *
 * Safe_Mutation exemption: install only ever adds new files (and, if
 * activate:true, changes the active_plugins option for a plugin that didn't
 * exist a moment ago); there is no prior state to snapshot or roll back, the
 * same reasoning Create_User and create-post use to skip Safe_Mutation.
 *
 * wordpress.org-only: the slug must be a bare plugin directory slug (letters,
 * digits, dashes, underscores), never a URL or filesystem path, so this tool
 * can never be turned into an arbitrary-zip-URL installer. plugins_api() is
 * WordPress core's own client for the wordpress.org plugin repository API,
 * so passing it a slug never reaches outside that repository.
 */
class Install_Plugin
{
    private const SLUG_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    public function handle(array $args): array
    {
        $slug = isset($args['slug']) ? (string) $args['slug'] : '';
        if ('' === $slug) {
            throw new \InvalidArgumentException('A plugin slug is required.');
        }
        if (! preg_match(self::SLUG_PATTERN, $slug)) {
            throw new \InvalidArgumentException('Invalid plugin slug: only wordpress.org-style slugs (letters, digits, dashes) are allowed.');
        }

        if (! Package_Guard::filesystem_ready()) {
            throw new \RuntimeException('Direct filesystem access is required to install plugins.');
        }

        if (! function_exists('plugins_api')) {
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        }
        if (! class_exists('Plugin_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }

        $info = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
        if (is_wp_error($info)) {
            throw new \RuntimeException('Could not find plugin "' . $slug . '" on wordpress.org: ' . $info->get_error_message());
        }

        $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
        $result   = $upgrader->install($info->download_link);
        if (is_wp_error($result) || ! $result) {
            $message = is_wp_error($result) ? $result->get_error_message() : 'unknown error';
            throw new \RuntimeException('Plugin install failed: ' . $message);
        }

        $plugin_file = $upgrader->plugin_info();
        if (! $plugin_file) {
            throw new \RuntimeException('Plugin installed but its main file could not be determined.');
        }

        $activated = false;
        if (! empty($args['activate'])) {
            $activation = activate_plugin($plugin_file);
            $activated  = ! is_wp_error($activation);
        }

        return ['installed' => true, 'file' => $plugin_file, 'slug' => $slug, 'activated' => $activated];
    }
}
