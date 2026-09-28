<?php

namespace WPMCP\Tools\ThemeBuilder\Dynamic;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The bindable sources a dynamic template can print (issue #290), per
 * template context. Read by the list-dynamic-sources op, by the write-time
 * token check in Binding_Resolver::validate(), and by the resolver itself, so
 * what an agent is told it may bind, what a write accepts and what renders
 * are one list.
 *
 * A source is {key, token, label, type, group}. The type decides escaping at
 * render time (see Binding_Resolver::escape()):
 *  - text:    esc_html (esc_attr inside a tag)
 *  - url:     esc_url
 *  - html:    wp_kses_post (tags stripped inside a tag)
 *  - content: the post body through the_content, as the theme would print it
 *  - image:   wp_get_attachment_image (its URL inside a tag)
 *
 * ACF fields are listed only while ACF is active (filterable through
 * wpmcp_dynamic_sources_acf_active), read from the field groups whose
 * location rules match the post type asked about. Field types with no single
 * printable value (repeaters, groups, relationships, galleries) are reported
 * under `skipped` rather than silently left out.
 */
class Dynamic_Sources
{
    /** Template contexts a dynamic template can target. */
    public const CONTEXTS = ['single', 'archive', 'search'];

    /** Contexts whose template may repeat a block over the main query. */
    public const LOOP_CONTEXTS = ['archive', 'search'];

    /** ACF field type => binding type. Anything else is skipped. */
    private const ACF_TYPES = [
        'text'             => 'text',
        'textarea'         => 'text',
        'number'           => 'text',
        'range'            => 'text',
        'email'            => 'text',
        'select'           => 'text',
        'radio'            => 'text',
        'checkbox'         => 'text',
        'button_group'     => 'text',
        'true_false'       => 'text',
        'date_picker'      => 'text',
        'date_time_picker' => 'text',
        'time_picker'      => 'text',
        'color_picker'     => 'text',
        'url'              => 'url',
        'link'             => 'url',
        'page_link'        => 'url',
        'file'             => 'url',
        'wysiwyg'          => 'html',
        'image'            => 'image',
    ];

    public static function acf_active(): bool
    {
        $default = function_exists('acf_get_field_groups') && function_exists('acf_get_fields');

        return (bool) apply_filters('wpmcp_dynamic_sources_acf_active', $default);
    }

    /**
     * The documented placeholder syntax, returned with every discovery so an
     * agent never has to guess it.
     *
     * @return array<string,string>
     */
    public static function syntax(): array
    {
        return [
            'token'  => '{{group.field}}, e.g. {{post.title}}; whitespace inside the braces is ignored',
            'loop'   => '{{#loop}}...{{/loop}} repeats its markup for each post of the main query (archive and search only; not nested)',
            'escape' => 'Values are escaped by type at render time; inside a tag (an attribute value) every type becomes an attribute-safe string',
        ];
    }

    /**
     * @return array<string,mixed>|\WP_Error {context, post_type, syntax, loop, acf_active, sources, skipped}
     */
    public static function discover(string $context = 'single', string $post_type = '')
    {
        if (! in_array($context, self::CONTEXTS, true)) {
            return self::unknown_context($context);
        }

        $skipped = [];
        $sources = self::catalog($context, $post_type, $skipped);

        return [
            'context'    => $context,
            'post_type'  => $post_type,
            'syntax'     => self::syntax(),
            'loop'       => in_array($context, self::LOOP_CONTEXTS, true),
            'acf_active' => self::acf_active(),
            'sources'    => array_values($sources),
            'skipped'    => $skipped,
        ];
    }

    public static function unknown_context(string $context): \WP_Error
    {
        return new \WP_Error(
            'wpmcp_invalid_template_context',
            sprintf('Unknown template context "%s". Valid contexts: %s.', $context, implode(', ', self::CONTEXTS))
        );
    }

    /**
     * Every source for a context, keyed by source key. With no post type,
     * the term sources cover every public taxonomy and the ACF sources every
     * field group, which is what a write-time check needs when the template's
     * conditions do not pin one post type.
     *
     * @param array<int,array<string,string>> $skipped filled with ACF fields that cannot be bound
     *
     * @return array<string,array<string,string>>
     */
    public static function catalog(string $context, string $post_type = '', array &$skipped = []): array
    {
        $out = [];
        $add = static function (string $key, string $label, string $type, array $extra = []) use (&$out): void {
            $out[$key] = [
                'key'   => $key,
                'token' => '{{' . $key . '}}',
                'label' => $label,
                'type'  => $type,
                'group' => strstr($key, '.', true),
            ] + $extra;
        };

        $add('post.title', 'Post title', 'text');
        $add('post.content', 'Post content', 'content');
        $add('post.excerpt', 'Post excerpt', 'text');
        $add('post.featured_image', 'Featured image', 'image');
        $add('post.featured_image_url', 'Featured image URL', 'url');
        $add('post.author', 'Author display name', 'text');
        $add('post.author_url', 'Author archive URL', 'url');
        $add('post.date', 'Publish date', 'text');
        $add('post.permalink', 'Permalink', 'url');
        foreach (self::taxonomies($post_type) as $taxonomy => $label) {
            $add('post.terms.' . $taxonomy, $label . ' (comma separated)', 'text');
        }

        $add('site.name', 'Site title', 'text');
        $add('site.tagline', 'Tagline', 'text');
        $add('site.url', 'Home URL', 'url');
        $add('site.logo', 'Custom logo', 'image');

        if ('archive' === $context) {
            $add('archive.title', 'Archive title', 'text');
            $add('archive.description', 'Archive description', 'html');
        }
        if ('search' === $context) {
            $add('search.query', 'Search query', 'text');
        }
        if (in_array($context, self::LOOP_CONTEXTS, true)) {
            $add('loop.count', 'Total posts found', 'text');
            $add('loop.pagination', 'Pagination links', 'html');
        }

        if (self::acf_active()) {
            foreach (self::acf_fields($post_type) as $field) {
                $type = self::ACF_TYPES[$field['type']] ?? null;
                if (null === $type) {
                    $skipped[] = ['name' => $field['name'], 'field_type' => $field['type'], 'field_group' => $field['group']];
                    continue;
                }
                $add('acf.' . $field['name'], $field['label'], $type, [
                    'field_type'  => $field['type'],
                    'field_group' => $field['group'],
                ]);
            }
        }

        return $out;
    }

    /**
     * The ACF binding type for a field name, or null when no field group
     * declares a bindable field by that name.
     */
    public static function acf_type(string $name, string $post_type = ''): ?string
    {
        foreach (self::acf_fields($post_type) as $field) {
            if ($name === $field['name']) {
                return self::ACF_TYPES[$field['type']] ?? null;
            }
        }
        return null;
    }

    /** @return array<string,string> taxonomy slug => label */
    private static function taxonomies(string $post_type): array
    {
        $objects = '' !== $post_type
            ? get_object_taxonomies($post_type, 'objects')
            : get_taxonomies(['public' => true], 'objects');

        $out = [];
        foreach ($objects as $taxonomy) {
            if ($taxonomy->public && preg_match('/^[A-Za-z0-9_-]+$/', $taxonomy->name)) {
                $out[$taxonomy->name] = (string) $taxonomy->label;
            }
        }
        return $out;
    }

    /**
     * Top-level fields of the ACF field groups that apply to a post type (or
     * of every group when none is given).
     *
     * @return array<int,array{name:string,label:string,type:string,group:string}>
     */
    private static function acf_fields(string $post_type): array
    {
        if (! self::acf_active()) {
            return [];
        }
        $groups = '' !== $post_type ? acf_get_field_groups(['post_type' => $post_type]) : acf_get_field_groups();

        $out = [];
        foreach ((array) $groups as $group) {
            foreach ((array) acf_get_fields($group) as $field) {
                $name = (string) ($field['name'] ?? '');
                if ('' === $name || ! preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
                    continue;
                }
                $out[] = [
                    'name'  => $name,
                    'label' => (string) ($field['label'] ?? $name),
                    'type'  => (string) ($field['type'] ?? ''),
                    'group' => (string) ($group['title'] ?? ''),
                ];
            }
        }
        return $out;
    }
}
