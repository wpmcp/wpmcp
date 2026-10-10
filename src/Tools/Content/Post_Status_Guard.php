<?php

namespace WPMCP\Tools\Content;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The capabilities WordPress asks before a post of a type is created or put
 * in a publishing status (issue #482), applied the way core's REST posts
 * controller applies them:
 *
 * - creating a post of a type needs that type's create_posts;
 * - publish, future and private need the type's publish_posts, so a
 *   Contributor may save drafts and submit posts as pending, and nothing
 *   goes live or private without the capability the block editor asks for.
 *
 * The write tools are gated on edit_posts and the per-post edit_post, which
 * every Contributor holds for their own drafts, and wp_insert_post() checks
 * nothing itself, so each tool that creates a post or sets a status asks
 * here before it writes.
 *
 * A call with no user is server-side code (a migration, a test, a
 * scheduled task), never an MCP caller: the Registrar refuses every ability
 * to a logged-out request before its handler runs. Such a call is left to
 * wp_insert_post(), which trusts its caller the same way.
 */
final class Post_Status_Guard
{
    /** Statuses core gates on the type's publish_posts. */
    public const PUBLISHING = ['publish', 'future', 'private'];

    /**
     * Why the current user may not create a post of $post_type (when
     * $creating) or give one the status $status; null when allowed. An
     * unregistered type is left to the caller, which refuses it itself.
     */
    public static function denial(string $post_type, string $status, bool $creating): ?string
    {
        $type = get_post_type_object($post_type);
        if (0 === get_current_user_id() || ! $type instanceof \WP_Post_Type) {
            return null;
        }

        if ($creating && ! current_user_can($type->cap->create_posts)) {
            return sprintf('Creating a %s needs the %s capability.', $post_type, $type->cap->create_posts);
        }

        if (in_array($status, self::PUBLISHING, true) && ! current_user_can($type->cap->publish_posts)) {
            return sprintf(
                'Setting a %s to %s needs the %s capability. Save it as a draft, or as pending to submit it for review.',
                $post_type,
                $status,
                $type->cap->publish_posts
            );
        }

        return null;
    }

    /**
     * denial(), thrown.
     *
     * @throws \RuntimeException When the current user may not make the write.
     */
    public static function assert(string $post_type, string $status, bool $creating): void
    {
        $denial = self::denial($post_type, $status, $creating);
        if (null !== $denial) {
            throw new \RuntimeException(esc_html($denial));
        }
    }
}
