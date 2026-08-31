<?php
/**
 * Turn the staged full tree into the WordPress.org directory cut.
 *
 * Guideline 5 is the whole reason this file exists:
 *
 *   "Plugins may not contain functionality that is restricted or locked,
 *    only to be made available by payment or upgrade. Functionality may not
 *    be disabled after a trial period or quota is met."
 *
 * and, in the same section, the remedy the guideline itself recommends:
 *
 *   "We recommend the use of add-on plugins, hosted outside of
 *    WordPress.org, in order to exclude the premium code."
 *
 * So the directory build does not gate the paid surface, it does not contain
 * it. The paid tier becomes an add-on distributed off-directory, and what
 * ships here is a complete free plugin with no licence check, no paid
 * predicate, no quota that a payment lifts, and no execution construct.
 *
 * Every edit below is an exact-string replacement that fails the build if the
 * string it expects is missing, so a refactor upstream breaks the build
 * loudly instead of silently shipping a gated zip. Usage:
 *
 *   php scripts/flavors/wporg/strip.php <staged-plugin-dir>
 */

declare(strict_types=1);

$stage = $argv[1] ?? '';
if ('' === $stage || ! is_dir($stage)) {
    fwrite(STDERR, "usage: strip.php <staged-plugin-dir>\n");
    exit(2);
}
$stage = rtrim($stage, '/');

/** Paths removed outright: the paid tier and the two execution call sites. */
const REMOVED_PATHS = [
    // The licence check and the SDK that backs it. Nothing in this build is
    // unlocked by a payment, so guideline 6's "a service that exists for the
    // sole purpose of validating licenses ... is not permitted" has nothing
    // left to bite on.
    'src/Pro',
    'src/Freemius',
    // Paid ability groups, whole. These are the add-on.
    // Note: src/Cloud stays. Cloud_Client and Cloud_Config are a plain HTTP
    // seam and an option store with no paid gating in them, and the free
    // announcements feed in src/Admin/Announcements.php fetches through
    // Cloud_Client. The paid part is the ability wrappers below, not the seam.
    'src/Tools/Cloud',
    'src/Tools/Analysis',
    // Note: src/Tools/Builders is not removed by path any more. The same
    // reasoning as src/Cloud applies to it since issue #83: Builder_Detector
    // and Bricks_Content are plain postmeta readers with no paid gating in
    // them, and the free content search index reads through both. The paid
    // part is the ability wrappers (Detect_Builder, Get_Builder_Content,
    // Update_Builder_Content), which the sweep below takes out along with
    // Divi_Content once register_builder_abilities is gone.
    'src/Tools/BlockBuilder',
    'src/Tools/WidgetBuilder',
    // Execution. The guards stay (Governance\Opt_In_Gates references them);
    // the runners, the executor and their ability wrappers do not.
    'src/Tools/Cli/Run_Wp_Cli.php',
    'src/Tools/Cli/Wp_Cli_Executor.php',
    'src/Tools/Code/Run_Php_Snippet.php',
    'src/Tools/Code/Php_Snippet_Runner.php',
    // The only curl_setopt() in the tree. Page_Audit checks class_exists()
    // and falls back to wp_safe_remote_get() on its own.
    'src/Tools/Performance/Curl_Dns_Pin.php',
    // Paid ability whose handler lives inside an otherwise free directory.
    'src/Tools/Media/Stock/Insert_Stock_Image.php',
    // Same shape for the SEO group (issue #67): the post-meta surface stays
    // free, so the directory cannot go whole, but the schema generation and
    // extended social vocabulary are paid. Schema_Generator has no caller
    // once Generate_Schema_Markup is gone, so it goes with it.
    'src/Tools/SEO/Generate_Schema_Markup.php',
    'src/Tools/SEO/Schema_Generator.php',
    'src/Tools/SEO/Get_Social_Meta.php',
    // Brand kits (issue #75). Every class under here is reachable only from
    // register_brand_kit_abilities, which this build deletes, and the kit
    // library itself is data rather than a free feature, so the directory
    // goes whole rather than being swept.
    'src/Tools/Brand',
    // Agent project memory (issue #131). Only the three PRO ability wrappers
    // go. src/Memory and src/Admin/Memory_Page.php stay: publishing a
    // guardrail and having the server enforce it in Registrar::is_permitted()
    // is free on every tier, and a safety rule that stopped applying in this
    // build would be worse than not shipping it.
    'src/Tools/Memory',
];

/** Whole method declarations deleted from Plugin.php: every one is pro-only. */
const REMOVED_METHODS = [
    'register_builder_abilities',
    'register_analysis_abilities',
    'register_cli_abilities',
    'register_php_exec_abilities',
    'register_widget_builder_abilities',
    'register_block_builder_abilities',
    'register_cloud_abilities',
    'register_elementor_pro_abilities',
    'register_elementor_structural_abilities',
    'register_brand_kit_abilities',
    'register_memory_abilities',
];

/**
 * Exact-string edits, keyed by relative path. Each entry is [old, new,
 * expected occurrences]. A count mismatch aborts the build.
 *
 * @var array<string,array<int,array{0:string,1:string,2:int}>>
 */
$edits = [];

// ---------------------------------------------------------------- Registrar
// The tier skip stops being a licence question and becomes a statement of
// fact about this build. Kept rather than deleted so a pro-tier ability that
// somehow survived the prune still cannot reach the MCP surface.
$edits['src/MCP/Registrar.php'] = [
    ["use WPMCP\\Pro\\Gate;\n", '', 1],
    [
        "        if ('pro' === \$a->tier && ! Gate::is_pro()) {\n            return;\n        }\n",
        "        // The paid tier is a separate add-on plugin, not a locked part of\n"
            . "        // this one: its abilities are not in this build at all. This is a\n"
            . "        // belt-and-braces refusal, not a licence check.\n"
            . "        if ('pro' === \$a->tier) {\n            return;\n        }\n",
        1,
    ],
    [
        "        \$allowed = ('pro' !== \$a->tier || Gate::is_pro())\n",
        "        \$allowed = ('pro' !== \$a->tier)\n",
        1,
    ],
    [
        "     * Permission decision for one ability invocation. On top of the\n"
            . "     * pre-existing capability + Governance + identity-scope gating, 'pro'\n"
            . "     * tier abilities re-check the live license here (issue #54): the\n"
            . "     * Abilities API runs this before every execution, so a license that\n"
            . "     * lapses after registration cannot keep a pro tool usable. The\n"
            . "     * decision is audited exactly as before.\n",
        "     * Permission decision for one ability invocation: capability +\n"
            . "     * Governance + identity scope, audited. Pro-tier abilities are not\n"
            . "     * part of this build, so the tier test can only ever refuse.\n",
        1,
    ],
];

// ------------------------------------------------------- snapshot retention
// Guideline 5's quota clause. The cap becomes flat and unconditional, with a
// filter so a site can raise it for free: nothing here is lifted by a payment.
foreach (
    [
    'src/Safety/Safe_Mutation.php',
    'src/Tools/Compose/Build_Page.php',
    'src/Tools/Packages/Switch_Theme.php',
    'src/Tools/Media/Media_Import_Snapshot.php',
    // Reachable from the free build through List_Global_Classes, which stays:
    // the read is free, only the write tools around it are the add-on.
    'src/Tools/Elementor/Global_Classes_Store.php',
    ] as $path
) {
    $edits[$path][] = ["use WPMCP\\Pro\\Gate;\n", '', 1];
    $edits[$path][] = ['Snapshot_Store::prune(Gate::history_limit());', 'Snapshot_Store::prune(Snapshot_Store::history_limit());', 1];
}

// ---------------------------------------------------------------- Build_Page
// The Elementor dialect is not gated here, it is simply free.
$edits['src/Tools/Compose/Build_Page.php'][] = [
    "            if (! Gate::can_use('build-page-builder')) {\n"
        . "                throw new \\RuntimeException('The builder (Elementor) dialect of build-page is a PRO feature; the free tier composes Gutenberg pages.');\n"
        . "            }\n",
    '',
    1,
];
$edits['src/Tools/Compose/Build_Page.php'][] = [
    " * The Gutenberg dialect is free; the builder (Elementor) dialect is gated\n * PRO via Pro\\Gate before any write.\n",
    " * Both dialects, Gutenberg and the Elementor builder, are free.\n",
    1,
];

// ----------------------------------------------------------- site context
$edits['src/Tools/Context/Get_Site_Context.php'] = [
    ["use WPMCP\\Pro\\Gate;\n", '', 1],
    ["                'pro_active' => Gate::is_pro(),\n", '', 1],
];

// ------------------------------------------------------------- ability grid
// Guideline 9 prohibits "implying users must pay to unlock included
// features". With nothing paid in the build there is nothing to imply, so the
// locked-row state and its copy go.
$edits['src/Admin/Ability_Grid_Page.php'] = [
    ["use WPMCP\\Pro\\Gate;\n", '', 1],
    [
        "        \$pro_locked = 'pro' === \$a->tier && ! Gate::is_pro();\n        \$explain    = Governance::explain(\$a);\n",
        "        \$explain    = Governance::explain(\$a);\n",
        1,
    ],
    [
        "        if (\$pro_locked) {\n            \$reason = __('disabled: no pro license', 'wpmcp');\n        } elseif (\$explain['enabled']) {\n",
        "        if (\$explain['enabled']) {\n",
        1,
    ],
    ["            'pro_locked'  => \$pro_locked,\n", '', 1],
    ["            'enabled'     => ! \$pro_locked && \$explain['enabled'],\n", "            'enabled'     => \$explain['enabled'],\n", 1],
    ["        \$is_pro = Gate::is_pro();\n", '', 1],
    [
        "            <?php if (! \$is_pro) : ?>\n"
            . "                <p class=\"description\">\n"
            . "                    <?php echo esc_html__('PRO abilities are listed so you can see the full surface; they stay off until a pro license is active.', 'wpmcp'); ?>\n"
            . "                </p>\n"
            . "            <?php endif; ?>\n\n",
        '',
        1,
    ],
    [
                "                                <?php if ('pro' === \$row['tier']) : ?>\n"
        . "                                    <strong><?php echo esc_html__('PRO', 'wpmcp'); ?></strong>\n"
        . "                                    <?php if (\$row['pro_locked']) : ?>\n"
        . "                                        <span class=\"description\"><?php echo esc_html__('(locked)', 'wpmcp'); ?></span>\n"
        . "                                    <?php endif; ?>\n"
        . "                                <?php else : ?>\n"
        . "                                    <?php echo esc_html__('free', 'wpmcp'); ?>\n"
        . "                                <?php endif; ?>\n",
        "                                <?php echo esc_html__('free', 'wpmcp'); ?>\n",
        1,
    ],
    [
        "                                    <?php elseif (\$row['pro_locked']) : ?>\n"
            . "                                        <button type=\"button\" class=\"button button-small\" disabled\n"
            . "                                            title=\"<?php echo esc_attr__('Requires a pro license.', 'wpmcp'); ?>\">\n"
            . "                                            <?php echo esc_html__('Enable', 'wpmcp'); ?>\n"
            . "                                        </button>\n",
        '',
        1,
    ],
];

// ----------------------------------------------------------------- skills
// The skill tools and the whole bundled playbook library are free already.
// What leaves is the per-document tier: a premium library ships with the
// off-directory add-on, so in this build nothing is ever withheld and the
// lock branch, its error copy and the docs describing it all go.
$edits['src/Skills/Skill_Library.php'] = [
    ["use WPMCP\\Pro\\Gate;\n", '', 1],
    [
        " *  - TIERING IS PER SKILL, NOT PER SURFACE. The tools and the starter\n"
            . " *    library are free. A skill declaring `tier: pro` is still listed (an\n"
            . " *    agent can see it exists) but its body is withheld through Pro\\Gate\n"
            . " *    until the site is licensed, which is what lets a premium skill library\n"
            . " *    ship into the same directory structure later.\n",
        " *  - EVERY SKILL IN THIS BUILD IS FREE. The tools and every document\n"
            . " *    they serve are available to every install: no tier, no lock and\n"
            . " *    no body withheld from anyone.\n",
        1,
    ],
    [
        "    /** Whether a record's body is withheld pending a pro license. */\n"
            . "    public static function is_locked(array \$record): bool\n"
            . "    {\n"
            . "        return 'pro' === (\$record['tier'] ?? 'free') && ! Gate::is_pro();\n"
            . "    }\n",
        "    /**\n"
            . "     * Whether a record's body is withheld. Nothing in this build ever is,\n"
            . "     * so this can only answer no. Kept as a method because the listing\n"
            . "     * projection and get-skill both ask.\n"
            . "     *\n"
            . "     * @param array<string, mixed> \$record Unused: no record is withheld here.\n"
            . "     */\n"
            . "    public static function is_locked(array \$record): bool\n"
            . "    {\n"
            . "        return false;\n"
            . "    }\n",
        1,
    ],
];

$edits['src/Tools/Skills/Get_Skill.php'] = [
    [
        " *  - wpmcp_skill_locked: the skill declares `tier: pro` and this site is not\n"
            . " *    licensed. The catalog entry stays visible, only the body is withheld.\n",
        '',
        1,
    ],
    [
        "        if (true === (\$skill['locked'] ?? false)) {\n"
            . "            return new \\WP_Error(\n"
            . "                'wpmcp_skill_locked',\n"
            . "                sprintf(\n"
            . "                    'The skill \"%s\" is part of the premium skill library and needs an active WP MCP Pro license on this site.',\n"
            . "                    \$slug\n"
            . "                ),\n"
            . "                [\n"
            . "                    'slug' => \$slug,\n"
            . "                    'tier' => 'pro',\n"
            . "                ]\n"
            . "            );\n"
            . "        }\n\n"
            . "        unset(\$skill['locked']);\n",
        "        unset(\$skill['locked']);\n",
        1,
    ],
];

// ------------------------------------------------------------- Plugin.php
$plugin_edits = [
    // Ability-group wiring for the groups that are not in this build.
    ["            'builder'        => fn () => \$this->register_builder_abilities(\$registrar),\n", '', 1],
    ["            'analysis'       => fn () => \$this->register_analysis_abilities(\$registrar),\n", '', 1],
    ["            'cli'            => fn () => \$this->register_cli_abilities(\$registrar),\n", '', 1],
    ["            'php_exec'       => fn () => \$this->register_php_exec_abilities(\$registrar),\n", '', 1],
    ["            'widget_builder' => fn () => \$this->register_widget_builder_abilities(\$registrar),\n", '', 1],
    ["            'block_builder'  => fn () => \$this->register_block_builder_abilities(\$registrar),\n", '', 1],
    ["            'cloud'          => fn () => \$this->register_cloud_abilities(\$registrar),\n", '', 1],
    ["            'memory'         => fn () => \$this->register_memory_abilities(\$registrar),\n", '', 1],
    // The two pro suites chained off the free Elementor group.
    ["\n        \$this->register_elementor_pro_abilities(\$registrar);\n", "\n", 1],
    ["\n        \$this->register_elementor_structural_abilities(\$registrar);\n", "\n", 1],
];
// Runtime hook wiring for the two builder suites, which are not in this
// build. These are string callables, so nothing but removing them stops them
// fataling on the init hook.
$plugin_edits[] = [
    "        // Data-driven custom widget builder: register the wpmcp_widget CPT\n"
        . "        // and register active specs as Elementor widgets at runtime (no eval).\n"
        . "        if (\$this->group_enabled('widget_builder')) {\n"
        . "            add_action('init', ['\\\\WPMCP\\\\Tools\\\\WidgetBuilder\\\\Widget_Spec_Store', 'ensure_post_type']);\n"
        . "            add_action('elementor/widgets/register', ['\\\\WPMCP\\\\Tools\\\\WidgetBuilder\\\\Widget_Registry', 'register']);\n"
        . "        }\n"
        . "        // Data-driven custom Gutenberg block builder: register the wpmcp_block\n"
        . "        // CPT and register active specs as real blocks via register_block_type.\n"
        . "        if (\$this->group_enabled('block_builder')) {\n"
        . "            add_action('init', ['\\\\WPMCP\\\\Tools\\\\BlockBuilder\\\\Block_Spec_Store', 'ensure_post_type'], 5);\n"
        . "            add_action('init', ['\\\\WPMCP\\\\Tools\\\\BlockBuilder\\\\Block_Registry', 'register'], 20);\n"
        . "        }\n",
    "        // The widget and block builders are part of the off-directory\n"
        . "        // add-on, so this build has no runtime hooks to wire.\n",
    1,
];

// The handler for the one pro ability registered inline rather than in a
// group method. Its registration goes with remove_pro_abilities(); this is
// the local it was assigned to.
$plugin_edits[] = ["        \$insert_stock_image  = new Insert_Stock_Image();\n", '', 1];

// Same for the two inline pro SEO abilities (issue #67): remove_pro_abilities()
// takes the register() calls, these take the locals and the comments that
// describe the tiering.
$plugin_edits[] = [
    "        // Schema generation (issue #67): a proposal (read) tool that builds\n"
        . "        // JSON-LD from the post's own record, so it is useful even with no\n"
        . "        // SEO plugin active and registers unconditionally like get-seo-status.\n"
        . "        \$generate_schema = new Generate_Schema_Markup();\n\n",
    '',
    1,
];
$plugin_edits[] = [
    "        // Extended vocabulary (issue #67): per-post OG/Twitter reads in one\n"
        . "        // neutral field set. Plugins whose social storage is not mapped yet\n"
        . "        // answer with a structured \"unsupported\", never an error.\n"
        . "        \$get_social_meta = new Get_Social_Meta();\n\n",
    '',
    1,
];

// Documentation the reviewer reads too: a build with no licence gate must not
// describe one.
$plugin_edits[] = [
    "     * One-call declarative page composition (issue #57). Registered free:\n"
        . "     * the Gutenberg dialect is the free tier's builder; the Elementor\n"
        . "     * builder dialect is gated PRO inside the handler via Pro\\Gate, so the\n"
        . "     * one ability serves both tiers with the gate re-checked per call.\n",
    "     * One-call declarative page composition (issue #57). Both dialects,\n"
        . "     * the block editor and the Elementor builder, are available to every\n"
        . "     * install of this plugin.\n",
    1,
];
$plugin_edits[] = [
    "     * Register the SEO tool group. Mixed tiers since issue #67: the\n"
        . "     * post-meta surface (get-seo-status, get-seo-meta, update-seo-meta) is\n"
        . "     * free, and the generation and extended-vocabulary tools\n"
        . "     * (generate-schema-markup, get-social-meta) declare tier 'pro', which the\n"
        . "     * Registrar enforces centrally rather than each handler re-checking.\n"
        . "     *\n"
        . "     * get-seo-status is registered unconditionally: it must be reachable to\n"
        . "     * report \"no SEO plugin active\" at all, and it does not touch any\n"
        . "     * plugin-specific postmeta so it has nothing to degrade.\n"
        . "     * generate-schema-markup registers unconditionally for the same reason:\n"
        . "     * it builds the graph from the post's own record, so it works on a site\n"
        . "     * with no SEO plugin at all. get-seo-meta, update-seo-meta and\n"
        . "     * get-social-meta are registered conditionally on SEO_Adapter detecting a\n"
        . "     * supported plugin, following the same conditional-registration pattern\n"
        . "     * as the ACF tool group: no supported plugin has a free/pro split of its\n"
        . "     * own to key off, so plugin absence is the only signal, and skipping\n"
        . "     * keeps these out of the catalog on sites running none of them.\n",
    "     * Register the SEO tools as free-tier abilities.\n"
        . "     *\n"
        . "     * get-seo-status is registered unconditionally: it must be reachable to\n"
        . "     * report \"no SEO plugin active\" at all, and it does not touch any\n"
        . "     * plugin-specific postmeta so it has nothing to degrade. get-seo-meta and\n"
        . "     * update-seo-meta are registered conditionally on SEO_Adapter detecting a\n"
        . "     * supported plugin, following the same conditional-registration pattern\n"
        . "     * as the ACF tool group: no supported plugin has a free/pro split of its\n"
        . "     * own to key off, so plugin absence is the only signal, and skipping\n"
        . "     * keeps these out of the catalog on sites running none of them.\n",
    1,
];
$plugin_edits[] = [
    "     * Both tools are FREE and read-only. Tiering happens per skill document\n"
        . "     * (`tier: pro` in its frontmatter, enforced in Get_Skill through\n"
        . "     * Pro\\Gate), not at the surface, so a premium skill library can drop into\n"
        . "     * the same directory layout without changing this registration.\n",
    "     * Both tools are FREE and read-only, and so is every skill document\n"
        . "     * they serve: this build has no premium skill library to withhold.\n",
    1,
];
$edits['src/Plugin.php'] = $plugin_edits;

// The SEO group is mixed-tier, so its directory cannot go whole: the
// post-meta surface stays free while the extended social vocabulary is paid.
// Get_Social_Meta.php is in REMOVED_PATHS above, and these take the adapter
// code behind it (the three key maps, the Twitter/OG fallback pairing, and
// get_social_meta() itself) so the free build carries no unreachable pro
// logic and the comment about the vocabulary being paid stays true.
$edits['src/Tools/SEO/SEO_Adapter.php'] = [
    [
        "    // Extended vocabulary (issue #67): per-post OG/Twitter overrides for the\n"
        . "    // plugins that store them as flat postmeta. The SEO Framework derives its\n"
        . "    // social fields rather than storing a full per-post set, and SureRank\n"
        . "    // packs them into the serialized _surerank_meta array, so both need\n"
        . "    // dedicated branches. Until a plugin has a verified map here,\n"
        . "    // get_social_meta() reports it as unsupported rather than guessing.\n"
        . "    // TODO(#67): SEO Framework / SureRank social maps + write path.\n"
        . "    private const YOAST_SOCIAL_KEYS = [\n"
        . "        'og_title'            => '_yoast_wpseo_opengraph-title',\n"
        . "        'og_description'      => '_yoast_wpseo_opengraph-description',\n"
        . "        'og_image'            => '_yoast_wpseo_opengraph-image',\n"
        . "        'twitter_title'       => '_yoast_wpseo_twitter-title',\n"
        . "        'twitter_description' => '_yoast_wpseo_twitter-description',\n"
        . "        'twitter_image'       => '_yoast_wpseo_twitter-image',\n"
        . "    ];\n"
        . "\n"
        . "    private const RANKMATH_SOCIAL_KEYS = [\n"
        . "        'og_title'            => 'rank_math_facebook_title',\n"
        . "        'og_description'      => 'rank_math_facebook_description',\n"
        . "        'og_image'            => 'rank_math_facebook_image',\n"
        . "        'twitter_title'       => 'rank_math_twitter_title',\n"
        . "        'twitter_description' => 'rank_math_twitter_description',\n"
        . "        'twitter_image'       => 'rank_math_twitter_image',\n"
        . "    ];\n"
        . "\n"
        . "    private const SEOPRESS_SOCIAL_KEYS = [\n"
        . "        'og_title'            => '_seopress_social_fb_title',\n"
        . "        'og_description'      => '_seopress_social_fb_desc',\n"
        . "        'og_image'            => '_seopress_social_fb_img',\n"
        . "        'twitter_title'       => '_seopress_social_twitter_title',\n"
        . "        'twitter_description' => '_seopress_social_twitter_desc',\n"
        . "        'twitter_image'       => '_seopress_social_twitter_img',\n"
        . "    ];\n"
        . "\n",
        '',
        1,
    ],
    [
        "    /**\n"
        . "     * Which OpenGraph field each Twitter field falls back to when the active\n"
        . "     * plugin mirrors one onto the other. Same pairing on all three mapped\n"
        . "     * plugins.\n"
        . "     */\n"
        . "    private const TWITTER_OG_FALLBACKS = [\n"
        . "        'twitter_title'       => 'og_title',\n"
        . "        'twitter_description' => 'og_description',\n"
        . "        'twitter_image'       => 'og_image',\n"
        . "    ];\n"
        . "\n",
        '',
        1,
    ],
    [
        "    /**\n"
        . "     * Read the per-post social (OG/Twitter) overrides for the active plugin.\n"
        . "     *\n"
        . "     * Returns ['supported' => true, 'fields' => [...], 'sources' => [...]]\n"
        . "     * where mapped, or a structured ['supported' => false, 'reason' => ...]\n"
        . "     * where the active plugin has no verified per-post social map yet: issue\n"
        . "     * #67 requires unsupported combinations to be reported, not thrown.\n"
        . "     *\n"
        . "     * The SEO group answers \"unsupported\" with this payload rather than the\n"
        . "     * WP_Error / `unsupported_*` code the builder tools use, because the\n"
        . "     * issue asks for structured unsupported responses instead of errors: an\n"
        . "     * agent reading social fields on The SEO Framework has asked a sensible\n"
        . "     * question about a real post, and the honest answer is \"this plugin does\n"
        . "     * not store that\", not a failure. Every caller in this group uses this\n"
        . "     * one shape.\n"
        . "     *\n"
        . "     * `fields` is the resolved state, not the raw postmeta. All three mapped\n"
        . "     * plugins fall back to the OpenGraph values when a Twitter field is\n"
        . "     * empty (RankMath gates that on rank_math_twitter_use_facebook, which\n"
        . "     * defaults on), so returning the bare meta would report\n"
        . "     * `twitter_title: ''` for a post whose rendered Twitter card does have a\n"
        . "     * title. `sources` says where each value came from, so an agent can still\n"
        . "     * tell an explicit override from an inherited one before writing:\n"
        . "     *\n"
        . "     * - 'override'  the field is set in the plugin's own postmeta key\n"
        . "     * - 'inherited' empty here, resolved from the corresponding og_ field\n"
        . "     * - 'absent'    nothing set, and nothing to inherit\n"
        . "     *\n"
        . "     * TODO(#67): update_social_meta() write path through Safe_Mutation, and\n"
        . "     * term-level variants of both.\n"
        . "     */\n"
        . "    public static function get_social_meta(int \$post_id): array\n"
        . "    {\n"
        . "        \$active = self::active_plugin();\n"
        . "\n"
        . "        \$maps = [\n"
        . "            'yoast'    => self::YOAST_SOCIAL_KEYS,\n"
        . "            'rankmath' => self::RANKMATH_SOCIAL_KEYS,\n"
        . "            'seopress' => self::SEOPRESS_SOCIAL_KEYS,\n"
        . "        ];\n"
        . "\n"
        . "        if (! isset(\$maps[\$active])) {\n"
        . "            return [\n"
        . "                'supported' => false,\n"
        . "                'plugin'    => \$active,\n"
        . "                'reason'    => '' === \$active\n"
        . "                    ? 'No supported SEO plugin is active.'\n"
        . "                    : 'Per-post social fields are not mapped for this plugin yet.',\n"
        . "            ];\n"
        . "        }\n"
        . "\n"
        . "        \$fields  = [];\n"
        . "        \$sources = [];\n"
        . "        foreach (\$maps[\$active] as \$field => \$key) {\n"
        . "            \$fields[\$field]  = (string) get_post_meta(\$post_id, \$key, true);\n"
        . "            \$sources[\$field] = '' === \$fields[\$field] ? 'absent' : 'override';\n"
        . "        }\n"
        . "\n"
        . "        if (self::twitter_mirrors_og(\$active, \$post_id)) {\n"
        . "            foreach (self::TWITTER_OG_FALLBACKS as \$twitter => \$og) {\n"
        . "                if ('' === \$fields[\$twitter] && '' !== \$fields[\$og]) {\n"
        . "                    \$fields[\$twitter]  = \$fields[\$og];\n"
        . "                    \$sources[\$twitter] = 'inherited';\n"
        . "                }\n"
        . "            }\n"
        . "        }\n"
        . "\n"
        . "        return [\n"
        . "            'supported' => true,\n"
        . "            'plugin'    => \$active,\n"
        . "            'fields'    => \$fields,\n"
        . "            'sources'   => \$sources,\n"
        . "        ];\n"
        . "    }\n"
        . "\n"
        . "    /**\n"
        . "     * Whether the active plugin renders the Twitter card from the OpenGraph\n"
        . "     * fields when the Twitter ones are empty.\n"
        . "     *\n"
        . "     * Yoast and SEOPress always do. RankMath makes it a per-post switch,\n"
        . "     * `rank_math_twitter_use_facebook`, stored as 'on'/'off' and defaulting\n"
        . "     * to on: an unset value means the mirror is active, which is the state of\n"
        . "     * every post on a stock install, so an absent meta must read as true.\n"
        . "     */\n"
        . "    private static function twitter_mirrors_og(string \$active, int \$post_id): bool\n"
        . "    {\n"
        . "        if ('rankmath' !== \$active) {\n"
        . "            return true;\n"
        . "        }\n"
        . "\n"
        . "        \$flag = get_post_meta(\$post_id, 'rank_math_twitter_use_facebook', true);\n"
        . "\n"
        . "        return 'off' !== (string) \$flag;\n"
        . "    }\n",
        '',
        1,
    ],
];

$edits['src/Identity/Identity_Context.php'] = [
    [
        " * Mirrors WPMCP\\Pro\\Gate's test-seam pattern exactly: a non-null in-memory\n"
            . " * override takes precedence over production resolution, which falls back\n",
        " * A non-null in-memory override takes precedence over production\n"
            . " * resolution, which falls back\n",
        1,
    ],
];

$edits['src/Tools/Connect/List_Tool_Catalog.php'] = [
    [
        " * reflects what is actually available on this site right now (a free-tier\n"
            . " * site never sees pro abilities here, matching Registrar's own pro-gating).\n"
            . " * A caller (namely this class's own test suite) may inject a different\n"
            . " * Registrar to inspect a hand-built ability set, e.g. one built with\n"
            . " * Gate::set_pro_for_tests(true) to exercise a pro-tier entry.\n",
        " * reflects what is actually available on this site right now.\n"
            . " * A caller (namely this class's own test suite) may inject a different\n"
            . " * Registrar to inspect a hand-built ability set.\n",
        1,
    ],
];

// ------------------------------------------------------------- Snapshot_Store
// The flat cap the four call sites above now read.
$edits['src/Safety/Snapshot_Store.php'] = [
    [
        "    public static function prune(int \$keep): int\n",
        "    /**\n"
            . "     * How many snapshots a site keeps. One number for every install: no\n"
            . "     * licence, no tier, nothing a payment changes. Filterable so a site\n"
            . "     * that wants deeper history can have it for free, which is the\n"
            . "     * difference guideline 5 draws between a product decision and a lock.\n"
            . "     */\n"
            . "    public static function history_limit(): int\n"
            . "    {\n"
            . "        \$limit = (int) apply_filters('wpmcp_snapshot_history_limit', self::DEFAULT_HISTORY_LIMIT);\n"
            . "        return \$limit > 0 ? \$limit : self::DEFAULT_HISTORY_LIMIT;\n"
            . "    }\n\n"
            . "    public static function prune(int \$keep): int\n",
        1,
    ],
];

$failures = [];
$applied = 0;

/** Apply the exact-string edits. */
foreach ($edits as $relative => $file_edits) {
    $path = $stage . '/' . $relative;
    if (! is_file($path)) {
        $failures[] = sprintf('%s: file not found in the staged tree', $relative);
        continue;
    }
    $contents = (string) file_get_contents($path);
    foreach ($file_edits as [$old, $new, $expected]) {
        $found = substr_count($contents, $old);
        if ($found !== $expected) {
            $failures[] = sprintf(
                '%s: expected %d occurrence(s) of %s, found %d',
                $relative,
                $expected,
                json_encode(substr($old, 0, 70)),
                $found
            );
            continue;
        }
        $contents = str_replace($old, $new, $contents);
        $applied++;
    }
    file_put_contents($path, $contents);
}

/** DEFAULT_HISTORY_LIMIT has to exist for the new accessor to read it. */
$snapshot_store = $stage . '/src/Safety/Snapshot_Store.php';
if (is_file($snapshot_store) && ! str_contains((string) file_get_contents($snapshot_store), 'DEFAULT_HISTORY_LIMIT =')) {
    $failures[] = 'src/Safety/Snapshot_Store.php: DEFAULT_HISTORY_LIMIT is not declared in the source';
}

/** Delete the pro-only method declarations from Plugin.php, docblock included. */
$plugin_php = $stage . '/src/Plugin.php';
foreach (REMOVED_METHODS as $method) {
    $result = remove_method($plugin_php, $method);
    if (true !== $result) {
        $failures[] = sprintf('src/Plugin.php: %s', $result);
        continue;
    }
    $applied++;
}

/** Remove the paths that carry the paid tier and the execution constructs. */
foreach (REMOVED_PATHS as $relative) {
    $path = $stage . '/' . $relative;
    if (! file_exists($path)) {
        $failures[] = sprintf('%s: nothing to remove at this path', $relative);
        continue;
    }
    remove_path($path);
    $applied++;
}

/**
 * Remove the single pro-tier Ability registered inline rather than in a
 * group method, then prove no pro-tier registration survived anywhere.
 */
$removed_inline = remove_pro_abilities($plugin_php);
$applied += $removed_inline;

/**
 * Drop `use` imports left pointing at classes this build no longer has, and
 * imports the edits above orphaned. Unused imports are harmless to PHP but
 * they make the dangling-reference gate below unable to tell a real problem
 * from a leftover.
 */
$applied += prune_unused_imports($stage);

/**
 * Directories where the paid handlers sit alongside free ones, so they cannot
 * be removed by path. Everything unreachable after the registrations above
 * were deleted is swept out of these, to a fixed point.
 *
 * Guideline 5 recommends excluding the premium code, not merely disabling it,
 * so leaving forty unreachable pro handlers in the zip would miss the point
 * even though nothing can call them.
 */
const SWEPT_DIRECTORIES = ['src/Tools/Elementor', 'src/Tools/Builders'];

$swept = sweep_unreferenced($stage, SWEPT_DIRECTORIES);
$applied += count($swept);

if ([] !== $failures) {
    fwrite(STDERR, "wp.org strip failed:\n  " . implode("\n  ", $failures) . "\n");
    exit(1);
}

printf(
    "wp.org strip: %d edits applied, %d inline pro abilities removed, %d unreferenced files swept\n",
    $applied,
    $removed_inline,
    count($swept)
);

// ---------------------------------------------------------------- helpers

function remove_path(string $path): void
{
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ('.' === $entry || '..' === $entry) {
            continue;
        }
        remove_path($path . '/' . $entry);
    }
    rmdir($path);
}

/**
 * Delete one method declaration, its preceding docblock and the blank line
 * after it, by brace matching over the token stream.
 *
 * @return true|string true, or the reason it could not be done
 */
function remove_method(string $path, string $method)
{
    $contents = (string) file_get_contents($path);
    $tokens = token_get_all($contents);
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if (! is_array($tokens[$i]) || T_FUNCTION !== $tokens[$i][0]) {
            continue;
        }
        $name_index = null;
        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && T_WHITESPACE === $tokens[$j][0]) {
                continue;
            }
            $name_index = $j;
            break;
        }
        if (null === $name_index || ! is_array($tokens[$name_index]) || T_STRING !== $tokens[$name_index][0]) {
            continue;
        }
        if ($tokens[$name_index][1] !== $method) {
            continue;
        }

        // Walk back over the modifiers, then the docblock and the blank line.
        $start = $i;
        for ($k = $i - 1; $k >= 0; $k--) {
            if (! is_array($tokens[$k])) {
                break;
            }
            if (in_array($tokens[$k][0], [T_WHITESPACE, T_PRIVATE, T_PUBLIC, T_PROTECTED, T_STATIC, T_FINAL, T_ABSTRACT, T_DOC_COMMENT], true)) {
                $start = $k;
                continue;
            }
            break;
        }

        // Forward to the matching close brace.
        $depth = 0;
        $end = null;
        for ($k = $name_index; $k < $count; $k++) {
            $token = $tokens[$k];
            $text = is_array($token) ? $token[1] : $token;
            if ('{' === $text) {
                $depth++;
                continue;
            }
            if ('}' === $text) {
                $depth--;
                if (0 === $depth) {
                    $end = $k;
                    break;
                }
            }
        }
        if (null === $end) {
            return sprintf('could not find the closing brace of %s()', $method);
        }

        // $start was walked back past the whitespace preceding the method, so
        // $before ends at the previous member's closing brace and $after opens
        // with the whitespace and indentation of the next one. Concatenating
        // them leaves the file spaced exactly as it was.
        $before = token_text($tokens, 0, $start - 1);
        $after = token_text($tokens, $end + 1, $count - 1);
        file_put_contents($path, $before . $after);
        return true;
    }

    return sprintf('%s() not found', $method);
}

/**
 * @param array<int,array|string> $tokens
 */
function token_text(array $tokens, int $from, int $to): string
{
    $out = '';
    for ($i = $from; $i <= $to; $i++) {
        if (! isset($tokens[$i])) {
            continue;
        }
        $out .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
    }
    return $out;
}

/**
 * Delete every `$registrar->register(new Ability( 'name', 'pro', ... ));`
 * statement, wherever it sits, and assert none remain.
 */
function remove_pro_abilities(string $path): int
{
    $contents = (string) file_get_contents($path);
    $removed = 0;

    while (true) {
        $start = strpos($contents, '$registrar->register(new Ability(');
        $offset = 0;
        $target = null;
        while (false !== $start) {
            // The tier is the second constructor argument, on the line after
            // the ability name.
            $head = substr($contents, $start, 200);
            if (preg_match("/register\(new Ability\(\s*\n\s*'[^']+',\s*\n\s*'pro',/", $head)) {
                $target = $start;
                break;
            }
            $offset = $start + 1;
            $start = strpos($contents, '$registrar->register(new Ability(', $offset);
        }
        if (null === $target) {
            break;
        }

        $end = statement_end($contents, $target);
        if (null === $end) {
            fwrite(STDERR, "wp.org strip: unbalanced pro Ability registration\n");
            exit(1);
        }
        $line_start = (int) strrpos(substr($contents, 0, $target), "\n") + 1;
        $line_end = strpos($contents, "\n", $end);
        $contents = substr($contents, 0, $line_start) . substr($contents, false === $line_end ? $end + 1 : $line_end + 1);
        $removed++;
    }

    file_put_contents($path, $contents);

    if (preg_match("/new Ability\(\s*\n\s*'[^']+',\s*\n\s*'pro',/", $contents)) {
        fwrite(STDERR, "wp.org strip: a pro-tier Ability survived the prune\n");
        exit(1);
    }

    return $removed;
}

/** Offset of the ';' closing the statement that starts at $from. */
function statement_end(string $contents, int $from): ?int
{
    $depth = 0;
    $length = strlen($contents);
    $quote = null;
    for ($i = $from; $i < $length; $i++) {
        $char = $contents[$i];
        if (null !== $quote) {
            if ('\\' === $char) {
                $i++;
                continue;
            }
            if ($char === $quote) {
                $quote = null;
            }
            continue;
        }
        if ("'" === $char || '"' === $char) {
            $quote = $char;
            continue;
        }
        if ('(' === $char || '[' === $char) {
            $depth++;
            continue;
        }
        if (')' === $char || ']' === $char) {
            $depth--;
            continue;
        }
        if (';' === $char && 0 === $depth) {
            return $i;
        }
    }
    return null;
}

/**
 * Delete every class file under $directories whose short name is named
 * nowhere else in the staged tree, repeating until nothing more can go.
 *
 * Deliberately conservative: a file that declares no class is never touched,
 * and a name mentioned anywhere at all, including inside a string or a
 * comment, counts as a reference. The build's classmap gate catches anything
 * this gets wrong, because a swept file that was in fact needed shows up
 * there as a class the build names but does not ship.
 *
 * @param  string[] $directories relative to $stage
 * @return string[] relative paths deleted
 */
function sweep_unreferenced(string $stage, array $directories): array
{
    $deleted = [];

    do {
        $names = [];
        foreach ($directories as $directory) {
            $root = $stage . '/' . $directory;
            if (! is_dir($root)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || 'php' !== $file->getExtension()) {
                    continue;
                }
                $contents = (string) file_get_contents($file->getPathname());
                if (! preg_match('/^(?:final\s+|abstract\s+)*(?:class|interface|trait|enum)\s+([A-Za-z_][A-Za-z0-9_]*)/m', $contents, $match)) {
                    continue;
                }
                $names[$file->getPathname()] = $match[1];
            }
        }
        if ([] === $names) {
            return $deleted;
        }

        $referenced = [];
        $all = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage . '/src'));
        foreach ($all as $file) {
            if (! $file instanceof SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $path = $file->getPathname();
            $contents = (string) file_get_contents($path);
            foreach ($names as $candidate => $short) {
                if ($candidate === $path || isset($referenced[$candidate])) {
                    continue;
                }
                if (preg_match('/\b' . preg_quote($short, '/') . '\b/', $contents)) {
                    $referenced[$candidate] = true;
                }
            }
        }

        $round = 0;
        foreach ($names as $path => $short) {
            if (isset($referenced[$path])) {
                continue;
            }
            unlink($path);
            $deleted[] = substr($path, strlen($stage) + 1);
            $round++;
        }
    } while ($round > 0);

    return $deleted;
}

/**
 * Drop `use A\B\C;` lines whose short name appears nowhere else in the file.
 */
function prune_unused_imports(string $stage): int
{
    $removed = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage . '/src'));
    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || 'php' !== $file->getExtension()) {
            continue;
        }
        $contents = (string) file_get_contents($file->getPathname());
        if (! preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+);\r?\n/m', $contents, $matches, PREG_SET_ORDER)) {
            continue;
        }
        $changed = false;
        foreach ($matches as $match) {
            $short = substr(strrchr($match[1], '\\') ?: ('\\' . $match[1]), 1);
            $body = str_replace($match[0], '', $contents);
            if (preg_match('/\b' . preg_quote($short, '/') . '\b/', $body)) {
                continue;
            }
            $contents = $body;
            $changed = true;
            $removed++;
        }
        if ($changed) {
            file_put_contents($file->getPathname(), $contents);
        }
    }
    return $removed;
}
