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
        . "                throw new \\RuntimeException('The builder (Elementor) dialect is not enabled for this install; the Gutenberg dialect is always available.');\n"
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
    "     * Both tools are FREE and read-only. Tiering happens per skill document\n"
        . "     * (`tier: pro` in its frontmatter, enforced in Get_Skill through\n"
        . "     * Pro\\Gate), not at the surface, so a premium skill library can drop into\n"
        . "     * the same directory layout without changing this registration.\n",
    "     * Both tools are FREE and read-only, and so is every skill document\n"
        . "     * they serve: this build has no premium skill library to withhold.\n",
    1,
];
$edits['src/Plugin.php'] = $plugin_edits;

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
