<?php

namespace WPMCP\Tools\Builders;

use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Brizy's site-wide scopes on get-builder-content (issue #645), read only.
 * Recorded from Brizy 2.8.26:
 *
 * - design_system: the project's styles. Brizy keeps one `brizy-project`
 *   post whose `brizy-project` postmeta row holds `data`, base64 of a JSON
 *   object (selectedStyle, styles with their colorPalette and fontStyles,
 *   extraFontStyles, font). Only that `data` is read: the same row also
 *   holds the license key and cloud tokens, which are never returned.
 * - templates: the saved blocks (`brizy-saved-block`), layouts
 *   (`brizy-layout`) and global blocks (`brizy-global-block`) the caller may
 *   read; any of them can be added to a page with element {template: id}.
 * - catalog: the element types of Brizy's free editor, recorded from its
 *   build (Brizy keeps no element catalog in PHP), with where each goes.
 */
final class Brizy_Design
{
    public const PROJECT_TYPE = 'brizy-project';

    /** Element types of the free editor and where they sit. */
    private const CATALOG = [
        'Section'        => 'block: the top of a page; holds SectionItem',
        'SectionHeader'  => 'block: a header; holds SectionHeaderItem',
        'SectionFooter'  => 'block: a footer; holds items like a SectionItem',
        'GlobalBlock'    => 'block: a reference to a global block (value._id is its uid)',
        'SectionItem'    => 'inside a Section; holds Row, Wrapper and Cloneable',
        'Row'            => 'holds Column',
        'Column'         => 'inside a Row; holds Wrapper, Cloneable and Row',
        'Wrapper'        => 'holds one content element (RichText, Image, Spacer, Line, Video, Map, Embed and the like)',
        'Cloneable'      => 'holds repeatable elements (Button, Icon)',
        'RichText'       => 'text; value.text is HTML',
        'Image'          => 'an image; value.imageSrc',
        'Button'         => 'a button; value.text, linkExternal',
        'Icon'           => 'an icon',
        'IconText'       => 'an icon with text',
        'Spacer'         => 'vertical space',
        'Line'           => 'a divider',
        'Video'          => 'a video',
        'Map'            => 'a map',
        'Embed'          => 'embedded code',
        'Counter'        => 'an animated number',
        'Countdown2'     => 'a countdown',
        'ProgressBar'    => 'a progress bar',
        'Tabs'           => 'tabs; holds Tab',
        'Accordion'      => 'an accordion; holds AccordionItem',
        'ImageGallery'   => 'an image gallery',
        'Carousel'       => 'a carousel',
        'Form2'          => 'a form; holds FormFields',
        'Menu'           => 'a navigation menu',
        'WPCustomShortcode' => 'a WordPress shortcode',
    ];

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
        $out = [
            'builder'        => 'brizy',
            'scope'          => 'design_system',
            'plugin_active'  => Brizy_Content::plugin_active(),
            'plugin_version' => Brizy_Content::plugin_version(),
        ];

        $project = get_posts([
            'post_type'        => self::PROJECT_TYPE,
            'post_status'      => 'publish',
            'posts_per_page'   => 1,
            'orderby'          => 'ID',
            'order'            => 'DESC',
            'fields'           => 'ids',
        ]);
        $row  = [] === $project ? null : get_post_meta((int) $project[0], self::PROJECT_TYPE, true);
        $raw  = is_array($row) && is_string($row['data'] ?? null) ? $row['data'] : '';
        $json = '' === $raw ? false : base64_decode($raw, true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Brizy's own storage format.
        $data = false === $json ? null : json_decode($json, true);
        if (! is_array($data)) {
            return $out + ['project' => false, 'styles' => []];
        }

        $styles = [];
        foreach ((array) ($data['styles'] ?? []) as $style) {
            if (! is_array($style)) {
                continue;
            }
            $styles[] = [
                'id'           => (string) ($style['id'] ?? ''),
                'title'        => (string) ($style['title'] ?? ''),
                'colorPalette' => is_array($style['colorPalette'] ?? null) ? $style['colorPalette'] : [],
                'fontStyles'   => is_array($style['fontStyles'] ?? null) ? $style['fontStyles'] : [],
            ];
        }

        return $out + [
            'project'         => true,
            'selected_style'  => (string) ($data['selectedStyle'] ?? ''),
            'styles'          => $styles,
            'extraFontStyles' => is_array($data['extraFontStyles'] ?? null) ? $data['extraFontStyles'] : [],
            'font'            => (string) ($data['font'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    public static function templates(): array
    {
        $templates = [];
        $posts     = Readable_Posts::get_posts([
            'post_type'      => Brizy_Content::TEMPLATE_TYPES,
            'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => 200,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);
        $kinds = [Brizy_Content::SAVED_BLOCK_TYPE => 'saved_block', Brizy_Content::LAYOUT_TYPE => 'layout', Brizy_Content::GLOBAL_BLOCK_TYPE => 'global_block'];
        foreach ($posts as $post) {
            if (! $post instanceof \WP_Post) {
                continue;
            }
            $data        = Brizy_Content::decode((int) $post->ID);
            $blocks      = null === $data ? 0 : (isset($data['type']) ? 1 : count((array) ($data['items'] ?? [])));
            $templates[] = [
                'id'     => (int) $post->ID,
                'title'  => (string) $post->post_title,
                'status' => (string) $post->post_status,
                'kind'   => $kinds[ $post->post_type ] ?? $post->post_type,
                'blocks' => $blocks,
            ];
        }

        return ['builder' => 'brizy', 'scope' => 'templates', 'templates' => $templates];
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function catalog(string $element)
    {
        $out = ['builder' => 'brizy', 'scope' => 'catalog', 'source' => 'Brizy 2.8.26 editor build'];
        if ('' !== $element) {
            if (! isset(self::CATALOG[ $element ])) {
                return Builder_Design_Data::element_not_found('brizy', $element);
            }
            return $out + ['element' => $element, 'placement' => self::CATALOG[ $element ], 'block' => in_array($element, Brizy_Tree::BLOCK_TYPES, true)];
        }

        $elements = [];
        foreach (self::CATALOG as $type => $placement) {
            $elements[] = ['type' => $type, 'placement' => $placement];
        }

        return $out + ['elements' => $elements];
    }
}
