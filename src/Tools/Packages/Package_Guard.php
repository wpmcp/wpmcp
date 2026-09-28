<?php

namespace WPMCP\Tools\Packages;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shared guardrails for the plugin/theme package tools.
 *
 * Protected packages (this plugin itself, and Elementor free/pro) can never
 * be deactivated or deleted through these tools: an agent that disables its
 * own delivery mechanism, or the site's page builder, would strand the site
 * with no way to recover other than direct server access.
 */
class Package_Guard
{
    /** Plugin file paths (relative to the plugins dir) that may never be deactivated or deleted. */
    private const PROTECTED_PLUGINS = [
        'wpmcp/wpmcp.php',
        'elementor/elementor.php',
        'elementor-pro/elementor-pro.php',
    ];

    public static function is_protected_plugin(string $plugin_file): bool
    {
        return in_array($plugin_file, self::PROTECTED_PLUGINS, true);
    }

    /**
     * Whether a plugin DIRECTORY belongs to a protected package: the
     * directory of any protected plugin file, or the directory this plugin
     * is actually running from (a renamed install still protects itself).
     * install-package-from-zip checks this before it can overwrite anything.
     */
    public static function is_protected_plugin_dir(string $dir): bool
    {
        $dirs = array_map('dirname', self::PROTECTED_PLUGINS);
        if (defined('WPMCP_FILE')) {
            $dirs[] = dirname(plugin_basename(WPMCP_FILE));
        }
        return in_array(strtolower($dir), array_map('strtolower', $dirs), true);
    }

    /**
     * The default-off opt-in gate for installing packages from an uploaded
     * ZIP (issue #282). A ZIP from outside the directory is arbitrary code,
     * so the same constant-plus-filter pattern as the other execution-class
     * gates applies, and Governance\Opt_In_Gates reports it.
     */
    public static function zip_install_enabled(): bool
    {
        $default = defined('WPMCP_ENABLE_ZIP_INSTALL') && WPMCP_ENABLE_ZIP_INSTALL;

        return (bool) apply_filters('wpmcp_enable_zip_install', $default);
    }

    /**
     * Package installs/updates/deletes require WordPress to be able to write
     * to the filesystem directly (no FTP/SSH credential prompt). If the
     * detected method is anything other than 'direct', these tools refuse
     * rather than attempting a credential-based filesystem connection.
     */
    public static function filesystem_ready(): bool
    {
        if (! function_exists('get_filesystem_method')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        return 'direct' === get_filesystem_method();
    }
}
