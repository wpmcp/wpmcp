<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Export an elementor_library template to a portable structure (content +
 * page_settings + type + version), the same envelope export-page produces and
 * import-template accepts, so a saved template can round-trip as JSON between
 * sites. Read-only.
 */
class Export_Template
{
    public function handle(array $args)
    {
        $template_id = (int) ($args['template_id'] ?? 0);
        if ($template_id <= 0) {
            return new \WP_Error('missing_template_id', 'A template_id is required.');
        }
        if (! Elementor_Template_Data::is_template($template_id)) {
            return new \WP_Error('not_a_template', "Post {$template_id} is not an elementor_library template.");
        }

        $type = (string) get_post_meta($template_id, '_elementor_template_type', true);
        if ('' === $type) {
            $type = 'page';
        }

        return [
            'title'         => get_the_title($template_id),
            'type'          => Elementor_Template_Data::normalize_type($type),
            'version'       => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '',
            'content'       => Elementor_Template_Data::data($template_id),
            'page_settings' => Element_Tree::page_settings($template_id),
            'conditions'    => Elementor_Template_Data::conditions($template_id),
        ];
    }
}
