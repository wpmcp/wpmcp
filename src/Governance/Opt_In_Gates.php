<?php

namespace WPMCP\Governance;

use WPMCP\Tools\Cli\Wp_Cli_Guard;
use WPMCP\Tools\Code\Php_Snippet_Guard;
use WPMCP\Tools\Database\Delete_Rows;
use WPMCP\Tools\Database\Insert_Row;
use WPMCP\Tools\Database\Update_Rows;
use WPMCP\Tools\Filesystem\Delete_File;
use WPMCP\Tools\Filesystem\Edit_File;
use WPMCP\Tools\Filesystem\Write_File;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read-only view of the default-off execution opt-in gates (issue #78).
 *
 * The RCE-class / destructive ability groups — exec (WP-CLI, PHP snippets),
 * database writes, and filesystem writes — each guard their own execution
 * behind a default-false opt-in filter checked inside the tool
 * (wpmcp_allow_wp_cli, wpmcp_allow_php_exec, wpmcp_enable_db_writes,
 * wpmcp_enable_fs_writes). Those filters ARE the master gates and this class
 * does not touch them: it only maps ability names to the owning tool's own
 * is_enabled() check so the ability grid can (a) mark these rows with a
 * distinct warning and (b) REFUSE to write an enabling governance toggle
 * while the gate is closed — the grid must never become a UI that appears
 * to open a gate only code can open.
 */
class Opt_In_Gates
{
    /**
     * ability name => [opt-in filter name, the owning tool's gate check].
     * The callable is the tool class's own is_enabled(), so the single
     * source of truth for "is this gate open?" stays in the tool.
     *
     * @return array<string, array{filter: string, is_open: callable}>
     */
    private static function gates(): array
    {
        return [
            'wpmcp/run-wp-cli'      => ['filter' => 'wpmcp_allow_wp_cli', 'is_open' => [Wp_Cli_Guard::class, 'is_enabled']],
            'wpmcp/run-php-snippet' => ['filter' => 'wpmcp_allow_php_exec', 'is_open' => [Php_Snippet_Guard::class, 'is_enabled']],
            'wpmcp/insert-row'      => ['filter' => 'wpmcp_enable_db_writes', 'is_open' => [Insert_Row::class, 'is_enabled']],
            'wpmcp/update-rows'     => ['filter' => 'wpmcp_enable_db_writes', 'is_open' => [Update_Rows::class, 'is_enabled']],
            'wpmcp/delete-rows'     => ['filter' => 'wpmcp_enable_db_writes', 'is_open' => [Delete_Rows::class, 'is_enabled']],
            'wpmcp/write-file'      => ['filter' => 'wpmcp_enable_fs_writes', 'is_open' => [Write_File::class, 'is_enabled']],
            'wpmcp/edit-file'       => ['filter' => 'wpmcp_enable_fs_writes', 'is_open' => [Edit_File::class, 'is_enabled']],
            'wpmcp/delete-file'     => ['filter' => 'wpmcp_enable_fs_writes', 'is_open' => [Delete_File::class, 'is_enabled']],
        ];
    }

    /** Whether $ability is one of the default-off dangerous, gated abilities. */
    public static function is_gated(string $ability): bool
    {
        return isset(self::gates()[ $ability ]);
    }

    /** The opt-in filter guarding $ability, or null when it is not gated. */
    public static function filter_for(string $ability): ?string
    {
        return self::gates()[ $ability ]['filter'] ?? null;
    }

    /**
     * Whether $ability's execution gate is currently open. Ungated abilities
     * report true (there is nothing to open).
     */
    public static function is_open(string $ability): bool
    {
        $gate = self::gates()[ $ability ] ?? null;
        return null === $gate || (bool) ($gate['is_open'])();
    }
}
