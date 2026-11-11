<?php

namespace WPMCP\Tools\Builders;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read/write access to a page's BeBuilder layout (issue #643). The storage
 * and what the theme derives from it are described in BeTheme_Cache; the
 * element operations are BeTheme_Tree's.
 *
 * A write stores the layout the way BeTheme's own save does: in the format
 * the `builder-storage` theme option asks for (base64 of the serialized
 * array for 'encode', the array otherwise), the visual builder's preview
 * copy (`mfn-builder-preview`) kept in step when the page has one, the
 * stale `mfn-page-object` dropped (as the classic builder's save does),
 * then the SEO copy and local styles rebuilt through the theme. All of it is
 * postmeta, which the post snapshot captures; the CSS file is rebuilt by the
 * rollback path too.
 */
final class BeTheme_Content
{
    public const PREVIEW_META_KEY = 'mfn-builder-preview';

    public const TEMPLATE_POST_TYPE = 'template';

    /** The loaded BeTheme's version, or null when it is not the active theme. */
    public static function plugin_version(): ?string
    {
        if (! BeTheme_Cache::plugin_active() || ! defined('MFN_THEME_VERSION')) {
            return null;
        }
        $version = (string) constant('MFN_THEME_VERSION');

        return '' === $version ? null : $version;
    }

    /** The refusal for a write that needs BeTheme active. */
    public static function unavailable(): \WP_Error
    {
        $url = admin_url('themes.php');

        return new \WP_Error(
            'plugin_missing',
            "BeTheme is not the active theme on this site, so BeBuilder pages cannot be changed. Install and activate BeTheme ({$url}), then try again.",
            ['status' => 403, 'plugin' => 'BeTheme and BeBuilder', 'url' => $url]
        );
    }

    /** sha256 of the stored layout row, the hash writes are pinned to. */
    public static function hash(int $post_id): string
    {
        $raw = get_post_meta($post_id, BeTheme_Cache::META_KEY, true);

        return hash('sha256', is_array($raw) ? serialize($raw) : (string) $raw); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- a fingerprint of the stored row, never unserialized.
    }

    /** Whether the page holds a layout (a write to it then needs the hash). */
    public static function has_layout(int $post_id): bool
    {
        return 'none' !== BeTheme_Cache::storage($post_id);
    }

    /** @return array<int|string,mixed> the stored sections, [] when none */
    public static function sections(int $post_id): array
    {
        return BeTheme_Cache::decode($post_id) ?? [];
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function read(int $post_id)
    {
        if (! current_user_can('read_post', $post_id)) {
            return new \WP_Error('forbidden', 'You are not allowed to read this post.', ['status' => 403]);
        }

        $storage  = BeTheme_Cache::storage($post_id);
        $sections = BeTheme_Cache::decode($post_id);
        $out      = [
            'post_id'        => $post_id,
            'builder'        => 'betheme',
            'plugin_active'  => BeTheme_Cache::plugin_active(),
            'plugin_version' => self::plugin_version(),
            'storage'        => $storage,
            'hash'           => self::hash($post_id),
            'tree'           => BeTheme_Tree::outline($sections ?? []),
            'sections'       => $sections ?? [],
        ];
        if ('none' !== $storage && null === $sections) {
            $out['tree_error'] = 'The stored layout could not be decoded.';
        }

        return $out;
    }

    /**
     * Store a layout, as BeTheme's save does (see the class comment).
     *
     * @param array<int|string,mixed> $sections
     */
    public static function save(int $post_id, array $sections): void
    {
        if ([] === $sections) {
            delete_post_meta($post_id, BeTheme_Cache::META_KEY);
        } else {
            $value = self::encode($sections);
            update_post_meta($post_id, BeTheme_Cache::META_KEY, $value);
            if (metadata_exists('post', $post_id, self::PREVIEW_META_KEY)) {
                update_post_meta($post_id, self::PREVIEW_META_KEY, $value);
            }
        }
        delete_post_meta($post_id, 'mfn-page-object');

        BeTheme_Cache::refresh($post_id);
    }

    /**
     * The stored form of a layout under the site's builder-storage option.
     *
     * @param array<int|string,mixed> $sections
     * @return array<int|string,mixed>|string
     */
    private static function encode(array $sections)
    {
        $options = get_option('betheme', []);
        if (is_array($options) && 'encode' === ($options['builder-storage'] ?? '')) {
            return base64_encode(serialize($sections)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the theme's own 'encode' storage format.
        }

        return wp_slash($sections);
    }

    /**
     * Copies (new uids) of the elements a BeBuilder template holds, of the
     * kind the container takes.
     *
     * @param array<int|string,mixed> $sections the page's layout
     * @return array<int,array<string,mixed>>
     * @throws \InvalidArgumentException
     */
    public static function template_nodes(array $sections, string $to, string $template): array
    {
        $id   = ctype_digit($template) ? (int) $template : 0;
        $post = $id > 0 ? get_post($id) : null;
        if (! $post || self::TEMPLATE_POST_TYPE !== $post->post_type) {
            throw new \InvalidArgumentException(esc_html("element {template} needs the id of a BeBuilder template (scope templates lists them); got '{$template}'."));
        }
        if (! current_user_can('read_post', $id)) {
            throw new \InvalidArgumentException(esc_html("You are not allowed to read template {$id}."));
        }
        $layout = BeTheme_Cache::decode($id);
        if (null === $layout || [] === $layout) {
            throw new \InvalidArgumentException(esc_html("Template {$id} holds no BeBuilder layout."));
        }
        $kind  = BeTheme_Tree::child_kind($sections, $to);
        $nodes = BeTheme_Tree::template_nodes($layout, $kind, BeTheme_Tree::uids($sections));
        if ([] === $nodes) {
            throw new \InvalidArgumentException(esc_html("Template {$id} holds no {$kind}s to add here."));
        }

        return $nodes;
    }

    /**
     * The theme's builder field definitions, or null when they cannot be
     * loaded (BeTheme not active).
     */
    public static function fields(): ?object
    {
        static $fields = null;
        if (null !== $fields) {
            return $fields;
        }
        if (! BeTheme_Cache::plugin_active()) {
            return null;
        }
        BeTheme_Cache::load_theme_class('Mfn_Builder_Fields', '/functions/builder/class-mfn-builder-fields.php');
        if (! class_exists('Mfn_Builder_Fields', false)) {
            return null;
        }
        try {
            $fields = new \Mfn_Builder_Fields();
        } catch (\Throwable $e) {
            return null;
        }

        return $fields;
    }

    /**
     * The field list of a section, a wrap or one item type, or null.
     *
     * @return array<int,array<string,mixed>>|null
     */
    public static function field_list(string $kind, string $type = ''): ?array
    {
        $fields = self::fields();
        if (null === $fields) {
            return null;
        }
        try {
            if ('section' === $kind) {
                $list = $fields->get_section();
            } elseif ('wrap' === $kind) {
                $list = $fields->get_wrap();
            } else {
                $items = (array) $fields->get_items();
                $list  = isset($items[ $type ]['attr']) ? $items[ $type ]['attr'] : null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($list) ? array_values(array_filter($list, 'is_array')) : null;
    }

    /** A callable giving a style field's {selector, style} from the theme's definitions. */
    public static function css_def(): callable
    {
        return static function (string $kind, string $type, string $key): ?array {
            foreach (self::field_list($kind, $type) ?? [] as $field) {
                if (($field['id'] ?? null) === $key && isset($field['selector'], $field['style'])) {
                    return ['selector' => $field['selector'], 'style' => $field['style']];
                }
            }
            return null;
        };
    }

    /** A callable giving an item type's default sizes, refusing an unknown type when the catalog is loaded. */
    public static function item_defaults(): callable
    {
        return static function (string $type): array {
            $fields = self::fields();
            if (null === $fields) {
                return [];
            }
            $items = (array) $fields->get_items();
            if (! isset($items[ $type ])) {
                throw new \InvalidArgumentException(esc_html("BeBuilder has no item type '{$type}'; read scope catalog for the list."));
            }
            $out = [];
            foreach (['size', 'tablet_size', 'mobile_size'] as $field) {
                if (isset($items[ $type ][ $field ]) && is_string($items[ $type ][ $field ])) {
                    $out[ $field ] = $items[ $type ][ $field ];
                }
            }
            return $out;
        };
    }
}
