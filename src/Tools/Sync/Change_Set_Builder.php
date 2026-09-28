<?php

namespace WPMCP\Tools\Sync;

use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Snapshot;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Elementor\Global_Classes_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Derive a change set from the snapshot ledger (issue #192, phase 1).
 *
 * The unit of local-live sync is not the database: it is a set of
 * explicitly selected objects. Every mutating tool in this plugin already
 * snapshots before writing (Safe_Mutation + Snapshot_Store), so the ledger
 * IS the change set: we read wpmcp_snapshots for a marker (a session id, an
 * operation id, or a ledger row id), dedupe to one entry per object, and
 * serialize the object's CURRENT state. An explicit `objects` list selects
 * objects directly (for example a page created by a tool that writes no
 * ledger row), alone or on top of a marker.
 *
 * The ledger also holds what a sync needs to be safe on the other side: the
 * before-image of the OLDEST row per object in the marker range is the
 * state the object was in before this build touched it, i.e. the base
 * revision. Each entry carries a content hash of that base and of its
 * current state (see Change_Set_Format::post_hash), which is what lets the
 * apply side tell "the live copy is still at the base, safe to update" from
 * "the live copy moved on too, refuse". An object whose current state
 * equals its base (edited and reverted) is flagged `unchanged` so it is
 * never pushed over live.
 *
 * Things this builder is deliberately careful about, because a change set
 * that lies is worse than no change set at all:
 *
 * 1. Truncation. Safe_Mutation prunes the ledger to the site's history
 *    limit after every write, so a long session's earliest rows may be
 *    gone. It reports `truncated` rather than a silently partial set.
 * 2. Under-reporting. Everything a ledger range touched that the change set
 *    will NOT carry is listed one row per ledger row in `excluded`.
 * 3. Policy vs. gap. Users, comments, orders, live-side post types and
 *    non-theme options are excluded BY DESIGN (they are exactly the
 *    live-side data a sync must never overwrite); anything else unhandled
 *    is reported as not implemented.
 * 4. Dependencies. A page that syncs without its media, its terms, the
 *    synced patterns and templates it embeds or the Elementor global
 *    classes it uses is a broken page. Each object lists what it `requires`
 *    so the apply side can refuse to push it half-resolved.
 */
class Change_Set_Builder
{
    /** Change-set artifact format version (see Change_Set_Format). */
    public const FORMAT_VERSION = Change_Set_Format::FORMAT_VERSION;

    /**
     * Hard cap on ledger rows read for one change set. A Pro history limit
     * is PHP_INT_MAX, so an unbounded `since_id` marker would otherwise read
     * the whole table. Hitting the cap is reported as truncation.
     */
    public const MAX_LEDGER_ROWS = 5000;

    /** Cap on explicitly selected objects. */
    public const MAX_SELECTED = 500;

    /** Cap on dependency posts (templates, patterns) resolved transitively. */
    public const MAX_DEPENDENCY_POSTS = 200;

    /** Above this size a dependency's checksum is skipped rather than hashing the file. */
    private const CHECKSUM_MAX_BYTES = 64 * 1024 * 1024;

    /** Default per-file cap on media carried inline as bytes. */
    private const INLINE_MEDIA_MAX_BYTES = 8 * 1024 * 1024;

    /** Cap on all inline media in one artifact; past it, media is listed without bytes. */
    private const INLINE_MEDIA_TOTAL_BYTES = 64 * 1024 * 1024;

    /**
     * Ledger object types excluded BY DESIGN: the live-side rows a sync
     * must never carry.
     */
    private const NON_SYNCABLE_BY_DESIGN = ['user', 'comment', 'wc_order', 'db_rows'];

    /**
     * Ledger object_type => export kind for post-backed rows. `page_build`
     * (Build_Page's composite snapshot) and `media_import` are post-backed
     * with a numeric id and must route to the post exporter, or the flagship
     * build tool produces a change set that omits exactly the pages it built.
     */
    private const POST_KINDS = [
        'post'         => 'post',
        'page_build'   => 'post',
        'post_create'  => 'post',
        'attachment'   => 'attachment',
        'media_import' => 'attachment',
    ];

    /** Ledger rows whose before-image is "did not exist yet". */
    private const CREATION_KINDS = ['page_build', 'media_import', 'post_create'];

    /**
     * Which block attribute holds an attachment id, per core media block.
     * Keyed by block name on purpose: `id` is not a media attribute in
     * general (core/navigation-link stores a post id there).
     */
    public const BLOCK_MEDIA_ATTRS = [
        'core/image'      => 'id',
        'core/cover'      => 'id',
        'core/audio'      => 'id',
        'core/video'      => 'id',
        'core/file'       => 'id',
        'core/media-text' => 'mediaId',
        'core/gallery'    => 'ids',
    ];

    /** Blocks that embed another post by id: block name => attribute. */
    public const BLOCK_POST_REFS = [
        'core/block'      => 'ref',
        'core/navigation' => 'ref',
    ];

    /** @var array<string, string> origin URL tokens, see Change_Set_Format::url_tokens() */
    private array $tokens = [];

    /** Bytes of media already inlined into the artifact being built. */
    private int $inline_total = 0;

    /**
     * Build the change set for a marker and/or an explicit selection.
     *
     * @param array $marker Any one of:
     *                      ['session_id'   => string] all rows in a session
     *                      ['operation_id' => string] rows written after that operation
     *                      ['since_id'     => int]    ledger rows with id > since_id
     *                      optionally with (or instead of)
     *                      ['objects' => string[]]    explicit refs: post:ID, option:NAME, term:TAXONOMY:SLUG
     * @throws \InvalidArgumentException On an unreadable object ref.
     * @throws \RuntimeException         On an unknown operation_id.
     */
    public function build(array $marker): array
    {
        $this->tokens       = Change_Set_Format::url_tokens(home_url(), site_url());
        $this->inline_total = 0;

        $selection = $this->parse_selection((array) ($marker['objects'] ?? []));
        $rows      = $this->ledger_rows($marker);

        $excluded = [];
        $oldest   = []; // key => ['row' => ledger row, 'snapshot' => array|null]
        $blobs    = []; // operation_id => decoded snapshot (or null)

        foreach ($rows as $row) {
            $type = (string) $row['object_type'];

            if (in_array($type, self::NON_SYNCABLE_BY_DESIGN, true)) {
                $excluded[] = $this->excluded_row($row, 'excluded by design: live-side data a sync must never overwrite');
                continue;
            }

            $key = null;
            if (Post_Creation_Snapshot::OBJECT_TYPE === $type) {
                // One creation row can cover several posts (duplicate-post
                // with include_children); every one of them is an object the
                // session touched.
                $snapshot = $this->blob($row, $blobs);
                $ids      = is_array($snapshot) ? Post_Creation_Snapshot::post_ids($snapshot) : [(int) $row['object_id']];
                foreach ($ids as $created_id) {
                    $oldest[ 'post:' . $created_id ] = $row;
                }
                continue;
            }
            if (isset(self::POST_KINDS[ $type ])) {
                $key = 'post:' . (int) $row['object_id'];
            } elseif ('option' === $type || 'term' === $type) {
                $snapshot = $this->blob($row, $blobs);
                $name     = is_array($snapshot) ? (string) ($snapshot['data']['name'] ?? ($snapshot['object_id'] ?? '')) : '';
                if ('option' === $type) {
                    if ('' === $name) {
                        $excluded[] = $this->excluded_row($row, 'the option name could not be read from the ledger');
                        continue;
                    }
                    if (! Change_Set_Format::is_syncable_option($name)) {
                        $excluded[] = $this->excluded_row($row, 'excluded by design: only theme mods are syncable options; the rest is site configuration', $name);
                        continue;
                    }
                    $key = 'option:' . $name;
                } else {
                    $name = is_array($snapshot) ? (string) ($snapshot['object_id'] ?? '') : '';
                    [$taxonomy, $slug] = Snapshot::split_term_key($name);
                    if ('' === $taxonomy || '' === $slug) {
                        $excluded[] = $this->excluded_row($row, 'the term could not be identified from the ledger');
                        continue;
                    }
                    $key = 'term:' . $taxonomy . ':' . $slug;
                }
            } else {
                $excluded[] = $this->excluded_row($row, 'not implemented in this slice');
                continue;
            }

            // Ledger is newest-first, so the last row seen per key is the
            // oldest in range: its before-image is the base revision.
            $oldest[ $key ] = $row;
        }

        $objects = [];
        foreach ($oldest as $key => $row) {
            $snapshot = $this->blob($row, $blobs);
            $entry    = $this->export($key, $row, $snapshot, $excluded);
            if (null !== $entry) {
                $objects[ $key ] = $entry;
            }
        }
        foreach ($selection as $key) {
            if (isset($objects[ $key ]) || isset($oldest[ $key ])) {
                continue;
            }
            $entry = $this->export($key, null, null, $excluded);
            if (null !== $entry) {
                $objects[ $key ] = $entry;
            }
        }

        $objects = $this->sorted_entries(array_values($objects));

        [$objects, $dependencies, $unresolved] = $this->resolve_dependencies($objects);
        $excluded = array_merge($excluded, $unresolved);

        $set = [
            'format_version' => self::FORMAT_VERSION,
            'origin'         => $this->origin(),
            'marker'         => $this->marker_record($marker, $selection),
            'objects'        => $objects,
            'dependencies'   => $dependencies,
            'excluded'       => $excluded,
            'truncated'      => $this->truncation_report($marker, count($rows)),
        ];
        $set['checksum'] = Change_Set_Format::checksum($set);

        return $set;
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

    /**
     * Per-file cap on media carried inline as bytes. A seam so tests can
     * exercise the over-cap path without writing an 8MB fixture.
     */
    protected function inline_media_max_bytes(): int
    {
        return self::INLINE_MEDIA_MAX_BYTES;
    }

    /**
     * The origin's Elementor global classes (id => item), or null when this
     * site has no Elementor v4 class manager. A seam for tests.
     *
     * @return array<string, array>|null
     */
    protected function global_class_items(): ?array
    {
        if (! class_exists(Global_Classes_Store::class) || ! Global_Classes_Store::is_supported()) {
            return null;
        }
        $state = Global_Classes_Store::read();
        return is_wp_error($state) ? null : (array) $state['items'];
    }

    /**
     * @param string[] $refs
     * @return string[] validated, de-duplicated object keys
     * @throws \InvalidArgumentException
     */
    private function parse_selection(array $refs): array
    {
        if (count($refs) > self::MAX_SELECTED) {
            throw new \InvalidArgumentException(sprintf('Select at most %d objects per change set.', (int) self::MAX_SELECTED));
        }

        $keys = [];
        foreach ($refs as $ref) {
            $ref = trim((string) $ref);
            if (preg_match('/^post:([1-9]\d*)$/', $ref, $m)) {
                $keys[] = 'post:' . (int) $m[1];
                continue;
            }
            if (preg_match('/^option:(.+)$/', $ref, $m) && Change_Set_Format::is_syncable_option($m[1])) {
                $keys[] = $ref;
                continue;
            }
            if (preg_match('/^term:([a-z0-9_-]+):(.+)$/', $ref, $m)) {
                $keys[] = $ref;
                continue;
            }
            throw new \InvalidArgumentException(sprintf(
                'Unreadable object ref "%s": use post:ID, option:theme_mods_THEME or term:TAXONOMY:SLUG.',
                esc_html($ref)
            ));
        }

        return array_values(array_unique($keys));
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
     * Decode one ledger row's before-image, cached per operation. An
     * undecodable blob yields null, which downgrades that object's base to
     * `unknown` (the apply side then refuses to overwrite it unless forced)
     * rather than failing the whole change set.
     */
    private function blob(?array $row, array &$cache): ?array
    {
        if (null === $row) {
            return null;
        }
        $op = (string) ($row['operation_id'] ?? '');
        if (! array_key_exists($op, $cache)) {
            try {
                $stored        = Snapshot_Store::get_by_operation($op);
                $cache[ $op ] = $stored ? (array) $stored['snapshot'] : null;
            } catch (\RuntimeException $e) {
                $cache[ $op ] = null;
            }
        }
        return $cache[ $op ];
    }

    /**
     * Is this change set provably incomplete? See the class docblock. A
     * session marker is judged by the per-session prune record, an id marker
     * by the surviving ledger floor.
     *
     * @return array{truncated:bool, reason:string|null, retention_floor:int|null, rows_read:int}
     */
    private function truncation_report(array $marker, int $rows_read): array
    {
        $floor  = Snapshot_Store::min_id();
        $reason = null;

        if (isset($marker['session_id'])) {
            $pruned = Snapshot_Store::pruned_rows_for_session((string) $marker['session_id']);
            if ($pruned > 0) {
                $reason = sprintf(
                    '%d ledger row(s) from this session have already been pruned from the history, so its earliest mutations are not in this change set.',
                    $pruned
                );
            }
        }

        if (null !== $reason) {
            // Fall through to the report.
        } elseif ($rows_read >= self::MAX_LEDGER_ROWS) {
            $reason = sprintf(
                'The ledger read hit the %d row cap, so older rows in this range were not examined.',
                self::MAX_LEDGER_ROWS
            );
        } elseif (! isset($marker['session_id']) && null !== $floor) {
            $marker_floor = null;
            try {
                $marker_floor = $this->marker_floor($marker);
            } catch (\RuntimeException $e) {
                $marker_floor = null;
            }
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
    private function excluded_row(array $row, string $reason, string $name = ''): array
    {
        $out = [
            'object_type'  => (string) $row['object_type'],
            'object_id'    => (int) $row['object_id'],
            'operation_id' => (string) ($row['operation_id'] ?? ''),
            'tool_name'    => (string) ($row['tool_name'] ?? ''),
            'reason'       => $reason,
        ];
        if ('' !== $name) {
            $out['name'] = $name;
        }
        return $out;
    }

    private function marker_record(array $marker, array $selection): array
    {
        $out = [];
        foreach (['session_id', 'operation_id', 'since_id'] as $key) {
            if (isset($marker[ $key ])) {
                $out[ $key ] = $marker[ $key ];
            }
        }
        if ([] !== $selection) {
            $out['objects'] = $selection;
        }
        return $out;
    }

    /**
     * Export one object by key. Returns null (with an excluded row) when the
     * object must not travel at all.
     *
     * @param array|null $row      oldest ledger row in range, null for an explicit selection
     * @param array|null $snapshot that row's decoded before-image
     */
    private function export(string $key, ?array $row, ?array $snapshot, array &$excluded): ?array
    {
        $parts = explode(':', $key, 2);
        $type  = $parts[0];
        $rest  = $parts[1] ?? '';

        if ('post' === $type) {
            $kind = $row ? (self::POST_KINDS[ (string) $row['object_type'] ] ?? 'post') : 'post';
            return $this->export_post((int) $rest, $kind, $row, $snapshot, $excluded);
        }
        if ('option' === $type) {
            return $this->export_option($rest, $row, $snapshot);
        }
        [$taxonomy, $slug] = Snapshot::split_term_key($rest);
        return $this->export_term($taxonomy, $slug, $row, $snapshot);
    }

    /**
     * Export one post's CURRENT state plus its base revision.
     *
     * An object that no longer exists locally, or sits in the trash, is
     * returned with `deleted` set rather than dropped: deletions are
     * reported, never applied automatically, so the apply side has to see
     * them. A trashed post must never be published on the target.
     */
    private function export_post(int $post_id, string $kind, ?array $row, ?array $snapshot, array &$excluded, string $role = 'object'): ?array
    {
        $key  = 'post:' . $post_id;
        $post = get_post($post_id, ARRAY_A);

        if (! $post) {
            return ['key' => $key, 'object_type' => $kind, 'object_id' => $post_id, 'deleted' => true];
        }

        $post_type = (string) $post['post_type'];
        $kind      = 'attachment' === $post_type ? 'attachment' : 'post';

        if (! Change_Set_Format::is_syncable_post_type($post_type)) {
            $excluded[] = [
                'object_type'  => $kind,
                'object_id'    => $post_id,
                'operation_id' => (string) ($row['operation_id'] ?? ''),
                'tool_name'    => (string) ($row['tool_name'] ?? ''),
                'reason'       => sprintf('excluded by design: %s posts are live-side data a sync must never overwrite', $post_type),
            ];
            return null;
        }

        if ('trash' === $post['post_status']) {
            return ['key' => $key, 'object_type' => $kind, 'object_id' => $post_id, 'post_type' => $post_type, 'deleted' => true, 'trashed' => true];
        }

        $data = [];
        foreach (Change_Set_Format::POST_FIELDS as $field) {
            $data[ $field ] = $post[ $field ] ?? '';
        }
        $data['post_parent'] = (int) $data['post_parent'];
        $data['menu_order']  = (int) $data['menu_order'];

        $meta  = $this->filter_meta((array) get_post_meta($post_id));
        $terms = $this->post_terms($post_id, $post_type);

        // Base revision.
        $base_state = 'unknown';
        $base_post  = null;
        $base_meta  = [];
        $base_terms = [];
        if (null !== $row && null !== $snapshot) {
            $creation = in_array((string) $row['object_type'], self::CREATION_KINDS, true)
                || in_array((string) ($snapshot['object_type'] ?? ''), self::CREATION_KINDS, true);
            if ($creation || empty($snapshot['data']['post'])) {
                $base_state = 'absent';
            } else {
                $base_state = 'present';
                $base_post  = (array) $snapshot['data']['post'];
                $base_meta  = $this->filter_meta((array) ($snapshot['data']['meta'] ?? []));
                $base_terms = $this->term_ids_to_slugs((array) ($snapshot['data']['terms'] ?? []));
            }
        }

        $meta_keys = array_values(array_unique(array_merge(array_keys($meta), array_keys($base_meta))));
        sort($meta_keys, SORT_STRING);
        $taxonomies = array_values(array_unique(array_merge(array_keys($terms), array_keys($base_terms))));
        sort($taxonomies, SORT_STRING);
        $removed = array_values(array_diff(array_keys($base_meta), array_keys($meta)));
        sort($removed, SORT_STRING);

        $hash = Change_Set_Format::post_hash($post, $meta, $terms, $meta_keys, $taxonomies, $this->tokens);
        $base = ['state' => $base_state];
        if ('present' === $base_state) {
            $base['hash']          = Change_Set_Format::post_hash($base_post, $base_meta, $base_terms, $meta_keys, $taxonomies, $this->tokens);
            $base['post_modified'] = (string) ($base_post['post_modified_gmt'] ?? '');
        }

        $entry = [
            'key'               => $key,
            'object_type'       => $kind,
            'object_id'         => $post_id,
            'role'              => $role,
            'post_type'         => $post_type,
            'post_modified'     => (string) $post['post_modified_gmt'],
            'data'              => $data,
            'meta'              => $meta,
            'terms'             => $terms,
            'hash_scope'        => ['meta_keys' => $meta_keys, 'taxonomies' => $taxonomies],
            'removed_meta_keys' => $removed,
            'parent'            => $this->parent_ref((int) $post['post_parent']),
            'hash'              => $hash,
            'base'              => $base,
            'unchanged'         => 'present' === $base_state && $base['hash'] === $hash,
            'requires'          => [],
        ];

        return $entry;
    }

    /** Identity of a post's parent, so the target can find it under whatever id it has there. */
    private function parent_ref(int $parent_id): ?array
    {
        if ($parent_id <= 0) {
            return null;
        }
        $parent = get_post($parent_id);
        if (! $parent) {
            return null;
        }
        return [
            'object_id'     => $parent_id,
            'post_type'     => $parent->post_type,
            'post_name'     => $parent->post_name,
            'post_date_gmt' => $parent->post_date_gmt,
        ];
    }

    /**
     * Export a syncable option (theme mods) as a per-key change: the keys
     * whose value differs between the base and now. The apply side merges
     * exactly those keys, so a live-side change to a different theme mod is
     * kept rather than overwritten by the whole local array.
     */
    private function export_option(string $name, ?array $row, ?array $snapshot): array
    {
        $key     = 'option:' . $name;
        $missing = '__wpmcp_missing__' . $name;
        $value   = get_option($name, $missing);

        if ($missing === $value) {
            return ['key' => $key, 'object_type' => 'option', 'name' => $name, 'deleted' => true];
        }
        $value = is_array($value) ? $value : [];

        $base_state = 'unknown';
        $base_value = null;
        if (null !== $row && null !== $snapshot) {
            $existed    = ! empty($snapshot['data']['existed']);
            $base_state = $existed ? 'present' : 'absent';
            $base_value = $existed && is_array($snapshot['data']['value'] ?? null) ? $snapshot['data']['value'] : [];
        }

        $changed = [];
        $removed = [];
        if ('unknown' === $base_state) {
            $changed = array_map('strval', array_keys($value));
        } else {
            foreach (array_unique(array_merge(array_keys($value), array_keys((array) $base_value))) as $k) {
                $k = (string) $k;
                if (! array_key_exists($k, $value)) {
                    $removed[] = $k;
                    $changed[] = $k;
                    continue;
                }
                $was = array_key_exists($k, (array) $base_value) ? $base_value[ $k ] : null;
                if (
                    ! array_key_exists($k, (array) $base_value)
                    || Change_Set_Format::hash(Change_Set_Format::tokenize($was, $this->tokens)) !== Change_Set_Format::hash(Change_Set_Format::tokenize($value[ $k ], $this->tokens))
                ) {
                    $changed[] = $k;
                }
            }
        }
        sort($changed, SORT_STRING);
        sort($removed, SORT_STRING);

        return [
            'key'          => $key,
            'object_type'  => 'option',
            'name'         => $name,
            'value'        => $value,
            'changed_keys' => array_values(array_unique($changed)),
            'removed_keys' => $removed,
            'base'         => ['state' => $base_state, 'value' => $base_value],
            'unchanged'    => 'unknown' !== $base_state && [] === $changed,
            'requires'     => [],
        ];
    }

    /** Export a taxonomy term the ledger (or the selection) names. */
    private function export_term(string $taxonomy, string $slug, ?array $row, ?array $snapshot): array
    {
        $key  = 'term:' . $taxonomy . ':' . $slug;
        $term = ('' !== $taxonomy && '' !== $slug) ? get_term_by('slug', $slug, $taxonomy) : false;

        if (! $term instanceof \WP_Term) {
            return ['key' => $key, 'object_type' => 'term', 'taxonomy' => $taxonomy, 'slug' => $slug, 'deleted' => true];
        }

        $data = Change_Set_Format::term_projection($term->name, $term->description, (int) $term->parent, $taxonomy);

        $base_state = 'unknown';
        $base_hash  = null;
        if (null !== $row && null !== $snapshot) {
            if (empty($snapshot['data']['existed']) || ! is_array($snapshot['data']['term'] ?? null)) {
                $base_state = 'absent';
            } else {
                $was        = $snapshot['data']['term'];
                $base_state = 'present';
                $base_hash  = Change_Set_Format::hash(Change_Set_Format::tokenize(
                    Change_Set_Format::term_projection((string) ($was['name'] ?? ''), (string) ($was['description'] ?? ''), (int) ($was['parent'] ?? 0), $taxonomy),
                    $this->tokens
                ));
            }
        }

        $hash = Change_Set_Format::hash(Change_Set_Format::tokenize($data, $this->tokens));
        $base = ['state' => $base_state];
        if (null !== $base_hash) {
            $base['hash'] = $base_hash;
        }

        return [
            'key'         => $key,
            'object_type' => 'term',
            'taxonomy'    => $taxonomy,
            'slug'        => $slug,
            'data'        => $data,
            'hash'        => $hash,
            'base'        => $base,
            'unchanged'   => 'present' === $base_state && $base_hash === $hash,
            'requires'    => [],
        ];
    }

    /**
     * Non-volatile meta, key-sorted, values as raw strings.
     *
     * @param array<string, array> $meta
     * @return array<string, string[]>
     */
    private function filter_meta(array $meta): array
    {
        $out = [];
        foreach ($meta as $key => $values) {
            $key = (string) $key;
            if (Change_Set_Format::is_volatile_meta($key)) {
                continue;
            }
            $out[ $key ] = array_values(array_map(static fn ($v) => is_scalar($v) ? (string) $v : (string) maybe_serialize($v), (array) $values));
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @return array<string, string[]> taxonomy => sorted slugs, every taxonomy of the type (empty lists kept, so "removed all terms" travels) */
    private function post_terms(int $post_id, string $post_type): array
    {
        $out = [];
        foreach (get_object_taxonomies($post_type) as $taxonomy) {
            $terms = get_the_terms($post_id, $taxonomy);
            $slugs = is_array($terms) ? array_values(array_map('strval', wp_list_pluck($terms, 'slug'))) : [];
            sort($slugs, SORT_STRING);
            $out[ $taxonomy ] = $slugs;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * A snapshot records term ids; hashes compare slugs, since ids are
     * site-local. An id that no longer resolves is kept as "#id" so a base
     * with a since-deleted term still differs from one without it.
     *
     * @param array<string, int[]> $terms
     * @return array<string, string[]>
     */
    private function term_ids_to_slugs(array $terms): array
    {
        $out = [];
        foreach ($terms as $taxonomy => $ids) {
            $slugs = [];
            foreach ((array) $ids as $id) {
                $term    = get_term((int) $id, (string) $taxonomy);
                $slugs[] = $term instanceof \WP_Term ? $term->slug : '#' . (int) $id;
            }
            sort($slugs, SORT_STRING);
            $out[ (string) $taxonomy ] = $slugs;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * Stable order: terms, then posts (attachments included) by numeric id,
     * then options by name. Ledger order is not stable across builds.
     */
    private function sorted_entries(array $entries): array
    {
        $rank = ['term' => 0, 'post' => 1, 'option' => 2];
        usort($entries, static function (array $a, array $b) use ($rank): int {
            $ka = explode(':', (string) $a['key'], 2);
            $kb = explode(':', (string) $b['key'], 2);
            $ra = $rank[ $ka[0] ] ?? 9;
            $rb = $rank[ $kb[0] ] ?? 9;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            if ('post' === $ka[0]) {
                return (int) ($ka[1] ?? 0) <=> (int) ($kb[1] ?? 0);
            }
            return strcmp((string) $a['key'], (string) $b['key']);
        });
        return $entries;
    }

    /**
     * Resolve every exported object's dependencies, transitively through the
     * templates and patterns it embeds.
     *
     * - attachments: featured image, core media blocks, classic wp-image-N,
     *   and media controls anywhere in page-builder JSON meta; carried as
     *   bytes (within caps) so the change set is self-contained.
     * - terms: every term an object is filed under, with its parent chain.
     * - posts: synced patterns (core/block ref), navigation menus
     *   (core/navigation ref), database template parts (core/template-part),
     *   and Elementor templates (template_id / templateID in the element
     *   tree). Exported in full, role "dependency".
     * - elementor_global_classes: the class definitions an Elementor element
     *   tree references.
     * - external: theme or plugin patterns and template parts that live in
     *   files, not the database; listed so the operator can check the target
     *   has them.
     *
     * Referenced ids that do not exist locally are reported under excluded,
     * never emitted as phantom manifest entries.
     *
     * @return array{0: array, 1: array, 2: array} [objects with requires, dependencies, unresolved]
     */
    private function resolve_dependencies(array $objects): array
    {
        $attachments = [];
        $terms       = [];
        $dep_posts   = [];
        $classes     = [];
        $external    = [];
        $unresolved  = [];

        $object_keys = [];
        foreach ($objects as $object) {
            $object_keys[ $object['key'] ] = true;
        }

        $class_items = null;
        $class_read  = false;

        // Work queue of [list, index] so dependency posts are scanned too.
        $queue = [];
        foreach (array_keys($objects) as $i) {
            $queue[] = ['objects', $i];
        }

        $excluded = [];
        while ([] !== $queue) {
            [$list, $i] = array_shift($queue);
            $entry      = 'objects' === $list ? $objects[ $i ] : $dep_posts[ $i ];

            if (! empty($entry['deleted'])) {
                continue;
            }

            $requires = [];

            if ('option' === $entry['object_type']) {
                $this->option_dependencies($entry, $attachments, $terms, $requires, $unresolved);
            } elseif ('term' === $entry['object_type']) {
                if (null !== ($entry['data']['parent'] ?? null)) {
                    $this->add_term_chain((string) $entry['taxonomy'], (string) $entry['data']['parent'], $terms);
                }
            } else {
                $post_id = (int) $entry['object_id'];
                $content = (string) ($entry['data']['post_content'] ?? '');
                $meta    = (array) ($entry['meta'] ?? []);

                // Attachments.
                $media = $this->content_attachment_ids($content);
                foreach ($this->meta_attachment_ids($meta) as $ref) {
                    $media[] = $ref;
                }
                $thumb = (int) ($meta['_thumbnail_id'][0] ?? 0);
                if ($thumb > 0) {
                    $media[] = $thumb;
                }
                if ('attachment' === $entry['object_type']) {
                    $media[] = $post_id;
                }
                foreach (array_unique($media) as $ref) {
                    if ($this->add_attachment((int) $ref, $attachments, $unresolved)) {
                        $requires[] = 'attachment:' . (int) $ref;
                    }
                }

                // Terms.
                foreach ((array) ($entry['terms'] ?? []) as $taxonomy => $slugs) {
                    foreach ((array) $slugs as $slug) {
                        $this->add_term_chain((string) $taxonomy, (string) $slug, $terms);
                    }
                }

                // Templates, patterns, navigation.
                [$post_refs, $parts, $patterns] = $this->content_post_refs($content);
                $tree                           = $this->elementor_tree($meta);
                [$tree_templates, $class_ids]   = $this->tree_refs($tree);
                $post_refs                      = array_merge($post_refs, $tree_templates);

                foreach ($parts as $part) {
                    $found = $this->template_part_id($part['slug'], $part['theme']);
                    if ($found > 0) {
                        $post_refs[] = $found;
                    } else {
                        $external[] = ['kind' => 'template_part', 'ref' => ('' !== $part['theme'] ? $part['theme'] . '//' : '') . $part['slug']];
                    }
                }
                foreach ($patterns as $pattern) {
                    $external[] = ['kind' => 'pattern', 'ref' => $pattern];
                }

                foreach (array_unique($post_refs) as $ref) {
                    $ref = (int) $ref;
                    if ($ref <= 0 || $ref === $post_id) {
                        continue;
                    }
                    $dep_key = 'post:' . $ref;
                    if (isset($object_keys[ $dep_key ])) {
                        continue; // Selected in its own right; applied as an object.
                    }
                    if (! isset($dep_posts[ $dep_key ])) {
                        if (count($dep_posts) >= self::MAX_DEPENDENCY_POSTS) {
                            $unresolved[] = $this->unresolved('post', $ref, sprintf('dependency limit of %d posts reached; this reference was not resolved', self::MAX_DEPENDENCY_POSTS));
                            continue;
                        }
                        $dep = $this->export_post($ref, 'post', null, null, $excluded, 'dependency');
                        if (null === $dep || ! empty($dep['deleted'])) {
                            $unresolved[] = $this->unresolved('post', $ref, 'referenced template or pattern does not exist locally (or is not syncable); the target may render it broken');
                            continue;
                        }
                        $dep_posts[ $dep_key ] = $dep;
                        $queue[]               = ['deps', $dep_key];
                    }
                    $requires[] = $dep_key;
                }

                // Elementor global classes.
                if ([] !== $class_ids) {
                    if (! $class_read) {
                        $class_items = $this->global_class_items();
                        $class_read  = true;
                    }
                    foreach ($class_ids as $class_id) {
                        if (is_array($class_items) && isset($class_items[ $class_id ])) {
                            $classes[ $class_id ] = $class_items[ $class_id ];
                            $requires[]           = 'global_class:' . $class_id;
                        } elseif (null === $class_items && str_starts_with($class_id, 'g-')) {
                            $unresolved[] = $this->unresolved('elementor_global_class', 0, sprintf('global class %s is referenced but this site exposes no Elementor global classes', $class_id));
                        }
                    }
                }
            }

            $requires = array_values(array_unique($requires));
            sort($requires, SORT_STRING);
            if ('objects' === $list) {
                $objects[ $i ]['requires'] = $requires;
            } else {
                $dep_posts[ $i ]['requires'] = $requires;
            }
        }

        ksort($attachments, SORT_NUMERIC);
        ksort($terms, SORT_STRING);
        ksort($classes, SORT_STRING);
        $dep_list = $this->sorted_entries(array_values($dep_posts));

        $ext = [];
        foreach ($external as $item) {
            $ext[ $item['kind'] . "\0" . $item['ref'] ] = $item;
        }
        ksort($ext, SORT_STRING);

        return [
            $objects,
            [
                'attachments'              => array_values($attachments),
                'terms'                    => array_values($terms),
                'posts'                    => $dep_list,
                'elementor_global_classes' => $classes,
                'external'                 => array_values($ext),
            ],
            array_merge($excluded, $unresolved),
        ];
    }

    /** Theme mods that point at other objects: the logo attachment and the menus assigned to locations. */
    private function option_dependencies(array $entry, array &$attachments, array &$terms, array &$requires, array &$unresolved): void
    {
        $changed = array_flip((array) ($entry['changed_keys'] ?? []));
        $value   = (array) ($entry['value'] ?? []);

        if (isset($changed['custom_logo']) && (int) ($value['custom_logo'] ?? 0) > 0) {
            if ($this->add_attachment((int) $value['custom_logo'], $attachments, $unresolved)) {
                $requires[] = 'attachment:' . (int) $value['custom_logo'];
            }
        }
        if (isset($changed['nav_menu_locations']) && is_array($value['nav_menu_locations'] ?? null)) {
            foreach ($value['nav_menu_locations'] as $term_id) {
                $menu = get_term((int) $term_id, 'nav_menu');
                if ($menu instanceof \WP_Term) {
                    $this->add_term_chain('nav_menu', $menu->slug, $terms);
                }
            }
        }
    }

    private function unresolved(string $type, int $id, string $reason): array
    {
        return [
            'object_type'  => $type,
            'object_id'    => $id,
            'operation_id' => '',
            'tool_name'    => '',
            'reason'       => $reason,
        ];
    }

    /** Add a term and its ancestors to the dependency map (keyed term:taxonomy:slug). */
    private function add_term_chain(string $taxonomy, string $slug, array &$terms): void
    {
        $guard = 0;
        while ('' !== $slug && $guard++ < 32) {
            $key = 'term:' . $taxonomy . ':' . $slug;
            if (isset($terms[ $key ])) {
                return;
            }
            $term = get_term_by('slug', $slug, $taxonomy);
            if (! $term instanceof \WP_Term) {
                return;
            }
            $parent_slug = null;
            if ((int) $term->parent > 0) {
                $parent      = get_term((int) $term->parent, $taxonomy);
                $parent_slug = $parent instanceof \WP_Term ? $parent->slug : null;
            }
            $terms[ $key ] = [
                'key'         => $key,
                'taxonomy'    => $taxonomy,
                'slug'        => $term->slug,
                'name'        => $term->name,
                'description' => $term->description,
                'parent'      => $parent_slug,
                'term_id'     => (int) $term->term_id,
            ];
            $slug = (string) $parent_slug;
        }
    }

    /**
     * Add one attachment to the manifest, with its bytes when they fit.
     *
     * @return bool false when the id is not a local attachment (reported as unresolved)
     */
    private function add_attachment(int $id, array &$attachments, array &$unresolved): bool
    {
        if (isset($attachments[ $id ])) {
            return true;
        }
        if ($id <= 0 || 'attachment' !== get_post_type($id)) {
            $unresolved[] = $this->unresolved('attachment', $id, 'referenced attachment does not exist locally; the target may render a broken image');
            return false;
        }

        $post     = get_post($id, ARRAY_A);
        $file     = get_attached_file($id);
        $relative = (string) get_post_meta($id, '_wp_attached_file', true);
        $size     = ($file && is_readable($file)) ? (int) @filesize($file) : 0;

        $bytes   = null;
        $omitted = null;
        if (! $file || ! is_readable($file)) {
            $omitted = 'the file is missing on the origin';
        } elseif ($size > $this->inline_media_max_bytes()) {
            $omitted = sprintf('the file is over the %d byte inline cap', $this->inline_media_max_bytes());
        } elseif ($this->inline_total + $size > self::INLINE_MEDIA_TOTAL_BYTES) {
            $omitted = sprintf('the change set reached its %d byte inline media cap', self::INLINE_MEDIA_TOTAL_BYTES);
        } else {
            $raw = file_get_contents($file);
            if (false === $raw) {
                $omitted = 'the file could not be read on the origin';
            } else {
                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- carries media bytes inside a JSON artifact; not obfuscation.
                $bytes               = base64_encode($raw);
                $this->inline_total += $size;
            }
        }

        $alt = get_post_meta($id, '_wp_attachment_image_alt', true);

        $attachments[ $id ] = [
            'key'           => 'attachment:' . $id,
            'object_type'   => 'attachment',
            'object_id'     => $id,
            'relative_path' => $relative,
            'file'          => $file ? wp_basename($file) : null,
            'url'           => wp_get_attachment_url($id) ?: null,
            'checksum'      => $this->checksum($file ?: null),
            'size'          => $size,
            'mime_type'     => get_post_mime_type($id) ?: null,
            'post'          => [
                'post_title'     => (string) $post['post_title'],
                'post_excerpt'   => (string) $post['post_excerpt'],
                'post_content'   => (string) $post['post_content'],
                'post_name'      => (string) $post['post_name'],
                'post_date'      => (string) $post['post_date'],
                'post_date_gmt'  => (string) $post['post_date_gmt'],
                'post_mime_type' => (string) $post['post_mime_type'],
            ],
            'alt'           => is_string($alt) ? $alt : '',
            'bytes'         => $bytes,
            'bytes_omitted' => $omitted,
        ];
        return true;
    }

    /**
     * Checksum a dependency, or null above the cap: md5_file() on a
     * multi-gigabyte upload would stall the request for a field the target
     * only uses to skip a re-transfer.
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

        if (preg_match_all('/wp-image-(\d+)/', $content, $m)) {
            foreach ($m[1] as $id) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * @param array $blocks
     * @param int[] $ids
     */
    private function walk_blocks(array $blocks, array &$ids): void
    {
        foreach ($blocks as $block) {
            $attrs = (array) ($block['attrs'] ?? []);
            $key   = self::BLOCK_MEDIA_ATTRS[ (string) ($block['blockName'] ?? '') ] ?? null;

            if (null !== $key && isset($attrs[ $key ])) {
                foreach ((array) $attrs[ $key ] as $one) {
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
     * Posts, template parts and theme patterns a block tree embeds.
     *
     * @return array{0: int[], 1: array<int, array{slug:string, theme:string}>, 2: string[]}
     */
    private function content_post_refs(string $content): array
    {
        $posts    = [];
        $parts    = [];
        $patterns = [];
        if ('' === trim($content) || ! function_exists('parse_blocks') || false === strpos($content, '<!-- wp:')) {
            return [$posts, $parts, $patterns];
        }

        $walk = function (array $blocks) use (&$walk, &$posts, &$parts, &$patterns): void {
            foreach ($blocks as $block) {
                $name  = (string) ($block['blockName'] ?? '');
                $attrs = (array) ($block['attrs'] ?? []);
                if (isset(self::BLOCK_POST_REFS[ $name ]) && is_numeric($attrs[ self::BLOCK_POST_REFS[ $name ] ] ?? null)) {
                    $posts[] = (int) $attrs[ self::BLOCK_POST_REFS[ $name ] ];
                }
                if ('core/template-part' === $name && ! empty($attrs['slug'])) {
                    $parts[] = ['slug' => (string) $attrs['slug'], 'theme' => (string) ($attrs['theme'] ?? '')];
                }
                if ('core/pattern' === $name && ! empty($attrs['slug'])) {
                    $patterns[] = (string) $attrs['slug'];
                }
                if (! empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                    $walk($block['innerBlocks']);
                }
            }
        };
        $walk(parse_blocks($content));

        return [$posts, $parts, array_values(array_unique($patterns))];
    }

    /** A template part customized in the database (wp_template_part post), or 0 if it only lives in theme files. */
    private function template_part_id(string $slug, string $theme): int
    {
        $theme = '' !== $theme ? $theme : get_stylesheet();
        $found = get_posts([
            'post_type'      => 'wp_template_part',
            'name'           => $slug,
            'post_status'    => ['publish', 'draft', 'private'],
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one-row lookup of a customized template part by its theme term.
            'tax_query'      => [[
                'taxonomy' => 'wp_theme',
                'field'    => 'name',
                'terms'    => $theme,
            ]],
        ]);
        return $found ? (int) $found[0] : 0;
    }

    /** Decoded `_elementor_data` element tree, or [] when the post has none. */
    private function elementor_tree(array $meta): array
    {
        $raw = (string) ($meta['_elementor_data'][0] ?? '');
        if ('' === $raw) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Elementor templates (template_id / templateID) and class ids
     * (settings.classes.value) anywhere in an element tree.
     *
     * @return array{0: int[], 1: string[]}
     */
    private function tree_refs(array $tree): array
    {
        $templates = [];
        $classes   = [];

        $walk = function (array $node, int $depth) use (&$walk, &$templates, &$classes): void {
            if ($depth > 64) {
                return;
            }
            foreach (['template_id', 'templateID'] as $k) {
                if (isset($node[ $k ]) && is_numeric($node[ $k ]) && (int) $node[ $k ] > 0) {
                    $templates[] = (int) $node[ $k ];
                }
            }
            if (isset($node['classes']['value']) && is_array($node['classes']['value'])) {
                foreach ($node['classes']['value'] as $class_id) {
                    if (is_string($class_id) && '' !== $class_id) {
                        $classes[] = $class_id;
                    }
                }
            }
            foreach ($node as $child) {
                if (is_array($child)) {
                    $walk($child, $depth + 1);
                }
            }
        };
        $walk($tree, 0);

        return [array_values(array_unique($templates)), array_values(array_unique($classes))];
    }

    /**
     * Attachment ids stored in postmeta by page builders: every media
     * control Elementor stores is ['id' => N, 'url' => '...'] somewhere in
     * the JSON element tree.
     *
     * @param array<string, array> $meta raw meta
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
    private function walk_media_tree(array $node, array &$ids, int $depth = 0): void
    {
        if ($depth > 64) {
            return;
        }
        if (isset($node['id'], $node['url']) && is_numeric($node['id']) && is_string($node['url'])) {
            $ids[] = (int) $node['id'];
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $this->walk_media_tree($child, $ids, $depth + 1);
            }
        }
    }

    /** Origin descriptor the apply side needs for URL rewriting. */
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
