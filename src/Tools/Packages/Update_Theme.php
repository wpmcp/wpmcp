<?php

namespace WPMCP\Tools\Packages;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Update an installed theme to the latest version available on
 * wordpress.org.
 *
 * File-level and NOT reversible, same reasoning as Update_Plugin: no full
 * file backup here (out of scope; see issue #24). Disabled by default via
 * wpmcp_enable_update_theme and always requires confirm:true.
 *
 * When no update is available this is a safe no-op that just reports
 * up_to_date:true without ever touching the filesystem or the upgrader.
 */
class Update_Theme
{
    public static function is_enabled(): bool
    {
        return (bool) apply_filters('wpmcp_enable_update_theme', false);
    }

    public function handle(array $args): array
    {
        if (! self::is_enabled()) {
            throw new \RuntimeException('The update-theme tool is disabled. Enable it with the wpmcp_enable_update_theme filter.');
        }

        $stylesheet = isset($args['stylesheet']) ? (string) $args['stylesheet'] : '';
        if ('' === $stylesheet) {
            throw new \InvalidArgumentException('A stylesheet (theme slug) is required.');
        }

        if (true !== ($args['confirm'] ?? null)) {
            throw new \InvalidArgumentException('Updating a theme modifies files on disk. Pass confirm:true to proceed.');
        }

        $theme = wp_get_theme($stylesheet);
        if (! $theme->exists()) {
            throw new \RuntimeException(sprintf('Theme "%s" was not found.', esc_html($stylesheet)));
        }

        $update_themes = get_site_transient('update_themes');
        $updates       = is_object($update_themes) ? (array) ($update_themes->response ?? []) : [];

        if (! isset($updates[ $stylesheet ])) {
            return ['stylesheet' => $stylesheet, 'up_to_date' => true, 'updated' => false];
        }

        if (! Package_Guard::filesystem_ready()) {
            throw new \RuntimeException('Direct filesystem access is required to update themes.');
        }

        if (! class_exists('Theme_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }

        $upgrader = new \Theme_Upgrader(new \Automatic_Upgrader_Skin());
        $result   = $upgrader->upgrade($stylesheet);
        if (is_wp_error($result) || ! $result) {
            $message = is_wp_error($result) ? $result->get_error_message() : 'unknown error';
            throw new \RuntimeException('Theme update failed: ' . esc_html($message));
        }

        $entry       = $updates[ $stylesheet ];
        $new_version = is_array($entry) ? ($entry['new_version'] ?? null) : ($entry->new_version ?? null);

        return [
            'stylesheet'         => $stylesheet,
            'up_to_date'         => false,
            'updated'            => true,
            'new_version'        => $new_version,
            'files_recoverable'  => false,
            'warning'            => 'This permanently overwrote the theme\'s files; there is no rollback for file changes (see issue #24).',
        ];
    }
}
