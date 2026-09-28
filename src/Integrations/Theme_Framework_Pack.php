<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Curated framework settings packs for the theme integration (issue #69,
 * phase 3 on top of the #144 theme-read/theme-write pair).
 *
 * A pack's operations are only in the pair's catalog while its theme family
 * is the active one (Theme_Integration passes the detected framework), so an
 * Astra-only op is never advertised on a Genesis site. Writes are allowlisted
 * and sanitized per key, snapshotted on the framework's own option, and
 * followed by that framework's CSS-cache refresh.
 *
 * Astra is the first pack (largest install base among the detected
 * frameworks). Kadence, GeneratePress and Blocksy (issue #288) keep their
 * settings in nested theme storage, so each is a declarative spec built
 * by Theme_Settings_Pack; a further family adds another case to
 * operations().
 */
final class Theme_Framework_Pack
{
    /**
     * Astra option keys the pack may write, with their sanitizer. Narrow on
     * purpose: colors and content width, the knobs an agent is actually asked
     * for. Widen with wpmcp_theme_framework_pack_allowlist.
     *
     * @var array<string,string>
     */
    private const ASTRA_SETTINGS = [
        'theme-color'        => 'color',
        'link-color'         => 'color',
        'link-h-color'       => 'color',
        'text-color'         => 'color',
        'heading-base-color' => 'color',
        'site-content-width' => 'width',
    ];

    /**
     * Operations for the active framework family, or none.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function operations(?string $framework): array
    {
        if ('astra' === $framework) {
            return self::astra_pack();
        }
        if ('kadence' === $framework) {
            return Theme_Pack_Kadence::operations();
        }
        if ('generatepress' === $framework) {
            return Theme_Pack_GeneratePress::operations();
        }
        if ('blocksy' === $framework) {
            return Theme_Pack_Blocksy::operations();
        }
        return [];
    }

    /**
     * Astra settings pack. Registered only while Astra (or an Astra child) is
     * the active family; writes are snapshotted on the astra-settings option
     * and refresh Astra's compiled CSS cache.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function astra_pack(): array
    {
        $allowlist = static function (): array {
            /** Filter: framework pack setting keys, keyed by family. */
            $keys = (array) apply_filters('wpmcp_theme_framework_pack_allowlist', self::ASTRA_SETTINGS, 'astra');
            return $keys;
        };

        return [
            'get-astra-settings' => [
                'mode'         => 'read',
                'capability'   => 'edit_theme_options',
                'description'  => 'Read the writable Astra theme settings (colors and content width) from the astra-settings option, with the effective per-key allowlist',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => static function () use ($allowlist): array {
                    $stored   = (array) get_option('astra-settings', []);
                    $keys     = $allowlist();
                    $settings = [];
                    foreach (array_keys($keys) as $key) {
                        $settings[ (string) $key ] = $stored[ (string) $key ] ?? null;
                    }
                    return [ 'framework' => 'astra', 'settings' => $settings, 'allowlist' => array_keys($keys) ];
                },
            ],
            'set-astra-settings' => [
                'mode'               => 'write',
                'capability'         => 'edit_theme_options',
                'enabled_by_default' => (bool) apply_filters('wpmcp_enable_theme_write', false),
                'description'        => 'Set allowlisted Astra theme settings (colors as hex, rgb/rgba, or an Astra palette reference like var(--ast-global-color-0); site-content-width as pixels). Values are sanitized per key, the whole batch is rejected before any write if one is invalid, the astra-settings option is snapshotted for rollback-operation, and Astra\'s compiled CSS cache is refreshed afterwards',
                'input_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'settings' => [
                            'type'                 => 'object',
                            'minProperties'        => 1,
                            'additionalProperties' => [ 'type' => [ 'string', 'integer', 'number' ] ],
                        ],
                    ],
                    'required'   => [ 'settings' ],
                ],
                'validate'           => static function (array $args) use ($allowlist): ?array {
                    $keys = $allowlist();
                    foreach ((array) ($args['settings'] ?? []) as $key => $value) {
                        $key = (string) $key;
                        if (! isset($keys[ $key ])) {
                            return [
                                'code'    => 'key_not_allowlisted',
                                'message' => sprintf('Astra setting "%s" is not on the write allowlist.', $key),
                                'data'    => [ 'key' => $key, 'allowlist' => array_keys($keys) ],
                            ];
                        }
                        if (null === self::sanitize_pack_value((string) $keys[ $key ], $value)) {
                            return [
                                'code'    => 'invalid_setting_value',
                                'message' => sprintf('Value for Astra setting "%s" is not valid for that key.', $key),
                                'data'    => [ 'key' => $key ],
                            ];
                        }
                    }
                    return null;
                },
                'handler'            => static function (array $args) use ($allowlist): array {
                    $keys    = $allowlist();
                    $stored  = (array) get_option('astra-settings', []);
                    $written = [];
                    foreach ((array) $args['settings'] as $key => $value) {
                        $key   = (string) $key;
                        $clean = self::sanitize_pack_value((string) ($keys[ $key ] ?? ''), $value);
                        if (null === $clean) {
                            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
                            throw new Operation_Refused('invalid_setting_value', sprintf('Value for Astra setting "%s" is not valid.', $key), [ 'key' => $key ]);
                        }
                        $stored[ $key ] = $clean;
                        $written[ $key ] = $clean;
                    }
                    update_option('astra-settings', $stored);
                    self::refresh_framework_cache('astra');
                    return [ 'framework' => 'astra', 'settings' => $written ];
                },
                'snapshot'           => static fn (array $args) => [
                    'object_type' => 'option',
                    'object_id'   => 'astra-settings',
                ],
            ],
        ];
    }

    /**
     * Drop a framework's compiled CSS cache after a settings write, so the
     * change is visible on the next request instead of after the cache
     * expires. Astra's own invalidation entry point is used when present.
     */
    private static function refresh_framework_cache(string $family): void
    {
        if ('astra' === $family) {
            // Astra's own entry point (3.6.1+) clears the theme and Astra Pro
            // caches. Older releases only have Astra_Cache_Base, whose
            // refresh_assets() is an INSTANCE method, so it is never called
            // statically.
            if (function_exists('astra_clear_all_assets_cache')) {
                astra_clear_all_assets_cache();
            } elseif (class_exists('\Astra_Cache_Base') && method_exists('\Astra_Cache_Base', 'refresh_assets')) {
                ( new \Astra_Cache_Base('astra') )->refresh_assets('astra');
            }
        }
        /** Action: a site or add-on refreshes its own caches after a framework pack write. */
        do_action('wpmcp_theme_framework_cache_refresh', $family);
    }

    /**
     * Sanitize a framework-pack setting value.
     *
     * @param  mixed $value
     * @return mixed|null
     */
    private static function sanitize_pack_value(string $rule, $value)
    {
        if (is_array($value) || is_object($value)) {
            return null;
        }
        if ('color' === $rule) {
            $raw = trim((string) $value);
            $hex = sanitize_hex_color($raw);
            if (is_string($hex) && '' !== $hex) {
                return $hex;
            }
            // Astra's own defaults are palette references such as
            // var(--ast-global-color-0), so an agent must be able to write
            // one back; nothing else inside var() is accepted.
            if (1 === preg_match('/^var\(--ast-global-color-\d{1,2}\)$/', $raw)) {
                return $raw;
            }
            return 1 === preg_match('/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/', $raw)
                ? $raw
                : null;
        }
        if ('width' === $rule) {
            $px = absint($value);
            return ($px >= 300 && $px <= 3000) ? $px : null;
        }
        return null;
    }
}
