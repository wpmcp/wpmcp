<?php

namespace WPMCP\Tools\BlockBuilder;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Registers active custom block specs as real Gutenberg blocks at runtime via
 * register_block_type, each with a render_callback that interprets the spec's
 * template (Block_Renderer). Data-driven, no code generation, no eval.
 */
class Block_Registry
{
    /** The one editor script every custom block shares. */
    public const EDITOR_HANDLE = 'wpmcp-custom-blocks-editor';

    /** Hooked on init (after the CPT is registered). */
    public static function register(): void
    {
        if (! function_exists('register_block_type')) {
            return;
        }

        $editor = self::register_editor_script();

        foreach (self::active_specs() as $spec) {
            $name = (string) $spec['name'];
            if (\WP_Block_Type_Registry::get_instance()->is_registered($name)) {
                continue;
            }

            $supports = Block_Spec::block_supports($spec);
            $args     = [
                'title'           => sanitize_text_field((string) $spec['title']),
                'category'        => sanitize_key((string) ($spec['category'] ?? '')) ?: 'widgets',
                'attributes'      => self::attributes($spec),
                'render_callback' => static function (array $attributes) use ($spec, $supports): string {
                    $html = Block_Renderer::render($spec, $attributes);
                    // Only a spec that opts into supports gets a wrapper, so
                    // blocks built before #852 render byte for byte as before.
                    if ([] === $supports || ! function_exists('get_block_wrapper_attributes')) {
                        return $html;
                    }
                    return '<div ' . get_block_wrapper_attributes() . '>' . $html . '</div>';
                },
            ];
            if ([] !== $supports) {
                $args['supports'] = $supports;
            }
            if ($editor) {
                $args['editor_script_handles'] = [self::EDITOR_HANDLE];
            }
            register_block_type($name, $args);
        }
    }

    /**
     * Active, valid specs. Validation is lenient about names WordPress
     * reserves, so a block stored before that rule keeps registering.
     *
     * @return array<int,array>
     */
    private static function active_specs(): array
    {
        $out = [];
        foreach (Block_Spec_Store::all(true) as $row) {
            $spec = Block_Spec_Store::get((int) $row['block_id']);
            if (is_array($spec) && true === Block_Spec::validate($spec, false)) {
                $out[] = $spec;
            }
        }
        return $out;
    }

    /**
     * Register the shared editor script (plain JS, no build step) and hand it
     * the block definitions when the block editor loads.
     */
    private static function register_editor_script(): bool
    {
        if (! function_exists('wp_register_script')) {
            return false;
        }
        if (! wp_script_is(self::EDITOR_HANDLE, 'registered')) {
            wp_register_script(
                self::EDITOR_HANDLE,
                plugins_url('assets/custom-blocks-editor.js', __FILE__),
                ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-data'],
                defined('WPMCP_VERSION') ? WPMCP_VERSION : null,
                true
            );
            add_action('enqueue_block_editor_assets', [self::class, 'localize_editor_script']);
        }
        return true;
    }

    /** Hooked on enqueue_block_editor_assets. */
    public static function localize_editor_script(): void
    {
        $json = wp_json_encode(self::editor_payload(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (is_string($json)) {
            wp_add_inline_script(self::EDITOR_HANDLE, 'window.wpmcpCustomBlocks = ' . $json . ';', 'before');
        }
    }

    /**
     * What the editor script needs to build each block's sidebar: names,
     * labels, controls, panels. Never the template (it renders server side).
     *
     * @return array<int,array>
     */
    public static function editor_payload(): array
    {
        $out = [];
        foreach (self::active_specs() as $spec) {
            $panels = [];
            foreach ((array) ($spec['panels'] ?? []) as $panel) {
                if (! is_array($panel)) {
                    continue;
                }
                $panels[] = [
                    'title'  => sanitize_text_field((string) ($panel['title'] ?? '')),
                    'help'   => sanitize_text_field((string) ($panel['help'] ?? '')),
                    'opened' => ! isset($panel['opened']) || (bool) $panel['opened'],
                ];
            }
            $out[] = [
                'name'       => (string) $spec['name'],
                'title'      => sanitize_text_field((string) $spec['title']),
                'category'   => sanitize_key((string) ($spec['category'] ?? '')) ?: 'widgets',
                'panels'     => $panels,
                'attributes' => self::editor_controls((array) $spec['attributes']),
            ];
        }
        return $out;
    }

    private static function editor_controls(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $attr) {
            if (! is_array($attr)) {
                continue;
            }
            $control = [
                'name'  => sanitize_key((string) ($attr['name'] ?? '')),
                'type'  => (string) ($attr['type'] ?? 'string'),
                'label' => sanitize_text_field((string) ($attr['label'] ?? '')),
                'help'  => sanitize_text_field((string) ($attr['help'] ?? '')),
                'panel' => sanitize_text_field((string) ($attr['panel'] ?? '')),
            ];
            if ('select' === $control['type']) {
                $control['options'] = Block_Spec::options($attr);
            }
            if ('repeater' === $control['type']) {
                $control['fields'] = self::editor_controls((array) ($attr['fields'] ?? []));
                $control['min']    = isset($attr['min']) ? (int) $attr['min'] : 0;
                $control['max']    = isset($attr['max']) ? (int) $attr['max'] : 0;
            }
            $out[] = $control;
        }
        return $out;
    }

    /** Build the block.json attribute map from the spec. */
    private static function attributes(array $spec): array
    {
        $out = [];
        foreach ((array) ($spec['attributes'] ?? []) as $attr) {
            if (! is_array($attr)) {
                continue;
            }
            $name = sanitize_key((string) ($attr['name'] ?? ''));
            $type = (string) ($attr['type'] ?? 'string');
            if ('' === $name || ! isset(Block_Spec::ATTRIBUTE_TYPES[$type])) {
                continue;
            }
            $def = ['type' => Block_Spec::ATTRIBUTE_TYPES[$type]['json']];
            switch ($type) {
                case 'repeater':
                    $def['default'] = is_array($attr['default'] ?? null) ? array_values($attr['default']) : [];
                    break;
                case 'media':
                case 'link':
                    // No default: an empty object renders nothing.
                    if (is_array($attr['default'] ?? null) && [] !== $attr['default']) {
                        $def['default'] = $attr['default'];
                    }
                    break;
                default:
                    $def['default'] = $attr['default'] ?? '';
            }
            $out[$name] = $def;
        }
        return $out;
    }
}
