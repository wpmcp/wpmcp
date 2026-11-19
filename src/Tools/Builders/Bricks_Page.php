<?php

namespace WPMCP\Tools\Builders;

use WPMCP\Safety\Post_Creation_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Page-level pieces of Bricks element editing (issue #633) beside the
 * element operations of Bricks_Tree. Recorded from the Bricks 2.3 source:
 *
 * - A page's layout is the row Bricks_Content::row_key() names (content,
 *   or the header or footer row of a header or footer template).
 * - Revisions are ordinary WordPress revisions; Bricks' save copies the
 *   saved rows onto each revision it makes (ajax.php, update_metadata on the
 *   revision id), so a revision holding the row holds the layout to restore
 *   (Revisions::ajax_get_revision_data reads it the same way).
 * - Templates are `bricks_template` posts whose `_bricks_template_type`
 *   names the area; inserting one copies its elements in with new ids, as
 *   the editor's template insert does. "Save as template" makes a new
 *   published `section` template.
 */
final class Bricks_Page
{
    /** How many revisions a page read lists, newest first. */
    public const REVISION_LIMIT = 20;

    /** The major versions this adapter was recorded from (1.x and 2.x share the _2 rows). */
    public const SUPPORTED_MAJORS = [1, 2];

    /** The loaded Bricks version, or null when Bricks is not loaded. */
    public static function plugin_version(): ?string
    {
        if (! Bricks_Cache::plugin_active() || ! defined('BRICKS_VERSION')) {
            return null;
        }
        $version = (string) constant('BRICKS_VERSION');

        return '' === $version ? null : $version;
    }

    /** A note when the loaded Bricks is a major version not recorded here, else null. */
    public static function version_note(?string $version): ?string
    {
        if (null === $version || in_array((int) $version, self::SUPPORTED_MAJORS, true)) {
            return null;
        }

        return "Bricks {$version} is loaded; this adapter was built against Bricks 1.x and 2.x, so check edits in the Bricks editor.";
    }

    /** plugin_missing when the Bricks theme is not loaded, else null. */
    public static function unavailable(): ?\WP_Error
    {
        if (Bricks_Cache::plugin_active()) {
            return null;
        }
        $url = admin_url('themes.php');

        return new \WP_Error(
            'plugin_missing',
            "The Bricks theme is not active on this site, so Bricks pages cannot be edited element by element. Install and activate Bricks ({$url}, " . admin_url('plugins.php') . '), then try again.',
            ['status' => 403, 'plugin' => 'bricks', 'url' => $url]
        );
    }

    /** The stored layout row as a list (empty when absent). @return array<int|string,mixed> */
    public static function elements(int $post_id): array
    {
        return Bricks_Content::get($post_id) ?? [];
    }

    /** Whether the post's layout row holds any element. */
    public static function has_layout(int $post_id): bool
    {
        return [] !== self::elements($post_id);
    }

    /** sha256 of the stored layout row, the hash writes are pinned to. */
    public static function hash(int $post_id): string
    {
        return hash('sha256', (string) wp_json_encode(self::elements($post_id)));
    }

    /**
     * The read: the stored content as before, plus what element edits need.
     *
     * @return array<string,mixed>
     */
    public static function read(int $post_id): array
    {
        $content = self::elements($post_id);
        $version = self::plugin_version();
        $out     = [
            'post_id'        => $post_id,
            'builder'        => 'bricks',
            'area'           => Bricks_Cache::area($post_id),
            'content'        => $content,
            'tree'           => Bricks_Tree::outline($content),
            'hash'           => self::hash($post_id),
            'plugin_active'  => Bricks_Cache::plugin_active(),
            'plugin_version' => $version,
            'revisions'      => self::revisions($post_id),
        ];
        $note = self::version_note($version);
        if (null !== $note) {
            $out['version_note'] = $note;
        }

        return $out;
    }

    /** @return array<int,array{id:int,date_gmt:string,author:int,hash:string}> */
    public static function revisions(int $post_id): array
    {
        $key = Bricks_Content::row_key($post_id);
        $out = [];
        foreach (wp_get_post_revisions($post_id, ['posts_per_page' => self::REVISION_LIMIT, 'check_enabled' => false]) as $revision) {
            $rows = get_post_meta((int) $revision->ID, $key, true);
            if (wp_is_post_autosave($revision) || ! is_array($rows) || [] === $rows) {
                continue;
            }
            $out[] = [
                'id'       => (int) $revision->ID,
                'date_gmt' => (string) $revision->post_date_gmt,
                'author'   => (int) $revision->post_author,
                'hash'     => hash('sha256', (string) wp_json_encode($rows)),
            ];
        }

        return $out;
    }

    /**
     * The layout stored on one of the page's revisions.
     *
     * @return array<int|string,mixed>
     * @throws \InvalidArgumentException when $revision is not a revision of $post_id holding a layout
     */
    public static function revision_elements(int $post_id, string $revision): array
    {
        $id   = ctype_digit($revision) ? (int) $revision : 0;
        $post = $id > 0 ? get_post($id) : null;
        if (! $post || 'revision' !== $post->post_type || (int) $post->post_parent !== $post_id) {
            throw new \InvalidArgumentException(esc_html("restore needs path: the id of a revision of this page (see revisions in get-builder-content); got '{$revision}'."));
        }
        $rows = get_post_meta($id, Bricks_Content::row_key($post_id), true);
        if (! is_array($rows) || [] === $rows) {
            throw new \InvalidArgumentException(esc_html("Revision {$id} holds no Bricks layout."));
        }
        Bricks_Tree::index($rows);

        return $rows;
    }

    /**
     * The elements of a Bricks template, to copy into a page.
     *
     * @return array<int|string,mixed>
     * @throws \InvalidArgumentException
     */
    public static function template_elements(int $template_id): array
    {
        $post = $template_id > 0 ? get_post($template_id) : null;
        if (! $post || Bricks_Cache::TEMPLATE_POST_TYPE !== $post->post_type || 'trash' === $post->post_status) {
            throw new \InvalidArgumentException(esc_html("No Bricks template with id {$template_id}; read get-builder-content scope templates for the ids."));
        }
        $rows = get_post_meta($template_id, Bricks_Cache::area_key(Bricks_Cache::area($template_id)), true);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Save one element of a page (with no path, the whole layout) as a new
     * Bricks section template, with new element ids. The page is left as it
     * is. Recorded as a creation, so rollback-operation trashes the new post.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>|\WP_Error
     */
    public static function save_template(int $post_id, array $args)
    {
        $type = Bricks_Cache::TEMPLATE_POST_TYPE;
        if (! post_type_exists($type)) {
            return new \WP_Error('builder_not_loaded', 'Saving a Bricks template needs the Bricks theme loaded on this site, since the template post type is its own.');
        }

        // A published template can be placed on any page, so saving one is
        // publishing it: the type's publish_posts as well as edit_posts.
        $type_object = get_post_type_object($type);
        foreach (['edit_posts', 'publish_posts'] as $cap) {
            if ($type_object && ! current_user_can($type_object->cap->{$cap})) {
                return new \WP_Error(
                    'forbidden',
                    sprintf('Saving a Bricks template needs the %s capability.', $type_object->cap->{$cap}),
                    ['status' => 403, 'capability' => $type_object->cap->{$cap}]
                );
            }
        }

        $spec  = $args['element'] ?? null;
        $title = is_array($spec) && is_string($spec['title'] ?? null) ? trim($spec['title']) : '';
        if ('' === $title) {
            return new \WP_Error('invalid_bricks_request', 'save_template needs element {title}: the template name to save under.');
        }

        $page = self::elements($post_id);
        $path = (string) ($args['path'] ?? '');
        try {
            if ([] === $page) {
                throw new \InvalidArgumentException(esc_html('This page holds no Bricks layout to save.'));
            }
            $elements = '' === $path ? Bricks_Tree::renumber($page) : Bricks_Tree::extract($page, $path);
        } catch (\InvalidArgumentException $e) {
            return new \WP_Error('invalid_bricks_request', $e->getMessage());
        }

        $id = wp_insert_post(wp_slash([
            'post_title'  => sanitize_text_field($title),
            'post_status' => 'publish',
            'post_type'   => $type,
        ]), true);
        if (is_wp_error($id)) {
            return $id;
        }
        $id = (int) $id;
        update_post_meta($id, Bricks_Cache::TEMPLATE_TYPE_KEY, 'section');
        Bricks_Content::save($id, $elements);

        $operation_id = Post_Creation_Snapshot::record('update-builder-content', [$id], $args, (string) ($args['session_id'] ?? 'default'));

        return [
            'operation_id' => $operation_id,
            'post_id'      => $post_id,
            'builder'      => 'bricks',
            'template_id'  => $id,
            'type'         => 'section',
            'hash'         => self::hash($id),
        ];
    }
}
