<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Snapshot;
use WPMCP\Tools\Elementor\Widget_View;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Elementor addon suite packs (issue #286): Essential Addons, Premium Addons
 * and Ultimate Addons for Elementor, plus Crocoblock JetElements, Happy
 * Addons, Royal Elementor Addons and PowerPack Lite (issue #653), as
 * paid-tier ops on the theme dispatcher pair, so the suites add no top-level
 * tools.
 *
 * Per suite, three ops:
 *  - list-{suite}-widgets reads the suite's widgets as Elementor's own widgets
 *    manager has them registered (so only modules the suite switched on),
 *    recognized by the suite's widget category or class namespace. The
 *    list carries no controls. Named widgets (one, or up to five) come with
 *    a summary per control (type, label, default, option keys), with what
 *    the widgets share given once per answer: Elementor's common Advanced
 *    tab controls and the fields of group controls (issues #878, #862). One
 *    widget's control stack runs to hundreds of KB on a real request and
 *    the whole suite's to megabytes, so controls for the whole list are
 *    refused and the complete definitions (detail:"full") come for exactly
 *    one named widget.
 *  - get-{suite}-modules reads every module's on/off state from the suite's
 *    own settings option, resolved exactly as the suite resolves it.
 *  - set-{suite}-modules switches modules on or off by rewriting that option
 *    in the suite's own format. The whole batch is validated first; only
 *    keys whose state really changes are written, and the option is
 *    snapshotted so rollback-operation restores it exactly (an option the
 *    write created is deleted again). A suite that keeps its switches in
 *    several options has every option the batch writes captured as one
 *    option_set, so one rollback puts them all back.
 *
 * Every op declares a 'requires' check, so an inactive suite is skipped
 * cleanly: its ops stay documented in list-operations (dependency_met:false)
 * and answer addon_suite_inactive without touching anything. Presence is
 * filterable per suite (wpmcp_essential_addons_active,
 * wpmcp_premium_addons_active, wpmcp_ultimate_addons_active,
 * wpmcp_jet_elements_active, wpmcp_happy_addons_active,
 * wpmcp_royal_addons_active, wpmcp_powerpack_active).
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
 *  - JetElements 2.9.2 (licensed build), one jet-elements-settings option
 *    that also holds the suite's other settings (API keys among them, never
 *    read out here). Its avaliable_widgets map is widget file slug (as in
 *    jet-elements-banner) => 'true' or 'false'; while that map is missing
 *    or empty every widget is registered, and once saved a missing slug is
 *    off. Its avaliable_extensions map works the same way for the one
 *    extension, section_parallax, which defaults on while the map is not
 *    saved. The suite has no version constant, so presence is its
 *    jet_elements() function. Its widgets are Elementor\Jet_Elements_*
 *    classes; the 'cherry' category is shared with the other Crocoblock
 *    plugins, so it is not used to recognize them. The suite caches the
 *    option on its settings singleton for the request, which is dropped
 *    after a write and after a rollback.
 *  - Happy Addons 3.52 (HAPPY_ADDONS_VERSION): three lists of the keys that
 *    are OFF, happyaddons_inactive_widgets, _features and _extensions; a key
 *    is on unless its list names it, and an unsaved list is empty. Features
 *    and extensions share names, so their keys carry a feature: or
 *    extension: prefix; always-on features are not switches. Widgets are
 *    Happy_Addons\Elementor\Widget\* classes.
 *  - Royal Elementor Addons 1.7 (WPR_ADDONS_VERSION): one option per
 *    module, wpr-element-{slug}, on while it is 'on' or unsaved; the
 *    settings form saves an unchecked module as ''. A group (widgets, theme
 *    builder, WooCommerce builder) with every module off loads the whole
 *    group again while wpr-element-toggle-all was never saved, so a batch
 *    that leaves a group all off saves that switch off too, and a batch in
 *    a group running on that fallback writes the rest of it out as on.
 *    Widgets are WprAddons\* classes.
 *  - PowerPack Lite 3.2 (POWERPACK_ELEMENTS_LITE_VER): pp_elementor_modules
 *    is a list of the widget names that are ON, or the string 'disabled'
 *    for none (never an empty list); unsaved means every widget except the
 *    ones that ship off. Legacy names read as their current name and are
 *    saved as it. PowerPack flushes its own lookup on every write of the
 *    option. On multisite it reads a network copy, so the pack refuses
 *    there (addon_suite_multisite). Widgets are PowerpackElementsLite\*
 *    classes. The paid PowerPack is a different plugin and is not covered.
 */
final class Elementor_Addon_Packs
{
    /** Premium Addons keys that stay off until the site turns them on. */
    private const PREMIUM_OPT_IN = [ 'pa_mc_temp', 'premium-ai-abilities' ];

    /** Upper bound on one toggle batch. */
    private const MAX_MODULES = 200;

    /** Upper bound on the widgets one control read names. */
    private const MAX_NAMED_WIDGETS = 5;

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
        'jet-elements'     => [
            'label'      => 'JetElements',
            'function'   => 'jet_elements',
            'option'     => 'jet-elements-settings',
            'format'     => 'jet_settings',
            'categories' => [],
            'namespaces' => [ 'Elementor\\Jet_Elements_' ],
        ],
        'happy-addons'     => [
            'label'      => 'Happy Addons',
            'constant'   => 'HAPPY_ADDONS_VERSION',
            'option'     => 'happyaddons_inactive_widgets, happyaddons_inactive_features, happyaddons_inactive_extensions',
            'format'     => 'happy_lists',
            'categories' => [],
            'namespaces' => [ 'Happy_Addons\\Elementor\\Widget\\' ],
        ],
        'royal-addons'     => [
            'label'      => 'Royal Elementor Addons',
            'product'    => 'Royal Elementor Addons',
            'constant'   => 'WPR_ADDONS_VERSION',
            'option'     => 'wpr-element-{module}',
            'format'     => 'royal_options',
            'categories' => [],
            'namespaces' => [ 'WprAddons\\' ],
        ],
        'powerpack'        => [
            'label'      => 'PowerPack Lite',
            'product'    => 'PowerPack Lite for Elementor',
            'constant'   => 'POWERPACK_ELEMENTS_LITE_VER',
            'option'     => 'pp_elementor_modules',
            'format'     => 'powerpack_list',
            'categories' => [],
            'namespaces' => [ 'PowerpackElementsLite\\' ],
        ],
    ];

    /** Happy Addons' three lists of switched-off keys, by key prefix ('' for widgets). */
    private const HAPPY_LISTS = [
        ''           => 'happyaddons_inactive_widgets',
        'feature:'   => 'happyaddons_inactive_features',
        'extension:' => 'happyaddons_inactive_extensions',
    ];

    /** Royal Elementor Addons' master switch, which decides its all-off fallback. */
    private const ROYAL_TOGGLE_ALL = 'wpr-element-toggle-all';

    /** The two module maps inside the JetElements settings option. */
    private const JET_WIDGETS    = 'avaliable_widgets';
    private const JET_EXTENSIONS = 'avaliable_extensions';

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
                'description'  => sprintf('List the %1$s widgets registered in Elementor (name, title, categories, control count; no controls). Name one widget (widget) or up to %2$d (widgets) to get just those with a control summary each (type, label, default, option keys of select-like controls; shared and group controls once per answer). detail:"full" with exactly one named widget returns its complete control definitions (conditions, selectors, repeater fields). Controls for the whole list are refused', $label, self::MAX_NAMED_WIDGETS),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'widget'           => [ 'type' => 'string' ],
                        'widgets'          => [
                            'type'     => 'array',
                            'items'    => [ 'type' => 'string' ],
                            'minItems' => 1,
                            'maxItems' => self::MAX_NAMED_WIDGETS,
                        ],
                        'include_controls' => [ 'type' => 'boolean' ],
                        'detail'           => [ 'type' => 'string', 'enum' => [ 'summary', 'full' ] ],
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
                    $plan  = self::plan($suite, (array) ($args['modules'] ?? []));
                    $names = array_map('strval', array_keys($plan['writes'] ?? []));
                    if (empty($plan['changes']) || [] === $names) {
                        return null;
                    }
                    // Suites that keep their switches in several options
                    // (Happy Addons, Royal Elementor Addons) are captured as
                    // one option_set, so one rollback puts back all of them.
                    return 1 === count($names)
                        ? [ 'object_type' => 'option', 'object_id' => $names[0] ]
                        : [ 'object_type' => 'option_set', 'object_id' => Snapshot::option_set_id($names) ];
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
        foreach ([ 'premium-addons', 'jet-elements' ] as $suite) {
            if (in_array(self::SUITES[ $suite ]['option'], $names, true)) {
                self::after_write($suite);
            }
        }
    }

    /** Whether a suite is loaded, filterable per suite for sites and tests. */
    public static function active(string $suite): bool
    {
        $def    = self::SUITES[ $suite ];
        $loaded = isset($def['function']) ? function_exists($def['function']) : defined($def['constant']);
        if ('jet-elements' === $suite) {
            return (bool) apply_filters('wpmcp_jet_elements_active', $loaded);
        }
        if ('happy-addons' === $suite) {
            return (bool) apply_filters('wpmcp_happy_addons_active', $loaded);
        }
        if ('royal-addons' === $suite) {
            return (bool) apply_filters('wpmcp_royal_addons_active', $loaded);
        }
        if ('powerpack' === $suite) {
            return (bool) apply_filters('wpmcp_powerpack_active', $loaded);
        }
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
            return Plugin_Refusal::make('addon_suite_inactive', (string) (self::SUITES[ $suite ]['product'] ?? sprintf('%s for Elementor', $label)));
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
        $detail = (string) ($args['detail'] ?? 'summary');
        $label  = self::SUITES[ $suite ]['label'];
        $named  = self::named_widgets($args);
        $ask    = sprintf('Name one widget with {"widget": "<name>"} or up to %d with {"widgets": ["<name>", ...]}; the list without controls gives every %s widget name.', self::MAX_NAMED_WIDGETS, $label);

        if (! in_array($detail, [ 'summary', 'full' ], true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Refused('invalid_detail', sprintf('detail is "summary" (the default) or "full", not "%s".', $detail), [ 'detail' => $detail ]);
        }
        if (count($named) > self::MAX_NAMED_WIDGETS) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Refused('too_many_widgets', sprintf('Controls come for at most %d named widgets per call, not %d. Split the names over several calls.', self::MAX_NAMED_WIDGETS, count($named)), [ 'max' => self::MAX_NAMED_WIDGETS ]);
        }
        if ('full' === $detail && 1 !== count($named)) {
            $message = sprintf('Full control definitions run to hundreds of KB per widget, so they come for exactly one named widget: {"widget": "<name>", "detail": "full"}. Without detail, up to %d named widgets come with a control summary.', self::MAX_NAMED_WIDGETS);
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Refused('full_detail_needs_widget', $message, [ 'detail' => 'full' ]);
        }
        if ([] === $named && ! empty($args['include_controls'])) {
            $message = sprintf('Controls for every %s widget run to megabytes, so they come for named widgets only. %s', $label, $ask);
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Refused('controls_need_widget', $message, [ 'max' => self::MAX_NAMED_WIDGETS ]);
        }

        $include = [] !== $named;
        $manager = \Elementor\Plugin::instance()->widgets_manager;
        $rows    = [];
        $objects = [];
        foreach ((array) $manager->get_widget_types() as $widget) {
            if (! $widget instanceof \Elementor\Widget_Base || ! self::owns($suite, $widget)) {
                continue;
            }
            $name = (string) $widget->get_name();
            if ($include && ! in_array($name, $named, true)) {
                continue;
            }
            $row = [
                'name'          => $name,
                'title'         => (string) $widget->get_title(),
                'categories'    => array_values((array) $widget->get_categories()),
                'icon'          => (string) $widget->get_icon(),
                'control_count' => count((array) $widget->get_controls()),
            ];
            if ('full' === $detail) {
                $row['controls'] = Widget_View::control_definitions($widget);
            }
            $rows[ $name ] = $row;
            if ($include) {
                $objects[ $name ] = $widget;
            }
        }

        $missing = array_values(array_diff($named, array_keys($rows)));
        if ([] !== $missing) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Refused('unknown_widget', sprintf('No %s widget is registered as "%s".', $label, implode('", "', $missing)), [ 'widget' => $missing[0], 'widgets' => $missing ]);
        }
        ksort($rows);
        ksort($objects);

        // A summary gives what the widgets share (Elementor's common
        // controls, group control fields) once per answer (#878, #862).
        $shared = [];
        if ($include && 'summary' === $detail) {
            $common = [];
            foreach ([ 'common', 'common-optimized' ] as $common_name) {
                $common_widget = $manager->get_widget_types($common_name);
                if ($common_widget instanceof \Elementor\Widget_Base) {
                    $common[] = (array) $common_widget->get_controls();
                }
            }
            $shared = Widget_View::shared_summary($objects, $common);
            foreach ($shared['widgets'] as $name => $part) {
                $rows[ $name ] += $part;
            }
            unset($shared['widgets']);
        }

        $out = [ 'suite' => $suite, 'count' => count($rows), 'widgets' => array_values($rows) ];
        if ($include) {
            $out['detail'] = $detail;
        }
        return $out + $shared;
    }

    /**
     * The widget names a read asks for, from widget and widgets together,
     * each once.
     *
     * @return string[]
     */
    private static function named_widgets(array $args): array
    {
        $names = isset($args['widgets']) && is_array($args['widgets']) ? $args['widgets'] : [];
        if (isset($args['widget']) && '' !== (string) $args['widget']) {
            array_unshift($names, $args['widget']);
        }
        $names = array_map('strval', array_filter($names, 'is_scalar'));
        return array_values(array_unique(array_filter($names, static fn (string $name): bool => '' !== $name)));
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
        $refusal = self::multisite_refusal($suite);
        if (null !== $refusal) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Refused((string) $refusal['code'], (string) $refusal['message'], (array) $refusal['data']);
        }
        return [ 'suite' => $suite, 'option' => self::SUITES[ $suite ]['option'], 'modules' => self::state($suite, self::raw($suite)) ];
    }

    /** The suite's one stored option, or null for suites that keep several. */
    private static function raw(string $suite)
    {
        $def = self::SUITES[ $suite ];
        return in_array($def['format'], [ 'happy_lists', 'royal_options', 'powerpack_list' ], true) ? null : get_option($def['option'], null);
    }

    /**
     * PowerPack reads its network copy of the module list on multisite
     * unless the site overrides it, so the pack does not guess which copy is
     * live there and refuses instead.
     *
     * @return array{code: string, message: string, data: array<string,mixed>}|null
     */
    private static function multisite_refusal(string $suite): ?array
    {
        if ('powerpack' !== $suite || ! is_multisite()) {
            return null;
        }
        return [
            'code'    => 'addon_suite_multisite',
            'message' => 'PowerPack Lite reads its module list from the network settings on multisite, so its modules are read and switched in its own settings screen there.',
            'data'    => [ 'suite' => $suite ],
        ];
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
        } elseif ('jet-elements' === $suite) {
            $settings = self::jet_settings();
            if (null !== $settings) {
                foreach (array_keys((array) $settings->avaliable_widgets) as $slug) {
                    $out[ (string) $slug ] = true;
                }
                foreach (array_keys((array) $settings->default_avaliable_extensions) as $key) {
                    $out[ (string) $key ] = true;
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
        if ('jet_settings' === $format) {
            return self::jet_state($raw);
        }
        if ('happy_lists' === $format) {
            return self::happy_state();
        }
        if ('royal_options' === $format) {
            return self::royal_state();
        }
        if ('powerpack_list' === $format) {
            return self::powerpack_state();
        }
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
     * Dry-run a toggle batch: every module known, and the option values
     * (option name => value) that writing only the real changes would store.
     *
     * @param array<string,mixed> $modules
     * @return array{error?: array<string,mixed>, changes?: array<string,bool>, writes?: array<string,mixed>}
     */
    private static function plan(string $suite, array $modules): array
    {
        $def     = self::SUITES[ $suite ];
        $refusal = self::multisite_refusal($suite);
        if (null !== $refusal) {
            return [ 'error' => $refusal ];
        }
        $raw   = self::raw($suite);
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

        if ('happy_lists' === $def['format']) {
            return [ 'changes' => $changes, 'writes' => self::happy_writes($changes) ];
        }
        if ('royal_options' === $def['format']) {
            return [ 'changes' => $changes, 'writes' => [] === $changes ? [] : self::royal_writes($state, $changes) ];
        }
        if ('powerpack_list' === $def['format']) {
            return [ 'changes' => $changes, 'writes' => [] === $changes ? [] : [ $def['option'] => self::powerpack_value($changes) ] ];
        }

        $value = is_array($raw) ? $raw : [];
        if ('jet_settings' === $def['format']) {
            $value = [] === $changes ? $value : self::jet_value($value, $state, $changes);
            return [ 'changes' => $changes, 'writes' => [ $def['option'] => $value ] ];
        }
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

        return [ 'changes' => $changes, 'writes' => [ $def['option'] => $value ] ];
    }

    /** @return array<string,mixed> */
    private static function write(string $suite, array $modules): array
    {
        $plan = self::plan($suite, $modules);
        if (isset($plan['error'])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- surfaced as a JSON tool error by Integration_Dispatcher, never rendered as HTML.
            throw new Operation_Refused((string) $plan['error']['code'], (string) $plan['error']['message'], (array) $plan['error']['data']);
        }
        if ([] !== $plan['changes']) {
            foreach ($plan['writes'] as $name => $value) {
                update_option((string) $name, $value);
            }
            self::after_write($suite);
        }

        return [
            'suite'   => $suite,
            'option'  => self::SUITES[ $suite ]['option'],
            'changed' => $plan['changes'],
            'modules' => self::state($suite, self::raw($suite)),
        ];
    }

    /** The suite's own cache refresh after its option changed. */
    private static function after_write(string $suite): void
    {
        if ('premium-addons' === $suite) {
            wp_cache_delete('pa_elements', 'premium_addons');
        }
        if ('jet-elements' === $suite) {
            $settings = self::jet_settings();
            if (null !== $settings && property_exists($settings, 'settings')) {
                $settings->settings = null;
            }
        }
    }

    /** The JetElements settings singleton, when the suite has loaded it. */
    private static function jet_settings(): ?object
    {
        $class = '\\Jet_Elements_Settings';
        if (! is_callable([ $class, 'get_instance' ])) {
            return null;
        }
        $settings = $class::get_instance();
        return is_object($settings) ? $settings : null;
    }

    /**
     * JetElements module state for a raw jet-elements-settings value: the
     * widget and extension maps resolved the way the suite's own
     * register_addons() and section extension read them.
     *
     * @param mixed $raw
     * @return array<string,bool>
     */
    private static function jet_state($raw): array
    {
        $stored     = is_array($raw) ? $raw : [];
        $known      = self::known('jet-elements');
        $settings   = self::jet_settings();
        $ext_keys   = null !== $settings ? array_map('strval', array_keys((array) $settings->default_avaliable_extensions)) : [];
        $widgets    = $stored[ self::JET_WIDGETS ] ?? null;
        $extensions = array_key_exists(self::JET_EXTENSIONS, $stored) ? $stored[ self::JET_EXTENSIONS ] : null;
        $widgets    = is_array($widgets) ? $widgets : [];

        $out = [];
        foreach (array_keys($known + array_fill_keys(array_map('strval', array_keys($widgets)), true)) as $key) {
            $key = (string) $key;
            if (in_array($key, $ext_keys, true)) {
                $value = is_array($extensions) ? ($extensions[ $key ] ?? false) : true;
            } else {
                // An empty or missing map registers every widget.
                $value = [] === $widgets ? true : ($widgets[ $key ] ?? false);
            }
            $out[ $key ] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        ksort($out);
        return $out;
    }

    /**
     * The jet-elements-settings value with the changes applied: every other
     * setting kept as stored, toggles as the 'true'/'false' strings the
     * suite saves. A map the suite has not saved yet is written out in full
     * from the current state, since saving only the toggled key would switch
     * every other widget off.
     *
     * @param array<mixed>       $value
     * @param array<string,bool> $state
     * @param array<string,bool> $changes
     * @return array<mixed>
     */
    private static function jet_value(array $value, array $state, array $changes): array
    {
        $settings = self::jet_settings();
        $ext_keys = null !== $settings ? array_map('strval', array_keys((array) $settings->default_avaliable_extensions)) : [];

        foreach ($changes as $key => $on) {
            $map = in_array((string) $key, $ext_keys, true) ? self::JET_EXTENSIONS : self::JET_WIDGETS;
            if (! isset($value[ $map ]) || ! is_array($value[ $map ]) || [] === $value[ $map ]) {
                $value[ $map ] = [];
                foreach ($state as $name => $current) {
                    if (in_array($name, $ext_keys, true) === (self::JET_EXTENSIONS === $map)) {
                        $value[ $map ][ $name ] = $current ? 'true' : 'false';
                    }
                }
            }
            $value[ $map ][ (string) $key ] = $on ? 'true' : 'false';
        }
        return $value;
    }

    /**
     * Happy Addons' switchable keys per list prefix: every widget its
     * register() loop knows, every feature but the always-on ones (which the
     * suite enables whatever the list says) and every extension.
     *
     * @return array<string,string[]>
     */
    private static function happy_catalog(): array
    {
        $widgets    = '\\Happy_Addons\\Elementor\\Classes\\Widgets_Manager';
        $extensions = '\\Happy_Addons\\Elementor\\Classes\\Extensions_Manager';
        $out        = [ '' => [], 'feature:' => [], 'extension:' => [] ];
        if (is_callable([ $widgets, 'get_local_widgets_map' ])) {
            $out[''] = array_map('strval', array_keys((array) $widgets::get_local_widgets_map()));
        }
        if (is_callable([ $extensions, 'get_local_features_map' ])) {
            $out['feature:'] = array_values(array_diff(array_map('strval', array_keys((array) $extensions::get_local_features_map())), self::happy_always_on()));
        }
        if (is_callable([ $extensions, 'get_local_extensions_map' ])) {
            $out['extension:'] = array_map('strval', array_keys((array) $extensions::get_local_extensions_map()));
        }
        return $out;
    }

    /** @return string[] */
    private static function happy_always_on(): array
    {
        $extensions = '\\Happy_Addons\\Elementor\\Classes\\Extensions_Manager';
        return is_callable([ $extensions, 'get_always_on_features' ])
            ? array_map('strval', (array) $extensions::get_always_on_features())
            : [];
    }

    /**
     * One Happy Addons list of switched-off keys as stored.
     *
     * @return string[]
     */
    private static function happy_list(string $option): array
    {
        $list = get_option($option, []);
        return is_array($list) ? array_values(array_map('strval', array_filter($list, 'is_scalar'))) : [];
    }

    /**
     * Happy Addons module state: a key is on unless its list names it.
     * Widget keys are bare, feature and extension keys carry a feature: or
     * extension: prefix, since the two maps share names.
     *
     * @return array<string,bool>
     */
    private static function happy_state(): array
    {
        $catalog   = self::happy_catalog();
        $always_on = self::happy_always_on();
        $out       = [];
        foreach (self::HAPPY_LISTS as $prefix => $option) {
            $off = self::happy_list($option);
            foreach ($catalog[ $prefix ] as $key) {
                $out[ $prefix . $key ] = ! in_array($key, $off, true);
            }
            foreach ($off as $key) {
                if ('feature:' === $prefix && in_array($key, $always_on, true)) {
                    continue;
                }
                $out[ $prefix . $key ] = false;
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * The Happy Addons lists the changes touch, each as the suite saves it:
     * a plain list, order kept, a key switched off appended once and a key
     * switched on removed.
     *
     * @param array<string,bool> $changes
     * @return array<string,string[]>
     */
    private static function happy_writes(array $changes): array
    {
        $writes = [];
        foreach ($changes as $key => $on) {
            $prefix = '';
            foreach ([ 'feature:', 'extension:' ] as $candidate) {
                if (0 === strpos((string) $key, $candidate)) {
                    $prefix = $candidate;
                }
            }
            $option = self::HAPPY_LISTS[ $prefix ];
            $bare   = substr((string) $key, strlen($prefix));
            $list   = $writes[ $option ] ?? self::happy_list($option);
            $list   = array_values(array_filter($list, static fn (string $item): bool => $item !== $bare));
            if (! $on) {
                $list[] = $bare;
            }
            $writes[ $option ] = $list;
        }
        return $writes;
    }

    /**
     * Royal Elementor Addons' module slugs in the groups its Manager loads:
     * the widgets, the theme builder modules and, with WooCommerce, the
     * WooCommerce builder modules.
     *
     * @return array<string,string[]>
     */
    private static function royal_groups(): array
    {
        $utilities = '\\WprAddons\\Classes\\Utilities';
        $lists     = [ 'widgets' => 'get_registered_modules', 'theme' => 'get_theme_builder_modules' ];
        if (class_exists('WooCommerce')) {
            $lists['woocommerce'] = 'get_woocommerce_builder_modules';
        }
        $out = [];
        foreach ($lists as $group => $method) {
            if (! is_callable([ $utilities, $method ])) {
                continue;
            }
            $slugs = [];
            foreach ((array) $utilities::$method() as $data) {
                $slug = (string) (is_array($data) ? ($data[0] ?? '') : $data);
                if ('' !== $slug) {
                    $slugs[] = $slug;
                }
            }
            $out[ $group ] = $slugs;
        }
        return $out;
    }

    /** Whether one Royal module's own option reads on (unsaved is on). */
    private static function royal_saved_on(string $slug): bool
    {
        return 'on' === get_option('wpr-element-' . $slug, 'on');
    }

    /** Whether Royal's master switch was never saved, which turns on its all-off fallback. */
    private static function royal_toggle_unsaved(): bool
    {
        return false === get_option(self::ROYAL_TOGGLE_ALL, false);
    }

    /**
     * Royal module state, as its Manager resolves it: each module on while
     * its option is 'on' or unsaved, except that a group with every module
     * off loads the whole group again while the master switch is unsaved.
     *
     * @return array<string,bool>
     */
    private static function royal_state(): array
    {
        $fallback = self::royal_toggle_unsaved();
        $out      = [];
        foreach (self::royal_groups() as $slugs) {
            $group = [];
            foreach ($slugs as $slug) {
                $group[ $slug ] = self::royal_saved_on($slug);
            }
            if ([] !== $group && $fallback && ! in_array(true, $group, true)) {
                $group = array_fill_keys(array_keys($group), true);
            }
            $out += $group;
        }
        ksort($out);
        return $out;
    }

    /**
     * The Royal options a batch writes, in the settings form's own values
     * ('on' when checked, '' when not). In every group the batch touches,
     * each module whose saved value differs from the state wanted is
     * written, so a module that was on only through the all-off fallback
     * stays on. A group the batch leaves all off saves the master switch as
     * off too, or Royal would load that group again.
     *
     * @param array<string,bool> $state
     * @param array<string,bool> $changes
     * @return array<string,string>
     */
    private static function royal_writes(array $state, array $changes): array
    {
        $wanted = array_merge($state, $changes);
        $writes = [];
        foreach (self::royal_groups() as $slugs) {
            if ([] === array_intersect($slugs, array_map('strval', array_keys($changes)))) {
                continue;
            }
            $any_on = false;
            foreach ($slugs as $slug) {
                $on     = (bool) ($wanted[ $slug ] ?? true);
                $any_on = $any_on || $on;
                if (self::royal_saved_on($slug) !== $on) {
                    $writes[ 'wpr-element-' . $slug ] = $on ? 'on' : '';
                }
            }
            if (! $any_on && self::royal_toggle_unsaved()) {
                $writes[ self::ROYAL_TOGGLE_ALL ] = '';
            }
        }
        return $writes;
    }

    /**
     * The widgets PowerPack Lite has switched on, as its own
     * powerpack_elements_lite_get_enabled_modules() reads them: the string
     * 'disabled' is none, an unsaved option is every widget that does not
     * ship off, and a saved list is read with legacy names as their current
     * name.
     *
     * @param mixed $raw
     * @return string[]
     */
    private static function powerpack_enabled($raw): array
    {
        if ('disabled' === $raw) {
            return [];
        }
        if (! is_array($raw)) {
            $catalog = function_exists('powerpack_elements_lite_get_modules') ? array_map('strval', array_keys((array) powerpack_elements_lite_get_modules())) : [];
            $off     = function_exists('powerpack_elements_lite_get_default_off_modules') ? array_map('strval', (array) powerpack_elements_lite_get_default_off_modules()) : [];
            return array_values(array_diff($catalog, $off));
        }
        $legacy = function_exists('powerpack_elements_lite_get_legacy_module_names') ? (array) powerpack_elements_lite_get_legacy_module_names() : [];
        $out    = [];
        foreach ($raw as $key => $value) {
            $name = is_int($key) ? $value : $key;
            if (! is_string($name) || '' === $name) {
                continue;
            }
            $name = (string) ($legacy[ $name ] ?? $name);
            if (! in_array($name, $out, true)) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /** @return array<string,bool> */
    private static function powerpack_state(): array
    {
        $enabled = self::powerpack_enabled(get_option(self::SUITES['powerpack']['option'], false));
        $catalog = function_exists('powerpack_elements_lite_get_modules') ? array_map('strval', array_keys((array) powerpack_elements_lite_get_modules())) : [];
        $out     = [];
        foreach (array_unique(array_merge($catalog, $enabled)) as $name) {
            $out[ $name ] = in_array($name, $enabled, true);
        }
        ksort($out);
        return $out;
    }

    /**
     * The pp_elementor_modules value after the changes, the way PowerPack's
     * settings save stores it: a plain list of the enabled widget names
     * (current names, unknown names kept), or the string 'disabled' when
     * none is left, never an empty list.
     *
     * @param array<string,bool> $changes
     * @return string[]|string
     */
    private static function powerpack_value(array $changes)
    {
        $list = self::powerpack_enabled(get_option(self::SUITES['powerpack']['option'], false));
        foreach ($changes as $name => $on) {
            $list = array_values(array_filter($list, static fn (string $item): bool => $item !== (string) $name));
            if ($on) {
                $list[] = (string) $name;
            }
        }
        return [] === $list ? 'disabled' : $list;
    }
}
