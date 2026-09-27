<?php

namespace WPMCP\Tools\SEO;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Per-post OpenGraph and Twitter card storage for the active SEO plugin, in
 * one neutral field set (issue #67, extended vocabulary).
 *
 * Kept out of SEO_Adapter on purpose: the adapter's post-meta surface is the
 * free tier, and this vocabulary is paid, so it lives in its own file that
 * the directory build removes whole instead of carving methods out of a free
 * class with exact-string edits.
 *
 * Yoast, RankMath and SEOPress store the six fields as flat postmeta. The
 * SEO Framework derives its social fields rather than storing a full
 * per-post set, and SureRank packs them into the serialized _surerank_meta
 * array, so both answer with the structured "unsupported" payload rather than
 * a guessed map.
 *
 * The SEO group answers "unsupported" with a payload rather than the
 * WP_Error / `unsupported_*` code the builder tools use, because the issue
 * asks for structured unsupported responses instead of errors: an agent
 * reading social fields on The SEO Framework has asked a sensible question
 * about a real post, and the honest answer is "this plugin does not store
 * that", not a failure.
 */
class Social_Meta
{
    public const FIELDS = [
        'og_title',
        'og_description',
        'og_image',
        'twitter_title',
        'twitter_description',
        'twitter_image',
    ];

    private const MAPS = [
        'yoast'    => [
            'og_title'            => '_yoast_wpseo_opengraph-title',
            'og_description'      => '_yoast_wpseo_opengraph-description',
            'og_image'            => '_yoast_wpseo_opengraph-image',
            'twitter_title'       => '_yoast_wpseo_twitter-title',
            'twitter_description' => '_yoast_wpseo_twitter-description',
            'twitter_image'       => '_yoast_wpseo_twitter-image',
        ],
        'rankmath' => [
            'og_title'            => 'rank_math_facebook_title',
            'og_description'      => 'rank_math_facebook_description',
            'og_image'            => 'rank_math_facebook_image',
            'twitter_title'       => 'rank_math_twitter_title',
            'twitter_description' => 'rank_math_twitter_description',
            'twitter_image'       => 'rank_math_twitter_image',
        ],
        'seopress' => [
            'og_title'            => '_seopress_social_fb_title',
            'og_description'      => '_seopress_social_fb_desc',
            'og_image'            => '_seopress_social_fb_img',
            'twitter_title'       => '_seopress_social_twitter_title',
            'twitter_description' => '_seopress_social_twitter_desc',
            'twitter_image'       => '_seopress_social_twitter_img',
        ],
    ];

    /**
     * The attachment-id companions Yoast and RankMath keep next to each image
     * URL, so their media pickers show the chosen attachment. SEOPress keeps
     * the URL only.
     */
    private const IMAGE_ID_KEYS = [
        'yoast'    => [
            'og_image'      => '_yoast_wpseo_opengraph-image-id',
            'twitter_image' => '_yoast_wpseo_twitter-image-id',
        ],
        'rankmath' => [
            'og_image'      => 'rank_math_facebook_image_id',
            'twitter_image' => 'rank_math_twitter_image_id',
        ],
    ];

    /**
     * Which OpenGraph field each Twitter field falls back to when the active
     * plugin mirrors one onto the other. Same pairing on all three mapped
     * plugins.
     */
    private const TWITTER_OG_FALLBACKS = [
        'twitter_title'       => 'og_title',
        'twitter_description' => 'og_description',
        'twitter_image'       => 'og_image',
    ];

    /** RankMath's per-post switch for rendering Twitter from the OG fields. */
    private const RANKMATH_MIRROR_KEY = 'rank_math_twitter_use_facebook';

    /** Whether the active plugin has a verified per-post social map. */
    public static function supported(): bool
    {
        return isset(self::MAPS[SEO_Adapter::active_plugin()]);
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
                : 'Per-post social fields are not mapped for this plugin yet.',
        ];
    }

    /**
     * Read the per-post social (OG/Twitter) overrides for the active plugin.
     *
     * Returns ['supported' => true, 'fields' => [...], 'sources' => [...]]
     * where mapped, or the structured unsupported() payload otherwise.
     *
     * `fields` is the resolved state, not the raw postmeta. All three mapped
     * plugins fall back to the OpenGraph values when a Twitter field is
     * empty (RankMath gates that on rank_math_twitter_use_facebook, which
     * defaults on), so returning the bare meta would report
     * `twitter_title: ''` for a post whose rendered Twitter card does have a
     * title. `sources` says where each value came from, so an agent can still
     * tell an explicit override from an inherited one before writing:
     *
     * - 'override'  the field is set in the plugin's own postmeta key
     * - 'inherited' empty here, resolved from the corresponding og_ field
     * - 'absent'    nothing set, and nothing to inherit
     */
    public static function get(int $post_id): array
    {
        $active = SEO_Adapter::active_plugin();
        if (! isset(self::MAPS[$active])) {
            return self::unsupported();
        }

        $fields  = [];
        $sources = [];
        foreach (self::MAPS[$active] as $field => $key) {
            $fields[$field]  = (string) get_post_meta($post_id, $key, true);
            $sources[$field] = '' === $fields[$field] ? 'absent' : 'override';
        }

        if (self::twitter_mirrors_og($active, $post_id)) {
            foreach (self::TWITTER_OG_FALLBACKS as $twitter => $og) {
                if ('' === $fields[$twitter] && '' !== $fields[$og]) {
                    $fields[$twitter]  = $fields[$og];
                    $sources[$twitter] = 'inherited';
                }
            }
        }

        return [
            'supported' => true,
            'plugin'    => $active,
            'fields'    => $fields,
            'sources'   => $sources,
        ];
    }

    /**
     * Write the social image for a post: the OpenGraph image, the Twitter
     * image, or both. Raw postmeta writes only: the caller routes this
     * through Safe_Mutation (object_type 'post', whose snapshot carries the
     * full postmeta map) so the change is snapshotted and reversible.
     *
     * A Twitter-only write on RankMath while the post still mirrors Twitter
     * from OpenGraph would be invisible (RankMath renders the OG image), so
     * the mirror is switched off, and the Twitter title and description the
     * card was inheriting are first written as explicit overrides: the
     * rendered card keeps its copy and gains only the new image.
     *
     * @param string $target 'og', 'twitter' or 'both'.
     */
    public static function set_image(int $post_id, string $target, string $url, int $attachment_id): void
    {
        $active = SEO_Adapter::active_plugin();
        if (! isset(self::MAPS[$active])) {
            return;
        }

        $fields = [];
        if ('og' === $target || 'both' === $target) {
            $fields[] = 'og_image';
        }
        if ('twitter' === $target || 'both' === $target) {
            $fields[] = 'twitter_image';
        }

        if ('rankmath' === $active && 'twitter' === $target && self::twitter_mirrors_og($active, $post_id)) {
            $resolved = self::get($post_id)['fields'];
            foreach (['twitter_title', 'twitter_description'] as $field) {
                if ('' !== $resolved[$field]) {
                    update_post_meta($post_id, self::MAPS[$active][$field], $resolved[$field]);
                }
            }
            update_post_meta($post_id, self::RANKMATH_MIRROR_KEY, 'off');
        }

        foreach ($fields as $field) {
            update_post_meta($post_id, self::MAPS[$active][$field], $url);

            $id_key = self::IMAGE_ID_KEYS[$active][$field] ?? '';
            if ('' === $id_key) {
                continue;
            }
            if ($attachment_id > 0) {
                update_post_meta($post_id, $id_key, (string) $attachment_id);
            } else {
                // A URL-only write must not leave the previous attachment id
                // behind, or the plugin's media picker shows the old image.
                delete_post_meta($post_id, $id_key);
            }
        }
    }

    /**
     * Whether the active plugin renders the Twitter card from the OpenGraph
     * fields when the Twitter ones are empty.
     *
     * Yoast and SEOPress always do. RankMath makes it a per-post switch,
     * `rank_math_twitter_use_facebook`, stored as 'on'/'off' and defaulting
     * to on: an unset value means the mirror is active, which is the state of
     * every post on a stock install, so an absent meta must read as true.
     */
    private static function twitter_mirrors_og(string $active, int $post_id): bool
    {
        if ('rankmath' !== $active) {
            return true;
        }

        return 'off' !== (string) get_post_meta($post_id, self::RANKMATH_MIRROR_KEY, true);
    }
}
