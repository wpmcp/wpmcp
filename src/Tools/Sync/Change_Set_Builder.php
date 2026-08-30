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
 * heuristics: we read wpmcp_snapshots for a marker (a session id, an
 * operation id, or a ledger row id), dedupe to one entry per object, and
 * serialize the object's CURRENT state (the ledger holds the before-image;
 * a change set carries the after-image).
 *
 * Three things this builder is deliberately careful about, because a change
 * set that lies is worse than no change set at all:
 *
 * 1. Truncation. Safe_Mutation prunes the ledger to the licence's history
 *    limit after every write (20 rows on free), so a long build session's
 *    earliest rows may already be gone. The builder compares the marker
 *    against the surviving floor and reports `truncated` rather than
 *    handing back a silently partial set.
 * 2. Under-reporting. Object types this slice cannot export are listed one
 *    row per ledger row in `excluded`, never collapsed: `excluded` is the
 *    honest account of what the sync will NOT carry.
 * 3. Policy vs. gap. 'user', 'comment' and 'wc_order' are excluded BY
 *    DESIGN (they are exactly the live-side data a sync must never
 *    overwrite). Everything else unhandled is reported as not implemented,
 *    so an operator is never told a gap is a policy.
 *
 * Dependency resolution is the real work: a page that syncs without its
 * attachments, terms or referenced templates is a broken page. This slice
 * resolves attachments from parsed blocks (image/gallery/cover/video/audio/
 * file/media-text), classic `wp-image-N` markup, the featured image, and
 * the Elementor `_elementor_data` element tree. Referenced templates and
 * patterns are TODO, tracked in docs/wip/issue-192.md.
 */
class Change_Set_Builder
{
    /** Change-set artifact format version, bumped on incompatible changes. */
    public const FORMAT_VERSION = 1;

    /**
     * Hard cap on ledger rows read for one change set. A Pro history limit
     * is PHP_INT_MAX, so an unbounded `since_id` marker would otherwise read
     * the whole table. Hitting the cap is reported as truncation.
     */
    public const MAX_LEDGER_ROWS = 5000;

    /** Above this size a dependency's checksum is skipped rather than hashing the file. */
    private const CHECKSUM_MAX_BYTES = 64 * 1024 * 1024;

    /**
     * Ledger object types excluded BY DESIGN. These are the live-side rows a
     * sync must never carry: pushing a local user table or a local order
     * over a live site is the data-loss mode the whole issue is written
     * around.
     */
    private const NON_SYNCABLE_BY_DESIGN = ['user', 'comment', 'wc_order'];

    /**
     * Ledger object_type => export kind. The raw ledger string is not the
     * export kind: `page_build` (Build_Page's composite snapshot) and
     * `media_import` are both post-backed with a numeric id and must route
     * to the post/attachment exporters, or the flagship build tool produces
     * a change set that omits exactly the pages it just built.
     */
    private const OBJECT_KINDS = [
        'post'         => 'post',
        'page_build'   => 'post',
        'attachment'   => 'attachment',
        'media_import' => 'attachment',
    ];

    /**
     * Build the change set for a marker.
     *
     * @param array $marker One of:
     *                      ['session_id'   => string] all rows in a session
     *                      ['operation_id' => string] rows written after that operation
     *                      ['since_id'     => int]    ledger rows with id > since_id
     * @return array{format_version:int, origin:array, objects:array, dependencies:array, excluded:array, truncated:array}
     */
    public function build(array $marker): array
    {
        $rows = $this->ledger_rows($marker);

        $seen     = [];
        $objects  = [];
        $excluded = [];

        foreach ($rows as $row) {
            $type = (string) $row['object_type'];

            if (in_array($type, self::NON_SYNCABLE_BY_DESIGN, true)) {
                $excluded[] = $this->excluded_row($row, 'excluded by design: live-side data a sync must never overwrite');
                continue;
            }

            $kind = self::OBJECT_KINDS[ $type ] ?? null;
            if (null === $kind) {
                $excluded[] = $this->excluded_row($row, 'not implemented in this slice');
                continue;
            }

            // Only numerically identified rows can be deduped: object_id is 0
            // for every string-keyed type (Snapshot_Store::db_object_id), so
            // keying those on the column would collapse them all into one.
            $key = $kind . ':' . (int) $row['object_id'];
            if (isset($seen[$key])) {
                continue; // Ledger is newest-first; first hit wins.
            }
            $seen[$key] = true;

            $objects[] = $this->export_object($kind, (int) $row['object_id']);
        }

        [$dependencies, $unresolved] = $this->resolve_dependencies($objects);
        $excluded                    = array_merge($excluded, $unresolved);

        return [
            'format_version' => self::FORMAT_VERSION,
            'origin'         => $this->origin(),
            'objects'        => $objects,
            'dependencies'   => $dependencies,
            'excluded'       => $excluded,
            'truncated'      => $this->truncation_report($marker, count($rows)),
        ];
    }

    /**
     * Resolve a marker to the ledger row id it starts after, or null for a
     * session marker (which is bounded by session_id, not by id).
     *
     * @throws \RuntimeException When an operation_id marker names no row.
     */
    public function marker_floor(array $marker): ?int
    {
        if (isset($marker['since_id'])) {
            return (int) $marker['since_id'];
        }
        if (isset($marker['operation_id'])) {
            $id = Snapshot_Store::id_for_operation((string) $marker['operation_id']);
            if (null === $id) {
                throw new \RuntimeException(
                    'No ledger row for that operation_id; it may already have been pruned from the history.'
                );
            }
            return $id;
        }
        return null;
    }

    /** @return array[] ledger rows, newest first, before_blob excluded */
    private function ledger_rows(array $marker): array
    {
        if (isset($marker['session_id'])) {
            return Snapshot_Store::index_by_session((string) $marker['session_id'], self::MAX_LEDGER_ROWS);
        }

        $floor = $this->marker_floor($marker);
        if (null === $floor) {
            return [];
        }

        return Snapshot_Store::index_since($floor, self::MAX_LEDGER_ROWS);
    }

    /**
     * Is this change set provably incomplete?
     *
     * Safe_Mutation::run() calls Snapshot_Store::prune(Gate::history_limit())
     * after every mutation, and the free tier keeps 20 rows, so a build
     * session with more mutations than that has already lost its earliest
     * ledger rows by the time anyone asks for a change set. A partial set
     * pushed to production as if it were complete is precisely the failure
     * this issue exists to prevent, so it is reported, loudly, in the
     * artifact itself.
     *
     * @return array{truncated:bool, reason:string|null, retention_floor:int|null, rows_read:int}
     */
    private function truncation_report(array $marker, int $rows_read): array
    {
        $floor  = Snapshot_Store::min_id();
        $reason = null;

        if (isset($marker['session_id']) && Snapshot_Store::row_count() >= \WPMCP\Pro\Gate::history_limit()) {
            // A session marker is bounded by session_id, not by id, so there
            // is no way to know how many of its rows once existed. What IS
            // knowable is that the ledger is sitting at its retention cap,
            // which means prune() has been discarding rows, which means this
            // session may well have lost its earliest ones.
            $reason = sprintf(
                'The ledger is at its retention cap of %d rows, so mutations earlier in this session may already have been pruned.',
                Snapshot_Store::row_count()
            );
        } elseif ($rows_read >= self::MAX_LEDGER_ROWS) {
            $reason = sprintf(
                'The ledger read hit the %d row cap, so older rows in this range were not examined.',
                self::MAX_LEDGER_ROWS
            );
        } elseif (null !== $floor) {
            $marker_floor = null;
            try {
                $marker_floor = $this->marker_floor($marker);
            } catch (\RuntimeException $e) {
                $marker_floor = null;
            }
            // A since/operation marker at or below the surviving floor means
            // rows between the marker and the floor were pruned away.
            if (null !== $marker_floor && $marker_floor < $floor - 1) {
                $reason = sprintf(
                    'The ledger has been pruned to id %d, above the marker, so mutations between them can no longer be recovered.',
                    $floor
                );
            }
        }

        return [
            'truncated'       => null !== $reason,
            'reason'          => $reason,
            'retention_floor' => $floor,
            'rows_read'       => $rows_read,
        ];
    }

    /** One honest excluded entry per ledger row; excluded is never deduped. */
    private function excluded_row(array $row, string $reason): array
    {
        return [
            'object_type'  => (string) $row['object_type'],
            'object_id'    => (int) $row['object_id'],
            'operation_id' => (string) ($row['operation_id'] ?? ''),
            'tool_name'    => (string) ($row['tool_name'] ?? ''),
            'reason'       => $reason,
        ];
    }

    /**
     * Export one object's CURRENT state. An object that no longer exists
     * locally is returned with `deleted` set rather than dropped: deletions
     * are reported, never applied automatically (see the issue's open
     * questions), so the apply side has to see them.
     */
    private function export_object(string $kind, int $object_id): array
    {
        $post = get_post($object_id, ARRAY_A);
        if (! $post) {
            return [
                'object_type' => $kind,
                'object_id'   => $object_id,
                'deleted'     => true,
            ];
        }

        return [
            'object_type'   => $kind,
            'object_id'     => $object_id,
            'post_type'     => $post['post_type'],
            'post_modified' => $post['post_modified_gmt'],
            'data'          => $post,
            'meta'          => get_post_meta($object_id),
            'terms'         => $this->post_terms($object_id, $post['post_type']),
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
     * Resolve each exported post's attachment dependencies.
     *
     * Sources, in order of reliability: the featured image; parsed blocks
     * (so wp:video, wp:audio, wp:file and wp:media-text count, which a
     * regex over `wp:image|gallery|cover` never did); classic `wp-image-N`
     * markup; and the Elementor `_elementor_data` element tree, where
     * Elementor stores media as ['id' => N, 'url' => '...'] control values.
     * Elementor is a first-class target in this codebase, so an Elementor
     * page resolving zero attachments would be a broken page on the target.
     *
     * Ids that do not resolve to a local attachment (stale or pasted-in
     * references) are NOT emitted as phantom manifest entries with null
     * everything; they are returned as excluded rows so the gap is visible.
     *
     * @return array{0: array{attachments: array}, 1: array} manifest, unresolved
     */
    private function resolve_dependencies(array $objects): array
    {
        $referenced = [];

        foreach ($objects as $object) {
            if (! empty($object['deleted'])) {
                continue;
            }

            $id = (int) $object['object_id'];

            $thumb = (int) get_post_thumbnail_id($id);
            if ($thumb) {
                $referenced[$thumb] = true;
            }

            foreach ($this->content_attachment_ids((string) ($object['data']['post_content'] ?? '')) as $ref) {
                $referenced[$ref] = true;
            }
            foreach ($this->meta_attachment_ids((array) ($object['meta'] ?? [])) as $ref) {
                $referenced[$ref] = true;
            }

            // An exported attachment is its own dependency: the apply side
            // needs its bytes, not just its post row.
            if ('attachment' === $object['object_type'] || 'attachment' === ($object['post_type'] ?? '')) {
                $referenced[$id] = true;
            }
        }

        $attachments = [];
        $unresolved  = [];

        foreach (array_keys($referenced) as $id) {
            if ('attachment' !== get_post_type($id)) {
                $unresolved[] = [
                    'object_type'  => 'attachment',
                    'object_id'    => $id,
                    'operation_id' => '',
                    'tool_name'    => '',
                    'reason'       => 'referenced attachment does not exist locally; the target may render a broken image',
                ];
                continue;
            }

            $file = get_attached_file($id);
            $attachments[] = [
                'object_type' => 'attachment',
                'object_id'   => $id,
                'file'        => $file ? wp_basename($file) : null,
                'url'         => wp_get_attachment_url($id) ?: null,
                'checksum'    => $this->checksum($file ?: null),
                'mime_type'   => get_post_mime_type($id) ?: null,
            ];
        }

        // TODO(#192): resolve referenced templates/patterns (wp:pattern,
        // template part refs) and Elementor global classes used by exported
        // templates. Tracked in docs/wip/issue-192.md.
        return [['attachments' => $attachments], $unresolved];
    }

    /**
     * Checksum a dependency, or null with the file simply unhashed above the
     * cap: md5_file() on a multi-gigabyte upload would stall the request for
     * a field the target only uses to skip a re-transfer.
     */
    private function checksum(?string $file): ?string
    {
        if (! $file || ! is_readable($file)) {
            return null;
        }
        $size = @filesize($file);
        if (false === $size || $size > self::CHECKSUM_MAX_BYTES) {
            return null;
        }
        $hash = @md5_file($file);
        return false === $hash ? null : $hash;
    }

    /**
     * Attachment ids referenced from post_content: parsed block attributes
     * plus classic markup.
     *
     * @return int[]
     */
    private function content_attachment_ids(string $content): array
    {
        $ids = [];

        if ('' === trim($content)) {
            return $ids;
        }

        if (function_exists('parse_blocks') && false !== strpos($content, '<!-- wp:')) {
            $this->walk_blocks(parse_blocks($content), $ids);
        }

        // Classic content and block markup both stamp wp-image-N on the img.
        if (preg_match_all('/wp-image-(\d+)/', $content, $m)) {
            foreach ($m[1] as $id) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Media attributes across core's media blocks. `id` covers image/cover/
     * audio/video/file, `mediaId` covers media-text, `ids` covers gallery
     * (both the modern inner-block form and the legacy attribute form).
     *
     * @param array $blocks
     * @param int[] $ids
     */
    private function walk_blocks(array $blocks, array &$ids): void
    {
        foreach ($blocks as $block) {
            $attrs = (array) ($block['attrs'] ?? []);

            foreach (['id', 'mediaId'] as $key) {
                if (isset($attrs[$key]) && is_numeric($attrs[$key])) {
                    $ids[] = (int) $attrs[$key];
                }
            }
            if (isset($attrs['ids']) && is_array($attrs['ids'])) {
                foreach ($attrs['ids'] as $one) {
                    if (is_numeric($one)) {
                        $ids[] = (int) $one;
                    }
                }
            }

            if (! empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $this->walk_blocks($block['innerBlocks'], $ids);
            }
        }
    }

    /**
     * Attachment ids stored in postmeta by page builders. Elementor keeps
     * every media control as ['id' => N, 'url' => '...'] somewhere inside
     * the JSON element tree in _elementor_data, so an id sitting next to a
     * url is the shape to look for; it also covers the same convention in
     * other builders' meta without hard-coding each one's schema.
     *
     * @param array<string, array> $meta get_post_meta() output (raw)
     * @return int[]
     */
    private function meta_attachment_ids(array $meta): array
    {
        $ids = [];

        foreach ($meta as $key => $values) {
            if (0 === strpos((string) $key, '_edit_')) {
                continue;
            }
            foreach ((array) $values as $value) {
                if (! is_string($value) || '' === $value) {
                    continue;
                }
                if (false === strpos($value, '"id"') && false === strpos($value, '"url"')) {
                    continue;
                }
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $this->walk_media_tree($decoded, $ids);
                }
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * @param array $node
     * @param int[] $ids
     */
    private function walk_media_tree(array $node, array &$ids): void
    {
        if (isset($node['id'], $node['url']) && is_numeric($node['id']) && is_string($node['url'])) {
            $ids[] = (int) $node['id'];
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $this->walk_media_tree($child, $ids);
            }
        }
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
