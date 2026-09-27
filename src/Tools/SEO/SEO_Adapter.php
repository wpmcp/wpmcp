<?php

namespace WPMCP\Tools\SEO;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Maps a neutral SEO field set to the active plugin's postmeta keys, so the
 * SEO tools work identically against either Yoast SEO or RankMath without the
 * tool classes themselves knowing which plugin is installed.
 *
 * Only one plugin is expected to be active on a given site. When detection
 * finds both (not expected, but not impossible), Yoast wins, matching
 * wpmcp_seo_plugin()'s test-harness precedence.
 *
 * The neutral fields are: title, description, focus_keyword, canonical,
 * noindex, nofollow. noindex/nofollow are booleans here even though Yoast
 * stores them as the strings '0'/'1' on the post: the adapter normalizes
 * that string-vs-bool difference on both read and write so callers only ever
 * deal with booleans.
 */
class SEO_Adapter
{
    private const YOAST_KEYS = [
        'title'         => '_yoast_wpseo_title',
        'description'   => '_yoast_wpseo_metadesc',
        'focus_keyword' => '_yoast_wpseo_focuskw',
        'canonical'     => '_yoast_wpseo_canonical',
        'noindex'       => '_yoast_wpseo_meta-robots-noindex',
        'nofollow'      => '_yoast_wpseo_meta-robots-nofollow',
    ];

    private const RANKMATH_KEYS = [
        'title'         => 'rank_math_title',
        'description'   => 'rank_math_description',
        'focus_keyword' => 'rank_math_focus_keyword',
        'canonical'     => 'rank_math_canonical_url',
        'noindex'       => 'rank_math_robots',
        'nofollow'      => 'rank_math_robots',
    ];

    // SEOPress stores each field in its own postmeta key and encodes
    // noindex/nofollow as the string 'yes' (verified against SEOPress source:
    // update_post_meta(..., '_seopress_robots_index', 'yes')).
    private const SEOPRESS_KEYS = [
        'title'         => '_seopress_titles_title',
        'description'   => '_seopress_titles_desc',
        'focus_keyword' => '_seopress_analysis_target_kw',
        'canonical'     => '_seopress_robots_canonical',
        'noindex'       => '_seopress_robots_index',
        'nofollow'      => '_seopress_robots_follow',
    ];

    // The SEO Framework stores each field in its own _genesis_* postmeta key
    // and encodes noindex/nofollow as the string '1' (like Yoast). It has no
    // focus-keyword field, so that slot is empty and skipped on read/write.
    private const THE_SEO_FRAMEWORK_KEYS = [
        'title'         => '_genesis_title',
        'description'   => '_genesis_description',
        'focus_keyword' => '',
        'canonical'     => '_genesis_canonical_uri',
        'noindex'       => '_genesis_noindex',
        'nofollow'      => '_genesis_nofollow',
    ];

    /** Test seam: force the detected plugin. Guarded by WPMCP_TESTING. */
    private static ?string $active_override = null;

    public static function set_active_plugin_for_tests(?string $plugin): void
    {
        if (defined('WPMCP_TESTING') && WPMCP_TESTING) {
            self::$active_override = $plugin;
        }
    }

    /**
     * Which SEO plugin is active: 'yoast', 'rankmath', 'seopress',
     * 'seoframework', 'surerank', or '' when none is.
     */
    public static function active_plugin(): string
    {
        if (null !== self::$active_override) {
            return self::$active_override;
        }

        return self::detect_active_plugin();
    }

    /**
     * The same detection, ignoring the test seam: what the real environment
     * has installed, whatever a test has forced for itself.
     *
     * Public because the test harness's wpmcp_seo_plugin() gates on it. That
     * helper used to restate the checks and had drifted to knowing only
     * Yoast and RankMath, which makes a test skip itself on a SEOPress or
     * SureRank leg while the code it covers is live: a silent green.
     */
    public static function detect_active_plugin(): string
    {
        if (defined('WPSEO_VERSION') || class_exists('WPSEO_Options')) {
            return 'yoast';
        }

        if (class_exists('RankMath')) {
            return 'rankmath';
        }

        // SEOPress: the key map below has existed since the adapter was
        // written, but detection did not, so a SEOPress site reported "no
        // supported plugin". Both constants are defined by the free plugin;
        // the function check covers installs that load the settings layer
        // before the version constant.
        if (defined('SEOPRESS_VERSION') || function_exists('seopress_get_toggle_titles_option')) {
            return 'seopress';
        }

        if (defined('THE_SEO_FRAMEWORK_VERSION') || function_exists('tsf')) {
            return 'seoframework';
        }

        if (defined('SURERANK_VERSION')) {
            return 'surerank';
        }

        return '';
    }

    /**
     * Human-readable plugin name and version for get-seo-status, or null when
     * no supported SEO plugin is active.
     */
    public static function plugin_info(): ?array
    {
        $active = self::active_plugin();

        if ('yoast' === $active) {
            return [
                'plugin'  => 'yoast',
                'name'    => 'Yoast SEO',
                'version' => defined('WPSEO_VERSION') ? WPSEO_VERSION : '',
            ];
        }

        if ('rankmath' === $active) {
            return [
                'plugin'  => 'rankmath',
                'name'    => 'Rank Math',
                'version' => defined('RANK_MATH_VERSION') ? RANK_MATH_VERSION : '',
            ];
        }

        if ('seopress' === $active) {
            return [
                'plugin'  => 'seopress',
                'name'    => 'SEOPress',
                'version' => defined('SEOPRESS_VERSION') ? SEOPRESS_VERSION : '',
            ];
        }

        if ('seoframework' === $active) {
            return [
                'plugin'  => 'seoframework',
                'name'    => 'The SEO Framework',
                'version' => defined('THE_SEO_FRAMEWORK_VERSION') ? THE_SEO_FRAMEWORK_VERSION : '',
            ];
        }

        if ('surerank' === $active) {
            return [
                'plugin'  => 'surerank',
                'name'    => 'SureRank',
                'version' => defined('SURERANK_VERSION') ? SURERANK_VERSION : '',
            ];
        }
        return null;
    }

    /**
     * The postmeta key map for the active plugin, or an empty array when no
     * supported SEO plugin is active.
     */
    public static function meta_keys(): array
    {
        $active = self::active_plugin();

        if ('yoast' === $active) {
            return self::YOAST_KEYS;
        }

        if ('rankmath' === $active) {
            return self::RANKMATH_KEYS;
        }

        if ('seopress' === $active) {
            return self::SEOPRESS_KEYS;
        }

        if ('seoframework' === $active) {
            return self::THE_SEO_FRAMEWORK_KEYS;
        }
        return [];
    }

    /** Read one postmeta value as a string, or '' when the plugin has no key for the field. */
    private static function read_meta(int $post_id, string $key): string
    {
        return '' === $key ? '' : (string) get_post_meta($post_id, $key, true);
    }

    /**
     * SureRank stores every field inside a single serialized `_surerank_meta`
     * post-meta array (page_title, page_description, canonical_url, and
     * post_no_index / post_no_follow encoded as 'yes'/'no'), so it needs
     * dedicated read/write branches rather than the per-field key map.
     */
    private static function get_surerank_meta(int $post_id): array
    {
        $data = get_post_meta($post_id, '_surerank_meta', true);
        $data = is_array($data) ? $data : [];

        return [
            'title'         => (string) ($data['page_title'] ?? ''),
            'description'   => (string) ($data['page_description'] ?? ''),
            'focus_keyword' => '',
            'canonical'     => (string) ($data['canonical_url'] ?? ''),
            'noindex'       => 'yes' === ($data['post_no_index'] ?? ''),
            'nofollow'      => 'yes' === ($data['post_no_follow'] ?? ''),
        ];
    }

    private static function update_surerank_meta(int $post_id, array $fields): void
    {
        $data = get_post_meta($post_id, '_surerank_meta', true);
        $data = is_array($data) ? $data : [];

        $map = [
            'title'       => 'page_title',
            'description' => 'page_description',
            'canonical'   => 'canonical_url',
        ];
        foreach ($map as $field => $sub) {
            if (array_key_exists($field, $fields)) {
                $data[$sub] = (string) $fields[$field];
            }
        }
        if (array_key_exists('noindex', $fields)) {
            $data['post_no_index'] = $fields['noindex'] ? 'yes' : 'no';
        }
        if (array_key_exists('nofollow', $fields)) {
            $data['post_no_follow'] = $fields['nofollow'] ? 'yes' : 'no';
        }

        update_post_meta($post_id, '_surerank_meta', $data);
    }

    /**
     * Read the neutral SEO field set for a post from the active plugin's
     * postmeta keys. noindex/nofollow are normalized to booleans regardless
     * of how the active plugin stores them on the post.
     */
    public static function get_meta(int $post_id): array
    {
        if ('surerank' === self::active_plugin()) {
            return self::get_surerank_meta($post_id);
        }

        $keys   = self::meta_keys();
        $active = self::active_plugin();

        if ([] === $keys) {
            return [
                'title'         => '',
                'description'   => '',
                'focus_keyword' => '',
                'canonical'     => '',
                'noindex'       => false,
                'nofollow'      => false,
            ];
        }

        $out = [
            'title'         => self::read_meta($post_id, $keys['title']),
            'description'   => self::read_meta($post_id, $keys['description']),
            'focus_keyword' => self::read_meta($post_id, $keys['focus_keyword']),
            'canonical'     => self::read_meta($post_id, $keys['canonical']),
        ];

        if ('yoast' === $active || 'seoframework' === $active) {
            $out['noindex']  = '1' === (string) get_post_meta($post_id, $keys['noindex'], true);
            $out['nofollow'] = '1' === (string) get_post_meta($post_id, $keys['nofollow'], true);
        } elseif ('rankmath' === $active) {
            $robots           = get_post_meta($post_id, $keys['noindex'], true);
            $robots           = is_array($robots) ? $robots : [];
            $out['noindex']   = in_array('noindex', $robots, true);
            $out['nofollow']  = in_array('nofollow', $robots, true);
        } elseif ('seopress' === $active) {
            $out['noindex']  = 'yes' === (string) get_post_meta($post_id, $keys['noindex'], true);
            $out['nofollow'] = 'yes' === (string) get_post_meta($post_id, $keys['nofollow'], true);
        } else {
            $out['noindex']  = false;
            $out['nofollow'] = false;
        }

        return $out;
    }

    /**
     * Write a subset of the neutral SEO field set to a post via
     * update_post_meta(), translated to the active plugin's keys and storage
     * format. Only keys present in $fields are written; omitted fields are
     * left untouched. Callers are expected to route this through
     * Safe_Mutation themselves so the change is snapshotted and undoable;
     * this method performs the raw postmeta writes only.
     */
    public static function update_meta(int $post_id, array $fields): void
    {
        if ('surerank' === self::active_plugin()) {
            self::update_surerank_meta($post_id, $fields);
            return;
        }

        $keys   = self::meta_keys();
        $active = self::active_plugin();

        if ([] === $keys) {
            return;
        }

        foreach (['title', 'description', 'focus_keyword', 'canonical'] as $field) {
            if (array_key_exists($field, $fields) && '' !== $keys[$field]) {
                update_post_meta($post_id, $keys[$field], (string) $fields[$field]);
            }
        }

        if ('yoast' === $active || 'seoframework' === $active) {
            if (array_key_exists('noindex', $fields)) {
                update_post_meta($post_id, $keys['noindex'], $fields['noindex'] ? '1' : '0');
            }
            if (array_key_exists('nofollow', $fields)) {
                update_post_meta($post_id, $keys['nofollow'], $fields['nofollow'] ? '1' : '0');
            }
            return;
        }

        if ('rankmath' === $active && (array_key_exists('noindex', $fields) || array_key_exists('nofollow', $fields))) {
            $robots  = get_post_meta($post_id, $keys['noindex'], true);
            $robots  = is_array($robots) ? $robots : [];
            $noindex = array_key_exists('noindex', $fields) ? (bool) $fields['noindex'] : in_array('noindex', $robots, true);
            $nofollow = array_key_exists('nofollow', $fields) ? (bool) $fields['nofollow'] : in_array('nofollow', $robots, true);

            $new_robots = [];
            if ($noindex) {
                $new_robots[] = 'noindex';
            }
            if ($nofollow) {
                $new_robots[] = 'nofollow';
            }

            update_post_meta($post_id, $keys['noindex'], $new_robots);
            return;
        }
        if ('seopress' === $active) {
            if (array_key_exists('noindex', $fields)) {
                update_post_meta($post_id, $keys['noindex'], $fields['noindex'] ? 'yes' : '');
            }
            if (array_key_exists('nofollow', $fields)) {
                update_post_meta($post_id, $keys['nofollow'], $fields['nofollow'] ? 'yes' : '');
            }
        }
    }
}
