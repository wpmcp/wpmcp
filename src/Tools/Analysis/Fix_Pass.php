<?php

namespace WPMCP\Tools\Analysis;

use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Save_Filters;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The shared contract every auto-fixer in this domain obeys.
 *
 * Two guarantees live here rather than in each fixer, so no fixer can forget
 * one of them:
 *
 * 1. DRY RUN IS THE DEFAULT. `apply` must be explicitly true before a single
 *    byte is written. A dry run returns the complete proposed change set, and
 *    performs zero writes, so an agent always previews before it mutates.
 *
 * 2. ONE PASS, ONE SNAPSHOT, ONE ROLLBACK. Every edit in a fix pass is
 *    spliced into the post content in memory and written by a SINGLE
 *    wp_update_post() inside ONE Safe_Mutation::run(). The pass therefore has
 *    exactly one operation_id, and rolling back that operation reverts the
 *    entire pass rather than leaving a half-fixed post behind.
 *
 * Consequence of (2), and the reason the fixers only ever rewrite
 * post_content: a 'post' snapshot captures that post's row, meta and terms.
 * It cannot revert an edit to a DIFFERENT object, so these fixers deliberately
 * do not also write the media library's `_wp_attachment_image_alt` for the
 * images they touch. Writing it would produce a change that the returned
 * operation_id cannot undo, which is exactly the promise this plugin exists to
 * keep. Callers that also want the library value updated can call
 * wpmcp/update-media, which snapshots the attachment itself.
 */
final class Fix_Pass
{
    /** Validate the post_id argument and return the post. */
    public static function post(array $args): \WP_Post
    {
        $post_id = (int) ($args['post_id'] ?? 0);
        if ($post_id <= 0) {
            throw new \InvalidArgumentException('A post id is required.');
        }

        $post = get_post($post_id);
        if (! $post instanceof \WP_Post) {
            throw new \InvalidArgumentException('Post not found.');
        }

        return $post;
    }

    /** True only when the caller explicitly asked to write; anything else is a dry run. */
    public static function is_apply(array $args): bool
    {
        return true === filter_var($args['apply'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Build the response for a fix pass, writing only when the caller asked
     * for it and there is something to write.
     *
     * @param array<int,array<string,mixed>>                             $proposed
     * @param array<int,array{location:string,reason:string}>            $skipped
     * @param array<int,array{offset:int,length:int,replacement:string}> $edits
     *
     * @return array<string,mixed>
     */
    public static function finish(
        string $tool_name,
        \WP_Post $post,
        array $args,
        array $proposed,
        array $skipped,
        array $edits
    ): array {
        $post_id = (int) $post->ID;
        $apply   = self::is_apply($args);

        $out = [
            'post_id'  => $post_id,
            'dry_run'  => ! $apply,
            'applied'  => false,
            'count'    => count($proposed),
            'proposed' => array_values($proposed),
            'skipped'  => array_values($skipped),
        ];

        if (! $apply) {
            $out['note'] = 'Dry run: nothing was written. Call again with apply=true to write the whole set under one reversible snapshot.';
            return $out;
        }

        // Nothing to do is not a mutation: taking a snapshot here would burn a
        // history slot (the free tier keeps 20) recording a no-op.
        if ([] === $edits) {
            $out['note'] = 'Nothing to fix; no snapshot was taken and nothing was written.';
            return $out;
        }

        $content = Markup_Scanner::splice((string) $post->post_content, $edits);

        $result = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post_id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => $tool_name,
                'args'        => $args,
            ],
            static function () use ($post_id, $content) {
                // The spliced content is the stored (unslashed) body, and
                // wp_update_post() unslashes: slash it or every backslash
                // elsewhere in the post is lost.
                return Save_Filters::update_post(
                    [
                        'ID'           => $post_id,
                        'post_content' => wp_slash($content),
                    ]
                );
            }
        );

        $out['applied']      = true;
        $out['operation_id'] = $result['operation_id'];
        $out['note']         = 'Applied under one snapshot. wpmcp/rollback-operation with this operation_id reverts the entire pass.';

        return $out;
    }

    /**
     * Text of the nearest heading at or above $offset, used by the alt-text
     * fixer as the image's page context. Headings are matched in document
     * order from the same scan as everything else, so the "nearest" heading is
     * genuinely the previous one in the source, not the first one of some tag
     * name.
     *
     * @param array<int,array<string,mixed>> $tags Markup_Scanner::tags() output.
     */
    public static function heading_before(array $tags, int $offset): string
    {
        $heading = '';
        foreach ($tags as $tag) {
            if ((int) $tag['offset'] >= $offset) {
                break;
            }
            if (preg_match('/^h[1-6]$/', (string) $tag['name'])) {
                $text = trim(wp_strip_all_tags((string) $tag['inner']));
                if ('' !== $text) {
                    $heading = $text;
                }
            }
        }
        return $heading;
    }
}
