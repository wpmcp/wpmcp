<?php

namespace WPMCP\Integrations;

use WPMCP\Tools\Security\Hardening_Settings;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Apply the hardening fixes scan-security reports (issue #847) as free ops
 * on the theme dispatcher pair, so no top-level tool is added:
 *
 *  - get-hardening-status (read): each setting's state, whether something
 *    else already handles it (wp-config.php, the server, another plugin or
 *    the theme), the scan-security finding it answers, and which security
 *    plugins are active.
 *  - set-hardening (write): switch any of the settings on or off in one
 *    call. One option snapshot per call, so rollback-operation puts every
 *    setting back as it was. Off until the site opts in through the
 *    wpmcp_enable_hardening_write filter.
 *
 * The settings and the hooks that apply them live in
 * WPMCP\Tools\Security\Hardening_Settings. Switching a setting on that
 * another layer already handles is refused (handled_elsewhere) rather than
 * applied twice; switching one off is always allowed.
 */
final class Hardening_Pack
{
    public const ENABLE_FILTER = 'wpmcp_enable_hardening_write';

    /** setting => the scan-security finding it answers (null: the audit has none). */
    private const FINDINGS = [
        'disable_xmlrpc'         => 'harden_xmlrpc',
        'hide_wp_version'        => 'harden_version_disclosure',
        'block_user_enumeration' => null,
        'hide_php_errors'        => 'harden_debug_display',
        'disable_file_editor'    => 'harden_file_edit',
        'security_headers'       => 'harden_security_headers',
    ];

    /** @return array<string, array<string, mixed>> op name => definition for the theme pair. */
    public static function operations(): array
    {
        // The literal name, not ENABLE_FILTER, so the hook stays greppable.
        $enabled = (bool) apply_filters('wpmcp_enable_hardening_write', false);

        return [
            'get-hardening-status' => [
                'mode'         => 'read',
                'capability'   => 'manage_options',
                'description'  => 'Hardening settings WP MCP can apply (disable XML-RPC, hide the WordPress version, block user enumeration, hide PHP errors, disable the file editor, security headers): each one\'s state, whether wp-config.php, the server or another plugin already handles it, and the scan-security finding it answers',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => static fn (): array => self::status(),
            ],
            'set-hardening'        => [
                'mode'               => 'write',
                'capability'         => 'manage_options',
                'description'        => 'Switch hardening settings on or off: settings is an object of disable_xmlrpc (also pingbacks; breaks Jetpack and the mobile apps), hide_wp_version, block_user_enumeration (visitors only), hide_php_errors, disable_file_editor, security_headers => true or false. Applied by WP MCP\'s hooks, no file edits. Refuses a setting something else already handles. One snapshot; undo: rollback-operation. Disabled by default (wpmcp_enable_hardening_write filter)',
                'enabled_by_default' => $enabled,
                'opt_in_filter'      => self::ENABLE_FILTER,
                'input_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'settings' => [
                            'type'                 => 'object',
                            'properties'           => array_fill_keys(Hardening_Settings::SETTINGS, [ 'type' => 'boolean' ]),
                            'additionalProperties' => false,
                            'minProperties'        => 1,
                        ],
                    ],
                    'required'   => [ 'settings' ],
                ],
                'validate'           => static fn (array $args): ?array => self::refusal((array) $args['settings']),
                'snapshot'           => static fn (): array => [ 'object_type' => 'option', 'object_id' => Hardening_Settings::OPTION ],
                'handler'            => static fn (array $args): array => self::apply((array) $args['settings']),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function status(): array
    {
        $current  = Hardening_Settings::all();
        $handled  = self::handled_elsewhere();
        $settings = [];
        foreach (Hardening_Settings::SETTINGS as $setting) {
            $settings[ $setting ] = [
                'enabled'           => $current[ $setting ],
                'handled_elsewhere' => $handled[ $setting ] ?? null,
                'audit_finding'     => self::FINDINGS[ $setting ],
            ];
        }

        $plugins = self::security_plugins();

        return [
            'settings'         => $settings,
            'security_plugins' => $plugins,
            'writes_enabled'   => (bool) apply_filters('wpmcp_enable_hardening_write', false),
            'notes'            => array_values(array_filter([
                'Settings are applied by WP MCP on every request; nothing is written to wp-config.php or .htaccess. Run scan-security with checks [hardening] to see the findings again.',
                'hide_wp_version does not delete readme.html; security_headers does not add Strict-Transport-Security or Content-Security-Policy, so the audit can still list those.',
                [] !== $plugins ? 'A security plugin is active: check its own hardening options before switching the same thing on here.' : null,
            ])),
        ];
    }

    /** @return array{code: string, message: string, data: array}|null */
    private static function refusal(array $settings): ?array
    {
        $current = Hardening_Settings::all();
        $changes = array_filter(
            $settings,
            static fn ($value, $name): bool => isset($current[ $name ]) && (bool) $value !== $current[ $name ],
            ARRAY_FILTER_USE_BOTH
        );
        if ([] === $changes) {
            return self::error('no_change', 'Every setting named is already in that state. Nothing was written and no undo point was taken.', [ 'settings' => $current ]);
        }

        $handled  = self::handled_elsewhere();
        $conflict = [];
        foreach ($changes as $name => $value) {
            if (true === (bool) $value && isset($handled[ $name ])) {
                $conflict[ $name ] = $handled[ $name ];
            }
        }
        if ([] !== $conflict) {
            $list = implode(', ', array_map(static fn (string $name, string $by): string => $name . ' (' . $by . ')', array_keys($conflict), $conflict));
            return self::error('handled_elsewhere', 'Already handled outside WP MCP, so it is not applied twice: ' . $list . '. Leave it out of settings.', [ 'handled_elsewhere' => $conflict ]);
        }
        return null;
    }

    /** @return array<string,mixed> */
    private static function apply(array $settings): array
    {
        $before = Hardening_Settings::all();
        $after  = $before;
        foreach ($settings as $name => $value) {
            if (array_key_exists($name, $after)) {
                $after[ $name ] = (bool) $value;
            }
        }
        update_option(Hardening_Settings::OPTION, $after, true);

        $changed = [];
        foreach ($after as $name => $value) {
            if ($value !== $before[ $name ]) {
                $changed[ $name ] = $value;
            }
        }

        return [
            'changed'  => $changed,
            'settings' => $after,
            'note'     => 'Takes effect on the next request. Undo: rollback-operation with this operation_id.',
        ];
    }

    /**
     * What already handles each setting outside WP MCP, as a short label,
     * filterable through wpmcp_hardening_handled_elsewhere.
     *
     * @return array<string,string>
     */
    private static function handled_elsewhere(): array
    {
        $handled = Hardening_Settings::without_hardening(static function (): array {
            $out = [];
            // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- reading core's own filters.
            if (! file_exists(ABSPATH . 'xmlrpc.php')) {
                $out['disable_xmlrpc'] = 'the server (xmlrpc.php is missing)';
            } elseif (! (bool) apply_filters('xmlrpc_enabled', true)) {
                $out['disable_xmlrpc'] = 'another plugin or the theme';
            }
            if (false === has_action('wp_head', 'wp_generator') || '' === (string) apply_filters('the_generator', 'probe', 'xhtml')) {
                $out['hide_wp_version'] = 'another plugin or the theme';
            }
            $headers = apply_filters('wp_headers', [], null);
            // phpcs:enable
            $present = is_array($headers) ? array_change_key_case($headers, CASE_LOWER) : [];
            if ([] === array_diff(array_map('strtolower', array_keys(Hardening_Settings::HEADERS)), array_keys($present))) {
                $out['security_headers'] = 'another plugin or the theme';
            }
            return $out;
        });

        if (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT) {
            $handled['disable_file_editor'] = 'wp-config.php (DISALLOW_FILE_EDIT)';
        }
        $display = Hardening_Settings::display_errors_at_boot();
        if (null !== $display && in_array(strtolower($display), [ '0', '', 'off', 'false', 'no', 'stderr' ], true)) {
            $handled['hide_php_errors'] = 'the server (display_errors is already off)';
        }

        $solid = Solid_Security_Status::is_active() ? get_site_option('itsec-storage', []) : [];
        $tweak = is_array($solid) && is_array($solid['wordpress-tweaks'] ?? null) ? $solid['wordpress-tweaks'] : [];
        if (! empty($tweak['disable_xmlrpc'])) {
            $handled['disable_xmlrpc'] = 'Solid Security';
        }
        if (! empty($tweak['file_editor'])) {
            $handled['disable_file_editor'] = 'Solid Security';
        }
        if (Wordfence_Integration::is_active() && is_callable([ 'wfConfig', 'get' ])) {
            if (! empty(call_user_func([ 'wfConfig', 'get' ], 'loginSec_disableAuthorScan'))) {
                $handled['block_user_enumeration'] = 'Wordfence';
            }
            if (! empty(call_user_func([ 'wfConfig', 'get' ], 'other_hideWPVersion'))) {
                $handled['hide_wp_version'] = 'Wordfence';
            }
        }

        $handled = apply_filters('wpmcp_hardening_handled_elsewhere', $handled);
        return is_array($handled) ? array_intersect_key(array_map('strval', $handled), array_flip(Hardening_Settings::SETTINGS)) : [];
    }

    /** @return list<string> the security plugins that are active. */
    private static function security_plugins(): array
    {
        $active = [
            'Wordfence'              => Wordfence_Integration::is_active(),
            'Solid Security'         => Solid_Security_Status::is_active(),
            'Sucuri Security'        => Sucuri_Status::is_active(),
            'All In One WP Security' => AIOS_Status::is_active(),
        ];
        return array_keys(array_filter($active));
    }

    private static function error(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }
}
