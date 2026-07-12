<?php

namespace WPMCP\Tools\Packages;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Permanently delete an installed theme's files.
 *
 * File-level and NOT reversible, same reasoning as Delete_Plugin: no full
 * file backup here (out of scope; see issue #24), disabled by default via
 * wpmcp_enable_delete_theme, and always requires confirm:true.
 *
 * Guardrails: the active theme can never be deleted, nor can a theme that is
 * the active theme's parent (deleting a child theme's parent breaks the
 * still-active child), and an unknown stylesheet errors rather than
 * silently no-op-ing.
 */
class Delete_Theme
{
    public static function is_enabled(): bool
    {
        return (bool) apply_filters('wpmcp_enable_delete_theme', false);
    }

    public function handle(array $args): array
    {
        if (! self::is_enabled()) {
            throw new \RuntimeException('The delete-theme tool is disabled. Enable it with the wpmcp_enable_delete_theme filter.');
        }

        $stylesheet = isset($args['stylesheet']) ? (string) $args['stylesheet'] : '';
        if ('' === $stylesheet) {
            throw new \InvalidArgumentException('A stylesheet (theme slug) is required.');
        }

        if (true !== ($args['confirm'] ?? null)) {
            throw new \InvalidArgumentException('Deleting a theme is permanent. Pass confirm:true to proceed.');
        }

        $theme = wp_get_theme($stylesheet);
        if (! $theme->exists()) {
            throw new \RuntimeException("Theme \"{$stylesheet}\" was not found.");
        }

        $active_stylesheet = get_stylesheet();
        $active_template   = get_template();
        if ($stylesheet === $active_stylesheet || $stylesheet === $active_template) {
            throw new \RuntimeException("Refusing to delete active theme \"{$stylesheet}\".");
        }

        if (! Package_Guard::filesystem_ready()) {
            throw new \RuntimeException('Direct filesystem access is required to delete themes.');
        }

        if (! function_exists('delete_theme')) {
            require_once ABSPATH . 'wp-admin/includes/theme.php';
        }

        $result = delete_theme($stylesheet);
        if (is_wp_error($result)) {
            throw new \RuntimeException('Theme delete failed: ' . $result->get_error_message());
        }

        return [
            'stylesheet'        => $stylesheet,
            'deleted'           => true,
            'files_recoverable' => false,
            'warning'           => 'This permanently deleted the theme\'s files; there is no rollback for file deletion (see issue #24).',
        ];
    }
}
