<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Weglot and GTranslate language settings and exclusions (issue #690),
 * paid-tier ops on the plugin-data pair, so they add no top-level tool:
 *
 *  - weglot-get-settings: the original language, the destination languages
 *    (code, custom code and name, public or private), the excluded URLs and
 *    blocks and whether the project is connected. Weglot keeps these in its
 *    cloud project and the plugin reads them through weglot_get_options()
 *    (cached from Weglot's CDN); the answer never carries an API key.
 *    Read only: a change is a remote write to the Weglot project, which no
 *    local snapshot can undo and which can change the plan's word usage, so
 *    it stays in the Weglot dashboard.
 *  - gtranslate-get-settings: the default language, the widget look, the
 *    dropdown and flag language lists, the languages the current look shows
 *    and whether a paid GTranslate plan is set. GTranslate has no URL
 *    exclusion setting; content is excluded with the notranslate class.
 *  - gtranslate-set-languages: replace the dropdown list (incl_langs) and or
 *    the flag list (fincl_langs) in the 'GTranslate' option, codes checked
 *    against GTranslate's own list, the default language kept in each list
 *    the way GTranslate's settings screen keeps it. Snapshotted on the
 *    option, so rollback-operation puts it back exactly. Off until the site
 *    opts in through wpmcp_enable_translation_settings_write.
 *
 * Every op runs at manage_options, the capability both plugins' settings
 * screens need. Without the plugin the ops answer plugin_missing or
 * plugin_inactive naming it. Presence is filterable (wpmcp_weglot_active,
 * wpmcp_gtranslate_active).
 */
final class Translation_Settings_Pack
{
    public const ENABLE_FILTER = 'wpmcp_enable_translation_settings_write';

    /** GTranslate's own settings option. */
    private const GTRANSLATE_OPTION = 'GTranslate';

    /** Looks that show the flag list, per GTranslate 5.0.2; flags_dropdown shows both, the rest the dropdown list. */
    private const FLAG_LOOKS = [ 'float', 'flags', 'dropdown_with_flags', 'flags_name', 'flags_code', 'popup', 'popup_search' ];

    private const CHECK_NOTE = 'Load a page on the front end and check the language switcher shows the languages you expect; undo with rollback-operation if not.';

    public static function weglot_active(): bool
    {
        return (bool) apply_filters('wpmcp_weglot_active', defined('WEGLOT_VERSION') && function_exists('weglot_get_options'));
    }

    public static function gtranslate_active(): bool
    {
        return (bool) apply_filters('wpmcp_gtranslate_active', defined('GTRANSLATE_VERSION') && class_exists('GTranslate'));
    }

    /**
     * Whether either plugin is usable and this install may run the paid
     * tier, or Loco Translate's .po file ops are (issue #691), which this
     * pack carries onto the pair with its own.
     */
    public static function active(): bool
    {
        return (Registrar::tier_permitted('pro') && (self::weglot_active() || self::gtranslate_active()))
            || Loco_Translate_Pack::active();
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        return self::weglot_operations() + self::gtranslate_operations() + Loco_Translate_Pack::operations();
    }

    /** @return array<string, array<string, mixed>> */
    public static function weglot_operations(): array
    {
        return [
            'weglot-get-settings' => [
                'mode'         => 'read',
                'tier'         => 'pro',
                'capability'   => 'manage_options',
                'requires'     => static fn () => self::weglot_active() ? true : self::refusal('Weglot'),
                'description'  => 'Weglot original and destination languages, excluded URLs and blocks, and connection status. Read only: Weglot keeps them in its cloud project',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => static fn (): array => self::weglot_settings(),
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function gtranslate_operations(): array
    {
        // The literal name, not ENABLE_FILTER, so the hook stays greppable.
        $enabled    = (bool) apply_filters('wpmcp_enable_translation_settings_write', false);
        $gtranslate = [
            'tier'       => 'pro',
            'capability' => 'manage_options',
            'requires'   => static fn () => self::gtranslate_active() ? true : self::refusal('GTranslate'),
        ];
        $codes      = [ 'type' => 'array', 'minItems' => 1, 'maxItems' => 120, 'items' => [ 'type' => 'string', 'minLength' => 2, 'maxLength' => 10 ] ];

        return [
            'gtranslate-get-settings'  => $gtranslate + [
                'mode'         => 'read',
                'description'  => 'GTranslate default language, widget look, dropdown and flag language lists and the languages shown',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => static fn (): array => self::gtranslate_settings($enabled),
            ],
            'gtranslate-set-languages' => $gtranslate + [
                'mode'               => 'write',
                'enabled_by_default' => $enabled,
                'opt_in_filter'      => self::ENABLE_FILTER,
                'description'        => 'Replace GTranslate\'s dropdown_languages and/or flag_languages (language codes, default language included). Snapshotted; undo: rollback-operation. Disabled by default (wpmcp_enable_translation_settings_write filter)',
                'input_schema'       => [
                    'type'          => 'object',
                    'properties'    => [ 'dropdown_languages' => $codes, 'flag_languages' => $codes ],
                    'minProperties' => 1,
                ],
                'validate'           => static fn (array $args): ?array => self::set_refusal($args),
                'snapshot'           => static fn (): array => [ 'object_type' => 'option', 'object_id' => self::GTRANSLATE_OPTION ],
                'handler'            => static fn (array $args): array => self::set_languages($args),
            ],
        ];
    }

    /**
     * plugin_missing or plugin_inactive naming the plugin and the plugins screen.
     *
     * @return array{code: string, message: string, data: array<string, string>}
     */
    private static function refusal(string $plugin): array
    {
        $refusal         = Plugin_Refusal::make('plugin_missing', $plugin);
        $refusal['code'] = 'inactive' === $refusal['data']['state'] ? 'plugin_inactive' : 'plugin_missing';
        return $refusal;
    }

    // ---------------------------------------------------------------
    // Weglot
    // ---------------------------------------------------------------

    /** @return array<string, mixed> Weglot's mapped options, as weglot_get_options() returns them. */
    private static function weglot_options(): array
    {
        $options = [];
        if (function_exists('weglot_get_options')) {
            try {
                $options = (array) weglot_get_options();
            } catch (\Throwable $e) {
                $options = [];
            }
        }
        return (array) apply_filters('wpmcp_weglot_options', $options);
    }

    /** @return array<string, mixed> */
    private static function weglot_settings(): array
    {
        $options   = self::weglot_options();
        $languages = $options['destination_language'] ?? ($options['languages'] ?? []);
        $excluded  = $options['exclude_urls'] ?? ($options['excluded_paths'] ?? []);
        $blocks    = $options['exclude_blocks'] ?? ($options['excluded_blocks'] ?? []);

        return [
            'original_language' => (string) ($options['original_language'] ?? ($options['language_from'] ?? '')),
            'languages'         => array_values(array_map(static function ($lang): array {
                $lang = (array) $lang;
                return [
                    'code'        => (string) ($lang['language_to'] ?? ''),
                    'custom_code' => isset($lang['custom_code']) && '' !== $lang['custom_code'] ? (string) $lang['custom_code'] : null,
                    'custom_name' => isset($lang['custom_name']) && '' !== $lang['custom_name'] ? (string) $lang['custom_name'] : null,
                    'public'      => (bool) ($lang['public'] ?? ($lang['enabled'] ?? true)),
                ];
            }, array_filter((array) $languages, 'is_array'))),
            'excluded_urls'     => array_values(array_map(static function ($item): array {
                $item = (array) $item;
                return [
                    'type'      => (string) ($item['type'] ?? 'CONTAIN'),
                    'value'     => (string) ($item['value'] ?? ''),
                    'languages' => array_values(array_map('strval', (array) ($item['excluded_languages'] ?? ($item['languages'] ?? [])))),
                    'behavior'  => (string) ($item['exclusion_behavior'] ?? 'NOT_TRANSLATED'),
                ];
            }, array_filter((array) $excluded, 'is_array'))),
            'excluded_blocks'   => array_values(array_map(
                static fn ($block): string => is_array($block) ? (string) ($block['value'] ?? '') : (string) $block,
                (array) $blocks
            )),
            'status'            => [
                'connected'          => '' !== (string) ($options['api_key'] ?? ''),
                'allowed'            => (bool) ($options['allowed'] ?? false),
                'translation_engine' => isset($options['translation_engine']) ? (int) $options['translation_engine'] : null,
                'auto_redirect'      => (bool) ($options['auto_redirect'] ?? ($options['auto_switch'] ?? false)),
            ],
            'writable'          => false,
            'note'              => 'Weglot keeps languages and exclusions in its cloud project; change them in the Weglot dashboard (https://dashboard.weglot.com/). Translations are stored there too.',
        ];
    }

    // ---------------------------------------------------------------
    // GTranslate
    // ---------------------------------------------------------------

    /** @return array<string, mixed> The stored option with GTranslate's own defaults filled in. */
    private static function gtranslate_data(): array
    {
        $data = get_option(self::GTRANSLATE_OPTION, []);
        if (! is_array($data)) {
            $data = [];
        }
        if (class_exists('GTranslate') && method_exists('GTranslate', 'load_defaults')) {
            \GTranslate::load_defaults($data);
        }
        return $data;
    }

    /** @return string[] */
    private static function codes($list): array
    {
        return array_values(array_unique(array_map('strval', (array) $list)));
    }

    /** @return string[] The languages the switcher shows for the stored look, as GTranslate's front end picks them. */
    private static function active_languages(array $data): array
    {
        $look  = (string) ($data['widget_look'] ?? 'float');
        $flags = self::codes($data['fincl_langs'] ?? []);
        $menu  = self::codes($data['incl_langs'] ?? []);
        if (in_array($look, self::FLAG_LOOKS, true)) {
            return $flags;
        }
        if ('flags_dropdown' === $look) {
            return self::codes(array_merge($flags, $menu));
        }
        return $menu;
    }

    /** @return array<string, mixed> */
    private static function gtranslate_settings(bool $enabled): array
    {
        $data = self::gtranslate_data();

        return self::gtranslate_shape($data) + [
            'paid_plan'      => '' !== (string) ($data['pro_version'] ?? '') || '' !== (string) ($data['enterprise_version'] ?? ''),
            'exclusions'     => 'GTranslate has no URL exclusion setting; content inside an element with the notranslate class is left untranslated.',
            'writes_enabled' => $enabled,
        ];
    }

    /** @return array<string, mixed> */
    private static function gtranslate_shape(array $data): array
    {
        return [
            'default_language'   => (string) ($data['default_language'] ?? ''),
            'widget_look'        => (string) ($data['widget_look'] ?? ''),
            'dropdown_languages' => self::codes($data['incl_langs'] ?? []),
            'flag_languages'     => self::codes($data['fincl_langs'] ?? []),
            'active_languages'   => self::active_languages($data),
        ];
    }

    /** @return array<string, string[]> option key => new list, for the lists the call names. */
    private static function requested(array $args): array
    {
        $lists = [];
        foreach ([ 'dropdown_languages' => 'incl_langs', 'flag_languages' => 'fincl_langs' ] as $arg => $key) {
            if (isset($args[ $arg ])) {
                $lists[ $key ] = self::codes(array_map('trim', array_map('strval', (array) $args[ $arg ])));
            }
        }
        return $lists;
    }

    /** @return array{code: string, message: string, data?: array}|null */
    private static function set_refusal(array $args): ?array
    {
        $lists = self::requested($args);
        if ([] === $lists) {
            return [ 'code' => 'invalid_args', 'message' => 'Pass dropdown_languages, flag_languages or both.' ];
        }
        $data  = self::gtranslate_data();
        $known = class_exists('GTranslate') && isset(\GTranslate::$lang_array) ? array_keys((array) \GTranslate::$lang_array) : [];
        $all   = self::codes(array_merge(...array_values($lists)));
        $bad   = [] === $known ? [] : array_values(array_diff($all, array_map('strval', $known)));
        if ([] !== $bad) {
            return [
                'code'    => 'unknown_language',
                'message' => sprintf('Not a GTranslate language code: %s.', implode(', ', $bad)),
                'data'    => [ 'unknown' => $bad, 'languages' => array_map('strval', $known) ],
            ];
        }
        $default = (string) ($data['default_language'] ?? '');
        foreach ($lists as $list) {
            if ('' !== $default && ! in_array($default, $list, true)) {
                return [
                    'code'    => 'default_language_required',
                    'message' => sprintf('Each list must include the default language "%s", as GTranslate\'s settings screen requires.', $default),
                    'data'    => [ 'default_language' => $default ],
                ];
            }
        }
        $changed = false;
        foreach ($lists as $key => $list) {
            $changed = $changed || $list !== self::codes($data[ $key ] ?? []);
        }
        if (! $changed) {
            return [ 'code' => 'no_change', 'message' => 'Those language lists are already set; nothing to change.' ];
        }
        return null;
    }

    /** @return array<string, mixed> */
    private static function set_languages(array $args): array
    {
        $stored = get_option(self::GTRANSLATE_OPTION, []);
        if (! is_array($stored)) {
            $stored = [];
        }
        if ([] === $stored) {
            $stored = self::gtranslate_data();
        }
        foreach (self::requested($args) as $key => $list) {
            $stored[ $key ] = $list;
        }
        update_option(self::GTRANSLATE_OPTION, $stored);

        return self::gtranslate_shape(self::gtranslate_data()) + [ 'check' => self::CHECK_NOTE ];
    }
}
