<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create an Elementor theme-builder template: a header, footer, single,
 * archive, loop-item, search-results, or error-404 `elementor_library` post,
 * optionally seeded with an element tree and display conditions. Creating a
 * template destroys nothing, so it is not snapshot-wrapped; remove it with
 * delete-theme-template.
 */
class Create_Theme_Template
{
    public function handle(array $args)
    {
        $type = (string) ($args['template_type'] ?? '');
        if (! Elementor_Template_Data::is_theme_type($type)) {
            return new \WP_Error(
                'invalid_theme_type',
                sprintf(
                    '"%s" is not a theme-builder location. Use one of: %s.',
                    $type,
                    implode(', ', Elementor_Template_Data::THEME_TYPES)
                )
            );
        }

        $title = (string) ($args['title'] ?? '');
        if ('' === trim($title)) {
            return new \WP_Error('missing_title', 'A title is required.');
        }

        $elements    = is_array($args['elements'] ?? null) ? $args['elements'] : [];
        $template_id = Elementor_Template_Data::create($title, $type, $elements);
        if (is_wp_error($template_id)) {
            return $template_id;
        }

        $conditions = [];
        if (is_array($args['conditions'] ?? null) && [] !== $args['conditions']) {
            $conditions = Elementor_Template_Data::normalize_conditions($args['conditions']);
            Template_Conditions::save($template_id, $conditions);
        }

        $out = [
            'template_id'   => $template_id,
            'title'         => sanitize_text_field($title),
            'template_type' => sanitize_key($type),
            'conditions'    => $conditions,
        ];

        $warnings = Theme_Header_Builder_Clash::warnings($type, Theme_Header_Builder_Clash::is_site_wide($conditions));
        if ([] !== $warnings) {
            $out['warnings'] = $warnings;
        }

        return $out;
    }
}
