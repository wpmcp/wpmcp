<?php

namespace WPMCP\Tools\SiteEditor;

use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Site_Template_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Global styles on block themes (issue #379), served through the site
 * template tools as entity "global_styles". Not a tool itself.
 *
 * Core resolves global styles from layers: core defaults, blocks, the
 * theme's theme.json and the user layer, which is one wp_global_styles post
 * per theme holding a theme.json document. The site editor writes only the
 * user layer, and so does this.
 *
 * Read reports the theme, user and merged layers (settings and styles), the
 * theme's style variations and the effective font families. A dot path in
 * "id" narrows the three layers to one subtree.
 *
 * Write actions:
 *  - save: "attrs" maps dot paths ("styles.elements.link.color.text",
 *    "settings.color.palette.theme.base.color") to values; null removes the
 *    user value. Inside a preset list a segment is an entry's slug (or an
 *    index), and a list the user layer does not have yet is first copied
 *    from the effective data, so patching one palette color keeps the rest.
 *  - variation: replace the user layer with a style variation by "title".
 *  - revert: delete the user layer, back to the theme's styles.
 *
 * Every write is validated before anything is saved: each path must start
 * with settings or styles and survive core's theme.json schema sanitizing
 * for the schema version in use, values may not break out of a CSS
 * declaration, preset entries need a slug and a value, custom CSS takes
 * edit_css, and without unfiltered_html the values must survive core's safe
 * CSS filter (the one kses applies to this post). Then it is one snapshot of
 * the user post by theme key (Site_Template_Snapshot), so rollback restores
 * the prior post byte for byte, or deletes it when the write created it.
 */
class Global_Styles
{
    private const TOOL = 'site-templates-write';

    /** JSON flags core uses for this post (REST controller and kses filter). */
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;

    /** Value keys of the presets whose PRESETS_METADATA computes the value instead. */
    private const VALUE_FUNC_KEYS = [
        'typography.fontSizes' => 'size',
        'color.duotone'        => 'colors',
    ];

    public static function classic_message(): string
    {
        return sprintf(
            'The active theme (%s) is a classic theme, so global styles do not apply: the site editor\'s styles only exist on block themes. Use theme-read and theme-write for its Customizer settings instead.',
            get_stylesheet()
        );
    }

    public function read($path): array
    {
        $out = [
            'theme'       => get_stylesheet(),
            'block_theme' => wp_is_block_theme(),
        ];
        if (! $out['block_theme']) {
            return $out + ['message' => self::classic_message()];
        }

        $post    = $this->user_post();
        $content = null === $post ? '' : (string) $post->post_content;
        $user    = null === $post ? [] : (array) json_decode($content, true);
        unset($user['version'], $user['isGlobalStylesUserThemeJSON']);

        $layers = [
            'theme'  => $this->layer(\WP_Theme_JSON_Resolver::get_theme_data()->get_raw_data()),
            'user'   => $this->layer($user),
            'merged' => $this->layer(\WP_Theme_JSON_Resolver::get_merged_data()->get_raw_data()),
        ];

        $out += [
            'schema_version' => \WP_Theme_JSON::LATEST_SCHEMA,
            'user_post_id'   => null === $post ? 0 : $post->ID,
            'content_hash'   => null === $post ? '' : hash('sha256', $content),
        ];

        $path = null === $path ? '' : trim((string) $path);
        if ('' !== $path) {
            $segments = $this->segments($path);
            return $out + [
                'path'   => $path,
                'layers' => array_map(static fn (array $layer) => self::get_at($layer, $segments), $layers),
            ];
        }

        return $out + [
            'layers'        => $layers,
            'variations'    => $this->variations(),
            'font_families' => $this->font_families($layers['merged']),
        ];
    }

    public function write(string $action, array $args): array
    {
        if (! wp_is_block_theme()) {
            throw new \InvalidArgumentException(esc_html(self::classic_message()));
        }
        if (! in_array($action, ['save', 'variation', 'revert'], true)) {
            throw new \InvalidArgumentException('For global_styles "action" must be one of: save, variation, revert.');
        }

        $theme   = get_stylesheet();
        $post    = $this->user_post();
        $content = null === $post ? '' : (string) $post->post_content;

        $expected = (string) ($args['expected_hash'] ?? '');
        if ('' !== $expected && ! hash_equals(hash('sha256', $content), $expected)) {
            throw new \InvalidArgumentException(
                'Stale expected_hash: the user global styles changed since they were read. Re-read with site-templates-read and retry.'
            );
        }

        if ('revert' === $action) {
            return $this->revert($theme, $post, $args);
        }

        $current = $this->current_config($content);
        $config  = 'variation' === $action
            ? $this->variation_config((string) ($args['title'] ?? ''))
            : $this->patched_config($current, $args['attrs'] ?? null);
        $new     = (string) wp_json_encode($config, self::JSON_FLAGS);

        $post_id = null === $post ? 0 : $post->ID;
        $out     = $this->mutate($theme, $args, function () use (&$post_id, $new, $theme) {
            if ($post_id > 0) {
                wp_update_post(wp_slash(['ID' => $post_id, 'post_content' => $new]));
            } else {
                $inserted = wp_insert_post(wp_slash([
                    'post_type'    => 'wp_global_styles',
                    'post_status'  => 'publish',
                    'post_title'   => 'Custom Styles',
                    'post_name'    => 'wp-global-styles-' . rawurlencode($theme),
                    'post_content' => $new,
                ]), true);
                if (is_wp_error($inserted)) {
                    throw new \RuntimeException(esc_html($inserted->get_error_message()));
                }
                $post_id = (int) $inserted;
                wp_set_post_terms($post_id, [$theme], 'wp_theme');
            }
            self::forget_cache();
            return true;
        }, function () use (&$post_id, $new) {
            clean_post_cache($post_id);
            $saved = $post_id > 0 ? get_post($post_id) : null;
            return $saved && (string) $saved->post_content === $new;
        });

        return [
            'operation_id' => $out['operation_id'],
            'entity'       => 'global_styles',
            'user_post_id' => $post_id,
            'content_hash' => hash('sha256', $new),
        ];
    }

    private function revert(string $theme, ?\WP_Post $post, array $args): array
    {
        if (null === $post) {
            throw new \InvalidArgumentException(sprintf(
                'The active theme (%s) has no user global styles to revert; it already uses the theme\'s own styles.',
                esc_html($theme)
            ));
        }
        $key = ['wp_global_styles', $theme, Site_Template_Snapshot::GLOBAL_STYLES_SLUG];

        $out = $this->mutate($theme, $args, function () use ($key) {
            foreach (Site_Template_Snapshot::customization_ids(...$key) as $post_id) {
                wp_delete_post($post_id, true);
            }
            self::forget_cache();
            return true;
        }, static fn (): bool => [] === Site_Template_Snapshot::customization_ids(...$key));

        return [
            'operation_id' => $out['operation_id'],
            'entity'       => 'global_styles',
            'user_post_id' => 0,
            'content_hash' => '',
        ];
    }

    private function mutate(string $theme, array $args, callable $mutation, callable $verify): array
    {
        return Safe_Mutation::run(
            [
                'object_type' => Site_Template_Snapshot::TYPE,
                'object_id'   => Site_Template_Snapshot::key('wp_global_styles', $theme, Site_Template_Snapshot::GLOBAL_STYLES_SLUG),
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => self::TOOL,
                'args'        => $args,
            ],
            $mutation,
            $verify
        );
    }

    private static function forget_cache(): void
    {
        \WP_Theme_JSON_Resolver::clean_cached_data();
        wp_clean_theme_json_cache();
    }

    /** The active theme's user global styles post, without creating one. */
    private function user_post(): ?\WP_Post
    {
        $ids = Site_Template_Snapshot::customization_ids('wp_global_styles', get_stylesheet(), Site_Template_Snapshot::GLOBAL_STYLES_SLUG);
        if ([] === $ids) {
            return null;
        }
        $post = get_post($ids[ count($ids) - 1 ]);
        return $post instanceof \WP_Post ? $post : null;
    }

    /** Only the settings and styles of a theme.json document. */
    private function layer(array $data): array
    {
        return array_intersect_key($data, ['settings' => true, 'styles' => true]);
    }

    private function variations(): array
    {
        $out = [];
        foreach (\WP_Theme_JSON_Resolver::get_style_variations() as $variation) {
            $row = ['title' => (string) ($variation['title'] ?? '')];
            if (! empty($variation['description'])) {
                $row['description'] = (string) $variation['description'];
            }
            $out[] = $row;
        }
        return $out;
    }

    /** The effective font families, one row per family with the origin that defines it. */
    private function font_families(array $merged): array
    {
        $families = $merged['settings']['typography']['fontFamilies'] ?? [];
        $out      = [];
        foreach ((array) $families as $origin => $list) {
            foreach ((array) $list as $family) {
                if (! is_array($family)) {
                    continue;
                }
                $out[] = [
                    'origin'     => (string) $origin,
                    'slug'       => (string) ($family['slug'] ?? ''),
                    'name'       => (string) ($family['name'] ?? ''),
                    'fontFamily' => (string) ($family['fontFamily'] ?? ''),
                ];
            }
        }
        return $out;
    }

    /** The stored user document, migrated to the schema in use. */
    private function current_config(string $content): array
    {
        if ('' === $content) {
            return ['version' => \WP_Theme_JSON::LATEST_SCHEMA];
        }
        $config = json_decode($content, true);
        if (! is_array($config)) {
            throw new \InvalidArgumentException(
                'The stored user global styles are not valid JSON; use action revert to reset them first.'
            );
        }
        unset($config['isGlobalStylesUserThemeJSON']);
        $config            = \WP_Theme_JSON_Schema::migrate($config, 'custom');
        $config['version'] = \WP_Theme_JSON::LATEST_SCHEMA;
        return $config;
    }

    private function variation_config(string $title): array
    {
        $available = [];
        foreach (\WP_Theme_JSON_Resolver::get_style_variations() as $variation) {
            $name        = (string) ($variation['title'] ?? '');
            $available[] = $name;
            if ('' !== $title && 0 === strcasecmp($name, $title)) {
                $config = [
                    'version' => \WP_Theme_JSON::LATEST_SCHEMA,
                    'title'   => $name,
                ];
                foreach (['settings', 'styles'] as $key) {
                    if (! empty($variation[ $key ])) {
                        $config[ $key ] = $variation[ $key ];
                    }
                }
                return $this->finalize($config, []);
            }
        }
        throw new \InvalidArgumentException(sprintf(
            'No style variation titled "%s". The active theme has: %s.',
            esc_html($title),
            esc_html([] === $available ? 'none' : implode(', ', $available))
        ));
    }

    /**
     * Apply every path in attrs to the current document and validate it.
     *
     * @param mixed $attrs
     */
    private function patched_config(array $config, $attrs): array
    {
        if (! is_array($attrs) || [] === $attrs || array_is_list($attrs)) {
            throw new \InvalidArgumentException(
                'save needs "attrs": an object mapping dot paths (settings... or styles...) to values, null to remove one.'
            );
        }

        $merged  = \WP_Theme_JSON_Resolver::get_merged_data()->get_raw_data();
        $before  = $config;
        $patched = [];
        foreach ($attrs as $path => $value) {
            $path     = (string) $path;
            $segments = $this->segments($path);
            $this->check_value($path, $segments, $value);
            $concrete  = $this->apply($config, $segments, $value, $merged, $path);
            $patched[] = [$path, $concrete, $value];
        }

        $this->check_presets($before, $config);

        return $this->finalize($config, $patched);
    }

    /**
     * Run the document through core's schema sanitizing (and, without
     * unfiltered_html, its safe CSS filter) and refuse when a patched value
     * does not come out the other side.
     */
    private function finalize(array $config, array $patched): array
    {
        $sanitized = (new \WP_Theme_JSON($config, 'custom'))->get_raw_data();
        foreach ($patched as [$path, $concrete, $value]) {
            if (null === $value) {
                continue;
            }
            $got = self::get_at($sanitized, $concrete);
            if (null === $got && is_array($value) && array_is_list($value)) {
                // A bare preset list is stored keyed by the custom origin.
                $got = self::get_at($sanitized, array_merge($concrete, ['custom']));
            }
            if ($got !== $value) {
                throw new \InvalidArgumentException(sprintf(
                    'Path "%s" is not valid in the theme.json schema (version %d): unknown key, unregistered block, or an object where a value belongs (or the reverse). Nothing was saved.',
                    esc_html($path),
                    (int) \WP_Theme_JSON::LATEST_SCHEMA
                ));
            }
        }

        if (! current_user_can('unfiltered_html')) {
            $filtered = \WP_Theme_JSON::remove_insecure_properties($config, 'custom');
            foreach ($patched as [$path, $concrete, $value]) {
                if (null !== $value && self::get_at($filtered, $concrete) !== self::get_at($config, $concrete)) {
                    throw new \InvalidArgumentException(sprintf(
                        'Path "%s": the value does not pass the safe CSS filter applied for users without unfiltered_html. Nothing was saved.',
                        esc_html($path)
                    ));
                }
            }
            $config = $filtered;
        }

        $config['isGlobalStylesUserThemeJSON'] = true;
        return $config;
    }

    /** @return string[] */
    private function segments(string $path): array
    {
        $segments = explode('.', $path);
        if (count($segments) < 2 || ! in_array($segments[0], ['settings', 'styles'], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Path "%s" must start with "settings." or "styles.".',
                esc_html($path)
            ));
        }
        foreach ($segments as $segment) {
            if ('' === $segment) {
                throw new \InvalidArgumentException(sprintf('Path "%s" has an empty segment.', esc_html($path)));
            }
        }
        return $segments;
    }

    /**
     * Custom CSS takes edit_css, as it does in the site editor; any other
     * string may not close a CSS declaration or open markup.
     *
     * @param mixed $value
     */
    private function check_value(string $path, array $segments, $value): void
    {
        if (in_array('css', $segments, true)) {
            if (! current_user_can('edit_css')) {
                throw new \InvalidArgumentException(sprintf(
                    'Path "%s" is custom CSS, which needs the edit_css capability.',
                    esc_html($path)
                ));
            }
            return;
        }
        $leaves = is_array($value) ? $value : [$value];
        $bad    = false;
        array_walk_recursive($leaves, static function ($leaf) use (&$bad) {
            if (is_string($leaf) && strpbrk($leaf, '{};<>') !== false) {
                $bad = true;
            }
        });
        if ($bad) {
            throw new \InvalidArgumentException(sprintf(
                'Path "%s": values may not contain { } ; < or >.',
                esc_html($path)
            ));
        }
    }

    /**
     * Set (or, for null, remove) one path in the document. A segment inside
     * a list addresses an entry by slug or index; a preset list the document
     * lacks is copied from the effective data first.
     *
     * @param mixed $value
     * @return array The path with list entries resolved to indexes.
     */
    private function apply(array &$config, array $segments, $value, array $merged, string $path): array
    {
        $node     = &$config;
        $concrete = [];
        $last     = count($segments) - 1;
        foreach ($segments as $i => $segment) {
            $key = $segment;
            if (is_array($node) && [] !== $node && array_is_list($node)) {
                $key = $this->list_index($node, $segment, $path);
            }
            $concrete[] = $key;

            if ($i === $last) {
                if (null === $value) {
                    if (is_array($node) && array_key_exists($key, $node)) {
                        if (array_is_list($node)) {
                            array_splice($node, (int) $key, 1);
                        } else {
                            unset($node[ $key ]);
                        }
                    }
                } else {
                    $node[ $key ] = $value;
                }
                break;
            }

            if (! isset($node[ $key ])) {
                $seed         = self::get_at($merged, $concrete);
                $node[ $key ] = is_array($seed) && self::is_preset_list($seed) ? $seed : [];
            }
            if (! is_array($node[ $key ])) {
                throw new \InvalidArgumentException(sprintf(
                    'Path "%s": "%s" holds a value, not an object.',
                    esc_html($path),
                    esc_html(implode('.', $concrete))
                ));
            }
            $node = &$node[ $key ];
        }
        unset($node);

        if (null === $value) {
            self::prune($config, array_slice($concrete, 0, -1));
        }
        return $concrete;
    }

    private function list_index(array $list, string $segment, string $path): int
    {
        if (ctype_digit($segment) && (int) $segment < count($list)) {
            return (int) $segment;
        }
        foreach ($list as $index => $entry) {
            if (is_array($entry) && (string) ($entry['slug'] ?? '') === $segment) {
                return $index;
            }
        }
        throw new \InvalidArgumentException(sprintf(
            'Path "%s": no entry with slug "%s" in that list. Set the whole list to add one.',
            esc_html($path),
            esc_html($segment)
        ));
    }

    /** Remove objects a removal left empty, deepest first, along one path. */
    private static function prune(array &$config, array $path): void
    {
        while ([] !== $path) {
            $parent = array_slice($path, 0, -1);
            $key    = $path[ count($path) - 1 ];
            $node   = &$config;
            foreach ($parent as $segment) {
                if (! is_array($node) || ! isset($node[ $segment ])) {
                    return;
                }
                $node = &$node[ $segment ];
            }
            if (! is_array($node) || ! isset($node[ $key ]) || [] !== $node[ $key ]) {
                return;
            }
            unset($node[ $key ]);
            unset($node);
            $path = $parent;
        }
    }

    /**
     * Every preset list the patch changed must hold entries with a slug and
     * the preset's value key, as core needs to build its CSS variables.
     */
    private function check_presets(array $before, array $after): void
    {
        $roots = [['settings']];
        foreach (array_keys((array) ($after['settings']['blocks'] ?? [])) as $block) {
            $roots[] = ['settings', 'blocks', (string) $block];
        }
        foreach ($roots as $root) {
            foreach (\WP_Theme_JSON::PRESETS_METADATA as $meta) {
                $path  = array_merge($root, $meta['path']);
                $value = self::get_at($after, $path);
                if (! is_array($value) || self::get_at($before, $path) === $value) {
                    continue;
                }
                $key   = $meta['value_key'] ?? (self::VALUE_FUNC_KEYS[ implode('.', $meta['path']) ] ?? 'slug');
                $lists = array_is_list($value) ? ['' => $value] : $value;
                foreach ($lists as $origin => $list) {
                    foreach ((array) $list as $index => $entry) {
                        if (! is_array($entry) || ! is_string($entry['slug'] ?? null) || '' === $entry['slug'] || empty($entry[ $key ])) {
                            throw new \InvalidArgumentException(sprintf(
                                'Preset entry %s[%d] needs a "slug" and a "%s". Nothing was saved.',
                                esc_html(implode('.', array_filter(array_merge($path, [$origin]), 'strlen'))),
                                (int) $index,
                                esc_html($key)
                            ));
                        }
                    }
                }
            }
        }
    }

    private static function is_preset_list(array $value): bool
    {
        return [] !== $value && array_is_list($value) && is_array($value[0]) && isset($value[0]['slug']);
    }

    /** @return mixed The value at a path, or null. */
    private static function get_at(array $data, array $path)
    {
        foreach ($path as $segment) {
            if (! is_array($data) || ! array_key_exists($segment, $data)) {
                return null;
            }
            $data = $data[ $segment ];
        }
        return $data;
    }
}
