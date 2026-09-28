<?php

namespace WPMCP\Tools\Analysis;

use WPMCP\Tools\Content\Content_Extractor;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: given a post id, return the post's readable plain text plus a
 * structural summary (headings, word count, link and image counts) extracted
 * from its stored content. Delegates to Content_Extractor; reads have nothing
 * to roll back, so this never touches Safe_Mutation.
 *
 * Optional `keywords` (issue #295): a positive N adds the top N ranked terms
 * and 2 to 3 word phrases (see Keyword_Extractor, capped at
 * Keyword_Extractor::MAX_LIMIT). Absent or 0 leaves the response unchanged.
 */
class Extract_Content
{
    public function handle(array $args): array
    {
        $post_id = (int) ($args['post_id'] ?? 0);
        if ($post_id <= 0) {
            throw new \InvalidArgumentException('A post id is required.');
        }

        $extract = Content_Extractor::extract($post_id);

        $out = [
            'post_id' => $extract['post_id'],
            'text'    => $extract['text'],
            'summary' => [
                'headings'    => $extract['headings'],
                'word_count'  => $extract['word_count'],
                'link_count'  => count($extract['links']),
                'image_count' => count($extract['images']),
                'form_fields' => $extract['form_fields'],
            ],
        ];

        $keywords = (int) ($args['keywords'] ?? 0);
        $post     = get_post($post_id);
        if ($keywords > 0 && $post instanceof \WP_Post) {
            $out['keywords'] = Keyword_Extractor::extract($post, $keywords);
        }

        return $out;
    }
}
