<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Request_Log;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Phase 1 of the theme workflow (#144, parent #69): theme context reads plus
 * reversible, allowlist-gated theme-mod writes behind a single
 * wpmcp/theme-read + wpmcp/theme-write dispatcher pair.
 *
 * No file I/O happens in this phase. create-child-theme (phase 2) and the
 * framework packs (phases 3-4) hook the wpmcp_theme_mod_allowlist and
 * wpmcp_theme_mod_value_rules seams later to extend what set-mods may touch;
 * nothing here needs to change for that.
 *
 * The active theme is always present, so is_available() is always true and
 * the pair never reports integration_unavailable. Both halves demand
 * edit_theme_options, matching what the Customizer itself requires.
 *
 * Write posture mirrors the ACF reference integration: set-mods is
 * default-off behind the wpmcp_enable_theme_write filter, and every accepted
 * write is snapshotted on the theme_mods_{stylesheet} option so
 * rollback-operation restores the exact prior state. A batch in which no key
 * survives the guards writes nothing and takes no snapshot, so repeated
 * fully-refused calls cannot evict real undo points through the global
 * keep-newest-N prune.
 *
 * Filter contracts (the durable part of this class, relied on by later phases):
 *  - wpmcp_enable_theme_write (bool): opt the whole set-mods operation in.
 *    Default false.
 *  - wpmcp_theme_mod_allowlist (string[]): the effective set of writable mod
 *    keys, seeded with CORE_ALLOWLIST. It both widens and narrows: returning
 *    [] closes set-mods entirely. STRUCTURAL_KEYS are stripped from whatever
 *    it returns, so it can never open them.
 *  - wpmcp_theme_mod_value_rules (array<string, mixed>): key => validator for
 *    filter-added keys, seeded with VALUE_RULES. A rule is either one of the
 *    built-in rule names (see validate_value()), a list of literal allowed
 *    values, or a callable(mixed $value): mixed returning null to refuse.
 *
 * Three guard layers on a set-mods key, in order:
 *  - STRUCTURAL_KEYS is a hard refusal evaluated BEFORE the allowlist filter,
 *    so a filter can never open nav_menu_locations, sidebars_widgets, or
 *    custom_css_post_id: those rewire site structure rather than
 *    presentation, and a bad write there is not "wrong colors" but broken
 *    navigation or orphaned CSS posts. The refusal names the supported tool
 *    for each one instead of dead-ending the agent.
 *  - The presentation allowlist (core logo/header/background mods), which is
 *    exactly what get-mods advertises as `allowlist`/`writable`. There is no
 *    hidden prefix rule: every writable key is enumerated.
 *  - A per-key validator. Core registers these settings in the Customizer
 *    with sanitizers for a reason: header_textcolor is echoed bare by the
 *    header_textcolor() template tag inside a <style> block and
 *    background_color reaches _custom_background_cb() through
 *    maybe_hash_hex_color(), which returns invalid input unchanged. A value
 *    that fails its validator is REFUSED (reason invalid_value), never
 *    coerced, so the agent learns it wrote nothing.
 */
class Theme_Integration extends Integration_Dispatcher
{
    /**
     * Mods that rewire structure rather than presentation. Hard-refused in
     * set-mods even when a filter adds them to the allowlist, with the
     * supported route named per key.
     */
    private const STRUCTURAL_KEYS = [
        'nav_menu_locations' => 'Assign menu locations with wpmcp/assign-menu-to-location instead.',
        'sidebars_widgets'   => 'Place widgets with wpmcp/add-widget, wpmcp/update-widget, and wpmcp/list-sidebar-widgets instead.',
        'custom_css_post_id' => 'Manage the Additional CSS post with wpmcp/add-custom-css and wpmcp/get-custom-css instead.',
    ];

    /**
     * Core presentation mods writable out of the box. Enumerated in full:
     * every key set-mods accepts is listed here or added by the
     * wpmcp_theme_mod_allowlist filter, so what get-mods advertises is the
     * real write policy.
     */
    private const CORE_ALLOWLIST = [
        'custom_logo',
        'header_textcolor',
        'header_image',
        'background_image',
        'background_color',
        'background_preset',
        'background_position_x',
        'background_position_y',
        'background_size',
        'background_repeat',
        'background_attachment',
    ];

    /**
     * Per-key validators, mirroring how core registers these same settings on
     * the Customizer (class-wp-customize-manager.php).
     */
    private const VALUE_RULES = [
        'custom_logo'           => 'attachment_id',
        'header_textcolor'      => 'header_textcolor',
        'header_image'          => 'image_url',
        'background_image'      => 'image_url',
        'background_color'      => 'hex_no_hash',
        'background_preset'     => [ 'default', 'fill', 'fit', 'repeat', 'custom' ],
        'background_position_x' => [ 'left', 'center', 'right' ],
        'background_position_y' => [ 'top', 'center', 'bottom' ],
        'background_size'       => [ 'auto', 'contain', 'cover' ],
        'background_repeat'     => [ 'repeat', 'no-repeat' ],
        'background_attachment' => [ 'fixed', 'scroll' ],
    ];

    /**
     * Mods a block theme renders from global styles instead, so writing them
     * stores a value the front end never uses. Reported in `ineffective`
     * rather than refused: the value is still legal, it just will not show.
     */
    private const BLOCK_THEME_INERT = [
        'header_textcolor',
        'header_image',
        'background_image',
        'background_color',
        'background_preset',
        'background_position_x',
        'background_position_y',
        'background_size',
        'background_repeat',
        'background_attachment',
    ];

    /** Upper bound on one set-mods batch, so a call cannot bloat the autoloaded option. */
    private const MAX_VALUES = 50;

    /** Key fragments whose theme_mod values are masked on the read half. */
    private const SECRET_KEY_PARTS = [
        'pass',
        'secret',
        'token',
        'key',
        'auth',
        'nonce',
        'credential',
        'cookie',
        'signature',
        'licen',
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
                'description'  => 'Read every theme_mod value for the active theme (secret-looking values masked), plus the effective allowlist of keys set-mods would accept',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [],
                ],
                'handler'      => fn (array $args) => $this->mods(),
            ],
            'set-mods'          => [
                'mode'               => 'write',
                'description'        => 'Set allowlisted presentation theme_mod values (core logo/header/background mods; extend via the wpmcp_theme_mod_allowlist filter). Structural keys are always refused, and a value that fails its per-key validator is refused rather than coerced. Snapshotted on the theme_mods option; restorable with rollback-operation. Disabled by default (site opts in via the wpmcp_enable_theme_write filter)',
                'enabled_by_default' => (bool) apply_filters('wpmcp_enable_theme_write', false),
                'input_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'values' => [
                            'type'          => 'object',
                            'minProperties' => 1,
                            'maxProperties' => self::MAX_VALUES,
                        ],
                    ],
                    'required'   => [ 'values' ],
                ],
                'handler'            => fn (array $args) => $this->set_mods((array) $args['values']),
                'snapshot'           => fn (array $args) => $this->snapshot_target((array) ($args['values'] ?? [])),
            ],
        ];
    }

    /**
     * Name the snapshot target only when at least one key would really be
     * written. A batch where everything is refused changes nothing, so it
     * must not persist a snapshot row: Safe_Mutation prunes to the tier's
     * global keep-newest-N after every write, and no-op rows would silently
     * evict genuine undo points. Returning null makes the dispatcher run the
     * handler directly and report recoverable:false, which is the honest
     * answer for a call that wrote nothing.
     */
    private function snapshot_target(array $values): ?array
    {
        foreach ($values as $key => $value) {
            if (null !== $this->accepted_value((string) $key, $value)) {
                return [
                    'object_type' => 'option',
                    'object_id'   => 'theme_mods_' . get_stylesheet(),
                ];
            }
        }
        return null;
    }

    private function theme_context(): array
    {
        $theme  = wp_get_theme();
        $parent = $theme->parent();

        // A child theme is one whose stylesheet differs from its template,
        // exactly as child_theme_exists() tests it. WP_Theme::parent()
        // returns false when the parent is not installed, so it says whether
        // the parent RESOLVES, not whether this is a child.
        $is_child        = get_stylesheet() !== get_template();
        $parent_resolved = $parent instanceof \WP_Theme;

        $supports = [];
        foreach (self::PROBED_SUPPORTS as $feature) {
            $supports[ $feature ] = current_theme_supports($feature);
        }

        return [
            'stylesheet'         => get_stylesheet(),
            'template'           => get_template(),
            'name'               => $theme->get('Name'),
            'version'            => $theme->get('Version'),
            'is_child'           => $is_child,
            'parent'             => $parent_resolved ? [
                'stylesheet' => $parent->get_stylesheet(),
                'name'       => $parent->get('Name'),
                'version'    => $parent->get('Version'),
            ] : null,
            'parent_missing'     => $is_child && ! $parent_resolved,
            'framework'          => $this->detect_framework(),
            'is_block_theme'     => $this->is_block_theme(),
            'theme_supports'     => $supports,
            'menu_locations'     => get_registered_nav_menus(),
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

    /** wp_is_block_theme() with a test/override seam. */
    private function is_block_theme(): bool
    {
        return (bool) apply_filters('wpmcp_theme_is_block_theme', wp_is_block_theme());
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

    /**
     * Theme mods for the active theme. Values are masked by key the way
     * Request_Log::redact() masks captured payloads: commercial themes park
     * API keys, tokens and license keys in theme mods, and handing those to a
     * model verbatim is a credential leak, not a read.
     *
     * `writable` is the effective allowlist (what set-mods accepts), NOT just
     * the stored keys, so an allowlisted key that has never been set still
     * reports as writable. `writable_present` is the intersection with what
     * is actually stored.
     */
    private function mods(): array
    {
        $mods = get_theme_mods();
        $mods = is_array($mods) ? $mods : [];

        $allowlist = $this->allowlist();
        $present   = [];
        foreach (array_keys($mods) as $key) {
            if (in_array((string) $key, $allowlist, true)) {
                $present[] = (string) $key;
            }
        }

        $redacted = [];
        $masked   = $this->redact($mods, $redacted);

        return [
            'stylesheet'       => get_stylesheet(),
            'mods'             => $masked,
            'writable'         => $allowlist,
            'writable_present' => $present,
            'allowlist'        => $allowlist,
            'redacted'         => $redacted,
        ];
    }

    /**
     * Recursive key-based masking, same convention and mask string as
     * Request_Log::redact(). Structure is preserved so the agent still sees
     * the shape of a theme's settings, just not its secrets.
     *
     * @param array<mixed>      $values
     * @param array<int,string> $redacted Collects the top-level masked keys.
     * @return array<mixed>
     */
    private function redact(array $values, array &$redacted, int $depth = 0): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if ($this->is_secret_key((string) $key)) {
                $out[ $key ] = Request_Log::REDACTED;
                if (0 === $depth) {
                    $redacted[] = (string) $key;
                }
                continue;
            }
            if (is_array($value)) {
                $out[ $key ] = $depth >= 4 ? '[array]' : $this->redact($value, $redacted, $depth + 1);
                continue;
            }
            $out[ $key ] = $value;
        }
        return $out;
    }

    private function is_secret_key(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::SECRET_KEY_PARTS as $part) {
            if (false !== strpos($key, $part)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Apply each allowlisted key whose value passes its validator, refuse
     * everything else with a structured per-key report. Runs inside
     * Safe_Mutation whenever at least one key is really written (the
     * snapshot of theme_mods_{stylesheet} is taken first), so even a
     * partially applied batch is restorable as one unit via
     * rollback-operation.
     */
    private function set_mods(array $values): array
    {
        $updated     = [];
        $refused     = [];
        $ineffective = [];
        $allowlist   = $this->allowlist();
        $block_theme = $this->is_block_theme();

        foreach ($values as $key => $value) {
            $key = (string) $key;

            if (isset(self::STRUCTURAL_KEYS[ $key ])) {
                $refused[] = [
                    'key'    => $key,
                    'reason' => 'structural',
                    'detail' => 'Structural theme mods are never writable through set-mods. '
                        . self::STRUCTURAL_KEYS[ $key ],
                ];
                continue;
            }

            if (! in_array($key, $allowlist, true)) {
                $refused[] = [
                    'key'    => $key,
                    'reason' => 'not_allowlisted',
                    'detail' => 'Key is not in the theme-mod allowlist reported by get-mods. Extend it with the wpmcp_theme_mod_allowlist filter.',
                ];
                continue;
            }

            $accepted = $this->accepted_value($key, $value, true);
            if (null === $accepted) {
                $refused[] = [
                    'key'    => $key,
                    'reason' => 'invalid_value',
                    'detail' => $this->rule_detail($key),
                ];
                continue;
            }

            set_theme_mod($key, $accepted['value']);
            $updated[] = $key;

            if ($block_theme && in_array($key, self::BLOCK_THEME_INERT, true)) {
                $ineffective[] = $key;
            }
        }

        return [
            'stylesheet'  => get_stylesheet(),
            'updated'     => $updated,
            'refused'     => $refused,
            'ineffective' => $ineffective,
            'notes'       => $ineffective
                ? 'This is a block theme: the listed mods were stored but the front end renders those settings from global styles, so they will have no visible effect.'
                : '',
        ];
    }

    /**
     * The effective allowlist: core presentation mods, extended or narrowed
     * by the wpmcp_theme_mod_allowlist filter, minus the structural keys the
     * filter is never allowed to open. This is the single source of truth for
     * what set-mods accepts and it is exactly what get-mods advertises.
     *
     * @return array<int, string>
     */
    private function allowlist(): array
    {
        $allowlist = (array) apply_filters('wpmcp_theme_mod_allowlist', self::CORE_ALLOWLIST);
        $allowlist = array_map('strval', $allowlist);
        $allowlist = array_filter($allowlist, static fn (string $key): bool => ! isset(self::STRUCTURAL_KEYS[ $key ]));
        return array_values(array_unique($allowlist));
    }

    /** @return array<string, mixed> key => rule, filterable for framework packs. */
    private function value_rules(): array
    {
        return (array) apply_filters('wpmcp_theme_mod_value_rules', self::VALUE_RULES);
    }

    /**
     * The value that would actually be stored for $key, or null when the key
     * is not writable or the value fails its validator. Returns a one-element
     * wrapper ['value' => ...] so a legitimately falsy stored value (0, '',
     * false) is not confused with a refusal.
     *
     * @return array{value: mixed}|null
     */
    private function accepted_value(string $key, $value, bool $allowlist_checked = false): ?array
    {
        if (! $allowlist_checked) {
            if (isset(self::STRUCTURAL_KEYS[ $key ]) || ! in_array($key, $this->allowlist(), true)) {
                return null;
            }
        }

        $rules = $this->value_rules();
        $rule  = $rules[ $key ] ?? null;

        // Order matters: a rule NAME is matched before callables, because
        // several of the built-in names (header_textcolor) collide with real
        // WordPress functions and would otherwise be invoked as callables.
        if (is_array($rule)) {
            return in_array($value, $rule, true) ? [ 'value' => $value ] : null;
        }

        if (null !== $rule && ! is_string($rule) && is_callable($rule)) {
            $out = $rule($value);
            return null === $out ? null : [ 'value' => $out ];
        }

        switch ((string) $rule) {
            case 'hex_no_hash':
                if (! is_string($value)) {
                    return null;
                }
                $hex = sanitize_hex_color_no_hash($value);
                return null === $hex || '' === $hex ? null : [ 'value' => $hex ];

            case 'header_textcolor':
                if (! is_string($value)) {
                    return null;
                }
                if ('blank' === $value) {
                    return [ 'value' => 'blank' ];
                }
                $hex = sanitize_hex_color_no_hash($value);
                return null === $hex || '' === $hex ? null : [ 'value' => $hex ];

            case 'attachment_id':
                if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                    return null;
                }
                $id = (int) $value;
                if ($id <= 0) {
                    return null;
                }
                $post = get_post($id);
                if (! $post || 'attachment' !== $post->post_type) {
                    return null;
                }
                return [ 'value' => $id ];

            case 'image_url':
                if (! is_string($value)) {
                    return null;
                }
                if ('' === $value || 'remove-header' === $value || 'random-default-image' === $value) {
                    return [ 'value' => $value ];
                }
                $url = esc_url_raw($value, [ 'http', 'https' ]);
                return '' === $url ? null : [ 'value' => $url ];
        }

        // Filter-added key with no registered rule: accept only inert data.
        // Anything carrying markup is refused rather than silently stripped,
        // because these values are echoed by themes without escaping.
        return $this->is_inert_value($value) ? [ 'value' => $value ] : null;
    }

    /** Scalars (and flat arrays of them) free of markup and control characters. */
    private function is_inert_value($value, int $depth = 0): bool
    {
        if (is_array($value)) {
            if ($depth >= 2) {
                return false;
            }
            foreach ($value as $item) {
                if (! $this->is_inert_value($item, $depth + 1)) {
                    return false;
                }
            }
            return true;
        }
        if (is_bool($value) || is_int($value) || is_float($value) || null === $value) {
            return true;
        }
        if (! is_string($value)) {
            return false;
        }
        return $value === wp_strip_all_tags($value)
            && false === strpos($value, '<')
            && false === strpos($value, '>')
            && ! preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value);
    }

    /** Agent-facing explanation of what a key's validator wanted. */
    private function rule_detail(string $key): string
    {
        $rule = $this->value_rules()[ $key ] ?? null;

        if (is_array($rule) && ! is_callable($rule)) {
            return sprintf(
                'Value refused: "%s" accepts only one of %s.',
                $key,
                implode(', ', array_map('strval', $rule))
            );
        }

        switch ((string) $rule) {
            case 'hex_no_hash':
                return sprintf('Value refused: "%s" must be a hex color such as aabbcc (core stores it without the leading #, and emits it unescaped inside a <style> block).', $key);
            case 'header_textcolor':
                return sprintf('Value refused: "%s" must be a hex color such as aabbcc, or the literal "blank".', $key);
            case 'attachment_id':
                return sprintf('Value refused: "%s" must be the ID of an attachment that exists on this site.', $key);
            case 'image_url':
                return sprintf('Value refused: "%s" must be an http(s) URL, an empty string, "remove-header", or "random-default-image".', $key);
        }

        return sprintf('Value refused: "%s" has no registered validator, so it accepts only markup-free scalars (or a flat array of them). Register a validator with the wpmcp_theme_mod_value_rules filter.', $key);
    }
}
