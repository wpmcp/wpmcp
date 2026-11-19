<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Otter Blocks' own settings and generated page CSS on the block-suites
 * pair (issue #647), next to the block and pattern ops that pair already has.
 *
 *  - get-otter-settings reads Otter's feature switches (the toggles on its
 *    Settings screen) and block settings as Otter itself reads them: the
 *    stored value, or Otter's registered default when nothing is stored.
 *    Its API keys and secrets are reported only as set or not set, never as
 *    values.
 *  - update-otter-settings writes allowlisted features and settings. The
 *    whole batch is checked before any write, then exactly the options it
 *    touches are snapshotted (one 'option', or an 'option_set'), so
 *    rollback-operation restores them, removing an option the write added.
 *    Off by default (wpmcp_enable_otter_settings_write). API keys, form
 *    email and webhook settings are not writable here.
 *  - get-otter-page-styles reports a post's generated stylesheet: the file
 *    Otter serves it from (uploads/themeisle-gutenberg/{name}.css), whether
 *    that file is there, and the CSS kept in the post meta.
 *  - rebuild-otter-page-styles drops the post's cached stylesheet and, when
 *    Otter's CSS handler is loaded, has Otter generate it again at once;
 *    otherwise Otter rebuilds it on the next view. The post is snapshotted
 *    first; a rollback restores it and drops the cache once more, so the
 *    page is rebuilt from the restored blocks.
 *
 * Option names and defaults come from Otter's register_settings() (its
 * inc/plugins/class-options-settings.php).
 */
final class Block_Suite_Otter
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    /** The prefix of every Otter feature and block setting option. */
    private const PREFIX = 'themeisle_blocks_settings_';

    /** The blocks hidden from the inserter. */
    private const DISABLED = 'themeisle_disabled_blocks';

    /** The post meta Otter serves a post's generated CSS from. */
    private const FILE_META   = '_themeisle_gutenberg_block_stylesheet';
    private const STYLES_META = '_themeisle_gutenberg_block_styles';

    /** The longest CSS returned inline by get-otter-page-styles. */
    private const MAX_CSS = 20000;

    /**
     * Feature switches: key (option name after PREFIX) => Otter's default.
     */
    public const FEATURES = [
        'css_module'              => true,
        'blocks_animation'        => true,
        'block_conditions'        => true,
        'patterns_library'        => true,
        'dynamic_content'         => true,
        'highlight_dynamic'       => true,
        'optimize_animations_css' => true,
        'block_ai_toolbar_module' => true,
        'atomic_wind_blocks'      => false,
    ];

    /**
     * Block settings: boolean ones (option name after PREFIX) => default,
     * plus disabled_blocks, a list of block names.
     */
    public const SETTINGS = [
        'default_block'         => false,
        'disable_review_schema' => true,
        'review_scale'          => false,
    ];

    /** Credentials Otter stores; reported only as set or not set. */
    private const SECRETS = [
        'google_maps_api_key'  => 'themeisle_google_map_block_api_key',
        'stripe_api_key'       => 'themeisle_stripe_api_key',
        'recaptcha_site_key'   => 'themeisle_google_captcha_api_site_key',
        'recaptcha_secret_key' => 'themeisle_google_captcha_api_secret_key',
        'turnstile_site_key'   => 'themeisle_cloudflare_turnstile_site_key',
        'turnstile_secret_key' => 'themeisle_cloudflare_turnstile_secret_key',
        'open_ai_api_key'      => 'themeisle_open_ai_api_key',
    ];

    /** @return array<string,array<string,mixed>> */
    public static function operations(): array
    {
        $ready = static fn (): ?array => Block_Suites_Integration::refuse_inactive_suite([ 'suite' => Block_Suite::OTTER ]);
        $post  = [ 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ];

        return [
            'get-otter-settings'        => [
                'mode'         => 'read',
                'tier'         => 'pro',
                'capability'   => 'manage_options',
                'description'  => 'Otter Blocks: its feature switches and block settings (stored value or Otter\'s default), disabled blocks, and which API keys are set (never their values)',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'validate'     => $ready,
                'handler'      => [ self::class, 'read_settings' ],
            ],
            'update-otter-settings'     => [
                'mode'               => 'write',
                'tier'               => 'pro',
                'capability'         => 'manage_options',
                'enabled_by_default' => (bool) apply_filters('wpmcp_enable_otter_settings_write', false),
                'opt_in_filter'      => 'wpmcp_enable_otter_settings_write',
                'description'        => 'Otter Blocks: set features and settings by the keys get-otter-settings returns (booleans; disabled_blocks a list of block names). One bad key or value refuses the batch. Snapshotted for rollback-operation. Off by default (wpmcp_enable_otter_settings_write)',
                'input_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'settings' => [
                            'type'                 => 'object',
                            'minProperties'        => 1,
                            'additionalProperties' => [ 'type' => [ 'boolean', 'array' ] ],
                        ],
                    ],
                    'required'   => [ 'settings' ],
                ],
                'validate'           => static fn (array $args): ?array => $ready() ?? self::refuse_batch((array) ($args['settings'] ?? [])),
                'snapshot'           => static fn (array $args): ?array => self::snapshot_target((array) ($args['settings'] ?? [])),
                'handler'            => [ self::class, 'write_settings' ],
            ],
            'get-otter-page-styles'     => [
                'mode'         => 'read',
                'objects'      => [ 'id' => 'post' ],
                'tier'         => 'pro',
                'description'  => 'Otter Blocks: post id\'s generated stylesheet (file name, url, whether the file exists) and the CSS kept for it',
                'input_schema' => [ 'type' => 'object', 'properties' => $post, 'required' => [ 'id' ] ],
                'validate'     => $ready,
                'handler'      => [ self::class, 'page_styles' ],
            ],
            'rebuild-otter-page-styles' => [
                'mode'         => 'write',
                'objects'      => [ 'id' => 'post' ],
                'tier'         => 'pro',
                'description'  => 'Otter Blocks: drop post id\'s cached stylesheet and have Otter generate it again (or on the next view). Snapshotted for rollback-operation',
                'input_schema' => [ 'type' => 'object', 'properties' => $post, 'required' => [ 'id' ] ],
                'validate'     => $ready,
                'snapshot'     => static fn (array $args): ?array => [ 'object_type' => 'post', 'object_id' => (int) $args['id'] ],
                'handler'      => [ self::class, 'rebuild_page_styles' ],
            ],
        ];
    }

    public static function read_settings(): array
    {
        $features = [];
        foreach (self::FEATURES as $key => $default) {
            $features[ $key ] = self::bool_option(self::PREFIX . $key, $default);
        }
        $settings = [];
        foreach (self::SETTINGS as $key => $default) {
            $settings[ $key ] = self::bool_option(self::PREFIX . $key, $default);
        }
        $disabled                    = get_option(self::DISABLED, []);
        $settings['disabled_blocks'] = is_array($disabled) ? array_values(array_map('strval', $disabled)) : [];

        $keys = [];
        foreach (self::SECRETS as $key => $option) {
            $keys[ $key ] = [ 'set' => '' !== trim((string) get_option($option, '')) ];
        }

        return [
            'version'  => Block_Suite::version(Block_Suite::OTTER),
            'features' => $features,
            'settings' => $settings,
            'api_keys' => $keys,
        ];
    }

    /** @param array<string,mixed> $values */
    public static function refuse_batch(array $values): ?array
    {
        $writable = array_merge(array_keys(self::FEATURES), array_keys(self::SETTINGS), [ 'disabled_blocks' ]);
        foreach ($values as $key => $value) {
            $key = (string) $key;
            if (! in_array($key, $writable, true)) {
                return [
                    'code'    => 'unknown_setting',
                    'message' => sprintf('"%s" is not a writable Otter Blocks setting. Writable: %s.', $key, implode(', ', $writable)),
                    'data'    => [ 'key' => $key, 'writable' => $writable ],
                ];
            }
            if ('disabled_blocks' === $key) {
                $ok = is_array($value) && array_is_list($value);
                foreach ($ok ? $value : [] as $name) {
                    $ok = $ok && is_string($name) && 1 === preg_match('#^[a-z0-9-]+/[a-z0-9-]+$#', $name);
                }
                if (! $ok) {
                    return [
                        'code'    => 'invalid_setting',
                        'message' => 'disabled_blocks must be a list of block names such as "themeisle-blocks/accordion".',
                        'data'    => [ 'key' => $key ],
                    ];
                }
            } elseif (! is_bool($value)) {
                return [
                    'code'    => 'invalid_setting',
                    'message' => sprintf('"%s" takes true or false.', $key),
                    'data'    => [ 'key' => $key ],
                ];
            }
        }
        return null;
    }

    /** @param array<string,mixed> $args */
    public static function write_settings(array $args): array
    {
        $written = [];
        foreach ((array) $args['settings'] as $key => $value) {
            $key = (string) $key;
            if ('disabled_blocks' === $key) {
                update_option(self::DISABLED, array_values(array_unique(array_map('strval', (array) $value))));
            } elseif (null === get_option(self::PREFIX . $key, null)) {
                // update_option() skips false for a missing option (it equals
                // get_option's default), which would leave Otter's default on.
                add_option(self::PREFIX . $key, (bool) $value);
            } else {
                update_option(self::PREFIX . $key, (bool) $value);
            }
            $written[] = $key;
        }
        return [ 'updated' => $written ] + self::read_settings();
    }

    /** @param array<string,mixed> $values */
    private static function snapshot_target(array $values): ?array
    {
        $names = [];
        foreach (array_keys($values) as $key) {
            $key = (string) $key;
            if ('disabled_blocks' === $key) {
                $names[] = self::DISABLED;
            } elseif (isset(self::FEATURES[ $key ]) || isset(self::SETTINGS[ $key ])) {
                $names[] = self::PREFIX . $key;
            }
        }
        $names = array_values(array_unique($names));
        if ([] === $names) {
            return null;
        }
        if (1 === count($names)) {
            return [ 'object_type' => 'option', 'object_id' => $names[0] ];
        }
        return [ 'object_type' => 'option_set', 'object_id' => \WPMCP\Safety\Snapshot::option_set_id($names) ];
    }

    /** @param array<string,mixed> $args */
    public static function page_styles(array $args): array
    {
        $post_id = (int) $args['id'];
        if (! get_post($post_id)) {
            throw new Operation_Error('not_found', sprintf('Post %d does not exist.', $post_id), [ 'id' => $post_id ]);
        }
        $name   = get_post_meta($post_id, self::FILE_META, true);
        $name   = is_string($name) && 1 === preg_match('/^[A-Za-z0-9_-]{1,100}$/', $name) ? $name : '';
        $css    = get_post_meta($post_id, self::STYLES_META, true);
        $css    = is_string($css) ? $css : '';
        $upload = wp_upload_dir(null, false);
        $file   = '' === $name ? '' : $upload['basedir'] . '/themeisle-gutenberg/' . $name . '.css';

        return [
            'id'          => $post_id,
            'cached'      => '' !== $name,
            'file'        => '' === $name ? null : $name . '.css',
            'url'         => '' === $name ? null : $upload['baseurl'] . '/themeisle-gutenberg/' . $name . '.css',
            'file_exists' => '' !== $file && is_file($file),
            'css_bytes'   => strlen($css),
            'css'         => substr($css, 0, self::MAX_CSS),
            'truncated'   => strlen($css) > self::MAX_CSS,
        ];
    }

    /** @param array<string,mixed> $args */
    public static function rebuild_page_styles(array $args): array
    {
        $post_id = (int) $args['id'];
        if (! get_post($post_id)) {
            throw new Operation_Error('not_found', sprintf('Post %d does not exist.', $post_id), [ 'id' => $post_id ]);
        }
        Block_Suite::refresh_css(Block_Suite::OTTER, $post_id);

        $handler = '\ThemeIsle\GutenbergBlocks\CSS\CSS_Handler';
        $rebuilt = false;
        if (is_callable([ $handler, 'generate_css_file' ])) {
            call_user_func([ $handler, 'generate_css_file' ], $post_id);
            $rebuilt = true;
        }

        return [
            'rebuilt'          => $rebuilt,
            'rebuilds_on_view' => ! $rebuilt,
        ] + self::page_styles($args);
    }

    private static function bool_option(string $name, bool $default): bool
    {
        $value = get_option($name, null);
        return null === $value ? $default : rest_sanitize_boolean($value);
    }
}
