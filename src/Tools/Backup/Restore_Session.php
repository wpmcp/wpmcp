<?php

namespace WPMCP\Tools\Backup;

use WPMCP\Auth\Client_Store;
use WPMCP\Auth\Refresh_Token_Store;
use WPMCP\Auth\Token_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Keeps the acting user signed in across a restore (issue #190).
 *
 * Replacing wp_users and wp_usermeta replaces every credential the current
 * caller is holding: the login cookie is bound to the password hash and to
 * a session_tokens entry, an application password lives in usermeta, and
 * an MCP bearer token lives in the wpmcp_oauth_* options and is bound to a
 * fingerprint of the password hash. Restoring a backup from before any of
 * those existed would lock out the very admin who ran the restore.
 *
 * capture() records the acting user's credential state before the import;
 * reapply() writes it back afterwards, for that one user only, and only if
 * the restored database still has the same account (same ID, same login).
 * Nobody else's sessions or tokens are carried across: a restore is often
 * the response to an incident, and silently re-arming every session that
 * existed at restore time would undo part of what the restore was for.
 * When the account cannot be matched, reapply() says so and asks for a
 * re-login instead of guessing.
 */
class Restore_Session
{
    /** Usermeta that carries the acting user's live credentials. */
    public const META_KEYS = ['session_tokens', '_application_passwords'];

    /**
     * @return array<string, mixed>|null Null when there is no acting user
     *                                   (WP-CLI, cron) to keep signed in.
     */
    public static function capture(int $user_id): ?array
    {
        if ($user_id <= 0) {
            return null;
        }

        $user = get_userdata($user_id);
        if (! $user instanceof \WP_User) {
            return null;
        }

        $meta = [];
        foreach (self::META_KEYS as $key) {
            $meta[ $key ] = get_user_meta($user_id, $key, true);
        }

        $tokens  = self::for_user((array) get_option(Token_Store::OPTION, []), $user_id);
        $refresh = self::for_user((array) get_option(Refresh_Token_Store::OPTION, []), $user_id);

        $client_ids = [];
        foreach (array_merge($tokens, $refresh) as $record) {
            if (is_array($record) && isset($record['client_id'])) {
                $client_ids[ (string) $record['client_id'] ] = true;
            }
        }
        $clients = array_intersect_key((array) get_option(Client_Store::OPTION, []), $client_ids);

        return [
            'user_id'    => $user_id,
            'user_login' => (string) $user->user_login,
            'user_pass'  => (string) $user->user_pass,
            'meta'       => $meta,
            'oauth'      => [
                Token_Store::OPTION         => $tokens,
                Refresh_Token_Store::OPTION => $refresh,
                Client_Store::OPTION        => $clients,
            ],
        ];
    }

    /**
     * Write the captured credentials back into the restored database.
     *
     * @param array<string, mixed>|null $captured
     * @return array<string, mixed> The session part of the restore result.
     */
    public static function reapply(?array $captured): array
    {
        if (null === $captured) {
            return [
                'preserved'        => false,
                'relogin_required' => false,
                'reason'           => 'No signed-in user ran this restore, so there was no session to keep.',
            ];
        }

        global $wpdb;

        $user_id = (int) $captured['user_id'];
        clean_user_cache($user_id);
        $restored = get_userdata($user_id);

        if (! $restored instanceof \WP_User) {
            return self::relogin(sprintf(
                'Your account (user ID %d) does not exist in the restored database, so your session could not be kept. Log in with an account from the backup.',
                $user_id
            ));
        }

        if ((string) $restored->user_login !== (string) $captured['user_login']) {
            return self::relogin(sprintf(
                'User ID %d belongs to a different login in the restored database, so your session was not carried over. Log in with an account from the backup.',
                $user_id
            ));
        }

        $kept = [];

        if ((string) $restored->user_pass !== (string) $captured['user_pass']) {
            // Not wp_set_password(): that re-hashes the value and destroys
            // every session, which is the opposite of the point. The hash is
            // the one this same account had a moment ago.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- writes back the acting user's own pre-restore password hash; wp_set_password() would re-hash it and end the session. The user cache is cleared immediately below.
            $wpdb->update($wpdb->users, ['user_pass' => (string) $captured['user_pass']], ['ID' => $user_id], ['%s'], ['%d']);
            $kept[] = 'password';
        }

        foreach ((array) $captured['meta'] as $key => $value) {
            if ('' === $value || null === $value || [] === $value) {
                continue;
            }
            update_user_meta($user_id, (string) $key, wp_slash($value));
            $kept[] = (string) $key;
        }

        foreach ((array) $captured['oauth'] as $option => $records) {
            if (! is_array($records) || [] === $records) {
                continue;
            }
            $current = get_option((string) $option, []);
            $current = is_array($current) ? $current : [];
            update_option((string) $option, array_replace($current, $records));
            $kept[] = (string) $option;
        }

        clean_user_cache($user_id);

        // The global current_user and the role map were built from the
        // tables that were just replaced.
        wp_roles()->for_site();
        wp_set_current_user(0);
        wp_set_current_user($user_id);

        $is_admin = user_can($user_id, 'manage_options');

        return [
            'preserved'        => true,
            'relogin_required' => false,
            'user_id'          => $user_id,
            'kept'             => $kept,
            'has_manage_options' => $is_admin,
            'reason'           => $is_admin
                ? 'Your session, application passwords and MCP tokens were carried over; you stay signed in.'
                : 'Your session was carried over, but this account is not an administrator in the restored database.',
        ];
    }

    /** @return array<string, mixed> */
    private static function relogin(string $reason): array
    {
        return [
            'preserved'        => false,
            'relogin_required' => true,
            'reason'           => $reason,
        ];
    }

    /** Records in an OAuth store that belong to $user_id, keyed as stored. */
    private static function for_user(array $records, int $user_id): array
    {
        return array_filter(
            $records,
            static fn($record): bool => is_array($record) && (int) ($record['user_id'] ?? 0) === $user_id
        );
    }
}
