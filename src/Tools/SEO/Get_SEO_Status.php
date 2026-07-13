<?php

namespace WPMCP\Tools\SEO;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: report which SEO plugin (if any) is active on this site, by
 * name and version, via SEO_Adapter::plugin_info(). Reads have nothing to
 * roll back, so this never touches Safe_Mutation.
 */
class Get_SEO_Status
{
    public function handle(array $args): array
    {
        $info = SEO_Adapter::plugin_info();

        if (null === $info) {
            return ['active' => false, 'plugin' => '', 'name' => '', 'version' => ''];
        }

        return [
            'active'  => true,
            'plugin'  => $info['plugin'],
            'name'    => $info['name'],
            'version' => $info['version'],
        ];
    }
}
