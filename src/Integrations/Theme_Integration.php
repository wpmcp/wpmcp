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
 *    built-in rule names (see evaluate()), a LIST of literal allowed
 *    values, or a callable(mixed $value): mixed returning null to refuse. The
 *    core VALUE_RULES entries are forced back on top of whatever the filter
 *    returns, so a pack that replaces the map instead of merging into it can
 *    add rules for its own keys but can never strip the sanitizer off a core
 *    key. Array-callables (['My_Class', 'check']) are NOT a supported rule
 *    shape: every array is an enum, in both the check and the explanation.
 *    Pass a Closure or a function-name string instead.
 *  - wpmcp_theme_is_block_theme (bool): overrides wp_is_block_theme() when
 *    deciding which written mods to report in `ineffective`. Present so a
 *    site (or a test) can correct the block-theme verdict for a theme core
 *    misclassifies; it changes reported output, not what is written.
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
 *  - A per-key validator, which FAILS CLOSED. A key with no registered rule
 *    is refused (reason no_validator), and a string rule that is neither a
 *    built-in name nor a callable is refused (reason unknown_rule). There is
 *    no permissive "looks inert" fallback: guard layer 2 can be widened by a
 *    filter, and a widened key with no validator would otherwise be the one
 *    hole in the whole chain. Core registers these settings in the Customizer
 *    with sanitizers for a reason: header_textcolor is echoed bare by the
 *    header_textcolor() template tag inside a <style> block and
 *    background_color reaches _custom_background_cb() through
 *    maybe_hash_hex_color(), which returns invalid input unchanged. A value
 *    that fails its validator is REFUSED (reason invalid_value), never
 *    coerced, so the agent learns it wrote nothing.
 *
 * Passing null as a value is an explicit CLEAR: the key is routed through
 * remove_theme_mod() and reported in `cleared` rather than `updated`. It is a
 * distinct sentinel from the empty string, which image_url treats as a
 * meaningful stored value.
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
        'sidebars_widgets'   => 'Inspect sidebars with wpmcp/list-sidebar-widgets; placing and updating widgets (wpmcp/add-widget, wpmcp/update-widget) requires the pro tier.',
        'custom_css_post_id' => 'Managing the Additional CSS post (wpmcp/add-custom-css, wpmcp/get-custom-css) requires the pro tier; on the free tier edit Additional CSS in the Customizer.',
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
                'description'        => 'Set allowlisted presentation theme_mod values (core logo/header/background mods; extend via the wpmcp_theme_mod_allowlist filter). Pass null as a value to clear that mod. Structural keys are always refused; a key with no registered validator is refused; a value that fails its validator is refused rather than coerced. Snapshotted on the theme_mods option; restorable with rollback-operation. Disabled by default (site opts in via the wpmcp_enable_theme_write filter)',
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
            $key = (string) $key;
            if (null === $value ? $this->is_writable_key($key) : $this->evaluate($key, $value)['ok']) {
                return [
                    'object_type' => 'option',
                    'object_id'   => $this->mods_option(),
                ];
            }
        }
        return null;
    }

    /**
     * The option set_theme_mod()/get_theme_mods() actually read and write.
     * Core keys them off the UNFILTERED get_option('stylesheet')
     * (wp-includes/theme.php), while get_stylesheet() passes that value
     * through the `stylesheet` filter. On any site that filters `stylesheet`
     * the two diverge, and a snapshot taken against the filtered name would
     * restore a different option than the one the write mutated: rollback
     * would report success having reverted nothing.
     */
    private function mods_option(): string
    {
        return 'theme_mods_' . get_option('stylesheet');
    }

    /** Whether $key survives guard layers 1 and 2 (structural, then allowlist). */
    private function is_writable_key(string $key): bool
    {
        return ! isset(self::STRUCTURAL_KEYS[ $key ]) && in_array($key, $this->allowlist(), true);
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
     * Theme mods for the active theme. Values go through the SHARED
     * Request_Log::redact() primitive rather than a local copy of it:
     * commercial themes park API keys, tokens and license keys in theme mods,
     * and handing those to a model verbatim is a credential leak, not a read.
     * Using the shared helper also means values are truncated and stored
     * objects collapse to '[object]', both of which a hand-rolled masker in
     * this class had drifted away from.
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

        $masked   = Request_Log::redact($mods);
        $redacted = [];
        foreach (array_keys($mods) as $key) {
            if (Request_Log::is_secret_key((string) $key)) {
                $redacted[] = (string) $key;
            }
        }

        return [
            'stylesheet'       => get_option('stylesheet'),
            'mods'             => $masked,
            'writable'         => $allowlist,
            'writable_present' => $present,
            'redacted'         => $redacted,
        ];
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
        $cleared     = [];
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

            // null is the explicit clear sentinel. It skips the validators
            // (there is nothing to validate) and routes through
            // remove_theme_mod(), so an agent that can set custom_logo can
            // also remove it, the way the Customizer allows.
            if (null === $value) {
                remove_theme_mod($key);
                if ('header_image' === $key) {
                    remove_theme_mod('header_image_data');
                }
                $cleared[] = $key;
                continue;
            }

            $verdict = $this->evaluate($key, $value, true);
            if (! $verdict['ok']) {
                $refused[] = [
                    'key'    => $key,
                    'reason' => $verdict['reason'],
                    'detail' => $verdict['detail'],
                ];
                continue;
            }

            set_theme_mod($key, $verdict['value']);
            if ('header_image' === $key) {
                $this->sync_header_image_data($verdict['value']);
            }
            $updated[] = $key;

            if ($block_theme && in_array($key, self::BLOCK_THEME_INERT, true)) {
                $ineffective[] = $key;
            }
        }

        return [
            'stylesheet'  => get_option('stylesheet'),
            'updated'     => $updated,
            'cleared'     => $cleared,
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

    /**
     * key => rule, filterable so framework packs can describe their own keys.
     *
     * The core VALUE_RULES entries are merged back on TOP of whatever the
     * filter returns, mirroring what allowlist() does with STRUCTURAL_KEYS: a
     * pack that returns its own map instead of array_merge-ing into the one
     * it was handed would otherwise silently strip the sanitizers off
     * header_textcolor and background_color, the two keys core echoes
     * unescaped inside a <style> block.
     *
     * @return array<string, mixed>
     */
    private function value_rules(): array
    {
        $filtered = (array) apply_filters('wpmcp_theme_mod_value_rules', self::VALUE_RULES);
        return array_merge($filtered, self::VALUE_RULES);
    }

    /**
     * Decide what would really be stored for $key, as a structured verdict.
     *
     * A verdict is ['ok' => true, 'value' => mixed] or
     * ['ok' => false, 'reason' => string, 'detail' => string]. The wrapper
     * exists so a legitimately falsy stored value (0, '', false) is never
     * confused with a refusal, and so the refusal REASON travels with the
     * decision instead of being re-derived by a second, drift-prone pass over
     * the rule.
     *
     * Dispatch order, with the refusal EXPLANATION produced by the same
     * branch that made the decision, so the two can never disagree (the
     * earlier split between accepted_value() and rule_detail() had already
     * drifted into a fatal on Closure rules and an "Array to string
     * conversion" warning on array rules):
     *   1. array  -> enum of literal allowed values;
     *   2. non-string callable (Closure, invokable object) -> custom validator;
     *   3. built-in rule name;
     *   4. string that is callable -> custom validator by function name;
     *   5. any other string -> unknown_rule;
     *   6. no rule at all -> no_validator.
     *
     * Steps 3 and 4 are in that order deliberately: several built-in names
     * (header_textcolor) collide with real WordPress functions and would
     * otherwise be invoked as callables.
     *
     * @return array{ok: bool, value?: mixed, reason?: string, detail?: string}
     */
    private function evaluate(string $key, $value, bool $allowlist_checked = false): array
    {
        if (! $allowlist_checked && ! $this->is_writable_key($key)) {
            return $this->refuse($key, 'not_allowlisted', 'Key is not in the theme-mod allowlist reported by get-mods.');
        }

        $rules = $this->value_rules();
        $rule  = $rules[ $key ] ?? null;

        if (is_array($rule)) {
            return in_array($value, $rule, true)
                ? [ 'ok' => true, 'value' => $value ]
                : $this->refuse($key, 'invalid_value', sprintf(
                    'accepts only one of %s.',
                    implode(', ', array_map('strval', $rule))
                ));
        }

        if (null !== $rule && ! is_string($rule) && is_callable($rule)) {
            return $this->apply_callable_rule($key, $rule, $value);
        }

        if (is_string($rule)) {
            $built_in = $this->apply_builtin_rule($key, $rule, $value);
            if (null !== $built_in) {
                return $built_in;
            }
            if (is_callable($rule)) {
                return $this->apply_callable_rule($key, $rule, $value);
            }
            return $this->refuse($key, 'unknown_rule', sprintf(
                'is registered with the rule "%s", which is neither a built-in rule name nor a callable. Fix the wpmcp_theme_mod_value_rules entry.',
                $rule
            ));
        }

        // Guard layer 3 fails CLOSED. wpmcp_theme_mod_allowlist can widen
        // guard layer 2, so a widened key with no validator is precisely the
        // case that must not be waved through: these values are echoed by
        // themes without escaping, and no generic "looks inert" sniff is a
        // substitute for knowing what the key means.
        return $this->refuse($key, 'no_validator', 'has no registered validator, so nothing can vouch for the value. Register one with the wpmcp_theme_mod_value_rules filter.');
    }

    /** @return array{ok: false, reason: string, detail: string} */
    private function refuse(string $key, string $reason, string $explanation): array
    {
        return [
            'ok'     => false,
            'reason' => $reason,
            'detail' => sprintf('Value refused: "%s" %s', $key, $explanation),
        ];
    }

    /**
     * Run a custom validator. It refuses by returning null; anything else is
     * the value to store.
     *
     * @param callable $rule
     * @return array{ok: bool, value?: mixed, reason?: string, detail?: string}
     */
    private function apply_callable_rule(string $key, $rule, $value): array
    {
        $out = $rule($value);
        return null === $out
            ? $this->refuse($key, 'invalid_value', 'was refused by the custom validator registered for it through wpmcp_theme_mod_value_rules.')
            : [ 'ok' => true, 'value' => $out ];
    }

    /**
     * The built-in rules, mirroring how core registers these same settings on
     * the Customizer. Returns null when $rule is not a built-in NAME at all,
     * which is what lets evaluate() fall through to the callable and
     * unknown_rule branches instead of silently accepting.
     *
     * @return array{ok: bool, value?: mixed, reason?: string, detail?: string}|null
     */
    private function apply_builtin_rule(string $key, string $rule, $value): ?array
    {
        switch ($rule) {
            case 'hex_no_hash':
                $hex = is_string($value) ? sanitize_hex_color_no_hash($value) : null;
                return null === $hex || '' === $hex
                    ? $this->refuse($key, 'invalid_value', 'must be a hex color such as aabbcc (core stores it without the leading #, and emits it unescaped inside a <style> block).')
                    : [ 'ok' => true, 'value' => $hex ];

            case 'header_textcolor':
                if ('blank' === $value) {
                    return [ 'ok' => true, 'value' => 'blank' ];
                }
                $hex = is_string($value) ? sanitize_hex_color_no_hash($value) : null;
                return null === $hex || '' === $hex
                    ? $this->refuse($key, 'invalid_value', 'must be a hex color such as aabbcc, or the literal "blank".')
                    : [ 'ok' => true, 'value' => $hex ];

            case 'attachment_id':
                $bad = $this->refuse($key, 'invalid_value', 'must be the ID of an attachment that exists on this site (pass null to clear it).');
                if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                    return $bad;
                }
                $id = (int) $value;
                if ($id <= 0) {
                    return $bad;
                }
                $post = get_post($id);
                if (! $post || 'attachment' !== $post->post_type) {
                    return $bad;
                }
                return [ 'ok' => true, 'value' => $id ];

            case 'image_url':
                $bad = $this->refuse($key, 'invalid_value', 'must be an absolute http(s) URL, an empty string, "remove-header", or "random-default-image" (pass null to clear it).');
                if (! is_string($value)) {
                    return $bad;
                }
                if ('' === $value || 'remove-header' === $value || 'random-default-image' === $value) {
                    return [ 'ok' => true, 'value' => $value ];
                }
                $url = esc_url_raw($value, [ 'http', 'https' ]);
                if ('' === $url) {
                    return $bad;
                }
                // esc_url_raw() only vets the PROTOCOL, so it happily returns
                // protocol-relative (//evil.tld/x.png), root-relative (/x.png)
                // and fragment (#x) values. The refusal text promises an
                // http(s) URL; enforce exactly that.
                $scheme = wp_parse_url($url, PHP_URL_SCHEME);
                return in_array($scheme, [ 'http', 'https' ], true)
                    ? [ 'ok' => true, 'value' => $url ]
                    : $bad;
        }

        return null;
    }

    /**
     * Keep header_image_data in step with header_image.
     *
     * Core's Custom_Image_Header always writes the two together, and
     * get_custom_header() / the_header_image_tag() read the companion for
     * attachment_id, width and height. Writing header_image alone leaves them
     * describing the PREVIOUS image against the new URL, so either refresh
     * the companion from the attachment the URL resolves to, or drop it.
     */
    private function sync_header_image_data(string $url): void
    {
        if ('' === $url || 'remove-header' === $url || 'random-default-image' === $url) {
            remove_theme_mod('header_image_data');
            return;
        }

        $id = attachment_url_to_postid($url);
        if ($id <= 0) {
            remove_theme_mod('header_image_data');
            return;
        }

        $meta = wp_get_attachment_metadata($id);
        set_theme_mod('header_image_data', (object) [
            'attachment_id' => $id,
            'url'           => $url,
            'thumbnail_url' => $url,
            'width'         => (int) ($meta['width'] ?? 0),
            'height'        => (int) ($meta['height'] ?? 0),
        ]);
    }
}
