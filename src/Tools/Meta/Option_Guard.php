<?php

namespace WPMCP\Tools\Meta;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Shared guardrail for the generic option tools: which option names are
 * refused, on both the read and write side.
 *
 * The denylist is intentionally conservative rather than exhaustive: it
 * covers credential/secret-shaped names by pattern (auth/secret/password/
 * salt/token/api key material) plus a short exact-match list of core
 * options that would brick or seriously destabilize a site if read out
 * (exposing hashed/plaintext secrets) or overwritten blind (siteurl/home
 * pointing the whole install at the wrong URL, active_plugins swapping the
 * plugin set, wp_user_roles corrupting the capability system). Sites can
 * extend it via the wpmcp_option_denylist filter; there is deliberately no
 * way to shrink it from outside, since that would defeat the guard.
 */
class Option_Guard
{
    /** Exact option names refused regardless of pattern match. */
    private const DENYLISTED_NAMES = [
        'siteurl',
        'home',
        'active_plugins',
        'stylesheet',
        'template',
        'wp_user_roles',
        'db_version',
        'secret',
        'auth_key',
        'auth_salt',
        'logged_in_key',
        'logged_in_salt',
        'nonce_key',
        'nonce_salt',
        'secure_auth_key',
        'secure_auth_salt',
    ];

    /** Substrings (case-insensitive) that mark an option name as sensitive. */
    private const DENYLISTED_PATTERNS = [
        'secret',
        'password',
        'passwd',
        'auth_key',
        'auth_salt',
        'api_key',
        'apikey',
        'private_key',
        'access_token',
        'credential',
        // Every WP MCP Cloud option (issue #141): the sealed vault, the phase
        // A plaintext key until it is migrated, a legacy URL whose import
        // would replace the vault, and the refresh health marker, whose write
        // would pin the connection in a rejection backoff. A pattern rather
        // than exact names, because it is matched case-insensitively and the
        // options table collation is too.
        'wpmcp_cloud_',
    ];

    public static function is_denylisted(string $name): bool
    {
        $denylisted_names    = (array) apply_filters('wpmcp_option_denylist', self::DENYLISTED_NAMES);
        $denylisted_patterns = (array) apply_filters('wpmcp_option_denylist_patterns', self::DENYLISTED_PATTERNS);

        if (in_array($name, $denylisted_names, true)) {
            return true;
        }

        $lower = strtolower($name);
        foreach ($denylisted_patterns as $pattern) {
            if ('' !== $pattern && false !== strpos($lower, strtolower((string) $pattern))) {
                return true;
            }
        }

        return false;
    }
}
