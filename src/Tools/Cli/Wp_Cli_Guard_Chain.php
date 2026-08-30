<?php

namespace WPMCP\Tools\Cli;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Identity\Identity_Context;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The composed wp-cli guard chain, extracted from Run_Wp_Cli (issue #44) so
 * the synchronous tool and the background job dispatcher (issue #84) run
 * literally the same code rather than two copies that can drift apart.
 *
 * Wp_Cli_Guard holds the individual checks as pure predicates; this class
 * holds the ORDER they run in, the exact refusal message each one produces,
 * and the audit-log convention around them. That ordering plus those
 * messages are themselves a security property (which guard fires first
 * decides what an attacker learns from a refusal), so there must be exactly
 * one implementation of it in the codebase:
 *
 *  1. Wp_Cli_Guard::is_enabled()                - default OFF
 *  2. Wp_Cli_Guard::is_allowed_on_environment() - refuses production
 *  3. Wp_Cli_Guard::is_allowed_subcommand()     - allowlist, deny by default
 *  4. Wp_Cli_Guard::validate_args()             - shell metacharacter/NUL check
 *  5. Wp_Cli_Guard::validate_flags()            - safe-flag allowlist, deny by
 *                                                 default on every "-"-prefixed
 *                                                 token anywhere in the argv
 *  6. Wp_Cli_Guard::resolve_binary()            - locates the wp binary
 *
 * Every entry point that can reach Wp_Cli_Executor MUST pass through
 * assert_allowed() first. The background path calls it twice, once at
 * dispatch and again immediately before execution, because the guard state
 * (the enable filter, the environment, the allowlist) can change between a
 * job being queued and the cron run that executes it.
 */
class Wp_Cli_Guard_Chain
{
    /**
     * Run every guard in order, throwing a RuntimeException with the
     * relevant message on the first one that fails. Never executes anything.
     *
     * @param string[] $subcommand_argv wp-cli argv WITHOUT the binary.
     *
     * @throws \RuntimeException
     */
    public static function assert_allowed(array $subcommand_argv): void
    {
        if (! Wp_Cli_Guard::is_enabled()) {
            throw new \RuntimeException(
                'WP-CLI execution is disabled. Enable it with the WPMCP_ALLOW_WP_CLI constant or the wpmcp_allow_wp_cli filter.'
            );
        }

        if (! Wp_Cli_Guard::is_allowed_on_environment()) {
            throw new \RuntimeException(
                'WP-CLI execution is refused on a production environment. Set WPMCP_ALLOW_WP_CLI_ON_PRODUCTION or the wpmcp_allow_wp_cli_on_production filter to override.'
            );
        }

        if (! Wp_Cli_Guard::is_allowed_subcommand($subcommand_argv)) {
            throw new \RuntimeException(
                'This wp-cli subcommand is not on the allowlist. Extend it with the wpmcp_wp_cli_allowlist filter.'
            );
        }

        $args_valid = Wp_Cli_Guard::validate_args($subcommand_argv);
        if (is_wp_error($args_valid)) {
            throw new \RuntimeException(esc_html($args_valid->get_error_message()));
        }

        $flags_valid = Wp_Cli_Guard::validate_flags($subcommand_argv);
        if (is_wp_error($flags_valid)) {
            throw new \RuntimeException(esc_html($flags_valid->get_error_message()));
        }

        self::resolve_binary_or_throw();
    }

    /**
     * Wp_Cli_Guard::resolve_binary() with its WP_Error turned into the same
     * RuntimeException every other guard failure raises.
     *
     * @throws \RuntimeException
     */
    public static function resolve_binary_or_throw(): string
    {
        $binary = Wp_Cli_Guard::resolve_binary();
        if (is_wp_error($binary)) {
            throw new \RuntimeException(esc_html($binary->get_error_message()));
        }

        return (string) $binary;
    }

    /**
     * Split a command string into argv words. A plain whitespace split is
     * sufficient (and deliberately not a shell-style quote-aware parser):
     * Wp_Cli_Guard::validate_args() rejects shell metacharacters outright,
     * so there is no quoting syntax this tool needs to understand or
     * support in the first place.
     *
     * @return string[]
     */
    public static function split_command(string $command): array
    {
        $parts = preg_split('/\s+/', trim($command));
        return is_array($parts) ? $parts : [];
    }

    /**
     * Record an attempt to Governance_Audit_Log. Deliberately logs only the
     * ability name, active identity, and allow/deny outcome, mirroring
     * Registrar::record_audit(): never the command string or argv, so no
     * wp-cli argument (which may contain a secret value) ever reaches the
     * audit log.
     */
    public static function audit(string $ability, bool $allowed): void
    {
        try {
            $identity = Identity_Context::current() ?? 'none';
            Governance_Audit_Log::record($ability, $identity, $allowed);
        } catch (\Throwable $e) {
            // Auditing must never break (or block) the command outcome it observes.
        }
    }
}
