<?php

namespace WPMCP\Tools\Elementor;

use WPMCP\Tools\Builders\Elementor_Cache;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create an Elementor popup (an `elementor_library` post of type popup),
 * optionally seeded with elements and trigger/display settings. Creating a
 * popup destroys nothing, so it is not snapshot-wrapped; remove it with
 * delete-post. Configure it further with set-popup-settings.
 */
class Create_Popup
{
    public function handle(array $args)
    {
        $title = (string) ($args['title'] ?? '');
        if ('' === trim($title)) {
            return new \WP_Error('missing_title', 'A title is required.');
        }

        $elements = is_array($args['elements'] ?? null) ? $args['elements'] : [];

        $popup_id = Elementor_Template_Data::create($title, 'popup', $elements);
        if (is_wp_error($popup_id)) {
            return $popup_id;
        }

        $settings = is_array($args['settings'] ?? null) ? $args['settings'] : [];
        if ([] !== $settings) {
            update_post_meta($popup_id, '_elementor_page_settings', wp_slash($settings));
            Elementor_Cache::invalidate_document($popup_id);
        }

        return [
            'popup_id' => $popup_id,
            'title'    => sanitize_text_field($title),
            'settings' => $settings,
        ];
    }
}
