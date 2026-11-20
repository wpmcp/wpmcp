# WordPress.org submission plan (internal)

Status: research and plan only. Nothing submitted, nothing committed, no code
changed. Written 2026-11-20 02:58 PKT (2026-11-19 21:58 UTC) against
origin/main `c39b61e2` (WPMCP_VERSION 1.1.0).

Internal document. The competitor whose founder gave the "skipping wp.org was
my biggest mistake" advice is referred to here as "the competitor" and must not
be named in any public copy.

## Context

On 2026-11-01 the owner put WordPress.org out of scope; on 2026-11-20 he
reopened it. The earlier reasoning is in
/Users/isupercoder/Code/wpmcppro/HANDOFF-2026-11-01-gtm.md (the findings
section, about lines 90 to 120): the competitor estimates skipping wp.org cost
him about 2x earnings, got a lite version approved in about two weeks, and
believes direct file and DB access conflicts with the guidelines.

New fact found today: the competitor is now listed on wp.org (a lite build,
added 2026-03-25, about 40,000 active installs per the plugins API). So the
"lite version" route works, and it is the route this plan takes.

## 1. What the wporg build does today, and does it pass

### What it strips or changes

All driven by /Users/isupercoder/Code/wpmcppro/wpmcp/scripts/build-wporg-release.sh
and the shared policy in
/Users/isupercoder/Code/wpmcppro/wpmcp/scripts/flavors/wporg/policy.php:

- Licensing and paid gating removed, not gated: `src/Pro`, `src/Freemius`
  (policy.php:37-38), Freemius SDK removed from composer.json before vendoring
  (build-wporg-release.sh:94-97).
- The in-admin Pro banner is removed (policy.php:42, `src/Admin/Pro_Banner.php`).
- Paid ability trees removed whole or file by file: Cloud wrappers, Analysis,
  BlockBuilder, WidgetBuilder, Brand, Memory wrappers, about 100 paid
  integration packs (policy.php:49-301).
- Execution removed: WP-CLI runner and async CLI jobs, PHP snippet runner and
  activation (policy.php:66-87). Token-level exec gate over src, vendor and
  the main file (build-wporg-release.sh:117-126).
- Guideline 8 shape removed: install-from-zip (policy.php:106-108).
- Custom CSS and JS injection removed (policy.php, CustomCode entries near the
  end of `removed_paths`).
- Snapshot retention is a flat, filterable 20 (WPORG-SUBMISSION.md:54-57).
- Gates after the strip re-derive everything from the staged tree: syntax,
  paid identifiers, pay-to-unlock prose in PHP, docs and readme, removed paths,
  no pro ability registered, class references, Tested up to and Stable tag
  consistency, updater surface, zip contents, then the compliance engine in the
  `wporg-free` profile against the extracted zip (build-wporg-release.sh:285-336).

### Does it build and pass today

Verified 2026-11-20 about 02:55 PKT on `c39b61e2`, disk 122 GB free before
running:

| Build | Result | Zip | Engine findings |
| --- | --- | --- | --- |
| `bash scripts/build-wporg-release.sh` | exit 0, 76 s | wpmcp-1.1.0.zip, 1,181 files, 7.96 MB unpacked | 0 blocker, 0 likely-reject, 37 reviewer-discretion, 6 best-practice |
| `bash scripts/build-woo-release.sh` | exit 0, 95 s | wpmcp-for-woocommerce-1.1.0.zip, 958 files, 5.90 MB unpacked | 0 blocker, 0 likely-reject, 34 reviewer-discretion, 7 best-practice |

GitHub CI on the same commit (run 44522534683): `plugin-check`, `static` and
`compliance` jobs all succeeded. The zips were deleted after inspection
(dist/ is gitignored); the build stages in mktemp with an EXIT trap.

Reviewer-discretion categories (wporg build): WPORG-07 external services (18
dynamic destinations), WPORG-08 plugin install (core upgrader, wp.org slugs),
WPORG-11 admin notices (Announcements.php:63, Backup_Dir_Notice.php:27,
New_Tools_Notice.php:27, Snapshot_Retention_Notice.php:34, wpmcp.php:31).
Best-practice: readme is 18,095 bytes (Plugin Check suggests under 10,240),
"wp" trademark warning on name and slug, `auto_update_plugin` filter warning.

Not verified: PHPUnit (not run, per instructions), Plugin Check against a live
WordPress 7.1 install (only CI's action, which skips vendor/), and a manual
install and smoke test of the zip.

### Important: the engine passing does not mean the review passes

The engine models the written guidelines. The Plugin Developer FAQ (last
updated 2026-11-10, "Are there plugins you don't accept?") adds a policy the
engine does not encode:

> "We also do not accept new plugins that allow arbitrary code insertion or
> execution." "Examples include PHP or JavaScript editors, file managers, and
> AI tools that generate code" "intended to be executed on the site."

The current free build still ships exactly that shape, behind code-only
filters. See blocker 1 below.

## 2. What still risks rejection

Guidelines checked: Detailed Plugin Guidelines page at
https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
(header "Last modified: March 11, 2026"; 18 guidelines; no AI-specific rule)
and the Plugin Developer FAQ (last updated November 10, 2026), both fetched
2026-11-20.

### Blocker 1 (high): file editor, raw DB writes and code storage ship in the free zip

In the built wporg zip (and the woo zip):

- `wpmcp/write-file`, `wpmcp/edit-file`, `wpmcp/delete-file`
  (src/Tools/Filesystem/). Writing PHP is an explicit feature: Php_Edit_Guard
  parses and loopback-checks a saved `functions.php`
  (src/Tools/Filesystem/Php_Edit_Guard.php header). Off by default behind the
  `wpmcp_enable_fs_writes` filter (src/Tools/Filesystem/Write_File.php:72-75),
  needs `edit_files`, honors DISALLOW_FILE_EDIT.
- `wpmcp/insert-row`, `update-rows`, `delete-rows` (src/Tools/Database/),
  filter `wpmcp_enable_db_writes` (src/Governance/Opt_In_Gates.php:68-70), plus
  read-only `query` (arbitrary SELECT).
- PHP snippet store CRUD (src/Tools/Code/Create_Php_Snippet.php and siblings):
  stores PHP that the add-on can later run. The submission doc already admits
  this (WPORG-SUBMISSION.md:276-298). To a reviewer reading the FAQ this is
  "code insertion", run or not.
- `create-code-snippet` into the Code Snippets plugin, gated by
  `wpmcp_allow_js_injection` (Opt_In_Gates.php:81).
- Site migration `receive-site-archive` and `push-site-archive`
  (src/Tools/Migration/, Opt_In_Gates.php:96-97): accepting a site archive from
  another server writes files and SQL that did not come from wp.org, which
  reads as guideline 8.
- `incident-response` `rotate-salts` writes wp-config.php and is not behind the
  fs-writes opt-in (Plugin.php:2472 description).

The opt-ins are a real mitigation, but a reviewer judges what ships, and these
are the FAQ's named examples. This is also exactly the "direct file and DB
access" the competitor said would block him, and his approved build is a lite
one. Fix: move these into the off-directory add-on via policy.php, the same
mechanism already used for execution. Estimated 1 to 2 days including strip
edits, ability-description and readme scrubs, and the manifest and tests.

### Blocker 2 (high): the vertical's planned name is taken, and only one submission at a time

- `mcp-for-woocommerce` exists on wp.org: "MCP for WooCommerce" by another
  author, v1.3.0, 60 installs, added 2025-10-25 (plugins API, 2026-11-20). The
  July plan to lead with "MCP for WooCommerce" cannot happen under that name.
  Our flavor is "WP MCP for WooCommerce", slug `wpmcp-for-woocommerce`
  (scripts/flavors/woocommerce/wpmcp-for-woocommerce.php:3,10), which is free
  but will draw a similar-name warning at submission (FAQ: "If there is an
  existing plugin with your name or slug, then you'll get a warning").
- FAQ, "How many plugins can I submit for review at a time?": "Generally, just
  one." So the two listings are serial, not parallel. The lead choice decides
  which one exists in December.

### Blocker 3 (medium): account, contributor and stale runbook

- WPORG-SUBMISSION.md:100 says no wp.org account exists, yet the readme
  declares `Contributors: fahdi` (scripts/flavors/wporg/readme.txt:2) and a
  public profile `profiles.wordpress.org/fahdi` exists and is listed as author
  on 7 directory plugins (one is "Attendance Management For LifterLMS"). The
  owner must confirm whether that is his account. If not, the contributor line
  is wrong and Plugin Check will flag it; if it is, the runbook is stale and
  the account's email should be a company address (FAQ trademark-flag note,
  WPORG-SUBMISSION.md:103-106).
- WPORG-SUBMISSION.md still describes 0.8.1 (lines 8, 176, 203); the code is
  1.1.0. Refresh before use.

### Other risks, lower

| Area | Finding | Risk |
| --- | --- | --- |
| Trialware (g5) | Stripped, gated by the build. The add-on paragraph (readme.txt:40-47) is upsell copy, which g5 permits. | Low |
| Phoning home (g7) | Announcements feed reads a cloud URL and key only if the add-on saved one (readme.txt:249-259; Cloud_Client.php:93,146; Token_Refresher.php:400). Disclosed, dormant, but a reviewer may ask why code that talks to the author's server ships with no way to configure it. Recommend stripping Announcements and the Cloud seam from the wporg build. | Low to medium |
| External services disclosure (g7) | 18 dynamic destinations all described in the readme External services section; image generation through a connected AI provider is described too. | Low |
| Remote code (g8) | No eval, proc_open or any exec construct (token gate). Plugin install and update use core's upgrader with wp.org slugs only (WPORG-SUBMISSION.md:303-334). | Low to medium, reviewer discretion |
| Admin nags (g11) | Five admin_notices hooks; Pro banner stripped. Each must be dismissible or self-dismissing. Check New_Tools_Notice and Snapshot_Retention_Notice screens and dismissal before submitting. | Low |
| Readme (g12) | 18 KB, 5 tags, no vendor tags. Trim toward 10 KB; move long External services detail to a docs page linked from the readme if the reviewer asks. | Low |
| Trademark (g17) | The guideline text restricts trademarks "as the sole or initial term of a plugin slug"; "WP" is not named in the guideline or FAQ. Plugin Check warns. Many `wp-*` listings exist. "Claude", "ChatGPT" etc. must stay out of name, slug and tags (they are in several competitor names, but that is their risk). | Low |
| Size | 1,181 files, 2.4 MB zip. A big plugin is a slow review. Blocker 1's strip helps. | Medium for timing |
| Post-approval | Since June 2026 every release sits in a 6 hour cooldown scored by AI models plus Jetpack Scan; high-risk releases are blocked automatically (make.wordpress.org/plugins, 2026-09-09). Shipping file-write or SQL tools in a later update invites blocked releases. | Medium, ongoing |

## 3. Which listing to lead with

Directory landscape (plugins API search "mcp", 2026-11-20): 1,005 results.
Notable: official `mcp-adapter` by WordPress.org (80,000 installs, added
2026-10-02), the competitor (40,000), three other AI or MCP plugins with
100,000, 90,000 and 10,000 installs, and a 2,000-install "MCP server with
undo" listing (added 2026-07-03), which already uses our undo wedge in its
listing title. Slugs
`wpmcp` and `wp-mcp` are free.

| Option | For | Against |
| --- | --- | --- |
| A. General "WP MCP" free core (slug `wpmcp`) | Matches the brand, GitHub repo, marketing site and every link we already have. Biggest addressable search ("mcp", "mcp server", "wordpress mcp"). Same shape as the competitor's approved lite build. All later traffic and reviews accrue to one listing. | Crowded, with an official core plugin at 80k. Undo is no longer unique in a listing title. |
| B. "WP MCP for WooCommerce" (slug `wpmcp-for-woocommerce`) | Higher commercial intent; WooCommerce owners pay. Narrower search, fewer big competitors. | The exact "MCP for WooCommerce" name and slug belong to someone else, so we land second on our own keyword with a near-identical name. Not a thin wrapper: 958 files and the same file and DB tools as the general build (build-woo-release.sh:15-20 says the prologue ships in every flavor). Requires WooCommerce, so it cannot serve the agency audience. |
| C. Both | Two shelves in search. | Serial anyway (one in the queue at a time); doubles review, SVN and support load; two near-duplicate listings from one author can read as directory spam under g12. |

Recommendation: A first. Revisit B only after A is approved, and only if it is
made meaningfully different (store-specific abilities and copy, or a true thin
add-on that requires `wpmcp`), not a second cut of the same tree.

## 4. Timeline and Black Friday verdict

Current time when checked: 2026-11-20 02:52 PKT (2026-11-19 21:52 UTC), a
Friday. Black Friday window: 2026-11-23 to 2026-11-30.

Queue, from "Plugins Team: 16 Nov 2026" on make.wordpress.org/updates (fetched
2026-11-20):

- In the queue (new and pending): 4,702, of which 3,322 older than 7 days.
- New, not yet processed or replied to: 727.
- Pending, waiting on author: 3,163; waiting on reviewer: 627.
- Week of 2026-11-09 to 2026-11-15: 1,000 (labelled figure, most likely new
  submissions). Status changes: 1,330 requested, 826 approved, 1,749
  rejected, 1,071 closed (period not stated).

Official expectations: "within 5 business days" and "anywhere between 1 and 10
days" (wordpress.org/plugins/developers/add/, modified 2025-07-13); FAQ: reply
within 10 business days, a small correct plugin approved "within fourteen days
of initial review", rejection if not complete after three months, no queue
jumping.

Realistic path for us:

- Blocker 1 strip, runbook refresh, account check, readme trim: 2 to 3 working
  days. Earliest sensible submission about 2026-11-24 PKT.
- First reviewer email: 3 to 10 business days, so roughly 2026-11-27 to
  2026-12-08.
- A plugin of this size and subject will draw at least one round of questions;
  each round costs our reply time plus the reviewer's. Realistic approval:
  mid-December 2026 to January 2027.

Verdict: nothing will be live on wp.org for Black Friday. Even a best case
(approval within a week of a Monday submission) lands at the end of the window
with zero installs and no reviews, so it would contribute nothing. Black Friday
should run on the existing direct channel as planned; submit wp.org on its own
merit, without rushing the strip.

## 5. Step by step plan

Owner decisions are marked D1 to D6 with a recommendation.

1. **D1, account.** Is `profiles.wordpress.org/fahdi` yours?
   - (a) Yes: use it, move its email to a company address. (recommended if true)
   - (b) No: register a new account with a company address, change
     `Contributors:` in both flavor readmes.
2. **D2, lead listing.** (a) General WP MCP, slug `wpmcp` (recommended),
   (b) WooCommerce vertical, (c) both, serially.
3. **D3, free-build scope.** What leaves the directory build for the add-on:
   - (a) Strip file write/edit/delete, DB insert/update/delete, raw SQL
     `query`, PHP snippet store, `create-code-snippet`, migration push and
     receive, and `rotate-salts` (recommended; closest to the competitor's
     approved lite shape, and consistent with the FAQ).
   - (b) Strip writes only; keep read-only `query`, `read-file`,
     `list-directory`, `search-files` (some review risk on file manager
     wording; still defensible as read-only).
   - (c) Ship as is with the opt-in argument in the reviewer notes (not
     recommended: a rejection costs weeks and blocks our one queue slot).
4. **D4, cloud announcements.** Strip Announcements plus the Cloud seam from
   the wporg build (recommended) or keep as disclosed and dormant.
5. **D5, upsell in the directory build.** Guideline 5 allows upselling and
   guideline 11 allows limited, dismissible prompts. The policy comment at
   policy.php:39-41 overstates g11. Options:
   - (a) Submit with no banner; after approval add a banner limited to WP MCP's
     own screens, dismissible, no locked features shown (recommended: keeps the
     first review clean, recovers the main conversion lever later).
   - (b) Include that limited banner in the first submission.
   - (c) Never ship one in the directory build.
6. **D6, timing.** Submit when ready (recommended) or hold the submission until
   after Black Friday so support load does not overlap. Either way, no wp.org
   effect on Black Friday.
7. After D1 to D6: open GitHub issues for the strip work (wpmcp/wordpress-mcp,
   `wporg-compliance` label) and implement via policy.php plus strip.php
   exact-string edits, ability-description and readme scrubs, and tests in
   tests/free/Flavors.
8. Rebuild with `composer build:wporg`; confirm 0 blockers and 0
   likely-reject; check the engine no longer reports the removed paths.
9. Install the zip on a clean WordPress 7.1.x site, run Plugin Check there
   (it scans vendor/, unlike CI), activate, pair a client, run a write and a
   restore, confirm every admin notice is dismissible.
10. Trim readme.txt toward 10 KB; refresh WPORG-SUBMISSION.md to 1.1.x and the
    new scope; rewrite section 5.4 now that the snippet store is gone.
11. Prepare listing assets (banner 1544x500 and 772x250, icon 256 and 128,
    screenshots matching `== Screenshots ==`); they go in SVN `assets/`, not
    the zip.
12. Submit at wordpress.org/plugins/developers/add/; on the confirmation
    screen set the slug to `wpmcp` (one change only).
13. Answer every reviewer email within 24 hours.
14. On approval: SVN trunk plus tag per WPORG-SUBMISSION.md section 7; keep
    GitHub releases and SVN tags in lockstep; then decide D2 (c) and D5 (a).

## Verified vs not verified

Verified:

- Real time via `date` (02:52 PKT, 21:52 UTC); disk space.
- Both builds run to completion on origin/main `c39b61e2` with 0 blockers;
  CI plugin-check, static and compliance green on that commit.
- Built zip contents: Filesystem, Database, Code (snippet store) and
  Migration trees present in both zips; opt-in filters are code-only for fs
  and db writes.
- Guideline page and FAQ text and dates; queue figures from the 16 Nov 2026
  team post; slug availability and competitor listings via the plugins API.
- All 32 `wporg-compliance` issues are closed (last on 2026-09-28).

Not verified:

- PHPUnit; Plugin Check on a live site; installing and exercising either zip.
- Whether `fahdi` on wp.org is the owner's account.
- The meaning of the "1,000" weekly queue figure and actual time to first
  review for a submission today; the estimates above are inferences.
- Whether reviewers currently accept or reject the "wp" prefix in practice,
  beyond the guideline text and Plugin Check's warning.
- Whether a second, near-duplicate listing from the same author would be
  refused.

## Owner decisions, 20 Nov 2026 (about 03:15 PKT)

- D1 account: submit from the existing wp.org account `fahdi` as it is. No email change at this stage.
- D2 lead listing: the WooCommerce vertical first, named "WP MCP for WooCommerce", slug `wp-mcp-for-woocommerce` (free on the wp.org API on 20 Nov; `mcp-for-woocommerce` is taken). The general `wpmcp` listing waits until after approval.
- D3 scope of the wp.org Woo build: store tools plus the safety core only. WooCommerce catalog, orders and customers, posts, pages and media basics, snapshot and undo. Strip file writes, DB writes, raw SQL, the PHP snippet store, code-snippet creation, site migration and rotate-salts from the wp.org zip; they stay in the direct download and Pro.
- D4 cloud: strip the announcements feed and the cloud client from the wp.org build.
- D5 upsell banner: none in the first submission; add a dismissible banner on WP MCP's own screens in the first update after approval. Fix the policy.php comment that says upsells are not allowed.
- D6 timing: build now and submit as soon as the narrowed build passes its gates. The submission itself still needs the owner's go.
