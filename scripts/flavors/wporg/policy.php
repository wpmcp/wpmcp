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
        // Execution. The guards stay (Governance\Opt_In_Gates references
        // them); the runners, the executor and their ability wrappers do not.
        'src/Tools/Cli/Run_Wp_Cli.php',
        'src/Tools/Cli/Wp_Cli_Executor.php',
        'src/Tools/Code/Run_Php_Snippet.php',
        'src/Tools/Code/Php_Snippet_Runner.php',
        // The only curl_setopt() in the tree. Page_Audit checks class_exists()
        // and falls back to wp_safe_remote_get() on its own.
        'src/Tools/Performance/Curl_Dns_Pin.php',
        // Paid ability whose handler lives inside an otherwise free directory.
        'src/Tools/Media/Stock/Insert_Stock_Image.php',
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
