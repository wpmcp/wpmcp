<?php

namespace WPMCP\Governance;

use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * "Switch on new tools after updates" (issue #853).
 *
 * Off by default: a newly shipped default-off ability arrives switched off
 * (Default_Seeder, #78) until an administrator turns it on. With the setting
 * on, the seeder leaves such an ability switched on instead, unless:
 *
 *  - the owner already stored a decision for it (a tool switched off by
 *    hand stays off), or
 *  - it is never switched on automatically: anything behind an execution
 *    opt-in filter (Opt_In_Gates), and by name anything that runs code,
 *    writes files or database rows, or manages users, roles, plugins or
 *    themes. The name match is deliberately broad: a read tool caught by it
 *    only waits for a click, while a write tool missed by it would arrive
 *    live on sites whose owners never looked at it.
 *
 * What the last seeding added, with each tool's resulting state, is kept in
 * NOTICE_OPTION for the Dashboard notice until an administrator dismisses
 * it. The setting itself is written through set(), one Safe_Mutation over
 * its option, so every change has an undo point and an audit log row.
 */
class New_Tools_Policy
{
    public const OPTION        = 'wpmcp_governance_auto_enable_new';
    public const NOTICE_OPTION = 'wpmcp_governance_new_tools_notice';

    /** The subject the setting's changes carry in the governance audit log. */
    public const AUDIT_SUBJECT = 'setting:auto_enable_new_tools';

    /**
     * Name patterns that are never switched on automatically, on top of
     * every Opt_In_Gates ability: code execution, file writes, database
     * writes, user and role management, plugin and theme management.
     */
    private const NEVER_AUTO_PATTERNS = [
        '/(php|cli|shell|exec|execute|snippet|script|eval|run-|code)/',
        '/(write|edit|delete|upload|move|rename|create|update|replace)-[a-z-]*file/',
        '/file[a-z-]*-(write|edit|delete|upload)/',
        '/(insert|update|delete|replace|truncate|drop|alter)-(row|rows|table|tables|sql|query|db|database)/',
        '/(sql|query-db|database)/',
        '/(user|users|role|roles|capabilit)/',
        '/(install|activate|deactivate|delete|update|switch|upload)-(plugin|theme|package)/',
    ];

    public static function is_on(): bool
    {
        return true === get_option(self::OPTION, false) || '1' === get_option(self::OPTION, false);
    }

    /** Whether an update may switch $name on by itself while the setting is on. */
    public static function may_auto_enable(string $name): bool
    {
        if (Opt_In_Gates::is_gated($name)) {
            return false;
        }
        $short = strtolower((string) preg_replace('#^[^/]*/#', '', $name));
        foreach (self::NEVER_AUTO_PATTERNS as $pattern) {
            if (preg_match($pattern, $short)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Store the setting as one undo point and audit the change. Returns the
     * operation_id, or null when the stored value already matches.
     */
    public static function set(bool $enabled, string $tool_name, string $identity = ''): ?string
    {
        if (self::is_on() === $enabled) {
            return null;
        }

        $out = Safe_Mutation::run(
            [
                'object_type' => 'option_set',
                'object_id'   => Snapshot::option_set_id([ self::OPTION ]),
                'object_ref'  => 'switch on new tools after updates',
                'session_id'  => 'default',
                'tool_name'   => $tool_name,
                'args'        => [ 'auto_enable_new_tools' => $enabled ],
            ],
            static function () use ($enabled): void {
                self::store($enabled);
            }
        );
        self::audit($enabled, $identity);

        return $out['operation_id'];
    }

    /**
     * Raw write of the setting. Callers wrap it in their own Safe_Mutation
     * (set() here, or update-governance-settings' single undo point).
     */
    public static function store(bool $enabled): void
    {
        update_option(self::OPTION, $enabled, false);
    }

    /** Audit row for a change to the setting; never blocks the change. */
    public static function audit(bool $enabled, string $identity = ''): void
    {
        if ('' === $identity) {
            Governance_Audit_Log::record_quietly(self::AUDIT_SUBJECT, $enabled);
            return;
        }
        try {
            Governance_Audit_Log::record(self::AUDIT_SUBJECT, $identity, $enabled);
        } catch (\Throwable $e) {
            unset($e);
        }
    }

    /**
     * Remember what an update added for the Dashboard notice.
     *
     * @param array<string, string> $tools name => on|off|never_auto|kept
     */
    public static function remember_added(int $version, array $tools): void
    {
        if ([] === $tools) {
            return;
        }
        update_option(self::NOTICE_OPTION, [ 'version' => $version, 'tools' => $tools ], false);
    }

    /** @return array{version: int, tools: array<string, string>}|null */
    public static function last_added(): ?array
    {
        $stored = get_option(self::NOTICE_OPTION, null);
        if (! is_array($stored) || ! isset($stored['tools']) || ! is_array($stored['tools']) || [] === $stored['tools']) {
            return null;
        }
        return [ 'version' => (int) ($stored['version'] ?? 0), 'tools' => $stored['tools'] ];
    }

    public static function dismiss_notice(): void
    {
        delete_option(self::NOTICE_OPTION);
    }

    public static function reset_for_tests(): void
    {
        delete_option(self::OPTION);
        delete_option(self::NOTICE_OPTION);
    }
}
