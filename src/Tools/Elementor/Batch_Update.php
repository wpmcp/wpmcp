<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Apply N element settings updates atomically under ONE snapshot
 * (issue #58). Every target is validated before anything is written: one
 * unknown element id refuses the whole batch. The single Element_Tree
 * write means one snapshot, one operation_id, and full rollback of the
 * entire batch on any failure - there is no partial application.
 */
class Batch_Update
{
    public function handle(array $args)
    {
        $updates = is_array($args['updates'] ?? null) ? $args['updates'] : [];
        if ([] === $updates) {
            return new \WP_Error('missing_updates', 'A non-empty "updates" array of {element_id, settings} entries is required.');
        }

        $read = Element_Tree::read_for_edit($args);
        if (is_wp_error($read)) {
            return $read;
        }
        [$post_id, $elements] = $read;

        // Validate the entire batch before touching the tree: atomicity
        // starts with refusing to start.
        foreach ($updates as $index => $update) {
            if (! is_array($update) || '' === (string) ($update['element_id'] ?? '') || ! is_array($update['settings'] ?? null)) {
                return new \WP_Error(
                    'invalid_updates',
                    "Update #{$index} is malformed: every entry needs an element_id string and a settings object."
                );
            }
            $element_id = (string) $update['element_id'];
            if (null === Elementor_Page_Data::find($elements, $element_id)) {
                return new \WP_Error(
                    'element_not_found',
                    "No element found with id '{$element_id}' (update #{$index}); the whole batch was refused and nothing was written."
                );
            }
        }

        foreach ($updates as $update) {
            Elementor_Page_Data::update_settings($elements, (string) $update['element_id'], $update['settings']);
        }

        $out = Element_Tree::write($post_id, $elements, 'batch-update', $args);
        if (is_wp_error($out)) {
            return $out;
        }

        return $out + ['updated_count' => count($updates)];
    }
}
