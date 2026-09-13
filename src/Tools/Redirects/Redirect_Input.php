<?php

namespace WPMCP\Tools\Redirects;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shared argument validation for create-redirect / update-redirect.
 *
 * Kept in one place so the two write tools cannot drift apart on what a
 * legal redirect is, and so every rule is unit-testable without a database.
 * Everything here throws \InvalidArgumentException with an actionable
 * message: an agent that gets "source path is already redirected by #7, use
 * update-redirect" can fix its own call, where a silent clamp or a generic
 * failure would just make it retry the same mistake.
 */
class Redirect_Input
{
    /** Validate and normalize a source path. */
    public static function source(string $raw): string
    {
        $source = Redirect_Store::normalize_path($raw);

        if ('/' === $source) {
            throw new \InvalidArgumentException(
                'A redirect needs a non-empty source path; the site root cannot be redirected.'
            );
        }
        if (strlen($source) > Redirect_Store::MAX_SOURCE_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'Source path is %d characters; the maximum is %d.',
                (int) strlen($source),
                (int) Redirect_Store::MAX_SOURCE_LENGTH
            ));
        }

        return $source;
    }

    /**
     * Resolve the target half of a write into a (url, post_id) pair.
     *
     * A target_post_id is stored as an id, never as a frozen permalink: the
     * redirect then resolves at match time and keeps working after the target
     * post's own slug changes. A plain target is stored as given.
     *
     * @param array<string,mixed> $args
     * @return array{0:string,1:int}
     */
    public static function target(array $args): array
    {
        $has_post_id = isset($args['target_post_id']) && '' !== $args['target_post_id'];
        $has_url     = isset($args['target']) && '' !== trim((string) $args['target']);

        if ($has_post_id && $has_url) {
            throw new \InvalidArgumentException(
                'Pass either "target" or "target_post_id", not both: the post id already determines the URL.'
            );
        }
        if (! $has_post_id && ! $has_url) {
            throw new \InvalidArgumentException('A redirect needs a "target" URL/path or a "target_post_id".');
        }

        if ($has_post_id) {
            $post_id = (int) $args['target_post_id'];
            $post    = $post_id > 0 ? get_post($post_id) : null;
            if (! $post) {
                throw new \InvalidArgumentException(sprintf('Target post %d does not exist.', (int) $post_id));
            }
            $permalink = get_permalink($post_id);
            return [is_string($permalink) ? $permalink : '', $post_id];
        }

        return [trim((string) $args['target']), 0];
    }

    /** Validate a status code, defaulting to a permanent 301. */
    public static function status_code(array $args): int
    {
        if (! isset($args['status_code']) || '' === $args['status_code']) {
            return 301;
        }

        $code = (int) $args['status_code'];
        if (! in_array($code, Redirect_Store::ALLOWED_STATUS_CODES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Status code %d is not a supported redirect code (%s).',
                (int) $code,
                esc_html(implode(', ', Redirect_Store::ALLOWED_STATUS_CODES))
            ));
        }

        return $code;
    }

    public static function notes(array $args, string $fallback = ''): string
    {
        if (! isset($args['notes'])) {
            return $fallback;
        }
        return substr(sanitize_text_field((string) $args['notes']), 0, 255);
    }
}
