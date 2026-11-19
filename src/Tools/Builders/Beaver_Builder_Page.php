<?php

namespace WPMCP\Tools\Builders;

use WPMCP\Safety\Post_Creation_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Page-level pieces of Beaver Builder editing (issue #638) beside the node
 * operations of Beaver_Builder_Nodes, and its scope reads. Recorded from
 * the Beaver Builder 2.11 source (FLBuilderModel):
 *
 * - Revisions are ordinary WordPress revisions. save_revision() copies the
 *   page's published node map and settings onto each new revision as
 *   `_fl_builder_data` / `_fl_builder_data_settings`; restore_revision()
 *   writes them back. A revision without that row holds no layout.
 * - Saved templates ("Save as template" for a row, column or module, or a
 *   whole layout) are `fl-builder-template` posts with a
 *   `fl-builder-template-type` term (layout, row, column or module), the
 *   node map in their own `_fl_builder_data`, a custom
 *   `_fl_builder_template_id` and the `_fl_builder_template_global` flag.
 *   apply_node_template() copies a template in with new ids and merges its
 *   CSS/JS into the page; a global one places only its root, whose
 *   template_id links it to the template. The post type comes with the
 *   paid plugin (not Beaver Builder Lite), so saving needs it registered.
 * - Global settings live in the `_fl_builder_settings` option, colour
 *   presets in `_fl_builder_color_presets` and global styles in
 *   `_fl_builder_styles`; the module catalog is FLBuilderModel::$modules.
 */
final class Beaver_Builder_Page
{
    /** How many revisions a page read lists, newest first. */
    public const REVISION_LIMIT = 20;

    public const TEMPLATE_POST_TYPE = 'fl-builder-template';

    public const TEMPLATE_TYPE_TAXONOMY = 'fl-builder-template-type';

    public const TEMPLATE_TYPES = ['layout', 'row', 'column', 'module'];

    /** The refusal for a call that needs Beaver Builder (with saved templates) loaded. */
    public static function unavailable(string $what = 'This'): \WP_Error
    {
        $url = admin_url('plugins.php');

        return new \WP_Error(
            'plugin_missing',
            "{$what} needs Beaver Builder with its saved templates (not part of Beaver Builder Lite) active on this site. Install and activate Beaver Builder ({$url}), then try again.",
            ['status' => 403, 'plugin' => 'Beaver Builder', 'url' => $url]
        );
    }

    /** @return array<int,array{id:int,date_gmt:string,author:int,hash:string}> */
    public static function revisions(int $post_id): array
    {
        $out = [];
        foreach (wp_get_post_revisions($post_id, ['posts_per_page' => self::REVISION_LIMIT, 'check_enabled' => false]) as $revision) {
            $id = (int) $revision->ID;
            if (wp_is_post_autosave($revision) || [] === Beaver_Builder_Content::get_nodes($id)) {
                continue;
            }
            $out[] = [
                'id'       => $id,
                'date_gmt' => (string) $revision->post_date_gmt,
                'author'   => (int) $revision->post_author,
                'hash'     => hash('sha256', maybe_serialize(get_post_meta($id, Beaver_Builder_Content::DATA_META_KEY, true))),
            ];
        }

        return $out;
    }

    /**
     * The layout one of the page's revisions holds.
     *
     * @return array{0:array<string,object>,1:?object} nodes and settings
     * @throws \InvalidArgumentException when $revision is not a layout revision of $post_id
     */
    public static function revision_layout(int $post_id, string $revision): array
    {
        $id   = ctype_digit($revision) ? (int) $revision : 0;
        $post = $id > 0 ? get_post($id) : null;
        if (! $post || 'revision' !== $post->post_type || (int) $post->post_parent !== $post_id) {
            throw new \InvalidArgumentException(esc_html("restore needs path: the id of a revision of this page (see revisions in get-builder-content); got '{$revision}'."));
        }
        $nodes = Beaver_Builder_Content::get_nodes($id);
        if ([] === $nodes) {
            throw new \InvalidArgumentException(esc_html("Revision {$id} holds no Beaver Builder layout."));
        }

        return [$nodes, Beaver_Builder_Content::get_settings($id)];
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function read(string $scope, string $element)
    {
        if ('templates' === $scope) {
            return self::templates();
        }

        return 'design_system' === $scope ? self::design_system() : self::catalog($element);
    }

    /** @return array<string,mixed> the templates scope read */
    public static function templates(): array
    {
        $templates = [];
        foreach (Builder_Design_Data::posts([self::TEMPLATE_POST_TYPE]) as $post) {
            $id    = (int) $post->ID;
            $nodes = Beaver_Builder_Content::get_nodes($id);
            $item  = [
                'id'          => $id,
                'template_id' => (string) get_post_meta($id, '_fl_builder_template_id', true),
                'name'        => (string) $post->post_title,
                'type'        => self::template_type($id, $nodes),
                'global'      => (bool) get_post_meta($id, '_fl_builder_template_global', true),
                'status'      => (string) $post->post_status,
                'tree'        => Beaver_Builder_Nodes::tree($nodes),
            ];
            $module = (string) get_post_meta($id, '_fl_builder_template_module_type', true);
            if ('' !== $module) {
                $item['module_type'] = $module;
            }
            $templates[] = $item;
        }

        return [
            'builder'        => 'beaver-builder',
            'scope'          => 'templates',
            'plugin_active'  => Beaver_Builder_Cache::plugin_active(),
            'plugin_version' => Beaver_Builder_Content::plugin_version(),
            'templates'      => $templates,
        ];
    }

    /**
     * Copy a saved template in under `to` at `index` (see
     * Beaver_Builder_Nodes::graft()).
     *
     * @param array<string,object> $nodes
     * @param mixed                $id
     * @return array{0:array<string,object>,1:string,2:?object} nodes, first new node id, CSS/JS to merge (null for a global template)
     */
    public static function insert_template(array $nodes, string $to, ?int $index, $id): array
    {
        $post_id = is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : 0;
        if ($post_id <= 0 || self::TEMPLATE_POST_TYPE !== get_post_type($post_id)) {
            $shown = is_scalar($id) ? (string) $id : '';
            throw new \InvalidArgumentException(esc_html("No Beaver Builder template '{$shown}'; read get-builder-content scope templates for the ids."));
        }
        $source = Beaver_Builder_Content::get_nodes($post_id);
        $global = (bool) get_post_meta($post_id, '_fl_builder_template_global', true);

        [$nodes, $path] = Beaver_Builder_Nodes::graft($nodes, $source, $to, $index, $global);

        return [$nodes, $path, $global ? null : Beaver_Builder_Content::get_settings($post_id)];
    }

    /**
     * Save the node at `path` (or the whole layout when path is '') as a
     * new, non-global saved template named by element {title}, as Beaver
     * Builder's save_node_template() stores one. Writes no page; the new
     * post is recorded for undo.
     *
     * @return array<string,mixed>|\WP_Error
     */
    public static function save_template(int $post_id, array $args)
    {
        if (! Beaver_Builder_Cache::plugin_active() || ! post_type_exists(self::TEMPLATE_POST_TYPE) || ! taxonomy_exists(self::TEMPLATE_TYPE_TAXONOMY)) {
            return self::unavailable('Saving a Beaver Builder template');
        }
        $spec  = $args['element'] ?? null;
        $title = is_array($spec) && is_string($spec['title'] ?? null) ? trim($spec['title']) : '';
        if ('' === $title) {
            return new \WP_Error('invalid_beaver_builder_request', 'save_template needs element {title}: the name to save the template under.');
        }

        $nodes = Beaver_Builder_Content::get_nodes($post_id);
        $path  = (string) ($args['path'] ?? '');
        try {
            if ([] === $nodes) {
                throw new \InvalidArgumentException(esc_html('This page holds no Beaver Builder layout to save.'));
            }
            $saved = Beaver_Builder_Nodes::extract($nodes, $path);
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('invalid_beaver_builder_request', $e->getMessage());
        }
        $type = '' === $path ? 'layout' : (string) $nodes[$path]->type;
        if (! in_array($type, self::TEMPLATE_TYPES, true)) {
            return new \WP_Error('invalid_beaver_builder_request', "Node type '{$type}' cannot be saved as a template; save its row, column or module.");
        }

        $type_object = get_post_type_object(self::TEMPLATE_POST_TYPE);
        $cap         = $type_object && isset($type_object->cap->edit_posts) ? (string) $type_object->cap->edit_posts : 'edit_posts';
        if (! current_user_can($cap)) {
            return new \WP_Error('forbidden', 'Saving a Beaver Builder template needs the capability to create templates.');
        }

        $id = wp_insert_post(wp_slash([
            'post_title'     => sanitize_text_field($title),
            'post_type'      => self::TEMPLATE_POST_TYPE,
            'post_status'    => current_user_can('publish_posts') ? 'publish' : 'pending',
            'ping_status'    => 'closed',
            'comment_status' => 'closed',
        ]), true);
        if (is_wp_error($id)) {
            return $id;
        }
        $id = (int) $id;

        wp_set_object_terms($id, $type, self::TEMPLATE_TYPE_TAXONOMY);
        if ('module' === $type && is_string($nodes[$path]->settings->type ?? null)) {
            update_post_meta($id, '_fl_builder_template_module_type', $nodes[$path]->settings->type);
        }
        update_post_meta($id, '_fl_builder_template_id', Beaver_Builder_Nodes::fresh_id());
        update_post_meta($id, '_fl_builder_template_global', false);
        add_post_meta($id, Beaver_Builder_Content::DRAFT_META_KEY, []);
        Beaver_Builder_Content::save_layout($id, $saved, self::template_settings($post_id, '' === $path));

        $operation_id = Post_Creation_Snapshot::record('update-builder-content', [$id], $args, (string) ($args['session_id'] ?? 'default'));

        return ['operation_id' => $operation_id, 'post_id' => $post_id, 'builder' => 'beaver-builder', 'template_id' => $id, 'type' => $type];
    }

    /** @return array<string,mixed> */
    public static function design_system(): array
    {
        $settings = get_option('_fl_builder_settings', []);
        $presets  = get_option('_fl_builder_color_presets', []);
        $styles   = get_option('_fl_builder_styles', []);

        return [
            'builder'         => 'beaver-builder',
            'scope'           => 'design_system',
            'plugin_active'   => Beaver_Builder_Cache::plugin_active(),
            'plugin_version'  => Beaver_Builder_Content::plugin_version(),
            'global_settings' => is_object($settings) || is_array($settings) ? self::to_array($settings) : [],
            'color_presets'   => is_array($presets) ? array_values($presets) : [],
            'global_styles'   => is_object($styles) || is_array($styles) ? self::to_array($styles) : [],
        ];
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function catalog(string $element)
    {
        if (! Beaver_Builder_Cache::plugin_active() || ! class_exists('\\FLBuilderModel') || ! property_exists('\\FLBuilderModel', 'modules')) {
            return Builder_Design_Data::not_loaded('beaver-builder');
        }

        $all = is_array(\FLBuilderModel::$modules) ? \FLBuilderModel::$modules : [];
        $out = [
            'builder'        => 'beaver-builder',
            'scope'          => 'catalog',
            'plugin_version' => Beaver_Builder_Content::plugin_version(),
        ];

        if ('' !== $element) {
            $module = $all[ $element ] ?? null;
            if (! is_object($module)) {
                return Builder_Design_Data::element_not_found('beaver-builder', $element);
            }

            return $out + ['element' => self::describe((string) $element, $module) + ['fields' => self::fields($module->form ?? [])]];
        }

        $elements = [];
        foreach ($all as $slug => $module) {
            if (is_object($module)) {
                $elements[] = self::describe((string) $slug, $module);
            }
        }

        return $out + ['elements' => $elements];
    }

    /** @param array<string,object> $nodes */
    private static function template_type(int $id, array $nodes): string
    {
        if (taxonomy_exists(self::TEMPLATE_TYPE_TAXONOMY)) {
            $terms = get_the_terms($id, self::TEMPLATE_TYPE_TAXONOMY);
            if (is_array($terms) && [] !== $terms) {
                return (string) $terms[0]->slug;
            }
        }
        // Without the taxonomy (Beaver Builder not loaded), read it off the nodes.
        $roots = Beaver_Builder_Nodes::child_ids($nodes, null);

        return 1 === count($roots) ? (string) $nodes[$roots[0]]->type : 'layout';
    }

    /** The page's CSS/JS a whole-layout template carries along, or none. */
    private static function template_settings(int $post_id, bool $whole): ?object
    {
        $settings = $whole ? Beaver_Builder_Content::get_settings($post_id) : null;

        return $settings ?? (object) ['css' => '', 'js' => ''];
    }

    /** @return array{slug:string,name:string,category:string,enabled:bool} */
    private static function describe(string $slug, object $module): array
    {
        return [
            'slug'     => $slug,
            'name'     => is_string($module->name ?? null) ? $module->name : '',
            'category' => is_string($module->category ?? null) ? $module->category : '',
            'enabled'  => (bool) ($module->enabled ?? true),
        ];
    }

    /**
     * The settings fields of a module form (tab => sections => fields),
     * flattened in order.
     *
     * @param mixed $form
     * @return array<int,array<string,mixed>>
     */
    private static function fields($form): array
    {
        $out = [];
        foreach (is_array($form) ? $form : [] as $tab_key => $tab) {
            foreach ((is_array($tab) && is_array($tab['sections'] ?? null)) ? $tab['sections'] : [] as $section) {
                foreach ((is_array($section) && is_array($section['fields'] ?? null)) ? $section['fields'] : [] as $name => $field) {
                    if (! is_array($field)) {
                        continue;
                    }
                    $item = [
                        'name'  => (string) $name,
                        'tab'   => (string) $tab_key,
                        'type'  => is_string($field['type'] ?? null) ? $field['type'] : '',
                        'label' => is_string($field['label'] ?? null) ? $field['label'] : '',
                    ];
                    if (array_key_exists('default', $field)) {
                        $item['default'] = $field['default'];
                    }
                    if (is_array($field['options'] ?? null)) {
                        $item['options'] = array_map('strval', array_keys($field['options']));
                    }
                    $out[] = $item;
                }
            }
        }

        return $out;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function to_array($value)
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            return array_map([self::class, 'to_array'], $value);
        }

        return $value;
    }
}
