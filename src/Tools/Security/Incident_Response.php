<?php

namespace WPMCP\Tools\Security;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\MCP\Confirmation_Required;
use WPMCP\Privacy\Masking;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * incident-response ability handler (issue #382): the containment steps that
 * follow a scan-security finding or a leaked credential.
 *
 * Actions:
 *  - list-app-passwords: a user's application passwords, never the hashes;
 *    the last IP is anonymized with wp_privacy_anonymize_ip().
 *  - revoke-app-password: one (uuid) or all (all:true).
 *  - list-sessions: one user's login sessions (issue #715): login and
 *    expiry times, the IP anonymized, the user agent masked, which one is
 *    the caller's own; never the session token or its hash.
 *  - end-sessions: every login session of one user, or of all users.
 *  - rotate-salts: Salt_Rotator.
 *  - reinstall-core-files: Core_File_Repair.
 *  - quarantine-file, list-quarantine, restore-quarantined-file,
 *    purge-quarantined-file: File_Quarantine (issue #844), behind the
 *    wpmcp_enable_fs_writes opt-in like the other file-changing tools.
 *
 * Every action but the listing needs confirm:true and is recorded in the
 * governance audit log, refusals included, with counts only: no secret, key
 * or password value ever reaches the log or the result. Capabilities narrow
 * per action beyond the ability's manage_options: another user's passwords
 * and sessions take core's own meta capabilities (edit_user), salts take
 * manage_network_options on multisite, core files take update_core, and both
 * file writes honor DISALLOW_FILE_MODS.
 *
 * The caller's own footing is kept by default: the application password this
 * request authenticated with and the caller's current login session survive a
 * revoke-all or end-sessions unless include_current:true. Salt rotation cannot
 * spare anyone: it invalidates every cookie session, the caller's included,
 * but never an application password.
 */
class Incident_Response
{
    public const ABILITY = 'wpmcp/incident-response';

    public const ACTIONS = [
        'list-app-passwords',
        'revoke-app-password',
        'list-sessions',
        'end-sessions',
        'rotate-salts',
        'reinstall-core-files',
        'quarantine-file',
        'list-quarantine',
        'restore-quarantined-file',
        'purge-quarantined-file',
    ];

    private ?Salt_Rotator $salts;
    private ?Core_File_Repair $core;
    private ?File_Quarantine $quarantine;

    public function __construct(?Salt_Rotator $salts = null, ?Core_File_Repair $core = null, ?File_Quarantine $quarantine = null)
    {
        $this->salts      = $salts;
        $this->core       = $core;
        $this->quarantine = $quarantine;
    }

    public function handle(array $args): array
    {
        $action = (string) ($args['action'] ?? '');
        if (! in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('action must be one of: ' . esc_html(implode(', ', self::ACTIONS)) . '.');
        }

        if ('list-app-passwords' === $action) {
            return $this->list_app_passwords($args);
        }
        if ('list-sessions' === $action) {
            return $this->list_sessions($args);
        }
        if ('list-quarantine' === $action) {
            $result = $this->quarantine()->list();
            Governance_Audit_Log::record_quietly(self::ABILITY, true, 'list-quarantine:count=' . $result['count']);
            return $result;
        }

        if (true !== ($args['confirm'] ?? null)) {
            throw Confirmation_Required::in_arguments(sprintf(
                false !== strpos($action, 'quarantine') ? '%s requires confirm:true.' : '%s cannot be undone in full and requires confirm:true.',
                esc_html($action)
            ));
        }

        try {
            switch ($action) {
                case 'revoke-app-password':
                    return $this->revoke_app_passwords($args);
                case 'end-sessions':
                    return $this->end_sessions($args);
                case 'rotate-salts':
                    return $this->rotate_salts();
                case 'quarantine-file':
                case 'restore-quarantined-file':
                case 'purge-quarantined-file':
                    return $this->quarantine_action($action, $args);
                default:
                    return $this->reinstall_core_files($args);
            }
        } catch (\RuntimeException $e) {
            Governance_Audit_Log::record_quietly(self::ABILITY, false, $action . ':refused');
            throw $e;
        }
    }

    // -----------------------------------------------------------------
    // application passwords
    // -----------------------------------------------------------------

    private function list_app_passwords(array $args): array
    {
        $user_id = $this->target_user($args);
        if (! $this->may_manage($user_id)) {
            throw new \RuntimeException('You cannot list this user\'s application passwords (another user\'s take edit_users).');
        }

        $current = $this->current_app_password();
        $rows    = [];
        foreach (\WP_Application_Passwords::get_user_application_passwords($user_id) as $item) {
            $ip     = (string) ($item['last_ip'] ?? '');
            $rows[] = [
                'uuid'      => (string) $item['uuid'],
                'name'      => (string) $item['name'],
                'app_id'    => (string) ($item['app_id'] ?? ''),
                'created'   => (int) ($item['created'] ?? 0),
                'last_used' => isset($item['last_used']) ? (int) $item['last_used'] : null,
                'last_ip'   => '' === $ip ? null : wp_privacy_anonymize_ip($ip),
                'current'   => $item['uuid'] === $current,
            ];
        }

        Governance_Audit_Log::record_quietly(self::ABILITY, true, sprintf('list-app-passwords:user=%d', $user_id));

        return [
            'user_id'       => $user_id,
            'app_passwords' => $rows,
            'count'         => count($rows),
        ];
    }

    private function revoke_app_passwords(array $args): array
    {
        $user_id = $this->target_user($args);
        $all     = true === ($args['all'] ?? null);
        $uuid    = (string) ($args['uuid'] ?? '');
        if (! $all && '' === $uuid) {
            throw new \InvalidArgumentException('Pass uuid to revoke one application password, or all:true to revoke every one.');
        }
        if (! $this->may_manage($user_id)) {
            throw new \RuntimeException('You cannot revoke this user\'s application passwords (another user\'s take edit_users).');
        }

        $current      = $this->current_app_password();
        $keep_current = true !== ($args['include_current'] ?? null);
        $items        = \WP_Application_Passwords::get_user_application_passwords($user_id);
        if (! $all) {
            $items = array_values(array_filter($items, static fn (array $item): bool => $item['uuid'] === $uuid));
            if ([] === $items) {
                throw new \RuntimeException('No application password with that uuid belongs to this user.');
            }
        }

        $revoked      = [];
        $kept_current = false;
        foreach ($items as $item) {
            if ($keep_current && null !== $current && $item['uuid'] === $current) {
                $kept_current = true;
                continue;
            }
            $deleted = \WP_Application_Passwords::delete_application_password($user_id, $item['uuid']);
            if (true === $deleted) {
                $revoked[] = ['uuid' => (string) $item['uuid'], 'name' => (string) $item['name']];
            }
        }

        Governance_Audit_Log::record_quietly(
            self::ABILITY,
            true,
            sprintf('revoke-app-password:user=%d:revoked=%d', $user_id, count($revoked))
        );

        return [
            'user_id'      => $user_id,
            'revoked'      => $revoked,
            'kept_current' => $kept_current,
            'recoverable'  => false,
        ];
    }

    /** The uuid of the application password this request authenticated with, if any. */
    private function current_app_password(): ?string
    {
        if (! function_exists('rest_get_authenticated_app_password')) {
            return null;
        }
        $uuid = rest_get_authenticated_app_password();
        return is_string($uuid) && '' !== $uuid ? $uuid : null;
    }

    // -----------------------------------------------------------------
    // sessions
    // -----------------------------------------------------------------

    /**
     * One user's login sessions, newest login first. Each row is masked the
     * way every list is (src/Privacy/Masking.php): the IP anonymized like the
     * application password listing, the user agent cut to its first
     * character. The session token and its verifier hash never leave.
     */
    private function list_sessions(array $args): array
    {
        $user_id = $this->target_user($args);
        if (! $this->may_manage($user_id)) {
            throw new \RuntimeException('You cannot list this user\'s sessions (another user\'s take edit_users).');
        }

        $manager = \WP_Session_Tokens::get_instance($user_id);
        $token   = get_current_user_id() === $user_id ? wp_get_session_token() : '';
        $current = '' !== $token ? $manager->get($token) : null;
        $marked  = false;
        $rows    = [];
        foreach ($manager->get_all() as $session) {
            if (! is_array($session)) {
                continue;
            }
            $is_current = false;
            if (! $marked && is_array($current) && $session == $current) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- array equality is the intent
                $is_current = true;
                $marked     = true;
            }
            $ip     = (string) ($session['ip'] ?? '');
            $ua     = (string) ($session['ua'] ?? '');
            $rows[] = [
                'login'      => (int) ($session['login'] ?? 0),
                'expiration' => (int) ($session['expiration'] ?? 0),
                'ip'         => '' === $ip ? null : wp_privacy_anonymize_ip($ip),
                'user_agent' => '' === $ua ? null : Masking::value($ua),
                'current'    => $is_current,
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $b['login'] <=> $a['login']);

        Governance_Audit_Log::record_quietly(self::ABILITY, true, sprintf('list-sessions:user=%d', $user_id));

        return [
            'user_id'  => $user_id,
            'sessions' => $rows,
            'count'    => count($rows),
            'masked'   => true,
        ];
    }

    private function end_sessions(array $args): array
    {
        $caller       = get_current_user_id();
        $keep_current = true !== ($args['include_current'] ?? null);
        $token        = wp_get_session_token();

        if (true === ($args['all'] ?? null)) {
            if (! current_user_can('edit_users')) {
                throw new \RuntimeException('Ending every user\'s sessions takes edit_users.');
            }
            $users = 0;
            $ended = 0;
            $kept  = false;
            $page  = 1;
            do {
                $batch = get_users(['fields' => 'all_with_meta', 'number' => 200, 'paged' => $page, 'orderby' => 'ID']);
                foreach ($batch as $user) {
                    $count = $this->end_user_sessions((int) $user->ID, $caller, $keep_current, $token, $kept);
                    if ($count > 0) {
                        $users++;
                        $ended += $count;
                    }
                }
                $page++;
            } while (200 === count($batch));

            Governance_Audit_Log::record_quietly(self::ABILITY, true, sprintf('end-sessions:all:users=%d:sessions=%d', $users, $ended));

            return [
                'users_logged_out' => $users,
                'sessions_ended'   => $ended,
                'kept_current'     => $kept,
                'recoverable'      => false,
            ];
        }

        $user_id = $this->target_user($args);
        if (! $this->may_manage($user_id)) {
            throw new \RuntimeException('Ending another user\'s sessions takes edit_users.');
        }
        $kept  = false;
        $ended = $this->end_user_sessions($user_id, $caller, $keep_current, $token, $kept);

        Governance_Audit_Log::record_quietly(self::ABILITY, true, sprintf('end-sessions:user=%d:sessions=%d', $user_id, $ended));

        return [
            'user_id'        => $user_id,
            'sessions_ended' => $ended,
            'kept_current'   => $kept,
            'recoverable'    => false,
        ];
    }

    /** Destroy one user's sessions, sparing the caller's current one when asked. Returns how many ended. */
    private function end_user_sessions(int $user_id, int $caller, bool $keep_current, string $token, bool &$kept): int
    {
        $manager = \WP_Session_Tokens::get_instance($user_id);
        $before  = count($manager->get_all());
        if (0 === $before) {
            return 0;
        }
        if ($user_id === $caller && $keep_current && '' !== $token && null !== $manager->get($token)) {
            $manager->destroy_others($token);
            $kept = true;
            return $before - 1;
        }
        $manager->destroy_all();
        return $before;
    }

    // -----------------------------------------------------------------
    // salts and core files
    // -----------------------------------------------------------------

    private function rotate_salts(): array
    {
        if (! current_user_can(is_multisite() ? 'manage_network_options' : 'manage_options')) {
            throw new \RuntimeException('Rotating the salts takes manage_options (manage_network_options on multisite).');
        }
        $this->require_file_mods();

        $result = ($this->salts ?? new Salt_Rotator())->rotate();

        Governance_Audit_Log::record_quietly(self::ABILITY, true, 'rotate-salts:rotated=' . count($result['rotated']));

        return $result + [
            'recoverable' => false,
            'note'        => 'Every login session is now invalid, yours included; application passwords still work. The previous wp-config.php is kept as the backup file next to it.',
        ];
    }

    private function reinstall_core_files(array $args): array
    {
        if (! current_user_can('update_core')) {
            throw new \RuntimeException('Reinstalling core files takes update_core.');
        }
        $this->require_file_mods();

        $paths  = isset($args['paths']) && is_array($args['paths']) ? array_values($args['paths']) : [];
        $result = ($this->core ?? new Core_File_Repair())->repair($paths, $args);

        Governance_Audit_Log::record_quietly(
            self::ABILITY,
            true,
            sprintf('reinstall-core-files:repaired=%d:skipped=%d', count($result['repaired']), count($result['skipped']))
        );

        return $result;
    }

    private function quarantine(): File_Quarantine
    {
        return $this->quarantine ??= new File_Quarantine();
    }

    private function quarantine_action(string $action, array $args): array
    {
        $quarantine = $this->quarantine();
        if ('quarantine-file' === $action) {
            $result = $quarantine->quarantine($args);
            $detail = 'id=' . $result['entry']['id'];
        } elseif ('restore-quarantined-file' === $action) {
            $result = $quarantine->restore($args);
            $detail = 'id=' . $result['id'];
        } else {
            $result = $quarantine->purge($args);
            $detail = 'id=' . $result['id'];
        }
        Governance_Audit_Log::record_quietly(self::ABILITY, true, $action . ':' . $detail);
        return $result;
    }

    private function require_file_mods(): void
    {
        if (! wp_is_file_mod_allowed('wpmcp_incident_response')) {
            throw new \RuntimeException('File changes are disabled on this site (DISALLOW_FILE_MODS).');
        }
    }

    /**
     * The caller's own credentials, or another user's with edit_user: the
     * capability core maps list_app_passwords, delete_app_passwords and the
     * profile screen's "Log Out Everywhere" to. Checked directly rather than
     * through the app-password meta capabilities, which deny outright when
     * application passwords are unavailable (plain HTTP), the very case where
     * leftover passwords still need revoking.
     */
    private function may_manage(int $user_id): bool
    {
        return get_current_user_id() === $user_id || current_user_can('edit_user', $user_id);
    }

    private function target_user(array $args): int
    {
        $user_id = isset($args['user_id']) ? (int) $args['user_id'] : get_current_user_id();
        if ($user_id <= 0 || ! get_userdata($user_id)) {
            throw new \InvalidArgumentException('user_id does not name an existing user.');
        }
        return $user_id;
    }
}
