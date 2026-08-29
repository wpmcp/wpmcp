<?php

namespace WPMCP\Tools\Packages;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Permanently delete an installed plugin's files.
 *
 * File-level and NOT reversible: unlike Deactivate_Plugin (an option flip),
 * this removes the plugin's directory from disk. There is no full file
 * backup here (out of scope; see issue #24), so this is disabled by default
 * (sites must opt in via the wpmcp_enable_delete_plugin filter) and always
 * requires confirm:true, mirroring Delete_Media's honesty about
 * irreversibility rather than claiming a rollback that doesn't exist.
 *
 * Guardrails: protected packages (wpmcp, Elementor) always refuse, and an
 * active plugin must be deactivated first (deleting live code out from under
 * a running plugin is asking for a fatal error on the next request).
 */
class Delete_Plugin
{
    public static function is_enabled(): bool
    {
        return (bool) apply_filters('wpmcp_enable_delete_plugin', false);
    }

    public function handle(array $args): array
    {
        if (! self::is_enabled()) {
            throw new \RuntimeException('The delete-plugin tool is disabled. Enable it with the wpmcp_enable_delete_plugin filter.');
        }

        $plugin = isset($args['plugin']) ? (string) $args['plugin'] : '';
        if ('' === $plugin) {
            throw new \InvalidArgumentException('A plugin file is required.');
        }

        if (true !== ($args['confirm'] ?? null)) {
            throw new \InvalidArgumentException('Deleting a plugin is permanent. Pass confirm:true to proceed.');
        }

        if (Package_Guard::is_protected_plugin($plugin)) {
            throw new \RuntimeException('Refusing to delete protected plugin "' . esc_html($plugin) . '".');
        }

        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        if (! isset($all_plugins[ $plugin ])) {
            throw new \RuntimeException('Plugin "' . esc_html($plugin) . '" was not found.');
        }

        if (is_plugin_active($plugin)) {
            throw new \RuntimeException('Refusing to delete active plugin "' . esc_html($plugin) . '"; deactivate it first.');
        }

        if (! Package_Guard::filesystem_ready()) {
            throw new \RuntimeException('Direct filesystem access is required to delete plugins.');
        }

        $result = delete_plugins([$plugin]);
        if (is_wp_error($result)) {
            throw new \RuntimeException('Plugin delete failed: ' . esc_html($result->get_error_message()));
        }

        return [
            'plugin'             => $plugin,
            'deleted'            => true,
            'files_recoverable'  => false,
            'warning'            => 'This permanently deleted the plugin\'s files; there is no rollback for file deletion (see issue #24).',
        ];
    }
}
