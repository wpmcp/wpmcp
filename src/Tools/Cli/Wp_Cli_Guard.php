<?php

namespace WPMCP\Tools\Cli;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Security core for the guarded wp-cli executor (issue #44). Every guard the
 * run-wp-cli tool relies on lives here as a pure, independently testable
 * check: enable/disable, environment refusal, subcommand allowlisting,
 * shell-metacharacter rejection, and binary resolution. Run_Wp_Cli composes
 * these checks and is the only caller that ever actually spawns a process.
 *
 * Mirrors Database_Guard's shape: static methods returning true|WP_Error (or
 * bool for simple predicates), so callers convert a WP_Error into a thrown
 * exception the same way Query::handle() does with is_read_only_sql().
 */
class Wp_Cli_Guard
{
    /** Test seam: forces wp_get_environment_type()'s return value. Null = live detection. */
    private static ?string $environment_override = null;

    /**
     * Whether wp-cli execution is enabled at all. Two opt-in seams, either
     * sufficient: the WPMCP_ALLOW_WP_CLI constant (for wp-config.php) and the
     * wpmcp_allow_wp_cli filter (programmatic control, also what tests use).
     * Default (neither set) is OFF, matching OAuth_Config::is_enabled().
     */
    public static function is_enabled(): bool
    {
        $default = defined('WPMCP_ALLOW_WP_CLI') && WPMCP_ALLOW_WP_CLI;

        return (bool) apply_filters('wpmcp_allow_wp_cli', $default);
    }

    /**
     * Test seam: force wp_get_environment_type()'s effective value so tests
     * do not depend on live server configuration, mirroring
     * Database_Guard::set_no_backslash_escapes_override(). Pass null to
     * resume live detection.
     */
    public static function set_environment_override(?string $environment): void
    {
        self::$environment_override = $environment;
    }

    /** Current environment type, honoring the test override when set. */
    private static function environment_type(): string
    {
        if (null !== self::$environment_override) {
            return self::$environment_override;
        }

        return function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
    }

    /**
     * Whether the active environment permits wp-cli execution. Production is
     * refused unless a SEPARATE, explicit wpmcp_allow_wp_cli_on_production
     * filter/WPMCP_ALLOW_WP_CLI_ON_PRODUCTION constant is also set: enabling
     * wp-cli at all (is_enabled()) is not, by itself, enough to run it on a
     * live production site. Every other environment (development, staging,
     * local, and the "production" fallback WordPress itself uses when
     * wp_get_environment_type() is unavailable is intentionally treated as
     * production, i.e. refused by default) is allowed.
     */
    public static function is_allowed_on_environment(): bool
    {
        if ('production' !== self::environment_type()) {
            return true;
        }

        $default = defined('WPMCP_ALLOW_WP_CLI_ON_PRODUCTION') && WPMCP_ALLOW_WP_CLI_ON_PRODUCTION;

        return (bool) apply_filters('wpmcp_allow_wp_cli_on_production', $default);
    }

    /**
     * Conservative, read-only-ish default allowlist. Each entry is a
     * space-joined subcommand prefix that an argv (see is_allowed_subcommand())
     * must start with, word-for-word, to be permitted. Filterable via
     * wpmcp_wp_cli_allowlist but this default set is always what a fresh
     * install starts from.
     *
     * Issue #44 security review, M1: `option get` and `user list` are
     * deliberately NOT in this default set. `option get` accepts any option
     * name, so it can read arbitrary secrets (API keys, SMTP credentials,
     * payment provider keys, ...) stored in wp_options; `user list` can
     * enumerate user data. Both are genuine confidentiality risks even
     * though the tool is already manage_options-gated, so an operator who
     * accepts that risk must deliberately re-add either via the
     * wpmcp_wp_cli_allowlist filter rather than getting them for free.
     *
     * @return string[]
     */
    public static function default_allowlist(): array
    {
        return [
            'core version',
            'plugin list',
            'theme list',
            'cache flush',
            'cron event list',
        ];
    }

    /** @return string[] The active allowlist: the default set, narrowable/extendable via filter. */
    public static function allowlist(): array
    {
        return (array) apply_filters('wpmcp_wp_cli_allowlist', self::default_allowlist());
    }

    /**
     * Whether $argv's leading words match one of the allowlist entries
     * exactly (word-for-word), regardless of any trailing arguments/flags
     * after that. Deny-by-default: an empty argv, or one that only partially
     * matches an allowlist entry's word count (e.g. "cron event" against the
     * "cron event list" entry), is rejected.
     *
     * @param string[] $argv Positional wp-cli arguments, NOT including the
     *                       'wp' binary itself (e.g. ['plugin', 'list']).
     */
    public static function is_allowed_subcommand(array $argv): bool
    {
        if ([] === $argv) {
            return false;
        }

        foreach (self::allowlist() as $entry) {
            $entry_words = preg_split('/\s+/', trim((string) $entry));
            if (! is_array($entry_words) || [] === $entry_words) {
                continue;
            }

            if (count($argv) < count($entry_words)) {
                continue;
            }

            $prefix = array_slice($argv, 0, count($entry_words));
            if ($prefix === $entry_words) {
                return true;
            }
        }

        return false;
    }

    /**
     * Characters that must never appear in an individual argv element. The
     * executor already runs argv via proc_open as an array (never a shell
     * string), so none of these are actually shell-interpreted; this is
     * defense-in-depth against a future refactor reintroducing a shell
     * string, and against the NUL byte specifically, which some C library
     * calls treat as a string terminator regardless of shell involvement.
     */
    private const DANGEROUS_CHARACTERS = [
        ';', '|', '&', '$', '`', '>', '<', '(', ')', '{', '}', "\n", "\r", "\0",
    ];

    /**
     * Validate every element of $argv, rejecting the whole command if any
     * single argument contains a shell metacharacter or NUL byte, or if
     * $argv is empty.
     *
     * @return true|\WP_Error
     */
    public static function validate_args(array $argv)
    {
        if ([] === $argv) {
            return new \WP_Error('wp_cli_unsafe_argument', 'No arguments were given.');
        }

        foreach ($argv as $arg) {
            $arg = (string) $arg;
            foreach (self::DANGEROUS_CHARACTERS as $char) {
                if (false !== strpos($arg, $char)) {
                    return new \WP_Error(
                        'wp_cli_unsafe_argument',
                        'Argument contains a disallowed character and was rejected.'
                    );
                }
            }
        }

        return true;
    }

    /**
     * Narrow, conservative default set of flag NAMES (without any leading
     * dashes) permitted anywhere in an argv, regardless of which allowlisted
     * subcommand they follow. Deliberately tiny: only harmless read/format
     * flags. Filterable via wpmcp_wp_cli_flag_allowlist, but every wp-cli
     * GLOBAL flag capable of altering execution context or running code
     * (--exec, --require, --ssh, --http, --path, --url, --user, --prompt,
     * --debug, --eval, and any unrecognized flag) is denied by default and
     * must be deliberately opted into via that filter.
     *
     * @return string[]
     */
    public static function default_flag_allowlist(): array
    {
        return ['--format', '--fields', '--field'];
    }

    /** @return string[] The active safe-flag allowlist: default set, narrowable/extendable via filter. */
    public static function flag_allowlist(): array
    {
        return (array) apply_filters('wpmcp_wp_cli_flag_allowlist', self::default_flag_allowlist());
    }

    /**
     * Validate every element of $argv against the safe-flag allowlist:
     * deny-by-default on flags. Any token beginning with "-" (short or long,
     * "=value" form or not) must have its flag NAME (the part before any
     * "=") present in flag_allowlist(), or the whole command is rejected.
     * This closes the allowlist-bypass-to-RCE class described in issue #44's
     * security review (C1): is_allowed_subcommand() only matches the
     * LEADING positional words, so this method validates the ENTIRE token
     * stream instead, including every trailing flag a caller could append
     * after an allowlisted subcommand prefix.
     *
     * Also rejects any token containing a literal space: argv elements are
     * already discrete array entries never passed through a shell, but a
     * space inside one element would imply an attempt to smuggle what looks
     * like a second token past this check within a single element.
     *
     * @return true|\WP_Error
     */
    public static function validate_flags(array $argv)
    {
        $allowed = self::flag_allowlist();

        foreach ($argv as $token) {
            $token = (string) $token;

            if (false !== strpos($token, ' ')) {
                return new \WP_Error(
                    'wp_cli_unsafe_argument',
                    'Argument contains a space and was rejected.'
                );
            }

            if ('' === $token || '-' !== $token[0]) {
                continue;
            }

            $flag_name = strtok($token, '=');
            if (! in_array($flag_name, $allowed, true)) {
                return new \WP_Error(
                    'wp_cli_disallowed_flag',
                    'The flag "' . esc_html($flag_name) . '" is not on the safe-flag allowlist and was rejected.'
                );
            }
        }

        return true;
    }

    /**
     * Conservative, fixed candidate paths checked when no
     * WPMCP_WP_CLI_BINARY constant or wpmcp_wp_cli_binary filter resolves a
     * location. Deliberately NOT a shell PATH search (e.g. `which wp` via a
     * shell): every candidate here is an absolute path this class checks
     * directly with is_file()/is_executable(), so resolution can never be
     * influenced by an attacker-controlled PATH environment variable.
     *
     * @return string[]
     */
    private static function default_binary_candidates(): array
    {
        return [
            '/usr/local/bin/wp',
            '/usr/bin/wp',
        ];
    }

    /**
     * Resolve the wp-cli binary path. Order: WPMCP_WP_CLI_BINARY constant,
     * then the wpmcp_wp_cli_binary filter (either may set an explicit path),
     * then the fixed default candidates. The first candidate that exists and
     * is executable wins; if nothing resolves, returns a WP_Error rather
     * than falling back to any shell-based search.
     *
     * @return string|\WP_Error
     */
    public static function resolve_binary()
    {
        $configured = defined('WPMCP_WP_CLI_BINARY') ? WPMCP_WP_CLI_BINARY : null;
        $configured = apply_filters('wpmcp_wp_cli_binary', $configured);

        $candidates = null !== $configured && '' !== $configured
            ? [(string) $configured]
            : self::default_binary_candidates();

        $found_but_not_executable = null;

        foreach ($candidates as $candidate) {
            if (! is_file($candidate)) {
                continue;
            }
            if (! is_executable($candidate)) {
                $found_but_not_executable = $candidate;
                continue;
            }
            return $candidate;
        }

        if (null !== $found_but_not_executable) {
            return new \WP_Error(
                'wp_cli_binary_not_executable',
                'The wp-cli binary at "' . esc_html($found_but_not_executable) . '" is not executable.'
            );
        }

        return new \WP_Error('wp_cli_binary_not_found', 'No wp-cli binary could be resolved.');
    }
}
