<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Theme integration (wpmcp/theme-read + theme-write), issue #69. First slice
 * of the theme toolset: context detection (framework family, parent/child,
 * block-theme flag, registered menus and supports), allowlisted theme-mod
 * writes snapshotted on the theme_mods_{stylesheet} option (restorable via
 * rollback-operation), and a confirm-gated, idempotent child-theme scaffolder
 * that refuses to create a grandchild.
 *
 * Always available: WordPress always has an active theme, so is_available()
 * is unconditionally true (unlike host-plugin integrations).
 *
 * Framework settings packs (issue #69, third bullet) are not implemented in
 * this slice; see framework_pack_operations() and docs/wip/issue-69.md.
 */
class Theme_Integration extends Integration_Dispatcher
{
    /**
     * Parent-theme slugs (and slug prefixes) mapped to a framework family
     * label for get-context. Extend via the wpmcp_theme_framework_map filter.
     *
     * @var array<string,string>
     */
    private const FRAMEWORK_MAP = [
        'genesis'        => 'genesis',
        'astra'          => 'astra',
        'generatepress'  => 'generatepress',
        'oceanwp'        => 'oceanwp',
        'kadence'        => 'kadence',
        'blocksy'        => 'blocksy',
        'divi'           => 'divi',
        'avada'          => 'avada',
        'hello-elementor' => 'hello-elementor',
        'neve'           => 'neve',
        'twentytwentyfive' => 'core-default',
        'twentytwentyfour' => 'core-default',
    ];

    /**
     * Theme-mod keys the write op may touch. Presentation-level knobs only;
     * anything structural (menu assignments, widget areas, custom CSS post
     * linkage) is refused even if a caller asks nicely. Filterable via
     * wpmcp_theme_mod_allowlist so a site can widen or narrow it.
     *
     * @var array<int,string>
     */
    private const MOD_ALLOWLIST = [
        'custom_logo',
        'header_textcolor',
        'header_text',
        'background_color',
        'background_image',
        'background_preset',
        'background_position_x',
        'background_position_y',
        'background_size',
        'background_repeat',
        'background_attachment',
        'header_image',
        'site_icon',
    ];

    /**
     * Structural keys refused outright with a dedicated error so callers see
     * "refused", not "not on the allowlist" (they must never be filterable
     * onto the allowlist either).
     *
     * @var array<int,string>
     */
    private const MOD_STRUCTURAL = [
        'nav_menu_locations',
        'sidebars_widgets',
        'custom_css_post_id',
    ];

    public function integration(): string
    {
        return 'theme';
    }

    public function is_available(): bool
    {
        return true;
    }

    protected function summary(): string
    {
        return 'Active theme (context detection, allowlisted theme mods, child-theme scaffolding)';
    }

    protected function operations(): array
    {
        return array_merge(
            [
                'get-context'        => $this->op_get_context(),
                'list-theme-mods'    => $this->op_list_theme_mods(),
                'set-theme-mods'     => $this->op_set_theme_mods(),
                'create-child-theme' => $this->op_create_child_theme(),
            ],
            $this->framework_pack_operations()
        );
    }

    /**
     * Curated framework settings packs (issue #69, third bullet). Each pack
     * registers extra ops only when its theme family is active, and refreshes
     * the framework's CSS cache after writes.
     *
     * TODO(#69): implement the first pack (family chosen by install-base
     * data; Astra is the leading candidate) as the template. Until then this
     * contributes nothing to the catalog.
     *
     * @return array<string,array<string,mixed>>
     */
    protected function framework_pack_operations(): array
    {
        return [];
    }

    /** @return array<string,mixed> */
    private function op_get_context(): array
    {
        return [
            'mode'         => 'read',
            'description'  => 'Report the active theme context: name, version, framework family, parent/child relationship, block-theme flag, registered nav menus, and declared theme supports',
            'input_schema' => [ 'type' => 'object', 'properties' => [] ],
            'handler'      => function (): array {
                $theme  = wp_get_theme();
                $parent = $theme->parent();

                $supports = [];
                foreach ([ 'custom-logo', 'custom-header', 'custom-background', 'post-thumbnails', 'editor-styles', 'wp-block-styles', 'align-wide', 'title-tag', 'html5', 'automatic-feed-links' ] as $feature) {
                    if (current_theme_supports($feature)) {
                        $supports[] = $feature;
                    }
                }

                return [
                    'stylesheet'      => get_stylesheet(),
                    'template'        => get_template(),
                    'name'            => $theme->get('Name'),
                    'version'         => $theme->get('Version'),
                    'is_child_theme'  => is_child_theme(),
                    'parent'          => $parent ? [
                        'stylesheet' => $parent->get_stylesheet(),
                        'name'       => $parent->get('Name'),
                        'version'    => $parent->get('Version'),
                    ] : null,
                    'is_block_theme'  => function_exists('wp_is_block_theme') && wp_is_block_theme(),
                    'framework'       => self::detect_framework(),
                    'nav_menus'       => (array) get_registered_nav_menus(),
                    'supports'        => $supports,
                ];
            },
        ];
    }

    /** @return array<string,mixed> */
    private function op_list_theme_mods(): array
    {
        return [
            'mode'         => 'read',
            'description'  => 'List the active theme\'s theme mods with per-key writability: allowlisted (writable via set-theme-mods), structural (always refused), or other (not writable through this tool)',
            'input_schema' => [ 'type' => 'object', 'properties' => [] ],
            'handler'      => function (): array {
                $allowlist = self::allowlist();
                $mods      = [];
                foreach ((array) get_theme_mods() as $key => $value) {
                    $key    = (string) $key;
                    $mods[] = [
                        'key'      => $key,
                        'value'    => $value,
                        'writable' => in_array($key, $allowlist, true) ? 'allowlisted'
                            : (in_array($key, self::MOD_STRUCTURAL, true) ? 'structural' : 'other'),
                    ];
                }
                return [ 'stylesheet' => get_stylesheet(), 'mods' => $mods, 'allowlist' => $allowlist ];
            },
        ];
    }

    /** @return array<string,mixed> */
    private function op_set_theme_mods(): array
    {
        return [
            'mode'         => 'write',
            'description'  => 'Set one or more allowlisted theme mods on the active theme. Structural keys (nav_menu_locations, sidebars_widgets, custom_css_post_id) are refused; any non-allowlisted key rejects the whole call before any write. Snapshotted on the theme_mods option; restorable with rollback-operation',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'mods' => [ 'type' => 'object', 'minProperties' => 1 ],
                ],
                'required'   => [ 'mods' ],
            ],
            'handler'      => function (array $args): array {
                $allowlist = self::allowlist();
                $mods      = (array) $args['mods'];

                // Validate the full batch before writing anything: a single
                // bad key rejects the call with no side effects.
                foreach (array_keys($mods) as $key) {
                    $key = (string) $key;
                    if (in_array($key, self::MOD_STRUCTURAL, true)) {
                        return [ 'error' => [
                            'code'    => 'structural_key_refused',
                            'message' => sprintf('Theme mod "%s" is structural and can never be written through this tool.', $key),
                            'data'    => [ 'key' => $key ],
                        ] ];
                    }
                    if (! in_array($key, $allowlist, true)) {
                        return [ 'error' => [
                            'code'    => 'key_not_allowlisted',
                            'message' => sprintf('Theme mod "%s" is not on the write allowlist.', $key),
                            'data'    => [ 'key' => $key, 'allowlist' => $allowlist ],
                        ] ];
                    }
                }

                foreach ($mods as $key => $value) {
                    set_theme_mod((string) $key, $value);
                }

                $out = [];
                foreach (array_keys($mods) as $key) {
                    $out[(string) $key] = get_theme_mod((string) $key);
                }
                return [ 'stylesheet' => get_stylesheet(), 'mods' => $out ];
            },
            'snapshot'     => fn (array $args) => [
                'object_type' => 'option',
                'object_id'   => 'theme_mods_' . get_stylesheet(),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function op_create_child_theme(): array
    {
        return [
            'mode'         => 'destructive',
            'description'  => 'Scaffold a child theme of the active parent theme (style.css + functions.php enqueueing the parent stylesheet). Idempotent: re-running against an existing wpmcp-scaffolded child reports it instead of failing. Refuses to run when the active theme is already a child theme (no grandchildren). Requires confirm:true. Does not activate the child theme',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'slug' => [ 'type' => 'string', 'description' => 'Directory slug for the child theme; defaults to {parent}-child. Sanitized to a safe key, confined to the themes directory' ],
                    'name' => [ 'type' => 'string', 'description' => 'Human-readable theme Name header; defaults to "{Parent Name} Child"' ],
                ],
            ],
            'handler'      => function (array $args): array {
                if (is_child_theme()) {
                    return [ 'error' => [
                        'code'    => 'grandchild_refused',
                        'message' => 'The active theme is already a child theme; creating a grandchild theme is not supported.',
                        'data'    => [ 'stylesheet' => get_stylesheet(), 'template' => get_template() ],
                    ] ];
                }

                $parent = wp_get_theme(get_template());
                $slug   = sanitize_key((string) ($args['slug'] ?? (get_template() . '-child')));
                if ('' === $slug || $slug === get_template()) {
                    return [ 'error' => [
                        'code'    => 'invalid_slug',
                        'message' => 'Child theme slug is empty or collides with the parent theme slug after sanitization.',
                        'data'    => [ 'slug' => $slug ],
                    ] ];
                }

                // Path confinement: the slug is a single sanitize_key()
                // token (no slashes, no dots), joined onto the theme root.
                $root = get_theme_root();
                $dir  = trailingslashit($root) . $slug;

                $marker = 'Generated by WPMCP create-child-theme';
                if (is_dir($dir)) {
                    $existing = @file_get_contents($dir . '/style.css');
                    if (is_string($existing) && false !== strpos($existing, $marker)) {
                        return [
                            'created'    => false,
                            'existing'   => true,
                            'stylesheet' => $slug,
                            'path'       => $dir,
                        ];
                    }
                    return [ 'error' => [
                        'code'    => 'directory_exists',
                        'message' => sprintf('Theme directory "%s" already exists and was not scaffolded by wpmcp; refusing to touch it.', $slug),
                        'data'    => [ 'slug' => $slug ],
                    ] ];
                }

                $name = trim((string) ($args['name'] ?? '')) !== ''
                    ? (string) $args['name']
                    : $parent->get('Name') . ' Child';

                $style = "/*\n"
                    . 'Theme Name: ' . str_replace(["\r", "\n"], ' ', $name) . "\n"
                    . 'Template: ' . get_template() . "\n"
                    . 'Version: 1.0.0' . "\n"
                    . 'Description: Child theme of ' . str_replace(["\r", "\n"], ' ', (string) $parent->get('Name')) . '. ' . $marker . ".\n"
                    . "*/\n";

                $functions = "<?php\n"
                    . "// " . $marker . ".\n"
                    . "add_action('wp_enqueue_scripts', function () {\n"
                    . "    wp_enqueue_style('wpmcp-child-parent-style', get_template_directory_uri() . '/style.css', [], wp_get_theme(get_template())->get('Version'));\n"
                    . "});\n";

                // TODO(#69): route these writes through WPMCP\Safety\File_Backup
                // so the scaffold participates in the standard undo story.
                if (! wp_mkdir_p($dir)) {
                    return [ 'error' => [
                        'code'    => 'mkdir_failed',
                        'message' => 'Could not create the child theme directory.',
                        'data'    => [ 'path' => $dir ],
                    ] ];
                }
                if (false === file_put_contents($dir . '/style.css', $style) || false === file_put_contents($dir . '/functions.php', $functions)) {
                    return [ 'error' => [
                        'code'    => 'write_failed',
                        'message' => 'Could not write the child theme files.',
                        'data'    => [ 'path' => $dir ],
                    ] ];
                }

                return [
                    'created'    => true,
                    'existing'   => false,
                    'stylesheet' => $slug,
                    'name'       => $name,
                    'template'   => get_template(),
                    'path'       => $dir,
                    'activated'  => false,
                ];
            },
        ];
    }

    /** @return array<int,string> the effective allowlist, never containing structural keys. */
    private static function allowlist(): array
    {
        $keys = (array) apply_filters('wpmcp_theme_mod_allowlist', self::MOD_ALLOWLIST);
        return array_values(array_diff(array_map('strval', $keys), self::MOD_STRUCTURAL));
    }

    /** @return string framework family label ('none' when unrecognized). */
    private static function detect_framework(): string
    {
        $map      = (array) apply_filters('wpmcp_theme_framework_map', self::FRAMEWORK_MAP);
        $template = get_template();
        foreach ($map as $slug => $family) {
            if ($template === $slug || 0 === strpos($template, $slug . '-')) {
                return (string) $family;
            }
        }
        return 'none';
    }
}
