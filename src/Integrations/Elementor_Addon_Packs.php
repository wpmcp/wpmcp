<?php

namespace WPMCP\Integrations;

use WPMCP\Tools\Elementor\Widget_View;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Elementor addon suite packs (issue #286): Essential Addons, Premium Addons
 * and Ultimate Addons for Elementor, as paid-tier ops on the theme dispatcher
 * pair, so the three suites add no top-level tools.
 *
 * Per suite, three ops:
 *  - list-{suite}-widgets reads the suite's widgets as Elementor's own widgets
 *    manager has them registered (so only modules the suite switched on),
 *    recognized by the suite's widget category or class namespace. Controls
 *    come on request, or always for one named widget.
 *  - get-{suite}-modules reads every module's on/off state from the suite's
 *    own settings option, resolved exactly as the suite resolves it.
 *  - set-{suite}-modules switches modules on or off by rewriting that option
 *    in the suite's own format. The whole batch is validated first; only
 *    keys whose state really changes are written, and the option is
 *    snapshotted so rollback-operation restores it exactly (an option the
 *    write created is deleted again).
 *
 * Every op declares a 'requires' check, so an inactive suite is skipped
 * cleanly: its ops stay documented in list-operations (dependency_met:false)
 * and answer addon_suite_inactive without touching anything. Presence is
 * filterable per suite (wpmcp_essential_addons_active,
 * wpmcp_premium_addons_active, wpmcp_ultimate_addons_active).
 *
 * Storage formats, verified against the free wordpress.org builds:
 *  - Essential Addons 6.8, eael_save_settings: module key => bool. A key
 *    that is not saved is on (the suite merges the option over all-on
 *    defaults from $GLOBALS['eael_config'] elements and extensions).
 *  - Premium Addons 4.11, pa_save_settings: only enabled keys, each => true.
 *    With no saved option every module is on except the opt-in ones; once
 *    saved, a missing key is off. The suite caches the resolved map as
 *    pa_elements in the premium_addons cache group, which is dropped after
 *    a write and after a rollback.
 *  - Ultimate Addons 2.9 (header-footer-elementor), _hfe_widgets: widget key
 *    => the key itself when on, 'disabled' when off; a key that is not saved
 *    takes the widget list's own 'default'.
 */
final class Elementor_Addon_Packs
{
    /** Premium Addons keys that stay off until the site turns them on. */
    private const PREMIUM_OPT_IN = [ 'pa_mc_temp', 'premium-ai-abilities' ];

    /** Upper bound on one toggle batch. */
    private const MAX_MODULES = 200;

    private const SUITES = [
        'essential-addons' => [
            'label'      => 'Essential Addons',
            'constant'   => 'EAEL_PLUGIN_VERSION',
            'option'     => 'eael_save_settings',
            'format'     => 'bool_map',
            'categories' => [ 'essential-addons-elementor' ],
            'namespaces' => [ 'Essential_Addons_Elementor\\' ],
        ],
        'premium-addons'   => [
            'label'      => 'Premium Addons',
            'constant'   => 'PREMIUM_ADDONS_VERSION',
            'option'     => 'pa_save_settings',
            'format'     => 'enabled_only',
            'categories' => [ 'premium-elements' ],
            'namespaces' => [ 'PremiumAddons\\', 'PremiumAddonsPro\\' ],
        ],
        'ultimate-addons'  => [
            'label'      => 'Ultimate Addons',
            'constant'   => 'HFE_VER',
            'option'     => '_hfe_widgets',
            'format'     => 'slug_or_disabled',
            'categories' => [ 'hfe-widgets' ],
            'namespaces' => [ 'HFE\\WidgetsManager\\' ],
        ],
    ];

    /**
     * The three ops of every suite.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function operations(): array
    {
        $ops = [];
        foreach (self::SUITES as $suite => $def) {
            $label    = $def['label'];
            $requires = static fn () => self::requirement($suite, false);

            $ops[ "list-{$suite}-widgets" ] = [
                'mode'         => 'read',
                'tier'         => 'pro',
                'description'  => sprintf('List the %s widgets registered in Elementor (name, title, categories, control count). include_controls:true adds each control stack; naming one widget returns just it with its controls', $label),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'widget'           => [ 'type' => 'string' ],
                        'include_controls' => [ 'type' => 'boolean' ],
                    ],
                ],
                'requires'     => static fn () => self::requirement($suite, true),
                'handler'      => static fn (array $args): array => self::widgets($suite, $args),
            ];
            $ops[ "get-{$suite}-modules" ] = [
                'mode'         => 'read',
                'tier'         => 'pro',
                'description'  => sprintf('Read every %1$s module\'s on/off state from the suite\'s own settings option (%2$s), resolved the way the suite resolves it', $label, $def['option']),
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'requires'     => $requires,
                'handler'      => static fn (): array => self::read($suite),
            ];
            $ops[ "set-{$suite}-modules" ] = [
                'mode'         => 'write',
                'tier'         => 'pro',
                'capability'   => 'manage_options',
                'description'  => sprintf('Switch %1$s modules on (true) or off (false) in the suite\'s own settings option (%2$s). A module switched off disappears from every page using its widgets. An unknown module refuses the whole batch before any write. Snapshotted for rollback-operation', $label, $def['option']),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'modules' => [
                            'type'                 => 'object',
                            'minProperties'        => 1,
                            'maxProperties'        => self::MAX_MODULES,
                            'additionalProperties' => [ 'type' => 'boolean' ],
                        ],
                    ],
                    'required'   => [ 'modules' ],
                ],
                'requires'     => $requires,
                'validate'     => static function (array $args) use ($suite): ?array {
                    return self::plan($suite, (array) ($args['modules'] ?? []))['error'] ?? null;
                },
                'snapshot'     => static function (array $args) use ($suite): ?array {
                    $plan = self::plan($suite, (array) ($args['modules'] ?? []));
                    return empty($plan['changes'])
                        ? null
                        : [ 'object_type' => 'option', 'object_id' => self::SUITES[ $suite ]['option'] ];
                },
                'handler'      => static fn (array $args): array => self::write($suite, (array) $args['modules']),
            ];
        }
        return $ops;
    }

    /**
     * After a rollback put options back (wpmcp_rollback_options_restored),
     * drop the Premium Addons module cache when its option was one of them,
     * as the forward write does.
     *
     * @param string[] $names
     */
    public static function after_restore(array $names): void
    {
        if (in_array(self::SUITES['premium-addons']['option'], $names, true)) {
            self::after_write('premium-addons');
        }
    }

    /** Whether a suite is loaded, filterable per suite for sites and tests. */
    public static function active(string $suite): bool
    {
        $loaded = defined(self::SUITES[ $suite ]['constant']);
        if ('essential-addons' === $suite) {
            return (bool) apply_filters('wpmcp_essential_addons_active', $loaded);
        }
        if ('premium-addons' === $suite) {
            return (bool) apply_filters('wpmcp_premium_addons_active', $loaded);
        }
        return (bool) apply_filters('wpmcp_ultimate_addons_active', $loaded);
    }

    /** @return true|array{code: string, message: string} */
    private static function requirement(string $suite, bool $widgets)
    {
        $label = self::SUITES[ $suite ]['label'];
        if (! self::active($suite)) {
            return [
                'code'    => 'addon_suite_inactive',
                'message' => sprintf('%s for Elementor is not active on this site.', $label),
            ];
        }
        if ($widgets && ! class_exists('\\Elementor\\Plugin')) {
            return [
                'code'    => 'elementor_unavailable',
                'message' => sprintf('Elementor is not loaded, so the %s widgets cannot be read.', $label),
            ];
        }
        return true;
    }

    /** @return array<string,mixed> */
    private static function widgets(string $suite, array $args): array
    {
        $only    = (string) ($args['widget'] ?? '');
        $include = '' !== $only || ! empty($args['include_controls']);
        $rows    = [];

        foreach ((array) \Elementor\Plugin::instance()->widgets_manager->get_widget_types() as $widget) {
            if (! $widget instanceof \Elementor\Widget_Base || ! self::owns($suite, $widget)) {
                continue;
            }
            $name = (string) $widget->get_name();
            if ('' !== $only && $name !== $only) {
                continue;
            }
            $controls = Widget_View::controls($widget);
            $row      = [
                'name'          => $name,
                'title'         => (string) $widget->get_title(),
                'categories'    => array_values((array) $widget->get_categories()),
                'icon'          => (string) $widget->get_icon(),
                'control_count' => count($controls),
            ];
            if ($include) {
                $row['controls'] = $controls;
            }
            $rows[ $name ] = $row;
        }

        if ('' !== $only && [] === $rows) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Refused('unknown_widget', sprintf('No %s widget is registered as "%s".', self::SUITES[ $suite ]['label'], $only), [ 'widget' => $only ]);
        }
        ksort($rows);

        return [ 'suite' => $suite, 'count' => count($rows), 'widgets' => array_values($rows) ];
    }

    /** Whether a registered widget belongs to the suite, by category or class namespace. */
    private static function owns(string $suite, \Elementor\Widget_Base $widget): bool
    {
        $def = self::SUITES[ $suite ];
        if ([] !== array_intersect($def['categories'], (array) $widget->get_categories())) {
            return true;
        }
        $class = get_class($widget);
        foreach ($def['namespaces'] as $prefix) {
            if (0 === strpos($class, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,mixed> */
    private static function read(string $suite): array
    {
        $option = self::SUITES[ $suite ]['option'];
        return [ 'suite' => $suite, 'option' => $option, 'modules' => self::state($suite, get_option($option, null)) ];
    }

    /**
     * Every module the suite lists, with its default state: the suite's own
     * catalog when it is loaded.
     *
     * @return array<string,bool>
     */
    private static function known(string $suite): array
    {
        $out = [];
        if ('essential-addons' === $suite) {
            $config = $GLOBALS['eael_config'] ?? null;
            if (is_array($config)) {
                foreach ([ 'elements', 'extensions' ] as $group) {
                    foreach (array_keys((array) ($config[ $group ] ?? [])) as $key) {
                        $out[ (string) $key ] = true;
                    }
                }
            }
        } elseif ('premium-addons' === $suite) {
            $helper = '\\PremiumAddons\\Admin\\Includes\\Admin_Helper';
            if (is_callable([ $helper, 'get_elements_keys' ])) {
                foreach ((array) $helper::get_elements_keys() as $row) {
                    $key = (string) ($row['key'] ?? '');
                    if ('' === $key) {
                        continue;
                    }
                    $out[ $key ] = ! in_array($key, self::PREMIUM_OPT_IN, true);
                    if (! empty($row['draw_svg'])) {
                        $out[ 'svg_' . $key ] = true;
                    }
                }
            }
        } else {
            $helper = '\\HFE\\WidgetsManager\\Base\\HFE_Helper';
            if (is_callable([ $helper, 'get_widget_list' ])) {
                foreach ((array) $helper::get_widget_list() as $key => $data) {
                    $out[ (string) $key ] = (bool) ($data['default'] ?? false);
                }
            }
        }
        return $out;
    }

    /**
     * Each module's effective state for a raw stored option value, resolved
     * the way the suite resolves it. Saved keys the suite no longer lists
     * are reported too, so nothing in the option is hidden.
     *
     * @param mixed $raw
     * @return array<string,bool>
     */
    private static function state(string $suite, $raw): array
    {
        $format = self::SUITES[ $suite ]['format'];
        $stored = is_array($raw) ? $raw : [];
        $known  = self::known($suite) + array_fill_keys(array_map('strval', array_keys($stored)), false);
        $out    = [];

        foreach ($known as $key => $default) {
            if ('bool_map' === $format) {
                $on = array_key_exists($key, $stored) ? (bool) $stored[ $key ] : $default;
            } elseif ('enabled_only' === $format) {
                $on = is_array($raw) ? ! empty($stored[ $key ]) : $default;
            } else {
                $on = isset($stored[ $key ]) ? 'disabled' !== $stored[ $key ] : $default;
            }
            $out[ (string) $key ] = $on;
        }
        ksort($out);
        return $out;
    }

    /**
     * Dry-run a toggle batch: every module known, and the option value that
     * writing only the real changes would store.
     *
     * @param array<string,mixed> $modules
     * @return array{error?: array<string,mixed>, changes?: array<string,bool>, value?: array<mixed>}
     */
    private static function plan(string $suite, array $modules): array
    {
        $def   = self::SUITES[ $suite ];
        $raw   = get_option($def['option'], null);
        $state = self::state($suite, $raw);

        $changes = [];
        foreach ($modules as $key => $on) {
            $key = (string) $key;
            if (! array_key_exists($key, $state)) {
                return [ 'error' => [
                    'code'    => 'unknown_module',
                    'message' => sprintf('%s has no module "%s".', $def['label'], $key),
                    'data'    => [ 'module' => $key, 'modules' => array_keys($state) ],
                ] ];
            }
            if ((bool) $on !== $state[ $key ]) {
                $changes[ $key ] = (bool) $on;
            }
        }

        $value = is_array($raw) ? $raw : [];
        if ('enabled_only' === $def['format'] && ! is_array($raw)) {
            // No saved option means the suite's defaults are live; saving
            // only the toggled key would switch every other module off.
            $value = array_fill_keys(array_keys(array_filter($state)), true);
        }
        foreach ($changes as $key => $on) {
            if ('bool_map' === $def['format']) {
                $value[ $key ] = $on;
            } elseif ('enabled_only' === $def['format']) {
                if ($on) {
                    $value[ $key ] = true;
                } else {
                    unset($value[ $key ]);
                }
            } else {
                $value[ $key ] = $on ? $key : 'disabled';
            }
        }

        return [ 'changes' => $changes, 'value' => $value ];
    }

    /** @return array<string,mixed> */
    private static function write(string $suite, array $modules): array
    {
        $plan = self::plan($suite, $modules);
        if (isset($plan['error'])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Refused((string) $plan['error']['code'], (string) $plan['error']['message'], (array) $plan['error']['data']);
        }
        $option = self::SUITES[ $suite ]['option'];
        if ([] !== $plan['changes']) {
            update_option($option, $plan['value']);
            self::after_write($suite);
        }

        return [
            'suite'   => $suite,
            'option'  => $option,
            'changed' => $plan['changes'],
            'modules' => self::state($suite, get_option($option, null)),
        ];
    }

    /** The suite's own cache refresh after its option changed. */
    private static function after_write(string $suite): void
    {
        if ('premium-addons' === $suite) {
            wp_cache_delete('pa_elements', 'premium_addons');
        }
    }
}
