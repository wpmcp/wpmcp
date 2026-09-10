<?php

namespace WPMCP\Tools;

use WPMCP\Plugin;
use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only audit log query over wpmcp_snapshots. Only ever reads from
 * Snapshot_Store (via its public table_name() accessor); the safety core
 * itself is never modified. Extends the original bare-limit shape with
 * optional filters, all backward compatible: a caller passing only 'limit'
 * gets identical behavior to before these filters existed.
 */
class List_Operations
{
    /** object_types Rollback_Service::apply_snapshot() knows how to restore. */
    private const RESTORABLE_OBJECT_TYPES = ['post', 'option', 'user', 'comment', 'wc_order'];

    public function handle(array $args): array
    {
        global $wpdb;

        $limit = (int) ($args['limit'] ?? 20);
        $table = Snapshot_Store::table_name();

        [$where, $params] = $this->build_where($args);
        $where_sql         = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $sql = "SELECT operation_id, session_id, tool_name, object_type, object_id, user_id, created_at "
            . "FROM %i {$where_sql} ORDER BY id DESC LIMIT %d";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- wpmcp_snapshots is this plugin's own audit table; $sql is literal fragments from build_where() (this file) with %i/%s/%d placeholders, all bound on this line, which the sniffs cannot follow through the variable. The log must never be stale.
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge([$table], $params, [$limit])), ARRAY_A);

        $count_sql = "SELECT COUNT(*) FROM %i {$where_sql}";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- same shape as $sql above: literal fragments from build_where() (this file) with %i/%s/%d placeholders, all bound on this line; the count must match the live log.
        $total_count = (int) $wpdb->get_var($wpdb->prepare($count_sql, array_merge([$table], $params)));

        $abilities_by_tool = $this->abilities_by_tool_name();

        $ops = array_map(function (array $r) use ($abilities_by_tool) {
            $domain = $abilities_by_tool[$r['tool_name']] ?? null;
            return [
                'operation_id'       => $r['operation_id'],
                'session_id'         => $r['session_id'],
                'tool_name'          => $r['tool_name'],
                'object_type'        => $r['object_type'],
                'object_id'          => (int) $r['object_id'],
                'created_at'         => $r['created_at'],
                'user_id'            => (int) $r['user_id'],
                'domain'             => $domain,
                'rollback_available' => in_array($r['object_type'], self::RESTORABLE_OBJECT_TYPES, true),
            ];
        }, $rows);

        return ['operations' => $ops, 'total_count' => $total_count];
    }

    /**
     * @return array{0: string[], 1: array<int, mixed>}
     */
    private function build_where(array $args): array
    {
        global $wpdb;

        $where  = [];
        $params = [];

        if (isset($args['session_id'])) {
            $where[]  = 'session_id = %s';
            $params[] = (string) $args['session_id'];
        }
        if (isset($args['user_id'])) {
            $where[]  = 'user_id = %d';
            $params[] = (int) $args['user_id'];
        }
        if (isset($args['tool_name'])) {
            $where[]  = 'tool_name = %s';
            $params[] = (string) $args['tool_name'];
        }
        if (isset($args['object_type'])) {
            $where[]  = 'object_type = %s';
            $params[] = (string) $args['object_type'];
        }
        if (isset($args['object_id'])) {
            $where[]  = 'object_id = %d';
            $params[] = (int) $args['object_id'];
        }
        if (isset($args['date_from'])) {
            $where[]  = 'created_at >= %s';
            $params[] = (string) $args['date_from'];
        }
        if (isset($args['date_to'])) {
            $where[]  = 'created_at <= %s';
            $params[] = (string) $args['date_to'];
        }
        if (isset($args['domain'])) {
            $tool_names = array_keys(array_filter(
                $this->abilities_by_tool_name(),
                fn($domain) => $domain === $args['domain']
            ));
            if (empty($tool_names)) {
                // No ability in this domain: force an empty result set rather
                // than an unfiltered one.
                $where[] = '1 = 0';
            } else {
                $placeholders = implode(', ', array_fill(0, count($tool_names), '%s'));
                $where[]      = "tool_name IN ({$placeholders})";
                array_push($params, ...$tool_names);
            }
        }

        return [$where, $params];
    }

    /** @return array<string, string> tool name (without wpmcp/ prefix) => domain */
    private function abilities_by_tool_name(): array
    {
        $map = [];
        foreach (Plugin::instance()->registrar()->all() as $ability) {
            $short_name        = preg_replace('#^wpmcp/#', '', $ability->name);
            $map[$short_name]   = $ability->domain;
        }
        return $map;
    }
}
