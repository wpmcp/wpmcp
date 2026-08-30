<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create a library template from a portable export structure (the envelope
 * export-page produces: a `content` element tree, optionally `page_settings`,
 * `type`, `title`). Lets an agent round-trip a design into a reusable
 * `elementor_library` template, including across sites. Creating a template
 * destroys nothing, so this is not routed through Safe_Mutation.
 */
class Import_Template
{
    public function handle(array $args)
    {
        $export = is_array($args['export'] ?? null) ? $args['export'] : [];

        $content = $export['content'] ?? ($export['elements'] ?? null);
        if (! is_array($content) || [] === $content) {
            return new \WP_Error('missing_content', 'The export must include a non-empty "content" element tree.');
        }

        $title = (string) ($args['title'] ?? ($export['title'] ?? ''));
        if ('' === trim($title)) {
            $title = __('Imported template', 'wpmcp');
        }

        $type = (string) ($args['template_type'] ?? ($export['type'] ?? 'page'));
        // export-page stamps type 'page' on the envelope; honor an explicit
        // template_type arg over the envelope's page-level type.
        if (isset($args['template_type'])) {
            $type = (string) $args['template_type'];
        }

        // Regenerate every element id on import so a template exported from
        // another site (or the same one) never carries colliding ids.
        $taken   = [];
        $content = Elementor_Template_Data::regenerate_ids($content, $taken);

        $template_id = Elementor_Template_Data::create($title, $type, $content);
        if (is_wp_error($template_id)) {
            return $template_id;
        }

        return [
            'template_id'   => $template_id,
            'title'         => sanitize_text_field($title),
            'template_type' => Elementor_Template_Data::normalize_type($type),
        ];
    }
}
