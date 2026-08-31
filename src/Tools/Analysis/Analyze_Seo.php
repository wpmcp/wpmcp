<?php

namespace WPMCP\Tools\Analysis;

use WPMCP\Tools\Content\Content_Extractor;
use WPMCP\Tools\SEO\SEO_Adapter;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: score a post's on-page SEO (0-100 plus severity-tagged findings).
 *
 * The post title comes from get_the_title(); the meta description and focus
 * keyword come from the active SEO plugin via SEO_Adapter, falling back to the
 * post excerpt for the description when no plugin value is set. Content
 * structure (headings, links, images, word count, plain text) comes from
 * Content_Extractor, and the scoring itself is delegated to Seo_Analyzer.
 * Reads have nothing to roll back, so this never touches Safe_Mutation.
 */
class Analyze_Seo
{
    public function handle(array $args): array
    {
        $post_id = (int) ($args['post_id'] ?? 0);
        if ($post_id <= 0) {
            throw new \InvalidArgumentException('A post id is required.');
        }

        $post = get_post($post_id);
        if (! $post) {
            throw new \InvalidArgumentException('Post not found.');
        }

        $focus_keyword = (string) ($args['focus_keyword'] ?? '');

        $extract  = Content_Extractor::extract($post_id);
        $seo_meta = SEO_Adapter::get_meta($post_id);

        $seo = [
            'title'         => get_the_title($post_id),
            'description'   => '' !== $seo_meta['description']
                ? $seo_meta['description']
                : (string) $post->post_excerpt,
            'focus_keyword' => $seo_meta['focus_keyword'],
        ];

        $report = Seo_Analyzer::analyze($extract, $seo, $focus_keyword);

        return [
            'post_id'          => $post_id,
            'seo_plugin'       => SEO_Adapter::active_plugin(),
            'description_source' => '' !== $seo_meta['description'] ? 'seo_plugin' : 'excerpt',
            'report'           => $report,
        ];
    }
}
