<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create an Elementor Custom Code snippet (an `elementor_snippet` post with the
 * meta Elementor Pro reads: _elementor_code, _elementor_location,
 * _elementor_priority). The snippet is stored on any site; it renders where
 * Elementor Pro's Custom Code module is active. Creating destroys nothing, so
 * it is not snapshot-wrapped; remove it with delete-code-snippet.
 */
class Create_Code_Snippet
{
    private const VALID_LOCATIONS = ['wp_head', 'wp_body_open', 'wp_footer'];

    public function handle(array $args)
    {
        $code = (string) ($args['code'] ?? '');
        if ('' === trim($code)) {
            return new \WP_Error('missing_code', 'A non-empty code string is required.');
        }

        $title    = sanitize_text_field((string) ($args['title'] ?? 'Code snippet'));
        $location = sanitize_key((string) ($args['location'] ?? 'wp_head'));
        if (! in_array($location, self::VALID_LOCATIONS, true)) {
            $location = 'wp_head';
        }
        $priority = max(1, min(10, (int) ($args['priority'] ?? 10)));
        $status   = 'draft' === ($args['status'] ?? 'publish') ? 'draft' : 'publish';

        $snippet_id = wp_insert_post(wp_slash([
            'post_title'  => '' !== $title ? $title : 'Code snippet',
            'post_type'   => 'elementor_snippet',
            'post_status' => $status,
        ]), true);

        if (is_wp_error($snippet_id)) {
            return $snippet_id;
        }
        $snippet_id = (int) $snippet_id;

        // update_post_meta() unslashes; a regex or string escape in the code must survive.
        update_post_meta($snippet_id, '_elementor_code', wp_slash($code));
        update_post_meta($snippet_id, '_elementor_location', $location);
        update_post_meta($snippet_id, '_elementor_priority', $priority);
        update_post_meta($snippet_id, '_elementor_template_type', 'code_snippet');
        update_post_meta($snippet_id, '_elementor_edit_mode', 'builder');

        return [
            'snippet_id' => $snippet_id,
            'title'      => $title,
            'location'   => $location,
            'priority'   => $priority,
            'status'     => $status,
        ];
    }
}
