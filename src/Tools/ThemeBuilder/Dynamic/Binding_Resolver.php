<?php

namespace WPMCP\Tools\ThemeBuilder\Dynamic;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Binding tokens for dynamic templates (issue #290).
 *
 * Syntax, documented to agents by Dynamic_Sources::syntax():
 *  - `{{group.field}}` prints one source, e.g. {{post.title}},
 *    {{post.terms.category}}, {{site.logo}}, {{acf.subtitle}}.
 *  - `{{#loop}}...{{/loop}}` repeats its markup once per post of the main
 *    query, with post.* and acf.* bound to that post (archive and search
 *    templates only, not nested).
 *
 * Resolution runs once over the rendered template (after do_blocks(), from
 * the wpmcp_site_part_rendered filter), and in a single pass: a value is
 * never scanned again, so a post title that reads "{{site.name}}" prints as
 * that text instead of being resolved. Every value is escaped for its type
 * (escape()) and for its position: wp_html_split() tells a token inside a
 * tag (an attribute value) from one in text, and inside a tag every type
 * collapses to an attribute-safe string.
 */
class Binding_Resolver
{
    public const TOKEN = '/\{\{\s*([a-z]+(?:\.[A-Za-z0-9_-]+){1,2})\s*\}\}/';

    private const LOOP_TAG = '/\{\{\s*(#|\/)loop\s*\}\}/';

    /** Splits a template into [outside, loop body, outside, ...]. */
    private const LOOP_SPLIT = '/\{\{\s*#loop\s*\}\}(.*?)\{\{\s*\/loop\s*\}\}/s';

    /** Binding type of every key that is not an ACF field or a term list. */
    private const TYPES = [
        'post.title'              => 'text',
        'post.content'            => 'content',
        'post.excerpt'            => 'text',
        'post.featured_image'     => 'image',
        'post.featured_image_url' => 'url',
        'post.author'             => 'text',
        'post.author_url'         => 'url',
        'post.date'               => 'text',
        'post.permalink'          => 'url',
        'site.name'               => 'text',
        'site.tagline'            => 'text',
        'site.url'                => 'url',
        'site.logo'               => 'image',
        'archive.title'           => 'text',
        'archive.description'     => 'html',
        'search.query'            => 'text',
        'loop.count'              => 'text',
        'loop.pagination'         => 'html',
    ];

    /** Image size every image binding prints. */
    private const IMAGE_SIZE = 'large';

    /** @var array<int,bool> posts whose content is being rendered, so a post cannot embed itself. */
    private static array $rendering_content = [];

    /**
     * Check a template's tokens before it is stored: every token must be a
     * source of this context, loops must be balanced, flat, and allowed here,
     * and no `{{` may be left that is not a token (a typo would otherwise
     * print literally on every page).
     *
     * @return true|\WP_Error
     */
    public static function validate(string $content, string $context)
    {
        if (! in_array($context, Dynamic_Sources::CONTEXTS, true)) {
            return Dynamic_Sources::unknown_context($context);
        }

        preg_match_all(self::LOOP_TAG, $content, $loops);
        if ([] !== $loops[1]) {
            if (! in_array($context, Dynamic_Sources::LOOP_CONTEXTS, true)) {
                return new \WP_Error(
                    'wpmcp_invalid_loop',
                    sprintf('A %s template has no loop; {{#loop}} is for archive and search templates.', $context)
                );
            }
            $open = false;
            foreach ($loops[1] as $mark) {
                if (('#' === $mark) === $open) {
                    return new \WP_Error('wpmcp_invalid_loop', 'Every {{#loop}} needs its own {{/loop}}, and loops do not nest.');
                }
                $open = ! $open;
            }
            if ($open) {
                return new \WP_Error('wpmcp_invalid_loop', 'Every {{#loop}} needs its own {{/loop}}, and loops do not nest.');
            }
        }

        $catalog = Dynamic_Sources::catalog($context);
        preg_match_all(self::TOKEN, $content, $tokens);
        $unknown = array_values(array_unique(array_diff($tokens[1], array_keys($catalog))));
        if ([] !== $unknown) {
            return new \WP_Error(
                'wpmcp_unknown_binding',
                sprintf(
                    'Unknown binding(s) for a %s template: %s. The list-dynamic-sources op lists the valid ones.',
                    $context,
                    implode(', ', array_map(static fn (string $key): string => '{{' . $key . '}}', $unknown))
                ),
                ['unknown' => $unknown]
            );
        }

        $rest = preg_replace([self::TOKEN, self::LOOP_TAG], '', $content);
        if (false !== strpos((string) $rest, '{{')) {
            return new \WP_Error(
                'wpmcp_malformed_binding',
                'The content has a "{{" that is not a binding; bindings look like {{post.title}}.'
            );
        }

        return true;
    }

    /**
     * Resolve every token in rendered markup. $post binds post.* and acf.*
     * outside a loop; each loop body is repeated once per $loop_posts entry
     * with those bound to that post.
     *
     * @param \WP_Post[] $loop_posts
     */
    public static function resolve(string $html, ?\WP_Post $post, array $loop_posts = []): string
    {
        if (false === strpos($html, '{{')) {
            return $html;
        }

        $parts = preg_split(self::LOOP_SPLIT, $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (false === $parts) {
            return '';
        }

        $out = '';
        foreach ($parts as $index => $part) {
            if (0 === $index % 2) {
                $out .= self::resolve_tokens($part, $post);
                continue;
            }
            foreach ($loop_posts as $loop_post) {
                if ($loop_post instanceof \WP_Post) {
                    $out .= self::resolve_tokens($part, $loop_post);
                }
            }
        }
        return $out;
    }

    /**
     * The wpmcp_site_part_rendered filter: resolve a rendered template
     * against the live main query.
     *
     * @param string              $html     the template after do_blocks()
     * @param array<string,mixed> $template a Template_Store::get() row
     */
    public static function filter_rendered($html, $template): string
    {
        $html = (string) $html;
        if (false === strpos($html, '{{')) {
            return $html;
        }

        $post   = null;
        $object = is_singular() ? get_queried_object() : null;
        if ($object instanceof \WP_Post) {
            $post = $object;
        }

        $loop       = [];
        $part_type  = is_array($template) ? (string) ($template['part_type'] ?? '') : '';
        $main_query = $GLOBALS['wp_query'] ?? null;
        if (in_array($part_type, Dynamic_Sources::LOOP_CONTEXTS, true) && $main_query instanceof \WP_Query) {
            foreach ((array) $main_query->posts as $item) {
                $item = get_post($item);
                if ($item instanceof \WP_Post) {
                    $loop[] = $item;
                }
            }
        }

        return self::resolve($html, $post, $loop);
    }

    /**
     * Escape one value for its binding type and position.
     *
     * @param mixed $value a string, or an attachment id for an image
     */
    public static function escape(string $type, $value, bool $in_tag): string
    {
        if ('image' === $type) {
            $id = is_numeric($value) ? (int) $value : 0;
            if ($id < 1) {
                return '';
            }
            return $in_tag
                ? esc_url((string) wp_get_attachment_image_url($id, self::IMAGE_SIZE))
                : (string) wp_get_attachment_image($id, self::IMAGE_SIZE);
        }

        $value = is_scalar($value) ? (string) $value : '';
        if ('url' === $type) {
            return esc_url($value);
        }
        if ($in_tag) {
            return esc_attr(wp_strip_all_tags($value));
        }
        if ('html' === $type) {
            return wp_kses_post($value);
        }
        if ('content' === $type) {
            // The post body through the_content, exactly as the theme's own
            // template prints it.
            return $value;
        }
        return esc_html($value);
    }

    private static function resolve_tokens(string $html, ?\WP_Post $post): string
    {
        if (false === strpos($html, '{{')) {
            return $html;
        }

        $out = '';
        foreach (wp_html_split($html) as $chunk) {
            $in_tag = '' !== $chunk && '<' === $chunk[0];
            $out   .= (string) preg_replace_callback(
                self::TOKEN,
                static function (array $match) use ($post, $in_tag): string {
                    [$type, $value] = self::value($match[1], $post);
                    return self::escape($type, $value, $in_tag);
                },
                $chunk
            );
        }
        return $out;
    }

    /**
     * The binding type and raw value of one key.
     *
     * @return array{0:string,1:mixed}
     */
    private static function value(string $key, ?\WP_Post $post): array
    {
        $segments = explode('.', $key);
        $group    = $segments[0];

        if ('acf' === $group) {
            return self::acf_value((string) ($segments[1] ?? ''), $post);
        }
        if ('post' === $group && 'terms' === ($segments[1] ?? '') && isset($segments[2])) {
            return ['text', null === $post ? '' : self::term_names($post, $segments[2])];
        }

        $type = self::TYPES[$key] ?? 'text';
        if ('post' === $group) {
            return [$type, null === $post ? '' : self::post_value($key, $post)];
        }
        return [$type, self::context_value($key)];
    }

    /** @return mixed */
    private static function post_value(string $key, \WP_Post $post)
    {
        switch ($key) {
            case 'post.title':
                return get_the_title($post);
            case 'post.content':
                return self::content($post);
            case 'post.excerpt':
                return get_the_excerpt($post);
            case 'post.featured_image':
                return (int) get_post_thumbnail_id($post);
            case 'post.featured_image_url':
                return (string) get_the_post_thumbnail_url($post, self::IMAGE_SIZE);
            case 'post.author':
                return (string) get_the_author_meta('display_name', (int) $post->post_author);
            case 'post.author_url':
                return get_author_posts_url((int) $post->post_author);
            case 'post.date':
                return (string) get_the_date('', $post);
            case 'post.permalink':
                return (string) get_permalink($post);
            default:
                return '';
        }
    }

    /** @return mixed */
    private static function context_value(string $key)
    {
        global $wp_query;

        switch ($key) {
            case 'site.name':
                return get_bloginfo('name');
            case 'site.tagline':
                return get_bloginfo('description');
            case 'site.url':
                return home_url('/');
            case 'site.logo':
                return (int) get_theme_mod('custom_logo');
            case 'archive.title':
                return wp_strip_all_tags((string) get_the_archive_title());
            case 'archive.description':
                return (string) get_the_archive_description();
            case 'search.query':
                return get_search_query(false);
            case 'loop.count':
                return $wp_query instanceof \WP_Query ? (string) (int) $wp_query->found_posts : '0';
            case 'loop.pagination':
                return (string) paginate_links(['type' => 'plain']);
            default:
                return '';
        }
    }

    /**
     * The post body through the_content with the post set up as the global,
     * as a theme's own template renders it, then the globals put back.
     */
    private static function content(\WP_Post $post): string
    {
        if (isset(self::$rendering_content[$post->ID])) {
            return '';
        }
        self::$rendering_content[$post->ID] = true;

        $previous = $GLOBALS['post'] ?? null;
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Set for the_content, restored below.
        $GLOBALS['post'] = $post;
        setup_postdata($post);
        try {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter, applied exactly as a theme template prints a post body.
            $html = (string) apply_filters('the_content', get_the_content(null, false, $post));
        } finally {
            // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the value saved above.
            $GLOBALS['post'] = $previous;
            if ($previous instanceof \WP_Post) {
                setup_postdata($previous);
            }
            unset(self::$rendering_content[$post->ID]);
        }

        return str_replace(']]>', ']]&gt;', $html);
    }

    private static function term_names(\WP_Post $post, string $taxonomy): string
    {
        $terms = get_the_terms($post, $taxonomy);
        if (! is_array($terms)) {
            return '';
        }
        return implode(', ', wp_list_pluck($terms, 'name'));
    }

    /**
     * An ACF field's binding type and value. The type comes from the field
     * definition, never from the stored value, so a text field holding
     * markup is still printed as text.
     *
     * @return array{0:string,1:mixed}
     */
    private static function acf_value(string $name, ?\WP_Post $post): array
    {
        if (null === $post || '' === $name || ! Dynamic_Sources::acf_active() || ! function_exists('get_field')) {
            return ['text', ''];
        }
        $type = Dynamic_Sources::acf_type($name, (string) $post->post_type)
            ?? Dynamic_Sources::acf_type($name);
        if (null === $type) {
            return ['text', ''];
        }

        if ('image' === $type) {
            $raw = get_field($name, $post->ID, false);
            if (is_array($raw)) {
                $raw = $raw['ID'] ?? ($raw['id'] ?? 0);
            }
            return ['image', is_numeric($raw) ? (int) $raw : 0];
        }

        $value = get_field($name, $post->ID);
        if ('url' === $type) {
            if (is_array($value)) {
                $value = $value['url'] ?? (isset($value[0]) && is_string($value[0]) ? $value[0] : '');
            } elseif (is_numeric($value)) {
                $value = (string) wp_get_attachment_url((int) $value);
            }
            return ['url', is_string($value) ? $value : ''];
        }
        if ('html' === $type) {
            return ['html', is_string($value) ? $value : ''];
        }

        return ['text', self::stringify($value)];
    }

    /** @param mixed $value */
    private static function stringify($value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            if (isset($value['label']) && is_scalar($value['label'])) {
                return (string) $value['label'];
            }
            $parts = [];
            foreach ($value as $item) {
                $item = self::stringify($item);
                if ('' !== $item) {
                    $parts[] = $item;
                }
            }
            return implode(', ', $parts);
        }
        return '';
    }
}
