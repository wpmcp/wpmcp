<?php
/**
 * What the WordPress.org directory cut must not contain, in one place.
 *
 * Three consumers ask this question and used to answer it from three
 * hand-copied lists:
 *
 *   * scripts/flavors/wporg/strip.php, which does the surgery
 *   * scripts/build-wporg-release.sh, whose gates re-derive the answer from
 *     the staged tree and never trust the strip to have done its job
 *   * tests/free/Platform/WporgStripTest.php, which runs the strip on every
 *     test run rather than only at release time
 *
 * Sharing the vocabulary is not the same as trusting the strip: the gates
 * still re-scan the staged tree themselves. What it removes is the drift
 * where the release build and CI disagree about what counts as a finding.
 *
 * Every pattern is a POSIX extended regular expression, matched one line at
 * a time, so `grep -E` and `preg_match('/<pattern>/')` agree. No pattern
 * carries a case-insensitivity flag for the same reason: the character
 * classes are written out ([Ff]reemius) so both engines see the same thing.
 */

declare(strict_types=1);

return [
    /**
     * Paths removed outright: the paid tier and the two execution call
     * sites. strip.php deletes these; the build script asserts they are
     * absent from the stage and again from the extracted zip.
     */
    'removed_paths' => [
        // The licence check and the SDK that backs it. Nothing in this build
        // is unlocked by a payment, so guideline 6's "a service that exists
        // for the sole purpose of validating licenses ... is not permitted"
        // has nothing left to bite on.
        'src/Pro',
        'src/Freemius',
        // Paid ability groups, whole. These are the add-on.
        // Note: src/Cloud stays. Cloud_Client and Cloud_Config are a plain
        // HTTP seam and an option store with no paid gating in them, and the
        // free announcements feed in src/Admin/Announcements.php fetches
        // through Cloud_Client. The paid part is the ability wrappers below,
        // not the seam.
        'src/Tools/Cloud',
        'src/Tools/Analysis',
        // Note: src/Tools/Builders is not removed by path any more. The same
        // reasoning as src/Cloud applies to it since issue #83:
        // Builder_Detector and Bricks_Content are plain postmeta readers with
        // no paid gating in them, and the free content search index reads
        // through both. The paid part is the ability wrappers (Detect_Builder,
        // Get_Builder_Content, Update_Builder_Content), which the sweep takes
        // out along with Divi_Content once register_builder_abilities is gone.
        'src/Tools/BlockBuilder',
        'src/Tools/WidgetBuilder',
        // The shared base of the two builders' portable bundle kinds (issue
        // #297). The free bundle tools and the snippet kind stay; with both
        // builders gone this base has nothing left to extend it.
        'src/Tools/Portable/Spec_Bundle_Kind.php',
        // Execution. The guards stay (Governance\Opt_In_Gates references
        // them); the runners, the executor and their ability wrappers do not.
        'src/Tools/Cli/Run_Wp_Cli.php',
        'src/Tools/Cli/Wp_Cli_Executor.php',
        // Async wp-cli (issue #84) is the same execution surface on a cron
        // hook, so it leaves with the synchronous tool. Run_Cli_Job defaults
        // its executor to Wp_Cli_Executor::class, which the line above
        // deletes, so leaving it behind would ship a hook that fatals when it
        // fires.
        'src/Tools/Cli/Dispatch_Cli_Job.php',
        'src/Tools/Cli/Get_Cli_Job.php',
        'src/Tools/Cli/List_Cli_Jobs.php',
        'src/Tools/Cli/Cancel_Cli_Job.php',
        'src/Tools/Cli/Cli_Job_Store.php',
        'src/Tools/Cli/Run_Cli_Job.php',
        // The guard CHAIN is composed only by the runners above; Wp_Cli_Guard
        // itself stays because Governance\Opt_In_Gates references it.
        'src/Tools/Cli/Wp_Cli_Guard_Chain.php',
        'src/Tools/Code/Run_Php_Snippet.php',
        'src/Tools/Code/Php_Snippet_Runner.php',
        // Activation of a stored snippet (issue #85) is the exec gate's own
        // ability and is pro. The free store CRUD stays: it persists and reads
        // PHP source and nothing in this build can execute it.
        'src/Tools/Code/Activate_Php_Snippet.php',
        // The only curl_setopt() in the tree. Page_Audit checks class_exists()
        // and falls back to wp_safe_remote_get() on its own.
        'src/Tools/Performance/Curl_Dns_Pin.php',
        // Paid ability whose handler lives inside an otherwise free directory.
        'src/Tools/Media/Stock/Insert_Stock_Image.php',
        // Installing a plugin or theme from an uploaded ZIP (issue #282).
        // Guideline 8 forbids installing plugins or themes from anywhere but
        // WordPress.org, and the directory installers in src/Tools/Packages
        // stay defensible only because they accept nothing but a directory
        // slug. The ability is pro, so its registration leaves with the paid
        // tier; the handler and its archive validator leave here.
        'src/Tools/Packages/Install_Package_From_Zip.php',
        'src/Tools/Packages/Package_Archive.php',
        'src/Tools/Packages/Package_Rejected.php',
        // Cloud settings sync (issue #135). The engine behind the paid
        // cloud-sync-settings / cloud-apply-settings wrappers, and its apply()
        // path is the Pro\Gate entitlement itself. Only src/Tools/Cloud
        // reaches it, so once the wrappers are gone it goes too.
        'src/Cloud/Settings_Sync.php',
        // Same shape for the SEO group (issue #67): the post-meta surface
        // stays free, so the directory cannot go whole, but generation, the
        // extended social vocabulary and term-level SEO are paid. The helpers
        // (Schema_Generator, Social_Meta, Term_SEO) have no caller once the
        // handlers are gone, so they go with them.
        'src/Tools/SEO/Generate_Schema_Markup.php',
        'src/Tools/SEO/Schema_Generator.php',
        'src/Tools/SEO/Generate_Meta_Tags.php',
        'src/Tools/SEO/Get_Social_Meta.php',
        'src/Tools/SEO/Set_Social_Image.php',
        'src/Tools/SEO/Social_Meta.php',
        'src/Tools/SEO/Get_Term_SEO_Meta.php',
        'src/Tools/SEO/Update_Term_SEO_Meta.php',
        'src/Tools/SEO/Term_SEO.php',
        // All in One SEO support (issue #294) is the paid add-on's; strip.php
        // takes its one registration line out of SEO_Adapter.
        'src/Tools/SEO/Aioseo_Store.php',
        // The builder dialect of build-page is not in this build (issue #162);
        // its composer goes with it. Build_Page's references to it are edited
        // out in the exact-string pass.
        'src/Tools/Compose/Elementor_Composer.php',
        // The bundled Elementor playbook. Every ability in its `requires:` list
        // is pro-tier and therefore not in this build, so the document would
        // ship as a free skill instructing an agent to call tools that do not
        // exist. A skill the reader cannot follow is worse than no skill.
        'src/Skills/library/wpmcp-elementor-editing',
        // Brand kits (issue #75). Every class under here is reachable only
        // from register_brand_kit_abilities, which this build deletes, and the
        // kit library itself is data rather than a free feature, so the
        // directory goes whole rather than being swept.
        'src/Tools/Brand',
        // Agent project memory (issue #131). Only the three PRO ability
        // wrappers go. src/Memory and src/Admin/Memory_Page.php stay:
        // publishing a guardrail and having the server enforce it in
        // Registrar::is_permitted() is free on every tier, and a safety rule
        // that stopped applying in this build would be worse than not
        // shipping it.
        'src/Tools/Memory',
        // The forms adapter pack (issue #66). The five adapters sit beside
        // the free ones (Contact Form 7, Forminator, MetForm, SureForms) and
        // the shared dispatcher in src/Integrations, so they leave file by
        // file; strip.php removes register_forms_pack_abilities(), the only
        // place that constructs them.
        'src/Integrations/WPForms_Integration.php',
        'src/Integrations/Gravity_Forms_Integration.php',
        'src/Integrations/Formidable_Integration.php',
        'src/Integrations/Ninja_Forms_Integration.php',
        'src/Integrations/Fluent_Forms_Integration.php',
        'src/Tools/WooCommerce/Catalog',
        // The ACF batch write (issue #291): the op definition and its handler.
        // strip.php takes out the one line in ACF_Integration that merges it.
        'src/Integrations/ACF_Batch_Update.php',
        // The GeneratePress and Blocksy theme settings packs (issue #288).
        // strip.php takes out the two cases in Theme_Framework_Pack that
        // build them; the Kadence pack and the shared builder stay.
        'src/Integrations/Theme_Pack_GeneratePress.php',
        'src/Integrations/Theme_Pack_Blocksy.php',
        // The block suite packs (issue #287): the pro block-suites pair and
        // its helpers. strip.php removes register_block_suite_abilities(),
        // its call site, and the rollback hook wiring in boot().
        'src/Integrations/Block_Suites_Integration.php',
        'src/Integrations/Block_Suite.php',
        'src/Integrations/Block_Suite_Styles.php',
        // Stored custom CSS/JS (issue #63). The whole group is pro, so the
        // two handlers, the sanitizer, the store and the front-end renderer
        // all go. Named file by file rather than by directory because
        // Custom_Js_Guard.php STAYS, exactly as the wp-cli and PHP-snippet
        // guards do: Governance\Opt_In_Gates reports the JS opt-in gate's
        // state on every build, and a build that could not answer "is JS
        // injection enabled here" would be reporting a gate it cannot see.
        'src/Tools/CustomCode/Add_Scoped_Css.php',
        'src/Tools/CustomCode/Add_Custom_Js.php',
        'src/Tools/CustomCode/Css_Sanitizer.php',
        'src/Tools/CustomCode/Custom_Code_Store.php',
        'src/Tools/CustomCode/Custom_Code_Renderer.php',
    ],

    /**
     * Paid predicate and licensing surface, scanned over the staged PHP
     * (src/ and the plugin bootstrap). Text-level on purpose: a docblock
     * that still talks about a licence check is also a finding, because the
     * reviewer reads those too.
     *
     * pro_locked and the 'tier' => 'pro' error payload are here because they
     * live in files the strip edits in place rather than deletes: if one of
     * those edits drifted while the others still applied, no Gate::/is_pro
     * token would be left on the surviving line to catch it.
     */
    'paid_source_patterns' => [
        'Pro\\\\Gate',
        '\bis_pro\b',
        '\bGate::',
        '\bpro_active\b',
        '\bpro_locked\b',
        'can_use_premium_code',
        '[Ff]reemius',
        'WPMCP_FS_',
        'fs_dynamic_init',
        '^[[:space:]]*\'pro\',[[:space:]]*$',
        '\'tier\'[[:space:]]*=>[[:space:]]*\'pro\'',
    ],

    /**
     * Pay-to-unlock copy inside PHP *string literals*, which is the copy an
     * agent and a reviewer actually see: an Ability description goes out in
     * every tools/list response. The token patterns above cannot see it (a
     * description saying a dialect is PRO carries no Gate::/is_pro token)
     * and the document patterns below skip PHP entirely, so this is its own
     * scan over token_get_all()'s string tokens rather than whole files.
     * Scanning literals rather than lines is what keeps the docblocks that
     * legitimately discuss third-party paid plugins (WPML, Elementor Pro)
     * out of it.
     */
    'string_literal_patterns' => [
        '\(PRO',
        '[Pp]remium',
        'pro licen[sc]e',
        '[Pp]ro tier',
        'unlicensed',
        'needs? an active',
    ],

    /**
     * Pay-to-unlock copy in the non-PHP files under src/. The bundled
     * SKILL.md playbooks ship inside the zip and the agent reads them, so a
     * document promising that a capability unlocks with a licence is the
     * same guideline 5 and 9 finding as the code that used to enforce it.
     *
     * `tier: pro` is here because it is the one machine-readable
     * pay-to-unlock marker in a non-PHP file that the shipped code consumes:
     * Skill_Library parses SKILL.md frontmatter.
     *
     * vendor/ is deliberately out of scope: it is full of third-party
     * licence files.
     */
    'document_copy_patterns' => [
        'pro licen[sc]e',
        'pro[ -]tier',
        '[Pp]remium',
        'unlicensed',
        'needs? an active .* [Ll]icense',
        '^[[:space:]]*tier:[[:space:]]*pro[[:space:]]*$',
    ],

    /**
     * readme.txt gets a narrower list than src/. Guideline 5 recommends
     * "add-on plugins, hosted outside of WordPress.org, in order to exclude
     * the premium code", so the readme is the one file that is expected to
     * point factually at the off-directory add-on, and it carries the
     * required "License: GPLv2 or later" header and third-party image
     * licence URLs. What is not allowed there is copy claiming something in
     * *this* download is withheld pending payment.
     */
    'readme_copy_patterns' => [
        'pro licen[sc]e',
        'pro[ -]tier',
        'unlicensed',
        'needs? an active .* [Ll]icense',
        'only (available|unlocked) (with|by|in)',
    ],
];
