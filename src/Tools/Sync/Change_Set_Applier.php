<?php

namespace WPMCP\Tools\Sync;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Save_Filters;
use WPMCP\Safety\Snapshot;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Elementor\Global_Classes_Store;
use WPMCP\Tools\Media\Media_Import_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Apply a change set to the site this runs on (issue #192, phases 2 and 3).
 *
 * A sync is not a migration. The target is a live site with data the
 * origin has never seen, so this class is built around three guarantees:
 *
 * 1. Only what the change set selected is ever overwritten. It iterates the
 *    artifact's entries and nothing else; an object is matched on the
 *    target by id AND identity (post type plus creation date), so an id
 *    that belongs to a different object on the target is never written to:
 *    the incoming object is created under a new id instead, and references
 *    to it (featured image, image blocks, wp-image-N, Elementor media
 *    controls and template ids, menu item targets) are remapped.
 *    Dependencies (media, terms, templates, global classes) are only ever
 *    ADDED; one that already exists on the target is reused as is, never
 *    rewritten. Deletions are reported, never applied.
 *
 * 2. No silent last-writer-wins. Each object carries a content hash of its
 *    base revision (its state before the build session touched it) and of
 *    its new state. The same hash computed on the target decides: equal to
 *    the new state means already in sync (skipped); equal to the base means
 *    the target has not moved since the base (applied); anything else means
 *    both sides changed (conflicted, nothing written) unless the operator
 *    names that object in `force`. Theme mods are merged per key on the
 *    same rule, so a live-side change to a different key is kept.
 *
 * 3. Every write is undoable. Updates run through Safe_Mutation (the
 *    target object is snapshotted before it is touched); creations record a
 *    creation row (page_build / media_import / term) whose rollback deletes
 *    exactly what was created. Every write in one apply shares one
 *    session_id, so rollback-session undoes the whole sync.
 *
 * Dry run (the default at the tool layer) runs the same decisions and
 * writes nothing.
 */
class Change_Set_Applier
{
    /** Post types applied before ordinary content, so references to them can be remapped. */
    private const TEMPLATE_TYPES = ['wp_block', 'wp_template_part', 'wp_template', 'wp_navigation', 'elementor_library', 'wp_global_styles'];

    private const ZERO_DATE = '0000-00-00 00:00:00';

    private bool $dry = true;

    /** @var array<string, true> */
    private array $force = [];

    private string $session = '';

    private array $origin = [];

    private string $home = '';

    private string $site = '';

    /** @var array<string, string> */
    private array $origin_tokens = [];

    /** @var array<string, string> */
    private array $target_tokens = [];

    /** @var array<int, int> origin attachment id => target id */
    private array $attachment_map = [];

    /** @var array<int, int> origin post id => target id */
    private array $post_map = [];

    /** @var array<int, int> origin term id => target term id */
    private array $term_map = [];

    /** @var array<string, bool> dependency key => usable on the target */
    private array $available = [];

    /** @var array<string, array> dependency key => creation outcome, for objects that are also dependencies */
    private array $created = [];

    private array $dep_results = [];

    private array $obj_results = [];

    private array $warnings = [];

    /**
     * @param array $set  A change-set artifact (validated here, never trusted).
     * @param array $opts dry_run (bool, default true), force (string[] object keys), session_id (string)
     * @throws \RuntimeException When the artifact is not a valid, intact change set.
     */
    public function apply(array $set, array $opts = []): array
    {
        Change_Set_Format::validate($set);

        $this->dry     = ! array_key_exists('dry_run', $opts) || (bool) $opts['dry_run'];
        $this->force   = array_fill_keys(array_map('strval', (array) ($opts['force'] ?? [])), true);
        $this->session = $this->session_id((string) ($opts['session_id'] ?? ''));
        $this->origin  = (array) $set['origin'];
        $this->home    = home_url();
        $this->site    = site_url();

        $this->origin_tokens = Change_Set_Format::url_tokens((string) ($this->origin['home_url'] ?? ''), (string) ($this->origin['site_url'] ?? ''));
        $this->target_tokens = Change_Set_Format::url_tokens($this->home, $this->site);

        $deps = (array) $set['dependencies'];

        $this->apply_dependency_terms((array) ($deps['terms'] ?? []));
        foreach ((array) ($deps['attachments'] ?? []) as $attachment) {
            if (is_array($attachment)) {
                $this->apply_attachment($attachment);
            }
        }
        $this->apply_global_classes((array) ($deps['elementor_global_classes'] ?? []));
        $this->apply_dependency_posts((array) ($deps['posts'] ?? []));

        foreach ($this->object_order((array) $set['objects']) as $entry) {
            if ('option' === $entry['object_type']) {
                $this->apply_option($entry);
            } elseif ('term' === $entry['object_type']) {
                $this->apply_term_object($entry);
            } else {
                $this->apply_post_object($entry);
            }
        }

        return $this->report($set);
    }

    /**
     * The target's Elementor global class state, or null when this site has
     * no v4 class manager. A seam for tests.
     *
     * @return array{kit_id:int, items:array, order:array}|null
     */
    protected function global_classes_state(): ?array
    {
        if (! class_exists(Global_Classes_Store::class) || ! Global_Classes_Store::is_supported()) {
            return null;
        }
        $state = Global_Classes_Store::read();
        return is_wp_error($state) ? null : $state;
    }

    /**
     * Persist an extended class set, snapshot-first (Global_Classes_Store
     * records the complete prior set so rollback restores it). A seam for tests.
     *
     * @return array|\WP_Error
     */
    protected function write_global_classes(array $before, array $items, array $order)
    {
        return Global_Classes_Store::write($before, $items, $order, 'apply-change-set', ['session_id' => $this->session]);
    }

    /**
     * Snapshot_Store keeps session_id in a CHAR(36); anything else would be
     * truncated into a session nobody can roll back by name.
     */
    private function session_id(string $given): string
    {
        $given = trim($given);
        if ('' !== $given && strlen($given) <= 36 && preg_match('/^[A-Za-z0-9._:-]+$/', $given)) {
            return $given;
        }
        return wp_generate_uuid4();
    }

    // ------------------------------------------------------------------
    // Dependencies: only ever added, never rewritten.
    // ------------------------------------------------------------------

    /** Terms, parents first, created only when missing. */
    private function apply_dependency_terms(array $terms): void
    {
        $pending = [];
        foreach ($terms as $term) {
            if (is_array($term) && ! empty($term['key'])) {
                $pending[ (string) $term['key'] ] = $term;
            }
        }

        $guard = 0;
        while ([] !== $pending && $guard++ < 64) {
            $progress = false;
            foreach ($pending as $key => $term) {
                $parent_key = null !== ($term['parent'] ?? null) ? 'term:' . $term['taxonomy'] . ':' . $term['parent'] : null;
                if (null !== $parent_key && isset($pending[ $parent_key ])) {
                    continue; // Parent first.
                }
                $this->apply_dependency_term($term);
                unset($pending[ $key ]);
                $progress = true;
            }
            if (! $progress) {
                // A parent cycle in a hand-edited artifact: apply the rest flat.
                foreach ($pending as $term) {
                    $this->apply_dependency_term($term);
                }
                break;
            }
        }
    }

    private function apply_dependency_term(array $term): void
    {
        $key      = (string) $term['key'];
        $taxonomy = (string) ($term['taxonomy'] ?? '');
        $slug     = (string) ($term['slug'] ?? '');

        if ('' === $taxonomy || '' === $slug || ! taxonomy_exists($taxonomy)) {
            $this->available[ $key ] = false;
            $this->dep($key, 'skipped', 'none', sprintf('taxonomy "%s" is not registered on the target', $taxonomy));
            return;
        }

        $existing = get_term_by('slug', $slug, $taxonomy);
        if ($existing instanceof \WP_Term) {
            $this->available[ $key ] = true;
            $this->map_term($term, (int) $existing->term_id);
            $this->dep($key, 'skipped', 'none', 'already on the target; existing terms are never modified by a sync', (int) $existing->term_id);
            return;
        }

        if ($this->dry) {
            $this->available[ $key ] = true;
            $this->dep($key, 'would_apply', 'create', 'missing on the target; would be created');
            return;
        }

        $parent = 0;
        if (null !== ($term['parent'] ?? null)) {
            $p      = get_term_by('slug', (string) $term['parent'], $taxonomy);
            $parent = $p instanceof \WP_Term ? (int) $p->term_id : 0;
        }

        try {
            $run = Safe_Mutation::run(
                [
                    'object_type' => 'term',
                    'object_id'   => Snapshot::term_key($taxonomy, $slug),
                    'session_id'  => $this->session,
                    'tool_name'   => 'apply-change-set',
                    'args'        => ['key' => $key],
                ],
                static fn () => wp_insert_term(
                    wp_slash((string) ($term['name'] ?? $slug)),
                    $taxonomy,
                    ['slug' => $slug, 'description' => wp_slash((string) ($term['description'] ?? '')), 'parent' => $parent]
                )
            );
        } catch (\Throwable $e) {
            $this->available[ $key ] = false;
            $this->dep($key, 'failed', 'create', $e->getMessage());
            return;
        }

        if (is_wp_error($run['result'])) {
            Rollback_Service::restore_operation($run['operation_id']);
            $this->available[ $key ] = false;
            $this->dep($key, 'failed', 'create', $run['result']->get_error_message());
            return;
        }

        $term_id                 = (int) $run['result']['term_id'];
        $this->available[ $key ] = true;
        $this->map_term($term, $term_id);
        $this->dep($key, 'applied', 'create', 'created on the target', $term_id, $run['operation_id']);
    }

    private function map_term(array $term, int $target_id): void
    {
        $source = (int) ($term['term_id'] ?? 0);
        if ($source > 0) {
            $this->term_map[ $source ] = $target_id;
        }
    }

    /**
     * Place one attachment the change set needs: reuse the target's copy
     * when it provably is the same file, otherwise create it from the bytes
     * the artifact carries. Never overwrites an existing attachment, and
     * never writes into an id another object holds.
     */
    private function apply_attachment(array $dep): void
    {
        $src  = (int) ($dep['object_id'] ?? 0);
        $key  = 'attachment:' . $src;
        $post = (array) ($dep['post'] ?? []);

        // 1. The same attachment at the same id (a site cloned from the other).
        $at = get_post($src);
        if ($at && 'attachment' === $at->post_type && $at->post_date_gmt === (string) ($post['post_date_gmt'] ?? '')) {
            $this->attachment_map[ $src ] = $src;
            $this->available[ $key ]      = true;
            $this->dep($key, 'skipped', 'none', 'already on the target; existing media is never overwritten by a sync', $src);
            return;
        }

        // 2. The same file under a different id.
        $found = $this->find_attachment_by_file((string) ($dep['relative_path'] ?? ''), (string) ($dep['checksum'] ?? ''));
        if ($found > 0) {
            $this->attachment_map[ $src ] = $found;
            $this->available[ $key ]      = true;
            $this->dep($key, 'skipped', 'none', 'the same file is already on the target; reused', $found);
            return;
        }

        // 3. Create it from the carried bytes.
        $bytes = $dep['bytes'] ?? null;
        if (! is_string($bytes) || '' === $bytes) {
            $this->available[ $key ] = false;
            $this->dep($key, 'skipped', 'none', sprintf(
                'not on the target and the change set carries no bytes for it (%s)',
                (string) ($dep['bytes_omitted'] ?? 'omitted')
            ));
            return;
        }

        if ($this->dry) {
            $this->available[ $key ] = true;
            $this->dep($key, 'would_apply', 'create', get_post($src) ? 'would be created under a new id (the origin id is taken on the target)' : 'would be created');
            return;
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decodes the media bytes carried in the JSON artifact; not obfuscation.
        $raw = base64_decode($bytes, true);
        if (false === $raw || ('' !== (string) ($dep['checksum'] ?? '') && md5($raw) !== (string) $dep['checksum'])) {
            $this->available[ $key ] = false;
            $this->dep($key, 'failed', 'create', 'the carried bytes do not match the recorded checksum');
            return;
        }

        $relative = (string) ($dep['relative_path'] ?? '');
        $name     = sanitize_file_name(wp_basename('' !== $relative ? $relative : (string) ($dep['file'] ?? 'sync-media')));
        $time     = preg_match('#^(\d{4}/\d{2})/#', $relative, $m) ? $m[1] : null;
        $upload   = wp_upload_bits($name, null, $raw, $time);
        if (! empty($upload['error'])) {
            $this->available[ $key ] = false;
            $this->dep($key, 'failed', 'create', (string) $upload['error']);
            return;
        }

        $attachment = [
            'post_mime_type' => (string) ($dep['mime_type'] ?? ''),
            'post_title'     => (string) ($post['post_title'] ?? ''),
            'post_excerpt'   => (string) ($post['post_excerpt'] ?? ''),
            'post_content'   => (string) ($post['post_content'] ?? ''),
            'post_date'      => (string) ($post['post_date'] ?? ''),
            'post_date_gmt'  => (string) ($post['post_date_gmt'] ?? ''),
            'post_status'    => 'inherit',
            'post_author'    => get_current_user_id(),
        ];
        if (! get_post($src)) {
            $attachment['import_id'] = $src;
        }

        $id = wp_insert_attachment(wp_slash($attachment), $upload['file'], 0, true);
        if (is_wp_error($id) || ! $id) {
            wp_delete_file($upload['file']);
            $this->available[ $key ] = false;
            $this->dep($key, 'failed', 'create', is_wp_error($id) ? $id->get_error_message() : 'the attachment could not be inserted');
            return;
        }
        $id = (int) $id;

        try {
            $operation = Media_Import_Snapshot::record('apply-change-set', $id, ['key' => $key], $this->session);
        } catch (\Throwable $e) {
            // No undo point, no write: remove what was just created.
            wp_delete_attachment($id, true);
            $this->available[ $key ] = false;
            $this->dep($key, 'failed', 'create', $e->getMessage());
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $upload['file']));
        if ('' !== (string) ($dep['alt'] ?? '')) {
            update_post_meta($id, '_wp_attachment_image_alt', wp_slash((string) $dep['alt']));
        }

        $this->attachment_map[ $src ] = $id;
        $this->available[ $key ]      = true;
        $this->created[ 'post:' . $src ] = ['target_id' => $id, 'operation_id' => $operation];
        $this->dep($key, 'applied', 'create', $id === $src ? 'created on the target' : sprintf('created on the target as attachment %d (the origin id is taken by another object)', $id), $id, $operation);
    }

    /** An attachment on the target holding the same relative path AND the same bytes. */
    private function find_attachment_by_file(string $relative, string $checksum): int
    {
        if ('' === $relative || '' === $checksum) {
            return 0;
        }
        $ids = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'any',
            'posts_per_page' => 5,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- bounded dedup lookup of one upload path.
            'meta_key'       => '_wp_attached_file',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- see above.
            'meta_value'     => $relative,
        ]);
        foreach ($ids as $id) {
            $file = get_attached_file((int) $id);
            if ($file && is_readable($file) && md5_file($file) === $checksum) {
                return (int) $id;
            }
        }
        return 0;
    }

    /**
     * Global classes an Elementor page uses: missing ones are added in one
     * snapshot-first write; an existing class is never modified, and one
     * whose definition differs is reported.
     */
    private function apply_global_classes(array $classes): void
    {
        if ([] === $classes) {
            return;
        }

        $state = $this->global_classes_state();
        if (null === $state) {
            foreach (array_keys($classes) as $id) {
                $key                     = 'global_class:' . $id;
                $this->available[ $key ] = false;
                $this->dep($key, 'skipped', 'none', 'this target exposes no Elementor global classes');
            }
            return;
        }

        $add = [];
        foreach ($classes as $id => $item) {
            $id  = (string) $id;
            $key = 'global_class:' . $id;
            if (isset($state['items'][ $id ])) {
                $this->available[ $key ] = true;
                if (Change_Set_Format::hash($state['items'][ $id ]) === Change_Set_Format::hash($item)) {
                    $this->dep($key, 'skipped', 'none', 'already on the target');
                } else {
                    $this->dep($key, 'conflicted', 'none', 'a global class with this id differs on the target; it was not overwritten, so the page may render with the live definition');
                }
                continue;
            }
            if (! is_array($item)) {
                $this->available[ $key ] = false;
                $this->dep($key, 'failed', 'create', 'malformed class definition in the change set');
                continue;
            }
            $add[ $id ] = $item;
        }

        if ([] === $add) {
            return;
        }

        if ($this->dry) {
            foreach (array_keys($add) as $id) {
                $this->available[ 'global_class:' . $id ] = true;
                $this->dep('global_class:' . $id, 'would_apply', 'create', 'missing on the target; would be added');
            }
            return;
        }

        $items  = (array) $state['items'] + $add;
        $order  = array_values(array_merge((array) $state['order'], array_map('strval', array_keys($add))));
        $result = $this->write_global_classes($state, $items, $order);

        foreach (array_keys($add) as $id) {
            $key = 'global_class:' . $id;
            if (is_wp_error($result)) {
                $this->available[ $key ] = false;
                $this->dep($key, 'failed', 'create', $result->get_error_message());
            } else {
                $this->available[ $key ] = true;
                $this->dep($key, 'applied', 'create', 'added on the target', null, (string) ($result['operation_id'] ?? ''));
            }
        }
    }

    /** Templates and patterns the selected objects embed: created when missing, never overwritten. */
    private function apply_dependency_posts(array $posts): void
    {
        $pending = [];
        foreach ($posts as $entry) {
            if (is_array($entry) && ! empty($entry['key'])) {
                $pending[ (string) $entry['key'] ] = $entry;
            }
        }

        $guard = 0;
        while ([] !== $pending && $guard++ < 64) {
            $progress = false;
            foreach ($pending as $key => $entry) {
                $waits = false;
                foreach ((array) ($entry['requires'] ?? []) as $req) {
                    if (isset($pending[ $req ]) && $req !== $key) {
                        $waits = true;
                        break;
                    }
                }
                if ($waits) {
                    continue;
                }
                $this->apply_dependency_post($entry);
                unset($pending[ $key ]);
                $progress = true;
            }
            if (! $progress) {
                foreach ($pending as $entry) {
                    $this->apply_dependency_post($entry);
                }
                break;
            }
        }
    }

    private function apply_dependency_post(array $entry): void
    {
        $key       = (string) $entry['key'];
        $post_type = (string) ($entry['data']['post_type'] ?? '');

        $refusal = $this->post_type_refusal($post_type);
        if (null !== $refusal) {
            $this->available[ $key ] = false;
            $this->dep($key, 'skipped', 'none', $refusal);
            return;
        }

        $target = $this->find_target($entry);
        if (null !== $target) {
            $this->post_map[ (int) $entry['object_id'] ] = $target;
            $this->available[ $key ]                     = true;
            $this->dep($key, 'skipped', 'none', 'already on the target; dependencies are only ever added, never overwritten', $target);
            return;
        }

        $missing = $this->missing_requires($entry);
        if ([] !== $missing) {
            $this->available[ $key ] = false;
            $this->dep($key, 'skipped', 'none', 'its own dependencies are unavailable on the target: ' . implode(', ', $missing));
            return;
        }

        if ($this->dry) {
            $this->available[ $key ] = true;
            $this->dep($key, 'would_apply', 'create', 'missing on the target; would be created');
            return;
        }

        $created = $this->create_post($entry);
        $this->available[ $key ] = 'applied' === $created['outcome'];
        $this->dep($key, $created['outcome'], 'create', $created['reason'], $created['target_id'], $created['operation_id']);
    }

    // ------------------------------------------------------------------
    // Selected objects.
    // ------------------------------------------------------------------

    /**
     * Apply order: terms, attachments, templates/patterns, other content,
     * menu items, theme mods. Things referenced come before things that
     * reference them, so their target ids are known when references are
     * remapped.
     */
    private function object_order(array $objects): array
    {
        $buckets = [[], [], [], [], [], []];
        foreach ($objects as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $type      = (string) ($entry['object_type'] ?? '');
            $post_type = (string) ($entry['post_type'] ?? ($entry['data']['post_type'] ?? ''));
            if ('term' === $type) {
                $buckets[0][] = $entry;
            } elseif ('option' === $type) {
                $buckets[5][] = $entry;
            } elseif ('attachment' === $type || 'attachment' === $post_type) {
                $buckets[1][] = $entry;
            } elseif (in_array($post_type, self::TEMPLATE_TYPES, true)) {
                $buckets[2][] = $entry;
            } elseif ('nav_menu_item' === $post_type) {
                $buckets[4][] = $entry;
            } else {
                $buckets[3][] = $entry;
            }
        }
        return array_merge(...$buckets);
    }

    private function apply_post_object(array $entry): void
    {
        $key = (string) $entry['key'];

        if (! empty($entry['deleted'])) {
            $this->obj($entry, 'skipped', 'none', 'deleted on the origin; deletions are reported, never applied');
            return;
        }

        $post_type = (string) ($entry['data']['post_type'] ?? ($entry['post_type'] ?? ''));
        $refusal   = $this->post_type_refusal($post_type);
        if (null !== $refusal) {
            $this->obj($entry, 'skipped', 'none', $refusal);
            return;
        }

        if (! empty($entry['unchanged'])) {
            $this->obj($entry, 'skipped', 'none', 'unchanged on the origin since the base; nothing to push');
            return;
        }

        $forced  = isset($this->force[ $key ]);
        $missing = $this->missing_requires($entry);
        if ([] !== $missing && ! $forced) {
            $this->obj($entry, 'skipped', 'none', 'dependency unavailable on the target: ' . implode(', ', $missing));
            return;
        }

        // An attachment created from the change set's own media this run.
        if (isset($this->created[ $key ])) {
            $made = $this->created[ $key ];
            if (! $this->dry) {
                $this->write_meta((int) $made['target_id'], $entry);
            }
            $this->post_map[ (int) $entry['object_id'] ] = (int) $made['target_id'];
            $this->obj($entry, $this->dry ? 'would_apply' : 'applied', 'create', 'created from the media the change set carries', (int) $made['target_id'], $made['operation_id']);
            return;
        }

        $src    = (int) $entry['object_id'];
        $target = ('attachment' === $post_type && isset($this->attachment_map[ $src ]))
            ? $this->attachment_map[ $src ]
            : $this->find_target($entry);

        if (null === $target) {
            if ('attachment' === $post_type) {
                if ($this->dry && true === ($this->available[ 'attachment:' . $src ] ?? false)) {
                    $this->obj($entry, 'would_apply', 'create', 'would be created from the media the change set carries');
                    return;
                }
                $this->obj($entry, 'skipped', 'none', 'the attachment is not on the target and could not be created from the change set');
                return;
            }
            if ($this->dry) {
                $this->obj($entry, 'would_apply', 'create', 'missing on the target; would be created');
                return;
            }
            $created = $this->create_post($entry);
            $this->obj($entry, $created['outcome'], 'create', $created['reason'], $created['target_id'], $created['operation_id']);
            return;
        }

        $this->post_map[ (int) $entry['object_id'] ] = $target;

        $current     = get_post($target, ARRAY_A);
        $target_hash = $this->target_post_hash($target, $entry);

        if ($target_hash === (string) ($entry['hash'] ?? '')) {
            $this->obj($entry, 'skipped', 'none', 'already in sync on the target', $target);
            return;
        }

        $conflict = null;
        if ('trash' === ($current['post_status'] ?? '')) {
            $conflict = 'the object is in the trash on the target';
        } elseif ('present' !== ($entry['base']['state'] ?? '') || $target_hash !== (string) ($entry['base']['hash'] ?? '')) {
            $conflict = $this->conflict_reason($entry, $current);
        }

        if (null !== $conflict && ! $forced) {
            $this->obj($entry, 'conflicted', 'update', $conflict . '; pass this key in force to overwrite it (the overwrite is snapshotted)', $target);
            return;
        }

        if ($this->dry) {
            $this->obj($entry, 'would_apply', 'update', null !== $conflict ? 'forced over a conflict: ' . $conflict : 'the target is unmodified since the base', $target);
            return;
        }

        $updated = $this->update_post($target, $entry);
        $reason  = 'applied' === $updated['outcome'] && null !== $conflict ? 'forced over a conflict: ' . $conflict : $updated['reason'];
        $this->obj($entry, $updated['outcome'], 'update', $reason, $target, $updated['operation_id']);
    }

    private function conflict_reason(array $entry, array $current): string
    {
        $state = (string) ($entry['base']['state'] ?? 'unknown');
        if ('absent' === $state) {
            return 'the object did not exist when the build began, but a different version of it exists on the target';
        }
        if ('unknown' === $state) {
            return 'the change set has no base revision for this object (it was selected explicitly), so a target copy that differs cannot be proven unmodified';
        }
        return sprintf(
            'modified on the target since the change set\'s base (target post_modified %s, base %s)',
            (string) ($current['post_modified_gmt'] ?? '?'),
            (string) ($entry['base']['post_modified'] ?? '?')
        );
    }

    /** Terms named by the ledger: created, or updated when unmodified on the target since the base. */
    private function apply_term_object(array $entry): void
    {
        $key      = (string) $entry['key'];
        $taxonomy = (string) ($entry['taxonomy'] ?? '');
        $slug     = (string) ($entry['slug'] ?? '');

        if (! empty($entry['deleted'])) {
            $this->obj($entry, 'skipped', 'none', 'deleted on the origin; deletions are reported, never applied');
            return;
        }
        if (! taxonomy_exists($taxonomy)) {
            $this->obj($entry, 'skipped', 'none', sprintf('taxonomy "%s" is not registered on the target', $taxonomy));
            return;
        }
        if (! empty($entry['unchanged'])) {
            $this->obj($entry, 'skipped', 'none', 'unchanged on the origin since the base; nothing to push');
            return;
        }

        $data     = (array) ($entry['data'] ?? []);
        $existing = get_term_by('slug', $slug, $taxonomy);
        $forced   = isset($this->force[ $key ]);
        $parent   = 0;
        if (null !== ($data['parent'] ?? null)) {
            $p      = get_term_by('slug', (string) $data['parent'], $taxonomy);
            $parent = $p instanceof \WP_Term ? (int) $p->term_id : 0;
        }

        if ($existing instanceof \WP_Term) {
            $target_hash = Change_Set_Format::hash(Change_Set_Format::tokenize(
                Change_Set_Format::term_projection($existing->name, $existing->description, (int) $existing->parent, $taxonomy),
                $this->target_tokens
            ));
            if ($target_hash === (string) ($entry['hash'] ?? '')) {
                $this->obj($entry, 'skipped', 'none', 'already in sync on the target', (int) $existing->term_id);
                return;
            }
            $unmodified = 'present' === ($entry['base']['state'] ?? '') && $target_hash === (string) ($entry['base']['hash'] ?? '');
            if (! $unmodified && ! $forced) {
                $this->obj($entry, 'conflicted', 'update', 'modified on the target since the change set\'s base; pass this key in force to overwrite it', (int) $existing->term_id);
                return;
            }
            if ($this->dry) {
                $this->obj($entry, 'would_apply', 'update', 'the target is unmodified since the base', (int) $existing->term_id);
                return;
            }
            $args = [
                'name'        => wp_slash((string) $this->rewrite($data['name'] ?? '')),
                'description' => wp_slash((string) $this->rewrite($data['description'] ?? '')),
                'parent'      => $parent,
            ];
        } else {
            if ($this->dry) {
                $this->obj($entry, 'would_apply', 'create', 'missing on the target; would be created');
                return;
            }
            $args = null;
        }

        try {
            $run = Safe_Mutation::run(
                ['object_type' => 'term', 'object_id' => Snapshot::term_key($taxonomy, $slug), 'session_id' => $this->session, 'tool_name' => 'apply-change-set', 'args' => ['key' => $key]],
                function () use ($existing, $args, $data, $taxonomy, $slug, $parent) {
                    if ($existing instanceof \WP_Term) {
                        return wp_update_term((int) $existing->term_id, $taxonomy, $args);
                    }
                    return wp_insert_term(
                        wp_slash((string) $this->rewrite($data['name'] ?? $slug)),
                        $taxonomy,
                        ['slug' => $slug, 'description' => wp_slash((string) $this->rewrite($data['description'] ?? '')), 'parent' => $parent]
                    );
                }
            );
        } catch (\Throwable $e) {
            $this->obj($entry, 'failed', $existing ? 'update' : 'create', $e->getMessage());
            return;
        }

        if (is_wp_error($run['result'])) {
            Rollback_Service::restore_operation($run['operation_id']);
            $this->obj($entry, 'failed', $existing ? 'update' : 'create', $run['result']->get_error_message());
            return;
        }
        $this->obj($entry, 'applied', $existing ? 'update' : 'create', $existing ? 'the target was unmodified since the base' : 'created on the target', (int) $run['result']['term_id'], $run['operation_id']);
    }

    /**
     * Theme mods, merged per key: only the keys the build changed are
     * written, and a key changed on both sides refuses the whole option.
     */
    private function apply_option(array $entry): void
    {
        $key  = (string) $entry['key'];
        $name = (string) ($entry['name'] ?? '');

        if (! empty($entry['deleted'])) {
            $this->obj($entry, 'skipped', 'none', 'deleted on the origin; deletions are reported, never applied');
            return;
        }
        if (! Change_Set_Format::is_syncable_option($name)) {
            $this->obj($entry, 'skipped', 'none', 'only theme mods are syncable options; this one is site configuration and is never synced');
            return;
        }
        if (! empty($entry['unchanged'])) {
            $this->obj($entry, 'skipped', 'none', 'unchanged on the origin since the base; nothing to push');
            return;
        }

        $forced  = isset($this->force[ $key ]);
        $missing = $this->missing_requires($entry);
        if ([] !== $missing && ! $forced) {
            $this->obj($entry, 'skipped', 'none', 'dependency unavailable on the target: ' . implode(', ', $missing));
            return;
        }

        $absent  = '__wpmcp_missing__' . $name;
        $current = get_option($name, $absent);
        $target  = is_array($current) ? $current : [];
        $value   = (array) ($entry['value'] ?? []);
        $known   = in_array((string) ($entry['base']['state'] ?? ''), ['present', 'absent'], true);
        $base    = (array) ($entry['base']['value'] ?? []);

        $merged    = $target;
        $conflicts = [];
        foreach ((array) ($entry['changed_keys'] ?? []) as $k) {
            $k  = (string) $k;
            $nt = $this->norm_key($target, $k, $this->target_tokens);
            $nl = $this->norm_key($value, $k, $this->origin_tokens);
            if ($nt === $nl) {
                continue;
            }
            if (! ($known && $nt === $this->norm_key($base, $k, $this->origin_tokens)) && ! $forced) {
                $conflicts[] = $k;
                continue;
            }
            if (array_key_exists($k, $value)) {
                $merged[ $k ] = $this->remap_option_value($k, $this->rewrite($value[ $k ]));
            } else {
                unset($merged[ $k ]);
            }
        }

        if ([] !== $conflicts) {
            $this->obj($entry, 'conflicted', 'update', 'changed on both sides since the base: ' . implode(', ', $conflicts) . '; pass this key in force to overwrite them');
            return;
        }
        if ($merged === $target && $absent !== $current) {
            $this->obj($entry, 'skipped', 'none', 'already in sync on the target');
            return;
        }
        if ($this->dry) {
            $this->obj($entry, 'would_apply', 'update', 'the changed keys are unmodified on the target since the base');
            return;
        }

        try {
            $run = Safe_Mutation::run(
                ['object_type' => 'option', 'object_id' => $name, 'session_id' => $this->session, 'tool_name' => 'apply-change-set', 'args' => ['key' => $key]],
                static fn () => update_option($name, $merged)
            );
        } catch (\Throwable $e) {
            $this->obj($entry, 'failed', 'update', $e->getMessage());
            return;
        }
        $this->obj($entry, 'applied', 'update', 'merged the changed keys', null, $run['operation_id']);
    }

    /** @return string hash of one key's value, or "absent" */
    private function norm_key(array $values, string $k, array $tokens): string
    {
        if (! array_key_exists($k, $values)) {
            return 'absent';
        }
        return Change_Set_Format::hash(Change_Set_Format::tokenize($values[ $k ], $tokens));
    }

    /** @param mixed $value */
    private function remap_option_value(string $k, $value)
    {
        if ('custom_logo' === $k && is_numeric($value)) {
            return $this->map_id((int) $value, $this->attachment_map);
        }
        if ('nav_menu_locations' === $k && is_array($value)) {
            foreach ($value as $location => $term_id) {
                $value[ $location ] = $this->map_id((int) $term_id, $this->term_map);
            }
        }
        return $value;
    }

    // ------------------------------------------------------------------
    // Post writes.
    // ------------------------------------------------------------------

    /** @return string|null why this post type must not be written, or null */
    private function post_type_refusal(string $post_type): ?string
    {
        if (! Change_Set_Format::is_syncable_post_type($post_type)) {
            return sprintf('%s posts are live-side data and are never synced', '' !== $post_type ? $post_type : 'untyped');
        }
        if (! post_type_exists($post_type)) {
            return sprintf('post type "%s" is not registered on the target', $post_type);
        }
        return null;
    }

    /** @return string[] required dependency keys that are not usable on the target */
    private function missing_requires(array $entry): array
    {
        $missing = [];
        foreach ((array) ($entry['requires'] ?? []) as $req) {
            if (true !== ($this->available[ (string) $req ] ?? false)) {
                $missing[] = (string) $req;
            }
        }
        return $missing;
    }

    /**
     * The target post that IS this object: same id and same identity, or
     * the same identity under another id. Identity is post type plus
     * creation date (plus slug for a never-published draft), the check
     * Rollback_Service already trusts; an id alone is not identity, since
     * a live site allocates ids to orders and uploads the origin never saw.
     */
    private function find_target(array $entry): ?int
    {
        $src = (int) ($entry['object_id'] ?? 0);
        if (isset($this->post_map[ $src ])) {
            return $this->post_map[ $src ];
        }
        $data = (array) ($entry['data'] ?? []);
        return $this->find_by_identity($src, (string) ($data['post_type'] ?? ''), (string) ($data['post_name'] ?? ''), (string) ($data['post_date_gmt'] ?? ''));
    }

    private function find_by_identity(int $src, string $post_type, string $post_name, string $date_gmt): ?int
    {
        $at = $src > 0 ? get_post($src) : null;
        if (
            $at && $at->post_type === $post_type && $at->post_date_gmt === $date_gmt
            && (self::ZERO_DATE !== $date_gmt || ('' !== $post_name && $at->post_name === $post_name))
        ) {
            return $src;
        }

        if ('' === $post_name || '' === $date_gmt || self::ZERO_DATE === $date_gmt) {
            return null;
        }

        $ids = get_posts([
            'post_type'        => $post_type,
            'name'             => $post_name,
            'post_status'      => 'any',
            'posts_per_page'   => 5,
            'fields'           => 'ids',
            'no_found_rows'    => true,
        ]);
        foreach ($ids as $id) {
            if (get_post_field('post_date_gmt', (int) $id) === $date_gmt) {
                return (int) $id;
            }
        }
        return null;
    }

    private function target_post_hash(int $target, array $entry): string
    {
        $row   = (array) get_post($target, ARRAY_A);
        $scope = (array) ($entry['hash_scope'] ?? []);
        $taxes = (array) ($scope['taxonomies'] ?? []);
        $terms = [];
        foreach ($taxes as $taxonomy) {
            $found              = taxonomy_exists((string) $taxonomy) ? wp_get_object_terms($target, (string) $taxonomy, ['fields' => 'slugs']) : [];
            $terms[ $taxonomy ] = is_array($found) ? $found : [];
        }
        return Change_Set_Format::post_hash(
            $row,
            (array) get_post_meta($target),
            $terms,
            array_map('strval', (array) ($scope['meta_keys'] ?? [])),
            array_map('strval', $taxes),
            $this->target_tokens
        );
    }

    /** @return array{outcome:string, reason:string, target_id:int|null, operation_id:string|null} */
    private function update_post(int $target, array $entry): array
    {
        $fields       = $this->post_fields($entry, false);
        $fields['ID'] = $target;

        try {
            $run = Safe_Mutation::run(
                [
                    'object_type' => 'post',
                    'object_id'   => $target,
                    'session_id'  => $this->session,
                    'tool_name'   => 'apply-change-set',
                    'args'        => ['key' => (string) $entry['key']],
                ],
                function () use ($target, $fields, $entry) {
                    // A change set carries the whole row, text included. The
                    // text columns the target already holds are left out, so
                    // the save filters run only over the ones that change.
                    $result = Save_Filters::update_post(wp_slash(Save_Filters::changed_text($target, $fields)), true);
                    if (is_wp_error($result)) {
                        return $result;
                    }
                    $this->write_meta($target, $entry);
                    $this->write_terms($target, $entry);
                    return true;
                },
                static fn ($result) => true === $result
            );
        } catch (\Throwable $e) {
            return ['outcome' => 'failed', 'reason' => $e->getMessage(), 'target_id' => $target, 'operation_id' => null];
        }

        $this->note_slug($entry, $target);
        return ['outcome' => 'applied', 'reason' => 'the target was unmodified since the base', 'target_id' => $target, 'operation_id' => $run['operation_id']];
    }

    /**
     * Create a post the target does not have, then record the creation so a
     * rollback deletes exactly it. The origin id is reused when it is free
     * (so the next sync matches by id); otherwise the post gets a new id and
     * references to it are remapped.
     *
     * @return array{outcome:string, reason:string, target_id:int|null, operation_id:string|null}
     */
    private function create_post(array $entry): array
    {
        $src    = (int) $entry['object_id'];
        $fields = $this->post_fields($entry, true);
        if ($src > 0 && ! get_post($src)) {
            $fields['import_id'] = $src;
        }

        $id = wp_insert_post(wp_slash($fields), true);
        if (is_wp_error($id) || ! $id) {
            return ['outcome' => 'failed', 'reason' => is_wp_error($id) ? $id->get_error_message() : 'the post could not be created', 'target_id' => null, 'operation_id' => null];
        }
        $id = (int) $id;

        $operation = wp_generate_uuid4();
        try {
            Snapshot_Store::save(
                $operation,
                $this->session,
                [
                    'object_type' => 'page_build',
                    'object_id'   => $id,
                    'data'        => [
                        'post_id'       => $id,
                        'post_date_gmt' => (string) get_post_field('post_date_gmt', $id),
                        'menu_item_ids' => [],
                    ],
                ],
                'apply-change-set',
                hash('sha256', (string) wp_json_encode(['key' => (string) $entry['key']]))
            );
            Snapshot_Store::prune();
        } catch (\Throwable $e) {
            wp_delete_post($id, true); // No undo point, no write.
            return ['outcome' => 'failed', 'reason' => $e->getMessage(), 'target_id' => null, 'operation_id' => null];
        }

        $this->post_map[ $src ] = $id;

        try {
            $this->write_meta($id, $entry);
            $this->write_terms($id, $entry);
        } catch (\Throwable $e) {
            Rollback_Service::restore_operation($operation);
            return ['outcome' => 'failed', 'reason' => $e->getMessage(), 'target_id' => null, 'operation_id' => null];
        }

        $this->note_slug($entry, $id);
        $reason = $id === $src ? 'created on the target' : sprintf('created on the target as post %d (the origin id is taken by another object)', $id);
        return ['outcome' => 'applied', 'reason' => $reason, 'target_id' => $id, 'operation_id' => $operation];
    }

    /** WordPress de-duplicates slugs silently; a synced page that lands at a different URL is worth saying so. */
    private function note_slug(array $entry, int $target): void
    {
        $wanted = (string) ($entry['data']['post_name'] ?? '');
        $got    = (string) get_post_field('post_name', $target);
        if ('' !== $wanted && $wanted !== $got) {
            $this->warnings[] = sprintf('%s landed with slug "%s" instead of "%s": the target already uses that slug for another post.', (string) $entry['key'], $got, $wanted);
        }
    }

    private function post_fields(array $entry, bool $create): array
    {
        $d = (array) ($entry['data'] ?? []);

        $fields = [
            'post_title'     => (string) $this->rewrite((string) ($d['post_title'] ?? '')),
            'post_content'   => $this->remap_content((string) $this->rewrite((string) ($d['post_content'] ?? ''))),
            'post_excerpt'   => (string) $this->rewrite((string) ($d['post_excerpt'] ?? '')),
            'post_status'    => (string) ($d['post_status'] ?? 'draft'),
            'post_name'      => (string) ($d['post_name'] ?? ''),
            'menu_order'     => (int) ($d['menu_order'] ?? 0),
            'comment_status' => (string) ($d['comment_status'] ?? ''),
            'ping_status'    => (string) ($d['ping_status'] ?? ''),
            'post_password'  => (string) ($d['post_password'] ?? ''),
            'post_parent'    => $this->resolve_parent($entry),
        ];
        if ('trash' === $fields['post_status']) {
            $fields['post_status'] = 'draft'; // Never publish, never trash, from a sync.
        }

        if ($create) {
            $fields['post_type']      = (string) ($d['post_type'] ?? 'post');
            $fields['post_date']      = (string) ($d['post_date'] ?? '');
            $fields['post_date_gmt']  = (string) ($d['post_date_gmt'] ?? '');
            $fields['post_mime_type'] = (string) ($d['post_mime_type'] ?? '');
            $fields['post_author']    = get_current_user_id();
        }

        return $fields;
    }

    private function resolve_parent(array $entry): int
    {
        $parent = $entry['parent'] ?? null;
        if (! is_array($parent) || (int) ($parent['object_id'] ?? 0) <= 0) {
            return 0;
        }
        $src = (int) $parent['object_id'];
        if (isset($this->post_map[ $src ])) {
            return $this->post_map[ $src ];
        }
        $found = $this->find_by_identity($src, (string) ($parent['post_type'] ?? ''), (string) ($parent['post_name'] ?? ''), (string) ($parent['post_date_gmt'] ?? ''));
        if (null !== $found) {
            return $found;
        }
        $this->warnings[] = sprintf('%s: its parent (post %d on the origin) is not on the target, so it was placed at the top level.', (string) $entry['key'], $src);
        return 0;
    }

    /**
     * Write the object's carried meta. Each key is replaced as a whole;
     * keys the target has that the change set does not carry are left
     * alone (they may be live-side data), except the keys the build deleted
     * on the origin. Values are decoded without instantiating objects; a
     * key whose value holds one is refused and reported.
     */
    private function write_meta(int $target, array $entry): void
    {
        foreach ((array) ($entry['meta'] ?? []) as $key => $values) {
            $key = (string) $key;
            if (Change_Set_Format::is_volatile_meta($key)) {
                continue;
            }
            $decoded = [];
            foreach ((array) $values as $raw) {
                $raw         = $this->remap_meta($key, (string) $this->rewrite((string) $raw), $entry);
                [$ok, $value] = Change_Set_Format::safe_unserialize($raw);
                if (! $ok) {
                    $this->warnings[] = sprintf('%s: meta "%s" holds a serialized object (or is undecodable) and was not written.', (string) $entry['key'], $key);
                    continue 2;
                }
                $decoded[] = $value;
            }
            delete_post_meta($target, $key);
            foreach ($decoded as $value) {
                add_post_meta($target, $key, wp_slash($value));
            }
        }

        foreach ((array) ($entry['removed_meta_keys'] ?? []) as $key) {
            if (! Change_Set_Format::is_volatile_meta((string) $key)) {
                delete_post_meta($target, (string) $key);
            }
        }

        // The target's compiled Elementor CSS describes the old layout.
        if (isset($entry['meta']['_elementor_data'])) {
            delete_post_meta($target, '_elementor_css');
        }
    }

    private function write_terms(int $target, array $entry): void
    {
        foreach ((array) ($entry['terms'] ?? []) as $taxonomy => $slugs) {
            $taxonomy = (string) $taxonomy;
            if (! taxonomy_exists($taxonomy)) {
                $this->warnings[] = sprintf('%s: taxonomy "%s" is not registered on the target; its terms were not assigned.', (string) $entry['key'], $taxonomy);
                continue;
            }
            $ids = [];
            foreach ((array) $slugs as $slug) {
                $term = get_term_by('slug', (string) $slug, $taxonomy);
                if ($term instanceof \WP_Term) {
                    $ids[] = (int) $term->term_id;
                } else {
                    $this->warnings[] = sprintf('%s: term %s:%s is not on the target and was not assigned.', (string) $entry['key'], $taxonomy, (string) $slug);
                }
            }
            wp_set_object_terms($target, $ids, $taxonomy, false);
        }
    }

    // ------------------------------------------------------------------
    // Rewriting.
    // ------------------------------------------------------------------

    /**
     * @param mixed $value
     * @return mixed
     */
    private function rewrite($value)
    {
        return Change_Set_Format::rewrite_urls($value, $this->origin, $this->home, $this->site);
    }

    private function map_id(int $id, array $map): int
    {
        return (isset($map[ $id ]) && (int) $map[ $id ] > 0) ? (int) $map[ $id ] : $id;
    }

    /** True when some origin id landed under a different id on the target. */
    private function has_remaps(): bool
    {
        foreach ([$this->attachment_map, $this->post_map] as $map) {
            foreach ($map as $src => $tgt) {
                if ((int) $src !== (int) $tgt) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Remap media and post ids inside block markup and classic wp-image-N classes. */
    private function remap_content(string $content): string
    {
        if ('' === $content || ! $this->has_remaps()) {
            return $content;
        }

        if (function_exists('parse_blocks') && false !== strpos($content, '<!-- wp:')) {
            $changed = false;
            $blocks  = $this->remap_blocks(parse_blocks($content), $changed);
            if ($changed) {
                $content = serialize_blocks($blocks);
            }
        }

        return (string) preg_replace_callback(
            '/\bwp-image-(\d+)\b/',
            fn ($m) => 'wp-image-' . $this->map_id((int) $m[1], $this->attachment_map),
            $content
        );
    }

    private function remap_blocks(array $blocks, bool &$changed): array
    {
        foreach ($blocks as $i => $block) {
            $name = (string) ($block['blockName'] ?? '');

            $media = Change_Set_Builder::BLOCK_MEDIA_ATTRS[ $name ] ?? null;
            if (null !== $media && isset($block['attrs'][ $media ])) {
                $old = $block['attrs'][ $media ];
                $new = is_array($old)
                    ? array_map(fn ($v) => is_numeric($v) ? $this->map_id((int) $v, $this->attachment_map) : $v, $old)
                    : (is_numeric($old) ? $this->map_id((int) $old, $this->attachment_map) : $old);
                if ($new !== $old) {
                    $blocks[ $i ]['attrs'][ $media ] = $new;
                    $changed                         = true;
                }
            }

            $ref = Change_Set_Builder::BLOCK_POST_REFS[ $name ] ?? null;
            if (null !== $ref && isset($block['attrs'][ $ref ]) && is_numeric($block['attrs'][ $ref ])) {
                $new = $this->map_id((int) $block['attrs'][ $ref ], $this->post_map);
                if ($new !== (int) $block['attrs'][ $ref ]) {
                    $blocks[ $i ]['attrs'][ $ref ] = $new;
                    $changed                       = true;
                }
            }

            if (! empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $blocks[ $i ]['innerBlocks'] = $this->remap_blocks($block['innerBlocks'], $changed);
            }
        }
        return $blocks;
    }

    /** Remap ids inside meta values that are known to hold them. */
    private function remap_meta(string $key, string $raw, array $entry): string
    {
        if (! $this->has_remaps()) {
            return $raw;
        }
        if ('_thumbnail_id' === $key && is_numeric($raw)) {
            return (string) $this->map_id((int) $raw, $this->attachment_map);
        }
        if ('_menu_item_object_id' === $key && is_numeric($raw) && 'post_type' === ($entry['meta']['_menu_item_type'][0] ?? '')) {
            return (string) $this->map_id((int) $raw, $this->post_map);
        }
        if ('_menu_item_menu_item_parent' === $key && is_numeric($raw)) {
            return (string) $this->map_id((int) $raw, $this->post_map);
        }
        if ('_elementor_data' === $key) {
            $tree = json_decode($raw, true);
            if (is_array($tree)) {
                $changed = false;
                $tree    = $this->remap_tree($tree, $changed, 0);
                if ($changed) {
                    return (string) wp_json_encode($tree);
                }
            }
        }
        return $raw;
    }

    private function remap_tree(array $node, bool &$changed, int $depth): array
    {
        if ($depth > 64) {
            return $node;
        }
        if (isset($node['id'], $node['url']) && is_numeric($node['id']) && is_string($node['url'])) {
            $new = $this->map_id((int) $node['id'], $this->attachment_map);
            if ($new !== (int) $node['id']) {
                $node['id'] = is_string($node['id']) ? (string) $new : $new;
                $changed    = true;
            }
        }
        foreach (['template_id', 'templateID'] as $k) {
            if (isset($node[ $k ]) && is_numeric($node[ $k ])) {
                $new = $this->map_id((int) $node[ $k ], $this->post_map);
                if ($new !== (int) $node[ $k ]) {
                    $node[ $k ] = is_string($node[ $k ]) ? (string) $new : $new;
                    $changed    = true;
                }
            }
        }
        foreach ($node as $k => $child) {
            if (is_array($child)) {
                $node[ $k ] = $this->remap_tree($child, $changed, $depth + 1);
            }
        }
        return $node;
    }

    // ------------------------------------------------------------------
    // Reporting.
    // ------------------------------------------------------------------

    private function dep(string $key, string $outcome, string $action, string $reason, ?int $target_id = null, ?string $operation_id = null): void
    {
        $this->dep_results[] = [
            'key'          => $key,
            'outcome'      => $outcome,
            'action'       => $action,
            'reason'       => $reason,
            'target_id'    => $target_id,
            'operation_id' => $operation_id,
        ];
    }

    private function obj(array $entry, string $outcome, string $action, string $reason, ?int $target_id = null, ?string $operation_id = null): void
    {
        $this->obj_results[] = [
            'key'          => (string) $entry['key'],
            'object_type'  => (string) $entry['object_type'],
            'outcome'      => $outcome,
            'action'       => $action,
            'reason'       => $reason,
            'target_id'    => $target_id,
            'operation_id' => $operation_id,
        ];
    }

    private function report(array $set): array
    {
        $summary = ['applied' => 0, 'would_apply' => 0, 'skipped' => 0, 'conflicted' => 0, 'failed' => 0];
        foreach ($this->obj_results as $row) {
            if (isset($summary[ $row['outcome'] ])) {
                $summary[ $row['outcome'] ]++;
            }
        }

        $wrote = false;
        foreach (array_merge($this->obj_results, $this->dep_results) as $row) {
            if ('applied' === $row['outcome']) {
                $wrote = true;
                break;
            }
        }

        return [
            'dry_run'      => $this->dry,
            'session_id'   => $this->session,
            'checksum'     => (string) $set['checksum'],
            'origin'       => ['home_url' => $this->origin['home_url'] ?? null, 'site_url' => $this->origin['site_url'] ?? null],
            'target'       => ['home_url' => $this->home, 'site_url' => $this->site],
            'summary'      => $summary,
            'objects'      => $this->obj_results,
            'dependencies' => $this->dep_results,
            'excluded'     => count((array) ($set['excluded'] ?? [])),
            'truncated'    => $set['truncated'] ?? null,
            'warnings'     => $this->warnings,
            'rollback'     => $wrote ? sprintf('rollback-session with session_id "%s" undoes every write this sync made.', $this->session) : null,
        ];
    }
}
