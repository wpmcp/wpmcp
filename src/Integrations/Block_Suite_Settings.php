<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Plugin settings and generated page CSS of Kadence Blocks and Spectra on
 * the block-suites pair (issue #648): get-settings, update-settings,
 * get-page-styles and rebuild-page-styles.
 *
 * Kadence Blocks keeps its settings as JSON strings in options it registers
 * with register_setting(): kadence_blocks_settings (global switches such as
 * performance_optimizer_enabled), kadence_blocks_colors (the palette),
 * kadence_blocks_settings_blocks (per block visibility) and
 * kadence_blocks_config_blocks (per block defaults). A write merges top-level
 * keys into one of those objects (null removes a key) and stores it as JSON
 * again, as its settings screen does. A store that holds something the
 * plugin cannot decode is never overwritten.
 *
 * Spectra keeps one plain option per setting (UAGB_Admin_Helper's
 * get_admin_settings_option()); the allowlist below covers its switches
 * and layout numbers, each with Spectra's own default and allowed values.
 *
 * API keys and secrets (reCAPTCHA, Turnstile, hCaptcha, mail and social
 * login keys, the cloud library key) live in options of their own that this
 * class neither reads nor writes.
 *
 * Every write validates the whole batch first, then snapshots exactly the
 * options it touches as one undo point. Writes are off until the site opts
 * in through wpmcp_enable_block_suite_settings_write.
 *
 * Page styles: Spectra stores each post's generated CSS in the
 * _uag_page_assets post meta (and a file named by _uag_css_file_name), which
 * rebuild-page-styles drops through Block_Suite::refresh_css() so Spectra
 * builds it again on the next view. Kadence builds block CSS while the
 * blocks render and caches nothing per post, so there is nothing to read or
 * rebuild there; get-page-styles says so and lists the block ids its CSS is
 * keyed on.
 */
final class Block_Suite_Settings
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const OPT_IN_FILTER = 'wpmcp_enable_block_suite_settings_write';

    /** The suites these ops cover. */
    public const SUITES = [ Block_Suite::KADENCE, Block_Suite::SPECTRA ];

    /** Kadence Blocks: store => the JSON option it lives in. */
    private const KADENCE_STORES = [
        'settings'         => 'kadence_blocks_settings',
        'colors'           => 'kadence_blocks_colors',
        'block_visibility' => 'kadence_blocks_settings_blocks',
        'block_defaults'   => 'kadence_blocks_config_blocks',
    ];

    /** Largest stored page CSS returned whole. */
    private const CSS_CAP = 200000;

    /**
     * Spectra: setting => [option, default, rule]. A rule is a list of the
     * allowed values, or ['int', min, max].
     *
     * @return array<string,array{0:string,1:mixed,2:array}>
     */
    private static function spectra_settings(): array
    {
        $toggle = [ 'enabled', 'disabled' ];
        $yes_no = [ 'yes', 'no' ];
        return [
            'enable_file_generation'      => [ '_uagb_allow_file_generation', 'enabled', $toggle ],
            'enable_templates_button'     => [ 'uag_enable_templates_button', 'yes', $yes_no ],
            'enable_on_page_css_button'   => [ 'uag_enable_on_page_css_button', 'yes', $yes_no ],
            'enable_block_condition'      => [ 'uag_enable_block_condition', 'disabled', $toggle ],
            'enable_masonry_gallery'      => [ 'uag_enable_masonry_gallery', 'enabled', $toggle ],
            'enable_quick_action_sidebar' => [ 'uag_enable_quick_action_sidebar', 'enabled', $toggle ],
            'enable_block_responsive'     => [ 'uag_enable_block_responsive', 'enabled', $toggle ],
            'enable_dynamic_content'      => [ 'uag_enable_dynamic_content', 'enabled', $toggle ],
            'enable_animations_extension' => [ 'uag_enable_animations_extension', 'enabled', $toggle ],
            'load_select_font_globally'   => [ 'uag_load_select_font_globally', 'disabled', $toggle ],
            'load_gfonts_locally'         => [ 'uag_load_gfonts_locally', 'disabled', $toggle ],
            'preload_local_fonts'         => [ 'uag_preload_local_fonts', 'disabled', $toggle ],
            'collapse_panels'             => [ 'uag_collapse_panels', 'enabled', $toggle ],
            'copy_paste'                  => [ 'uag_copy_paste', 'enabled', $toggle ],
            'btn_inherit_from_theme'      => [ 'uag_btn_inherit_from_theme', 'disabled', $toggle ],
            'visibility_mode'             => [ 'uag_visibility_mode', 'disabled', $toggle ],
            'content_width'               => [ 'uag_content_width', '', [ 'int', 0, 10000 ] ],
            'container_global_elements_gap' => [ 'uag_container_global_elements_gap', 20, [ 'int', 0, 1000 ] ],
            'blocks_editor_spacing'       => [ 'uag_blocks_editor_spacing', 0, [ 'int', 0, 1000 ] ],
        ];
    }

    /**
     * The four operations, merged into the block-suites pair.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function operations(): array
    {
        $suite = [ 'type' => 'string', 'enum' => self::SUITES ];
        $ready = [ Block_Suites_Integration::class, 'refuse_inactive_suite' ];
        $post  = [
            'type'       => 'object',
            'properties' => [ 'suite' => $suite, 'id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
            'required'   => [ 'suite', 'id' ],
        ];

        return [
            'get-settings'        => [
                'mode'         => 'read',
                'tier'         => 'pro',
                'capability'   => 'manage_options',
                'description'  => 'Kadence Blocks or Spectra plugin settings with defaults, and the writable keys. API keys and secrets are never returned',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'suite' => $suite ], 'required' => [ 'suite' ] ],
                'validate'     => $ready,
                'handler'      => [ self::class, 'read' ],
            ],
            'update-settings'     => [
                'mode'               => 'write',
                'tier'               => 'pro',
                'capability'         => 'manage_options',
                'enabled_by_default' => (bool) apply_filters('wpmcp_enable_block_suite_settings_write', false),
                'opt_in_filter'      => self::OPT_IN_FILTER,
                'description'        => 'Set writable keys from get-settings. Kadence: {store: {key: value|null}} merged into that store. Spectra: {key: value}. One bad value refuses the batch. Snapshotted',
                'input_schema'       => [
                    'type'       => 'object',
                    'properties' => [ 'suite' => $suite, 'settings' => [ 'type' => 'object', 'minProperties' => 1 ] ],
                    'required'   => [ 'suite', 'settings' ],
                ],
                'validate'           => static function (array $args) use ($ready): ?array {
                    $refusal = $ready($args);
                    if (null !== $refusal) {
                        return $refusal;
                    }
                    $plan = self::plan((string) $args['suite'], (array) $args['settings']);
                    return $plan['error'] ?? null;
                },
                'snapshot'           => [ self::class, 'snapshot_target' ],
                'handler'            => [ self::class, 'write' ],
            ],
            'get-page-styles'     => [
                'mode'         => 'read',
                'tier'         => 'pro',
                'objects'      => [ 'id' => 'post' ],
                'description'  => 'The CSS the suite generated for post id: Spectra\'s stored page CSS, version and fonts; Kadence builds CSS at render time, so its block ids instead',
                'input_schema' => $post,
                'validate'     => $ready,
                'handler'      => [ self::class, 'page_styles' ],
            ],
            'rebuild-page-styles' => [
                'mode'         => 'write',
                'tier'         => 'pro',
                'objects'      => [ 'id' => 'post' ],
                'description'  => 'Drop Spectra\'s generated CSS for post id so it is rebuilt on the next view. Snapshotted',
                'input_schema' => $post,
                'validate'     => [ self::class, 'refuse_rebuild' ],
                'snapshot'     => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['id'] ],
                'handler'      => [ self::class, 'rebuild' ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function read(array $args): array
    {
        $suite    = (string) $args['suite'];
        $settings = [];
        if (Block_Suite::KADENCE === $suite) {
            foreach (self::KADENCE_STORES as $store => $option) {
                $decoded            = self::kadence_load($option);
                $settings[ $store ] = is_array($decoded) && [] !== $decoded ? $decoded : null;
            }
            return [ 'suite' => $suite, 'settings' => $settings, 'writable' => array_keys(self::KADENCE_STORES) ];
        }
        foreach (self::spectra_settings() as $key => [ $option, $default ]) {
            $settings[ $key ] = get_option($option, $default);
        }
        return [ 'suite' => $suite, 'settings' => $settings, 'writable' => array_keys(self::spectra_settings()) ];
    }

    /** @return array<string,mixed> */
    public static function write(array $args): array
    {
        $plan = self::plan((string) $args['suite'], (array) $args['settings']);
        if (isset($plan['error'])) {
            throw new Operation_Refused((string) $plan['error']['code'], (string) $plan['error']['message'], (array) ($plan['error']['data'] ?? []));
        }
        foreach ((array) $plan['options'] as $option => $value) {
            update_option($option, $value);
        }
        return [ 'suite' => (string) $args['suite'], 'updated' => array_keys((array) $plan['written']) ];
    }

    /** The options the batch writes, as one undo point. */
    public static function snapshot_target(array $args): ?array
    {
        $plan  = self::plan((string) ($args['suite'] ?? ''), (array) ($args['settings'] ?? []));
        $names = array_keys((array) ($plan['options'] ?? []));
        if ([] === $names) {
            return null;
        }
        return 1 === count($names)
            ? [ 'object_type' => 'option', 'object_id' => $names[0] ]
            : [ 'object_type' => 'option_set', 'object_id' => \WPMCP\Safety\Snapshot::option_set_id($names) ];
    }

    /**
     * Dry-run a batch: the option values it would store, or the refusal.
     *
     * @return array{error?:array<string,mixed>,options?:array<string,mixed>,written?:array<string,mixed>}
     */
    private static function plan(string $suite, array $values): array
    {
        $options = [];
        $written = [];
        foreach ($values as $key => $value) {
            $key = (string) $key;
            if (Block_Suite::KADENCE === $suite) {
                $option = self::KADENCE_STORES[ $key ] ?? null;
                if (null === $option) {
                    return self::refusal('key_not_allowlisted', sprintf('Kadence Blocks setting store "%s" is not writable.', $key), [ 'key' => $key, 'allowlist' => array_keys(self::KADENCE_STORES) ]);
                }
                if (! is_array($value) || ([] !== $value && array_is_list($value))) {
                    return self::refusal('invalid_setting_value', sprintf('Kadence Blocks store "%s" takes an object of keys to merge.', $key), [ 'key' => $key ]);
                }
                if (! self::plain_data($value)) {
                    return self::refusal('invalid_setting_value', sprintf('A value for Kadence Blocks store "%s" contains markup ("<").', $key), [ 'key' => $key ]);
                }
                $current = self::kadence_load($option);
                if (null === $current) {
                    return self::refusal('setting_unreadable', sprintf('The stored Kadence Blocks "%s" is not JSON the plugin can read; save it once in Kadence Blocks first.', $key), [ 'key' => $key ]);
                }
                foreach ($value as $field => $field_value) {
                    if (null === $field_value) {
                        unset($current[ $field ]);
                    } else {
                        $current[ (string) $field ] = $field_value;
                    }
                }
                $options[ $option ] = (string) wp_json_encode($current);
                $written[ $key ]    = true;
                continue;
            }

            $def = self::spectra_settings()[ $key ] ?? null;
            if (null === $def) {
                return self::refusal('key_not_allowlisted', sprintf('Spectra setting "%s" is not on the write allowlist.', $key), [ 'key' => $key, 'allowlist' => array_keys(self::spectra_settings()) ]);
            }
            $clean = self::spectra_value($def[2], $value);
            if (null === $clean) {
                return self::refusal('invalid_setting_value', sprintf('Value for Spectra setting "%s" is not valid for that key.', $key), [ 'key' => $key, 'allowed' => 'int' === $def[2][0] ? sprintf('integer %d to %d', $def[2][1], $def[2][2]) : $def[2] ]);
            }
            $options[ $def[0] ] = $clean;
            $written[ $key ]    = $clean;
        }
        return [ 'options' => $options, 'written' => $written ];
    }

    /**
     * @param mixed $value
     * @return mixed|null null to refuse
     */
    private static function spectra_value(array $rule, $value)
    {
        if ('int' === $rule[0]) {
            if (is_string($value) && 1 === preg_match('/^\d+$/', $value)) {
                $value = (int) $value;
            }
            return is_int($value) && $value >= $rule[1] && $value <= $rule[2] ? $value : null;
        }
        return is_string($value) && in_array($value, $rule, true) ? $value : null;
    }

    /** Whether a value holds only scalars and arrays, and no string with markup. */
    private static function plain_data($value): bool
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (false !== strpos((string) $key, '<') || ! self::plain_data($item)) {
                    return false;
                }
            }
            return true;
        }
        if (is_string($value)) {
            return false === strpos($value, '<');
        }
        return null === $value || is_scalar($value);
    }

    /**
     * A Kadence JSON store decoded: [] when unset or empty, null when it
     * holds something that is not a JSON object.
     *
     * @return array<string,mixed>|null
     */
    private static function kadence_load(string $option): ?array
    {
        $raw = get_option($option, '');
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || '' === trim($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) && ([] === $decoded || ! array_is_list($decoded)) ? $decoded : null;
    }

    /** @return array{error:array<string,mixed>} */
    private static function refusal(string $code, string $message, array $data): array
    {
        return [ 'error' => [ 'code' => $code, 'message' => $message, 'data' => $data ] ];
    }

    /** @return array<string,mixed> */
    public static function page_styles(array $args): array
    {
        $suite   = (string) $args['suite'];
        $post_id = (int) $args['id'];
        if (! get_post($post_id)) {
            throw new Operation_Error('not_found', sprintf('Post %d does not exist.', $post_id));
        }
        if (! current_user_can('read_post', $post_id)) {
            throw new Operation_Error('operation_denied', 'You cannot read this post.', [ 'reason' => 'capability' ]);
        }
        $out = [ 'suite' => $suite, 'id' => $post_id, 'model' => Block_Suite::spec($suite)['css_model'] ];

        if (Block_Suite::KADENCE === $suite) {
            return $out + [
                'cached'     => false,
                'css'        => null,
                'unique_ids' => self::kadence_ids(parse_blocks((string) get_post($post_id)->post_content)),
                'note'       => 'Kadence Blocks builds each block\'s CSS from its attributes while it renders, keyed by uniqueID; nothing is stored per post.',
            ];
        }

        $assets = get_post_meta($post_id, '_uag_page_assets', true);
        if (! is_array($assets) || ! isset($assets['css'])) {
            return $out + [ 'cached' => false, 'css' => null, 'note' => 'Spectra has not generated this post\'s assets yet; it does on the next view.' ];
        }
        $css     = wp_unslash((string) $assets['css']);
        $current = defined('UAGB_ASSET_VER') ? (string) constant('UAGB_ASSET_VER') : null;
        $file    = get_post_meta($post_id, '_uag_css_file_name', true);
        return $out + [
            'cached'          => true,
            'css'             => strlen($css) > self::CSS_CAP ? substr($css, 0, self::CSS_CAP) : $css,
            'css_bytes'       => strlen($css),
            'truncated'       => strlen($css) > self::CSS_CAP,
            'js_bytes'        => strlen((string) ($assets['js'] ?? '')),
            'asset_version'   => (string) ($assets['uag_version'] ?? ''),
            'current_version' => $current,
            'stale'           => null !== $current && $current !== (string) ($assets['uag_version'] ?? ''),
            'google_fonts'    => is_array($assets['gfonts'] ?? null) ? array_map('strval', array_keys($assets['gfonts'])) : [],
            'css_file'        => is_string($file) && '' !== $file ? $file : null,
        ];
    }

    /** @return string[] the Kadence uniqueIDs in a block tree, depth first. */
    private static function kadence_ids(array $blocks): array
    {
        $ids = [];
        foreach ($blocks as $block) {
            if (Block_Suite::KADENCE === Block_Suite::suite_of((string) ($block['blockName'] ?? '')) && is_string($block['attrs']['uniqueID'] ?? null)) {
                $ids[] = $block['attrs']['uniqueID'];
            }
            $ids = array_merge($ids, self::kadence_ids((array) ($block['innerBlocks'] ?? [])));
        }
        return $ids;
    }

    /** Refuse a rebuild for a suite that caches nothing, or one that is not loaded. */
    public static function refuse_rebuild(array $args): ?array
    {
        $refusal = Block_Suites_Integration::refuse_inactive_suite($args);
        if (null !== $refusal) {
            return $refusal;
        }
        if ('render_time' === (Block_Suite::spec((string) $args['suite'])['css_model'] ?? '')) {
            return [
                'code'    => 'no_page_style_cache',
                'message' => 'Kadence Blocks builds block CSS while the page renders and stores none per post, so there is nothing to rebuild: a block write shows on the next view.',
                'data'    => [ 'suite' => (string) $args['suite'] ],
            ];
        }
        if (! get_post((int) $args['id'])) {
            return [ 'code' => 'not_found', 'message' => sprintf('Post %d does not exist.', (int) $args['id']), 'data' => [] ];
        }
        if (! current_user_can('edit_post', (int) $args['id'])) {
            return [ 'code' => 'operation_denied', 'message' => 'You cannot edit this post.', 'data' => [ 'reason' => 'capability' ] ];
        }
        return null;
    }

    /** @return array<string,mixed> */
    public static function rebuild(array $args): array
    {
        $post_id = (int) $args['id'];
        return [ 'suite' => (string) $args['suite'], 'id' => $post_id ] + Block_Suite::refresh_css((string) $args['suite'], $post_id);
    }

    // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
}
