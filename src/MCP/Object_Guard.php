<?php

namespace WPMCP\MCP;

use WPMCP\Tools\Content\Content_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The per-object capability rule for objects an ability names outside the
 * post-id keys Content_Guard reads itself: the ids an integration pack
 * operation takes inside its `args` (issue #450). Registrar asks it through
 * Ability::$objects, so the permission decision covers every pack op the
 * same way it covers the content tools, and a refused call never reaches
 * the op.
 *
 * Each entry is ['type' => ..., 'id' => int, 'access' => 'read'|'write'|
 * 'delete'], plus 'own_type' for a post and 'capability' for an entry:
 *
 * - post: Content_Guard::post_object_denial() (read_post, edit_post or
 *   delete_post, no password-protected content without edit_post, and the
 *   content tools' post-type rule unless own_type);
 * - term: reading a term of a public taxonomy is open, otherwise edit_term;
 *   a write takes edit_term and a delete delete_term;
 * - user: the caller themselves is always allowed; reading another user
 *   takes list_users, changing one edit_user, deleting one delete_user;
 * - comment: reading an approved comment on a post the caller may read is
 *   open, anything else takes edit_comment;
 * - order: a WooCommerce order takes edit_shop_orders (delete_shop_orders
 *   to delete), the capability WooCommerce's own order screens require;
 * - entry: a form or plugin entry has no per-row WordPress capability, so
 *   it takes the capability the op names for its host plugin's entries
 *   (manage_options when it names none).
 *
 * An id that names nothing is not refused: the op answers "not found".
 */
final class Object_Guard
{
    public const TYPES = ['post', 'term', 'user', 'comment', 'order', 'entry'];

    /**
     * The audit reason for the first object the current user may not act
     * on, or null when every one is allowed.
     *
     * @param array<int, array<string, mixed>> $objects
     */
    public static function denial(array $objects): ?string
    {
        foreach ($objects as $object) {
            $id     = (int) ($object['id'] ?? 0);
            $access = (string) ($object['access'] ?? 'read');
            if ($id <= 0) {
                continue;
            }
            $type   = (string) ($object['type'] ?? '');
            $denial = 'post' === $type
                ? Content_Guard::post_object_denial($id, $access, empty($object['own_type']))
                : (self::allows($type, $id, $access, (string) ($object['capability'] ?? '')) ? null : 'object-capability');
            if (null !== $denial) {
                return $denial;
            }
        }
        return null;
    }

    private static function allows(string $type, int $id, string $access, string $capability): bool
    {
        switch ($type) {
            case 'term':
                $term = get_term($id);
                if (! $term instanceof \WP_Term) {
                    return true;
                }
                if ('read' === $access) {
                    $taxonomy = get_taxonomy($term->taxonomy);
                    return ($taxonomy instanceof \WP_Taxonomy && $taxonomy->public) || current_user_can('edit_term', $id);
                }
                return current_user_can('delete' === $access ? 'delete_term' : 'edit_term', $id);

            case 'user':
                if (! get_userdata($id) instanceof \WP_User || get_current_user_id() === $id) {
                    return true;
                }
                $cap = ['read' => 'list_users', 'delete' => 'delete_user'][ $access ] ?? 'edit_user';
                return 'list_users' === $cap ? current_user_can($cap) : current_user_can($cap, $id);

            case 'comment':
                $comment = get_comment($id);
                if (! $comment instanceof \WP_Comment) {
                    return true;
                }
                $public = 'read' === $access && '1' === (string) $comment->comment_approved;
                if ($public && null === Content_Guard::post_object_denial((int) $comment->comment_post_ID, 'read', false)) {
                    return true;
                }
                return current_user_can('edit_comment', $id);

            case 'order':
                if (function_exists('wc_get_order') && ! wc_get_order($id)) {
                    return true;
                }
                return current_user_can('delete' === $access ? 'delete_shop_orders' : 'edit_shop_orders');

            case 'entry':
                return current_user_can('' !== $capability ? $capability : 'manage_options');
        }

        // An undeclared kind is a programming error; refuse rather than
        // silently allow.
        return false;
    }
}
