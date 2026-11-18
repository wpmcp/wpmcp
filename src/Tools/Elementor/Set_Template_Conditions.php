<?php

namespace WPMCP\Tools\Elementor;

use WPMCP\Safety\Mutation_Failed;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Set an Elementor theme template's display conditions (where it renders):
 * arrays of parts (["include","singular","post"]) or slash strings
 * ("include/general"). The conditions meta is snapshot-first through
 * Safe_Mutation, so a change is undoable via rollback-operation.
 */
class Set_Template_Conditions
{
    public function handle(array $args)
    {
        $post_id = (int) ($args['post_id'] ?? 0);
        if ($post_id <= 0) {
            return new \WP_Error('missing_post_id', 'A post_id is required.');
        }
        if (! Elementor_Template_Data::is_template($post_id)) {
            return new \WP_Error('not_a_template', "Post {$post_id} is not an elementor_library template.");
        }

        $conditions = is_array($args['conditions'] ?? null) ? $args['conditions'] : [];
        if ([] === $conditions) {
            return new \WP_Error('missing_conditions', 'A non-empty conditions array is required.');
        }
        $normalized = Elementor_Template_Data::normalize_conditions($conditions);

        $operation_id = wp_generate_uuid4();
        try {
            Safe_Mutation::run(
                [
                    'operation_id' => $operation_id,
                    'object_type'  => 'post',
                    'object_id'    => $post_id,
                    'session_id'   => (string) ($args['session_id'] ?? 'default'),
                    'tool_name'    => 'set-template-conditions',
                    'args'         => $args,
                ],
                function () use ($post_id, $normalized) {
                    Template_Conditions::save($post_id, $normalized);
                    return true;
                },
                function () use ($post_id, $normalized) {
                    clean_post_cache($post_id);
                    return Elementor_Template_Data::conditions($post_id) === $normalized;
                }
            );
        } catch (Mutation_Failed $e) {
            return new \WP_Error('mutation_failed', 'The conditions were not stored; the template was rolled back.');
        } catch (\Throwable $e) {
            Rollback_Service::restore_operation($operation_id);
            return new \WP_Error('mutation_failed', 'The write failed mid-save and was rolled back: ' . $e->getMessage());
        }

        $saved = Elementor_Template_Data::conditions($post_id);
        $out   = [
            'operation_id' => $operation_id,
            'post_id'      => $post_id,
            'conditions'   => $saved,
        ];

        // Issue #870: only a site-wide header or footer is flagged here.
        if (Theme_Header_Builder_Clash::is_site_wide($saved)) {
            $type     = (string) get_post_meta($post_id, '_elementor_template_type', true);
            $warnings = Theme_Header_Builder_Clash::warnings($type, true);
            if ([] !== $warnings) {
                $out['warnings'] = $warnings;
            }
        }

        return $out;
    }
}
