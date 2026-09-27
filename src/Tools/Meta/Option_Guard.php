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
        // The stored PHP snippet corpus (issue #85). Its 'status' and
        // 'validation' fields are the bookkeeping the activation flow
        // writes, and update-option / get-option are not the right door to
        // either: rewriting them would let a caller blank a validation
        // report or flip a snippet to active outside Php_Snippet_Guard, and
        // reading them back through the generic option reader dumps PHP
        // source into a tool response that never expected it. The snippet
        // tools are the only supported way in.
        'wpmcp_php_snippets',
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
    ];

    public static function is_denylisted(string $name): bool
    {
        $denylisted_names    = (array) apply_filters('wpmcp_option_denylist', self::DENYLISTED_NAMES);
        $denylisted_patterns = (array) apply_filters('wpmcp_option_denylist_patterns', self::DENYLISTED_PATTERNS);

        // Fold the name the way the database will match it. WordPress trims
        // option names, and option_name is compared under a case- and
        // accent-insensitive collation by default, so 'SITEURL' or a padded
        // or accented variant reaches the very same row a strict in_array()
        // would wave through.
        //
        // Folding cannot see collation-IGNORABLE code points: a zero-width
        // space or a soft hyphen survives remove_accents() but is skipped by
        // utf8mb4_unicode_ci, so 'wpmcp_php_snippets' plus U+200B lands on
        // the real row. Option names are printable ASCII in practice, so any
        // name that is not is treated as denied outright rather than folded.
        if (! self::is_plain_name($name)) {
            return true;
        }

        $folded = self::fold($name);
        foreach ($denylisted_names as $denylisted) {
            if ($folded === self::fold((string) $denylisted)) {
                return true;
            }
        }

        // Patterns are folded like the name, including the ones a filter
        // supplies, so '  MyVendor_Token ' matches as 'myvendor_token'.
        foreach ($denylisted_patterns as $pattern) {
            $pattern = self::fold((string) $pattern);
            if ('' !== $pattern && false !== strpos($folded, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an option name is printable ASCII only (0x20 to 0x7E). Anything
     * else, a control character, a non-ASCII letter or an invisible format
     * character, can match a different row than it appears to under the
     * database collation, so the generic option tools refuse it.
     */
    public static function is_plain_name(string $name): bool
    {
        return 1 === preg_match('/\A[\x20-\x7E]*\z/', $name);
    }

    /** Trimmed, accent-stripped, lower-cased form of an option name. */
    private static function fold(string $name): string
    {
        $name = trim($name);
        if (function_exists('remove_accents')) {
            $name = remove_accents($name);
        }

        return strtolower($name);
    }
}
