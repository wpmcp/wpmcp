<?php

namespace WPMCP\Tools\SEO;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Term-level (category, tag, custom taxonomy archive) SEO fields for the
 * active plugin, in the same neutral vocabulary SEO_Adapter uses for posts:
 * title, description, focus_keyword, canonical, noindex, nofollow (issue
 * #67).
 *
 * Storage differs more than it does for posts, so each plugin gets a branch
 * rather than a key map alone:
 *
 * - Yoast keeps every term's fields in ONE option, `wpseo_taxonomy_meta`,
 *   shaped [taxonomy => [term_id => [wpseo_title, wpseo_desc, ...]]], with
 *   noindex as 'default' / 'index' / 'noindex'. It has no term nofollow.
 * - RankMath uses term meta under the same keys it uses on posts, robots
 *   included as an array.
 * - SEOPress uses term meta under its post keys, robots as 'yes'. It has no
 *   focus keyword on terms.
 * - The SEO Framework packs everything into one serialized term meta array,
 *   `autodescription-term-settings`, noindex/nofollow as 1. No focus keyword.
 * - Slim SEO keeps the same `slim_seo` array it uses on posts in term meta
 *   (title, description, canonical, noindex as 1). No focus keyword or
 *   nofollow.
 * - All in One SEO keeps a row per term in its `aioseo_terms` table, which
 *   only the paid AIOSEO creates; without it terms answer "unsupported".
 * - SureRank's term storage is not mapped, so it answers "unsupported".
 *
 * A field the active plugin does not store on terms is reported in
 * `unsupported_fields` and skipped on write, never an error: the issue asks
 * for structured "unsupported" answers for combinations a plugin lacks.
 *
 * snapshot_target() says what Safe_Mutation must capture before a write, so
 * the rollback covers exactly the storage the write touches: this term's
 * row inside the Yoast option, or the term (whose snapshot carries its full
 * meta map).
 */
class Term_SEO
{
    public const FIELDS = ['title', 'description', 'focus_keyword', 'canonical', 'noindex', 'nofollow'];

    public const YOAST_OPTION = 'wpseo_taxonomy_meta';

    private const YOAST_KEYS = [
        'title'         => 'wpseo_title',
        'description'   => 'wpseo_desc',
        'focus_keyword' => 'wpseo_focuskw',
        'canonical'     => 'wpseo_canonical',
        'noindex'       => 'wpseo_noindex',
    ];

    private const RANKMATH_KEYS = [
        'title'         => 'rank_math_title',
        'description'   => 'rank_math_description',
        'focus_keyword' => 'rank_math_focus_keyword',
        'canonical'     => 'rank_math_canonical_url',
    ];

    private const RANKMATH_ROBOTS_KEY = 'rank_math_robots';

    private const SEOPRESS_KEYS = [
        'title'       => '_seopress_titles_title',
        'description' => '_seopress_titles_desc',
        'canonical'   => '_seopress_robots_canonical',
        'noindex'     => '_seopress_robots_index',
        'nofollow'    => '_seopress_robots_follow',
    ];

    public const SEOFRAMEWORK_META = 'autodescription-term-settings';

    private const SEOFRAMEWORK_KEYS = [
        'title'       => 'doctitle',
        'description' => 'description',
        'canonical'   => 'canonical',
        'noindex'     => 'noindex',
        'nofollow'    => 'nofollow',
    ];

    /**
     * The neutral fields the active plugin stores on terms. Empty when the
     * plugin has no mapped term storage at all.
     *
     * @return string[]
     */
    public static function supported_fields(): array
    {
        switch (SEO_Adapter::active_plugin()) {
            case 'yoast':
                return array_keys(self::YOAST_KEYS);
            case 'rankmath':
                return array_merge(array_keys(self::RANKMATH_KEYS), ['noindex', 'nofollow']);
            case 'seopress':
                return array_keys(self::SEOPRESS_KEYS);
            case 'seoframework':
                return array_keys(self::SEOFRAMEWORK_KEYS);
            case 'slimseo':
                return ['title', 'description', 'canonical', 'noindex'];
            case 'aioseo':
                return Aioseo_Store::term_fields();
            default:
                return [];
        }
    }

    /**
     * The structured "unsupported" payload for the active plugin.
     *
     * @return array{supported: false, plugin: string, reason: string}
     */
    public static function unsupported(): array
    {
        $active = SEO_Adapter::active_plugin();

        return [
            'supported' => false,
            'plugin'    => $active,
            'reason'    => '' === $active
                ? 'No supported SEO plugin is active.'
                : 'Term-level SEO fields are not mapped for this plugin yet.',
        ];
    }

    /**
     * Read the neutral term field set. Every field is always present, typed
     * as it is for posts (strings, noindex/nofollow booleans), so the shape
     * is identical on every plugin; the ones the plugin does not store read
     * empty and are listed in `unsupported_fields`.
     */
    public static function get(\WP_Term $term): array
    {
        $supported = self::supported_fields();
        if ([] === $supported) {
            return self::unsupported();
        }

        $raw = self::read_raw($term);

        $fields = [];
        foreach (self::FIELDS as $field) {
            $fields[$field] = in_array($field, ['noindex', 'nofollow'], true)
                ? (bool) ($raw[$field] ?? false)
                : (string) ($raw[$field] ?? '');
        }

        return [
            'supported'          => true,
            'plugin'             => SEO_Adapter::active_plugin(),
            'fields'             => $fields,
            'unsupported_fields' => array_values(array_diff(self::FIELDS, $supported)),
        ];
    }

    /**
     * Write the subset of $fields the active plugin stores on terms. Keys
     * absent from $fields are untouched; keys the plugin cannot store are
     * ignored (the caller reports them). Raw writes only: the caller routes
     * this through Safe_Mutation using snapshot_target().
     */
    public static function update(\WP_Term $term, array $fields): void
    {
        $fields = array_intersect_key($fields, array_flip(self::supported_fields()));
        if ([] === $fields) {
            return;
        }

        $term_id = (int) $term->term_id;

        switch (SEO_Adapter::active_plugin()) {
            case 'yoast':
                self::update_yoast($term, $fields);
                return;

            case 'rankmath':
                foreach (self::RANKMATH_KEYS as $field => $key) {
                    if (array_key_exists($field, $fields)) {
                        update_term_meta($term_id, $key, wp_slash((string) $fields[$field]));
                    }
                }
                if (array_key_exists('noindex', $fields) || array_key_exists('nofollow', $fields)) {
                    $robots   = get_term_meta($term_id, self::RANKMATH_ROBOTS_KEY, true);
                    $robots   = is_array($robots) ? $robots : [];
                    $noindex  = array_key_exists('noindex', $fields) ? (bool) $fields['noindex'] : in_array('noindex', $robots, true);
                    $nofollow = array_key_exists('nofollow', $fields) ? (bool) $fields['nofollow'] : in_array('nofollow', $robots, true);

                    // Other robots directives (noarchive, nosnippet, ...) the
                    // term already carries are kept: only the two flags this
                    // vocabulary owns are rewritten.
                    $kept = array_values(array_diff($robots, ['noindex', 'nofollow', 'index', 'follow']));
                    $new  = $kept;
                    if ($noindex) {
                        $new[] = 'noindex';
                    }
                    if ($nofollow) {
                        $new[] = 'nofollow';
                    }
                    update_term_meta($term_id, self::RANKMATH_ROBOTS_KEY, $new);
                }
                return;

            case 'seopress':
                foreach (self::SEOPRESS_KEYS as $field => $key) {
                    if (! array_key_exists($field, $fields)) {
                        continue;
                    }
                    $value = in_array($field, ['noindex', 'nofollow'], true)
                        ? ($fields[$field] ? 'yes' : '')
                        : (string) $fields[$field];
                    update_term_meta($term_id, $key, wp_slash($value));
                }
                return;

            case 'seoframework':
                $data = get_term_meta($term_id, self::SEOFRAMEWORK_META, true);
                $data = is_array($data) ? $data : [];
                foreach (self::SEOFRAMEWORK_KEYS as $field => $key) {
                    if (! array_key_exists($field, $fields)) {
                        continue;
                    }
                    $data[$key] = in_array($field, ['noindex', 'nofollow'], true)
                        ? ($fields[$field] ? 1 : 0)
                        : (string) $fields[$field];
                }
                update_term_meta($term_id, self::SEOFRAMEWORK_META, wp_slash($data));
                return;

            case 'slimseo':
                $data = get_term_meta($term_id, SEO_Adapter::SLIM_SEO_META, true);
                $data = SEO_Adapter::slim_seo_apply(is_array($data) ? $data : [], $fields);
                if ([] === $data) {
                    delete_term_meta($term_id, SEO_Adapter::SLIM_SEO_META);
                    return;
                }
                update_term_meta($term_id, SEO_Adapter::SLIM_SEO_META, wp_slash($data));
                return;

            case 'aioseo':
                Aioseo_Store::update_term($term_id, $fields);
                return;
        }
    }

    /**
     * What Safe_Mutation must snapshot before a write to this term: its row
     * in the AIOSEO terms table, its row inside the Yoast option (all term
     * SEO lives there), or the term itself,
     * whose snapshot carries its full meta map and restores it exactly.
     *
     * @return array{object_type: string, object_id: string}
     */
    public static function snapshot_target(\WP_Term $term): array
    {
        if ('aioseo' === SEO_Adapter::active_plugin()) {
            return Aioseo_Store::snapshot_target('term', (int) $term->term_id);
        }

        if ('yoast' === SEO_Adapter::active_plugin()) {
            // Only this term's row inside the shared option, so a rollback
            // cannot revert other terms' SEO edits made since.
            return [
                'object_type' => 'yoast_term_seo',
                'object_id'   => (string) $term->taxonomy . ':' . (int) $term->term_id,
            ];
        }

        return [
            'object_type' => 'term',
            'object_id'   => Snapshot::term_key((string) $term->taxonomy, (string) $term->slug),
        ];
    }

    /** The active plugin's stored values, keyed by neutral field. */
    private static function read_raw(\WP_Term $term): array
    {
        $term_id = (int) $term->term_id;

        switch (SEO_Adapter::active_plugin()) {
            case 'yoast':
                $row = self::yoast_row($term);
                $out = [];
                foreach (self::YOAST_KEYS as $field => $key) {
                    $value = $row[$key] ?? '';
                    $out[$field] = 'noindex' === $field
                        ? 'noindex' === $value
                        : (is_scalar($value) ? (string) $value : '');
                }
                return $out;

            case 'rankmath':
                $out = [];
                foreach (self::RANKMATH_KEYS as $field => $key) {
                    $out[$field] = self::scalar_meta($term_id, $key);
                }
                $robots          = get_term_meta($term_id, self::RANKMATH_ROBOTS_KEY, true);
                $robots          = is_array($robots) ? $robots : [];
                $out['noindex']  = in_array('noindex', $robots, true);
                $out['nofollow'] = in_array('nofollow', $robots, true);
                return $out;

            case 'seopress':
                $out = [];
                foreach (self::SEOPRESS_KEYS as $field => $key) {
                    $value       = self::scalar_meta($term_id, $key);
                    $out[$field] = in_array($field, ['noindex', 'nofollow'], true) ? 'yes' === $value : $value;
                }
                return $out;

            case 'seoframework':
                $data = get_term_meta($term_id, self::SEOFRAMEWORK_META, true);
                $data = is_array($data) ? $data : [];
                $out  = [];
                foreach (self::SEOFRAMEWORK_KEYS as $field => $key) {
                    $value       = $data[$key] ?? '';
                    $out[$field] = in_array($field, ['noindex', 'nofollow'], true)
                        ? 1 === (int) $value
                        : (is_scalar($value) ? (string) $value : '');
                }
                return $out;

            case 'slimseo':
                return SEO_Adapter::slim_seo_fields(get_term_meta($term_id, SEO_Adapter::SLIM_SEO_META, true));

            case 'aioseo':
                return Aioseo_Store::get_term($term_id);
        }

        return [];
    }

    /** One term meta value as a string, treating non-scalars as absent. */
    private static function scalar_meta(int $term_id, string $key): string
    {
        $value = get_term_meta($term_id, $key, true);

        return is_scalar($value) ? (string) $value : '';
    }

    /** This term's row inside the Yoast option, always as an array. */
    private static function yoast_row(\WP_Term $term): array
    {
        $option = get_option(self::YOAST_OPTION, []);
        $option = is_array($option) ? $option : [];
        $row    = $option[(string) $term->taxonomy][(int) $term->term_id] ?? [];

        return is_array($row) ? $row : [];
    }

    /**
     * Yoast noindex is tri-state. `false` writes 'default' rather than
     * 'index', matching the post path, where false writes '0' (follow the
     * taxonomy default) rather than '2' (force index).
     */
    private static function update_yoast(\WP_Term $term, array $fields): void
    {
        $row = self::yoast_row($term);

        foreach (self::YOAST_KEYS as $field => $key) {
            if (! array_key_exists($field, $fields)) {
                continue;
            }
            $row[$key] = 'noindex' === $field
                ? ($fields[$field] ? 'noindex' : 'default')
                : (string) $fields[$field];
        }

        // Through the safety layer's writer, so the write and its rollback
        // share one path (see Rollback_Service::write_yoast_term_seo_row()).
        Rollback_Service::write_yoast_term_seo_row((string) $term->taxonomy, (int) $term->term_id, $row);
    }
}
