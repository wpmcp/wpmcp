<?php

namespace WPMCP\Tools\Sync;

use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Derive a change set from the snapshot ledger (issue #192, phase 1).
 *
 * The unit of local-live sync is not the database: it is the set of objects
 * an agent actually touched during a build session. Every mutating tool in
 * this plugin already snapshots before writing (Safe_Mutation +
 * Snapshot_Store), so the ledger IS the change set. No database diffing, no
 * heuristics: we read wpmcp_snapshots since a marker (a session id or a
 * ledger row id), dedupe to one entry per object, and serialize the
 * object's CURRENT state (the ledger holds the before-image; a change set
 * carries the after-image).
 *
 * Dependency resolution is the real work: a page that syncs without its
 * attachments, terms or referenced templates is a broken page. This first
 * slice resolves attachments (featured image plus wp-image-N block
 * references in post_content) and terms. Referenced templates/patterns and
 * Elementor global classes are TODO, tracked in docs/wip/issue-192.md.
 */
class Change_Set_Builder
{
    /** Change-set artifact format version, bumped on incompatible changes. */
    public const FORMAT_VERSION = 1;

    /**
     * Object types this builder knows how to export. Everything else in the
     * ledger (users, comments, wc_order rows) is deliberately excluded:
     * those are exactly the live-side data a sync must never overwrite.
     */
    private const SYNCABLE_OBJECT_TYPES = ['post', 'option'];

    /**
     * Build the change set for every ledger row at or after the marker.
     *
     * @param array $marker One of:
     *                      ['session_id' => string]  all rows in a session
     *                      ['since_id'   => int]     ledger rows with id > since_id
     * @return array{objects: array, dependencies: array, excluded: array, origin: array}
     */
    public function build(array $marker): array
    {
        $rows = $this->ledger_rows($marker);

        $seen      = [];
        $objects   = [];
        $excluded  = [];

        foreach ($rows as $row) {
            $key = $row['object_type'] . ':' . $this->row_object_key($row);
            if (isset($seen[$key])) {
                continue; // Ledger is newest-first; first hit wins.
            }
            $seen[$key] = true;

            if (! in_array($row['object_type'], self::SYNCABLE_OBJECT_TYPES, true)) {
                $excluded[] = [
                    'object_type' => $row['object_type'],
                    'object_id'   => (int) $row['object_id'],
                    'reason'      => 'object type is not syncable by design',
                ];
                continue;
            }

            $exported = $this->export_object($row);
            if (null !== $exported) {
                $objects[] = $exported;
            }
        }

        $dependencies = $this->resolve_dependencies($objects);

        return [
            'format_version' => self::FORMAT_VERSION,
            'origin'         => $this->origin(),
            'objects'        => $objects,
            'dependencies'   => $dependencies,
            'excluded'       => $excluded,
        ];
    }

    /** @return array[] ledger rows, newest first */
    private function ledger_rows(array $marker): array
    {
        global $wpdb;
        $table = Snapshot_Store::table_name();

        if (isset($marker['session_id'])) {
            return (array) $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$table} WHERE session_id = %s ORDER BY id DESC", (string) $marker['session_id']),
                ARRAY_A
            );
        }
        if (isset($marker['since_id'])) {
            return (array) $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$table} WHERE id > %d ORDER BY id DESC", (int) $marker['since_id']),
                ARRAY_A
            );
        }

        return [];
    }

    /**
     * Options are keyed by name inside the snapshot blob (object_id column
     * is 0 for them); posts are keyed by the numeric column.
     */
    private function row_object_key(array $row): string
    {
        if ('option' === $row['object_type']) {
            return 'op:' . $row['operation_id'];
        }
        return (string) (int) $row['object_id'];
    }

    /**
     * Export one object's CURRENT state. Returns null when the object no
     * longer exists locally (a deletion). Deletions are reported, never
     * applied automatically; see the issue's open questions.
     */
    private function export_object(array $row): ?array
    {
        if ('post' === $row['object_type']) {
            $post = get_post((int) $row['object_id'], ARRAY_A);
            if (! $post) {
                return [
                    'object_type' => 'post',
                    'object_id'   => (int) $row['object_id'],
                    'deleted'     => true,
                ];
            }
            return [
                'object_type'   => 'post',
                'object_id'     => (int) $row['object_id'],
                'post_type'     => $post['post_type'],
                'post_modified' => $post['post_modified_gmt'],
                'data'          => $post,
                'meta'          => get_post_meta((int) $row['object_id']),
                'terms'         => $this->post_terms((int) $row['object_id'], $post['post_type']),
            ];
        }

        // TODO(#192): option export needs the option name out of the snapshot
        // blob (Snapshot::unserialize on before_blob, then data.name), plus a
        // syncable-options allowlist (theme mods, widget specs, menus). Not
        // wired in this slice; options are reported as pending instead.
        return [
            'object_type' => 'option',
            'operation_id' => $row['operation_id'],
            'pending'     => 'option export not implemented in this slice',
        ];
    }

    /** @return array<string, string[]> taxonomy => slugs */
    private function post_terms(int $post_id, string $post_type): array
    {
        $out = [];
        foreach (get_object_taxonomies($post_type) as $taxonomy) {
            $terms = get_the_terms($post_id, $taxonomy);
            if (is_array($terms)) {
                $out[$taxonomy] = wp_list_pluck($terms, 'slug');
            }
        }
        return $out;
    }

    /**
     * Resolve each exported post's dependencies: featured image and
     * attachments referenced from post_content. Dependencies are listed as
     * a manifest (id, file path, checksum) rather than bytes in this slice;
     * whether the artifact ultimately carries bytes with checksum dedup is
     * an open question on the issue.
     */
    private function resolve_dependencies(array $objects): array
    {
        $attachment_ids = [];

        foreach ($objects as $object) {
            if ('post' !== $object['object_type'] || ! empty($object['deleted'])) {
                continue;
            }

            $thumb = get_post_thumbnail_id($object['object_id']);
            if ($thumb) {
                $attachment_ids[$thumb] = true;
            }

            $content = (string) ($object['data']['post_content'] ?? '');
            // Block editor marks images as wp-image-N; classic content too.
            if (preg_match_all('/wp-image-(\d+)/', $content, $m)) {
                foreach ($m[1] as $id) {
                    $attachment_ids[(int) $id] = true;
                }
            }
            // Block attributes carry "id":N inside wp:image / wp:gallery.
            if (preg_match_all('/<!-- wp:(?:image|gallery|cover)[^>]*"id":(\d+)/', $content, $m)) {
                foreach ($m[1] as $id) {
                    $attachment_ids[(int) $id] = true;
                }
            }
        }

        $attachments = [];
        foreach (array_keys($attachment_ids) as $id) {
            $file = get_attached_file($id);
            $attachments[] = [
                'object_type' => 'attachment',
                'object_id'   => $id,
                'file'        => $file ? wp_basename($file) : null,
                'url'         => wp_get_attachment_url($id) ?: null,
                'checksum'    => ($file && is_readable($file)) ? md5_file($file) : null,
                'mime_type'   => get_post_mime_type($id) ?: null,
            ];
        }

        // TODO(#192): resolve referenced templates/patterns (wp:pattern,
        // template refs) and Elementor global classes used by exported
        // templates. Tracked in docs/wip/issue-192.md.
        return ['attachments' => $attachments];
    }

    /** Origin descriptor the apply side needs for Url_Rewriter. */
    private function origin(): array
    {
        return [
            'site_url'   => site_url(),
            'home_url'   => home_url(),
            'wp_version' => get_bloginfo('version'),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];
    }
}
