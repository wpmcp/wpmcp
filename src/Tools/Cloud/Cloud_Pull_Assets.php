<?php

namespace WPMCP\Tools\Cloud;

use WPMCP\Cloud\Cloud_Client;
use WPMCP\Tools\BlockBuilder\Block_Spec;
use WPMCP\Tools\BlockBuilder\Block_Spec_Store;
use WPMCP\Tools\WidgetBuilder\Widget_Spec;
use WPMCP\Tools\WidgetBuilder\Widget_Spec_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Pull the builder assets from this site's WP MCP Cloud account and recreate
 * them locally as custom widget / block specs. Each pulled spec is validated
 * before it is stored, so a malformed cloud asset is skipped rather than
 * creating a broken widget, and every skipped asset is reported by name with
 * the reason it was refused.
 */
class Cloud_Pull_Assets
{
    public function handle(array $args)
    {
        $result = (new Cloud_Client())->get('/assets');
        if (is_wp_error($result)) {
            return $result;
        }

        $assets  = is_array($result['assets'] ?? null) ? $result['assets'] : [];
        $pulled  = 0;
        $skipped = [];

        foreach ($assets as $asset) {
            if (! is_array($asset) || ! is_array($asset['spec'] ?? null)) {
                continue;
            }
            $type = (string) ($asset['type'] ?? '');
            $spec = $asset['spec'];

            if ('widget' === $type) {
                $valid = Widget_Spec::validate($spec);
                $store = static fn (array $s) => Widget_Spec_Store::create($s);
            } elseif ('block' === $type) {
                $valid = Block_Spec::validate($spec);
                $store = static fn (array $s) => Block_Spec_Store::create($s);
            } else {
                continue;
            }

            $created = true === $valid ? $store($spec) : $valid;
            if (is_wp_error($created) || true !== $valid) {
                // Report, never silently drop: a spec pushed under older,
                // looser rules is refused here, and the caller needs to know
                // which one and why rather than just seeing a lower count.
                $skipped[] = [
                    'name'   => is_scalar($asset['name'] ?? null) ? (string) $asset['name'] : '',
                    'type'   => $type,
                    'reason' => is_wp_error($created) ? $created->get_error_message() : 'The spec was refused.',
                ];
                continue;
            }
            $pulled++;
        }

        return ['pulled' => $pulled, 'skipped' => $skipped];
    }
}
