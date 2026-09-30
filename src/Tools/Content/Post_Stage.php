<?php

namespace WPMCP\Tools\Content;

use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Save_Filters;
use WPMCP\Tools\Builders\Elementor_Cache;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Staged edits (issue #417): a private draft copy of a live entry that the
 * ordinary edit tools change, then publish over the original or discard.
 *
 * The link lives ONLY on the stage (STAGE_OF_META plus the original's
 * fingerprint at staging time, STAGE_BASE_META). Nothing is written to the
 * original when it is staged, so staging never changes it, and a rollback of
 * a publish (which puts the original's meta back) can never resurrect a link
 * to a stage that no longer exists. One stage per original: lookups go by
 * the stage's meta, and a trashed or published stage no longer carries it.
 *
 * Publishing is ONE Safe_Mutation on the original, so its single snapshot is
 * what rollback-operation restores: the row, the whole meta map, the terms
 * and the builder caches. The original keeps its ID, slug, author, date,
 * status and comments; only title, content, excerpt, terms and content meta
 * (featured image and builder data included) come from the stage. Editor
 * bookkeeping and derived caches (KEEP_META) are never copied either way.
 *
 * A stage is never public: the status guard keeps it a draft whatever an
 * edit tool asks for, and its preview is noindex. The only way its content
 * goes live is publish().
 */
class Post_Stage
{
    public const STAGE_OF_META   = '_wpmcp_stage_of';
    public const STAGE_BASE_META = '_wpmcp_stage_base';

    /**
     * Meta that describes the post rather than its content, or is derived
     * from the content and rebuilt: never copied onto the original, never
     * removed from it, and ignored when deciding whether it changed. The
     * Elementor caches are rebuilt for the original after a publish; the
     * stage's were built for the stage document.
     */
    public const KEEP_META = [
        '_edit_lock',
        '_edit_last',
        '_wp_old_slug',
        '_wp_old_date',
        '_encloseme',
        '_pingme',
        '_wp_trash_meta_status',
        '_wp_trash_meta_time',
        '_wp_desired_post_slug',
        '_elementor_css',
        '_elementor_element_cache',
        '_elementor_page_assets',
        self::STAGE_OF_META,
        self::STAGE_BASE_META,
    ];

    /** Statuses a stage may be looked up in; a trashed stage is gone. */
    private const LIVE_STAGE_STATUSES = ['draft', 'pending', 'private'];

    /** Hooked from Plugin::boot(). */
    public static function register_runtime_hooks(): void
    {
        add_filter('wp_insert_post_data', [self::class, 'keep_stage_unpublished'], 99, 2);
        add_filter('wp_robots', [self::class, 'noindex_stage']);
    }

    /**
     * A stage only goes live through publish_stage: an edit tool (or the
     * block editor) asking to publish or schedule it leaves it a draft.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $postarr
     * @return array<string, mixed>
     */
    public static function keep_stage_unpublished($data, $postarr)
    {
        $id = (int) ($postarr['ID'] ?? 0);
        if (
            $id > 0 && is_array($data) && in_array($data['post_status'] ?? '', ['publish', 'future'], true)
            && self::original_of($id) > 0
        ) {
            $data['post_status'] = 'draft';
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $robots
     * @return array<string, mixed>
     */
    public static function noindex_stage($robots)
    {
        $id = (int) get_queried_object_id();
        if ($id > 0 && self::original_of($id) > 0) {
            $robots['noindex']  = true;
            $robots['nofollow'] = true;
        }
        return $robots;
    }

    /** The original a stage belongs to, or 0 when $post_id is not a stage. */
    public static function original_of(int $post_id): int
    {
        return (int) get_post_meta($post_id, self::STAGE_OF_META, true);
    }

    /** The live stage of an original, or 0. */
    public static function find(\WP_Post $original): int
    {
        $ids = get_posts([
            'post_type'        => $original->post_type,
            'post_status'      => self::LIVE_STAGE_STATUSES,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one indexed meta_key lookup per stage call.
            'meta_query'       => [[ 'key' => self::STAGE_OF_META, 'value' => (string) $original->ID ]],
            'fields'           => 'ids',
            'numberposts'      => 1,
            'orderby'          => 'ID',
            'order'            => 'ASC',
        ]);
        return [] === $ids ? 0 : (int) $ids[0];
    }

    /** Mark a fresh copy as the stage of $original, remembering what the original looked like. */
    public static function link(int $stage_id, int $original_id): void
    {
        add_post_meta($stage_id, self::STAGE_OF_META, $original_id, true);
        add_post_meta($stage_id, self::STAGE_BASE_META, self::fingerprint($original_id), true);
    }

    /**
     * Per-field hashes of everything publish() writes: title, content,
     * excerpt, each taxonomy's terms and each content meta key.
     *
     * @return array<string, string>
     */
    public static function fingerprint(int $post_id): array
    {
        $post = get_post($post_id);
        if (! $post instanceof \WP_Post) {
            return [];
        }
        $out = [
            'title'   => md5((string) $post->post_title),
            'content' => md5((string) $post->post_content),
            'excerpt' => md5((string) $post->post_excerpt),
        ];
        foreach (self::terms($post) as $taxonomy => $ids) {
            $out[ 'terms:' . $taxonomy ] = md5(implode(',', $ids));
        }
        foreach (self::content_meta($post_id) as $key => $values) {
            $out[ 'meta:' . $key ] = md5(serialize($values)); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- hashing raw meta rows, never stored or unserialized.
        }
        ksort($out);
        return $out;
    }

    /**
     * Write the stage onto its original and remove the stage.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function publish(int $stage_id, array $args): array
    {
        [$stage, $original] = self::pair($stage_id);

        if (! current_user_can('edit_post', $original->ID) || ! current_user_can('edit_post', $stage->ID)) {
            throw new \RuntimeException('You cannot edit post ' . (int) $original->ID . ' or its stage.');
        }

        $base    = (array) get_post_meta($stage->ID, self::STAGE_BASE_META, true);
        $changed = self::changed($base, self::fingerprint($original->ID));
        if ([] !== $changed && empty($args['force'])) {
            throw new \RuntimeException(sprintf(
                'Post %d changed since it was staged (%s). Review it, then pass force:true to publish over it.',
                (int) $original->ID,
                esc_html(implode(', ', $changed))
            ));
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $original->ID,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'duplicate-post',
                'args'        => $args,
            ],
            static function () use ($stage, $original, $base): bool {
                self::write($stage, $original, $base);
                self::unlink($stage->ID);
                Save_Filters::trash_post($stage->ID);
                return true;
            }
        );

        $result = [
            'post_id'      => (int) $original->ID,
            'stage_id'     => (int) $stage->ID,
            'url'          => get_permalink($original->ID),
            'operation_id' => $out['operation_id'],
        ];
        if ([] !== $changed) {
            $result['overwrote_changes'] = $changed;
        }
        return $result;
    }

    /** @return array<string, mixed> */
    public static function discard(int $stage_id): array
    {
        [$stage, $original] = self::pair($stage_id, false);

        if (! current_user_can('delete_post', $stage->ID)) {
            throw new \RuntimeException('You cannot delete stage ' . (int) $stage->ID . '.');
        }

        self::unlink($stage->ID);
        Save_Filters::trash_post($stage->ID);

        return [
            'post_id'   => (int) $stage->ID,
            'source_id' => $original instanceof \WP_Post ? (int) $original->ID : 0,
            'discarded' => true,
        ];
    }

    /**
     * The stage and its original, refusing anything that is not a live stage.
     *
     * @return array{0: \WP_Post, 1: \WP_Post|null}
     */
    private static function pair(int $stage_id, bool $need_original = true): array
    {
        $stage = $stage_id > 0 ? get_post($stage_id) : null;
        $of    = $stage instanceof \WP_Post ? self::original_of($stage_id) : 0;
        if (
            ! $stage instanceof \WP_Post || $of <= 0 || 'trash' === $stage->post_status
            || ! Content_Guard::is_writable_post_type((string) $stage->post_type)
        ) {
            throw new \InvalidArgumentException('Post ' . (int) $stage_id . ' is not a stage.');
        }

        $original = get_post($of);
        $live     = $original instanceof \WP_Post && 'trash' !== $original->post_status;
        if ($need_original && ! $live) {
            throw new \RuntimeException('The original of stage ' . (int) $stage_id . ' is gone; discard the stage.');
        }
        return [$stage, $live ? $original : null];
    }

    /** @param array<string, string> $base */
    private static function write(\WP_Post $stage, \WP_Post $original, array $base): void
    {
        $id = (int) $original->ID;

        // wp_update_post() unslashes; slash so block JSON escapes (<) survive.
        // Only the text the stage changed is written, and so filtered.
        $updated = Save_Filters::update_post(wp_slash(Save_Filters::changed_text($id, [
            'ID'           => $id,
            'post_title'   => $stage->post_title,
            'post_content' => $stage->post_content,
            'post_excerpt' => $stage->post_excerpt,
        ])), true);
        if (is_wp_error($updated)) {
            throw new \RuntimeException(esc_html($updated->get_error_message()));
        }

        $staged  = self::content_meta((int) $stage->ID);
        $current = self::content_meta($id);
        foreach ($staged as $key => $values) {
            if (($current[ $key ] ?? null) === $values) {
                continue;
            }
            delete_post_meta($id, $key);
            foreach ($values as $value) {
                add_post_meta($id, $key, self::slash(maybe_unserialize($value)));
            }
        }
        // Removed on the stage. A key the original gained after staging
        // (only reachable with force) was never on the stage, so it stays.
        foreach (array_keys(array_diff_key($current, $staged)) as $key) {
            if (isset($base[ 'meta:' . $key ])) {
                delete_post_meta($id, $key);
            }
        }

        foreach (self::terms($stage) as $taxonomy => $ids) {
            wp_set_object_terms($id, $ids, $taxonomy);
        }

        clean_post_cache($id);
        // Elementor rebuilds the dropped CSS and render cache from the new
        // data on the next view, as after any other raw builder write.
        if (metadata_exists('post', $id, '_elementor_data')) {
            Elementor_Cache::invalidate_document($id);
        }
    }

    private static function unlink(int $stage_id): void
    {
        delete_post_meta($stage_id, self::STAGE_OF_META);
        delete_post_meta($stage_id, self::STAGE_BASE_META);
    }

    /**
     * @param array<string, string> $base
     * @param array<string, string> $now
     * @return string[] the fingerprint keys that differ
     */
    private static function changed(array $base, array $now): array
    {
        $keys = array_unique(array_merge(array_keys($base), array_keys($now)));
        sort($keys);
        return array_values(array_filter($keys, static fn ($k) => ($base[ $k ] ?? null) !== ($now[ $k ] ?? null)));
    }

    /** @return array<string, array<int, string>> raw meta rows, KEEP_META removed */
    private static function content_meta(int $post_id): array
    {
        $meta = get_post_meta($post_id);
        $meta = is_array($meta) ? array_diff_key($meta, array_flip(self::KEEP_META)) : [];
        ksort($meta);
        return $meta;
    }

    /** @return array<string, int[]> */
    private static function terms(\WP_Post $post): array
    {
        $out = [];
        foreach (get_object_taxonomies($post->post_type) as $taxonomy) {
            $ids = wp_get_object_terms((int) $post->ID, $taxonomy, ['fields' => 'ids']);
            $ids = is_array($ids) ? array_map('intval', $ids) : [];
            sort($ids);
            $out[ $taxonomy ] = $ids;
        }
        return $out;
    }

    /**
     * add_post_meta() unslashes; slash every string so a value lands byte for byte.
     *
     * @param mixed $value
     * @return mixed
     */
    public static function slash($value)
    {
        return map_deep($value, static fn ($item) => is_string($item) ? addslashes($item) : $item);
    }
}
