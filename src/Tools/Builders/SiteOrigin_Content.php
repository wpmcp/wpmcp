<?php

namespace WPMCP\Tools\Builders;

use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read/write access to a page's SiteOrigin Page Builder layout, and its
 * site-wide scopes (issue #644). The layout shape and the element
 * operations are SiteOrigin_Layout's.
 *
 * Recorded from the SiteOrigin Page Builder 2.38.0 source: the layout is
 * the `panels_data` postmeta (an array, not JSON), and an editor save
 * (SiteOrigin_Panels_Admin::save_post()) runs each widget through its own
 * update() sanitizer (process_raw_widgets()), sanitizes the row, cell and
 * widget styles (SiteOrigin_Panels_Styles_Admin::sanitize_all()), applies
 * the siteorigin_panels_data_pre_save filter, stores the array, deletes the
 * row when the layout is empty, and renders the layout into post_content
 * when the copy-content setting is on (copy_content_to_post()). save()
 * does the same through the plugin's own methods, running update() only on
 * the widgets this write added or changed, as the plugin's own layout
 * write does (a widget's update() is not guaranteed to be idempotent). All
 * of it is postmeta and post_content, which the post snapshot captures.
 *
 * Templates are the plugin's prebuilt layouts (the
 * siteorigin_panels_prebuilt_layouts filter, which also carries the theme's
 * siteorigin-page-builder-layouts folder) and other pages with a layout the
 * caller may edit (the builder's "clone" source). The catalog is the
 * site's registered widgets, as the builder's widget dialog lists them.
 */
final class SiteOrigin_Content
{
    public const META_KEY = 'panels_data';

    /** Whether SiteOrigin Page Builder is loaded on this request. */
    public static function plugin_active(): bool
    {
        return (bool) apply_filters('wpmcp_siteorigin_active', defined('SITEORIGIN_PANELS_VERSION'));
    }

    /** The loaded plugin's version, or null when it is not loaded. */
    public static function plugin_version(): ?string
    {
        if (! defined('SITEORIGIN_PANELS_VERSION')) {
            return null;
        }
        $version = (string) constant('SITEORIGIN_PANELS_VERSION');

        return '' === $version ? null : $version;
    }

    /** The refusal for a write that needs the plugin loaded. */
    public static function unavailable(): \WP_Error
    {
        $url = admin_url('plugins.php');

        return new \WP_Error(
            'plugin_missing',
            "SiteOrigin Page Builder is not active on this site, so its pages cannot be changed. Install and activate SiteOrigin Page Builder ({$url}), then try again.",
            ['status' => 403, 'plugin' => 'SiteOrigin Page Builder', 'url' => $url]
        );
    }

    /** Whether a post holds a layout SiteOrigin would render (a row or a widget). */
    public static function has_layout(int $post_id): bool
    {
        $data = get_post_meta($post_id, self::META_KEY, true);

        return is_array($data) && (! empty($data['grids']) || ! empty($data['widgets']));
    }

    /** sha256 of the stored layout row, the hash writes are pinned to. */
    public static function hash(int $post_id): string
    {
        $raw = get_post_meta($post_id, self::META_KEY, true);

        return hash('sha256', is_array($raw) ? serialize($raw) : (string) $raw); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- a fingerprint of the stored row, never unserialized.
    }

    /** @return array<string,mixed> the stored layout, an empty one when none */
    public static function data(int $post_id): array
    {
        $data = get_post_meta($post_id, self::META_KEY, true);

        return is_array($data) ? $data : ['widgets' => [], 'grids' => [], 'grid_cells' => []];
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function read(int $post_id)
    {
        if (! current_user_can('read_post', $post_id)) {
            return new \WP_Error('forbidden', 'You are not allowed to read this post.', ['status' => 403]);
        }

        $data = self::data($post_id);
        $out  = [
            'post_id'        => $post_id,
            'builder'        => 'siteorigin',
            'plugin_active'  => self::plugin_active(),
            'plugin_version' => self::plugin_version(),
            'hash'           => self::hash($post_id),
        ];
        try {
            $out['tree'] = SiteOrigin_Layout::outline($data);
        } catch (\InvalidArgumentException $e) {
            $out['tree']       = [];
            $out['tree_error'] = $e->getMessage();
        }
        $out['panels_data'] = $data;

        return $out;
    }

    /**
     * Store a layout the way an editor save does (see the class comment).
     *
     * @param array<string,mixed> $data
     */
    public static function save(int $post_id, array $data): void
    {
        $old  = get_post_meta($post_id, self::META_KEY, true);
        $old  = is_array($old) ? $old : [];
        $data = self::sanitize($post_id, $data, $old);

        if (empty($data['widgets']) && empty($data['grids'])) {
            delete_post_meta($post_id, self::META_KEY);
            return;
        }

        update_post_meta($post_id, self::META_KEY, wp_slash($data));
        self::copy_content($post_id, $data);
    }

    /**
     * Run the plugin's save-time sanitizers over a layout. Widgets equal to
     * the stored widget with the same widget_id keep their stored value.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $old
     * @return array<string,mixed>
     */
    private static function sanitize(int $post_id, array $data, array $old): array
    {
        $stored = [];
        foreach ((array) ($old['widgets'] ?? []) as $widget) {
            $wid = is_array($widget) ? (string) ($widget['panels_info']['widget_id'] ?? '') : '';
            if ('' !== $wid) {
                $stored[ $wid ] = $widget;
            }
        }

        $changed = [];
        foreach ((array) ($data['widgets'] ?? []) as $key => $widget) {
            $wid = (string) ($widget['panels_info']['widget_id'] ?? '');
            if ('' === $wid || ! isset($stored[ $wid ]) || self::without_placement($stored[ $wid ]) !== self::without_placement($widget)) {
                $changed[ $key ] = $widget;
            }
        }

        $admin = class_exists('SiteOrigin_Panels_Admin') ? \SiteOrigin_Panels_Admin::single() : null;
        if ([] !== $changed) {
            if (null !== $admin && method_exists($admin, 'process_raw_widgets')) {
                $processed = $admin->process_raw_widgets($changed, [] === $stored ? false : array_values($stored), false);
            } elseif (! current_user_can('unfiltered_html')) {
                $processed = map_deep($changed, static fn ($v) => is_string($v) ? wp_kses_post($v) : $v);
            } else {
                $processed = $changed;
            }
            foreach ($processed as $key => $widget) {
                $data['widgets'][ $key ] = $widget;
            }
        }

        if (class_exists('SiteOrigin_Panels_Styles_Admin')) {
            $styles = \SiteOrigin_Panels_Styles_Admin::single();
            if (method_exists($styles, 'sanitize_all')) {
                $data = $styles->sanitize_all($data);
            }
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- applying SiteOrigin Page Builder's own pre-save filter, as its editor save does; wpmcp adds no callback to it.
            $data = apply_filters('siteorigin_panels_data_pre_save', $data, get_post($post_id), $post_id);
            if (method_exists($styles, 'remove_invalid_styles')) {
                $data = $styles->remove_invalid_styles($data);
            }
        }

        return $data;
    }

    /** @param mixed $widget */
    private static function without_placement($widget): array
    {
        $widget = (array) $widget;
        if (isset($widget['panels_info']) && is_array($widget['panels_info'])) {
            unset($widget['panels_info']['grid'], $widget['panels_info']['cell'], $widget['panels_info']['id']);
        }

        return $widget;
    }

    /**
     * Render the layout into post_content through the plugin, as an editor
     * save does when the copy-content setting is on (a no-op otherwise).
     * Never fails the write it follows.
     *
     * @param array<string,mixed> $data
     */
    private static function copy_content(int $post_id, array $data): void
    {
        if (! self::plugin_active() || ! class_exists('SiteOrigin_Panels_Admin')) {
            return;
        }
        $admin = \SiteOrigin_Panels_Admin::single();
        $post  = get_post($post_id);
        if (! $post || ! method_exists($admin, 'copy_content_to_post') || ! method_exists($admin, 'with_save_guard')) {
            return;
        }
        try {
            $admin->with_save_guard(static function () use ($admin, $post, $post_id, $data) {
                $admin->copy_content_to_post($post, $post_id, $data);
            });
        } catch (\Throwable $e) {
            // post_content is rebuilt on the next editor save; the layout row
            // is the source of truth and is already stored.
            unset($e);
        }
    }

    /** Whether the site has a widget of this class registered. */
    public static function widget_known(string $class): bool
    {
        global $wp_widget_factory;

        return is_object($wp_widget_factory) && isset($wp_widget_factory->widgets[ $class ]);
    }

    /**
     * The prebuilt layouts the plugin offers, keyed by id.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function prebuilt(): array
    {
        if (class_exists('SiteOrigin_Panels_Admin_Layouts') && method_exists('SiteOrigin_Panels_Admin_Layouts', 'single')) {
            // Hooks the theme's layout folder into the filter below.
            \SiteOrigin_Panels_Admin_Layouts::single();
        }
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- reading SiteOrigin Page Builder's own prebuilt layout registry, as its layout dialog does; wpmcp adds no callback to it.
        $layouts = apply_filters('siteorigin_panels_prebuilt_layouts', []);

        return is_array($layouts) ? array_filter($layouts, 'is_array') : [];
    }

    /**
     * The layout a template id names: a prebuilt layout id, or the id of
     * another post with a layout the caller may edit.
     *
     * @return array<string,mixed>
     * @throws \InvalidArgumentException
     */
    public static function template(string $id): array
    {
        if (ctype_digit($id)) {
            $post_id = (int) $id;
            if (! self::has_layout($post_id)) {
                throw new \InvalidArgumentException(esc_html("Post {$post_id} holds no SiteOrigin layout."));
            }
            if (! current_user_can('edit_post', $post_id)) {
                throw new \InvalidArgumentException(esc_html("You are not allowed to copy the layout of post {$post_id}."));
            }
            return self::data($post_id);
        }

        $layouts = self::prebuilt();
        if (! isset($layouts[ $id ])) {
            throw new \InvalidArgumentException(esc_html("There is no SiteOrigin template '{$id}'; read scope templates for the list."));
        }
        $layout = $layouts[ $id ];
        $file   = is_string($layout['filename'] ?? null) ? $layout['filename'] : '';
        if ('' !== $file && '.json' === substr($file, -5) && is_readable($file)) {
            $decoded = json_decode((string) file_get_contents($file), true); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local layout file the plugin itself reads this way.
            if (is_array($decoded)) {
                $layout = $decoded + $layout;
            }
        }
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- applying SiteOrigin Page Builder's own filter, as it does when it loads a prebuilt layout; wpmcp adds no callback to it.
        $layout = apply_filters('siteorigin_panels_prebuilt_layout', $layout, $id);

        return is_array($layout) ? $layout : [];
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function design(string $scope, string $element)
    {
        if ('templates' === $scope) {
            return self::templates();
        }
        if ('catalog' === $scope) {
            return self::catalog($element);
        }

        return new \WP_Error('unsupported_builder', "SiteOrigin Page Builder's scope reads are 'templates' and 'catalog'; it keeps no site-wide design system.");
    }

    /** @return array<string,mixed> */
    public static function templates(): array
    {
        $templates = [];
        foreach (self::prebuilt() as $id => $layout) {
            $templates[] = [
                'id'          => (string) $id,
                'name'        => wp_strip_all_tags((string) ($layout['name'] ?? $id)),
                'description' => wp_strip_all_tags((string) ($layout['description'] ?? '')),
                'rows'        => is_array($layout['grids'] ?? null) ? count($layout['grids']) : null,
                'source'      => 'prebuilt',
            ];
        }

        $posts = Readable_Posts::get_posts([
            'post_type'      => 'any',
            'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => 100,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_key'       => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the builder's own clone listing, capped.
        ]);
        foreach ($posts as $post) {
            if (! $post instanceof \WP_Post || ! current_user_can('edit_post', $post->ID) || ! self::has_layout((int) $post->ID)) {
                continue;
            }
            $data        = self::data((int) $post->ID);
            $templates[] = [
                'id'          => (string) $post->ID,
                'name'        => (string) $post->post_title,
                'description' => (string) $post->post_type . ', ' . (string) $post->post_status,
                'rows'        => count((array) ($data['grids'] ?? [])),
                'source'      => 'post',
            ];
        }

        return ['builder' => 'siteorigin', 'scope' => 'templates', 'templates' => $templates];
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function catalog(string $element)
    {
        global $wp_widget_factory;
        if (! self::plugin_active() || ! is_object($wp_widget_factory)) {
            return Builder_Design_Data::not_loaded('siteorigin');
        }
        $out = ['builder' => 'siteorigin', 'scope' => 'catalog', 'plugin_version' => self::plugin_version()];

        if ('' !== $element) {
            $widget = $wp_widget_factory->widgets[ $element ] ?? null;
            if (! is_object($widget)) {
                return Builder_Design_Data::element_not_found('siteorigin', $element);
            }
            $entry = self::entry($element, $widget);
            if (method_exists($widget, 'form_options')) {
                $fields = [];
                try {
                    foreach ((array) $widget->form_options() as $key => $field) {
                        if (! is_array($field)) {
                            continue;
                        }
                        $control = ['id' => (string) $key, 'type' => (string) ($field['type'] ?? ''), 'label' => wp_strip_all_tags((string) ($field['label'] ?? ''))];
                        if (isset($field['default'])) {
                            $control['default'] = $field['default'];
                        }
                        if (isset($field['options']) && is_array($field['options'])) {
                            $control['options'] = array_keys($field['options']);
                        }
                        $fields[] = $control;
                    }
                } catch (\Throwable $e) {
                    $fields = null;
                }
                $entry['fields'] = $fields;
            } else {
                $entry['fields'] = null;
                $entry['note']   = 'A core or third-party WordPress widget: its fields are the instance keys its form saves (read a page holding one for an example).';
            }

            return $out + ['element' => $entry];
        }

        $elements = [];
        foreach ($wp_widget_factory->widgets as $class => $widget) {
            if (is_object($widget)) {
                $elements[] = self::entry((string) $class, $widget);
            }
        }

        return $out + ['elements' => $elements];
    }

    /** @return array<string,string> */
    private static function entry(string $class, object $widget): array
    {
        return [
            'class'       => $class,
            'id_base'     => (string) ($widget->id_base ?? ''),
            'name'        => wp_strip_all_tags((string) ($widget->name ?? $class)),
            'description' => wp_strip_all_tags((string) ($widget->widget_options['description'] ?? '')),
        ];
    }
}
