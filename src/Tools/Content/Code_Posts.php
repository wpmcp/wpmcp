<?php

namespace WPMCP\Tools\Content;

use WPMCP\Safety\Post_Write_Refused;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Posts whose content or meta is code a theme add-on prints or runs on the
 * front end (issue #893): a Kadence Pro HTML element (kadence_element with
 * _kad_element_type script) keeps raw markup and scripts in post_content,
 * and an Astra Pro code editor layout (astra-advanced-hook with editor_type
 * code_editor) keeps its code in ast-advanced-hook-php-code. The theme
 * layout ops never read or write that code (code_layout); this holds the
 * generic post and meta tools to the same line, in every build, so the code
 * cannot be read or replaced around them.
 *
 * Described as data (post types and meta keys, never an add-on's classes),
 * so it ships in every build and protects a site whether or not the layout
 * ops do. GP Premium's element code is in protected `_`-prefixed meta,
 * which Content_Guard::check_meta() already refuses, so its entry only
 * holds the PHP switch for the status gate below. Other code layouts can be
 * declared through the wpmcp_code_post_rules filter.
 *
 * Nor do the generic tools switch such a layout on (follow-up to #890):
 * publishing it, or setting it to private, pending or future, would run its
 * code, so update-post, duplicate-post and the builder publish ops refuse
 * that with code_layout and point to the layout op, which checks whether
 * the site allows that code. Unpublishing stays open.
 *
 * Undo is not refused: Rollback_Service puts back what a snapshot holds.
 */
final class Code_Posts
{
    public const CODE = 'code_layout';

    /** Statuses that put a layout live, now or later. */
    private const LIVE_STATUSES = [ 'publish', 'private', 'pending', 'future' ];

    /**
     * Post type => rule:
     *  - type_meta / code_types: the meta key holding the layout kind and
     *    the kinds whose post_content is code;
     *  - code_meta: meta keys that hold code, never read or written;
     *  - switch_meta: meta keys that turn stored code on, never written;
     *  - run_meta: meta keys whose non-empty value makes the layout run code;
     *  - message / op / update_op: the refusal, the layout op that reads the
     *    rest and the one that publishes it.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function rules(): array
    {
        $rules = [
            'kadence_element'     => [
                'type_meta'   => '_kad_element_type',
                'code_types'  => [ 'script' ],
                'code_meta'   => [],
                'switch_meta' => [ '_kad_element_type' ],
                'message'     => 'This Kadence element is an HTML code element; its code is edited in wp-admin only.',
                'op'          => 'get-kadence-layout',
                'update_op'   => 'update-kadence-layout',
            ],
            'astra-advanced-hook' => [
                'type_meta'   => 'editor_type',
                'code_types'  => [ 'code_editor' ],
                'code_meta'   => [ 'ast-advanced-hook-php-code' ],
                'switch_meta' => [ 'editor_type' ],
                'message'     => 'This Astra layout is a code snippet (code editor); its code is edited in wp-admin only.',
                'op'          => 'get-astra-layout',
                'update_op'   => 'update-astra-layout',
            ],
            'gp_elements'         => [
                'code_meta'   => [],
                'switch_meta' => [],
                'run_meta'    => [ '_generate_hook_execute_php' ],
                'message'     => 'This GeneratePress hook element executes PHP; PHP execution is switched on in wp-admin only.',
                'op'          => 'get-generatepress-layout',
                'update_op'   => 'update-generatepress-layout',
            ],
        ];

        /**
         * Filters the post types whose content or meta is front-end code the
         * generic post tools must not read or write (issue #893).
         *
         * @param array<string,array<string,mixed>> $rules Post type => rule, as above.
         */
        $filtered = apply_filters('wpmcp_code_post_rules', $rules);
        return is_array($filtered) ? $filtered : $rules;
    }

    /** @return array<string,mixed>|null */
    private static function rule(string $post_type): ?array
    {
        $rule = self::rules()[ $post_type ] ?? null;
        return is_array($rule) ? $rule : null;
    }

    /**
     * The note for a post whose content is code, or null. A revision is
     * judged by the post it belongs to.
     */
    public static function content_note(\WP_Post $post): ?string
    {
        $subject = 'revision' === $post->post_type && $post->post_parent > 0 ? get_post((int) $post->post_parent) : $post;
        if (! $subject instanceof \WP_Post) {
            return null;
        }
        $rule = self::rule((string) $subject->post_type);
        if (null === $rule) {
            return null;
        }
        return self::content_is_code((int) $subject->ID, $rule) ? self::note($rule) : null;
    }

    /** @param array<string,mixed> $rule */
    private static function content_is_code(int $post_id, array $rule): bool
    {
        $type_meta = (string) ($rule['type_meta'] ?? '');
        if ('' === $type_meta) {
            return false;
        }
        $kind = (string) get_post_meta($post_id, $type_meta, true);
        return in_array($kind, (array) ($rule['code_types'] ?? []), true);
    }

    /** @param array<string,mixed> $rule */
    private static function runs_code(int $post_id, array $rule): bool
    {
        if (self::content_is_code($post_id, $rule)) {
            return true;
        }
        foreach ((array) ($rule['run_meta'] ?? []) as $key) {
            if ('' !== (string) get_post_meta($post_id, (string) $key, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Refuse giving a code layout a status that runs its code (publish,
     * private, pending to publish later, future): the layout op checks
     * whether the site allows that code; the generic tools do not switch it
     * on. A status the post already has, and draft, stay open.
     *
     * @throws Post_Write_Refused
     */
    public static function assert_status_allowed(int $post_id, string $status): void
    {
        if (! in_array($status, self::LIVE_STATUSES, true)) {
            return;
        }
        $post = get_post($post_id);
        if (! $post instanceof \WP_Post || $status === (string) $post->post_status) {
            return;
        }
        self::assert_copy_allowed($post, $status);
    }

    /**
     * Refuse a new post in $status that copies $source when $source is a
     * code layout and $status runs its code (duplicate-post).
     *
     * @throws Post_Write_Refused
     */
    public static function assert_copy_allowed(\WP_Post $source, string $status): void
    {
        if (! in_array($status, self::LIVE_STATUSES, true)) {
            return;
        }
        $rule = self::rule((string) $source->post_type);
        if (null === $rule || ! self::runs_code((int) $source->ID, $rule)) {
            return;
        }
        $op = (string) ($rule['update_op'] ?? '');
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Post_Write_Refused escapes the message it is given.
        throw self::refused(trim(sprintf(
            '%1$s Setting it to %2$s would run its code on the site, so the generic post tools do not switch it on.%3$s',
            (string) ($rule['message'] ?? ''),
            esc_html($status),
            '' === $op ? ' Publish it in wp-admin instead.' : sprintf(' Use theme-write %s, which checks whether the site allows that code, or publish it in wp-admin.', $op)
        )), (int) $source->ID);
    }

    /** The note for a post that holds code in meta the reads leave out, or null. */
    public static function meta_note(\WP_Post $post): ?string
    {
        $rule = self::rule((string) $post->post_type);
        if (null === $rule) {
            return null;
        }
        foreach ((array) ($rule['code_meta'] ?? []) as $key) {
            if ('' !== (string) get_post_meta((int) $post->ID, (string) $key, true)) {
                return self::note($rule);
            }
        }
        return null;
    }

    /** Whether $key holds code on a post of $post_type, so it is never read. */
    public static function is_code_meta(string $post_type, string $key): bool
    {
        $rule = self::rule($post_type);
        return null !== $rule && in_array($key, (array) ($rule['code_meta'] ?? []), true);
    }

    /**
     * Refuse a content write that would change a code post's code.
     *
     * @throws Post_Write_Refused
     */
    public static function assert_content_writable(int $post_id, string $content): void
    {
        $post = get_post($post_id);
        if (! $post instanceof \WP_Post || (string) $post->post_content === $content) {
            return;
        }
        $note = self::content_note($post);
        if (null !== $note) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Post_Write_Refused escapes the message it is given.
            throw self::refused($note, $post_id);
        }
    }

    /**
     * Refuse meta writes to code keys or keys that switch code on. With no
     * post type (a site-wide pass), every declared key is refused.
     *
     * @param string[] $keys
     * @throws Post_Write_Refused
     */
    public static function assert_meta_writable(?string $post_type, array $keys, int $post_id = 0): void
    {
        $rules = null === $post_type ? self::rules() : array_filter([ $post_type => self::rule($post_type) ]);
        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $guarded = array_merge((array) ($rule['code_meta'] ?? []), (array) ($rule['switch_meta'] ?? []));
            foreach ($keys as $key) {
                if (in_array((string) $key, $guarded, true)) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Post_Write_Refused escapes the message it is given.
                    throw self::refused(sprintf('Meta key "%s" holds or switches on layout code. %s', esc_html((string) $key), self::note($rule)), $post_id);
                }
            }
        }
    }

    /** @param array<string,mixed> $rule */
    private static function note(array $rule): string
    {
        $op = (string) ($rule['op'] ?? '');
        return trim((string) ($rule['message'] ?? '') . ('' === $op ? '' : sprintf(' The generic post tools do not read or change it; its hook and display rules are on theme-read %s.', $op)));
    }

    private static function refused(string $message, int $post_id): Post_Write_Refused
    {
        $data = [ 'status' => 403 ] + ($post_id > 0 ? [ 'post_id' => $post_id ] : []);
        return new Post_Write_Refused(new \WP_Error(self::CODE, $message . ' Nothing was written.', $data));
    }
}
