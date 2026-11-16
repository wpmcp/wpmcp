<?php

namespace WPMCP\Tools\Users;

use WPMCP\Privacy\Masking;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: list WordPress users as safe summary rows. Never touches
 * Safe_Mutation (reads have nothing to roll back) and never emits secrets:
 * the row is hand-built from a fixed set of public fields, so the password
 * hash (user_pass) and other sensitive columns can never leak into output.
 */
class List_Users
{
    private const VALID_ORDERBY = ['ID', 'login', 'display_name', 'registered', 'email'];

    public function handle(array $args): array
    {
        $per_page = max(1, min(100, (int) ($args['per_page'] ?? 20)));
        $page     = max(1, (int) ($args['page'] ?? 1));
        $orderby  = in_array($args['orderby'] ?? '', self::VALID_ORDERBY, true) ? (string) $args['orderby'] : 'ID';
        $order    = (isset($args['order']) && 'DESC' === strtoupper((string) $args['order'])) ? 'DESC' : 'ASC';

        $query_args = [
            'number'  => $per_page,
            'paged'   => $page,
            'orderby' => $orderby,
            'order'   => $order,
        ];
        if (! empty($args['role'])) {
            $query_args['role'] = sanitize_key((string) $args['role']);
        }
        if (! empty($args['search'])) {
            $query_args['search'] = '*' . sanitize_text_field((string) $args['search']) . '*';
        }

        $query = new \WP_User_Query($query_args);
        $rows  = [];
        foreach ((array) $query->get_results() as $user) {
            $rows[] = $this->row($user);
        }

        return [
            'users' => $rows,
            'total' => (int) $query->get_total(),
            'page'  => $page,
            'masked' => true,
        ];
    }

    private function row(\WP_User $user): array
    {
        return [
            'id'           => (int) $user->ID,
            'username'     => is_email((string) $user->user_login) ? Masking::email((string) $user->user_login) : (string) $user->user_login,
            'display_name' => Masking::value((string) $user->display_name),
            'email'        => Masking::email((string) $user->user_email),
            'roles'        => array_values((array) $user->roles),
            'registered'   => (string) $user->user_registered,
        ];
    }
}
