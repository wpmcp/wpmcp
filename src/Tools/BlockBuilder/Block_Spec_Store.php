<?php

namespace WPMCP\Tools\BlockBuilder;

use WPMCP\Safety\Mutation_Failed;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Storage for custom block specs as `wpmcp_block` posts (spec in the
 * _wpmcp_block_spec meta, active/inactive via post_status). Ordinary post +
 * postmeta, reversible through the standard trash.
 */
class Block_Spec_Store
{
    public const POST_TYPE = 'wpmcp_block';

    public static function ensure_post_type(): void
    {
        if (! post_type_exists(self::POST_TYPE)) {
            register_post_type(self::POST_TYPE, [
                'public'       => false,
                'show_ui'      => false,
                'show_in_rest' => false,
                'supports'     => ['title'],
            ]);
        }
    }

    /**
     * @param string $status 'publish' (active) or 'draft' (inactive, e.g. a
     *                       marketplace install awaiting review).
     * @return int|\WP_Error
     */
    public static function create(array $spec, string $status = 'publish')
    {
        self::ensure_post_type();
        if (! in_array($status, ['publish', 'draft'], true)) {
            return new \WP_Error('invalid_status', 'status must be publish or draft.');
        }
        $spec = Block_Spec::normalize($spec);

        $id = wp_insert_post([
            'post_type'   => self::POST_TYPE,
            'post_status' => $status,
            'post_title'  => sanitize_text_field((string) $spec['title']),
        ], true);

        if (is_wp_error($id)) {
            return $id;
        }
        $id = (int) $id;
        update_post_meta($id, '_wpmcp_block_spec', $spec);

        return $id;
    }

    public static function update(int $id, array $spec): bool
    {
        if (! self::is_block($id)) {
            return false;
        }
        $spec   = Block_Spec::normalize($spec);
        $result = wp_update_post(['ID' => $id, 'post_title' => sanitize_text_field((string) $spec['title'])], true);
        if (is_wp_error($result) || 0 === $result) {
            return false;
        }
        // update_post_meta() unslashes its value (a template backslash would
        // be lost), and returns false both on failure and when the value is
        // unchanged, so slash on the way in and read the spec back instead of
        // trusting its result.
        update_post_meta($id, '_wpmcp_block_spec', wp_slash($spec));
        return self::get($id) === $spec;
    }

    /**
     * Run one write against an existing block as a snapshotted operation.
     * The single place the Safe_Mutation context for the block tools is built.
     *
     * $write must return true only when the write actually landed. Anything
     * else fails Safe_Mutation's verify hook, which restores the snapshot, so
     * a write that did not happen is never reported as a success with an
     * operation_id attached.
     *
     * @param callable():bool $write
     * @return string|\WP_Error the operation_id, or a WP_Error after a rollback.
     */
    public static function mutate(int $id, string $tool, array $args, callable $write)
    {
        $operation_id = wp_generate_uuid4();
        try {
            Safe_Mutation::run(
                [
                    'object_type'  => 'post',
                    'object_id'    => $id,
                    'operation_id' => $operation_id,
                    'session_id'   => (string) ($args['session_id'] ?? 'default'),
                    'tool_name'    => $tool,
                    'args'         => $args,
                ],
                $write,
                static fn ($landed): bool => true === $landed
            );
        } catch (Mutation_Failed $e) {
            return new \WP_Error('mutation_failed', "The {$tool} write did not land; nothing was changed.");
        } catch (\Throwable $e) {
            Rollback_Service::restore_operation($operation_id);
            return new \WP_Error('mutation_failed', "The {$tool} write failed and was rolled back: " . $e->getMessage());
        }

        return $operation_id;
    }

    public static function get(int $id): ?array
    {
        if (! self::is_block($id)) {
            return null;
        }
        $spec = get_post_meta($id, '_wpmcp_block_spec', true);
        return is_array($spec) ? $spec : null;
    }

    /** @return array<int,array{block_id:int,name:string,title:string,status:string}> */
    public static function all(bool $active_only = false): array
    {
        self::ensure_post_type();
        // No suppress_filters: get_posts() defaults it to true, and the explicit
        // argument is what Plugin Check flags. Not false either, since this reads a
        // plugin-internal post type with no translated content to correct.
        $rows = get_posts([
            'post_type'        => self::POST_TYPE,
            'post_status'      => $active_only ? ['publish'] : ['publish', 'draft'],
            'posts_per_page'   => 200,
            'orderby'          => 'title',
            'order'            => 'ASC',
        ]);

        $out = [];
        foreach ($rows as $row) {
            $spec  = get_post_meta($row->ID, '_wpmcp_block_spec', true);
            $out[] = [
                'block_id' => $row->ID,
                'name'     => is_array($spec) ? (string) ($spec['name'] ?? '') : '',
                'title'    => get_the_title($row),
                'status'   => $row->post_status,
            ];
        }
        return $out;
    }

    public static function is_block(int $id): bool
    {
        return $id > 0 && self::POST_TYPE === get_post_type($id);
    }

    /**
     * The id of the block whose stored spec name is exactly $name, or null.
     *
     * A targeted lookup rather than a scan of all(), which stops at 200 rows:
     * a LIKE on the serialized spec's `"name";s:N:"<name>";` fragment narrows
     * the candidates in SQL, and each candidate is then confirmed against the
     * unserialized spec, since a control or attribute carrying the same name
     * would match the fragment too.
     */
    public static function find_by_name(string $name): ?int
    {
        if ('' === $name) {
            return null;
        }
        self::ensure_post_type();
        $fragment = '"name";s:' . strlen($name) . ':"' . $name . '";';
        $page     = 1;
        do {
            $ids = get_posts([
                'post_type'      => self::POST_TYPE,
                'post_status'    => ['publish', 'draft'],
                'fields'         => 'ids',
                'posts_per_page' => 50,
                'paged'          => $page,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'meta_query'     => [['key' => '_wpmcp_block_spec', 'value' => $fragment, 'compare' => 'LIKE']], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Plugin-internal post type, queried only on a marketplace install; the alternative is loading every spec.
            ]);
            foreach ($ids as $id) {
                $spec = get_post_meta((int) $id, '_wpmcp_block_spec', true);
                if (is_array($spec) && ($spec['name'] ?? null) === $name) {
                    return (int) $id;
                }
            }
            $page++;
        } while (50 === count($ids));

        return null;
    }
}
