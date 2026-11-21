# WordPress.org submission runbook

Everything here is prepared up to the upload. **Nothing has been
submitted.** The submission itself needs the owner's go. Nothing in this
document should be read as "filed" or "in review".

**Lead listing (owner decision, 20 Nov 2026): Safe MCP for WooCommerce**, slug
`safe-mcp-for-woocommerce`, from `scripts/build-woo-release.sh`. Section 0 is
its runbook. The general `wpmcp` listing (sections 1 to 7) waits until the
WooCommerce listing is approved: the Plugin Developer FAQ allows generally one
plugin in review at a time.

Current version: 1.1.0 (`WPMCP_VERSION` in `wpmcp.php`). The version numbers
in the examples below are placeholders for whatever that constant says when
you build.

---

## 0. Lead listing: Safe MCP for WooCommerce (issue #1140)

Plan and owner decisions:
`docs/superpowers/specs/2026-11-20-wporg-submission-plan.md`.

**What the zip is.** `composer build:woo` writes
`dist/safe-mcp-for-woocommerce-<version>.zip`: the shared directory strip
(section 1 applies to it unchanged), the WooCommerce flavor whitelist, and a
narrowing to store tools plus the safety core. The Plugin Developer FAQ
(updated 10 Nov 2026) does not accept new plugins that allow arbitrary code
insertion or execution, naming "PHP or JavaScript editors, file managers, and
AI tools that generate code" intended to run on the site. The compliance
engine does not encode that rule, so the flavor does. Left out of this zip,
kept in the direct download and Pro:

* file tools: `write-file`, `edit-file`, `delete-file`, and the read-only
  `read-file`, `list-directory`, `search-files` (the FAQ names file managers)
* raw SQL and database writes: `query`, `insert-row`, `update-rows`,
  `delete-rows` (the read-only `list-tables` and `describe-table` stay)
* the PHP snippet store tools and `validate-php-snippet`
* code snippet creation (`create-code-snippet`, Elementor custom code)
* site migration and site-wide search and replace: `push-site-archive`,
  `receive-site-archive`, `rewrite-site-urls`, `find-replace-content`
* the file and salt actions of `incident-response`: `rotate-salts` (it
  rewrites `wp-config.php`), `reinstall-core-files`, and the quarantine family
  (`quarantine-file`, `list-quarantine`, `restore-quarantined-file`,
  `purge-quarantined-file`); its credential and session actions stay
* the cloud announcements feed and the cloud client (`src/Cloud`)
* the upgrade banner (none in the first submission; a dismissible banner on
  WP MCP's own screens is planned for the first update after approval)

`scripts/flavors/woocommerce/narrowed.php` lists all of it and
`scripts/flavors/woocommerce/assert-narrowed.php` fails the build, over the
stage and again over the extracted zip, if any of it comes back. An agent that
calls one of those tools, or one of those actions, by name gets a
`not_in_this_build` refusal (`src/MCP/Not_In_Build.php`, added to this zip
only) rather than "Tool not found" or a schema error.

**Account:** `fahdi` on wordpress.org (owner decision D1: submit from it as
it is). The flavor readme's `Contributors:` line is `fahdi`.

**Slug to request:** `safe-mcp-for-woocommerce` (owner decision, 20 Nov
2026, replacing `wp-mcp-for-woocommerce`, whose "wp" prefix drew Plugin
Check's `trademarked_term` warning). Free on the plugins API on 20 Nov 2026; `mcp-for-woocommerce` belongs to another author, so expect the
similar-name warning at submission. The zip's directory and the text domain
are both `safe-mcp-for-woocommerce`, and so is the main file,
`safe-mcp-for-woocommerce.php` (the compliance engine reads the slug from the
main file's name and checks the text domain against it).

**Listing name** (the `Plugin Name` header and the readme title):

```
Safe MCP for WooCommerce
```

**Listing tags:** `ecommerce, mcp, ai, ai agent, automation`
(`scripts/flavors/woocommerce/readme.txt`).

**Steps:** the same as section 4, with `composer build:woo` and its zip in
place of `composer build:wporg`, and the slug above on the confirmation
screen. Run Plugin Check on a live install with WooCommerce active first.

**Reviewer question to expect:** "Can this read or edit files, run SQL or
execute code?" No. The zip has no file browser or editor, no SQL console, no
database row writes, no site-wide search and replace, no snippet store, no
migration import and no `eval`/`proc_open` (token gate, section 5.4). File
changes that remain are WordPress's own: media uploads, and plugin and theme
installs and updates from WordPress.org slugs (section 5.5).

---

## 1. What was decided, and why

The question was whether the directory build could keep the licensing SDK and
the free/pro split, or whether the paid tier had to come out. The guideline
text settles it in two directions at once, so both halves matter.

**A licensing SDK is permitted.** Plugin Check's own review ruleset excludes
the Freemius path by name (`phpcs-rulesets/plugin-review.xml:20`,
`<exclude-pattern>*/freemius/*</exclude-pattern>`), and guideline 5 says
plainly that "attempting to upsell the user on ad-hoc products and features is
acceptable". Nothing about having a paid product is disqualifying.

**Gating shipped code is not permitted.** Guideline 5:

> "Plugins may not contain functionality that is restricted or locked, only to
> be made available by payment or upgrade. Functionality may not be disabled
> after a trial period or quota is met."

and guideline 9's prohibited list includes, verbatim:

> "Implying users must pay to unlock included features"

`Pro\Gate::history_limit()` returned `PHP_INT_MAX` for paying sites and `20`
for everyone else, and `MCP\Registrar` withheld 73 abilities whose code was in
the same zip. Both are the prohibited shape exactly: the capability ships and a
payment switches it on. Guideline 5 also names the remedy:

> "We recommend the use of add-on plugins, hosted outside of WordPress.org, in
> order to exclude the premium code."

So the decision is: **strip, do not gate, and do not gut.** The directory build
loses the licence check and the paid abilities' source, and keeps everything
else. Concretely:

* `src/Pro/` and `src/Freemius/` are not in the zip, and
  `freemius/wordpress-sdk` is removed from `composer.json` before `vendor/` is
  packaged. There is no licence check in the build, so guideline 6's ban on "a
  service that exists for the sole purpose of validating licenses" has nothing
  to bite on.
* Snapshot retention is one flat number for every install
  (`Snapshot_Store::history_limit()`, default 20), filterable through
  `wpmcp_snapshot_history_limit` at no cost. A cap nobody pays to lift is a
  product decision, not a lock.
* The 73 pro abilities are absent, not disabled: their registrations and their
  handler files are removed at build time (53 unreferenced Elementor handlers
  are swept out by reachability, plus the Cloud, Analysis, Builders,
  WidgetBuilder and BlockBuilder trees whole).
* The Elementor dialect of `build-page` was PRO-gated. The gate is not what
  left: the dialect is not in this build at all. `Elementor_Composer` is
  deleted, `Page_Spec::DIALECTS` is `['gutenberg']` so an `elementor` spec is
  rejected by the validator before any handler runs, and every builder branch,
  reply key, docblock and ability-description clause that named the dialect is
  removed with it. The dialect ships with the off-directory add-on, which is
  guideline 5's own recommended remedy. Verified by
  `tests/free/Flavors/WporgStripBuildPageTest.php`, which strips a staged tree
  and then runs the stripped validator.
* `eval()` and `proc_open()` were both pro-tier abilities, so they leave with
  the rest of the paid tier. The directory build contains no execution
  construct at all, checked at token level by the build script.
* No pay-to-unlock copy survives anywhere, and the build script proves it
  rather than asserting it: alongside the identifier greps for `Gate::`,
  `is_pro` and `Freemius`, it greps the staged tree for the prose itself
  ("pay to unlock", "pro licence", "upgrade to pro", "upsell") and fails the
  build on a hit, so an unstripped comment or admin string cannot slip through
  a check that only looks for code. Two screens carried such copy: the ability
  grid rendered locked rows reading "disabled: no pro license", which issue
  #161 removed from the source outright so the grid now lists only the
  abilities the install would actually register; and the skills screen's
  "Listed, body needs a Pro licence" cell, an unreachable branch in this build
  (`Skill_Library::is_locked()` is collapsed to `false`) that the strip script
  now deletes rather than leaves dead.

**Divergence from the marketing plan worth flagging.** Phase 1 item 10 says to
"hard-code the unlimited value" for snapshot history. The build ships a flat
20 with a free filter instead. Unlimited retention with no prune would grow the
snapshots table without bound, which is a real defect rather than a compliance
question; a flat filterable cap satisfies guideline 5 the same way (the
rulebook's own wording: "hard limit, no gate, no pro code") without introducing
one. Change the constant if you disagree, it is a one-line product call:
`Snapshot_Store::DEFAULT_HISTORY_LIMIT`.

---

## 2. Account

The account is `fahdi` on wordpress.org, and the owner decided (20 Nov 2026)
to submit from it as it is, with no email change at this stage. Both flavor
readmes say `Contributors: fahdi`. The Plugin Developer FAQ notes that
"submitting your official plugin with a user that has a gmail address is
likely to be flagged for trademark infringement", so if a reviewer raises it,
moving the account's email to a company address is the answer.

---

## 3. What to paste

**Slug to request:** `wpmcp`

The submission form derives an initial slug from the plugin name, so it will
propose something like `wp-mcp-mcp-server-with-snapshot-undo-for-ai-agents`.
Change it on the confirmation screen before review starts. The slug can be
changed **exactly once pre-review and never after approval**, so spend that one
change here and check the result before doing anything else. The plugin's text
domain is `wpmcp` and the main file is `wpmcp.php`, so the final slug has to be
`wpmcp` for the text domain to match it.

**Plugin name** (identical in `wpmcp.php` and the readme title, which Plugin
Check requires):

```
WP MCP - MCP Server with Snapshot Undo for AI Agents
```

No "WordPress" anywhere in it: guideline 17 restricts the term, and Plugin
Check's `Trademarks_Check` matches it anywhere in the name, not just at the
start. "WP" itself trips a warning that Plugin Check's own source annotates
"it's allowed, but shows a warning"; thousands of `wp-*` slugs exist and it is
tolerated in practice.

That exact string is the `Plugin Name` header and the readme title in both
`scripts/flavors/wporg/wpmcp.php` / `readme.txt` (the files this zip is staged
from) and the root `wpmcp.php` / `readme.txt` that the self-hosted zip ships,
so the same install keeps its name whichever artifact it came from. The
WooCommerce vertical deliberately carries its own name, `WP MCP for
WooCommerce`, because it is a separate slug; the trailing "for WooCommerce" is
the only form guideline 17 permits for that mark.
`tests/free/Release/ReleaseHeadersTest.php` fails if any of the three pairs
drifts apart or a name picks up a term `Trademark_Rule` rejects, and
`scripts/build-woo-release.sh` runs that same rule over the pair it stages at
build time.

**Short description** (145 characters, plain text, no markup):

```
Turn this site into an MCP server for AI agents. Every write takes a snapshot first, so you can roll any change back from the History screen.
```

**Tags** (exactly five, the maximum guideline 12 allows):

```
mcp, mcp server, ai agent, automation, undo
```

"claude" was removed. It is a third-party trademark, and guideline 12 bars
competitor and vendor names as tags. The tag list was already at five, so
dropping it cost nothing.

The block above is what ships. `tests/free/Release/ReleaseHeadersTest.php`
reads it from this file and asserts that `scripts/flavors/wporg/readme.txt`,
the readme `scripts/build-wporg-release.sh` stages as the listing, carries it
verbatim, so this document and the listing cannot drift apart. The same test
gates the tag lists of the root `readme.txt` (the general GitHub zip) and the
WooCommerce flavor for the five-tag maximum and for vendor marks. That cover
matters because the compliance engine only reads the readme at the root of the
tree it scans, so `composer compliance` never sees a flavor readme.

**Version headers:** `Stable tag: <version>`, `Requires at least: 6.9`,
`Tested up to: 7.1`, `Requires PHP: 8.1`, `License: GPLv2 or later`. The stable
tag is substituted from `WPMCP_VERSION` at build time, so it cannot drift from
the main file. `Tested up to` is not substituted: it is written out in the three
shipped readmes (never in a loader, which Plugin Check reports as
`plugin_header_tested_up_to_not_allowed`), so `tests/free/Release/ReleaseHeadersTest.php` asserts they
agree and gate 4b in `scripts/build-wporg-release.sh` re-derives the same check
from the staged zip. The values above are the ones to re-read (and re-pin)
before every release, per `docs/release-checklist.md`.

---

## 4. Submission steps

Run `docs/release-checklist.md` first: it is the release sequence, and steps 1
to 3 below assume its version and header boxes are already ticked.

1. Build a fresh zip: `composer build:wporg`. It fails rather than produces an
   artifact if any gate trips, and its last step is the compliance engine
   against the unpacked zip.
2. Run Plugin Check as well, against the same zip. The engine covers the
   guideline-level judgements Plugin Check does not encode; Plugin Check covers
   the sniff layer and the runtime checks the engine cannot. The reviewer runs
   Plugin Check, so run it too, on a real install of the WordPress release
   `Tested up to` names (CI's `plugin-check` job runs it against the same zip
   on every push, but not on a live site).
3. Sign in at <https://wordpress.org/plugins/developers/add/>.
4. Upload `dist/wpmcp-directory-<version>.zip`. The whole plugin is examined before approval,
   which is why the zip is required and why a placeholder submission would be
   rejected under guideline 16.
5. **On the confirmation screen, check the derived slug** and change it to
   `wpmcp`. One change only, and never after approval.
6. Watch for the automated email. Then watch for the reviewer email, and answer
   it within 24 hours, every time. The queue is dominated by plugins waiting on
   their author, not on a reviewer; round trips are where weeks go.

---

## 5. What the reviewer will ask, and the answer

### 5.1 "This plugin lets an AI system change the site. What stops it?"

Every mutating tool routes through `Safe_Mutation::run()`, which captures the
prior state before the write and stores it as a snapshot, so any operation can
be reversed individually or a whole agent session rolled back at once. On top
of that: a WordPress capability check per ability, six governance layers that
can only narrow permissions and never widen them, per-request scoped
identities, a rate limit at the transport, and an audit log of every tool call
and every governance decision. Dry run is the default. There is no bypass path
around the snapshot; it is in the mutation helper, not in each tool.

### 5.2 "How does an external client connect? Is anything proxied through your servers?"

Nothing is proxied. The plugin exposes the site's own REST endpoint and speaks
MCP directly to whatever client the site owner pairs; there is no gateway, no
vendor relay and no account. Authentication is OAuth 2.1 with PKCE, or a
WordPress application password. The abilities are registered through the
Abilities API that shipped in core 6.9, so the surface is core's, not a private
one. The "Connection" admin screen provisions the credential and shows the
client config; the only request it makes is a loopback call to this site's own
URL to verify the endpoint answers.

### 5.3 "What external services does this contact?"

Four hosts, each documented in the readme's `== External services ==` section
with what is sent, when it fires, and links to terms and privacy policy:
`api.wordpress.org` (core checksums, for the security scan),
`api.openverse.org`, `api.pexels.com` and `api.unsplash.com` (stock image
search; the last two need an API key the user supplies).

Nothing is contacted on activation, on an admin page load, or on cron. There is
no scheduled job and no telemetry. Every request happens inside a tool call the
administrator or their agent made. There are also three dynamic destinations,
all described in the same section: `analyze-performance` fetches a URL the
caller supplies (private, loopback and reserved addresses refused, redirects
not followed), `import-stock-image` downloads from a fixed allowlist of five
image CDNs, and the analytics abilities call this site's own REST API to read
data through Google Site Kit when that plugin is installed and already
connected. This plugin holds no analytics credentials and talks to no analytics
provider directly.

### 5.4 "Is there PHP or shell execution in here?"

Not in this build. The plugin has two execution tools, `run-php-snippet` and
`run-wp-cli`, and both are part of the off-directory add-on. The directory zip
contains no `eval`, no `proc_open`, no `shell_exec`, no `passthru`, no `popen`,
no `exec`, no `system`, no `create_function` and no `assert`. That is enforced
by a token-level gate in `scripts/build-wporg-release.sh`, which fails the build
rather than producing a zip, so it cannot regress silently.

Two related, non-executing surfaces do ship, and both are worth stating plainly
rather than leaving for the reviewer to find.

`validate-php-snippet` is static analysis and nothing else. It parses a snippet
to report syntax validity and flags dangerous constructs (`eval`, `exec`,
backticks, obfuscation decoders, request-driven execution, outbound HTTP). It
never executes the code, never stores it and never writes it anywhere; it takes
a string and returns findings. It exists so that an agent can be told "this
snippet is unsafe" before a human is ever asked to paste it into a site.

The PHP snippet store (`create-php-snippet`, `list-php-snippets`,
`get-php-snippet`, `update-php-snippet`, `delete-php-snippet`,
`deactivate-php-snippet`) does STORE PHP source, in a non-autoloaded
`wpmcp_php_snippets` option, and we would rather say so than have it look
discovered. What it does not do, in this build, is run any of it. There is no
code path in the directory zip that reads a stored snippet and evaluates it:
`run-php-snippet` and `activate-php-snippet` are both part of the off-directory
add-on and their files are removed by
`scripts/flavors/wporg/strip.php`, and the no-`eval`/no-`proc_open` token gate
above still runs over the finished tree. Every write requires `manage_options`,
every snippet is stored inactive, and the store is on the option denylist so
the generic option tools cannot reach it.

That option is not the only copy, so we will name the other one too. Every
snippet write is snapshot-first, and the snapshot serializes the whole record,
source included, into the `before_blob` column of the plugin's own
`wp_wpmcp_snapshots` table. That is what makes a snippet edit or deletion
undoable, and it is the same mechanism every other write in the plugin uses.
The copy is short-lived (the table prunes to the most recent operations,
twenty by default) and no tool reads it back: `list-operations` selects only
the metadata columns and never the blob, and the only consumer is the rollback
path, which writes the record back into the option it came from. Still no
execution anywhere in that loop.

Concretely: this is a place to keep and review a snippet a human will decide
about, not a way to execute one.

### 5.5 "This can install and activate plugins. Isn't that guideline 8?"

Guideline 8 prohibits "serving updates or otherwise installing plugins, themes,
or add-ons from servers other than WordPress.org's". These tools do the
opposite: they drive core's own `Plugin_Upgrader` and `Theme_Upgrader`, exactly
as the Plugins and Themes screens do. Installs resolve the package through
core's `plugins_api()` / `themes_api()` from a wordpress.org slug validated by
regex; updates hand core the already-installed plugin file or stylesheet and
let core's own update transient supply the package. No package is bundled and
no package is fetched from our servers.

Nothing is installed without an explicit request naming the slug, and every
such request must carry the same granular core capability the equivalent
wp-admin screen requires: `install_plugins`, `activate_plugins`,
`update_plugins`, `delete_plugins`, `switch_themes`, `install_themes`,
`update_themes` or `delete_themes`. Where one tool spans two screens (the
installers' optional `activate: true` step) both capabilities are checked. It
is the same capability an administrator already has in wp-admin, exposed to the
tool surface they chose to connect.

Nothing runs in the background either. The only activation hook creates
tables; `wpmcp/schedule-event` refuses the core update hooks
(`wp_update_plugins`, `wp_update_themes`, `wp_maybe_auto_update`); and the
one cron tool that can fire those already-scheduled core hooks early,
`wpmcp/run-event`, ships disabled until a site opts in by filter, is gated on
`manage_options`, replays only core's stored args, and so at most runs core's
own updater against WordPress.org a little sooner than core would have.

The long-form version of this answer, with the complete file:line list of
every install, activate and delete site plus the shared guardrails, is
`docs/wporg/guideline-8-install-abilities.md`. If the reviewer pushes past
the paragraph above, paste from there.

### 5.6 "Is there a licensing SDK or paid gating in here?"

No. `src/Pro/`, `src/Freemius/` and the `freemius/wordpress-sdk` composer
dependency are not in this build, and neither are the paid abilities' source
files. There is no licence check, no `is_pro()`, no key field, no quota that a
payment lifts and no upsell notice. The paid tier is a separate add-on plugin
distributed by the author, which is the arrangement guideline 5 recommends
("add-on plugins, hosted outside of WordPress.org, in order to exclude the
premium code"). The readme mentions the add-on once, in a sentence that says
plainly that nothing in this plugin is locked, reduced or switched off by it.

Because the licensing SDK is removed rather than carved out, its updater is
gone with it: no PHP file in the zip matches any `Plugin_Updater_Check`
error-level marker, `site_transient_update_plugins` among them.
`Plugin_Updater_Check` is a file-content regex over PHP files with no Freemius
exclusion, and the WordPress.org run does not skip `vendor/`, so three
independent things assert this rather than one:

* `scripts/lib/updater-gate.sh` runs over the tree the build is about to zip,
  `vendor/` included, and fails the build on any hit. It reads its pattern
  list from the compliance engine's own rule rather than carrying a second
  copy, so the two cannot drift apart.
* The compliance engine then re-runs that rule against the extracted zip. For
  an artifact scan it covers `vendor/` as well, which it deliberately skips
  for a development checkout.
* Plugin Check itself cannot confirm this from CI: both its CLI and its
  GitHub action exclude `vendor/` unconditionally, and the action's
  `exclude-directories` input can only add to that list. The wordpress.org
  run does scan `vendor/`, which is why the two checks above exist.

### 5.7 "You have a `vendor/` directory."

`composer.json` ships beside it, as `File_Type_Check` requires. It is the
pruned manifest with the licensing SDK removed, so it matches what is actually
vendored. `composer.lock` is a development artifact and is not in the zip. The
build tooling and the full source are public at
<https://github.com/wpmcp/wordpress-mcp>, which is the readme link guideline 4 asks for.

### 5.8 "Why does the name start with WP?"

`Trademarks_Check` lists `wp` without a trailing dash, so it matches anywhere
in a slug and produces a warning; the check's own source comments the entry
"it's allowed, but shows a warning". The plugin does not use "WordPress" in its
name or slug, which is the term guideline 17 actually restricts. If the review
team would rather the name did not begin with WP, say so and it will be
changed before the slug is fixed.

---

## 6. Known non-blocking findings

These are in the engine report against the built zip and are deliberate. None
is a blocker; they are listed so nobody has to rediscover them.

| Finding | Count | Why it stands |
| --- | --- | --- |
| `WPORG-08-PLUGIN-INSTALL` | 19 | Section 5.5, long form in `docs/wporg/guideline-8-install-abilities.md`. Core's upgrader, wp.org packages, granular core capabilities (`install_plugins`/`activate_plugins`/`update_*`/`delete_*`/`switch_themes`), explicit request. |
| `WPORG-07-EXTERNAL-SERVICES`, dynamic destinations | 6 | Section 5.3. The engine reports every non-statically-resolvable destination because it cannot read prose; each one is described in the readme. |
| `WPORG-17-TRADEMARK`, term "wp" | 3 | Section 5.8. Best practice only, tolerated by Plugin Check's own annotation. |

---

## 7. Post-approval SVN workflow

Approval brings an email with the SVN URL,
`https://plugins.svn.wordpress.org/wpmcp/`. It is a **release** repository, not
a development one: guideline 14 is explicit that "all commits, code or readme
files, will trigger a regeneration of the zip files", and that "multiple,
rapid-fire commits that only tweak minor aspects of the plugin (including the
readme) cause undue strain on the system and can be seen as gaming Recently
Updated lists". The 13-PR git cadence does not go here. One commit per release.

First release:

```bash
svn co https://plugins.svn.wordpress.org/wpmcp/ /tmp/wpmcp-svn
cd /tmp/wpmcp-svn

# trunk is the working copy of the current release
rm -rf trunk/*
unzip -q /path/to/dist/wpmcp-directory-<version>.zip -d /tmp/unpacked
cp -R /tmp/unpacked/wpmcp/. trunk/

svn add --force trunk
svn status | grep '^!' | awk '{print $2}' | xargs -r svn rm

# assets/ is the listing artwork; it lives beside trunk, not inside it, and is
# never part of the plugin zip
#   assets/banner-1544x500.png, assets/banner-772x250.png
#   assets/icon-256x256.png, assets/icon-128x128.png
#   assets/screenshot-1.png ... matching the readme's == Screenshots == order

svn ci -m "Release <version>"
```

Then tag it. The tag is what the directory actually serves, and `Stable tag:`
in `trunk/readme.txt` must name it:

```bash
svn cp trunk tags/<version>
svn ci -m "Tag <version>"
```

Every later release:

1. Bump `WPMCP_VERSION` in `wpmcp.php` in git. `Stable tag:` is generated from
   it, so the two cannot disagree (guideline 15).
2. `composer build:wporg`. If the compliance gate fails, the release stops.
3. Copy the unpacked zip over `trunk/`, commit once.
4. `svn cp trunk tags/<version>`, commit once.
5. Keep git and SVN in lockstep. Guideline 3: "distributing code via alternate
   methods, while not keeping the code hosted here up to date, may result in a
   plugin being removed". A GitHub release of the free plugin without the
   matching SVN tag is the failure mode.

Readme-only changes still count as a release: bump the patch version rather
than committing a readme tweak on its own.

`Tested up to:` needs a bump after each WordPress major, and needs actual
testing first. Plugin Check errors when it trails the current release, and a
plugin that trails stops appearing in directory search.

**`docs/release-checklist.md` is the authoritative per-release sequence** for
both of those, and for the two other artifacts (`scripts/build-woo-release.sh`
and `scripts/build-release.sh`) that this section does not cover. Work from
that file and treat the steps above as background on why each one exists; when
the two disagree, the checklist wins and this section gets corrected.
