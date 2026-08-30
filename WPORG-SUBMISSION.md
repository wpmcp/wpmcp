# WordPress.org submission runbook

Everything here is prepared up to the upload. **The plugin has not been
submitted.** Submission needs a WordPress.org account, and no account exists
yet; that is the first blocking item below. Nothing in this document should be
read as "filed" or "in review".

Status of the artifact: `dist/wpmcp-0.8.0.zip`, built by
`scripts/build-wporg-release.sh`, passes the compliance engine's `wporg-free`
profile against the extracted zip with zero blockers and zero likely-reject
findings.

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
* No pay-to-unlock copy survives anywhere, including in the ability grid, which
  used to render locked rows reading "disabled: no pro license".

**Divergence from the marketing plan worth flagging.** Phase 1 item 10 says to
"hard-code the unlimited value" for snapshot history. The build ships a flat
20 with a free filter instead. Unlimited retention with no prune would grow the
snapshots table without bound, which is a real defect rather than a compliance
question; a flat filterable cap satisfies guideline 5 the same way (the
rulebook's own wording: "hard limit, no gate, no pro code") without introducing
one. Change the constant if you disagree, it is a one-line product call:
`Snapshot_Store::DEFAULT_HISTORY_LIMIT`.

---

## 2. Blocking prerequisite

No WordPress.org account exists. Everything in section 4 is unreachable until
one does.

1. Register at <https://login.wordpress.org/register> and verify the email.
   Use a company address, not a Gmail one: the Plugin Developer FAQ notes that
   "submitting your official plugin with a user that has a gmail address is
   likely to be flagged for trademark infringement".
2. The username becomes the `Contributors:` value. The readme currently says
   `fahdi`. If the new account uses a different username, change
   `scripts/flavors/wporg/readme.txt` before building the submission zip, or
   Plugin Check errors on an unknown contributor.

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

**Version headers:** `Stable tag: 0.8.0`, `Requires at least: 6.9`,
`Tested up to: 7.0`, `Requires PHP: 8.1`, `License: GPLv2 or later`. The stable
tag is substituted from `WPMCP_VERSION` at build time, so it cannot drift from
the main file.

---

## 4. Submission steps

1. Build a fresh zip: `composer build:wporg`. It fails rather than produces an
   artifact if any gate trips, and its last step is the compliance engine
   against the unpacked zip.
2. Run Plugin Check as well, against the same zip. The engine covers the
   guideline-level judgements Plugin Check does not encode; Plugin Check covers
   the sniff layer and the runtime checks the engine cannot. The reviewer runs
   Plugin Check, so run it too, on a real WordPress 7.0.
3. Sign in at <https://wordpress.org/plugins/developers/add/>.
4. Upload `dist/wpmcp-0.8.0.zip`. The whole plugin is examined before approval,
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

One related tool does ship: `validate-php-snippet`. It is static analysis and
nothing else. It parses a snippet to report syntax validity and flags dangerous
constructs (`eval`, `exec`, backticks, obfuscation decoders, request-driven
execution, outbound HTTP). It never executes the code, never stores it and
never writes it anywhere; it takes a string and returns findings. It exists so
that an agent can be told "this snippet is unsafe" before a human is ever asked
to paste it into a site.

### 5.5 "This can install and activate plugins. Isn't that guideline 8?"

Guideline 8 prohibits "serving updates or otherwise installing plugins, themes,
or add-ons from servers other than WordPress.org's". These tools do the
opposite: they drive core's own `Plugin_Upgrader` and `Theme_Upgrader` against
the WordPress.org repository, exactly as the Plugins screen does. No package is
bundled, no package is fetched from our servers, and nothing is installed
without an explicit `manage_options` request naming the slug. It is the same
capability an administrator already has in wp-admin, exposed to the tool
surface they chose to connect.

### 5.6 "Is there a licensing SDK or paid gating in here?"

No. `src/Pro/`, `src/Freemius/` and the `freemius/wordpress-sdk` composer
dependency are not in this build, and neither are the paid abilities' source
files. There is no licence check, no `is_pro()`, no key field, no quota that a
payment lifts and no upsell notice. The paid tier is a separate add-on plugin
distributed by the author, which is the arrangement guideline 5 recommends
("add-on plugins, hosted outside of WordPress.org, in order to exclude the
premium code"). The readme mentions the add-on once, in a sentence that says
plainly that nothing in this plugin is locked, reduced or switched off by it.

### 5.7 "You have a `vendor/` directory."

`composer.json` ships beside it, as `File_Type_Check` requires. It is the
pruned manifest with the licensing SDK removed, so it matches what is actually
vendored. `composer.lock` is a development artifact and is not in the zip. The
build tooling and the full source are public at
<https://github.com/wpmcp/wpmcp>, which is the readme link guideline 4 asks for.

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
| `WPORG-08-PLUGIN-INSTALL` | 19 | Section 5.5. Core's upgrader, wp.org packages, `manage_options`, explicit request. |
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
unzip -q /path/to/dist/wpmcp-0.8.0.zip -d /tmp/unpacked
cp -R /tmp/unpacked/wpmcp/. trunk/

svn add --force trunk
svn status | grep '^!' | awk '{print $2}' | xargs -r svn rm

# assets/ is the listing artwork; it lives beside trunk, not inside it, and is
# never part of the plugin zip
#   assets/banner-1544x500.png, assets/banner-772x250.png
#   assets/icon-256x256.png, assets/icon-128x128.png
#   assets/screenshot-1.png ... matching the readme's == Screenshots == order

svn ci -m "Release 0.8.0"
```

Then tag it. The tag is what the directory actually serves, and `Stable tag:`
in `trunk/readme.txt` must name it:

```bash
svn cp trunk tags/0.8.0
svn ci -m "Tag 0.8.0"
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
