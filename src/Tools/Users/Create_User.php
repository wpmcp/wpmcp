<?php

namespace WPMCP\Tools\Users;

use WPMCP\Safety\User_Creation_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Create a new, non-admin WordPress user.
 *
 * Creation has no prior state to snapshot, so like create-post it records a
 * creation row under the caller's session_id once the user exists (issue
 * #598, User_Creation_Snapshot) and returns its operation_id. There is still
 * deliberately no delete-user tool: rollback-operation and rollback-session
 * delete the created user only while it has no content and nothing about
 * the account changed since, and refuse with the reason otherwise. Undoing
 * it takes delete_users.
 *
 * Guardrails:
 *  - Only non-admin roles may be assigned. Any role that would grant an
 *    admin-level capability (or any role not registered on the site) is
 *    rejected with 'forbidden_role'; the default is 'subscriber'.
 *  - The password is auto-generated (strong, random) and passed to
 *    wp_insert_user(); it is NEVER returned to the caller and NEVER included
 *    in the response. The new user is notified by email so they can set their
 *    own password via the normal reset flow.
 */
class Create_User
{
    /** Capabilities that mark a role as admin-level and therefore forbidden here. */
    private const ADMIN_CAPS = ['manage_options', 'edit_users', 'promote_users', 'delete_users'];

    public function handle(array $args): array
    {
        $username = isset($args['username']) ? sanitize_user((string) $args['username'], true) : '';
        $email    = isset($args['email']) ? sanitize_email((string) $args['email']) : '';
        if ('' === $username || '' === $email) {
            throw new \InvalidArgumentException('Both username and email are required.');
        }

        $role = isset($args['role']) ? sanitize_key((string) $args['role']) : 'subscriber';
        if (! $this->is_allowed_role($role)) {
            throw new \InvalidArgumentException('That role is not allowed. Only non-admin roles may be created.');
        }

        // Multisite: the network's signup rules, as core applies them (#561).
        Network_Signup_Rules::assert_allowed($username, $email);

        $password = wp_generate_password(24, true, true);

        $userdata = [
            'user_login'   => $username,
            'user_email'   => $email,
            'user_pass'    => $password,
            'role'         => $role,
            'display_name' => isset($args['display_name']) ? sanitize_text_field((string) $args['display_name']) : $username,
            'first_name'   => isset($args['first_name']) ? sanitize_text_field((string) $args['first_name']) : '',
            'last_name'    => isset($args['last_name']) ? sanitize_text_field((string) $args['last_name']) : '',
        ];

        $user_id = wp_insert_user(wp_slash($userdata));
        if (is_wp_error($user_id)) {
            throw new \RuntimeException('Could not create user: ' . esc_html($user_id->get_error_message()));
        }

        // Notify the new user by email so they can set their own password.
        // The generated password never leaves this method.
        wp_new_user_notification((int) $user_id, null, 'user');

        // Recorded after the email, which sets the activation key, so the
        // fingerprint holds the account exactly as this call left it.
        $operation_id = User_Creation_Snapshot::record('create-user', (int) $user_id, $args, (string) ($args['session_id'] ?? 'default'));

        return [
            'operation_id' => $operation_id,
            'id'           => (int) $user_id,
            'username'     => $username,
            'email'        => $email,
            'role'         => $role,
        ];
    }

    /**
     * A role is allowed only if it is registered on the site AND grants none
     * of the admin-level capabilities. An unknown role has no definition to
     * inspect, so it is rejected outright.
     */
    private function is_allowed_role(string $role): bool
    {
        $role_object = get_role($role);
        if (! $role_object) {
            return false;
        }
        foreach (self::ADMIN_CAPS as $cap) {
            if (! empty($role_object->capabilities[ $cap ])) {
                return false;
            }
        }
        return true;
    }
}
