<?php

namespace WPMCP\Tools\SEO;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read tool over the extended SEO vocabulary (issue #67): the per-post
 * OpenGraph and Twitter overrides the active plugin stores, in one neutral
 * field set regardless of which plugin that is.
 *
 * Plugins whose per-post social storage is not mapped yet return a
 * structured `supported: false` with a reason rather than an error, which is
 * what the issue asks for from unsupported combinations.
 *
 * The extended vocabulary is a paid-tier surface per the issue's tiering.
 * The tier is declared on the Ability and enforced centrally in the
 * Registrar, not re-checked here.
 */
class Get_Social_Meta
{
    public function handle(array $args): array
    {
        $post_id = (int) ($args['post_id'] ?? 0);

        Post_Access::assert_readable($post_id);

        return array_merge(['post_id' => $post_id], SEO_Adapter::get_social_meta($post_id));
    }
}
