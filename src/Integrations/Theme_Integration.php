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
 * This class does no file I/O itself. Issue #69 adds create-child-theme
 * (Child_Theme_Scaffolder, snapshot-first file writes) and the framework
 * settings packs (Theme_Framework_Pack) as further ops on this same pair; see
 * extension_operations().
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
     * set-mods even when a filter adds them to the allowlist. Each entry lists
     * the abilities that own that structure plus a wp-admin fallback: the
     * refusal names only the abilities actually registered on this site, so
     * it never points an agent at a tool this build does not expose.
     */
    private const STRUCTURAL_KEYS = [
        'nav_menu_locations' => [
            'abilities' => [ 'wpmcp/assign-menu-to-location' ],
            'fallback'  => 'Assign menu locations under Appearance > Menus.',
        ],
        'sidebars_widgets'   => [
            'abilities' => [ 'wpmcp/list-sidebar-widgets', 'wpmcp/create-sidebar-widget', 'wpmcp/move-sidebar-widget' ],
            'fallback'  => 'Place and update widgets on the Widgets screen.',
        ],
        'custom_css_post_id' => [
            'abilities' => [ 'wpmcp/get-custom-css', 'wpmcp/add-custom-css' ],
            'fallback'  => 'Edit Additional CSS in the Customizer.',
        ],
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
        'header_image'          => 'header_image_url',
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

    /** Framework theme slugs; the detected framework is the slug itself. */
    private const FRAMEWORKS = [
        'astra',
        'kadence',
        'generatepress',
        'oceanwp',
        'blocksy',
        'neve',
        'hello-elementor',
    ];

    /** Bundled core theme slugs, reported as the 'core' framework. */
    private const CORE_THEMES = [
        'twentyten',
        'twentyeleven',
        'twentytwelve',
        'twentythirteen',
        'twentyfourteen',
        'twentyfifteen',
        'twentysixteen',
        'twentyseventeen',
        'twentynineteen',
        'twentytwenty',
        'twentytwentyone',
        'twentytwentytwo',
        'twentytwentythree',
        'twentytwentyfour',
        'twentytwentyfive',
        'twentytwentysix',
    ];

    /** Mask written in place of a secret-shaped theme-mod value. */
    private const REDACTED = '[redacted]';

    /** Key tokens that mark a value as a secret on their own. */
    private const SECRET_TOKENS = [ 'secret', 'secrets', 'token', 'password', 'passwd', 'apikey', 'credential', 'credentials' ];

    /** Token pairs (first, second) that mark a secret, e.g. api_key, license_key. */
    private const SECRET_PAIRS = [
        'key'  => [ 'api', 'license', 'licence', 'private', 'secret', 'access', 'client', 'auth', 'purchase' ],
        'code' => [ 'license', 'licence', 'purchase' ],
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

    /**
     * Per-call verdicts shared between snapshot_target() and set_mods(), as
     * ['values' => the args they were computed for, 'verdicts' => plan_for()].
     * Reset by set_mods() as soon as it is consumed.
     *
     * @var array{values: array, verdicts: array<string, array<string, mixed>>}|null
     */
    private ?array $plan = null;

    /** @var array<string, bool> template => whether a child of it is installed, per request. */
    private array $child_exists = [];

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
        return 'the active theme (context, supports, theme mods), Redirection, and backup, security, analytics and cache status';
    }

    protected function operations(): array
    {
        return array_merge($this->core_operations(), $this->extension_operations());
    }

    /**
     * Issue #69 ops layered on the phase 1 pair: create-child-theme
     * (Child_Theme_Scaffolder) and the framework settings pack for the
     * detected theme family (Theme_Framework_Pack), which is present in the
     * catalog only while that family is active. Issue #286 adds the
     * Elementor addon suite packs (Elementor_Addon_Packs): paid-tier widget
     * catalog and module toggle ops that answer addon_suite_inactive while
     * their suite is not loaded.
     * Issue #290 adds the paid-tier dynamic template ops
     * (Dynamic_Template_Ops): source discovery, preview, and create/update of
     * single, archive and search templates on the site parts store.
     * Issue #356 adds the paid-tier FunnelKit funnel reads (FunnelKit_Pack),
     * which answer funnelkit_inactive while FunnelKit is not loaded.
     *
     * Issue #300 adds the Redirection plugin adapter (Redirection_Pack):
     * free redirect and group reads plus snapshotted redirect writes that
     * answer redirection_inactive while the plugin is not loaded. Its second
     * slice adds the operations status adapters (Ops_Status_Packs): read-only
     * UpdraftPlus, Duplicator, Solid Security, MonsterInsights and W3 Total
     * Cache status plus the W3 Total Cache purge, each skipped cleanly while
     * its plugin is not loaded.
     *
     * @return array<string,array<string,mixed>>
     */
    private function extension_operations(): array
    {
        return array_merge(
            [ 'create-child-theme' => Child_Theme_Scaffolder::operation() ],
            Theme_Framework_Pack::operations($this->detect_framework()),
            Ops_Status_Packs::operations(),
            Redirection_Pack::operations(),
            Elementor_Addon_Packs::operations(),
            FunnelKit_Pack::operations(),
            \WPMCP\Tools\ThemeBuilder\Dynamic\Dynamic_Template_Ops::operations()
        );
    }

    /** @return array<string,array<string,mixed>> the phase 1 (#144) context and theme-mod ops. */
    private function core_operations(): array
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
        $this->plan = [ 'values' => $values, 'verdicts' => $this->plan_for($values) ];
        foreach ($this->plan['verdicts'] as $verdict) {
            if ($verdict['ok']) {
                return [
                    'object_type' => 'option',
                    'object_id'   => $this->mods_option(),
                ];
            }
        }
        return null;
    }

    /**
     * One verdict per key, computed exactly once per call. snapshot_target()
     * builds it and set_mods() consumes it, so the snapshot decision and the
     * write can never disagree: a custom validator that is non-deterministic
     * (or counts its calls) cannot refuse while the snapshot is being decided
     * and then accept when the write runs, which would be a write with no
     * undo point.
     *
     * A verdict is the evaluate() shape, plus 'clear' => true for the null
     * clear sentinel and a pre-rendered 'detail' for guard layers 1 and 2.
     *
     * @return array<string, array<string, mixed>>
     */
    private function plan_for(array $values): array
    {
        $plan      = [];
        $allowlist = $this->allowlist();
        $rules     = $this->value_rules();

        foreach ($values as $key => $value) {
            $key = (string) $key;

            if (isset(self::STRUCTURAL_KEYS[ $key ])) {
                $plan[ $key ] = [
                    'ok'     => false,
                    'reason' => 'structural',
                    'detail' => 'Structural theme mods are never writable through set-mods. '
                        . $this->structural_route($key),
                ];
                continue;
            }

            if (! in_array($key, $allowlist, true)) {
                $plan[ $key ] = [
                    'ok'     => false,
                    'reason' => 'not_allowlisted',
                    'detail' => 'Key is not in the theme-mod allowlist reported by get-mods. Extend it with the wpmcp_theme_mod_allowlist filter.',
                ];
                continue;
            }

            // null is the explicit clear sentinel. It skips the validators
            // (there is nothing to validate) and routes through
            // remove_theme_mod(), so an agent that can set custom_logo can
            // also remove it, the way the Customizer allows.
            $plan[ $key ] = null === $value
                ? [ 'ok' => true, 'clear' => true ]
                : $this->evaluate($key, $value, $rules);
        }

        return $plan;
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

    /** Match the template (parent) slug against the known framework and core slugs. */
    private function detect_framework(): ?string
    {
        $template = get_template();
        if (in_array($template, self::FRAMEWORKS, true)) {
            return $template;
        }
        if (in_array($template, self::CORE_THEMES, true)) {
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
        if (isset($this->child_exists[ $template ])) {
            return $this->child_exists[ $template ];
        }

        // wp_get_themes() scans the themes directory; do it at most once per
        // template per request.
        $found = false;
        foreach (wp_get_themes() as $theme) {
            if ($theme->get_template() === $template && $theme->get_stylesheet() !== $template) {
                $found = true;
                break;
            }
        }
        return $this->child_exists[ $template ] = $found;
    }

    /**
     * Theme mods for the active theme, verbatim except for secret-shaped keys.
     *
     * Commercial themes park API keys, tokens and license keys in theme mods,
     * and handing those to a model is a credential leak, not a read. Anything
     * else is returned exactly as stored: no truncation, no depth collapse,
     * and stored objects come back as their fields. Masking is by key TOKEN
     * (see is_secret_key()), not substring, so presentation mods such as
     * post_author_box or meta_keywords are never masked. Every masked path,
     * nested ones included (dot-separated), is listed in `redacted`.
     *
     * `writable` is the effective allowlist (what set-mods accepts), NOT just
     * the stored keys, so an allowlisted key that has never been set still
     * reports as writable. It is empty while set-mods is switched off, which
     * `write_enabled` states explicitly. `writable_present` is the
     * intersection with what is actually stored.
     */
    private function mods(): array
    {
        $mods = get_theme_mods();
        $mods = is_array($mods) ? $mods : [];

        $write_enabled = $this->write_enabled();
        $allowlist     = $write_enabled ? $this->allowlist() : [];
        $present       = [];
        foreach (array_keys($mods) as $key) {
            if (in_array((string) $key, $allowlist, true)) {
                $present[] = (string) $key;
            }
        }

        $redacted = [];
        $masked   = $this->mask($mods, '', $redacted);

        return [
            'stylesheet'       => get_option('stylesheet'),
            'mods'             => $masked,
            'write_enabled'    => $write_enabled,
            'writable'         => $allowlist,
            'writable_present' => $present,
            'redacted'         => $redacted,
        ];
    }

    /**
     * Whether set-mods is switched on, by the same two filters the dispatcher
     * applies (wpmcp_enable_theme_write, then wpmcp_integration_op_enabled).
     * Governance can still deny an individual call on top of this.
     */
    private function write_enabled(): bool
    {
        $default = (bool) apply_filters('wpmcp_enable_theme_write', false);
        return (bool) apply_filters('wpmcp_integration_op_enabled', $default, $this->integration(), 'set-mods');
    }

    /**
     * Copy $value, replacing the value under any secret-shaped key with
     * REDACTED and recording its path. Objects become arrays of their public
     * fields so nothing is collapsed.
     *
     * @param mixed              $value
     * @param array<int, string> $redacted
     * @return mixed
     */
    private function mask($value, string $path, array &$redacted)
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (! is_array($value)) {
            return $value;
        }

        $out = [];
        foreach ($value as $key => $item) {
            $child = '' === $path ? (string) $key : $path . '.' . $key;
            if (is_string($key) && $this->is_secret_key($key)) {
                $out[ $key ] = self::REDACTED;
                $redacted[]  = $child;
                continue;
            }
            $out[ $key ] = $this->mask($item, $child, $redacted);
        }
        return $out;
    }

    /**
     * Whether a key names a secret. The key is split into word tokens
     * (snake_case, kebab-case, dotted and camelCase all split), so
     * "meta_keywords" or "post_author_box" never match, while api_key,
     * apiKey, license_key, client_secret, access_token and password do.
     */
    private function is_secret_key(string $key): bool
    {
        $spaced = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $key);
        $tokens = preg_split('/[^a-z0-9]+/', strtolower($spaced), -1, PREG_SPLIT_NO_EMPTY);
        $tokens = is_array($tokens) ? $tokens : [];

        foreach ($tokens as $i => $token) {
            if (in_array($token, self::SECRET_TOKENS, true)) {
                return true;
            }
            if ($i > 0 && isset(self::SECRET_PAIRS[ $token ]) && in_array($tokens[ $i - 1 ], self::SECRET_PAIRS[ $token ], true)) {
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
        // Consume the plan snapshot_target() built for this very call. A plan
        // left over from a different call (its write aborted before reaching
        // the handler) never matches $values and is discarded.
        $plan       = null !== $this->plan && $this->plan['values'] === $values
            ? $this->plan['verdicts']
            : $this->plan_for($values);
        $this->plan = null;

        $updated     = [];
        $cleared     = [];
        $refused     = [];
        $ineffective = [];
        $block_theme = $this->is_block_theme();

        foreach ($plan as $key => $verdict) {
            $key = (string) $key;

            if (! $verdict['ok']) {
                $refused[] = [
                    'key'    => $key,
                    'reason' => $verdict['reason'],
                    'detail' => $verdict['detail'],
                ];
                continue;
            }

            if (! empty($verdict['clear'])) {
                remove_theme_mod($key);
                if ('header_image' === $key) {
                    remove_theme_mod('header_image_data');
                }
                $cleared[] = $key;
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
     * The supported route for a structural key: the owning abilities that are
     * registered here, then the wp-admin screen that always works.
     */
    private function structural_route(string $key): string
    {
        $route     = self::STRUCTURAL_KEYS[ $key ];
        $available = [];
        if (function_exists('wp_has_ability')) {
            foreach ($route['abilities'] as $name) {
                if (wp_has_ability($name)) {
                    $available[] = $name;
                }
            }
        }

        if ([] === $available) {
            return $route['fallback'];
        }

        return 'Use ' . implode(', ', $available) . ' instead, or: ' . $route['fallback'];
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
     * The caller (plan_for()) has already applied guard layers 1 and 2 and
     * passes the value_rules() map it resolved once for the whole batch.
     *
     * @param array<string, mixed> $rules
     * @return array{ok: bool, value?: mixed, reason?: string, detail?: string}
     */
    private function evaluate(string $key, $value, array $rules): array
    {
        $rule = $rules[ $key ] ?? null;

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
    private function apply_builtin_rule(string $key, string $rule, $value, string $extra = ''): ?array
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
                $bad = $this->refuse($key, 'invalid_value', 'must be the ID of an image attachment that exists on this site (pass null to clear it).');
                if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                    return $bad;
                }
                $id = (int) $value;
                if ($id <= 0) {
                    return $bad;
                }
                $post = get_post($id);
                if (! $post || 'attachment' !== $post->post_type || 0 !== strpos((string) get_post_mime_type($post), 'image/')) {
                    return $bad;
                }
                return [ 'ok' => true, 'value' => $id ];

            case 'header_image_url':
                // Core's header-only sentinels, then the plain image_url rule.
                if ('remove-header' === $value || 'random-default-image' === $value) {
                    return [ 'ok' => true, 'value' => $value ];
                }
                return $this->apply_builtin_rule($key, 'image_url', $value, '"remove-header", "random-default-image", ');

            case 'image_url':
                $bad = $this->refuse($key, 'invalid_value', sprintf('must be an absolute http(s) URL, %san empty string (pass null to clear it).', $extra));
                if (! is_string($value)) {
                    return $bad;
                }
                if ('' === $value) {
                    return [ 'ok' => true, 'value' => $value ];
                }
                // The INPUT must already be an absolute http(s) URL with a
                // host. esc_url_raw() only vets the protocol, passes
                // protocol-relative (//evil.tld/x.png), root-relative (/x.png)
                // and fragment (#x) values through, and prepends http:// to a
                // bare word, so "remove-header" would come back as
                // http://remove-header and pass a check on its output.
                $scheme = wp_parse_url($value, PHP_URL_SCHEME);
                $host   = wp_parse_url($value, PHP_URL_HOST);
                if (! is_string($scheme) || ! in_array(strtolower($scheme), [ 'http', 'https' ], true) || ! is_string($host) || '' === $host) {
                    return $bad;
                }
                $url = esc_url_raw($value, [ 'http', 'https' ]);
                return '' === $url ? $bad : [ 'ok' => true, 'value' => $url ];
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
