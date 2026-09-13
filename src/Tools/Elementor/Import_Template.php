<?php

namespace WPMCP\Tools\Elementor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create a library template from a portable export structure (the envelope
 * export-page and export-template produce: a `content` element tree, optionally
 * `page_settings`, `conditions`, `type`, `title`). Lets an agent round-trip a
 * design into a reusable `elementor_library` template, including across sites.
 * Creating a template destroys nothing, so this is not routed through
 * Safe_Mutation.
 *
 * The envelope arrives as untrusted JSON, so the element tree is validated
 * before anything walks it: a malformed tree fails as a WP_Error, never a
 * fatal.
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
        if (! Elementor_Template_Data::is_element_list($content)) {
            return new \WP_Error(
                'invalid_content',
                'The export "content" must be a list of element objects; every entry (and every nested "elements" list) has to be an object.'
            );
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

        // The envelope's page settings carry popup triggers/timing and page
        // level styling; without them a round-tripped popup loses its rules.
        $page_settings = [];
        if (! empty($export['page_settings']) && is_array($export['page_settings'])) {
            $page_settings = $export['page_settings'];
            update_post_meta($template_id, '_elementor_page_settings', $page_settings);
        }

        // Display conditions travel with theme-builder parts. They are written
        // through the same helper set-template-conditions uses, so Elementor
        // Pro's location cache is rebuilt when Pro is present.
        $conditions = [];
        if (! empty($export['conditions']) && is_array($export['conditions'])) {
            $conditions = Elementor_Template_Data::normalize_conditions($export['conditions']);
            if ([] !== $conditions) {
                Template_Conditions::save($template_id, $conditions);
            }
        }

        return [
            'template_id'   => $template_id,
            'title'         => sanitize_text_field($title),
            'template_type' => Elementor_Template_Data::normalize_type($type),
            'conditions'    => $conditions,
            'page_settings' => [] !== $page_settings,
        ];
    }
}
