<?php

namespace WPMCP\Tools\Users;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only: return the profile detail for one user, plus an is_admin flag
 * derived from live capabilities (not a role-name string match), so a user
 * granted admin-level capabilities by any means is flagged. Never emits the
 * password hash: the response is hand-built from a fixed set of public
 * profile fields.
 */
class Get_User
{
    public function handle(array $args): array
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('A user id is required.');
        }

        $user = get_userdata($id);
        if (! $user || ! Admin_Guard::is_reachable($user)) {
            throw new \RuntimeException('User not found.');
        }

        return [
            'id'           => (int) $user->ID,
            'username'     => (string) $user->user_login,
            'display_name' => (string) $user->display_name,
            'email'        => (string) $user->user_email,
            'url'          => (string) $user->user_url,
            'first_name'   => (string) $user->first_name,
            'last_name'    => (string) $user->last_name,
            'nickname'     => (string) $user->nickname,
            'description'  => (string) $user->description,
            'roles'        => array_values((array) $user->roles),
            'registered'   => (string) $user->user_registered,
            'is_admin'     => Admin_Guard::is_admin_user($user),
        ];
    }
}
