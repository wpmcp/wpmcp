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
    ];

    /**
     * Option-name prefixes refused unconditionally, before (and regardless
     * of) the filterable lists. The custom code store (issue #63) holds page
     * CSS printed raw into <style> and the site JS snippet; its own tools
     * require edit_css / unfiltered_html and a default-off gate, so the
     * generic option tools must not be a manage_options-only side door
     * around them, and a site trimming the denylist must not reopen one.
     */
    private const LOCKED_PREFIXES = [
        'wpmcp_custom_code',
    ];

    public static function is_denylisted(string $name): bool
    {
        $lower = strtolower($name);
        foreach (self::LOCKED_PREFIXES as $prefix) {
            if (0 === strpos($lower, $prefix)) {
                return true;
            }
        }

        $denylisted_names    = (array) apply_filters('wpmcp_option_denylist', self::DENYLISTED_NAMES);
        $denylisted_patterns = (array) apply_filters('wpmcp_option_denylist_patterns', self::DENYLISTED_PATTERNS);

        if (in_array($name, $denylisted_names, true)) {
            return true;
        }

        foreach ($denylisted_patterns as $pattern) {
            if ('' !== $pattern && false !== strpos($lower, strtolower((string) $pattern))) {
                return true;
            }
        }

        return false;
    }
}
