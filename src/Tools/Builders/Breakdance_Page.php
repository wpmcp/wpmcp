<?php

namespace WPMCP\Tools\Builders;

use WPMCP\Safety\Post_Creation_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Page-level pieces of editing on the Breakdance engine (issues #634 and
 * #635) that sit beside the node operations of Breakdance_Tree. The engine
 * ships as Breakdance and as Oxygen 6 (BREAKDANCE_MODE 'oxygen'), so every
 * method takes the builder slug; Oxygen_Page keeps Oxygen's own names on
 * top of this. Recorded from the engine source (Oxygen 6.1.0-beta.4, whose
 * themeless constants define both modes):
 *
 * - Revisions are ordinary WordPress revisions; the engine copies the page's
 *   data row onto each one it makes (Breakdance\Revisions,
 *   save_revisioned_meta_fields()), so a revision that carries the row holds
 *   the layout to restore.
 * - Templates are posts of the `<prefix>template`, `<prefix>header`,
 *   `<prefix>footer`, `<prefix>block` (Breakdance Global Blocks, Oxygen
 *   Components), `<prefix>part` (design library) and, for Breakdance,
 *   `breakdance_popup` types, each keeping its document in the same row a
 *   page does.
 * - "Save as Global Block" (Breakdance\Blocks\saveGlobalBlock()) inserts a
 *   new `<prefix>block` post and stores the tree in its data row; the engine
 *   only lists published blocks, so the new post is published.
 */
final class Breakdance_Page
{
    /** How many revisions a page read lists, newest first. */
    public const REVISION_LIMIT = 20;

    /**
     * Per product: the name used in messages, the plugin to install, what a
     * saved block is called, the majors this adapter was recorded from, and
     * the template post types whose tree can be added into a page.
     */
    private const PRODUCTS = [
        'breakdance' => [
            'label'      => 'Breakdance',
            'plugin'     => 'Breakdance',
            'block'      => 'Global Block',
            'block_type' => 'breakdance_block',
            'majors'     => [1, 2],
            'templates'  => ['breakdance_template', 'breakdance_header', 'breakdance_footer', 'breakdance_block', 'breakdance_popup', 'breakdance_part'],
        ],
        'oxygen'     => [
            'label'      => 'Oxygen',
            'plugin'     => 'Oxygen 6',
            'block'      => 'Component',
            'block_type' => 'oxygen_block',
            'majors'     => [6],
            'templates'  => ['oxygen_template', 'oxygen_header', 'oxygen_footer', 'oxygen_block', 'oxygen_part'],
        ],
    ];

    /** @return array{label:string,plugin:string,block:string,block_type:string,majors:int[],templates:string[]} */
    private static function product(string $builder): array
    {
        return self::PRODUCTS['oxygen' === $builder ? 'oxygen' : 'breakdance'];
    }

    /** @return string[] the post types whose tree can be added into a page */
    public static function template_post_types(string $builder): array
    {
        return self::product($builder)['templates'];
    }

    /** The loaded product's version, or null when it is not loaded. */
    public static function plugin_version(string $builder): ?string
    {
        if (! Breakdance_Cache::plugin_active($builder) || ! defined('__BREAKDANCE_VERSION')) {
            return null;
        }
        $version = (string) constant('__BREAKDANCE_VERSION');

        return '' === $version ? null : $version;
    }

    /** A note when the loaded version is not a major recorded here, else null. */
    public static function version_note(string $builder, ?string $version): ?string
    {
        $product = self::product($builder);
        if (null === $version || in_array((int) $version, $product['majors'], true)) {
            return null;
        }
        $majors = implode('.x and ', $product['majors']) . '.x';

        return "{$product['label']} {$version} is loaded; this adapter was built against {$product['label']} {$majors}, so check edits in the {$product['label']} editor.";
    }

    /**
     * Why a page cannot be written now, or null when it can: the engine owns
     * the CSS cache and the post types a write touches, so writes need the
     * product loaded.
     */
    public static function unavailable(string $builder): ?\WP_Error
    {
        if (Breakdance_Cache::plugin_active($builder)) {
            return null;
        }
        $product = self::product($builder);
        $url     = admin_url('plugins.php');

        return new \WP_Error(
            'plugin_missing',
            "{$product['plugin']} is not active on this site, so {$product['label']} pages cannot be edited. Install and activate {$product['plugin']} ({$url}), then try again.",
            ['status' => 403, 'plugin' => 'oxygen' === $builder ? 'oxygen' : 'breakdance', 'url' => $url]
        );
    }

    /** Whether the post already has a stored document row (either prefix for Breakdance). */
    public static function has_layout(int $post_id, string $builder): bool
    {
        foreach (Breakdance_Cache::data_keys($builder) as $key) {
            if (metadata_exists('post', $post_id, $key)) {
                return true;
            }
        }

        return false;
    }

    /** sha256 of the stored data row, the hash writes are pinned to. */
    public static function hash(int $post_id, string $builder): string
    {
        $raw = get_post_meta($post_id, Breakdance_Cache::data_key($builder, $post_id), true);

        return hash('sha256', is_string($raw) ? $raw : '');
    }

    /** @return array<int,array{id:int,date_gmt:string,author:int,hash:string}> */
    public static function revisions(int $post_id, string $builder): array
    {
        $out = [];
        foreach (wp_get_post_revisions($post_id, ['posts_per_page' => self::REVISION_LIMIT, 'check_enabled' => false]) as $revision) {
            if (wp_is_post_autosave($revision) || null === Breakdance_Content::get_document((int) $revision->ID, $builder)) {
                continue;
            }
            $out[] = [
                'id'       => (int) $revision->ID,
                'date_gmt' => (string) $revision->post_date_gmt,
                'author'   => (int) $revision->post_author,
                'hash'     => self::hash((int) $revision->ID, $builder),
            ];
        }

        return $out;
    }

    /**
     * The document stored on one of the page's revisions.
     *
     * @throws \InvalidArgumentException when $revision is not a revision of $post_id holding a layout
     */
    public static function revision_document(int $post_id, string $revision, string $builder): object
    {
        $id   = ctype_digit($revision) ? (int) $revision : 0;
        $post = $id > 0 ? get_post($id) : null;
        if (! $post || 'revision' !== $post->post_type || (int) $post->post_parent !== $post_id) {
            throw new \InvalidArgumentException(esc_html("restore needs path: the id of a revision of this page (see revisions in get-builder-content); got '{$revision}'."));
        }
        $doc = Breakdance_Content::get_document($id, $builder);
        if (null === $doc) {
            throw new \InvalidArgumentException(esc_html("Revision {$id} holds no " . self::product($builder)['label'] . ' layout.'));
        }

        return $doc;
    }

    /**
     * Add every top-level node of a template, header, footer, block, popup
     * or part under `to` ('' is the top level) from `index` on, with new
     * node ids.
     *
     * @return array{0:object,1:string} document, and the first new node's id
     */
    public static function insert_template(object $doc, string $to, ?int $index, int $template_id, string $builder): array
    {
        $post   = $template_id > 0 ? get_post($template_id) : null;
        $source = $post && in_array($post->post_type, self::template_post_types($builder), true) ? Breakdance_Content::get_document($template_id, $builder) : null;
        if (null === $source) {
            throw new \InvalidArgumentException(esc_html('No ' . self::product($builder)['label'] . " template with id {$template_id}; read get-builder-content scope templates for the ids."));
        }
        $nodes = Breakdance_Tree::nodes($source);
        if ([] === $nodes) {
            throw new \InvalidArgumentException(esc_html("Template {$template_id} holds no elements."));
        }

        $first = null;
        foreach ($nodes as $offset => $node) {
            [$doc, $id] = Breakdance_Tree::add($doc, $to, null === $index ? null : $index + $offset, get_object_vars($node));
            $first      = $first ?? $id;
        }

        return [$doc, (string) $first];
    }

    /**
     * Save one node of a page (with no path, the whole page) as a new block
     * (a Breakdance Global Block or an Oxygen Component), as the engine's
     * saveGlobalBlock() does. The page is left as it is. Recorded as a
     * creation, so rollback-operation trashes the new post.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    public static function save_block(int $post_id, array $args, string $builder, object $blank)
    {
        $product = self::product($builder);
        $noun    = $product['block'];
        $invalid = "invalid_{$builder}_request";
        if (! post_type_exists($product['block_type'])) {
            return new \WP_Error('builder_not_loaded', "Saving the new {$noun} needs {$product['plugin']} loaded on this site, since the {$noun} post type is its own.");
        }

        // The engine lists only published blocks and a block renders
        // wherever it is placed, so saving one is publishing it: it needs
        // the type's publish_posts as well as edit_posts, as wp-admin asks
        // (issue #947). Refused before anything is written or snapshotted.
        $type_object = get_post_type_object($product['block_type']);
        if ($type_object && ! current_user_can($type_object->cap->edit_posts)) {
            return new \WP_Error('forbidden', "Saving the new {$noun} needs the capability to create {$product['label']} {$noun}s.");
        }
        if ($type_object && ! current_user_can($type_object->cap->publish_posts)) {
            return new \WP_Error(
                'forbidden',
                sprintf('Saving the new %1$s %2$s needs the %3$s capability: %1$s publishes every %2$s as soon as it is saved. Ask an Editor or Administrator to save it.', $product['label'], $noun, $type_object->cap->publish_posts),
                ['status' => 403, 'capability' => $type_object->cap->publish_posts]
            );
        }

        $spec  = $args['element'] ?? null;
        $title = is_array($spec) && is_string($spec['title'] ?? null) ? trim($spec['title']) : '';
        if ('' === $title) {
            return new \WP_Error($invalid, "save_template needs element {title}: the {$noun} name to save under.");
        }

        $page = Breakdance_Content::get_document($post_id, $builder);
        if (null === $page) {
            return new \WP_Error($invalid, "This page holds no {$product['label']} layout to save.");
        }

        $path = (string) ($args['path'] ?? '');
        try {
            if ('' === $path) {
                $doc = $page;
            } else {
                [$doc] = Breakdance_Tree::add($blank, '', null, get_object_vars(Breakdance_Tree::node($page, $path)));
            }
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error($invalid, $e->getMessage());
        }

        $id = wp_insert_post(wp_slash([
            'post_title'  => sanitize_text_field($title),
            'post_status' => 'publish',
            'post_type'   => $product['block_type'],
        ]), true);
        if (is_wp_error($id)) {
            return $id;
        }
        $id = (int) $id;
        Breakdance_Content::save($id, $doc, $builder);

        $operation_id = Post_Creation_Snapshot::record('update-builder-content', [$id], $args, (string) ($args['session_id'] ?? 'default'));

        return [
            'operation_id' => $operation_id,
            'post_id'      => $post_id,
            'builder'      => $builder,
            'template_id'  => $id,
            'type'         => 'block',
            'hash'         => self::hash($id, $builder),
        ];
    }
}
