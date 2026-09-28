<?php

namespace WPMCP\Tools\Elementor;

use WPMCP\Safety\Mutation_Failed;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Tools\Builders\Elementor_Cache;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Set an Elementor popup's trigger/display settings, merged into the popup's
 * `_elementor_page_settings` (open/close triggers, timing, and advanced rules).
 * Snapshot-first, so a change is undoable via rollback-operation.
 */
class Set_Popup_Settings
{
    public function handle(array $args)
    {
        $post_id = (int) ($args['post_id'] ?? 0);
        if ($post_id <= 0) {
            return new \WP_Error('missing_post_id', 'A post_id is required.');
        }
        if (
            ! Elementor_Template_Data::is_template($post_id)
            || 'popup' !== get_post_meta($post_id, '_elementor_template_type', true)
        ) {
            return new \WP_Error('not_a_popup', "Post {$post_id} is not an Elementor popup template.");
        }

        $settings = is_array($args['settings'] ?? null) ? $args['settings'] : [];
        if ([] === $settings) {
            return new \WP_Error('missing_settings', 'A non-empty settings object is required.');
        }

        $current = get_post_meta($post_id, '_elementor_page_settings', true);
        $merged  = array_merge(is_array($current) ? $current : [], $settings);

        $operation_id = wp_generate_uuid4();
        try {
            Safe_Mutation::run(
                [
                    'operation_id' => $operation_id,
                    'object_type'  => 'post',
                    'object_id'    => $post_id,
                    'session_id'   => (string) ($args['session_id'] ?? 'default'),
                    'tool_name'    => 'set-popup-settings',
                    'args'         => $args,
                ],
                function () use ($post_id, $merged) {
                    update_post_meta($post_id, '_elementor_page_settings', $merged);
                    Elementor_Cache::invalidate_document($post_id);
                    return true;
                },
                function () use ($post_id, $settings) {
                    clean_post_cache($post_id);
                    $saved = get_post_meta($post_id, '_elementor_page_settings', true);
                    if (! is_array($saved)) {
                        return false;
                    }
                    foreach ($settings as $key => $value) {
                        if (! array_key_exists($key, $saved) || $saved[$key] != $value) { // phpcs:ignore
                            return false;
                        }
                    }
                    return true;
                }
            );
        } catch (Mutation_Failed $e) {
            return new \WP_Error('mutation_failed', 'The popup settings were not stored; the popup was rolled back.');
        } catch (\Throwable $e) {
            Rollback_Service::restore_operation($operation_id);
            return new \WP_Error('mutation_failed', 'The write failed mid-save and was rolled back: ' . $e->getMessage());
        }

        return [
            'operation_id' => $operation_id,
            'popup_id'     => $post_id,
            'settings'     => get_post_meta($post_id, '_elementor_page_settings', true),
        ];
    }
}
