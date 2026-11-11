<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Cornerstone (Themeco)'s site-wide scopes on get-builder-content (issue
 * #646), read only, and the templates a page write adds from. Recorded from
 * the Cornerstone 7.8.12 source:
 *
 * - design_system: the global colors (option `cornerstone_color_items`,
 *   stored as JSON by Controllers\Save) and global fonts
 *   (`cornerstone_font_items`, `cornerstone_font_config`).
 * - templates: posts of the `cs_template` type in Cornerstone's internal
 *   `tco-data` status (or publish), each with `_cs_template_identifier`
 *   ("<type>|<subtype>") and `_cs_template_data` (JSON). Sections saved
 *   from the builder are element|__multi__ and page templates are
 *   document|content:<post type>; both keep the elements to insert in
 *   `elements`, which is what the builder's insert uses. Presets
 *   (element|<element type>) style an element rather than add one, and
 *   layout templates (document|layout:*) fill headers, footers and layouts,
 *   so neither can be added to a page.
 * - catalog: the element library, which only a loaded Cornerstone knows;
 *   with `element`, that element's values with their defaults.
 */
final class Cornerstone_Design
{
    public const TEMPLATE_POST_TYPE = 'cs_template';

    /** @return array<string,mixed>|\WP_Error */
    public static function read(string $scope, string $element)
    {
        if ('templates' === $scope) {
            return self::templates();
        }

        return 'design_system' === $scope ? self::design_system() : self::catalog($element);
    }

    /** @return array<string,mixed> */
    public static function design_system(): array
    {
        return [
            'builder'        => 'cornerstone',
            'scope'          => 'design_system',
            'plugin_active'  => Cornerstone_Content::plugin_active(),
            'plugin_version' => Cornerstone_Content::plugin_version(),
            'colors'         => array_values(Cornerstone_Tree::decode_object(get_option('cornerstone_color_items', ''))),
            'fonts'          => array_values(Cornerstone_Tree::decode_object(get_option('cornerstone_font_items', ''))),
            'font_config'    => Cornerstone_Tree::decode_object(get_option('cornerstone_font_config', '')),
        ];
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function catalog(string $element)
    {
        $engine = Cornerstone_Content::plugin_active() ? Cornerstone_Engine::get() : null;
        if (null === $engine) {
            return Builder_Design_Data::not_loaded('cornerstone');
        }

        $base = [
            'builder'        => 'cornerstone',
            'scope'          => 'catalog',
            'plugin_active'  => true,
            'plugin_version' => Cornerstone_Content::plugin_version(),
        ];
        if ('' === $element) {
            return $base + ['elements' => $engine->catalog()];
        }

        $schema = $engine->schema($element);
        if (null === $schema) {
            return Builder_Design_Data::element_not_found('cornerstone', $element);
        }

        return $base + ['element' => $schema];
    }

    /** @return array<string,mixed> */
    public static function templates(): array
    {
        $templates = [];
        foreach (self::template_ids() as $id) {
            $identifier = (string) get_post_meta($id, '_cs_template_identifier', true);
            $elements   = self::stored_elements($id);
            $templates[] = [
                'id'         => $id,
                'title'      => (string) get_the_title($id),
                'identifier' => $identifier,
                'insertable' => self::insertable($identifier) && null !== $elements,
                'tree'       => self::insertable($identifier) ? Cornerstone_Tree::tree($elements ?? []) : [],
            ];
        }

        return [
            'builder'        => 'cornerstone',
            'scope'          => 'templates',
            'plugin_active'  => Cornerstone_Content::plugin_active(),
            'plugin_version' => Cornerstone_Content::plugin_version(),
            'templates'      => $templates,
        ];
    }

    /**
     * The elements a template adds to a page.
     *
     * @return array<int,array<string,mixed>>
     * @throws \InvalidArgumentException when $id is not a template that can be added
     */
    public static function template_elements(int $id): array
    {
        if (! in_array($id, self::template_ids(), true)) {
            throw new \InvalidArgumentException(esc_html("No Cornerstone template {$id}; read get-builder-content scope templates for the ids."));
        }
        $identifier = (string) get_post_meta($id, '_cs_template_identifier', true);
        $elements   = self::stored_elements($id);
        if (! self::insertable($identifier) || null === $elements || [] === $elements) {
            throw new \InvalidArgumentException(esc_html("Cornerstone template {$id} ({$identifier}) holds no elements a page can take; use a section or page template."));
        }

        return $elements;
    }

    /**
     * Template post ids, oldest first. Queried directly: `tco-data` is a
     * status only a loaded Cornerstone registers, and WP_Query drops
     * statuses it does not know.
     *
     * @return int[]
     */
    private static function template_ids(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- see above: the template status is unregistered without Cornerstone, so WP_Query cannot list these posts.
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('tco-data', 'publish') ORDER BY ID ASC", self::TEMPLATE_POST_TYPE));

        return array_map('intval', $ids);
    }

    private static function insertable(string $identifier): bool
    {
        return 'element|__multi__' === $identifier || 0 === strpos($identifier, 'document|content:');
    }

    /** @return array<int,array<string,mixed>>|null */
    private static function stored_elements(int $id): ?array
    {
        $data = Cornerstone_Tree::decode_object(get_post_meta($id, '_cs_template_data', true));

        return Cornerstone_Tree::decode($data['elements'] ?? null);
    }
}
