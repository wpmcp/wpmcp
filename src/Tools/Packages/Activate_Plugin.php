<?php

namespace WPMCP\Tools\Packages;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Activate an installed plugin.
 *
 * Like Deactivate_Plugin, this only ever changes the 'active_plugins'
 * option, so it is routed through Safe_Mutation with object_type 'option':
 * the prior list is snapshotted and rollback-operation can restore it,
 * undoing the activation with a single call.
 *
 * On a multisite network the activation stays on the current site unless a
 * network admin passes network_wide: true; see Plugin_Network_Scope, which
 * also adds active_sitewide_plugins to the snapshot of a network write.
 */
class Activate_Plugin
{
    public function handle(array $args): array
    {
        $plugin = isset($args['plugin']) ? (string) $args['plugin'] : '';
        if ('' === $plugin) {
            throw new \InvalidArgumentException('A plugin file is required.');
        }

        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        if (! isset($all_plugins[ $plugin ])) {
            throw new \RuntimeException(sprintf('Plugin "%s" was not found.', esc_html($plugin)));
        }

        $network_wide = Plugin_Network_Scope::for_activation($plugin, $args);

        $out = Safe_Mutation::run(
            [
                'object_type'         => 'option',
                'object_id'           => 'active_plugins',
                'session_id'          => (string) ($args['session_id'] ?? 'default'),
                'tool_name'           => 'activate-plugin',
                'args'                => $args,
                'extra_snapshot_data' => [ 'restore_capability' => 'activate_plugins' ] + Plugin_Network_Scope::snapshot_data($network_wide),
            ],
            function () use ($plugin, $network_wide) {
                return activate_plugin($plugin, '', $network_wide);
            },
            function ($result) {
                return ! is_wp_error($result);
            }
        );

        return ['operation_id' => $out['operation_id'], 'plugin' => $plugin, 'activated' => true, 'network_wide' => $network_wide];
    }
}
