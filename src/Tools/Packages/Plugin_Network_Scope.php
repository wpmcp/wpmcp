<?php

namespace WPMCP\Tools\Packages;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Where a plugin activation or deactivation lands on a multisite network
 * (issue #557).
 *
 * Left to itself, core's activate_plugin() network-activates a plugin whose
 * header says "Network: true", and deactivate_plugins() network-deactivates
 * a plugin that is active for the network, whoever asks. Core's own screens
 * never let that happen implicitly: the site Plugins screen refuses both
 * actions, and only the network screen, behind manage_network_plugins,
 * changes active_sitewide_plugins. The tools follow the same rules. A write
 * stays on the current site unless the caller passes network_wide: true,
 * which takes manage_network_plugins (and a network), and every refusal
 * happens before anything is snapshotted or written.
 *
 * A network write also snapshots active_sitewide_plugins next to the site's
 * active_plugins (snapshot_data()), so rollback-operation puts back both.
 */
final class Plugin_Network_Scope
{
    /** The snapshot data key Rollback_Service restores active_sitewide_plugins from. */
    public const SNAPSHOT_KEY = 'network_plugins';

    /**
     * Whether this activation is network-wide; throws for one core's
     * plugins.php would refuse the caller.
     */
    public static function for_activation(string $plugin, array $args): bool
    {
        if (self::requested($args)) {
            return true;
        }
        if (is_multisite() && is_network_only_plugin($plugin)) {
            throw new \RuntimeException(sprintf(
                'Plugin "%s" is network-only: it can only be activated for the whole network, by a network admin passing network_wide: true.',
                esc_html($plugin)
            ));
        }
        return false;
    }

    /**
     * Whether this deactivation is network-wide; throws for one core's
     * plugins.php would refuse the caller.
     */
    public static function for_deactivation(string $plugin, array $args): bool
    {
        if (self::requested($args)) {
            return true;
        }
        if (is_multisite() && is_plugin_active_for_network($plugin)) {
            throw new \RuntimeException(sprintf(
                'Plugin "%s" is active for the whole network, so it cannot be deactivated on one site. A network admin can deactivate it network-wide with network_wide: true.',
                esc_html($plugin)
            ));
        }
        return false;
    }

    /**
     * Extra snapshot data for a write: the network's active plugin list
     * when the write is network-wide, nothing otherwise.
     *
     * @return array<string, mixed>
     */
    public static function snapshot_data(bool $network_wide): array
    {
        if (! $network_wide) {
            return [];
        }
        $missing = '__wpmcp_missing__';
        $value   = get_site_option('active_sitewide_plugins', $missing);
        return [
            self::SNAPSHOT_KEY => [
                'existed' => $missing !== $value,
                'value'   => $missing !== $value ? $value : [],
            ],
        ];
    }

    /**
     * True when the caller asked for a network write and may make one;
     * throws when they asked and may not. Public so install-plugin can
     * refuse before its download runs.
     */
    public static function requested(array $args): bool
    {
        if (empty($args['network_wide'])) {
            return false;
        }
        if (! is_multisite()) {
            throw new \RuntimeException('network_wide: true needs a multisite network; this site is not one.');
        }
        if (! current_user_can('manage_network_plugins')) {
            throw new \RuntimeException('network_wide: true changes plugins for every site on the network and needs the manage_network_plugins capability.');
        }
        return true;
    }
}
