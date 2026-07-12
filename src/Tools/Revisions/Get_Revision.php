<?php

namespace WPMCP\Tools\Revisions;

if (! defined('ABSPATH')) {
    exit;
}

class Get_Revision
{
    public function handle(array $args): array
    {
        $revision_id = (int) ($args['revision_id'] ?? 0);
        $revision    = $revision_id ? get_post($revision_id) : null;
        if (! $revision || 'revision' !== $revision->post_type) {
            throw new \InvalidArgumentException('Revision not found');
        }

        return [
            'revision_id' => (int) $revision->ID,
            'post_id'     => (int) $revision->post_parent,
            'author_id'   => (int) $revision->post_author,
            'date'        => (string) $revision->post_date,
            'title'       => (string) $revision->post_title,
            'content'     => (string) $revision->post_content,
            'excerpt'     => (string) $revision->post_excerpt,
        ];
    }
}
