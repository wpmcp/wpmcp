<?php

namespace WPMCP\Tools\Elementor;

use WPMCP\Tools\Builders\Elementor_Cache;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Regenerate Elementor's generated CSS: for one document (its render cache is
 * dropped and its post CSS rebuilt now), or site-wide behind confirm:true
 * (Elementor's own purge; every document rebuilds on its next view).
 *
 * NOT routed through Safe_Mutation, for the reason documented on clear-cache:
 * generated CSS and the render cache are derived from stored data and have
 * no meaningful before-image to restore. Content is never written here.
 */
class Regenerate_Elementor_Css
{
    /**
     * @return array|\WP_Error
     */
    public function handle(array $args)
    {
        if (! Elementor_Cache::available()) {
            return new \WP_Error('elementor_inactive', 'Elementor is not active on this site.');
        }

        $post_id = (int) ($args['post_id'] ?? 0);
        if ($post_id > 0) {
            return $this->single($post_id);
        }

        if (true !== ($args['confirm'] ?? false)) {
            return new \WP_Error(
                'confirm_required',
                'Pass post_id for one page, or confirm:true to purge generated CSS and render caches site-wide.'
            );
        }

        if (! current_user_can('manage_options')) {
            return new \WP_Error('forbidden', 'Site-wide regeneration requires manage_options.');
        }

        Elementor_Cache::clear_all();

        return [
            'scope'   => 'site',
            'cleared' => true,
        ];
    }

    /**
     * @return array|\WP_Error
     */
    private function single(int $post_id)
    {
        if (! get_post($post_id) || ! metadata_exists('post', $post_id, '_elementor_data')) {
            return new \WP_Error('not_elementor', "Post {$post_id} has no Elementor data.");
        }

        if (! current_user_can('edit_post', $post_id)) {
            return new \WP_Error('forbidden', "You cannot edit post {$post_id}.");
        }

        $result = Elementor_Cache::regenerate_document($post_id);

        return [
            'scope'      => 'post',
            'post_id'    => $post_id,
            'css_status' => $result['status'],
        ];
    }
}
