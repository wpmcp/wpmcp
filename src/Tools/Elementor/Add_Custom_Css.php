<?php

namespace WPMCP\Tools\Elementor;

use WPMCP\Safety\Mutation_Failed;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Add site-wide custom CSS through WordPress core Additional CSS
 * (wp_update_custom_css_post), so it works on ANY site, Elementor Pro or not.
 * Appends to the existing CSS by default, or replaces it with replace=true;
 * css "" with replace=true clears it (discussion #749), keeping the
 * Additional-CSS post, emptied, so the clear is snapshotted and undoable
 * like any other write.
 * When the Additional-CSS post already exists the write is snapshot-first, so
 * the change is undoable via rollback-operation.
 *
 * Requires edit_css on top of the ability's manage_options (issue #558), the
 * bar core's Customizer applies to Additional CSS and the one add-scoped-css
 * enforces: core maps edit_css onto unfiltered_html, so multisite site admins
 * and DISALLOW_UNFILTERED_HTML sites are refused, as they are in core.
 */
class Add_Custom_Css
{
    public function handle(array $args)
    {
        if (! current_user_can('edit_css')) {
            return new \WP_Error(
                'edit_css_required',
                'Storing Additional CSS requires the edit_css capability (unfiltered_html), the same bar WordPress core applies in the Customizer.'
            );
        }

        $css     = (string) ($args['css'] ?? '');
        $replace = ! empty($args['replace']);
        // An empty css clears the Additional CSS, but only together with
        // replace=true, so a call that lost its css never wipes the site's
        // styles (discussion #749).
        $clear = $replace && array_key_exists('css', $args) && '' === trim($css);
        if ('' === trim($css) && ! $clear) {
            return new \WP_Error('missing_css', 'A non-empty css string is required. To clear the Additional CSS, pass css "" with replace=true.');
        }

        $current = (string) wp_get_custom_css();
        $next    = $replace ? ($clear ? '' : $css) : trim($current . "\n" . $css);

        $existing = wp_get_custom_css_post();

        // Nothing stored yet: there is nothing to clear and nothing to undo.
        if ($clear && ! $existing) {
            return ['css' => '', 'cleared' => true];
        }

        // No existing Additional-CSS post: creating one destroys nothing, so
        // write directly (mirrors create-post not being snapshot-wrapped).
        if (! $existing) {
            $result = wp_update_custom_css_post($next);
            if (is_wp_error($result)) {
                return $result;
            }
            return ['css' => wp_get_custom_css(), 'created' => true];
        }

        $post_id      = (int) $existing->ID;
        $operation_id = wp_generate_uuid4();

        try {
            Safe_Mutation::run(
                [
                    'operation_id' => $operation_id,
                    'object_type'  => 'post',
                    'object_id'    => $post_id,
                    'session_id'   => (string) ($args['session_id'] ?? 'default'),
                    'tool_name'    => 'add-custom-css',
                    'args'         => $args,
                ],
                function () use ($next) {
                    wp_update_custom_css_post($next);
                    return true;
                },
                function () use ($next) {
                    return trim((string) wp_get_custom_css()) === trim($next);
                }
            );
        } catch (Mutation_Failed $e) {
            return new \WP_Error('mutation_failed', 'The custom CSS was not stored; it was rolled back.');
        } catch (\Throwable $e) {
            Rollback_Service::restore_operation($operation_id);
            return new \WP_Error('mutation_failed', 'The write failed mid-save and was rolled back: ' . $e->getMessage());
        }

        $out = [
            'operation_id' => $operation_id,
            'css'          => wp_get_custom_css(),
        ];
        if ($clear) {
            $out['cleared'] = true;
        }
        return $out;
    }
}
