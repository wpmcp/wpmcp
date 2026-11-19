<?php

namespace WPMCP\Auth;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Resolves whether the OAuth 2.1 + Dynamic Client Registration subsystem
 * (issue #43) is active at all. Every OAuth endpoint and permission path
 * must consult this before granting anything, so a default install is
 * completely unaffected: no routes behave differently, no tokens are ever
 * accepted, until the site opts in.
 *
 * Three layers, lowest first (issue #842):
 *  - the "Allow OAuth sign-in" switch on the Connection screen (self::OPTION),
 *    so a site owner can connect ChatGPT or a custom connector without
 *    editing files;
 *  - the WPMCP_OAUTH_ENABLED constant in wp-config.php, which, when defined,
 *    decides instead of the switch (true or false);
 *  - the wpmcp_oauth_enabled filter, which has the last word (also what
 *    tests use).
 * The default (none set) is OFF. Because Bearer_Auth asks on every request,
 * switching OAuth off also refuses every OAuth token already issued.
 */
class OAuth_Config
{
    public const OPTION = 'wpmcp_oauth_enabled';

    public static function is_enabled(): bool
    {
        $constant = defined('WPMCP_OAUTH_ENABLED') ? (bool) constant('WPMCP_OAUTH_ENABLED') : null;
        $default  = self::resolve($constant, '1' === (string) get_option(self::OPTION, '0'));

        return (bool) apply_filters('wpmcp_oauth_enabled', $default);
    }

    /**
     * The value before the filter: a defined constant wins over the switch.
     *
     * @param bool|null $constant The WPMCP_OAUTH_ENABLED value, or null when it is not defined.
     * @param bool      $option   The Connection screen switch.
     */
    public static function resolve(?bool $constant, bool $option): bool
    {
        return null === $constant ? $option : $constant;
    }

    /**
     * What fixes the value so the switch cannot change it: 'constant' when
     * WPMCP_OAUTH_ENABLED is defined, 'filter' when something hooks
     * wpmcp_oauth_enabled, null when the switch decides.
     */
    public static function locked_by(): ?string
    {
        if (defined('WPMCP_OAUTH_ENABLED')) {
            return 'constant';
        }
        if (has_filter('wpmcp_oauth_enabled')) {
            return 'filter';
        }

        return null;
    }

    /**
     * Save the Connection screen switch. Refused (false) while the constant
     * or the filter decides, so the screen never shows a saved value that
     * has no effect. Callers check the management capability and nonce.
     */
    public static function set_enabled(bool $enabled): bool
    {
        if (null !== self::locked_by()) {
            return false;
        }

        update_option(self::OPTION, $enabled ? '1' : '0', false);

        return true;
    }
}
