<?php

namespace WPMCP\Tools\Analysis;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: given a post id, return the post's readable plain text plus a
 * structural summary (headings, word count, link and image counts) extracted
 * from its stored content. Delegates to Content_Extractor; reads have nothing
 * to roll back, so this never touches Safe_Mutation.
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

        return [
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
    }
}
