<?php

namespace WPMCP\Tools\Media\Stock;

use WPMCP\Pro\Gate;
use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * insert-stock-image (issue #64, PRO via Pro\Gate): the composite
 * search → sideload → insert flow's write step. Runs the exact same guarded
 * import as import-stock-image, then appends a Gutenberg image block to the
 * target post through Safe_Mutation. Two independently rollbackable
 * operations come back: undo the insert (content restored) and/or undo the
 * import (attachment deleted). Registration is tier-gated by the Registrar;
 * the handler ALSO fails closed here so a direct call without a live PRO
 * license can never execute (mirrors the live-license re-check stance of
 * issue #54).
 */
class Insert_Stock_Image
{
    public function handle(array $args): array
    {
        if (! Gate::can_use('insert-stock-image')) {
            throw new \RuntimeException('insert-stock-image requires an active wpmcp PRO license.');
        }

        $post_id = (int) ($args['post_id'] ?? 0);
        $post    = $post_id ? get_post($post_id) : null;
        if (! $post || 'attachment' === $post->post_type) {
            throw new \InvalidArgumentException('Post not found');
        }

        $import   = (new Import_Stock_Image())->handle($args);
        $media_id = (int) $import['media_id'];

        $alt   = sanitize_text_field((string) ($args['alt'] ?? ''));
        $block = sprintf(
            '<!-- wp:image {"id":%1$d,"sizeSlug":"full","linkDestination":"none"} -->' .
            '<figure class="wp-block-image size-full"><img src="%2$s" alt="%3$s" class="wp-image-%1$d"/></figure>' .
            '<!-- /wp:image -->',
            $media_id,
            esc_url((string) $import['url']),
            esc_attr($alt)
        );

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post_id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'insert-stock-image',
                'args'        => $args,
            ],
            function () use ($post_id, $block): bool {
                $post    = get_post($post_id);
                $content = (string) $post->post_content;
                $content = ('' === trim($content)) ? $block : $content . "\n\n" . $block;
                $result  = wp_update_post(['ID' => $post_id, 'post_content' => $content], true);
                if (is_wp_error($result)) {
                    throw new \RuntimeException('The image block could not be inserted: ' . esc_html($result->get_error_message()));
                }
                return true;
            }
        );

        return [
            'media_id'            => $media_id,
            'post_id'             => $post_id,
            'url'                 => $import['url'],
            'import_operation_id' => $import['operation_id'],
            'insert_operation_id' => $out['operation_id'],
        ];
    }
}
