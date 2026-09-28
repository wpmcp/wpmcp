<?php

namespace WPMCP\Tools\SEO;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Set a post's social sharing image (OpenGraph, Twitter card, or both)
 * through the active SEO plugin's own postmeta keys (issue #67).
 *
 * Snapshot-first: the write routes through Safe_Mutation with object_type
 * 'post', whose snapshot carries the post's full postmeta map, so
 * rollback-operation restores the previous image (and, on RankMath, the
 * Twitter mirror switch and any copy materialised alongside it) exactly.
 *
 * The image is either a media library attachment (preferred: it must be an
 * image, and its URL is resolved here) or an absolute http(s) URL. On a
 * plugin with no mapped per-post social storage nothing is written, no
 * snapshot is taken, and the structured "unsupported" payload comes back.
 *
 * Paid tier per the issue; enforced centrally by the Registrar.
 */
class Set_Social_Image
{
    private const TARGETS = ['og', 'twitter', 'both'];

    public function handle(array $args): array
    {
        $post_id = (int) ($args['post_id'] ?? 0);

        Post_Access::assert_editable($post_id);

        $target = (string) ($args['target'] ?? 'both');
        if (! in_array($target, self::TARGETS, true)) {
            throw new \InvalidArgumentException('target must be one of: og, twitter, both.');
        }

        [$url, $attachment_id] = self::resolve_image($args);

        if (! Social_Meta::supported()) {
            return array_merge(['post_id' => $post_id], Social_Meta::unsupported());
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post_id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'set-social-image',
                'args'        => $args,
            ],
            static function () use ($post_id, $target, $url, $attachment_id): void {
                Social_Meta::set_image($post_id, $target, $url, $attachment_id);
            }
        );

        return array_merge(
            ['post_id' => $post_id, 'target' => $target, 'image_url' => $url, 'attachment_id' => $attachment_id],
            Social_Meta::get($post_id),
            ['operation_id' => $out['operation_id'], 'recoverable' => true]
        );
    }

    /**
     * Resolve the image to [url, attachment_id]. An attachment id wins when
     * both are given, because it is the one the plugin's media picker can
     * display.
     *
     * @return array{0: string, 1: int}
     */
    private static function resolve_image(array $args): array
    {
        $attachment_id = (int) ($args['attachment_id'] ?? 0);
        if ($attachment_id > 0) {
            if (! wp_attachment_is_image($attachment_id)) {
                throw new \InvalidArgumentException('Attachment ' . (int) $attachment_id . ' is not an image.');
            }
            if (! current_user_can('read_post', $attachment_id)) {
                throw new \RuntimeException('You do not have permission to use attachment ' . (int) $attachment_id . '.');
            }
            $url = wp_get_attachment_url($attachment_id);
            if (! is_string($url) || '' === $url) {
                throw new \InvalidArgumentException('Attachment ' . (int) $attachment_id . ' has no URL.');
            }
            return [$url, $attachment_id];
        }

        $raw = trim((string) ($args['image_url'] ?? ''));
        if ('' === $raw) {
            throw new \InvalidArgumentException('Provide attachment_id or image_url.');
        }

        // Stored, never fetched here, so this validates shape only: an
        // absolute http(s) URL with a host. Anything else (javascript:,
        // data:, a relative path) would end up in an og:image tag verbatim.
        $url    = esc_url_raw($raw, ['http', 'https']);
        $scheme = wp_parse_url($url, PHP_URL_SCHEME);
        $host   = wp_parse_url($url, PHP_URL_HOST);
        if ('' === $url || ! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || '' === $host) {
            throw new \InvalidArgumentException('image_url must be an absolute http or https URL.');
        }

        return [$url, 0];
    }
}
