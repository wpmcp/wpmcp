<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Phase 1 of the theme workflow (#144, parent #69): theme context reads plus
 * reversible, allowlist-gated theme-mod writes behind a single
 * wpmcp/theme-read + wpmcp/theme-write dispatcher pair.
 *
 * No file I/O happens in this phase. create-child-theme (phase 2) and the
 * framework packs (phases 3-4) hook the wpmcp_theme_mod_allowlist seam later
 * to extend what set-mods may touch; nothing here needs to change for that.
 *
 * The active theme is always present, so is_available() is always true and
 * the pair never reports integration_unavailable. Both halves demand
 * edit_theme_options, matching what the Customizer itself requires.
 *
 * Write posture mirrors the ACF reference integration: set-mods is
 * default-off behind the wpmcp_enable_theme_write filter, and every accepted
 * write is snapshotted on the theme_mods_{stylesheet} option so
 * rollback-operation restores the exact prior state.
 *
 * Two allowlist layers, deliberately asymmetric:
 *  - STRUCTURAL_KEYS is a hard refusal evaluated BEFORE the allowlist filter,
 *    so a filter can never open nav_menu_locations, sidebars_widgets, or
 *    custom_css_post_id: those rewire site structure rather than
 *    presentation, and a bad write there is not "wrong colors" but broken
 *    navigation or orphaned CSS posts.
 *  - The presentation allowlist (core logo/header/background mods) is
 *    extendable via the wpmcp_theme_mod_allowlist filter, which is the seam
 *    framework packs use to expose their own mod keys.
 */
class Theme_Integration extends Integration_Dispatcher
{
    /**
     * Mods that rewire structure rather than presentation. Hard-refused in
     * set-mods even when a filter adds them to the allowlist.
     */
    private const STRUCTURAL_KEYS = [
        'nav_menu_locations',
        'sidebars_widgets',
        'custom_css_post_id',
    ];

    /** Core presentation mods writable out of the box (background_* by prefix). */
    private const CORE_ALLOWLIST = [
        'custom_logo',
        'header_textcolor',
        'header_image',
    ];

    /** Theme slug => framework name, for get-theme-context framework detection. */
    private const FRAMEWORKS = [
        'astra'           => 'astra',
        'kadence'         => 'kadence',
        'generatepress'   => 'generatepress',
        'oceanwp'         => 'oceanwp',
        'blocksy'         => 'blocksy',
        'neve'            => 'neve',
        'hello-elementor' => 'hello-elementor',
    ];

    /** Core theme_supports features probed by get-theme-context. */
    private const PROBED_SUPPORTS = [
        'custom-logo',
        'custom-header',
        'custom-background',
        'post-thumbnails',
        'editor-styles',
        'wp-block-styles',
        'align-wide',
        'menus',
        'widgets',
        'title-tag',
        'html5',
    ];

    public function integration(): string
    {
        return 'theme';
    }

    /** The active theme always exists; availability is never in question. */
    public function is_available(): bool
    {
        return true;
    }

    public function capability(): string
    {
        return 'edit_theme_options';
    }

    protected function summary(): string
    {
        return 'the active theme (context, theme supports, and theme-mod presentation settings)';
    }

    protected function operations(): array
    {
        return [
            'get-theme-context' => [
                'mode'         => 'read',
                'description'  => 'Report the active theme context: stylesheet/template, parent theme, child-theme status, detected framework, block-theme support, probed theme_supports, and registered menu locations',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [],
                ],
                'handler'      => fn (array $args) => $this->theme_context(),
            ],
            'get-mods'          => [
                'mode'         => 'read',
                'description'  => 'Read every theme_mod value for the active theme, plus a writable annotation of the keys set-mods would accept',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [],
                ],
                'handler'      => fn (array $args) => $this->mods(),
            ],
            'set-mods'          => [
                'mode'               => 'write',
                'description'        => 'Set allowlisted presentation theme_mod values (core logo/header/background mods; extend via the wpmcp_theme_mod_allowlist filter). Structural keys are always refused. Snapshotted on the theme_mods option; restorable with rollback-operation. Disabled by default (site opts in via the wpmcp_enable_theme_write filter)',
                'enabled_by_default' => (bool) apply_filters('wpmcp_enable_theme_write', false),
                'input_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'values' => [ 'type' => 'object', 'minProperties' => 1 ],
                    ],
                    'required'   => [ 'values' ],
                ],
                'handler'            => fn (array $args) => $this->set_mods((array) $args['values']),
                'snapshot'           => fn (array $args) => [
                    'object_type' => 'option',
                    'object_id'   => 'theme_mods_' . get_stylesheet(),
                ],
            ],
        ];
    }

    private function theme_context(): array
    {
        $theme    = wp_get_theme();
        $parent   = $theme->parent();
        $is_child = false !== $parent && null !== $parent;

        $supports = [];
        foreach (self::PROBED_SUPPORTS as $feature) {
            $supports[ $feature ] = current_theme_supports($feature);
        }

        return [
            'stylesheet'        => get_stylesheet(),
            'template'          => get_template(),
            'name'              => $theme->get('Name'),
            'version'           => $theme->get('Version'),
            'is_child'          => $is_child,
            'parent'            => $is_child ? [
                'stylesheet' => $parent->get_stylesheet(),
                'name'       => $parent->get('Name'),
                'version'    => $parent->get('Version'),
            ] : null,
            'framework'         => $this->detect_framework(),
            'is_block_theme'    => wp_is_block_theme(),
            'theme_supports'    => $supports,
            'menu_locations'    => get_registered_nav_menus(),
            'child_theme_exists' => $this->child_theme_exists(),
        ];
    }

    /** Match the template (parent) slug against the known-framework map. */
    private function detect_framework(): ?string
    {
        $template = get_template();
        if (isset(self::FRAMEWORKS[ $template ])) {
            return self::FRAMEWORKS[ $template ];
        }
        if (0 === strpos($template, 'twenty')) {
            return 'core';
        }
        return null;
    }

    /**
     * Whether a child of the active parent theme is already installed
     * (informs phase 2's create-child-theme without doing any file I/O here).
     */
    private function child_theme_exists(): bool
    {
        if (get_stylesheet() !== get_template()) {
            return true; // The active theme IS a child theme.
        }
        $template = get_template();
        foreach (wp_get_themes() as $theme) {
            if ($theme->get_template() === $template && $theme->get_stylesheet() !== $template) {
                return true;
            }
        }
        return false;
    }

    private function mods(): array
    {
        $mods = get_theme_mods();
        $mods = is_array($mods) ? $mods : [];

        $writable = [];
        foreach (array_keys($mods) as $key) {
            if ($this->is_writable_key((string) $key)) {
                $writable[] = (string) $key;
            }
        }

        return [
            'stylesheet' => get_stylesheet(),
            'mods'       => $mods,
            'writable'   => $writable,
            'allowlist'  => $this->allowlist(),
        ];
    }

    /**
     * Apply each allowlisted key, refuse everything else with a structured
     * per-key report. Runs inside Safe_Mutation (the snapshot of
     * theme_mods_{stylesheet} is taken first), so even a partially applied
     * batch is restorable as one unit via rollback-operation.
     */
    private function set_mods(array $values): array
    {
        $updated = [];
        $refused = [];

        foreach ($values as $key => $value) {
            $key = (string) $key;
            if (in_array($key, self::STRUCTURAL_KEYS, true)) {
                $refused[] = [
                    'key'    => $key,
                    'reason' => 'structural',
                    'detail' => 'Structural theme mods are never writable through set-mods.',
                ];
                continue;
            }
            if (! $this->is_writable_key($key)) {
                $refused[] = [
                    'key'    => $key,
                    'reason' => 'not_allowlisted',
                    'detail' => 'Key is not in the theme-mod allowlist. Extend it with the wpmcp_theme_mod_allowlist filter.',
                ];
                continue;
            }
            set_theme_mod($key, $value);
            $updated[] = $key;
        }

        return [
            'stylesheet' => get_stylesheet(),
            'updated'    => $updated,
            'refused'    => $refused,
        ];
    }

    /** The effective allowlist: core presentation mods extended by filter. */
    private function allowlist(): array
    {
        $allowlist = (array) apply_filters('wpmcp_theme_mod_allowlist', self::CORE_ALLOWLIST);
        return array_values(array_unique(array_map('strval', $allowlist)));
    }

    /**
     * Whether set-mods would accept this key: structural keys never,
     * background_* always (core presentation prefix), otherwise membership
     * in the filtered allowlist.
     */
    private function is_writable_key(string $key): bool
    {
        if (in_array($key, self::STRUCTURAL_KEYS, true)) {
            return false;
        }
        if (0 === strpos($key, 'background_')) {
            return true;
        }
        return in_array($key, $this->allowlist(), true);
    }
}
