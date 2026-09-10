<?php

namespace WPMCP\Tools\Users;

use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Update the profile fields of an existing, non-admin user.
 *
 * Routed through Safe_Mutation with object_type 'user', so the user's
 * editable profile (columns + usermeta) is snapshotted before the write and
 * the change can be undone via rollback-operation. The password hash is never
 * captured (see Snapshot::capture_user()).
 *
 * Guardrails:
 *  - Refuses any admin-capable target (Admin_Guard): those accounts can only
 *    be edited through WordPress core, never this tool.
 *  - Edits profile fields ONLY. role and password (in any form: 'role',
 *    'password', 'user_pass') are ignored even if supplied, so this tool can
 *    never escalate privileges or reset credentials.
 */
class Update_User
{
    /** The only fields this tool will change, mapped to their wp_update_user() keys. */
    private const EDITABLE_FIELDS = [
        'display_name' => 'display_name',
        'email'        => 'user_email',
        'url'          => 'user_url',
        'nickname'     => 'nickname',
        'first_name'   => 'first_name',
        'last_name'    => 'last_name',
        'description'  => 'description',
    ];

    public function handle(array $args): array
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            throw new \InvalidArgumentException('A user id is required.');
        }

        $user = get_userdata($id);
        if (! $user) {
            throw new \RuntimeException('User not found.');
        }

        if (Admin_Guard::is_admin_user($user)) {
            throw new \RuntimeException('This user has admin-level capabilities and cannot be edited with this tool.');
        }

        $changes = $this->collect_changes($args);
        if ([] === $changes) {
            return ['id' => $id, 'updated' => []];
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'user',
                'object_id'   => $id,
                'session_id'  => (string) ($args['session_id'] ?? 'default'),
                'tool_name'   => 'update-user',
                'args'        => $args,
            ],
            function () use ($id, $changes): void {
                $result = wp_update_user(array_merge(['ID' => $id], $changes));
                if (is_wp_error($result)) {
                    throw new \RuntimeException('Could not update user: ' . esc_html($result->get_error_message()));
                }
            }
        );

        return [
            'id'           => $id,
            'updated'      => array_keys($changes),
            'operation_id' => $out['operation_id'],
        ];
    }

    /**
     * Build the wp_update_user() field map from allowlisted profile inputs
     * only. role/password/user_pass are never read, so they cannot be changed.
     *
     * @return array<string,string>
     */
    private function collect_changes(array $args): array
    {
        $changes = [];
        foreach (self::EDITABLE_FIELDS as $input => $column) {
            if (! array_key_exists($input, $args)) {
                continue;
            }
            $value = (string) $args[ $input ];
            $changes[ $column ] = ('user_email' === $column) ? sanitize_email($value) : sanitize_text_field($value);
        }
        return $changes;
    }
}
