# WordPress.org compliance report

Plugin: wpmcp 0.8.0
Scanned: 418 PHP files, source tree and the built free zip (`dist/wpmcp-0.8.0.zip`, 721 files)
Tooling: `tools/compliance` (25 rules, 2 profiles) plus official Plugin Check 2.0.0 and PHPCS
Guideline text: https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/ (page footer "Last updated March 11, 2026")

---

## Executive summary

**Can we submit today? No.**

The engine reports **51 blockers** against the wp.org profile, and official Plugin Check independently reports **191 errors and 120 warnings** on the built zip. Neither number is the real obstacle. Three things are, in order:

1. **The plugin is gated freemium, and guideline 5 forbids exactly that shape.** 13 paid-state branches and one paid-dependent quota (`src/Pro/Gate.php:54`, 20 snapshots free vs `PHP_INT_MAX`) ship inside a single zip that also contains all 73 pro abilities. This is not a wording problem or a settings problem. The directory build has to be a different artifact with the pro code physically absent. Full reasoning and the guideline text are in [The freemium question](#the-freemium-question) below.
2. **The readme makes a privacy claim that is false.** `readme.txt:49` says the plugin "makes no calls home unless you explicitly connect an optional service". Four hosts are contacted with no opt-in at all: `api.wordpress.org`, `api.openverse.org`, `api.pexels.com`, `api.unsplash.com`. There is also no `== External services ==` section anywhere. A false privacy statement is the kind of thing that closes a plugin after approval rather than merely rejecting it.
3. **`eval()` and `proc_open()` ship in the free zip.** `src/Tools/Code/Php_Snippet_Runner.php:85` and `src/Tools/Cli/Wp_Cli_Executor.php:42`. Both are default-off behind constants and both are well built, but they are in the artifact a reviewer unzips, and the reviewer checklist puts raw-PHP intake on the not-accepted list. The WooCommerce flavor build already strips them; the general free build does not.

Everything else is tractable in a normal work week: 18 `WordPress.WP.AlternativeFunctions` sites, one malformed ABSPATH guard, a readme title that trips the trademark check, and the escaping/DB warnings.

There is no shortcut here. Items 1 and 3 both mean the wp.org artifact has to become a separate, smaller build, which is a build-tooling change, not a patch. The good news is that `scripts/build-woo-release.sh` already demonstrates the whole pattern, tokenizer gate and all.

### Counts

| Run | blocker | likely-reject | reviewer-discretion | best-practice |
|---|---|---|---|---|
| `wporg-free`, source tree | **51** | 4 | 34 | 8 |
| `distribution`, source tree | **21** | 3 | 24 | 49 |
| `wporg-free --artifact`, built zip | **51** | 4 | 34 | 8 |

The artifact run differs from the source run by exactly two findings, both expected: it gains `vendor/` shipping without `composer.json` (`scripts/build-release.sh:20` deletes it), and it loses the `composer.json:6` Freemius dependency finding because that file is not in the zip.

`composer lint` (PHPCS, PSR-12) exits **0** with no output.

---

## Cross-validation against official Plugin Check

WP-CLI 2.12.0 and Plugin Check 2.0.0 were installed into a throwaway WordPress 6.9 running on the SQLite drop-in, under the scratch directory. Nothing on the machine was disturbed: the stopped Homebrew MySQL was left stopped, and no service was started. The built zip was unpacked into that install and checked with all categories, experimental checks, and low-severity errors and warnings enabled.

**Where both tools look at the same thing, they agree exactly.** On all 19 `AlternativeFunctions` / `ForbiddenFunctions` / `DiscouragedFunctions` sites the two sets are identical, file and line, in both directions. The trademark findings match. `mismatched_plugin_name` and `missing_composer_json_file` match.

Reconciling the differences produced real fixes in both directions. Full detail in [Engine changes this run](#engine-changes-this-run).

### Plugin Check finds these; the engine does not check for them

These are genuine plugin defects, not engine noise. They are unfixed and they are listed as findings below.

| Plugin Check code | Count | Type |
|---|---|---|
| `WordPress.Security.EscapeOutput.ExceptionNotEscaped` | 135 | ERROR |
| `WordPress.DB.DirectDatabaseQuery.DirectQuery` / `.NoCaching` | 72 | WARNING |
| `WordPress.DB.PreparedSQL.NotPrepared` | 19 | ERROR |
| `PluginCheck.Security.DirectDB.UnescapedDBParameter` | 8 / 7 | ERROR / WARNING |
| `WordPress.Security.ValidatedSanitizedInput.*` | 11 | WARNING |
| `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` | 10 | WARNING |
| `WordPress.NamingConventions.PrefixAllGlobals.*` | 7 | WARNING |
| `WordPress.Security.NonceVerification.Recommended` | 4 | WARNING |
| `WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters` | 4 | ERROR |
| `WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler` | 2 | WARNING |
| `outdated_tested_upto_header` | 1 | ERROR |
| `WordPress.WP.I18n.MissingTranslatorsComment` | 1 | ERROR |
| `WordPress.DB.SlowDBQuery` | 1 | WARNING |

The engine deliberately does not reimplement PHPCS. The right fix is structural and is recorded as **ENG-1** below: `phpcs.xml.dist:8-16` is PSR-12 only, with no WordPressCS ruleset, so none of the above runs in CI today. That is why 135 escaping errors were invisible until Plugin Check was pointed at the zip.

### The engine finds these; Plugin Check has no equivalent

Guideline-level judgement is the entire reason the engine exists. Plugin Check has no check for trialware, paid quotas, licensing SDKs, external-service disclosure, false privacy claims, pay-to-unlock copy, or plugin-install capability. All 30 blockers in `WPORG-05` / `06` / `07` / `09` / `11` / `12` are engine-only, and items 1 and 2 of the executive summary are both in that set.

---

## Findings

Severity is the `wporg-free` profile. `dist` is the same finding's severity under the `distribution` profile, which is the standard for the paid build we ship ourselves.

### Blockers

| ID | Rule | Evidence | Fix |
|---|---|---|---|
| B-01 | WPORG-05-QUOTA | `src/Pro/Gate.php:54` — `return self::is_pro() ? PHP_INT_MAX : 20;` (dist: best-practice) | Guideline 5: "Functionality may not be disabled after a trial period or quota is met." Delete the branch. The directory build enforces a flat 20 with no `is_pro()` anywhere and no unlimited path in the source. |
| B-02 | WPORG-05-QUOTA | `src/Pro/Gate.php:52` — `history_limit()` (dist: best-practice) | Same change as B-01. Also update the four call sites that consume it: `src/Safety/Safe_Mutation.php:42`, `src/Tools/Compose/Build_Page.php:227`, `src/Tools/Packages/Switch_Theme.php:62`, `src/Tools/Media/Media_Import_Snapshot.php:43`. |
| B-03 | WPORG-05-TRIALWARE | `src/Pro/Gate.php:33`, `:44`, `:47`, `:49`, `:54` (dist: best-practice) | Delete `Pro\Gate` from the directory build. It is the whole gating mechanism. |
| B-04 | WPORG-05-TRIALWARE | `src/MCP/Registrar.php:37`, `:66` (dist: best-practice) | The registrar must not receive pro abilities at all in the directory build. Prune the manifest at build time the way `scripts/flavors/woocommerce/` prunes ability groups, rather than filtering by tier at runtime. |
| B-05 | WPORG-05-TRIALWARE | `src/Admin/Ability_Grid_Page.php:171`, `:256` (dist: best-practice) | Remove the `$pro_locked` row state. A grid that renders 73 rows as "disabled: no pro license" is guideline 9's "implying users must pay to unlock included features" rendered literally. |
| B-06 | WPORG-05-TRIALWARE | `src/Tools/Compose/Build_Page.php:56` — `Gate::can_use('build-page-builder')` (dist: best-practice) | Remove the Elementor dialect from the directory build entirely, not the check around it. |
| B-07 | WPORG-05-TRIALWARE | `src/Tools/Media/Stock/Insert_Stock_Image.php:27` (dist: best-practice) | Same: the ability leaves the build. |
| B-08 | WPORG-06-LICENSING | `src/Freemius/Bootstrap.php:64` — `'anonymous_mode' => true` (dist: best-practice) | Guideline 7 requires "explicit and authorized consent... commonly done via an 'opt in' method". `anonymous_mode` suppresses the SDK's stock opt-in screen, so the SDK can reach `api.freemius.com` with no consent step the user ever saw. Set it false and ship the stock, default-off opt-in, or drop the SDK from the directory build. |
| B-09 | WPORG-06-LICENSING | `composer.json:6`, `vendor/freemius`, `src/Freemius/Bootstrap.php:127`, `:167`, `:171`, `src/Freemius/wpmcp-fs.php:21`, `:26` (dist: best-practice) | Bundling the SDK is permitted and precedented (the Plugin Review Team's own ruleset carves out `*/freemius/*` at `plugin-review.xml:20`), but only once B-03 lands, because guideline 6 forbids "a service that exists for the sole purpose of validating licenses... while all functional aspects of the plugin are included locally". Today the SDK is exactly that. |
| B-10 | WPORG-07-EXTERNAL-SERVICES | `src/Tools/Security/Integrity_Audit.php:20` — `api.wordpress.org` | Add an `== External services ==` entry: what is sent (`$wp_version`, `get_locale()`, UA `WPMCP-Security-Scanner/1.0`), when (only on the `scan-security` ability, `manage_options`), and links to the wp.org terms and privacy policy. |
| B-11 | WPORG-07-EXTERNAL-SERVICES | `src/Tools/Media/Stock/Search_Stock_Images.php:77` — `api.openverse.org` | Sharpest case of the four: keyless, needs no configuration, so nothing resembling consent happens before it fires. Disclose it, and consider making it opt-in like the keyed providers. |
| B-12 | WPORG-07-EXTERNAL-SERVICES | `src/Tools/Media/Stock/Search_Stock_Images.php:107` — `api.pexels.com` | Disclose. Note the decrypted BYO key travels in the `Authorization` header (`:110`); say so. |
| B-13 | WPORG-07-EXTERNAL-SERVICES | `src/Tools/Media/Stock/Search_Stock_Images.php:137` — `api.unsplash.com` | Disclose, same as B-12 (`:140`). |
| B-14 | WPORG-09-PRIVACY-CLAIM | `readme.txt:49` — "makes no calls home unless you explicitly connect an optional service" (dist: blocker) | The statement is false for the four hosts above, none of which require connecting anything. Rewrite it to describe what actually happens. The flavor readme at `scripts/flavors/woocommerce/readme.txt:40-42` is worse ("No telemetry. The plugin makes no calls home.") and those tools ship in that build too. |
| B-15 | WPORG-12-README | `readme.txt:1` — no `== External services ==` section (dist: best-practice) | Add the section. This is the single most common rejection reason for a tool plugin, and it is cheap. |
| B-16 | WPORG-09-EXEC | `src/Tools/Code/Php_Snippet_Runner.php:85` — `eval($code)` (dist: best-practice, allowlisted) | Must not be in the directory zip. Reuse the `scripts/build-woo-release.sh:45-51` removal list and the `:66-79` tokenizer gate for the wp.org build. |
| B-17 | WPORG-09-EXEC | `src/Tools/Cli/Wp_Cli_Executor.php:42` — `proc_open($argv, ...)` (dist: best-practice, allowlisted) | Same. Array form, so no shell is spawned, but the construct still ships. |
| B-18 | WPORG-11-ADMIN-NAG | `src/Tools/Compose/Build_Page.php:57` — "is a PRO feature" in a thrown message (dist: blocker) | Guideline 9 prohibits "implying users must pay to unlock included features". Goes away with B-06. |
| B-19 | WPORG-17-TRADEMARK | `readme.txt:1` — "WordPress" mid-title (dist: reviewer-discretion) | Plugin Check confirms: "contains the restricted term 'wordpress' which cannot be used at all in your plugin name". Rewrite the title without it. |
| B-20 | WPORG-17-TRADEMARK | `readme.txt:3` — tag `claude` (dist: reviewer-discretion) | Third-party trademark as a tag. The list is at the 5-tag maximum anyway, so removing it costs nothing. |
| B-21 | PCP-DIRECT-FILE-ACCESS | `src/Plugin.php:241` — `if (! defined('ABSPATH') && ! defined('WPMCP_TESTING')) {` (dist: blocker) | Confirmed by Plugin Check: "PHP file should prevent direct access". The extra conjunct matches none of `Direct_File_Access_Check`'s five accepted patterns, so the file reads as unguarded. Emit a bare `if (! defined('ABSPATH')) { exit; }` and handle the test bypass another way. |
| B-22 | PCP-FORBIDDEN-FUNCTIONS | 18 sites, all confirmed by Plugin Check (dist: blocker) | `unlink` ×7 → `wp_delete_file()`; `fclose` ×4, `fopen`, `fread`, `readfile`, `rmdir` → `WP_Filesystem`; `parse_url` → `wp_parse_url()`; `curl_setopt` at `src/Tools/Performance/Page_Audit.php:94` → HTTP API, though the DNS pinning there is deliberate SSRF defence and may warrant an explanation instead; `wp_get_sidebars_widgets()` at `src/Tools/Structure/List_Sidebar_Widgets.php:27` → read `$wp_registered_sidebars` directly. Full list in the engine output. |
| B-23 | PC-only | `readme.txt` — `Tested up to: 6.9`, current WordPress is 7.0 | Plugin Check errors: "your plugin will not show up in searches". Bump after testing. The engine cannot see this offline; it is a live-version check by nature. |
| B-24 | PC-only | 135 × `WordPress.Security.EscapeOutput.ExceptionNotEscaped` across 56 files, worst `src/Tools/Blocks/Block_Tree.php` (15) and `src/Safety/Rollback_Service.php` (13) | Every `throw new \Exception("...$var...")`. Escape the interpolated value or add a scoped `phpcs:ignore` with a justification. Bulk mechanical change; see ENG-1 for why it went unnoticed. |
| B-25 | PC-only | 19 × `WordPress.DB.PreparedSQL.NotPrepared`, worst `src/Tools/Database/Database_Guard.php` (6) and `src/Safety/Snapshot_Store.php` (6); plus 8 × `PluginCheck.Security.DirectDB.UnescapedDBParameter` at ERROR | Interpolated identifiers. The read-only validator at `src/Tools/Database/Database_Guard.php:146` and the backtick stripping are real mitigations, but the sniff is an error and reviewers weight DB findings heavily. Prepare, or document each site. |
| B-26 | PC-only | 4 × `WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters` — `src/Tools/WidgetBuilder/Widget_Registry.php:52`, `src/Tools/WidgetBuilder/Widget_Spec_Store.php:85`, `src/Tools/Elementor/List_Code_Snippets.php:24`, `src/Tools/BlockBuilder/Block_Spec_Store.php:81` | Drop `suppress_filters`. |
| B-27 | PC-only | `WordPress.WP.I18n.MissingTranslatorsComment` — `src/Admin/Audit_Log_Page.php:50` | Add the `/* translators: */` comment. |
| B-28 | WPORG-09-EXEC | `src/Tools/WidgetBuilder/Compiler/Compiled_Widget_Manifest.php` — `require_once` of plugin-generated PHP from `wp-content/wpmcp-widgets` (dist: best-practice) | Third execution site, added by issue #72. Contains none of the scanned constructs, so the rule does not fire, but it is a code-generation feature and must be treated like B-16/B-17: PRO-only and default-off behind `wpmcp_enable_widget_compiler`, and it never reaches the directory zip: `scripts/flavors/wporg/strip.php:60` already removes `src/Tools/WidgetBuilder` whole, so the compiler leaves with it. Verified rather than assumed, because the strip list is by path and a compiler moved elsewhere would silently start shipping. |

### Likely reject

| ID | Rule | Evidence | Fix |
|---|---|---|---|
| L-01 | PCP-INPUT-SANITIZATION | `src/Admin/Audit_Log_Page.php:106` — `esc_attr((string) ($_GET['page'] ?? 'wpmcp-audit-log'))` (dist: likely-reject) | Confirmed by Plugin Check three times over on this line (`MissingUnslash`, `InputNotSanitized`, `NonceVerification`). Escaped on output so not exploitable, but add `sanitize_key(wp_unslash(...))`. |
| L-02 | PCP-ESCAPING | `src/Tools/WidgetBuilder/Dynamic_Widget.php:97` (dist: likely-reject) | Plugin Check reports `OutputNotEscaped` here too, because it does not follow calls. The code is correct: `Widget_Renderer::render()` at `src/Tools/WidgetBuilder/Widget_Renderer.php:19` escapes every interpolated value by control type. Fix is a `phpcs:ignore` recording that, not more escaping. |
| L-03 | PCP-FORBIDDEN-FUNCTIONS | `src/Tools/Code/Php_Snippet_Runner.php:47` — `set_time_limit()` (dist: likely-reject) | Confirmed by Plugin Check (`Squiz.PHP.DiscouragedFunctions`). Moot once B-16 removes the file from the directory build. |
| L-04 | WPORG-12-README | `readme.txt:1` — title differs from the `Plugin Name: wpmcp` header (dist: best-practice) | Confirmed by Plugin Check (`mismatched_plugin_name`). Make the header carry the real display name; fold into the B-19 retitle. |

### Reviewer discretion

| ID | Rule | Evidence | Note |
|---|---|---|---|
| R-01 | WPORG-08-PLUGIN-INSTALL | 19 sites across `src/Tools/Packages/*` and `src/Plugin.php:1281-1290` (dist: reviewer-discretion) | The reviewer checklist says "A plugin can be required but not included or auto-installed", and guideline 8 forbids "serving updates or otherwise installing plugins". These install from wp.org through core's own `Plugin_Upgrader` on an explicit `manage_options` request, which is the defensible reading, but it is the third most likely thing to sink the submission and it deserves a prepared answer in the submission notes. |
| R-02 | WPORG-07-EXTERNAL-SERVICES | 7 dynamic destinations: `src/Cloud/Cloud_Client.php:58`, `src/Tools/Performance/Page_Audit.php:102`, `src/Tools/Media/Remote_Image_Guard.php:111`, `src/Tools/Media/Stock/Search_Stock_Images.php:164`, `src/Tools/Analytics/Analytics_Adapter.php:482`, `:527`, `src/Connect/Connection_Tester.php:23` | Not statically resolvable. Three are loopback in practice (`Analytics_Adapter` ×2 go to the local REST API for Site Kit, `Connection_Tester` to `home_url()`); `Page_Audit` takes a caller-supplied URL behind a private-IP refusal at `:79`; `Remote_Image_Guard` is limited to five allowlisted CDNs at `:36`. Each needs a sentence in the readme describing when it fires and how far it can reach. |
| R-03 | WPORG-07-EXTERNAL-SERVICES | `src/Tools/Media/Stock/Search_Stock_Images.php:122` (`www.pexels.com`), `:152` (`unsplash.com`) | Linked, never requested: both are `license_url` values in the returned result array. Not a call, but they are the services' licence pages and belong in the B-15 disclosure entries. |
| R-04 | WPORG-05-TRIALWARE | `src/Tools/Context/Get_Site_Context.php:72` — `'pro_active' => Gate::is_pro()` | Reports paid state to the agent; locks nothing. Disappears with B-03. |
| R-05 | WPORG-06-LICENSING | `src/Freemius/Bootstrap.php:3`, `src/Freemius/wpmcp-fs.php:4`, `src/Pro/Gate.php:22`, `wpmcp.php:15` | Vendor references to confirm once B-03 and B-09 land. |
| R-06 | WPORG-08-UPDATER | `vendor/freemius/wordpress-sdk/includes/class-freemius.php` references `site_transient_update_plugins` | `Plugin_Updater_Check` is a static regex with **no** vendor carve-out (the `*/freemius/*` exclusion at `plugin-review.xml:20` applies only to PHPCS). Expect a Plugin Check error at submission. Strip the SDK's updater files from the directory build or prepare the explanation. Our local run did not surface it because Plugin Check excludes `vendor/` by default in the CLI; the wp.org-side run does not necessarily. |
| R-07 | PC-only | 7 × `WordPress.NamingConventions.PrefixAllGlobals` — `src/Tools/I18n/I18n_Adapter.php:102`, `:161`, `:200`, `:209`, `src/Tools/Cache/Page_Cache_Detector.php:140`, `src/Tools/Security/Hardening_Audit.php:161` | Non-prefixed hook names. Also relevant to the flavor build: `scripts/build-woo-release.sh:61` rewrites the text domain but not option, transient or hook prefixes, so the two flavors collide if both are installed. |
| R-08 | PC-only | 2 × `error_log_set_error_handler` — `src/Tools/Export/Export_Content.php:63`, `:67` | `WordPress.PHP.DevelopmentFunctions`. |
| R-09 | PC-only | 72 × `WordPress.DB.DirectDatabaseQuery` (DirectQuery + NoCaching), 1 × `SlowDBQuery` | Warnings. Add caching or scoped ignores with justifications. |

### Best practice

| ID | Rule | Evidence | Note |
|---|---|---|---|
| P-01 | PCP-I18N | `src/Plugin.php:426`, `:437`, `:449`, `:462`, `:474` | Admin menu labels passed to `add_menu_page`/`add_submenu_page` as raw strings. Wrap in `__()`. |
| P-02 | WPORG-17-TRADEMARK | `wpmcp.php:3` (slug and name), `readme.txt:1` | The `wp` term. Plugin Check confirms all three at warning. Its own list comments the entry "it's allowed, but shows a warning". Tolerated in practice; nothing to do. |
| P-03 | — | No `Domain Path` header in `wpmcp.php`, no `languages/` directory | Defensible for a wp.org build (JIT loading since WP 4.6) but means a self-hosted `.mo` never loads. |
| P-04 | — | `scripts/build-woo-release.sh:66-79` tokenizer gate covers `eval`, `proc_open`, `shell_exec`, `passthru`, `popen` | Plugin Check's forbidden list also has `move_uploaded_file`, `create_function`, `str_rot13`. Add them. The gate also only walks `$STAGE/src`, not `$STAGE/vendor` or the flavor main file. |

### Engine and tooling

| ID | Evidence | Fix |
|---|---|---|
| ENG-1 | `phpcs.xml.dist:8-16` is PSR-12 only | This is why 135 escaping errors, 19 SQL errors and 11 sanitization warnings were invisible. Add `wp-coding-standards/wpcs` and a second ruleset mirroring `phpcs-rulesets/plugin-review.xml`, and add a Plugin Check job to CI. The compliance engine covers the guideline layer that Plugin Check cannot; it is not a substitute for the sniff layer. |
| ENG-2 | `.github/workflows/ci.yml`, `compliance` job | The two check steps carry `continue-on-error: true`. Deleting those two lines turns the job into a gate. Leave them until the blockers above are cleared, then remove them. |

---

## The freemium question

This is the decision that determines how much has to be stripped, so it gets the guideline text verbatim.

### What guideline 5 actually says

> "Plugins may not contain functionality that is restricted or locked, only to be made available by payment or upgrade. Functionality may not be disabled after a trial period or quota is met. In addition, plugins that provide sandbox only access to APIs and services are also trial, or test, plugins and not permitted."

Guideline 9 repeats it from the other side, in its list of prohibited behaviour:

> "Implying users must pay to unlock included features"

And guideline 6 closes the licensing-service escape hatch:

> "A service that exists for the sole purpose of validating licenses or keys while all functional aspects of the plugin are included locally is not permitted."

### What is permitted

Guideline 5, same section:

> "Paid functionality in services is permitted (see guideline 6: serviceware), provided all the code inside a plugin is fully available. We recommend the use of add-on plugins, hosted outside of WordPress.org, in order to exclude the premium code."

> "Attempting to upsell the user on ad-hoc products and features is acceptable, provided it falls within bounds of guideline 11 (hijacking the admin experience)."

Reviewer's Handbook, "Selling, credits, and links":

> "Upselling is permitted from plugin settings screen or a link on their entry on the plugin list page."

The 2018 clarification that produced the current wording of guideline 5 (Mika Epstein, Plugin Review Team lead) states the intent:

> "Historically we've not permitted test or trial plugins that arbitrarily limit usage, and then upsell, to be included in the directory. The primary reason we don't permit this is that locking people down to a specific number of (say) images is foolish and a pointless endeavour. People can, and will, fork your locked plugin and unlock it, as well they should. That said, we've always allowed (and will continue to) plugins that offer a free limited service (think Akismet for a good example)."

### The line

The test is not "is there an upsell" and not "is there a licensing SDK". The test is: **does the plugin's own shipped PHP contain the paid capability, gated?**

- Upsell copy with no gated code: **allowed**, bounded by guideline 11.
- Code in the zip, unlocked by licence, payment or quota: **prohibited**, guideline 5 and guideline 9.
- Limit enforced by a remote service the plugin is a client of: **allowed**, guideline 6, Akismet being the named example.

### Our conclusion

**As shipped, wpmcp 0.8.0 is in the prohibited category and would be rejected on guideline 5.**

The specifics:

- `src/Pro/Gate.php:54` reads `return self::is_pro() ? PHP_INT_MAX : 20;`. The unlimited-history code path is present in the zip and switched off by payment. That is "functionality may not be disabled after a... quota is met", verbatim, and fork-and-flip-the-constant is precisely what the 2018 post says users are entitled to do.
- 13 further paid-state branches (B-03 through B-07) gate capabilities whose implementations are in the same zip.
- 73 pro abilities out of 260 are registered-or-not by tier at `src/MCP/Registrar.php:37`, and `src/Admin/Ability_Grid_Page.php:171` renders the withheld ones as "disabled: no pro license" — guideline 9's "implying users must pay to unlock included features", literally.
- The Freemius SDK in this configuration unlocks only locally-present code, which is guideline 6's named prohibition, and `anonymous_mode => true` at `src/Freemius/Bootstrap.php:64` additionally bypasses the consent step guideline 7 requires.

The compliant restructuring, and the one we should take:

**Strip, do not gate.** The wp.org build becomes a separate artifact with no `Pro\Gate`, no pro ability sources, no paid-state branch and no paid-dependent cap. The snapshot limit becomes a flat 20 that is a product decision rather than a lock. Guideline 5 explicitly recommends this shape: "the use of add-on plugins, hosted outside of WordPress.org, in order to exclude the premium code". Upsell copy on our own settings screen and the plugins-list row action stays permitted, and we currently have none, so there is nothing to remove there.

The alternative, moving the limit server-side under guideline 6, is available but worse for us. It would need the snapshot store to genuinely live on our API, and guideline 6 separately prohibits "creation of a service by moving arbitrary code out of the plugin so that the service may falsely appear to provide supplemented functionality" — which is exactly how a reviewer would read relocating snapshot storage to dodge guideline 5.

Keeping the Freemius SDK in the directory build is defensible once the gating is gone. The Plugin Review Team's own ruleset carves out `*/freemius/*` by name (`plugin-review.xml:20`, alongside cmb2 and redux-framework), so the SDK is expected in directory-hosted plugins. The conditions are: the opt-in must be the stock, default-off screen (fix B-08), the licence check must unlock nothing that ships (fix B-03), and the SDK's updater must be neutralised (R-06).

Two slugs is the clean end state: the free plugin on wp.org, and the pro add-on distributed off-directory. Note guideline 3 then applies — the directory copy has to be released in lockstep, not left stale.

---

## Engine changes this run

Every finding in this report was verified by reading the code before it went in. Verification removed four false positives and exposed one false negative and one coverage gap. Each is fixed in the engine with tests both ways, so the same mistake cannot come back. Test count went from 117 to 140 (819 assertions); the full suite is 2366 tests, 9 skipped, 0 failures; `phpcs` exits 0.

| # | Was reported | Verdict | Engine fix |
|---|---|---|---|
| FP-1 | `PCP-LOCALHOST` blocker ×2 at `src/Auth/Redirect_Uri_Validator.php:21` for `['127.0.0.1', '::1', 'localhost']` | **False positive.** `LocalhostSniff::process_token()` returns early unless the string contains `//`, then matches `#(https?:)?\/\/(localhost\|127.0.0.1\|(.*\.local(host)?))\/#i`. A bare host name never matches. Confirmed empirically by running the real sniff: it stays silent on this line. And on the merits, an OAuth loopback allowlist is an RFC 8252 requirement for native clients, not a development leftover. | `Localhost_Rule` now mirrors the sniff's regex and its `//` early return exactly. Verified against the real sniff across 9 URL shapes, 9/9 agreement, including that a port defeats it (`http://localhost:8080/api` is clean, `http://localhost/api` is not). |
| FP-2 | `PCP-PHP-HYGIENE` blocker at `src/Connect/Bundle_Builder.php:105` for `<<<'JS'` | **False positive.** PHP's native tokenizer reports heredoc and nowdoc alike as `T_START_HEREDOC`, but PHPCS re-emits a nowdoc opener as its own `T_START_NOWDOC` (`Tokens.php:64`, `PHP.php:1109`), and `HeredocSniff::register()` returns only `T_START_HEREDOC`. Plugin Check does not flag this file at all. | `Php_Hygiene_Rule` reads the opener text: `<<<'ID'` is a nowdoc and is not reported. Double-quoted and bare heredoc still are. |
| FP-3 | `WPORG-07-EXTERNAL-SERVICES` blocker ×2 for `www.pexels.com` and `unsplash.com` | **False positive as stated.** Both are `license_url` values in a returned result array. No request is made to either. Calling them "reachable" at blocker severity is a claim the code does not support. | `Http_Index` now classifies each host as `requested` or `linked`, by whether the URL literal ever appears outside an array-element value position (`Source_File::is_array_element_value()`). Link-only hosts report at reviewer-discretion with accurate wording, and are not dropped, because a licence page still belongs in the disclosure section. |
| FP-4 | `WPORG-09-PRIVACY-CLAIM` named `unsplash.com` and `www.pexels.com` among the contradicting hosts | **False positive**, same root cause. | `Privacy_Claim_Rule` now uses `Http_Index::requested_hosts()`. The finding names the four hosts actually contacted. |
| FP-5 | `PCP-FILE-HYGIENE` blocker for `.phpunit.result.cache` on a source-tree scan | **False positive.** The file is gitignored and never copied by `scripts/build-release.sh`. A checkout is supposed to contain dotfiles. | Hidden-file detection is now artifact-only, matching the rule's own docblock and the existing `.dist` carve-out. Still a blocker inside a zip. |
| FP-6 | `WPORG-05-TRIALWARE` blocker at `src/Tools/Context/Get_Site_Context.php:72` claiming the predicate "decides behaviour" | **Message was false.** `'pro_active' => Gate::is_pro()` reports state; it branches nothing. | `Paid_Gating_Rule` distinguishes value position from branch position. Value position reports at reviewer-discretion with accurate wording. Assignment (`$is_pro = Gate::is_pro()`) stays a blocker, because that exists to be branched on. |
| FP-7 | `PCP-ESCAPING` at `Dynamic_Widget.php:97` claiming "no escaping function" | **Not a false positive** — Plugin Check reports `OutputNotEscaped` on the same line, twice — **but the message was wrong.** The renderer does escape. | `Output_Escaping_Rule` resolves one level of call within the tree, class-qualified, and refuses to guess when a name is ambiguous. The finding now names `Widget_Renderer::render()` and its location and says the fix is a justified `phpcs:ignore`. |
| FN-1 | Nothing reported at `src/Plugin.php:241` | **False negative.** The rule grepped for `defined('ABSPATH')` anywhere. `Direct_File_Access_Check` accepts five exact shapes, and `if (! defined('ABSPATH') && ! defined('WPMCP_TESTING'))` matches none. Plugin Check reports the file as unprotected. | `Direct_File_Access_Rule` now carries all five accepted patterns verbatim, strips comments first as the checker does, and distinguishes "no guard" from "guard the checker will not accept". |
| GAP-1 | 10 `AlternativeFunctions` sites reported; Plugin Check found 19 | **Coverage gap.** The list was partial, so `fclose`, `fread`, `readfile`, `rmdir` and `curl_setopt` read as clean. | `Forbidden_Functions_Rule` now carries the `file_system_operations` group verbatim from `AlternativeFunctionsSniff`, less the two names `plugin-check.ruleset.xml` excludes, plus the `curl_*` wildcard with `curl_version` allowed. An intermediate version over-reached with `copy()` and `fseek()`, which are **not** in the sniff; those were removed and a test now pins them as clean. Result: **19 sites, exact 1:1 match with Plugin Check in both directions.** |

Every one of the eight has a fires test and a does-not-false-positive test.

---

## How to re-run this

### The engine

```bash
composer compliance          # distribution profile, source tree, exits 1 on any blocker
composer compliance:wporg    # wporg-free profile, source tree

php tools/compliance/bin/compliance.php --help
php tools/compliance/bin/compliance.php --list-rules
php tools/compliance/bin/compliance.php --explain=WPORG-05-TRIALWARE

# one pack, or one rule
php tools/compliance/bin/compliance.php --profile=wporg-free --no-artifact --pack=licensing
php tools/compliance/bin/compliance.php --profile=wporg-free --no-artifact --rule=WPORG-07-EXTERNAL-SERVICES

# machine readable
php tools/compliance/bin/compliance.php --profile=wporg-free --no-artifact --format=json
php tools/compliance/bin/compliance.php --profile=wporg-free --no-artifact --format=markdown
```

Against the built zip, which is the run that matters for submission:

```bash
bash scripts/build-wporg-release.sh
rm -rf build/wporg && mkdir -p build/wporg
unzip -q dist/wpmcp-*.zip -d build/wporg
php tools/compliance/bin/compliance.php --profile=wporg-free --artifact --path=build/wporg/wpmcp
```

Exit codes: 0 nothing at or above `--fail-on` (default `blocker`), 1 findings at or above it, 2 usage error.

### Tests and lint

```bash
./vendor/bin/phpunit --testsuite free --filter Compliance   # 140 tests, 819 assertions
./vendor/bin/phpunit                                        # full suite, 2366 tests
composer lint                                               # PHPCS, covers src and tools/compliance
```

### Official Plugin Check

No MySQL and no Local site needed. This builds a throwaway WordPress on the SQLite drop-in inside a scratch directory and leaves the machine alone.

```bash
SCRATCH=/tmp/wpmcp-pc && mkdir -p "$SCRATCH" && cd "$SCRATCH"

wp core download --version=6.9 --force --skip-content
wp config create --dbname=wpcheck --dbuser=root --dbpass= --skip-check --force

curl -sSL -o /tmp/sqlite.zip https://downloads.wordpress.org/plugin/sqlite-database-integration.zip
unzip -q -o /tmp/sqlite.zip -d wp-content/plugins/
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SCRATCH/wp-content/plugins/sqlite-database-integration#" \
    -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
    wp-content/plugins/sqlite-database-integration/db.copy > wp-content/db.php

wp core install --url=http://localhost:8899 --title=pccheck \
  --admin_user=admin --admin_password=admin --admin_email=a@example.com --skip-email
wp plugin install plugin-check --activate

unzip -q -o /path/to/wpmcp/dist/wpmcp-0.8.0.zip -d wp-content/plugins/
wp plugin check wpmcp --format=csv --fields=file,line,column,type,code,message \
  --include-experimental --include-low-severity-errors --include-low-severity-warnings
```

Two things to know about the CLI run. It excludes `vendor/` by default, so the bundled Freemius SDK is not scanned and R-06 will not appear locally even though it may at submission. And the CSV output repeats a header per file, so parse it by skipping `FILE:` lines and repeated header rows rather than handing it straight to a CSV reader.

### CI

`.github/workflows/ci.yml` has a `compliance` job that runs the distribution profile on the source tree, then builds the zip and runs `wporg-free --artifact` on it, and uploads a markdown report on every run. Both check steps carry `continue-on-error: true` with a comment saying so. Deleting those two lines turns the job into a hard gate; do that once the blockers above are cleared. The engine already exits 1 on any blocker, so nothing else has to change.
