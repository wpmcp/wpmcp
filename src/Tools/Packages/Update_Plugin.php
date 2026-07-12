<?php

namespace WPMCP\Tools\Packages;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Update an installed plugin to the latest version available on
 * wordpress.org.
 *
 * File-level and NOT reversible: like Delete_Plugin, there is no full file
 * backup here (out of scope; see issue #24). Disabled by default via
 * wpmcp_enable_update_plugin and always requires confirm:true.
 *
 * When no update is available this is a safe no-op that just reports
 * up_to_date:true without ever touching the filesystem or the upgrader.
 */
class Update_Plugin
{
    public static function is_enabled(): bool
    {
        return (bool) apply_filters('wpmcp_enable_update_plugin', false);
    }

    public function handle(array $args): array
    {
        if (! self::is_enabled()) {
            throw new \RuntimeException('The update-plugin tool is disabled. Enable it with the wpmcp_enable_update_plugin filter.');
        }

        $plugin = isset($args['plugin']) ? (string) $args['plugin'] : '';
        if ('' === $plugin) {
            throw new \InvalidArgumentException('A plugin file is required.');
        }

        if (true !== ($args['confirm'] ?? null)) {
            throw new \InvalidArgumentException('Updating a plugin modifies files on disk. Pass confirm:true to proceed.');
        }

        if (Package_Guard::is_protected_plugin($plugin)) {
            throw new \RuntimeException("Refusing to update protected plugin \"{$plugin}\".");
        }

        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        if (! isset($all_plugins[ $plugin ])) {
            throw new \RuntimeException("Plugin \"{$plugin}\" was not found.");
        }

        $update_plugins = get_site_transient('update_plugins');
        $updates        = is_object($update_plugins) ? (array) ($update_plugins->response ?? []) : [];

        if (! isset($updates[ $plugin ])) {
            return ['plugin' => $plugin, 'up_to_date' => true, 'updated' => false];
        }

        if (! Package_Guard::filesystem_ready()) {
            throw new \RuntimeException('Direct filesystem access is required to update plugins.');
        }

        if (! class_exists('Plugin_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }

        $upgrader = new \Plugin_Upgrader(new \Automatic_Upgrader_Skin());
        $result   = $upgrader->upgrade($plugin);
        if (is_wp_error($result) || ! $result) {
            $message = is_wp_error($result) ? $result->get_error_message() : 'unknown error';
            throw new \RuntimeException('Plugin update failed: ' . $message);
        }

        $new_version = $updates[ $plugin ]->new_version ?? null;

        return [
            'plugin'             => $plugin,
            'up_to_date'         => false,
            'updated'            => true,
            'new_version'        => $new_version,
            'files_recoverable'  => false,
            'warning'            => 'This permanently overwrote the plugin\'s files; there is no rollback for file changes (see issue #24).',
        ];
    }
}
