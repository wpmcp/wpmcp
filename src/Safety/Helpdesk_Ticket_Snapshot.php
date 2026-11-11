<?php

// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional (matches the rest of WPMCP\Safety).
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional (matches the rest of WPMCP\Safety).
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads and restores a support plugin's own rows live; there is nothing to cache behind.

namespace WPMCP\Safety;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Snapshots of everything one help desk ticket write can change (issue
 * #708): a reply, an internal note or a status change on a Fluent Support
 * or Awesome Support ticket. The key is "<kind>:<ticket id>".
 *
 * Fluent Support keeps a ticket as a row of {prefix}fs_tickets and its
 * thread as rows of {prefix}fs_conversations (replies, notes and the
 * internal_info lines it writes for a close or a reopen), with per-reply
 * settings in {prefix}fs_meta and files in {prefix}fs_attachments. Awesome
 * Support keeps a ticket as a `ticket` post with its state in post meta
 * (_wpas_status, reply counters, last reply date, ...) and its thread as
 * child posts (ticket_reply, plus the ticket_history and ticket_log lines
 * it writes), and counts each agent's open tickets in the wpas_open_tickets
 * user option.
 *
 * The snapshot is taken before the write, and the write records its after
 * image (record_after()). The restore then undoes only what the write did,
 * so a customer reply or an agent's edit made since is never lost:
 *  - thread rows or posts the write created are deleted (a Fluent Support
 *    reply with its meta and attachment rows);
 *  - every ticket column, meta key, reply status or counter the write
 *    changed is put back when it still holds what the write left there,
 *    and left alone with a warning when someone changed it since.
 *
 * What a ticket write cannot take back is mail: a reply the plugin emailed
 * to the customer stays in their inbox after its undo. Restoring takes the
 * plugin's own ticket permission (Fluent Support: an agent who may manage
 * the ticket, or manage_options; Awesome Support: edit_ticket), since the
 * snapshot holds the customer's messages.
 */
final class Helpdesk_Ticket_Snapshot
{
    public const TYPE = 'helpdesk_ticket';

    public const KINDS = [ 'fluent_support', 'awesome_support' ];

    /** Awesome Support's thread post types, the reply first. */
    public const AWESOME_CHILD_TYPES = [ 'ticket_reply', 'ticket_history', 'ticket_log' ];

    /** Awesome Support's per-agent open ticket counter (a user option). */
    public const AWESOME_OPEN_COUNTER = 'wpas_open_tickets';

    public static function key(string $kind, int $ticket_id): string
    {
        return $kind . ':' . $ticket_id;
    }

    /** @return array{kind: string, id: int} */
    public static function parse(string $key): array
    {
        [$kind, $id] = array_pad(explode(':', $key, 2), 2, '');
        return [ 'kind' => in_array($kind, self::KINDS, true) ? $kind : '', 'id' => max(0, (int) $id) ];
    }

    /** A Fluent Support table, prefixed. */
    public static function fs_table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'fs_' . $name;
    }

    public static function capture(string $key): array
    {
        $parsed = self::parse($key);
        $data   = [ 'kind' => $parsed['kind'], 'id' => $parsed['id'] ];
        if ('' !== $parsed['kind'] && $parsed['id'] > 0) {
            $data['before'] = self::image($parsed['kind'], $parsed['id']);
        }
        return [ 'object_type' => self::TYPE, 'object_id' => $key, 'data' => $data ];
    }

    /**
     * Complete a snapshot with the write's after image. Called by the
     * write, also when it failed half way, so the undo point always matches
     * what is there now.
     */
    public static function record_after(string $operation_id): void
    {
        $row = '' === $operation_id ? null : Snapshot_Store::get_by_operation($operation_id);
        if (null === $row || self::TYPE !== ($row['snapshot']['object_type'] ?? '')) {
            return;
        }
        $snapshot = (array) $row['snapshot'];
        $data     = (array) $snapshot['data'];
        if (! isset($data['before'])) {
            return;
        }
        $data['after']    = self::image((string) $data['kind'], (int) $data['id']);
        $snapshot['data'] = $data;
        Snapshot_Store::update_snapshot($operation_id, $snapshot);
    }

    /** Whether the recorded write changed nothing. */
    public static function unchanged(string $operation_id): bool
    {
        $row  = Snapshot_Store::get_by_operation($operation_id);
        $data = (array) ($row['snapshot']['data'] ?? []);
        return null !== $row && isset($data['after']) && ($data['before'] ?? null) === $data['after'];
    }

    /**
     * Everything a write to the ticket can move, read live.
     *
     * @return array<string, mixed>
     */
    public static function image(string $kind, int $id): array
    {
        return 'fluent_support' === $kind ? self::fluent_image($id) : self::awesome_image($id);
    }

    // ------------------------------------------------------------ Fluent Support

    /** @return array<string, mixed> */
    private static function fluent_image(int $id): array
    {
        global $wpdb;
        if (! self::table_exists(self::fs_table('tickets'))) {
            return [ 'exists' => false ];
        }
        $ticket = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::fs_table('tickets'), $id), ARRAY_A);
        $convos = array_map('intval', (array) $wpdb->get_col($wpdb->prepare('SELECT id FROM %i WHERE ticket_id = %d ORDER BY id ASC', self::fs_table('conversations'), $id)));
        return [
            'exists'        => true,
            'ticket'        => is_array($ticket) ? $ticket : null,
            'conversations' => $convos,
        ];
    }

    /** @return string[] warnings */
    private static function restore_fluent(int $id, array $before, array $after): array
    {
        global $wpdb;
        $warnings = [];
        if (empty($before['exists']) || ! self::table_exists(self::fs_table('tickets'))) {
            return $warnings;
        }

        $created = array_values(array_diff(array_map('intval', (array) ($after['conversations'] ?? [])), array_map('intval', (array) ($before['conversations'] ?? []))));
        if ([] !== $created) {
            $in = implode(',', array_fill(0, count($created), '%d'));
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d placeholders.
            $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE ticket_id = %d AND id IN ($in)", self::fs_table('conversations'), $id, ...$created));
            if (self::table_exists(self::fs_table('meta'))) {
                $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE object_type = 'response' AND object_id IN ($in)", self::fs_table('meta'), ...$created));
            }
            if (self::table_exists(self::fs_table('attachments'))) {
                $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE ticket_id = %d AND conversation_id IN ($in)", self::fs_table('attachments'), $id, ...$created));
            }
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        }

        $old = (array) ($before['ticket'] ?? []);
        $new = (array) ($after['ticket'] ?? []);
        if ([] === $old || [] === $new) {
            return $warnings;
        }
        $now = (array) $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::fs_table('tickets'), $id), ARRAY_A);
        if ([] === $now) {
            return [ sprintf('Fluent Support ticket %d is gone; its fields were not restored.', $id) ];
        }
        $set = [];
        foreach ($old as $column => $value) {
            if ('updated_at' === $column || ! array_key_exists($column, $new) || $new[ $column ] === $value) {
                continue;
            }
            if (($now[ $column ] ?? null) !== $new[ $column ]) {
                $warnings[] = sprintf('Fluent Support ticket %d: %s was changed since, so it was left as it is.', $id, $column);
                continue;
            }
            $set[ $column ] = $value;
        }
        if ([] !== $set) {
            if (array_key_exists('updated_at', $old)) {
                $set['updated_at'] = current_time('mysql');
            }
            $wpdb->update(self::fs_table('tickets'), $set, [ 'id' => $id ]);
        }
        return $warnings;
    }

    // ------------------------------------------------------------ Awesome Support

    /** @return array<string, mixed> */
    private static function awesome_image(int $id): array
    {
        global $wpdb;
        $post = get_post($id);
        if (! $post instanceof \WP_Post) {
            return [ 'exists' => false ];
        }
        $in       = implode(',', array_fill(0, count(self::AWESOME_CHILD_TYPES), '%s'));
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %s placeholders.
        $rows     = (array) $wpdb->get_results($wpdb->prepare("SELECT ID, post_type, post_status, post_date_gmt FROM {$wpdb->posts} WHERE post_parent = %d AND post_type IN ($in) ORDER BY ID ASC", $id, ...self::AWESOME_CHILD_TYPES), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $children = [];
        foreach ($rows as $row) {
            $children[ (string) $row['ID'] ] = [ 'type' => (string) $row['post_type'], 'status' => (string) $row['post_status'], 'date_gmt' => (string) $row['post_date_gmt'] ];
        }
        $meta = [];
        foreach ((array) get_post_meta($id) as $key => $values) {
            $meta[ (string) $key ] = array_map('strval', (array) $values);
        }
        ksort($meta);
        $counters = [];
        foreach ([ '_wpas_assignee', '_wpas_secondary_assignee', '_wpas_tertiary_assignee' ] as $key) {
            $agent = (int) get_post_meta($id, $key, true);
            if ($agent > 0) {
                $value                       = get_user_option(self::AWESOME_OPEN_COUNTER, $agent);
                $counters[ (string) $agent ] = false === $value ? null : (string) maybe_serialize($value);
            }
        }
        return [
            'exists'   => true,
            'post'     => [ 'post_status' => (string) $post->post_status ],
            'meta'     => $meta,
            'children' => $children,
            'counters' => $counters,
        ];
    }

    /** @return string[] warnings */
    private static function restore_awesome(int $id, array $before, array $after): array
    {
        global $wpdb;
        $warnings = [];
        if (empty($before['exists'])) {
            return $warnings;
        }
        $now = self::awesome_image($id);
        if (empty($now['exists'])) {
            return [ sprintf('Awesome Support ticket %d is gone; it was not restored.', $id) ];
        }

        // Thread posts the write created: its reply and the history lines.
        $old_children = (array) ($before['children'] ?? []);
        foreach ((array) ($after['children'] ?? []) as $child_id => $child) {
            if (isset($old_children[ $child_id ])) {
                continue;
            }
            $post = get_post((int) $child_id);
            if ($post instanceof \WP_Post && (int) $post->post_parent === $id && (string) $post->post_date_gmt === (string) $child['date_gmt']) {
                wp_delete_post((int) $child_id, true);
            }
        }
        // Replies the write marked read go back to unread.
        foreach ($old_children as $child_id => $child) {
            $was  = (string) $child['status'];
            $left = (string) ($after['children'][ $child_id ]['status'] ?? $was);
            if ($left === $was) {
                continue;
            }
            if ((string) ($now['children'][ $child_id ]['status'] ?? '') !== $left) {
                $warnings[] = sprintf('Awesome Support reply %d was changed since, so its status was left as it is.', (int) $child_id);
                continue;
            }
            $wpdb->update($wpdb->posts, [ 'post_status' => $was ], [ 'ID' => (int) $child_id ]);
            clean_post_cache((int) $child_id);
        }

        // The ticket's own post status.
        $was  = (string) ($before['post']['post_status'] ?? '');
        $left = (string) ($after['post']['post_status'] ?? $was);
        if ($was !== $left) {
            if ((string) $now['post']['post_status'] === $left) {
                $wpdb->update($wpdb->posts, [ 'post_status' => $was ], [ 'ID' => $id ]);
            } else {
                $warnings[] = sprintf('Awesome Support ticket %d: its status was changed since, so it was left as it is.', $id);
            }
        }

        // Meta keys the write changed, added or removed.
        $old_meta = (array) ($before['meta'] ?? []);
        $new_meta = (array) ($after['meta'] ?? []);
        foreach (array_unique(array_merge(array_keys($old_meta), array_keys($new_meta))) as $key) {
            $was  = $old_meta[ $key ] ?? null;
            $left = $new_meta[ $key ] ?? null;
            if ($was === $left) {
                continue;
            }
            if (($now['meta'][ $key ] ?? null) !== $left) {
                $warnings[] = sprintf('Awesome Support ticket %d: %s was changed since, so it was left as it is.', $id, $key);
                continue;
            }
            delete_post_meta($id, (string) $key);
            foreach ((array) $was as $value) {
                add_post_meta($id, (string) $key, wp_slash(maybe_unserialize($value)));
            }
        }

        // Agents' open ticket counters.
        foreach ((array) ($before['counters'] ?? []) as $agent => $was) {
            $left = $after['counters'][ $agent ] ?? $was;
            if ($was === $left) {
                continue;
            }
            $current = get_user_option(self::AWESOME_OPEN_COUNTER, (int) $agent);
            if ((false === $current ? null : (string) maybe_serialize($current)) !== $left) {
                continue;
            }
            if (null === $was) {
                delete_user_option((int) $agent, self::AWESOME_OPEN_COUNTER);
            } else {
                update_user_option((int) $agent, self::AWESOME_OPEN_COUNTER, maybe_unserialize($was));
            }
        }

        clean_post_cache($id);
        delete_transient('wpas_activity_meta_post_' . $id);
        if (function_exists('wpas_clean_ticketcount_cache')) {
            wpas_clean_ticketcount_cache();
        }
        return $warnings;
    }

    // ------------------------------------------------------------ restore

    /**
     * Undo the recorded write. Returns a warning naming what was left as
     * it is, or null.
     *
     * @throws Mutation_Failed When the current user may not restore it.
     */
    public static function restore(array $snapshot): ?string
    {
        $data = (array) ($snapshot['data'] ?? []);
        $kind = (string) ($data['kind'] ?? '');
        $id   = (int) ($data['id'] ?? 0);
        if (! in_array($kind, self::KINDS, true) || $id <= 0 || ! isset($data['before'], $data['after'])) {
            return null;
        }
        self::authorize($kind, $id);
        $warnings = 'fluent_support' === $kind
            ? self::restore_fluent($id, (array) $data['before'], (array) $data['after'])
            : self::restore_awesome($id, (array) $data['before'], (array) $data['after']);
        $warnings[] = empty($data['notified']) ? '' : sprintf('The reply was removed from ticket %d, but the email the plugin sent the customer cannot be unsent.', $id);
        $warnings   = array_values(array_filter($warnings));
        return [] === $warnings ? null : implode(' ', $warnings);
    }

    /** The WordPress capability a restore of this kind needs, before the plugin's own check. */
    public static function capability(string $kind): string
    {
        return 'awesome_support' === $kind ? 'edit_ticket' : 'edit_posts';
    }

    private static function authorize(string $kind, int $id): void
    {
        if (current_user_can('manage_options')) {
            return;
        }
        $manager = '\\FluentSupport\\App\\Modules\\PermissionManager';
        $allowed = 'awesome_support' === $kind
            ? current_user_can('edit_ticket')
            : class_exists($manager) && $manager::canManageTickets() && (bool) $manager::canAccessTicket((object) [ 'id' => $id, 'agent_id' => self::fluent_agent_of($id), 'mailbox_id' => self::fluent_mailbox_of($id) ]);
        if (! $allowed) {
            throw new Mutation_Failed(esc_html(sprintf('Rollback refused: restoring a %s ticket needs that plugin\'s permission to manage ticket %d.', 'awesome_support' === $kind ? 'Awesome Support' : 'Fluent Support', $id)));
        }
    }

    private static function fluent_agent_of(int $id): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT agent_id FROM %i WHERE id = %d', self::fs_table('tickets'), $id));
    }

    private static function fluent_mailbox_of(int $id): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT mailbox_id FROM %i WHERE id = %d', self::fs_table('tickets'), $id));
    }

    private static function table_exists(string $table): bool
    {
        return Plugin_Table_Rows_Snapshot::table_exists($table);
    }
}
