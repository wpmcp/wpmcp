<?php

namespace WPMCP\Tools\Builders;

use WPMCP\Safety\Post_Creation_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The Avada (Fusion Builder) library on the builder pair (issue #637), per
 * the Avada Builder 3.16 source (Fusion_Builder_Library, Fusion_Template_Builder):
 *
 * - Saved page templates are `fusion_template` posts, their layout in
 *   post_content and the builder flag `fusion_builder_status` set.
 * - Saved containers, columns, nested rows and elements are
 *   `fusion_element` posts filed under the `element_category` term
 *   `sections`, `columns` or `elements` (a nested row also carries
 *   `_fusion_element_type` = `nested`); a global one carries
 *   `_fusion_is_global` = `yes`, and a page using it names it with a
 *   `fusion_global="<id>"` attribute on the element's opening tag.
 * - Layout sections (headers, footers, page title bars, content) are
 *   `fusion_tb_section` posts filed under a `fusion_tb_category` term, and
 *   Layouts are `fusion_tb_layout` posts whose post_content is a JSON
 *   document of conditions and the sections they use (template_terms); the
 *   global Layout is the `fusion_tb_layout_default` option, same shape.
 *
 * Reads work without Avada loaded. Saving to the library needs it, since the
 * category taxonomy is Avada's. Adding a library item into a page splices its
 * stored markup in (as Avada's own load_layout() hands it to the editor), so
 * that write is the page write update-builder-content already snapshots.
 */
final class Avada_Library
{
    private const LIBRARY_TYPES = ['fusion_template' => 'template', 'fusion_element' => 'element', 'fusion_tb_section' => 'layout_section', 'fusion_tb_layout' => 'layout'];

    /** @return array<string,mixed> */
    public static function templates(): array
    {
        $templates = [self::global_layout()];
        foreach (Builder_Design_Data::posts(array_keys(self::LIBRARY_TYPES)) as $post) {
            $templates[] = self::describe($post);
        }

        return [
            'builder'       => 'avada',
            'scope'         => 'templates',
            'plugin_active' => Avada_Content::plugin_active(),
            'templates'     => $templates,
        ];
    }

    /** @return array<string,mixed> */
    private static function describe(\WP_Post $post): array
    {
        $type = self::LIBRARY_TYPES[ $post->post_type ];
        $item = [
            'id'     => (int) $post->ID,
            'title'  => $post->post_title,
            'status' => $post->post_status,
            'type'   => $type,
        ];

        if ('layout' === $type) {
            return $item + self::layout_data((string) $post->post_content);
        }

        $taxonomy = 'element' === $type ? 'element_category' : ('layout_section' === $type ? 'fusion_tb_category' : '');
        if ('' !== $taxonomy) {
            $item['category'] = self::category((int) $post->ID, $taxonomy);
        }
        if ('element' === $type) {
            $item['global'] = 'yes' === get_post_meta($post->ID, '_fusion_is_global', true);
        }
        $item['tree'] = Avada_Shortcodes::tree((string) $post->post_content);

        return $item;
    }

    /** The first term of a library post, read without the taxonomy registered. */
    private static function category(int $post_id, string $taxonomy): string
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the taxonomy is Avada's and may not be registered while Avada is off.
        $slug = $wpdb->get_var($wpdb->prepare(
            "SELECT t.slug FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = %s ORDER BY t.term_id LIMIT 1",
            $post_id,
            $taxonomy
        ));

        return (string) $slug;
    }

    /** @return array<string,mixed> the global Layout, as Avada lists it first */
    private static function global_layout(): array
    {
        $raw = get_option('fusion_tb_layout_default', '');

        return [
            'id'     => 0,
            'title'  => 'Global Layout',
            'status' => 'publish',
            'type'   => 'layout',
        ] + self::layout_data(is_string($raw) ? $raw : '');
    }

    /** @return array{conditions:mixed,template_terms:mixed} */
    private static function layout_data(string $json): array
    {
        $data = json_decode(wp_unslash($json), true);
        if (! is_array($data)) {
            // The way Avada's get_registered_layouts() reads a row its own
            // escaping left unparseable.
            $data = json_decode(str_replace(['\\"', '\\'], ['\'', ''], wp_unslash($json)), true);
        }
        $data = is_array($data) ? $data : [];

        return [
            'conditions'     => $data['conditions'] ?? [],
            'template_terms' => $data['template_terms'] ?? [],
        ];
    }

    /**
     * Splice a library item's layout into a page under a container ('' is
     * the top level). A global element is linked to its library post the way
     * Avada links it. Refused where the Avada editor could not place it:
     * containers only at the top level, and nothing else there.
     *
     * @return array{0:string,1:string} new content, and the inserted path
     */
    public static function insert_template(string $content, string $to, ?int $index, int $template_id): array
    {
        $post = $template_id > 0 ? get_post($template_id) : null;
        if (! $post || ! in_array($post->post_type, ['fusion_template', 'fusion_element'], true)) {
            throw new \InvalidArgumentException(esc_html("No Avada library template or element with id {$template_id}; read scope templates for the ids."));
        }

        $markup = (string) $post->post_content;
        $tree   = Avada_Shortcodes::tree($markup);
        if ([] === $tree) {
            throw new \InvalidArgumentException(esc_html("Library item {$template_id} holds no layout."));
        }

        $is_section = 'fusion_builder_container' === $tree[0]['tag'];
        if ($is_section && '' !== $to) {
            throw new \InvalidArgumentException(esc_html("Library item {$template_id} is a container, which Avada places only at the top level: pass to \"\"."));
        }
        if (! $is_section && '' === $to) {
            throw new \InvalidArgumentException(esc_html("Library item {$template_id} ({$tree[0]['tag']}) is not a container, so it cannot sit at the top level; pass the path of the row or column to add it to."));
        }

        if ('fusion_element' === $post->post_type && 'yes' === get_post_meta($template_id, '_fusion_is_global', true)) {
            $markup = Avada_Shortcodes::update($markup, '0', ['fusion_global' => (string) $template_id], null);
        }

        return Avada_Shortcodes::insert($content, $to, $index, $markup);
    }

    /**
     * Save an element of a page (or, with no path, the whole page) to the
     * library, as Avada's "save to library" does: a new fusion_element in the
     * category its layout level calls for, or a new fusion_template. The page
     * is left as it is. Recorded as a creation, so rollback-operation moves
     * the new post to the trash.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    public static function save_template(int $post_id, array $args)
    {
        if (! Avada_Content::plugin_active() || ! taxonomy_exists('element_category')) {
            return new \WP_Error('builder_not_loaded', 'Saving to the Avada library needs Avada Builder loaded on this site, since its library categories are its own.');
        }

        $spec  = $args['element'] ?? null;
        $title = is_array($spec) && is_string($spec['title'] ?? null) ? trim($spec['title']) : '';
        if ('' === $title) {
            return new \WP_Error('invalid_avada_operation', 'save_template needs element {title}: the library name to save under.');
        }

        $path    = (string) ($args['path'] ?? '');
        $content = Avada_Content::get_content($post_id);
        $meta    = [];
        $term    = '';

        if ('' === $path) {
            $type                           = 'fusion_template';
            $markup                         = $content;
            $meta['fusion_builder_status']  = 'active';
        } else {
            try {
                $markup = Avada_Shortcodes::markup($content, $path);
            } catch (\InvalidArgumentException $e) {
                return new \WP_Error('invalid_avada_operation', $e->getMessage());
            }
            $type = 'fusion_element';
            $tag  = Avada_Shortcodes::tree($markup)[0]['tag'];
            $term = ['fusion_builder_container' => 'sections', 'fusion_builder_column' => 'columns', 'fusion_builder_column_inner' => 'columns'][ $tag ] ?? 'elements';
            if ('fusion_builder_row_inner' === $tag) {
                $meta['_fusion_element_type'] = 'nested';
            }
            // A plain library copy is not linked to any global, as in Avada's save_layout().
            $markup = (string) preg_replace('/ fusion_global="[0-9]*"/', '', $markup);
        }

        // The capability Avada's save_layout() checks for the library post type.
        $type_object = get_post_type_object($type);
        if ($type_object && ! current_user_can($type_object->cap->edit_posts)) {
            return new \WP_Error('forbidden', "Saving to the Avada library needs the capability to create {$type} posts.");
        }

        $id = wp_insert_post(wp_slash([
            'post_title'   => sanitize_text_field($title),
            'post_content' => $markup,
            'post_status'  => current_user_can('publish_posts') ? 'publish' : 'pending',
            'post_type'    => $type,
        ]), true);
        if (is_wp_error($id)) {
            return $id;
        }
        $id = (int) $id;
        foreach ($meta as $key => $value) {
            add_post_meta($id, $key, $value);
        }
        if ('' !== $term) {
            wp_set_object_terms($id, $term, 'element_category');
        }

        $operation_id = Post_Creation_Snapshot::record('update-builder-content', [$id], $args, (string) ($args['session_id'] ?? 'default'));

        $out = [
            'operation_id' => $operation_id,
            'post_id'      => $post_id,
            'builder'      => 'avada',
            'template_id'  => $id,
            'type'         => self::LIBRARY_TYPES[ $type ],
        ];
        if ('' !== $term) {
            $out['category'] = $term;
        }

        return $out;
    }
}
