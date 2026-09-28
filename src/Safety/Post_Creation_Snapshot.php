<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Safety).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Safety).

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The ledger row for a tool that CREATED posts (create-post, duplicate-post,
 * create-custom-widget, create-custom-block), issue #192.
 *
 * Like 'page_build' and 'media_import', a 'post_create' row records what the
 * operation created, because there is no prior state to capture. It exists
 * for two readers: Change_Set_Builder, so a change set derived from a build
 * session lists the posts that session created, and Rollback_Service, so
 * rollback-operation and rollback-session can undo the creation.
 *
 * Unlike those two, undoing a 'post_create' row is non-destructive: an
 * ordinary post is moved to the trash (restore-post brings it back) and a
 * custom widget or block spec is deactivated (post status draft, the spec
 * store's own "inactive"). Nothing is permanently deleted.
 *
 * One row per operation, even when the operation created several posts
 * (duplicate-post with include_children): object_id is the primary post and
 * data.created lists every post, primary first. post_date_gmt is captured per
 * post for the identity check every creation restore makes, so a rollback
 * never touches a different post that has since reclaimed an id.
 *
 * The row is written AFTER the posts exist (their ids cannot be known
 * before). If it cannot be written, the posts this call just created are
 * removed again and the error is rethrown: a creation is never left behind
 * without its undo point.
 */
class Post_Creation_Snapshot
{
    public const OBJECT_TYPE = 'post_create';

    /**
     * @param int[] $post_ids The created posts, primary first.
     * @return string The operation id of the written row.
     * @throws Mutation_Failed When the row could not be written.
     */
    public static function record(string $tool_name, array $post_ids, array $args, string $session_id): string
    {
        $post_ids = array_values(array_filter(array_map('intval', $post_ids)));
        if ([] === $post_ids) {
            throw new \InvalidArgumentException('A creation row needs at least one created post.');
        }

        $created = [];
        foreach ($post_ids as $post_id) {
            $post      = get_post($post_id);
            $created[] = [
                'post_id'       => $post_id,
                'post_type'     => $post ? (string) $post->post_type : '',
                'post_date_gmt' => $post ? (string) $post->post_date_gmt : '',
            ];
        }

        $operation_id = wp_generate_uuid4();
        try {
            Snapshot_Store::save(
                $operation_id,
                '' === $session_id ? 'default' : $session_id,
                [
                    'object_type' => self::OBJECT_TYPE,
                    'object_id'   => $post_ids[0],
                    'data'        => ['created' => $created],
                ],
                $tool_name,
                hash('sha256', (string) wp_json_encode($args))
            );
        } catch (\Throwable $e) {
            // No undo point, no creation. These posts were created by this
            // same call a moment ago and nothing else can reference them yet.
            foreach (array_reverse($post_ids) as $post_id) {
                wp_delete_post($post_id, true);
            }
            throw $e;
        }
        Operation_Context::note($operation_id);
        Snapshot_Store::prune();

        return $operation_id;
    }

    /**
     * The post ids a 'post_create' snapshot covers, primary first.
     *
     * @return int[]
     */
    public static function post_ids(array $snapshot): array
    {
        $ids = [];
        foreach ((array) ($snapshot['data']['created'] ?? []) as $entry) {
            $id = (int) ($entry['post_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if ([] === $ids && (int) ($snapshot['object_id'] ?? 0) > 0) {
            $ids[] = (int) $snapshot['object_id'];
        }
        return $ids;
    }
}
