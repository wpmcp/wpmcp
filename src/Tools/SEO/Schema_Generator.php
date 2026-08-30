<?php

namespace WPMCP\Tools\SEO;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Builds schema.org JSON-LD structures from a post's own data (title,
 * excerpt, dates, author, featured image, permalink). Pure data assembly:
 * no postmeta writes, no output buffering, no theme hooks. The tool layer
 * (Generate_Schema_Markup) decides exposure and tiering; this class only
 * knows how to shape the graph.
 *
 * Supported types in this first slice: Article, WebPage.
 * TODO(#67): LocalBusiness (needs a site-profile source for address/geo,
 * probably a wpmcp option the agent fills in first) and Product (needs
 * WooCommerce product data when active, plain post fallback otherwise).
 * TODO(#67): merge-awareness: when the active SEO plugin already emits a
 * graph (Yoast/RankMath both do), report that in the proposal so the agent
 * does not double-emit conflicting JSON-LD.
 */
class Schema_Generator
{
    public const SUPPORTED_TYPES = ['Article', 'WebPage'];

    /**
     * Build the JSON-LD array for one post and schema type.
     *
     * @throws \InvalidArgumentException on an unknown type or missing post.
     */
    public static function generate(int $post_id, string $type): array
    {
        $post = get_post($post_id);
        if (! $post instanceof \WP_Post) {
            throw new \InvalidArgumentException('Post not found: ' . $post_id);
        }

        if (! in_array($type, self::SUPPORTED_TYPES, true)) {
            throw new \InvalidArgumentException(
                'Unsupported schema type: ' . $type . '. Supported: ' . implode(', ', self::SUPPORTED_TYPES)
            );
        }

        return 'Article' === $type
            ? self::article($post)
            : self::web_page($post);
    }

    private static function article(\WP_Post $post): array
    {
        $out = array_merge(self::common($post, 'Article'), [
            'headline'      => get_the_title($post),
            'datePublished' => get_post_time('c', true, $post),
            'dateModified'  => get_post_modified_time('c', true, $post),
        ]);

        $author = get_userdata((int) $post->post_author);
        if ($author instanceof \WP_User) {
            $out['author'] = [
                '@type' => 'Person',
                'name'  => $author->display_name,
            ];
        }

        return $out;
    }

    private static function web_page(\WP_Post $post): array
    {
        return array_merge(self::common($post, 'WebPage'), [
            'name'          => get_the_title($post),
            'datePublished' => get_post_time('c', true, $post),
            'dateModified'  => get_post_modified_time('c', true, $post),
        ]);
    }

    /**
     * Fields shared by every supported type: context, type, url, description
     * (excerpt when set), primary image, and publisher (the site itself).
     * The SEO plugin's meta description, when set via SEO_Adapter, wins over
     * the raw excerpt because it is the curated one.
     */
    private static function common(\WP_Post $post, string $type): array
    {
        $out = [
            '@context' => 'https://schema.org',
            '@type'    => $type,
            'url'      => get_permalink($post),
        ];

        $description = SEO_Adapter::get_meta($post->ID)['description'];
        if ('' === $description) {
            $description = (string) $post->post_excerpt;
        }
        if ('' !== $description) {
            $out['description'] = wp_strip_all_tags($description);
        }

        $image = get_the_post_thumbnail_url($post, 'full');
        if (is_string($image) && '' !== $image) {
            $out['image'] = $image;
        }

        $out['publisher'] = [
            '@type' => 'Organization',
            'name'  => get_bloginfo('name'),
            'url'   => home_url('/'),
        ];

        return $out;
    }
}
