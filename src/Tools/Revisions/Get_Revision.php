<?php

namespace WPMCP\Tools\Revisions;

use WPMCP\Tools\Content\Code_Posts;

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

        // A theme layout's code is withheld with a note (issue #893).
        $code = Code_Posts::content_note($revision);
        $out  = [
            'revision_id' => (int) $revision->ID,
            'post_id'     => (int) $revision->post_parent,
            'author_id'   => (int) $revision->post_author,
            'date'        => (string) $revision->post_date,
            'title'       => (string) $revision->post_title,
            'content'     => null === $code ? (string) $revision->post_content : '',
            'excerpt'     => null === $code ? (string) $revision->post_excerpt : '',
        ];
        if (null !== $code) {
            $out['code_withheld'] = true;
            $out['note']          = $code;
        }
        return $out;
    }
}
