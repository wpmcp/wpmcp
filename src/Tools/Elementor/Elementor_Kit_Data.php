<?php

namespace WPMCP\Tools\Elementor;

use WPMCP\Safety\Mutation_Failed;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Read/write access to the active Elementor global Kit (design tokens).
 *
 * A kit is an ordinary post; its palette and typography live in the kit's
 * `_elementor_page_settings` meta (keys system_colors, custom_colors,
 * system_typography, custom_typography), stored as a plain array. Because it
 * is postmeta on the kit post, the existing post snapshot in
 * Safe_Mutation::run() already captures and restores it, so kit edits are
 * undoable via rollback-operation with no safety-core change.
 *
 * Reads come from the persisted meta (the source of truth for user overrides).
 * When a kit has never been customized the four Elementor system tokens are
 * filled from known defaults so an agent can always patch them by _id, which
 * keeps this decoupled from Elementor's runtime control-default merging.
 */
class Elementor_Kit_Data
{
    /** Elementor's default kit palette (the four system color tokens). */
    private const DEFAULT_SYSTEM_COLORS = [
        ['_id' => 'primary', 'title' => 'Primary', 'color' => '#6EC1E4'],
        ['_id' => 'secondary', 'title' => 'Secondary', 'color' => '#54595F'],
        ['_id' => 'text', 'title' => 'Text', 'color' => '#7A7A7A'],
        ['_id' => 'accent', 'title' => 'Accent', 'color' => '#61CE70'],
    ];

    /** Genuine spacing tokens on the kit (gap between widgets, container padding). */
    private const SPACING_KEYS = ['space_between_widgets', 'container_padding'];

    /** Layout/breakpoint tokens: a content width and the responsive viewports. */
    private const LAYOUT_KEYS = ['container_width', 'viewport_lg', 'viewport_md'];

    /** Elementor's four system typography tokens (font fields left for editing). */
    private const DEFAULT_SYSTEM_TYPOGRAPHY = [
        ['_id' => 'primary', 'title' => 'Primary'],
        ['_id' => 'secondary', 'title' => 'Secondary'],
        ['_id' => 'text', 'title' => 'Text'],
        ['_id' => 'accent', 'title' => 'Accent'],
    ];

    public static function active_kit_id(): int
    {
        if (class_exists('\\Elementor\\Plugin') && isset(\Elementor\Plugin::instance()->kits_manager)) {
            $id = (int) \Elementor\Plugin::instance()->kits_manager->get_active_id();
            if ($id > 0 && get_post($id)) {
                return $id;
            }
        }

        $option = (int) get_option('elementor_active_kit');

        return ($option > 0 && get_post($option)) ? $option : 0;
    }

    /** Raw persisted kit settings ([] when the kit has no overrides). */
    public static function settings(int $kit_id): array
    {
        $settings = get_post_meta($kit_id, '_elementor_page_settings', true);

        return is_array($settings) ? $settings : [];
    }

    public static function settings_hash(int $kit_id): string
    {
        return hash('sha256', wp_json_encode(self::settings($kit_id)));
    }

    /**
     * Resolve the active kit and enforce the optimistic-lock guard shared by
     * every kit write: expected_hash must match the current settings_hash
     * (from get-global-settings), so a stale read is refused before any write.
     *
     * @return int|\WP_Error the kit post id.
     */
    public static function guard(array $args)
    {
        $kit_id = self::active_kit_id();
        if ($kit_id <= 0) {
            return new \WP_Error('kit_not_found', 'No active Elementor kit was found.');
        }

        $expected = (string) ($args['expected_hash'] ?? '');
        if ('' === $expected) {
            return new \WP_Error(
                'missing_expected_hash',
                '"expected_hash" is required: read the kit with get-global-settings first and pass back its settings_hash.'
            );
        }

        if (! hash_equals(self::settings_hash($kit_id), $expected)) {
            return new \WP_Error(
                'stale_expected_hash',
                'Stale expected_hash: the kit changed since it was read. Nothing was written. '
                . 'Re-read with get-global-settings and retry.'
            );
        }

        return $kit_id;
    }

    /**
     * The palette/typography an agent should see: persisted overrides, with
     * the four system tokens filled from defaults when absent.
     */
    public static function view(int $kit_id): array
    {
        $settings = self::settings($kit_id);

        return [
            'system_colors'     => self::with_system_defaults($settings['system_colors'] ?? [], self::DEFAULT_SYSTEM_COLORS),
            'custom_colors'     => array_values(is_array($settings['custom_colors'] ?? null) ? $settings['custom_colors'] : []),
            'system_typography' => self::with_system_defaults($settings['system_typography'] ?? [], self::DEFAULT_SYSTEM_TYPOGRAPHY),
            'custom_typography' => array_values(is_array($settings['custom_typography'] ?? null) ? $settings['custom_typography'] : []),
            'spacing'           => self::subset($settings, self::SPACING_KEYS),
            'layout'            => self::subset($settings, self::LAYOUT_KEYS),
        ];
    }

    /**
     * The current title of every system slot, keyed by _id, as the builder
     * shows it: the stored title when the user renamed a slot, otherwise the
     * Elementor default. Replacement tools use this so an entry that omits a
     * title keeps the user's name instead of silently reverting it.
     *
     * @param string $key 'system_colors' or 'system_typography'.
     * @return array<string,string>
     */
    public static function system_slot_titles(int $kit_id, string $key): array
    {
        $defaults = 'system_colors' === $key ? self::DEFAULT_SYSTEM_COLORS : self::DEFAULT_SYSTEM_TYPOGRAPHY;
        $titles   = array_column($defaults, 'title', '_id');

        foreach (self::view($kit_id)[$key] as $entry) {
            if (is_array($entry) && isset($entry['_id'], $entry['title']) && '' !== (string) $entry['title']) {
                $titles[(string) $entry['_id']] = (string) $entry['title'];
            }
        }

        return $titles;
    }

    /**
     * A keyed subset of the persisted settings (absent keys are simply
     * omitted; the builder falls back to Elementor's own defaults for those).
     *
     * TODO(#60): extend SPACING_KEYS with the breakpoint-specific variants
     * (space_between_widgets_tablet etc.) once update-global-spacing lands;
     * reads should stay in lockstep with whatever that write tool can touch.
     */
    private static function subset(array $settings, array $keys): array
    {
        $subset = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $settings)) {
                $subset[$key] = $settings[$key];
            }
        }

        return $subset;
    }

    public static function default_system_colors(): array
    {
        return self::DEFAULT_SYSTEM_COLORS;
    }

    public static function default_system_typography(): array
    {
        return self::DEFAULT_SYSTEM_TYPOGRAPHY;
    }

    /**
     * Snapshot-first write of a top-level settings patch (e.g. new
     * system_colors / custom_colors arrays) into the kit's
     * `_elementor_page_settings`, merged over what is already stored.
     *
     * @return array|\WP_Error operation result or a mutation_failed error.
     */
    public static function write(int $kit_id, array $patch, string $tool_name, array $args)
    {
        $operation_id = wp_generate_uuid4();
        $merged       = array_merge(self::settings($kit_id), $patch);

        try {
            Safe_Mutation::run(
                [
                    'operation_id' => $operation_id,
                    'object_type'  => 'post',
                    'object_id'    => $kit_id,
                    'session_id'   => (string) ($args['session_id'] ?? 'default'),
                    'tool_name'    => $tool_name,
                    'args'         => $args,
                ],
                function () use ($kit_id, $merged) {
                    update_post_meta($kit_id, '_elementor_page_settings', $merged);
                    if (class_exists('\\Elementor\\Plugin') && isset(\Elementor\Plugin::instance()->files_manager)) {
                        \Elementor\Plugin::instance()->files_manager->clear_cache();
                    }
                    return true;
                },
                function () use ($kit_id, $patch) {
                    clean_post_cache($kit_id);
                    $saved = self::settings($kit_id);
                    foreach ($patch as $key => $value) {
                        if (! array_key_exists($key, $saved) || $saved[$key] != $value) { // phpcs:ignore
                            return false;
                        }
                    }
                    return true;
                }
            );
        } catch (Mutation_Failed $e) {
            return new \WP_Error(
                'mutation_failed',
                'The write did not store the intended kit settings; the kit was rolled back to its pre-operation state.'
            );
        } catch (\Throwable $e) {
            Rollback_Service::restore_operation($operation_id);
            return new \WP_Error(
                'mutation_failed',
                'The write failed mid-save and the kit was rolled back: ' . $e->getMessage()
            );
        }

        return [
            'operation_id'  => $operation_id,
            'kit_id'        => $kit_id,
            'settings_hash' => self::settings_hash($kit_id),
        ];
    }

    /**
     * Fill any missing system token (matched by _id) from the default set,
     * preserving the order and values of what the kit already stores.
     */
    private static function with_system_defaults(array $stored, array $defaults): array
    {
        $stored  = is_array($stored) ? array_values($stored) : [];
        $present = array_column($stored, '_id');

        foreach ($defaults as $default) {
            if (! in_array($default['_id'], $present, true)) {
                $stored[] = $default;
            }
        }

        return $stored;
    }
}
