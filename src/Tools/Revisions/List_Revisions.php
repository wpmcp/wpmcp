<?php

namespace WPMCP\Tools\Revisions;

if (! defined('ABSPATH')) {
    exit;
}

class List_Revisions
{
    public function handle(array $args): array
    {
        $post_id = (int) ($args['post_id'] ?? 0);
        $post    = $post_id ? get_post($post_id) : null;
        if (! $post) {
            throw new \InvalidArgumentException('Post not found');
        }

        $revisions = wp_get_post_revisions($post_id);

        $rows = [];
        foreach ($revisions as $revision) {
            $rows[] = [
                'revision_id' => (int) $revision->ID,
                'author_id'   => (int) $revision->post_author,
                'date'        => (string) $revision->post_date,
                'excerpt'     => wp_trim_words((string) $revision->post_content, 20),
            ];
        }

        return ['revisions' => $rows];
    }
}
