<?php

namespace WPMCP\Tools\Builders;

use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Visual Composer's site-wide scopes on get-builder-content (issue #642),
 * read only. Recorded from Visual Composer 45.16:
 *
 * - pages: the posts holding a Visual Composer document (`vcv-pageContent`).
 * - templates: the saved templates, `vcv_templates` posts with their type in
 *   `_vcv-type`.
 * - catalog: the installed elements, one folder each (the plugin's own
 *   elements/ folder and the downloaded ones under
 *   uploads/visualcomposer-assets/elements), named by its manifest.json;
 *   with `element`, that element's attributes, read from the settings the
 *   element's bundle carries (public/dist/element.bundle.js).
 */
final class Visual_Composer_Design
{
    public const SCOPES = ['pages', 'templates', 'catalog'];

    /** @return array<string,mixed>|\WP_Error */
    public static function read(string $scope, string $element)
    {
        if ('pages' === $scope) {
            return self::pages();
        }
        if ('templates' === $scope) {
            return self::templates();
        }
        if ('catalog' === $scope) {
            return self::catalog($element);
        }

        return new \WP_Error('unsupported_builder', "Visual Composer's scope reads are 'pages', 'templates' and 'catalog'; its design system is not read yet.");
    }

    /** @return array<string,mixed> */
    public static function pages(): array
    {
        $pages = [];
        $posts = Readable_Posts::get_posts([
            'post_type'      => 'any',
            'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => 200,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_key'       => Visual_Composer_Content::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the one marker of a Visual Composer page.
        ]);
        foreach ($posts as $post) {
            if (! $post instanceof \WP_Post || Visual_Composer_Content::TEMPLATE_POST_TYPE === $post->post_type || ! Visual_Composer_Content::is_page((int) $post->ID)) {
                continue;
            }
            $pages[] = [
                'id'       => (int) $post->ID,
                'title'    => (string) $post->post_title,
                'type'     => (string) $post->post_type,
                'status'   => (string) $post->post_status,
                'elements' => count(Visual_Composer_Content::elements(Visual_Composer_Content::document((int) $post->ID))),
            ];
        }

        return ['builder' => 'visual-composer', 'scope' => 'pages', 'pages' => $pages];
    }

    /** @return array<string,mixed> */
    public static function templates(): array
    {
        $templates = [];
        $posts     = Readable_Posts::get_posts([
            'post_type'      => Visual_Composer_Content::TEMPLATE_POST_TYPE,
            'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => 200,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);
        foreach ($posts as $post) {
            if (! $post instanceof \WP_Post) {
                continue;
            }
            $templates[] = [
                'id'       => (int) $post->ID,
                'title'    => (string) $post->post_title,
                'status'   => (string) $post->post_status,
                'type'     => (string) get_post_meta((int) $post->ID, '_vcv-type', true),
                'elements' => count(Visual_Composer_Content::elements(Visual_Composer_Content::document((int) $post->ID))),
            ];
        }

        return ['builder' => 'visual-composer', 'scope' => 'templates', 'templates' => $templates];
    }

    /**
     * The folders installed elements live in, each holding one folder per
     * element tag.
     *
     * @return array<int,string>
     */
    public static function element_dirs(): array
    {
        $dirs = [];
        if (defined('VCV_PLUGIN_DIR_PATH')) {
            $dirs[] = rtrim((string) constant('VCV_PLUGIN_DIR_PATH'), '/') . '/elements';
        }
        if (defined('VCV_PLUGIN_ASSETS_DIR_PATH')) {
            $dirs[] = rtrim((string) constant('VCV_PLUGIN_ASSETS_DIR_PATH'), '/') . '/elements';
        }
        $dirs = apply_filters('wpmcp_visual_composer_element_dirs', $dirs);

        return array_values(array_filter(array_map('strval', is_array($dirs) ? $dirs : []), 'is_dir'));
    }

    /**
     * Installed elements by tag: {dir, name, description, category}.
     *
     * @return array<string,array<string,string>>
     */
    public static function elements(): array
    {
        $out = [];
        foreach (self::element_dirs() as $dir) {
            foreach ((array) glob($dir . '/*/manifest.json') as $file) {
                $manifest = json_decode((string) file_get_contents((string) $file), true); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local plugin file.
                if (! is_array($manifest) || ! is_array($manifest['elements'] ?? null)) {
                    continue;
                }
                $category = '';
                foreach ((array) ($manifest['categories'] ?? []) as $name => $group) {
                    $category = (string) $name;
                    break;
                }
                foreach ($manifest['elements'] as $tag => $data) {
                    $tag = (string) $tag;
                    if (isset($out[ $tag ]) || 1 !== preg_match('/^[A-Za-z0-9_]+$/', $tag)) {
                        continue;
                    }
                    $settings    = is_array($data['settings'] ?? null) ? $data['settings'] : [];
                    $out[ $tag ] = [
                        'dir'         => dirname((string) $file),
                        'name'        => (string) ($settings['name'] ?? $tag),
                        'description' => (string) ($settings['metaDescription'] ?? ''),
                        'category'    => $category,
                    ];
                }
            }
        }
        ksort($out);

        return $out;
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function catalog(string $element)
    {
        if (! Visual_Composer_Content::plugin_active()) {
            return Builder_Design_Data::not_loaded('visual-composer');
        }
        $all = self::elements();
        $out = ['builder' => 'visual-composer', 'scope' => 'catalog', 'plugin_version' => Visual_Composer_Content::plugin_version()];

        if ('' !== $element) {
            if (! isset($all[ $element ])) {
                return Builder_Design_Data::element_not_found('visual-composer', $element);
            }
            $settings = self::settings($all[ $element ]['dir'] . '/public/dist/element.bundle.js', $element);
            if (null === $settings) {
                return new \WP_Error('element_schema_unavailable', "The attribute list of '{$element}' could not be read from its bundle.");
            }
            $attrs = [];
            foreach ($settings as $key => $field) {
                if (! is_array($field) || 'public' !== ($field['access'] ?? '') || 'group' === ($field['type'] ?? '')) {
                    continue;
                }
                $attr = ['name' => (string) $key, 'type' => (string) ($field['type'] ?? '')];
                if (isset($field['options']['label']) && is_string($field['options']['label'])) {
                    $attr['label'] = $field['options']['label'];
                }
                if (array_key_exists('value', $field)) {
                    $attr['default'] = $field['value'];
                }
                if (isset($field['options']['values']) && is_array($field['options']['values'])) {
                    $attr['values'] = array_values(array_filter(array_map(static fn ($v) => is_array($v) && isset($v['value']) ? $v['value'] : null, $field['options']['values']), static fn ($v) => null !== $v));
                }
                $attrs[] = $attr;
            }

            return $out + ['element' => ['tag' => $element, 'name' => $all[ $element ]['name'], 'attributes' => $attrs]];
        }

        $list = [];
        foreach ($all as $tag => $data) {
            $list[] = ['tag' => $tag, 'name' => $data['name'], 'category' => $data['category'], 'description' => $data['description']];
        }

        return $out + ['elements' => $list];
    }

    /**
     * The settings an element bundle registers for $tag: the JSON.parse()
     * argument whose tag attribute is $tag, in the development build (code
     * inside eval() strings) or the minified one.
     *
     * @return array<string,mixed>|null
     */
    public static function settings(string $bundle, string $tag): ?array
    {
        if (! is_file($bundle)) {
            return null;
        }
        $source = (string) file_get_contents($bundle); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local plugin file.
        $codes  = [$source];
        foreach (self::literals($source, 'eval("', '"') as $literal) {
            $codes[] = self::js_string($literal, false);
        }
        foreach ($codes as $code) {
            foreach (self::literals($code, "JSON.parse('", "'") as $literal) {
                $settings = json_decode(self::js_string($literal, true), true);
                if (is_array($settings) && ($settings['tag']['value'] ?? null) === $tag) {
                    return $settings;
                }
            }
        }

        return null;
    }

    /**
     * The bodies of the string literals that follow each $opener (which
     * ends with the opening $quote), scanned by hand: a regular expression
     * over a bundle this size runs out of backtracking stack.
     *
     * @return array<int,string>
     */
    public static function literals(string $code, string $opener, string $quote): array
    {
        $out    = [];
        $offset = 0;
        $length = strlen($code);
        while (false !== ($start = strpos($code, $opener, $offset))) {
            $start += strlen($opener);
            $i      = $start;
            while ($i < $length && $quote !== $code[ $i ]) {
                $i += '\\' === $code[ $i ] ? 2 : 1;
            }
            if ($i >= $length) {
                break;
            }
            $out[]  = substr($code, $start, $i - $start);
            $offset = $i + 1;
        }

        return $out;
    }

    /**
     * The value of a JavaScript string literal's body. With $json the value
     * is JSON text, so an escaped character that would end or break a JSON
     * string stays a JSON escape.
     */
    public static function js_string(string $literal, bool $json): string
    {
        return (string) preg_replace_callback(
            '/\\\\(u[0-9a-fA-F]{4}|x[0-9a-fA-F]{2}|.)/s',
            static function (array $m) use ($json): string {
                $c = $m[1];
                if (('u' === $c[0] && 5 === strlen($c)) || ('x' === $c[0] && 3 === strlen($c))) {
                    $code = (int) hexdec(substr($c, 1));
                    if ($json && ($code < 0x20 || 0x22 === $code || 0x5c === $code)) {
                        return sprintf('\\u%04x', $code);
                    }
                    return (string) mb_convert_encoding(pack('N', $code), 'UTF-8', 'UTF-32BE');
                }
                $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f", '0' => "\0"];

                return $map[ $c ] ?? $c;
            },
            $literal
        );
    }
}
