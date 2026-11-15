<?php

namespace WPMCP\Tools\Maintenance;

use WPMCP\Maintenance\Site_Gate;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: report whether maintenance mode is on and, when it is, the
 * configured message and retry-after seconds.
 *
 * `enabled`, `message` and `retry_after` are WP MCP's own option, as before.
 * `site_gated` and `active_gates` add the rest of the picture (issue #903):
 * WordPress core's .maintenance flag and the coming soon and maintenance
 * plugins can close the site while WP MCP's own flag reads off. get-site-gate-status
 * on the theme pair adds the live probe and who bypasses each gate.
 *
 * Reads have nothing to roll back, so this never touches Safe_Mutation.
 */
class Get_Maintenance_Status
{
    public function handle(array $args): array
    {
        $option = get_option('wpmcp_maintenance');
        if (! is_array($option)) {
            $option = [];
        }

        $gate = Site_Gate::status(false);

        return [
            'enabled'      => ! empty($option['enabled']),
            'message'      => (string) ($option['message'] ?? ''),
            'retry_after'  => (int) ($option['retry_after'] ?? 0),
            'site_gated'   => $gate['public_site_gated'],
            'active_gates' => $gate['active_gates'],
        ];
    }
}
