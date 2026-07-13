<?php

namespace WPMCP\Tools\Diagnostics;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: list transients (name and expiry) from the options table,
 * matching the same "_transient_" and "_transient_timeout_" row pairs
 * Clear_Cache already enumerates. Supports an optional 'search' substring
 * filter on the transient name and a 'limit' cap (default DEFAULT_LIMIT,
 * hard-capped at MAX_LIMIT) so a site with a large options table cannot be
 * used to dump an unbounded result set. Reads have nothing to roll back, so
 * this never touches Safe_Mutation.
 */
class List_Transients
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT     = 500;

    public function handle(array $args): array
    {
        global $wpdb;

        $search = isset($args['search']) ? (string) $args['search'] : '';
        $limit  = isset($args['limit']) ? max(1, (int) $args['limit']) : self::DEFAULT_LIMIT;
        $limit  = min($limit, self::MAX_LIMIT);

        $like = $wpdb->esc_like('_transient_') . '%';
        $params = [$like];

        $name_clause = '';
        if ('' !== $search) {
            $name_clause = ' AND option_name LIKE %s';
            $params[]    = '%' . $wpdb->esc_like($search) . '%';
        }

        $sql = "SELECT option_name FROM {$wpdb->options}
                WHERE option_name LIKE %s
                AND option_name NOT LIKE '\_transient\_timeout\_%'"
                . $name_clause
                . ' ORDER BY option_name ASC LIMIT ' . (int) $limit;

        $rows = $wpdb->get_col($wpdb->prepare($sql, $params));

        $transients = [];
        foreach ((array) $rows as $option_name) {
            $name       = substr($option_name, strlen('_transient_'));
            $timeout    = get_option('_transient_timeout_' . $name);
            $transients[] = [
                'name'       => $name,
                'expiration' => '' === $timeout ? null : (int) $timeout,
            ];
        }

        return ['transients' => $transients];
    }
}
