<?php

namespace WPMCP\Tools\Packages;

use WPMCP\Safety\Mutation_Failed;
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
 *
 * The write runs under Plugin_State's lock, so parallel activations are
 * serialised, and it is verified against the stored list: an activation that
 * did not land is rolled back and reported, and its undo point is voided, so
 * the ledger never lists an activation that did not happen (issue #728).
 * Activation_Guard keeps a plugin that redirects while it activates from
 * ending the request, and its errors reach the client as plain text (issue
 * #746). A plugin that is already active is reported as such, with no
 * operation and no ledger row (issue #747).
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

        $error      = '';
        $redirected = false;
        try {
            $out = Plugin_State::locked(static function () use ($plugin, $network_wide, $args, &$error, &$redirected) {
                // Read under the lock: an active plugin changes nothing, so
                // it gets no undo point (issue #747).
                if (Plugin_State::already_active($plugin, $network_wide)) {
                    return null;
                }
                return Safe_Mutation::run(
                    [
                        'object_type'         => 'option',
                        'object_id'           => 'active_plugins',
                        'object_ref'          => $plugin,
                        'state_probe'         => [ Plugin_State::class, 'probe' ],
                        'session_id'          => (string) ($args['session_id'] ?? 'default'),
                        'tool_name'           => 'activate-plugin',
                        'args'                => $args,
                        'extra_snapshot_data' => [ 'restore_capability' => 'activate_plugins' ] + Plugin_Network_Scope::snapshot_data($network_wide),
                    ],
                    function () use ($plugin, $network_wide, &$error, &$redirected) {
                        $result = Activation_Guard::activate($plugin, $network_wide, $redirected);
                        if (is_wp_error($result)) {
                            $error = Activation_Guard::plain_message($result->get_error_message());
                        } elseif ($redirected) {
                            $error = Activation_Guard::redirect_message();
                        }
                        return $result;
                    },
                    function ($result) use ($plugin, $network_wide) {
                        return ! is_wp_error($result) && Plugin_State::is_active($plugin, $network_wide);
                    }
                );
            });
        } catch (Mutation_Failed $e) {
            throw new \RuntimeException(sprintf(
                'Plugin "%s" was not activated, and nothing was recorded: %s',
                esc_html($plugin),
                esc_html('' !== $error ? $error : 'the plugin is not in the active list after activation.')
            ));
        }

        if (null === $out) {
            return ['plugin' => $plugin, 'activated' => false, 'already_active' => true, 'network_wide' => $network_wide];
        }

        return ['operation_id' => $out['operation_id'], 'plugin' => $plugin, 'activated' => true, 'network_wide' => $network_wide];
    }
}
