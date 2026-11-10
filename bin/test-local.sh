#!/usr/bin/env bash
#
# The test gate. PHPUnit does not run on GitHub Actions; it runs here, from the
# pre-push hook (.githooks/pre-push) and by hand. GitHub keeps only the static
# gates (lint, drift, compliance, Plugin Check).
#
# What it does, in order:
#   1. Starts a private MariaDB server under the cache directory, if one is not
#      already running. It never touches a system MySQL or MariaDB.
#   2. Installs WordPress, its test library and the test plugins once per
#      WordPress version into the cache. The install directory is keyed on a
#      hash of bin/install-wp-tests.sh and bin/install-test-plugins.sh, so a
#      branch that edits either script gets its own install instead of wiping
#      one that other worktrees are running against, and on the version of
#      every test plugin (issue #532): the pinned ones, and for the rest the
#      latest stable release, asked of WordPress.org once per run. A new
#      release therefore gets a new install, built from the newest install of
#      the same installers with only the changed plugins reinstalled. Offline,
#      the run uses that newest cached install and says so. Each leg's summary
#      line names the plugin versions it ran against. The installs are kept
#      inside a budget, see "The cache" below.
#   3. Gives this checkout its own database, so worktrees can run side by side.
#   4. Runs composer lint, composer drift:check, then the suite on the default
#      WordPress (with the coverage floor when pcov is loaded), then the suite
#      on the `Requires at least` WordPress from readme.txt without coverage
#      (issue #488), so the floor is tested on every push, then the suite on
#      the default WordPress installed as a multisite network (WP_MULTISITE=1,
#      issue #530), also without coverage.
#   5. Runs the live forms group (issue #66): the Contact Form 7 adapter
#      against a real Contact Form 7 and Flamingo, on a separate install,
#      because the harness doubles stand down once the real classes load.
#   6. Runs the live blocks group (issue #287): blocks inserted through the
#      block-suites tools, rendered with the real Kadence Blocks,
#      GenerateBlocks, Spectra and Otter Blocks, on its own install too.
#   7. Runs the live BuddyPress group (issue #363): the BuddyPress ops run
#      through the real BuddyPress, its hooks firing, on its own install too.
#   8. Runs the live LMS groups (issue #394): the Tutor LMS and LifterLMS ops
#      through the real plugins, one install each.
#   9. Runs the live TablePress group (issue #481): the TablePress ops
#      through the real TablePress, on its own install too.
#  10. Runs the live CRM group (issue #477): the FluentCRM and MailPoet ops
#      through the real plugins, on their own install.
#  11. Runs the live Amelia group (issue #499): the Amelia ops through the
#      real Amelia Lite, on its own install too.
#  12. Runs the live FunnelKit group (issue #500): the FunnelKit funnel
#      writes through the free Funnel Builder's own REST controllers, with
#      WooCommerce, on their own install.
#  13. Runs the live Polylang group (issue #498): the page translation ops
#      through the real Polylang (booted as wp-admin boots it) and Elementor,
#      on the shared install with its own database.
#  14. Runs the live Breakdance group (issue #457) when WPMCP_BREAKDANCE_SRC
#      names a local copy of Breakdance (a commercial plugin, so not
#      downloadable here): pages saved by the real Breakdance are detected,
#      read, edited and rolled back, and Breakdance still loads them.
#  15. Runs the live MemberPress group (issue #668) when
#      WPMCP_MEMBERPRESS_SRC names a local copy of MemberPress (a commercial
#      plugin, so not downloadable here): memberships and rules written by the
#      ops load through MemberPress's own models, and what MemberPress stored
#      reads back through the ops.
#  16. Runs the live WooCommerce Subscriptions group (issue #658) when
#      WPMCP_WC_SUBSCRIPTIONS_SRC names a local copy of the plugin (commercial,
#      so not downloadable here): the subscriptions ops through the real
#      plugin beside WooCommerce, every write rolled back.
#  17. Runs the live Divi group (issue #636) when WPMCP_DIVI_SRC names a
#      local copy of the Divi 5 theme (commercial, so not downloadable
#      here), installed as a theme and made the active one: Divi 5 layouts
#      are detected, read, edited element by element and rolled back, and
#      Divi still parses and renders them.
#  18. Runs the live WPBakery group (issue #639) when WPMCP_WPBAKERY_SRC names
#      a local copy of WPBakery Page Builder (commercial too): its element
#      catalog, templates and design options are read from the real plugin,
#      pages and templates written here are rendered and listed by it, and
#      every write rolls back.
#  19. Runs the live Avada group (issue #637) when WPMCP_AVADA_SRC names a
#      local directory holding the Avada theme and the fusion-builder and
#      fusion-core plugins (commercial, so not downloadable here): pages
#      edited through wpmcp still render through the real Avada Builder,
#      its element map is read, and every write rolls back.
#  20. Runs the live Oxygen group (issue #635) when WPMCP_OXYGEN_SRC names a
#      local copy of Oxygen 6 (commercial, so not downloadable here): pages
#      saved by the real Oxygen are read and edited node by node, from
#      templates and Components, restored from revisions, still load and
#      render through Oxygen, and every write rolls back.
#  21. Runs the live theme add-on group (issue #656) once for each of
#      WPMCP_ASTRA_PRO_SRC, WPMCP_KADENCE_PRO_SRC and WPMCP_GP_PREMIUM_SRC
#      that names a local copy of Astra Pro, Kadence Pro or GP Premium
#      (commercial, so not downloadable here), beside its free theme from
#      wordpress.org made the active one: layouts written through the theme
#      ops are read back through the add-on's own code, and roll back.
#  22. Runs the live WooCommerce Bookings group (issue #659) when
#      WPMCP_WC_BOOKINGS_SRC names a local copy of the plugin (commercial, so
#      not downloadable here): the bookings ops through the real plugin
#      beside WooCommerce, every write rolled back.
#  23. Runs the live WP Mail SMTP group: the WP Mail SMTP ops on the theme
#      pair through the real plugin (its Options, encryption and constants),
#      on its own install, with every write rolled back and no mail sent.
#  24. Under --all (or --live-adapter alone), runs the adapter leg (issue
#      #501): the free and pro suites on the latest MCP Adapter plugin
#      released on WordPress.org, loaded next to the bundled copy the way a
#      site that installs the plugin runs us, with the version it tested in
#      the output. Not part of the plain gate: it repeats the whole suite,
#      several minutes on every push, for a dependency that only changes when
#      it is released.
#
# Every leg runs even when an earlier one fails, so a red floor leg never
# hides the live groups behind it. The run ends with a summary of every leg
# and exits non-zero if any failed. Use --plan to list the legs of a run.
#
# The cache. Each install is about 700 MB, and many worktrees running at once
# on branches that touch the installers or the pins once filled 73 GB in one
# night (9 to 10 November 2026). So:
#   - At most WPMCP_TEST_MAX_PARALLEL runs (default 2) go at once across all
#     worktrees, each holding a slot under $CACHE/locks/run-<n>; another run
#     says it is waiting and waits up to WPMCP_TEST_SLOT_TIMEOUT seconds
#     (default 3600). A slot whose holder has died is taken over.
#   - Before a run, and before building an install, free space on the cache's
#     volume is checked: under WPMCP_TEST_MIN_FREE_GB (default 20) the cache is
#     pruned to the two most recently used installs, and under
#     WPMCP_TEST_HARD_MIN_FREE_GB (default 10) after that the run stops.
#   - After every new install and when a run exits (also on Ctrl-C or TERM),
#     installs are pruned least recently used first (.last-used) to at most
#     WPMCP_TEST_KEEP_INSTALLS (default 6) and WPMCP_TEST_MAX_GB (default 15)
#     in all, and any install unused for WPMCP_TEST_INSTALL_TTL_HOURS (default
#     6) goes. A build that died without finishing goes too. Never removed:
#     an install a live run has claimed ($dir/.users/<pid>), one whose install
#     or pristine lock is held, and one used in the last 15 minutes (a run of
#     an older copy of this script claims nothing). A plain run uses about a
#     dozen installs (the live groups have one each), more than the default
#     count: the ones it used last are kept and the next plain run rebuilds
#     the others from the download cache below, without the network.
#   - Each run's core copies ($CACHE/cores/<hash>-<pid>) are removed when it
#     exits; those of runs that are no longer alive are removed by the next
#     run, and any older than a day regardless.
#   - Nothing is downloaded twice: WordPress core, the WordPress test library
#     (svn exports) and every plugin zip are kept in $CACHE/downloads, keyed
#     by exact version (core/wordpress-<version>.tar.gz,
#     tests-lib/<version>-includes, plugins/<slug>-<version>.zip), fetched
#     once under a lock through a .tmp file, and checked before use; see
#     bin/download-cache.sh. Building an install with everything cached
#     makes no network call. A plugin's latest release is asked of
#     WordPress.org at most once per WPMCP_TEST_LATEST_TTL_HOURS (default 24),
#     kept in $CACHE/downloads/latest. Downloads have their own budget: one
#     unused for WPMCP_TEST_DOWNLOAD_TTL_DAYS (default 30) goes, then the
#     least recently used until WPMCP_TEST_DOWNLOAD_MAX_GB (default 3) is
#     left; one an installer is reading is never removed.
#   - bin/test-local.sh --prune applies the budgets now; --prune --all removes
#     every install no live run is using, those from before issue #532 too
#     (the downloads keep their budget).
# A branch that edits only bin/install-test-plugins.sh still builds its
# install from scratch rather than from another installer's install: the
# plugin installer keeps whatever it finds at the wanted version, so a clone
# would carry the old installer's output and hide the very change the branch
# makes. The budget above bounds what those installs cost instead.
#
# MariaDB, not MySQL: the WordPress harness turns every CREATE TABLE into a
# TEMPORARY table, and WooCommerce's order-sync query reads one of them twice
# on admin_init. MySQL refuses that ("Can't reopen table: 'orders'"), which
# breaks RestoreControllerAjaxTest. MariaDB lifted the limitation.
#
# Usage:
#   bin/test-local.sh                 lint, drift, suite on the default WordPress with the
#                                     coverage floor, suite on the Requires at least WordPress,
#                                     suite on multisite, live groups
#   bin/test-local.sh --all           the same, on every WordPress in WP_VERSIONS below
#   bin/test-local.sh --wp 6.9        the suite on one WordPress version
#   bin/test-local.sh --no-coverage   skip the coverage run and floor
#   bin/test-local.sh --multisite     only the suite on the default WordPress as a multisite network;
#                                     takes PHPUnit arguments after -- like a targeted run
#   bin/test-local.sh --live-forms    only the live forms group (real Contact Form 7 + Flamingo)
#   bin/test-local.sh --live-blocks   only the live blocks group (real block suites)
#   bin/test-local.sh --live-buddypress only the live BuddyPress group (real BuddyPress)
#   bin/test-local.sh --live-lms      only the live LMS groups (real Tutor LMS, then real LifterLMS)
#   bin/test-local.sh --live-tablepress only the live TablePress group (real TablePress)
#   bin/test-local.sh --live-crm      only the live CRM group (real FluentCRM and MailPoet)
#   bin/test-local.sh --live-funnelkit only the live FunnelKit group (real Funnel Builder + WooCommerce)
#   bin/test-local.sh --live-amelia   only the live Amelia group (real Amelia Lite)
#   bin/test-local.sh --live-polylang only the live Polylang group (real Polylang and Elementor)
#   bin/test-local.sh --live-breakdance only the live Breakdance group (needs WPMCP_BREAKDANCE_SRC)
#   bin/test-local.sh --live-avada    only the live Avada group (needs WPMCP_AVADA_SRC)
#   bin/test-local.sh --live-oxygen   only the live Oxygen group (needs WPMCP_OXYGEN_SRC)
#   bin/test-local.sh --live-wpbakery only the live WPBakery group (needs WPMCP_WPBAKERY_SRC)
#   bin/test-local.sh --live-divi     only the live Divi group (needs WPMCP_DIVI_SRC)
#   bin/test-local.sh --live-memberpress only the live MemberPress group (needs WPMCP_MEMBERPRESS_SRC)
#   bin/test-local.sh --live-wc-subscriptions only the live WooCommerce Subscriptions group (needs WPMCP_WC_SUBSCRIPTIONS_SRC)
#   bin/test-local.sh --live-theme-pro only the live theme add-on group (needs at least one of
#                                     WPMCP_ASTRA_PRO_SRC, WPMCP_KADENCE_PRO_SRC, WPMCP_GP_PREMIUM_SRC)
#   bin/test-local.sh --live-wc-bookings only the live WooCommerce Bookings group (needs WPMCP_WC_BOOKINGS_SRC)
#   bin/test-local.sh --live-wp-mail-smtp only the live WP Mail SMTP group (real WP Mail SMTP)
#   bin/test-local.sh --live-adapter  only the adapter leg (both suites on the released MCP Adapter plugin)
#   bin/test-local.sh -- --filter Foo PHPUnit arguments, default WordPress only; skips lint,
#                                     drift, coverage, the floor leg and the live groups
#   bin/test-local.sh --plan [...]    print the legs the other options would run, run nothing
#   bin/test-local.sh --resolve-plugins [--wp V] print the test plugin versions and the
#                                     install a run would use, install nothing
#   bin/test-local.sh --stop-db       stop the private MariaDB server and exit
#   bin/test-local.sh --prune         prune the cache to its budget now, run nothing
#   bin/test-local.sh --prune --all   remove every install and core copy no live run is using
#
# Environment:
#   WPMCP_TEST_CACHE     cache root (default ~/.cache/wpmcp-tests)
#   WPMCP_TEST_KEEP_INSTALLS  most installs kept (default 6)
#   WPMCP_TEST_MAX_GB    most gigabytes of installs kept (default 15)
#   WPMCP_TEST_INSTALL_TTL_HOURS  an install unused this long is removed (default 6)
#   WPMCP_TEST_MIN_FREE_GB  below this much free space the cache is pruned to
#                        the two most recently used installs (default 20)
#   WPMCP_TEST_HARD_MIN_FREE_GB  below this much free space, after pruning, a
#                        run stops (default 10)
#   WPMCP_TEST_MAX_PARALLEL  most runs at once across worktrees (default 2)
#   WPMCP_TEST_SLOT_TIMEOUT  seconds a run waits for a slot (default 3600)
#   WPMCP_TEST_DOWNLOAD_TTL_DAYS  a cached download unused this long is
#                        removed (default 30)
#   WPMCP_TEST_DOWNLOAD_MAX_GB  most gigabytes of cached downloads (default 3)
#   WPMCP_TEST_LATEST_TTL_HOURS  how long a plugin's latest release, and the
#                        two unversioned downloads, are reused before asking
#                        again (default 24)
#   WPMCP_TEST_DB_PORT   MariaDB port (default 3399)
#   WPMCP_TEST_PHP       PHP binary (default php)
#   WPMCP_MARIADB_BIN    directory holding mariadbd, if not found automatically
#   WPMCP_BREAKDANCE_SRC a local Breakdance plugin directory for the live
#                        Breakdance group; without it that group is not run,
#                        except under --live-breakdance, where it is an error
#   WPMCP_AVADA_SRC      a local directory holding the unpacked Avada/ theme
#                        and the fusion-builder/ and fusion-core/ plugins for
#                        the live Avada group; without it that group is not
#                        run, except under --live-avada, where it is an error
#   WPMCP_ASTRA_PRO_SRC, WPMCP_KADENCE_PRO_SRC, WPMCP_GP_PREMIUM_SRC
#                        local astra-addon, kadence-pro or gp-premium plugin
#                        directories for the live theme add-on group, one run
#                        per variable set; with none set that group is not
#                        run, except under --live-theme-pro, where it is an
#                        error
#   WPMCP_OXYGEN_SRC     a local Oxygen 6 plugin directory for the live
#                        Oxygen group; without it that group is not run,
#                        except under --live-oxygen, where it is an error
#   WPMCP_WPBAKERY_SRC   a local WPBakery Page Builder directory (js_composer)
#                        for the live WPBakery group; without it that group is
#                        not run, except under --live-wpbakery, where it is an
#                        error
#   WPMCP_DIVI_SRC       a local Divi 5 theme directory for the live Divi
#                        group; without it that group is not run, except
#                        under --live-divi, where it is an error
#   WPMCP_MEMBERPRESS_SRC a local MemberPress plugin directory for the live
#                        MemberPress group; without it that group is not run,
#                        except under --live-memberpress, where it is an error
#   WPMCP_WC_SUBSCRIPTIONS_SRC a local WooCommerce Subscriptions plugin directory
#                        for the live Subscriptions group; without it that group
#                        is not run, except under --live-wc-subscriptions, where
#                        it is an error
#   WPMCP_WC_BOOKINGS_SRC a local WooCommerce Bookings plugin directory for the
#                        live Bookings group; without it that group is not run,
#                        except under --live-wc-bookings, where it is an error
#   WPMCP_ADAPTER_VERSION the MCP Adapter release the adapter leg tests (default:
#                        the latest on WordPress.org, asked for on every run)
#   ELEMENTOR_VERSION    passed to bin/install-test-plugins.sh ('latest' tests
#                        against the newest Elementor in a separate install)
#   WPMCP_PLUGIN_INFO_URL where to ask for a plugin's latest release, %s for
#                        the slug (default: the WordPress.org plugins info API)

set -euo pipefail

# The WordPress releases the suite runs on. The first is the default leg and
# must be the release the shipped headers declare (TESTED_UP_TO_FLOOR); the
# `Requires at least` floor must be in the list too; a plain run tests both.
# ReleaseHeadersTest parses this exact line, so keep it one quoted,
# space-separated list.
WP_VERSIONS="7.1 6.9"

# Line coverage floor for the default leg. A ratchet: only ever raise it.
COVERAGE_FLOOR=90.3

ROOT=$(cd "$(dirname "$0")/.." && pwd)
CACHE=${WPMCP_TEST_CACHE:-$HOME/.cache/wpmcp-tests}
PORT=${WPMCP_TEST_DB_PORT:-3399}
PHP=${WPMCP_TEST_PHP:-php}
DB_USER=wpmcp
DB_PASS=wpmcp
DB_DIR="$CACHE/mariadb"
SOCK="$DB_DIR/mariadbd.sock"
# The cache budget, see "The cache" above.
KEEP_INSTALLS=${WPMCP_TEST_KEEP_INSTALLS:-6}
MAX_GB=${WPMCP_TEST_MAX_GB:-15}
INSTALL_TTL_HOURS=${WPMCP_TEST_INSTALL_TTL_HOURS:-6}
MIN_FREE_GB=${WPMCP_TEST_MIN_FREE_GB:-20}
HARD_MIN_FREE_GB=${WPMCP_TEST_HARD_MIN_FREE_GB:-10}
MAX_PARALLEL=${WPMCP_TEST_MAX_PARALLEL:-2}
SLOT_TIMEOUT=${WPMCP_TEST_SLOT_TIMEOUT:-3600}
# The download cache, see bin/download-cache.sh.
DOWNLOADS="$CACHE/downloads"
DOWNLOAD_TTL_DAYS=${WPMCP_TEST_DOWNLOAD_TTL_DAYS:-30}
DOWNLOAD_MAX_GB=${WPMCP_TEST_DOWNLOAD_MAX_GB:-3}
LATEST_TTL_HOURS=${WPMCP_TEST_LATEST_TTL_HOURS:-24}
# Seconds since its last use during which an install is never pruned: a run
# of an older copy of this script marks its installs by .last-used alone.
RECENT_USE=900

say() { printf '\033[1m[test-local]\033[0m %s\n' "$*"; }
die() { printf '\033[31m[test-local]\033[0m %s\n' "$*" >&2; exit 1; }

for var in KEEP_INSTALLS INSTALL_TTL_HOURS MAX_PARALLEL SLOT_TIMEOUT DOWNLOAD_TTL_DAYS LATEST_TTL_HOURS; do
	[[ "${!var}" =~ ^[0-9]+$ ]] || die "WPMCP_TEST_$var must be a whole number (got '${!var}')"
done
for var in MAX_GB MIN_FREE_GB HARD_MIN_FREE_GB DOWNLOAD_MAX_GB; do
	[[ "${!var}" =~ ^[0-9]+(\.[0-9]+)?$ ]] || die "WPMCP_TEST_$var must be a number of gigabytes (got '${!var}')"
done
[ "$MAX_PARALLEL" -ge 1 ] || die "WPMCP_TEST_MAX_PARALLEL must be at least 1"

# ---------------------------------------------------------------- arguments

run_all=false
only_wp=""
only_live_forms=false
only_live_blocks=false
only_live_buddypress=false
only_live_lms=false
only_live_tablepress=false
only_live_crm=false
only_live_funnelkit=false
only_live_polylang=false
only_live_breakdance=false
only_live_avada=false
only_live_wpbakery=false
only_live_oxygen=false
only_live_divi=false
only_live_memberpress=false
only_live_amelia=false
only_live_wc_subscriptions=false
only_live_wc_bookings=false
only_live_wp_mail_smtp=false
only_live_adapter=false
only_live_theme_pro=false
only_multisite=false
coverage=true
plan_only=false
resolve_only=false
prune_only=false
phpunit_args=()
while [ $# -gt 0 ]; do
	case "$1" in
		--all) run_all=true ;;
		--wp) shift; only_wp=${1:-}; [ -n "$only_wp" ] || die "--wp needs a version" ;;
		--no-coverage) coverage=false ;;
		--live-forms) only_live_forms=true ;;
		--live-blocks) only_live_blocks=true ;;
		--live-buddypress) only_live_buddypress=true ;;
		--live-lms) only_live_lms=true ;;
		--live-tablepress) only_live_tablepress=true ;;
		--live-crm) only_live_crm=true ;;
		--live-funnelkit) only_live_funnelkit=true ;;
		--live-polylang) only_live_polylang=true ;;
		--live-breakdance) only_live_breakdance=true ;;
		--live-avada) only_live_avada=true ;;
		--live-wpbakery) only_live_wpbakery=true ;;
		--live-oxygen) only_live_oxygen=true ;;
		--live-divi) only_live_divi=true ;;
		--live-memberpress) only_live_memberpress=true ;;
		--live-amelia) only_live_amelia=true ;;
		--live-wc-subscriptions) only_live_wc_subscriptions=true ;;
		--live-wc-bookings) only_live_wc_bookings=true ;;
		--live-wp-mail-smtp) only_live_wp_mail_smtp=true ;;
		--live-adapter) only_live_adapter=true ;;
		--live-theme-pro) only_live_theme_pro=true ;;
		--multisite) only_multisite=true ;;
		--stop-db) stop_db=true ;;
		--plan) plan_only=true ;;
		--resolve-plugins) resolve_only=true ;;
		--prune) prune_only=true ;;
		--) shift; phpunit_args=("$@"); break ;;
		-h|--help) sed -n '2,/^set -euo/p' "$0" | sed '$d; s/^# \{0,1\}//'; exit 0 ;;
		*) die "unknown option: $1 (see --help)" ;;
	esac
	shift
done

# ---------------------------------------------------------------- MariaDB

mariadb_dir() {
	if [ -n "${WPMCP_MARIADB_BIN:-}" ]; then
		echo "$WPMCP_MARIADB_BIN"
		return
	fi
	if command -v brew >/dev/null 2>&1; then
		for formula in mariadb@10.11 mariadb@11.4 mariadb@10.6 mariadb; do
			local prefix
			prefix=$(brew --prefix "$formula" 2>/dev/null) || continue
			if [ -x "$prefix/bin/mariadbd" ]; then
				echo "$prefix/bin"
				return
			fi
		done
	fi
	if command -v mariadbd >/dev/null 2>&1; then
		dirname "$(command -v mariadbd)"
		return
	fi
	die "MariaDB not found. Install it with: brew install mariadb@10.11 (keg-only, does not conflict with mysql), or set WPMCP_MARIADB_BIN."
}

# ---------------------------------------------------------------- plan

read -r -a versions <<<"$WP_VERSIONS"
default_wp=${versions[0]}
floor_wp=$(sed -n 's/^Requires at least:[[:space:]]*//p' "$ROOT/readme.txt" | head -1 | tr -d '[:space:]')
[ -n "$floor_wp" ] || die "readme.txt has no Requires at least header"

targeted=false
[ ${#phpunit_args[@]} -gt 0 ] && targeted=true

# The legs of this run, in order, one "<kind> [WordPress] [coverage]" each.
LEGS=()
if [ "$only_live_forms" = true ] || [ "$only_live_blocks" = true ] || [ "$only_live_buddypress" = true ] || [ "$only_live_lms" = true ] || [ "$only_live_tablepress" = true ] || [ "$only_live_crm" = true ] || [ "$only_live_polylang" = true ] || [ "$only_live_funnelkit" = true ] || [ "$only_live_breakdance" = true ] || [ "$only_live_memberpress" = true ] || [ "$only_live_divi" = true ] || [ "$only_live_wpbakery" = true ] || [ "$only_live_avada" = true ] || [ "$only_live_oxygen" = true ] || [ "$only_live_amelia" = true ] || [ "$only_live_wc_subscriptions" = true ] || [ "$only_live_adapter" = true ] || [ "$only_live_theme_pro" = true ] || [ "$only_live_wc_bookings" = true ] || [ "$only_live_wp_mail_smtp" = true ]; then
	[ "$only_live_forms" = false ] || LEGS+=("live-forms ${only_wp:-$default_wp}")
	[ "$only_live_blocks" = false ] || LEGS+=("live-blocks ${only_wp:-$default_wp}")
	[ "$only_live_buddypress" = false ] || LEGS+=("live-buddypress ${only_wp:-$default_wp}")
	[ "$only_live_lms" = false ] || LEGS+=("live-lms ${only_wp:-$default_wp}")
	[ "$only_live_tablepress" = false ] || LEGS+=("live-tablepress ${only_wp:-$default_wp}")
	[ "$only_live_crm" = false ] || LEGS+=("live-crm ${only_wp:-$default_wp}")
	[ "$only_live_funnelkit" = false ] || LEGS+=("live-funnelkit ${only_wp:-$default_wp}")
	[ "$only_live_polylang" = false ] || LEGS+=("live-polylang ${only_wp:-$default_wp}")
	[ "$only_live_breakdance" = false ] || LEGS+=("live-breakdance ${only_wp:-$default_wp}")
	[ "$only_live_avada" = false ] || LEGS+=("live-avada ${only_wp:-$default_wp}")
	[ "$only_live_wpbakery" = false ] || LEGS+=("live-wpbakery ${only_wp:-$default_wp}")
	[ "$only_live_oxygen" = false ] || LEGS+=("live-oxygen ${only_wp:-$default_wp}")
	[ "$only_live_divi" = false ] || LEGS+=("live-divi ${only_wp:-$default_wp}")
	[ "$only_live_memberpress" = false ] || LEGS+=("live-memberpress ${only_wp:-$default_wp}")
	[ "$only_live_amelia" = false ] || LEGS+=("live-amelia ${only_wp:-$default_wp}")
	[ "$only_live_wc_subscriptions" = false ] || LEGS+=("live-wc-subscriptions ${only_wp:-$default_wp}")
	[ "$only_live_theme_pro" = false ] || LEGS+=("live-theme-pro ${only_wp:-$default_wp}")
	[ "$only_live_wc_bookings" = false ] || LEGS+=("live-wc-bookings ${only_wp:-$default_wp}")
	[ "$only_live_wp_mail_smtp" = false ] || LEGS+=("live-wp-mail-smtp ${only_wp:-$default_wp}")
	[ "$only_live_adapter" = false ] || LEGS+=("live-adapter ${only_wp:-$default_wp}")
elif [ "$only_multisite" = true ]; then
	LEGS+=("multisite ${only_wp:-$default_wp} none")
elif [ -n "$only_wp" ]; then
	LEGS+=("phpunit $only_wp none")
else
	if [ "$targeted" = false ]; then
		LEGS+=("lint" "drift")
	fi
	if [ "$coverage" = true ] && [ "$targeted" = false ]; then
		LEGS+=("phpunit $default_wp floor")
	else
		LEGS+=("phpunit $default_wp none")
	fi
	# The floor leg (issue #488): part of every plain run, so the pre-push
	# hook and the merge queue gate test the oldest WordPress the plugin
	# claims to support. --all runs every version in WP_VERSIONS instead.
	if [ "$run_all" = true ]; then
		for version in "${versions[@]:1}"; do
			LEGS+=("phpunit $version none")
		done
	elif [ "$targeted" = false ] && [ "$floor_wp" != "$default_wp" ]; then
		LEGS+=("phpunit $floor_wp none")
	fi
	# The multisite leg (issue #530): the whole suite on the default
	# WordPress installed as a network, in every plain run, so a write or a
	# permission check that only breaks on multisite fails before a push.
	if [ "$targeted" = false ]; then
		LEGS+=("multisite $default_wp none")
	fi
	if [ "$targeted" = false ]; then
		LEGS+=("live-forms $default_wp" "live-blocks $default_wp" "live-buddypress $default_wp" "live-lms $default_wp" "live-tablepress $default_wp" "live-crm $default_wp" "live-amelia $default_wp" "live-funnelkit $default_wp" "live-polylang $default_wp" "live-wp-mail-smtp $default_wp")
		if [ -n "${WPMCP_BREAKDANCE_SRC:-}" ]; then
			LEGS+=("live-breakdance $default_wp")
		fi
		if [ -n "${WPMCP_AVADA_SRC:-}" ]; then
			LEGS+=("live-avada $default_wp")
		fi
		if [ -n "${WPMCP_WPBAKERY_SRC:-}" ]; then
			LEGS+=("live-wpbakery $default_wp")
		fi
		if [ -n "${WPMCP_OXYGEN_SRC:-}" ]; then
			LEGS+=("live-oxygen $default_wp")
		fi
		if [ -n "${WPMCP_DIVI_SRC:-}" ]; then
			LEGS+=("live-divi $default_wp")
		fi
		if [ -n "${WPMCP_MEMBERPRESS_SRC:-}" ]; then
			LEGS+=("live-memberpress $default_wp")
		fi
		if [ -n "${WPMCP_WC_SUBSCRIPTIONS_SRC:-}" ]; then
			LEGS+=("live-wc-subscriptions $default_wp")
		fi
		if [ -n "${WPMCP_ASTRA_PRO_SRC:-}${WPMCP_KADENCE_PRO_SRC:-}${WPMCP_GP_PREMIUM_SRC:-}" ]; then
			LEGS+=("live-theme-pro $default_wp")
		fi
		if [ -n "${WPMCP_WC_BOOKINGS_SRC:-}" ]; then
			LEGS+=("live-wc-bookings $default_wp")
		fi
		# The adapter leg (issue #501) under --all only; see the header.
		if [ "$run_all" = true ]; then
			LEGS+=("live-adapter $default_wp")
		fi
	fi
fi

# "phpunit 6.9 none" -> "phpunit wp=6.9 coverage=none"; "live-forms 7.1" -> "live-forms wp=7.1".
leg_label() {
	local kind wp cov
	read -r kind wp cov <<<"$1"
	printf '%s%s%s' "$kind" "${wp:+ wp=$wp}" "${cov:+ coverage=$cov}"
}

if [ "$plan_only" = true ]; then
	for leg in "${LEGS[@]}"; do
		echo "leg: $(leg_label "$leg")"
	done
	echo "on-failure: continue"
	exit 0
fi

# --resolve-plugins and --prune need no database.
BIN=""
[ "$resolve_only" = true ] || [ "$prune_only" = true ] || BIN=$(mariadb_dir)

db_ping() { "$BIN/mariadb-admin" --no-defaults --socket="$SOCK" -uroot ping >/dev/null 2>&1; }

if [ "${stop_db:-false}" = true ]; then
	if db_ping; then
		"$BIN/mariadb-admin" --no-defaults --socket="$SOCK" -uroot shutdown
		say "MariaDB stopped."
	else
		say "MariaDB was not running."
	fi
	exit 0
fi

# Runs "$@" holding an exclusive lock named $1, so parallel runs from several
# worktrees never start the server or install the same WordPress twice. A lock
# whose holder has died is taken over.
with_lock() {
	local name=$1 lock
	shift
	mkdir -p "$CACHE/locks"
	lock="$CACHE/locks/$name"
	for _ in $(seq 1 1800); do
		if mkdir "$lock" 2>/dev/null; then
			echo $$ >"$lock/pid"
			local status=0
			"$@" || status=$?
			rm -rf "$lock"
			return $status
		fi
		local holder
		holder=$(cat "$lock/pid" 2>/dev/null || true)
		if [ -n "$holder" ] && ! kill -0 "$holder" 2>/dev/null; then
			rm -rf "$lock"
			continue
		fi
		sleep 1
	done
	die "timed out waiting for the $name lock ($lock)"
}

start_db() {
	db_ping && return 0
	with_lock db start_db_locked
}

start_db_locked() {
	db_ping && return 0

	# Unix socket paths are capped at 104 bytes on macOS.
	[ ${#SOCK} -lt 100 ] || die "socket path too long (${#SOCK} bytes): set WPMCP_TEST_CACHE to a shorter directory"
	mkdir -p "$DB_DIR"

	if [ ! -d "$DB_DIR/data/mysql" ]; then
		say "Initialising MariaDB in $DB_DIR/data"
		"$BIN/mariadb-install-db" --no-defaults --basedir="$(dirname "$BIN")" --datadir="$DB_DIR/data" \
			--auth-root-authentication-method=normal --skip-test-db >"$DB_DIR/install.log" 2>&1 \
			|| die "mariadb-install-db failed, see $DB_DIR/install.log"
	fi

	say "Starting MariaDB on 127.0.0.1:$PORT"
	nohup "$BIN/mariadbd" --no-defaults --basedir="$(dirname "$BIN")" --datadir="$DB_DIR/data" \
		--port="$PORT" --bind-address=127.0.0.1 --socket="$SOCK" \
		--pid-file="$DB_DIR/mariadbd.pid" --log-error="$DB_DIR/error.log" >/dev/null 2>&1 &

	for _ in $(seq 1 60); do
		db_ping && break
		sleep 0.5
	done
	db_ping || die "MariaDB did not start (is port $PORT taken?), see $DB_DIR/error.log"

	"$BIN/mariadb" --no-defaults --socket="$SOCK" -uroot -e "
		CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
		CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
		GRANT ALL ON *.* TO '$DB_USER'@'127.0.0.1';
		GRANT ALL ON *.* TO '$DB_USER'@'localhost';"
}

# ---------------------------------------------------------------- WordPress

# The flavor of the current environment's install: which plugin set
# bin/install-test-plugins.sh installs.
install_flavor() {
	local flavor=""
	[ "${ELEMENTOR_VERSION:-}" = latest ] && flavor="-elementor-latest"
	# WPMCP_LIVE_FORMS=1 makes bin/install-test-plugins.sh install Contact
	# Form 7 and Flamingo INSTEAD of the usual set, so it gets its own install.
	[ "${WPMCP_LIVE_FORMS:-}" = 1 ] && flavor="-forms-live"
	# Likewise WPMCP_LIVE_BLOCKS=1 and the block suites.
	[ "${WPMCP_LIVE_BLOCKS:-}" = 1 ] && flavor="-blocks-live"
	# And WPMCP_LIVE_BUDDYPRESS=1 and BuddyPress.
	[ "${WPMCP_LIVE_BUDDYPRESS:-}" = 1 ] && flavor="-buddypress-live"
	# And WPMCP_LIVE_LMS=tutor or lifterlms and that one LMS plugin.
	[ -n "${WPMCP_LIVE_LMS:-}" ] && flavor="-${WPMCP_LIVE_LMS}-live"
	# And WPMCP_LIVE_TABLEPRESS=1 and TablePress.
	[ "${WPMCP_LIVE_TABLEPRESS:-}" = 1 ] && flavor="-tablepress-live"
	# And WPMCP_LIVE_CRM=1 and FluentCRM and MailPoet.
	[ "${WPMCP_LIVE_CRM:-}" = 1 ] && flavor="-crm-live"
	# And WPMCP_LIVE_FUNNELKIT=1 and Funnel Builder with WooCommerce.
	[ "${WPMCP_LIVE_FUNNELKIT:-}" = 1 ] && flavor="-funnelkit-live"
	# And WPMCP_LIVE_AMELIA=1 and Amelia Lite.
	[ "${WPMCP_LIVE_AMELIA:-}" = 1 ] && flavor="-amelia-live"
	# And WPMCP_LIVE_BREAKDANCE=1 and the local Breakdance copy.
	[ "${WPMCP_LIVE_BREAKDANCE:-}" = 1 ] && flavor="-breakdance-live"
	# And WPMCP_LIVE_AVADA=1 and the local Avada copies.
	[ "${WPMCP_LIVE_AVADA:-}" = 1 ] && flavor="-avada-live"
	# And WPMCP_LIVE_WPBAKERY=1 and the local WPBakery copy.
	[ "${WPMCP_LIVE_WPBAKERY:-}" = 1 ] && flavor="-wpbakery-live"
	# And WPMCP_LIVE_OXYGEN=1 and the local Oxygen copy.
	[ "${WPMCP_LIVE_OXYGEN:-}" = 1 ] && flavor="-oxygen-live"
	# And WPMCP_LIVE_DIVI=1 and the local Divi theme copy.
	[ "${WPMCP_LIVE_DIVI:-}" = 1 ] && flavor="-divi-live"
	# And WPMCP_LIVE_MEMBERPRESS=1 and the local MemberPress copy.
	[ "${WPMCP_LIVE_MEMBERPRESS:-}" = 1 ] && flavor="-memberpress-live"
	# And WPMCP_LIVE_WC_SUBSCRIPTIONS=1, WooCommerce and the local Subscriptions copy.
	[ "${WPMCP_LIVE_WC_SUBSCRIPTIONS:-}" = 1 ] && flavor="-wc-subscriptions-live"
	# And WPMCP_LIVE_THEME_PRO=<family>, that theme and its local add-on copy.
	[ -n "${WPMCP_LIVE_THEME_PRO:-}" ] && flavor="-${WPMCP_LIVE_THEME_PRO}-pro-live"
	# And WPMCP_LIVE_WC_BOOKINGS=1, WooCommerce and the local Bookings copy.
	[ "${WPMCP_LIVE_WC_BOOKINGS:-}" = 1 ] && flavor="-wc-bookings-live"
	# And WPMCP_LIVE_WP_MAIL_SMTP=1 and WP Mail SMTP.
	[ "${WPMCP_LIVE_WP_MAIL_SMTP:-}" = 1 ] && flavor="-wp-mail-smtp-live"
	echo "$flavor"
}

# Prints the latest release of WordPress.org plugin <slug>, or nothing when
# WordPress.org does not answer.
wporg_latest() {
	local url info version
	url=${WPMCP_PLUGIN_INFO_URL:-'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=%s&request%5Bfields%5D%5Bsections%5D=0'}
	info=$(curl -fsSL --max-time 30 "${url//%s/$1}" 2>/dev/null || true)
	# shellcheck disable=SC2016 # PHP code, expanded by PHP.
	version=$("$PHP" -r '$d = json_decode(stream_get_contents(STDIN), true); echo is_array($d) && isset($d["version"]) && is_string($d["version"]) ? $d["version"] : "";' <<<"$info")
	[[ "$version" =~ ^[0-9]+(\.[0-9]+)*(-[0-9A-Za-z.]+)?$ ]] && echo "$version"
	return 0
}

# Prints the latest release of WordPress.org plugin <slug>, asking
# WordPress.org at most once per WPMCP_TEST_LATEST_TTL_HOURS: the answer is
# kept in $DOWNLOADS/latest/<slug>. When the kept answer is older and
# WordPress.org does not answer, it prints nothing, so the run falls back to
# the newest cached install as before.
latest_release() {
	local slug=$1 file="$DOWNLOADS/latest/$1" version=""
	if [ -s "$file" ] && [ -z "$(find "$file" -mmin +"$((LATEST_TTL_HOURS * 60))" 2>/dev/null)" ] && [ "$LATEST_TTL_HOURS" -gt 0 ]; then
		cat "$file"
		return 0
	fi
	version=$(wporg_latest "$slug")
	if [ -n "$version" ]; then
		mkdir -p "$DOWNLOADS/latest"
		echo "$version" >"$file.tmp.$$"
		mv "$file.tmp.$$" "$file"
	fi
	[ -z "$version" ] || echo "$version"
	return 0
}

# Prints "slug=version" for every WordPress.org plugin the current
# environment's install carries: its pin, or else its latest release. Each
# release is asked for once per run and kept in $RESOLVED, so every leg of a
# run keys on the same versions. Fails when a release cannot be resolved.
plugin_versions() {
	local slug main pinned version
	while read -r slug main pinned; do
		[ -n "$slug" ] || continue
		version=$pinned
		if [ "$pinned" = latest ]; then
			if grep -q "^$slug=" "$RESOLVED" 2>/dev/null; then
				version=$(sed -n "s/^$slug=//p" "$RESOLVED" | head -1)
			else
				version=$(latest_release "$slug")
				echo "$slug=$version" >>"$RESOLVED"
			fi
			[ -n "$version" ] || return 1
		fi
		echo "$slug=$version"
	done < <(bash "$ROOT/bin/install-test-plugins.sh" --list)
}

# The newest ready install keyed on plugin versions under <base>, if any.
newest_install() {
	local d best=""
	for d in "$1"-plugins-*/; do
		d=${d%/}
		[ -f "$d/.ready" ] || continue
		if [ -z "$best" ] || [ "$d/.ready" -nt "$best/.ready" ]; then
			best=$d
		fi
	done
	echo "$best"
}

# Prints the install directory for WordPress <version> in the current
# environment. The installers' hash is part of the directory, not a stamp
# inside it: branches carrying different installers (a moved plugin pin, say)
# get separate installs, so one run never deletes an install another run is
# testing against. So are the plugin versions (issue #532), so a new release
# gets an install of its own. When WordPress.org does not answer, it is the
# newest cached install of these installers.
install_target() {
	local version=$1 base pins dir
	base="$CACHE/wp-$version$(install_flavor)-$(cat "$ROOT/bin/install-wp-tests.sh" "$ROOT/bin/install-test-plugins.sh" "$ROOT/bin/download-cache.sh" | shasum | cut -c1-12)"
	if pins=$(plugin_versions); then
		echo "$base-plugins-$(printf '%s\n' "$pins" | shasum | cut -c1-8)"
		return 0
	fi
	dir=$(newest_install "$base")
	[ -n "$dir" ] || die "could not ask WordPress.org for the latest test plugin releases, and no $(basename "$base") install is cached"
	say "WordPress.org did not answer; testing the newest cached install, $(basename "$dir")." >&2
	echo "$dir"
}

# Installs WordPress <version> once and prints the install directory.
install_wp() {
	local version=$1 dir
	dir=$(install_target "$version") || exit 1
	if [ ! -f "$dir/.ready" ]; then
		with_lock "install-$(basename "$dir")" install_wp_locked "$version" "$dir"
	fi
	# Claims the install for this run, so no prune removes it before the run
	# exits, and marks it used for the least-recently-used order.
	with_lock prune claim_install "$dir" || die "the install $(basename "$dir") was pruned before this run could claim it; run again"
	touch "$dir/.last-used"
	plugin_note "$dir"
	echo "$dir"
}

install_wp_locked() {
	local version=$1 dir=$2 pins from
	[ -f "$dir/.ready" ] && return 0
	command -v svn >/dev/null 2>&1 || die "svn is required by bin/install-wp-tests.sh: brew install subversion"
	space_guard >&2
	pins=$(plugin_versions | tr '\n' ' ') || die "could not resolve the test plugin versions"
	from=$(newest_install "${dir%-plugins-*}")
	# The install copied from is claimed too, so a prune cannot remove it mid-copy.
	if [ -n "$from" ] && ! with_lock prune claim_install "$from"; then
		from=""
	fi
	rm -rf "$dir"
	mkdir -p "$dir/tmp"
	if [ -n "$from" ]; then
		# Only the plugins whose version moved are downloaded again: the
		# installer keeps a plugin already present at the wanted version.
		say "Updating $(basename "$from") into $dir (plugins: $pins)" >&2
		clone_dir "$from/wordpress" "$dir/wordpress"
		clone_dir "$from/wordpress-tests-lib" "$dir/wordpress-tests-lib"
		rm -f "$from/.users/$$"
		TMPDIR="$dir/tmp" WP_CORE_DIR="$dir/wordpress/" WPMCP_PLUGIN_VERSIONS="$pins" \
			WPMCP_DOWNLOAD_CACHE="$DOWNLOADS" WPMCP_TEST_LATEST_TTL_HOURS="$LATEST_TTL_HOURS" \
			bash "$ROOT/bin/install-test-plugins.sh" >"$dir/install.log" 2>&1 || die "updating the test plugins failed, see $dir/install.log"
	else
		say "Installing WordPress $version into $dir (plugins: $pins)" >&2
		(
			export TMPDIR="$dir/tmp" WP_CORE_DIR="$dir/wordpress/" WP_TESTS_DIR="$dir/wordpress-tests-lib" WPMCP_PLUGIN_VERSIONS="$pins"
			export WPMCP_DOWNLOAD_CACHE="$DOWNLOADS" WPMCP_TEST_LATEST_TTL_HOURS="$LATEST_TTL_HOURS"
			bash "$ROOT/bin/install-wp-tests.sh" wpmcp_unused "$DB_USER" "$DB_PASS" "127.0.0.1:$PORT" "$version" true
			bash "$ROOT/bin/install-test-plugins.sh"
		) >"$dir/install.log" 2>&1 || die "WordPress $version install failed, see $dir/install.log"
	fi
	touch "$dir/.last-used" "$dir/.ready"
	prune_installs "$dir"
}

# Copies directory <from> to <to>: an instant copy-on-write clone on APFS.
clone_dir() {
	cp -cR "$1" "$2" 2>/dev/null || { rm -rf "$2"; cp -R "$1" "$2"; }
}

# ---------------------------------------------------------------- cache budget

# Prints the modification time of <file> in epoch seconds, 0 when it is missing.
mtime() { stat -c %Y "$1" 2>/dev/null || stat -f %m "$1" 2>/dev/null || echo 0; }

pid_alive() { [ -n "$1" ] && kill -0 "$1" 2>/dev/null; }

gb_to_kb() { awk -v g="$1" 'BEGIN { printf "%d\n", g * 1048576 }'; }
kb_to_gb() { awk -v k="$1" 'BEGIN { printf "%.1f\n", k / 1048576 }'; }

# True when lock <name> is held by a live process, or is being taken (no pid
# written yet).
lock_held() {
	local lock="$CACHE/locks/$1" holder
	[ -d "$lock" ] || return 1
	holder=$(cat "$lock/pid" 2>/dev/null || true)
	[ -z "$holder" ] || pid_alive "$holder"
}

# True when a live run has claimed install <dir>, or holds its install or
# pristine lock. Claims of runs that are gone are dropped on the way.
install_in_use() {
	local d=$1 name f
	name=$(basename "$d")
	lock_held "install-$name" && return 0
	lock_held "pristine-$name" && return 0
	for f in "$d/.users/"*; do
		[ -e "$f" ] || continue
		pid_alive "$(basename "$f")" && return 0
		rm -f "$f"
	done
	return 1
}

# Claims ready install <dir> for this run (under the prune lock); fails when it
# is gone. The claim is dropped when the run exits.
claim_install() {
	[ -f "$1/.ready" ] || return 1
	mkdir -p "$1/.users"
	touch "$1/.users/$$"
}

# Prunes the installs keyed on plugin versions under the prune lock; see
# prune_installs_locked.
prune_installs() {
	with_lock prune prune_installs_locked "$@"
}

# Deletes installs keyed on plugin versions, least recently used first (by
# .last-used), until at most <count> (default WPMCP_TEST_KEEP_INSTALLS) and
# WPMCP_TEST_MAX_GB in all are left, and every install unused for
# WPMCP_TEST_INSTALL_TTL_HOURS. Builds that died before .ready are deleted
# too. Never deleted: <keep>, an install in use (install_in_use), and one used
# in the last <recent> seconds (default RECENT_USE). Installs that cannot go
# still count, so the budget can be exceeded by what is in use. Installs from
# before issue #532 have no plugins key and are left to --prune --all.
prune_installs_locked() {
	local keep=${1:-} max_count=${2:-$KEEP_INSTALLS} recent=${3:-$RECENT_USE}
	local now ttl max_kb d used kb count=0 total=0 rows=()
	now=$(date +%s)
	ttl=$((INSTALL_TTL_HOURS * 3600))
	max_kb=$(gb_to_kb "$MAX_GB")
	for d in "$CACHE"/wp-*-plugins-*/; do
		d=${d%/}
		[ -d "$d" ] || continue
		if [ ! -f "$d/.ready" ]; then
			if [ "$d" != "$keep" ] && ! install_in_use "$d"; then
				say "Removing the unfinished install $(basename "$d")" >&2
				rm -rf "$d"
			fi
			continue
		fi
		rows+=("$(mtime "$d/.last-used") $d")
	done
	[ ${#rows[@]} -gt 0 ] || return 0
	while read -r used d; do
		if [ "$d" = "$keep" ] || [ $((now - used)) -lt "$recent" ] || install_in_use "$d"; then
			:
		elif [ $((now - used)) -ge "$ttl" ]; then
			say "Removing the install $(basename "$d"), unused for $(((now - used) / 3600)) hours (WPMCP_TEST_INSTALL_TTL_HOURS=$INSTALL_TTL_HOURS)" >&2
			rm -rf "$d"
			continue
		else
			kb=$(du -sk "$d" 2>/dev/null | cut -f1 || true)
			kb=${kb:-0}
			if [ $((count + 1)) -gt "$max_count" ] || [ $((total + kb)) -gt "$max_kb" ]; then
				say "Removing the least recently used install $(basename "$d") (budget: $max_count installs, $MAX_GB GB)" >&2
				rm -rf "$d"
				continue
			fi
			count=$((count + 1))
			total=$((total + kb))
			continue
		fi
		kb=$(du -sk "$d" 2>/dev/null | cut -f1 || true)
		count=$((count + 1))
		total=$((total + ${kb:-0}))
	done < <(printf '%s\n' "${rows[@]}" | sort -rn)
}

# --prune --all: deletes every install, keyed on plugin versions or from
# before issue #532, that no live run is using.
prune_all_installs() {
	local d
	for d in "$CACHE"/wp-*/; do
		d=${d%/}
		[ -d "$d" ] || continue
		if install_in_use "$d"; then
			say "Keeping $(basename "$d"): a live run is using it"
			continue
		fi
		say "Removing the install $(basename "$d")"
		rm -rf "$d"
	done
}

# Deletes the per-run core copies ($CACHE/cores/<hash>-<pid>) of runs that are
# no longer alive, and any older than a day (a reused pid).
prune_cores() {
	local c pid
	for c in "$CACHE/cores/"*; do
		[ -d "$c" ] || continue
		pid=${c##*-}
		if [[ "$pid" =~ ^[0-9]+$ ]] && ! pid_alive "$pid"; then
			rm -rf "$c"
		fi
	done
	find "$CACHE/cores" -mindepth 1 -maxdepth 1 -type d -mtime +1 -exec rm -rf {} + 2>/dev/null || true
}

# Prunes the download cache ($DOWNLOADS/<kind>/<name>, see
# bin/download-cache.sh): an entry whose last use (its mtime) is older than
# WPMCP_TEST_DOWNLOAD_TTL_DAYS goes, then the least recently used until
# WPMCP_TEST_DOWNLOAD_MAX_GB is left. An entry is only removed under its own
# lock, so one an installer is fetching or reading (it holds that lock) is
# never touched. Leftover .tmp.<pid> files of dead fetches go too.
prune_downloads() {
	local entry name lock now ttl max_kb kb total=0 rows=() used pid
	[ -d "$DOWNLOADS" ] || return 0
	now=$(date +%s)
	ttl=$((DOWNLOAD_TTL_DAYS * 86400))
	max_kb=$(gb_to_kb "$DOWNLOAD_MAX_GB")
	for entry in "$DOWNLOADS"/*/*; do
		[ -e "$entry" ] || continue
		case "$entry" in
			"$DOWNLOADS"/latest/*) continue ;;
			*.fetched) continue ;;
			*.tmp.*)
				pid=${entry##*.tmp.}
				pid_alive "$pid" || rm -rf "$entry"
				continue ;;
		esac
		rows+=("$(mtime "$entry") $entry")
	done
	[ ${#rows[@]} -gt 0 ] || return 0
	while read -r used entry; do
		kb=$(du -sk "$entry" 2>/dev/null | cut -f1 || true)
		kb=${kb:-0}
		if [ $((now - used)) -lt "$ttl" ] && [ $((total + kb)) -le "$max_kb" ]; then
			total=$((total + kb))
			continue
		fi
		name=${entry#"$DOWNLOADS"/}
		lock="$DOWNLOADS/.locks/${name//\//__}"
		mkdir -p "$DOWNLOADS/.locks"
		if [ -d "$lock" ] && ! pid_alive "$(cat "$lock/pid" 2>/dev/null || true)" && [ -s "$lock/pid" ]; then
			rm -rf "$lock"
		fi
		if ! mkdir "$lock" 2>/dev/null; then
			# In use: it still counts.
			total=$((total + kb))
			continue
		fi
		echo $$ >"$lock/pid"
		say "Removing the cached download $name (budget: $DOWNLOAD_TTL_DAYS days unused, $DOWNLOAD_MAX_GB GB)" >&2
		rm -rf "$entry" "$entry.fetched"
		rm -rf "$lock"
	done < <(printf '%s\n' "${rows[@]}" | sort -rn)
}

# Prints the free space on the cache's volume in KB, nothing when df cannot tell.
free_kb() {
	mkdir -p "$CACHE"
	df -Pk "$CACHE" 2>/dev/null | awk 'NR == 2 && $4 ~ /^[0-9]+$/ { print $4 }' || true
}

# The free-space guard: under WPMCP_TEST_MIN_FREE_GB free, prunes the cache to
# the two most recently used installs (in use or not, nothing in use goes);
# under WPMCP_TEST_HARD_MIN_FREE_GB after that, stops the run.
space_guard() {
	local free
	free=$(free_kb)
	[ -n "$free" ] || return 0
	[ "$free" -lt "$(gb_to_kb "$MIN_FREE_GB")" ] || return 0
	say "Only $(kb_to_gb "$free") GB free on the volume holding $CACHE (WPMCP_TEST_MIN_FREE_GB=$MIN_FREE_GB): pruning the test cache to the 2 most recently used installs."
	with_lock prune prune_installs_locked "" 2 0 >&2
	prune_cores
	prune_downloads
	free=$(free_kb)
	[ -z "$free" ] || [ "$free" -ge "$(gb_to_kb "$HARD_MIN_FREE_GB")" ] \
		|| die "Only $(kb_to_gb "$free") GB free on the volume holding $CACHE, under WPMCP_TEST_HARD_MIN_FREE_GB=$HARD_MIN_FREE_GB even after pruning the test cache. Free some disk space (bin/test-local.sh --prune --all removes every install no run is using) and run again."
}

# Takes one of WPMCP_TEST_MAX_PARALLEL run slots ($CACHE/locks/run-<n>), shared
# by every worktree, waiting up to WPMCP_TEST_SLOT_TIMEOUT seconds for one. A
# slot whose holder has died is taken over. The slot is released by
# finish_run, with every other lock this run holds.
acquire_slot() {
	local n lock holder holders waited=0 said=false
	mkdir -p "$CACHE/locks"
	while :; do
		holders=""
		for n in $(seq 1 "$MAX_PARALLEL"); do
			lock="$CACHE/locks/run-$n"
			if mkdir "$lock" 2>/dev/null; then
				echo $$ >"$lock/pid"
				return 0
			fi
			holder=$(cat "$lock/pid" 2>/dev/null || true)
			if [ -n "$holder" ] && ! pid_alive "$holder"; then
				rm -rf "$lock"
				if mkdir "$lock" 2>/dev/null; then
					echo $$ >"$lock/pid"
					return 0
				fi
			fi
			holders+="${holders:+, }${holder:-?}"
		done
		[ "$waited" -lt "$SLOT_TIMEOUT" ] \
			|| die "timed out after ${SLOT_TIMEOUT}s waiting for a run slot (WPMCP_TEST_MAX_PARALLEL=$MAX_PARALLEL, WPMCP_TEST_SLOT_TIMEOUT=$SLOT_TIMEOUT; held by pid $holders)"
		if [ "$said" = false ]; then
			say "Waiting for a run slot: $MAX_PARALLEL runs are going already (pid $holders; WPMCP_TEST_MAX_PARALLEL=$MAX_PARALLEL), up to ${SLOT_TIMEOUT}s." >&2
			said=true
		fi
		sleep 2
		waited=$((waited + 2))
	done
}

# Prints how big the cache is.
cache_report() {
	local n=0 d kb free
	for d in "$CACHE"/wp-*/; do [ -d "$d" ] && n=$((n + 1)); done
	kb=$(du -sk "$CACHE" 2>/dev/null | cut -f1 || true)
	free=$(free_kb)
	say "The test cache holds $n installs, $(kb_to_gb "${kb:-0}") GB in all${free:+; $(kb_to_gb "$free") GB free on its volume}."
}

# Adds the versions of the plugins in <install dir> to this leg's summary
# line, as installed (their Version headers).
plugin_note() {
	local dir=$1 slug main pinned v line=""
	[ -n "${LEG_NOTE:-}" ] || return 0
	while read -r slug main pinned; do
		[ -n "$slug" ] || continue
		v=$(sed -n 's/^[[:space:]*]*Version:[[:space:]]*//p' "$dir/wordpress/wp-content/plugins/$slug/$main" 2>/dev/null | head -1 | tr -d '[:space:]')
		line+="${line:+, }$slug ${v:-unknown}"
	done < <(bash "$ROOT/bin/install-test-plugins.sh" --list)
	[ -z "$line" ] || echo "$line" >>"$LEG_NOTE"
}

# Gives this run its own copy of the WordPress install and prints its path.
# Tests write into wp-content (uploads, backups, filesystem fixtures), so
# parallel runs from several worktrees must not share one core, and each run
# starts from a clean one the way a CI job would. The copy comes from a
# pristine source that no run ever uses directly (its wp-content keeps only
# what an install ships), and on APFS it is a copy-on-write clone: instant,
# and it takes no space until a file is written.
checkout_core() {
	local dir=$1 core
	[ -f "$dir/pristine/wp-load.php" ] || with_lock "pristine-$(basename "$dir")" make_pristine "$dir"
	# Per run, not per checkout: two runs started in the same worktree must not
	# share a core either. The copy is removed when the run exits, and copies
	# left by killed runs are pruned once their pid is gone.
	core="$CACHE/cores/$(printf '%s|%s' "$ROOT" "$dir" | shasum | cut -c1-12)-$$"
	mkdir -p "$CACHE/cores"
	prune_cores
	rm -rf "$core"
	cp -cR "$dir/pristine" "$core" 2>/dev/null || cp -R "$dir/pristine" "$core"
	echo "$core"
}

make_pristine() {
	local dir=$1 entry
	[ -f "$dir/pristine/wp-load.php" ] && return 0
	rm -rf "$dir/pristine.tmp"
	cp -cR "$dir/wordpress" "$dir/pristine.tmp" 2>/dev/null || cp -R "$dir/wordpress" "$dir/pristine.tmp"
	for entry in "$dir/pristine.tmp/wp-content/"*; do
		case "$(basename "$entry")" in
			plugins | themes | languages | mu-plugins | db.php | index.php) ;;
			*) rm -rf "$entry" ;;
		esac
	done
	mv "$dir/pristine.tmp" "$dir/pristine"
}

# Creates this checkout's database for <install dir> and prints a tests config
# pointing at it. The name hashes the checkout path, so each worktree gets its own.
db_config() {
	local dir=$1 version=$2 core=$3 name config
	# Per run: parallel runs, even in one checkout, never share a database or
	# a config. Both are removed when the run exits.
	name="wpmcp_test_$(printf '%s|%s' "$ROOT" "$version" | shasum | cut -c1-12)_$$"
	"$BIN/mariadb" --no-defaults --socket="$SOCK" -uroot -e "CREATE DATABASE IF NOT EXISTS \`$name\`"
	mkdir -p "$CACHE/configs"
	config="$CACHE/configs/$name.php"
	sed -E \
		-e "s/define\( *'DB_NAME', *'[^']*' *\)/define( 'DB_NAME', '$name' )/" \
		-e "s/define\( *'DB_USER', *'[^']*' *\)/define( 'DB_USER', '$DB_USER' )/" \
		-e "s/define\( *'DB_PASSWORD', *'[^']*' *\)/define( 'DB_PASSWORD', '$DB_PASS' )/" \
		-e "s/define\( *'DB_HOST', *'[^']*' *\)/define( 'DB_HOST', '127.0.0.1:$PORT' )/" \
		-e "s#define\( *'ABSPATH', *'[^']*' *\)#define( 'ABSPATH', '$core/' )#" \
		"$dir/wordpress-tests-lib/wp-tests-config.php" >"$config"
	grep -q "'$name'" "$config" || die "could not write the DB name into $config"
	grep -q "'ABSPATH', '$core/'" "$config" || die "could not write ABSPATH into $config"
	echo "$config"
}

# ---------------------------------------------------------------- run

run_suite() {
	local version=$1 with_coverage=$2 dir core config
	dir=$(install_wp "$version")
	core=$(checkout_core "$dir")
	RUN_CORES+=("$core")
	config=$(db_config "$dir" "$version" "$core")
	RUN_CONFIGS+=("$config")
	say "PHPUnit on WordPress $version${ELEMENTOR_VERSION:+ (Elementor $ELEMENTOR_VERSION)}"
	local args=("${phpunit_args[@]+"${phpunit_args[@]}"}")
	if [ "$with_coverage" = true ]; then
		args+=(--coverage-clover coverage/clover.xml)
	fi
	# WP_CORE_DIR is read at run time too: tests/support/plugins.php finds the
	# optional plugins through it.
	WP_TESTS_DIR="$dir/wordpress-tests-lib" WP_TESTS_CONFIG_FILE_PATH="$config" WP_CORE_DIR="$core/" \
		"$PHP" vendor/bin/phpunit "${args[@]+"${args[@]}"}"
	if [ "$with_coverage" = true ]; then
		"$PHP" bin/check-coverage.php coverage/clover.xml "$COVERAGE_FLOOR"
	fi
}

# The multisite leg (issue #530): the suite on the shared install with its
# own database, which the WordPress test library installs as a multisite
# network because WP_MULTISITE=1 is exported. Tests that only make sense on
# a single site skip themselves with is_multisite().
run_multisite() {
	local version=$1 dir core config
	dir=$(install_wp "$version")
	core=$(checkout_core "$dir")
	RUN_CORES+=("$core")
	config=$(db_config "$dir" "$version-multisite" "$core")
	RUN_CONFIGS+=("$config")
	say "PHPUnit on WordPress $version as a multisite network"
	WP_MULTISITE=1 WP_TESTS_DIR="$dir/wordpress-tests-lib" WP_TESTS_CONFIG_FILE_PATH="$config" WP_CORE_DIR="$core/" \
		"$PHP" vendor/bin/phpunit "${phpunit_args[@]+"${phpunit_args[@]}"}"
}

# The live forms group (issue #66) on its own install and database. It runs
# with --fail-on-skipped: the test skips itself when the real plugins are
# missing, and a skip here would mean the check never ran.
run_live_forms() {
	local version=$1 dir core config
	export WPMCP_LIVE_FORMS=1
	dir=$(install_wp "$version")
	core=$(checkout_core "$dir")
	RUN_CORES+=("$core")
	config=$(db_config "$dir" "$version-forms-live" "$core")
	RUN_CONFIGS+=("$config")
	say "PHPUnit live forms group on WordPress $version (real Contact Form 7 + Flamingo)"
	WP_TESTS_DIR="$dir/wordpress-tests-lib" WP_TESTS_CONFIG_FILE_PATH="$config" WP_CORE_DIR="$core/" \
		"$PHP" vendor/bin/phpunit --group forms-live --fail-on-skipped
	unset WPMCP_LIVE_FORMS
}

# The live blocks group (issue #287), the same way: its own install with the
# real block suites, its own database, and --fail-on-skipped.
run_live_blocks() {
	local version=$1 dir core config
	export WPMCP_LIVE_BLOCKS=1
	dir=$(install_wp "$version")
	core=$(checkout_core "$dir")
	RUN_CORES+=("$core")
	config=$(db_config "$dir" "$version-blocks-live" "$core")
	RUN_CONFIGS+=("$config")
	say "PHPUnit live blocks group on WordPress $version (real Kadence Blocks, GenerateBlocks, Spectra, Otter Blocks)"
	WP_TESTS_DIR="$dir/wordpress-tests-lib" WP_TESTS_CONFIG_FILE_PATH="$config" WP_CORE_DIR="$core/" \
		"$PHP" vendor/bin/phpunit --group blocks-live --fail-on-skipped
	unset WPMCP_LIVE_BLOCKS
}

# The live BuddyPress group (issue #363), the same way: its own install with
# the real BuddyPress, its own database, and --fail-on-skipped.
run_live_buddypress() {
	local version=$1 dir core config
	export WPMCP_LIVE_BUDDYPRESS=1
	dir=$(install_wp "$version")
	core=$(checkout_core "$dir")
	RUN_CORES+=("$core")
	config=$(db_config "$dir" "$version-buddypress-live" "$core")
	RUN_CONFIGS+=("$config")
	say "PHPUnit live BuddyPress group on WordPress $version (real BuddyPress)"
	WP_TESTS_DIR="$dir/wordpress-tests-lib" WP_TESTS_CONFIG_FILE_PATH="$config" WP_CORE_DIR="$core/" \
		"$PHP" vendor/bin/phpunit --group buddypress-live --fail-on-skipped
	unset WPMCP_LIVE_BUDDYPRESS
}

# The live LMS groups (issue #394), the same way, once per plugin: Tutor LMS
# and LifterLMS both register a "lesson" post type, so each gets its own
# install, its own database and its own group, with --fail-on-skipped.
run_live_lms() {
	local version=$1 lms dir core config
	for lms in tutor lifterlms; do
		export WPMCP_LIVE_LMS=$lms
		dir=$(install_wp "$version")
		core=$(checkout_core "$dir")
		RUN_CORES+=("$core")
		config=$(db_config "$dir" "$version-$lms-live" "$core")
		RUN_CONFIGS+=("$config")
		say "PHPUnit live $lms group on WordPress $version (real $lms)"
		WP_TESTS_DIR="$dir/wordpress-tests-lib" WP_TESTS_CONFIG_FILE_PATH="$config" WP_CORE_DIR="$core/" \
			"$PHP" vendor/bin/phpunit --group "$lms-live" --fail-on-skipped
		unset WPMCP_LIVE_LMS
	done
}

# The live TablePress group (issue #481), the same way: its own install with
# the real TablePress, its own database, and --fail-on-skipped.
run_live_tablepress() {
	local version=$1 dir core config
	export WPMCP_LIVE_TABLEPRESS=1
	dir=$(install_wp "$version")
	core=$(checkout_core "$dir")
	RUN_CORES+=("$core")
	config=$(db_config "$dir" "$version-tablepress-live" "$core")
	RUN_CONFIGS+=("$config")
	say "PHPUnit live TablePress group on WordPress $version (real TablePress)"
	WP_TESTS_DIR="$dir/wordpress-tests-lib" WP_TESTS_CONFIG_FILE_PATH="$config" WP_CORE_DIR="$core/" \
		"$PHP" vendor/bin/phpunit --group tablepress-live --fail-on-skipped
	unset WPMCP_LIVE_TABLEPRESS
}

# The live WP Mail SMTP group, the same way: its own install with the real
# WP Mail SMTP, its own database, and --fail-on-skipped.
run_live_wp_mail_smtp() {
	local version=$1 dir core config
	export WPMCP_LIVE_WP_MAIL_SMTP=1
	dir=$(install_wp "$version")
	core=$(checkout_core "$dir")
	RUN_CORES+=("$core")
	config=$(db_config "$dir" "$version-wp-mail-smtp-live" "$core")
	RUN_CONFIGS+=("$config")
	say "PHPUnit live WP Mail SMTP group on WordPress $version (real WP Mail SMTP)"
	WP_TESTS_DIR="$dir/wordpress-tests-lib" WP_TESTS_CONFIG_FILE_PATH="$config" WP_CORE_DIR="$core/" \
		"$PHP" vendor/bin/phpunit --group wp-mail-smtp-live --fail-on-skipped
	unset WPMCP_LIVE_WP_MAIL_SMTP
}

# The live CRM group (issue #477), the same way: its own install with the real
# FluentCRM and MailPoet, its own database, and --fail-on-skipped.
run_live_crm() {
	local version=$1 dir core config
	export WPMCP_LIVE_CRM=1
	dir=$(install_wp "$version")
	core=$(checkout_core "$dir")
	RUN_CORES+=("$core")
	config=$(db_config "$dir" "$version-crm-live" "$core")
	RUN_CONFIGS+=("$config")
	say "PHPUnit live CRM group on WordPress $version (real FluentCRM and MailPoet)"
	WP_TESTS_DIR="$dir/wordpress-tests-lib" WP_TESTS_CONFIG_FILE_PATH="$config" WP_CORE_DIR="$core/" \
		"$PHP" vendor/bin/phpunit --group crm-live --fail-on-skipped
	unset WPMCP_LIVE_CRM
}

# The live FunnelKit group (issue #500), the same way: its own install with
# the real Funnel Builder and WooCommerce, its own database, and
# --fail-on-skipped.
run_live_funnelkit() {
	local version=$1 dir core config
	export WPMCP_LIVE_FUNNELKIT=1
	dir=$(install_wp "$version")
	core=$(checkout_core "$dir")
	RUN_CORES+=("$core")
	config=$(db_config "$dir" "$version-funnelkit-live" "$core")
	RUN_CONFIGS+=("$config")
	say "PHPUnit live FunnelKit group on WordPress $version (real Funnel Builder + WooCommerce)"
	WP_TESTS_DIR="$dir/wordpress-tests-lib" WP_TESTS_CONFIG_FILE_PATH="$config" WP_CORE_DIR="$core/" \
		"$PHP" vendor/bin/phpunit --group funnelkit-live --fail-on-skipped
	unset WPMCP_LIVE_FUNNELKIT
}

# The live Amelia group (issue #499), the same way: its own install with the
# real Amelia Lite, its own database, and --fail-on-skipped.
run_live_amelia() {
	local version=$1 dir core config
	export WPMCP_LIVE_AMELIA=1
	dir=$(install_wp "$version")
	core=$(checkout_core "$dir")
	RUN_CORES+=("$core")
	config=$(db_config "$dir" "$version-amelia-live" "$core")
	RUN_CONFIGS+=("$config")
	say "PHPUnit live Amelia group on WordPress $version (real Amelia Lite)"
	WP_TESTS_DIR="$dir/wordpress-tests-lib" WP_TESTS_CONFIG_FILE_PATH="$config" WP_CORE_DIR="$core/" \
		"$PHP" vendor/bin/phpunit --group amelia-live --fail-on-skipped
	unset WPMCP_LIVE_AMELIA
}

# The live Polylang group (issue #498): Polylang and Elementor are already in
# the shared install, so it runs on that install with its own database, with
# WPMCP_LIVE_POLYLANG=1 booting Polylang in tests/bootstrap.php, and
# --fail-on-skipped.
run_live_polylang() {
	local version=$1 dir core config
	dir=$(install_wp "$version")
	core=$(checkout_core "$dir")
	RUN_CORES+=("$core")
	config=$(db_config "$dir" "$version-polylang-live" "$core")
	RUN_CONFIGS+=("$config")
	say "PHPUnit live Polylang group on WordPress $version (real Polylang and Elementor)"
	WPMCP_LIVE_POLYLANG=1 WP_TESTS_DIR="$dir/wordpress-tests-lib" WP_TESTS_CONFIG_FILE_PATH="$config" WP_CORE_DIR="$core/" \
		"$PHP" vendor/bin/phpunit --group polylang-live --fail-on-skipped
}

# The live Breakdance group (issue #457), the same way, with the Breakdance
# copy from WPMCP_BREAKDANCE_SRC: its own install, its own database and
# --fail-on-skipped.
run_live_breakdance() {
	local version=$1 dir core config
	[ -f "${WPMCP_BREAKDANCE_SRC:-}/plugin.php" ] || die "the live Breakdance group needs WPMCP_BREAKDANCE_SRC, a directory holding the Breakdance plugin"
	export WPMCP_LIVE_BREAKDANCE=1
	dir=$(install_wp "$version")
	core=$(checkout_core "$dir")
	RUN_CORES+=("$core")
	config=$(db_config "$dir" "$version-breakdance-live" "$core")
	RUN_CONFIGS+=("$config")
	say "PHPUnit live Breakdance group on WordPress $version (real Breakdance from $WPMCP_BREAKDANCE_SRC)"
	WP_TESTS_DIR="$dir/wordpress-tests-lib" WP_TESTS_CONFIG_FILE_PATH="$config" WP_CORE_DIR="$core/" \
		"$PHP" vendor/bin/phpunit --group breakdance-live --fail-on-skipped
	unset WPMCP_LIVE_BREAKDANCE
}

# The live theme add-on group (issue #656): one run per add-on whose local
# copy is named (WPMCP_ASTRA_PRO_SRC, WPMCP_KADENCE_PRO_SRC,
# WPMCP_GP_PREMIUM_SRC), each on its own install with its free theme active,
# its own database and --fail-on-skipped.
run_live_theme_pro() {
	local version=$1 family src dir core config ran=false
	for family in astra kadence generatepress; do
		case "$family" in
			astra) src=${WPMCP_ASTRA_PRO_SRC:-} ;;
			kadence) src=${WPMCP_KADENCE_PRO_SRC:-} ;;
			generatepress) src=${WPMCP_GP_PREMIUM_SRC:-} ;;
		esac
		[ -n "$src" ] || continue
		ran=true
		export WPMCP_LIVE_THEME_PRO=$family
		dir=$(install_wp "$version")
		core=$(checkout_core "$dir")
		RUN_CORES+=("$core")
		config=$(db_config "$dir" "$version-$family-pro-live" "$core")
		RUN_CONFIGS+=("$config")
		say "PHPUnit live $family add-on group on WordPress $version (real add-on from $src)"
		WP_TESTS_DIR="$dir/wordpress-tests-lib" WP_TESTS_CONFIG_FILE_PATH="$config" WP_CORE_DIR="$core/" \
			"$PHP" vendor/bin/phpunit --group "$family-pro-live" --fail-on-skipped
		unset WPMCP_LIVE_THEME_PRO
	done
	[ "$ran" = true ] || die "the live theme add-on group needs WPMCP_ASTRA_PRO_SRC, WPMCP_KADENCE_PRO_SRC or WPMCP_GP_PREMIUM_SRC"
}

# The live Avada group (issue #637), the same way, with the Avada theme and
# the fusion-builder and fusion-core plugins from WPMCP_AVADA_SRC: its own
# install, its own database and --fail-on-skipped.
run_live_avada() {
	local version=$1 dir core config
	[ -f "${WPMCP_AVADA_SRC:-}/fusion-builder/fusion-builder.php" ] || die "the live Avada group needs WPMCP_AVADA_SRC, a directory holding Avada/, fusion-builder/ and fusion-core/"
	export WPMCP_LIVE_AVADA=1
	dir=$(install_wp "$version")
	core=$(checkout_core "$dir")
	RUN_CORES+=("$core")
	config=$(db_config "$dir" "$version-avada-live" "$core")
	RUN_CONFIGS+=("$config")
	say "PHPUnit live Avada group on WordPress $version (real Avada from $WPMCP_AVADA_SRC)"
	WP_TESTS_DIR="$dir/wordpress-tests-lib" WP_TESTS_CONFIG_FILE_PATH="$config" WP_CORE_DIR="$core/" \
		"$PHP" vendor/bin/phpunit --group avada-live --fail-on-skipped
	unset WPMCP_LIVE_AVADA
}

# The live WPBakery group (issue #639), the same way, with the WPBakery copy
# from WPMCP_WPBAKERY_SRC: its own install, its own database and
# --fail-on-skipped.
run_live_wpbakery() {
	local version=$1 dir core config
	[ -f "${WPMCP_WPBAKERY_SRC:-}/js_composer.php" ] || die "the live WPBakery group needs WPMCP_WPBAKERY_SRC, a directory holding the WPBakery plugin (js_composer.php)"
	export WPMCP_LIVE_WPBAKERY=1
	dir=$(install_wp "$version")
	core=$(checkout_core "$dir")
	RUN_CORES+=("$core")
	config=$(db_config "$dir" "$version-wpbakery-live" "$core")
	RUN_CONFIGS+=("$config")
	say "PHPUnit live WPBakery group on WordPress $version (real WPBakery from $WPMCP_WPBAKERY_SRC)"
	WP_TESTS_DIR="$dir/wordpress-tests-lib" WP_TESTS_CONFIG_FILE_PATH="$config" WP_CORE_DIR="$core/" \
		"$PHP" vendor/bin/phpunit --group wpbakery-live --fail-on-skipped
	unset WPMCP_LIVE_WPBAKERY
}

# The live Oxygen group (issue #635), the same way, with the Oxygen 6 copy
# from WPMCP_OXYGEN_SRC: its own install, its own database and
# --fail-on-skipped.
run_live_oxygen() {
	local version=$1 dir core config
	[ -f "${WPMCP_OXYGEN_SRC:-}/plugin.php" ] || die "the live Oxygen group needs WPMCP_OXYGEN_SRC, a directory holding the Oxygen 6 plugin (plugin.php)"
	export WPMCP_LIVE_OXYGEN=1
	dir=$(install_wp "$version")
	core=$(checkout_core "$dir")
	RUN_CORES+=("$core")
	config=$(db_config "$dir" "$version-oxygen-live" "$core")
	RUN_CONFIGS+=("$config")
	say "PHPUnit live Oxygen group on WordPress $version (real Oxygen from $WPMCP_OXYGEN_SRC)"
	WP_TESTS_DIR="$dir/wordpress-tests-lib" WP_TESTS_CONFIG_FILE_PATH="$config" WP_CORE_DIR="$core/" \
		"$PHP" vendor/bin/phpunit --group oxygen-live --fail-on-skipped
	unset WPMCP_LIVE_OXYGEN
}

# The live Divi group (issue #636), the same way, with the Divi theme copy
# from WPMCP_DIVI_SRC: its own install, its own database and
# --fail-on-skipped. tests/bootstrap.php switches to the theme.
run_live_divi() {
	local version=$1 dir core config
	[ -f "${WPMCP_DIVI_SRC:-}/style.css" ] || die "the live Divi group needs WPMCP_DIVI_SRC, a directory holding the Divi 5 theme"
	export WPMCP_LIVE_DIVI=1
	dir=$(install_wp "$version")
	core=$(checkout_core "$dir")
	RUN_CORES+=("$core")
	config=$(db_config "$dir" "$version-divi-live" "$core")
	RUN_CONFIGS+=("$config")
	say "PHPUnit live Divi group on WordPress $version (real Divi from $WPMCP_DIVI_SRC)"
	WP_TESTS_DIR="$dir/wordpress-tests-lib" WP_TESTS_CONFIG_FILE_PATH="$config" WP_CORE_DIR="$core/" \
		"$PHP" vendor/bin/phpunit --group divi-live --fail-on-skipped
	unset WPMCP_LIVE_DIVI
}

# The live MemberPress group (issue #668), the same way, with the MemberPress
# copy from WPMCP_MEMBERPRESS_SRC: its own install, its own database and
# --fail-on-skipped.
run_live_memberpress() {
	local version=$1 dir core config
	[ -f "${WPMCP_MEMBERPRESS_SRC:-}/memberpress.php" ] || die "the live MemberPress group needs WPMCP_MEMBERPRESS_SRC, a directory holding the MemberPress plugin"
	export WPMCP_LIVE_MEMBERPRESS=1
	dir=$(install_wp "$version")
	core=$(checkout_core "$dir")
	RUN_CORES+=("$core")
	config=$(db_config "$dir" "$version-memberpress-live" "$core")
	RUN_CONFIGS+=("$config")
	say "PHPUnit live MemberPress group on WordPress $version (real MemberPress from $WPMCP_MEMBERPRESS_SRC)"
	WP_TESTS_DIR="$dir/wordpress-tests-lib" WP_TESTS_CONFIG_FILE_PATH="$config" WP_CORE_DIR="$core/" \
		"$PHP" vendor/bin/phpunit --group memberpress-live --fail-on-skipped
	unset WPMCP_LIVE_MEMBERPRESS
}

# The live WooCommerce Subscriptions group (issue #658), the same way, with
# the plugin copied from WPMCP_WC_SUBSCRIPTIONS_SRC beside WooCommerce: its
# own install, its own database and --fail-on-skipped.
run_live_wc_subscriptions() {
	local version=$1 dir core config
	[ -f "${WPMCP_WC_SUBSCRIPTIONS_SRC:-}/woocommerce-subscriptions.php" ] || die "the live WooCommerce Subscriptions group needs WPMCP_WC_SUBSCRIPTIONS_SRC, a directory holding the WooCommerce Subscriptions plugin"
	export WPMCP_LIVE_WC_SUBSCRIPTIONS=1
	dir=$(install_wp "$version")
	core=$(checkout_core "$dir")
	RUN_CORES+=("$core")
	config=$(db_config "$dir" "$version-wc-subscriptions-live" "$core")
	RUN_CONFIGS+=("$config")
	say "PHPUnit live WooCommerce Subscriptions group on WordPress $version (real Subscriptions from $WPMCP_WC_SUBSCRIPTIONS_SRC)"
	WP_TESTS_DIR="$dir/wordpress-tests-lib" WP_TESTS_CONFIG_FILE_PATH="$config" WP_CORE_DIR="$core/" \
		"$PHP" vendor/bin/phpunit --group wc-subscriptions-live --fail-on-skipped
	unset WPMCP_LIVE_WC_SUBSCRIPTIONS
}

# The live WooCommerce Bookings group (issue #659), the same way, with the
# plugin copied from WPMCP_WC_BOOKINGS_SRC beside WooCommerce: its own
# install, its own database and --fail-on-skipped.
run_live_wc_bookings() {
	local version=$1 dir core config
	[ -f "${WPMCP_WC_BOOKINGS_SRC:-}/woocommerce-bookings.php" ] || die "the live WooCommerce Bookings group needs WPMCP_WC_BOOKINGS_SRC, a directory holding the WooCommerce Bookings plugin"
	export WPMCP_LIVE_WC_BOOKINGS=1
	dir=$(install_wp "$version")
	core=$(checkout_core "$dir")
	RUN_CORES+=("$core")
	config=$(db_config "$dir" "$version-wc-bookings-live" "$core")
	RUN_CONFIGS+=("$config")
	say "PHPUnit live WooCommerce Bookings group on WordPress $version (real Bookings from $WPMCP_WC_BOOKINGS_SRC)"
	WP_TESTS_DIR="$dir/wordpress-tests-lib" WP_TESTS_CONFIG_FILE_PATH="$config" WP_CORE_DIR="$core/" \
		"$PHP" vendor/bin/phpunit --group wc-bookings-live --fail-on-skipped
	unset WPMCP_LIVE_WC_BOOKINGS
}

# ---------------------------------------------------------------- MCP Adapter

# Prints the MCP Adapter release the adapter leg tests: WPMCP_ADAPTER_VERSION,
# else the latest on WordPress.org. Offline, it falls back to the newest
# release already in the cache and says so.
adapter_release() {
	local version="${WPMCP_ADAPTER_VERSION:-}"
	if [ -z "$version" ]; then
		version=$(latest_release mcp-adapter)
		if [ -z "$version" ]; then
			version=$(find "$CACHE/mcp-adapter" -mindepth 2 -maxdepth 2 -name .ready 2>/dev/null | sed 's#/\.ready$##; s#.*/##' | sort -V | tail -1 || true)
			[ -n "$version" ] || die "could not ask WordPress.org for the latest MCP Adapter release, and none is cached"
			say "WordPress.org did not answer; testing the newest cached MCP Adapter, $version." >&2
		fi
	fi
	[[ "$version" =~ ^[0-9]+(\.[0-9]+)*(-[0-9A-Za-z.]+)?$ ]] || die "not an MCP Adapter version: $version"
	echo "$version"
}

# Downloads MCP Adapter <version> from WordPress.org into the cache once and
# prints the plugin directory. Cached by version, so a new release is fetched
# and an old one is never overwritten.
fetch_adapter() {
	local version=$1 dir="$CACHE/mcp-adapter/$1"
	if [ ! -f "$dir/.ready" ]; then
		with_lock "mcp-adapter-$version" fetch_adapter_locked "$version" "$dir"
	fi
	echo "$dir/mcp-adapter"
}

fetch_adapter_locked() {
	local version=$1 dir=$2 header
	[ -f "$dir/.ready" ] && return 0
	say "Downloading MCP Adapter $version from WordPress.org into $dir" >&2
	rm -rf "$dir"
	mkdir -p "$dir"
	curl -fsSL --max-time 120 -o "$dir/mcp-adapter.zip" "https://downloads.wordpress.org/plugin/mcp-adapter.$version.zip" \
		|| die "could not download MCP Adapter $version from WordPress.org"
	unzip -q "$dir/mcp-adapter.zip" -d "$dir" || die "could not unpack MCP Adapter $version"
	header=$(sed -n 's/^[[:space:]*]*Version:[[:space:]]*//p' "$dir/mcp-adapter/mcp-adapter.php" | head -1 | tr -d '[:space:]')
	[ "$header" = "$version" ] || die "the MCP Adapter $version download says it is version '$header'"
	touch "$dir/.ready"
}

# The adapter leg (issue #501): the default install with the released MCP
# Adapter plugin added to this run's own core copy, where a site keeps it,
# and its own database. tests/bootstrap.php loads the plugin ahead of WP MCP,
# as WordPress orders 'mcp-adapter/' before 'wpmcp/'. First the coexistence
# group with --fail-on-skipped, then the free and pro suites in full.
run_live_adapter() {
	local version=$1 adapter_version src dir core config plugin
	adapter_version=$(adapter_release)
	src=$(fetch_adapter "$adapter_version")
	dir=$(install_wp "$version")
	core=$(checkout_core "$dir")
	RUN_CORES+=("$core")
	config=$(db_config "$dir" "$version-adapter-live" "$core")
	RUN_CONFIGS+=("$config")
	rm -rf "$core/wp-content/plugins/mcp-adapter"
	cp -R "$src" "$core/wp-content/plugins/mcp-adapter"
	plugin="$core/wp-content/plugins/mcp-adapter/mcp-adapter.php"
	[ -z "${LEG_NOTE:-}" ] || echo "mcp-adapter $adapter_version" >>"$LEG_NOTE"
	say "PHPUnit adapter group on WordPress $version (released MCP Adapter $adapter_version from WordPress.org)"
	WPMCP_TEST_ADAPTER_PLUGIN="$plugin" WPMCP_TEST_ADAPTER_VERSION="$adapter_version" \
		WP_TESTS_DIR="$dir/wordpress-tests-lib" WP_TESTS_CONFIG_FILE_PATH="$config" WP_CORE_DIR="$core/" \
		"$PHP" vendor/bin/phpunit --group adapter-live --fail-on-skipped
	say "PHPUnit free and pro suites on WordPress $version (released MCP Adapter $adapter_version)"
	WPMCP_TEST_ADAPTER_PLUGIN="$plugin" WPMCP_TEST_ADAPTER_VERSION="$adapter_version" \
		WP_TESTS_DIR="$dir/wordpress-tests-lib" WP_TESTS_CONFIG_FILE_PATH="$config" WP_CORE_DIR="$core/" \
		"$PHP" vendor/bin/phpunit
}

cd "$ROOT"

# Each test plugin's latest release, asked of WordPress.org once per run.
mkdir -p "$CACHE/notes"
RESOLVED=$(mktemp "$CACHE/notes/resolved.XXXXXX")

if [ "$resolve_only" = true ]; then
	trap 'rm -f "$RESOLVED"' EXIT
	dir=$(install_target "${only_wp:-$default_wp}") || exit 1
	echo "install: $(basename "$dir")"
	plugin_versions || true
	exit 0
fi

if [ "$prune_only" = true ]; then
	trap 'rm -f "$RESOLVED"' EXIT
	if [ "$run_all" = true ]; then
		with_lock prune prune_all_installs
	else
		prune_installs
	fi
	prune_cores
	prune_downloads
	cache_report
	exit 0
fi

RUN_CORES=()
RUN_CONFIGS=()
cleanup_run() {
	local c db
	for c in "${RUN_CORES[@]+"${RUN_CORES[@]}"}"; do rm -rf "$c"; done
	for c in "${RUN_CONFIGS[@]+"${RUN_CONFIGS[@]}"}"; do
		db=$(basename "$c" .php)
		"$BIN/mariadb" --no-defaults --socket="$SOCK" -uroot -e "DROP DATABASE IF EXISTS \`$db\`" 2>/dev/null || true
		rm -f "$c"
	done
}
# When the run exits, however it exits: its databases, configs and core
# copies go, its claims on installs and its locks (the run slot among them)
# are released, and the cache is pruned to its budget.
finish_run() {
	local lock
	cleanup_run || true
	rm -f "$RESOLVED"
	[ -z "${LEG_NOTE:-}" ] || rm -f "$LEG_NOTE"
	rm -rf "$CACHE/cores/"*-$$ 2>/dev/null || true
	rm -f "$CACHE"/wp-*/.users/$$ 2>/dev/null || true
	for lock in "$CACHE/locks/"*/; do
		[ "$(cat "${lock}pid" 2>/dev/null || true)" != $$ ] || rm -rf "$lock"
	done
	prune_installs || true
	prune_downloads || true
}
trap finish_run EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

acquire_slot
space_guard

# Point git at the versioned hooks, so the pre-push gate is on in every
# worktree of this clone once anyone has run the suite.
if [ -d .githooks ] && git rev-parse --git-dir >/dev/null 2>&1 \
	&& [ "$(git config --get core.hooksPath || true)" != ".githooks" ]; then
	git config core.hooksPath .githooks
	say "Enabled the pre-push test gate (git config core.hooksPath .githooks)."
fi

composer_install() { [ -x vendor/bin/phpunit ] || composer install --no-interaction; }
[ -x vendor/bin/phpunit ] || with_lock "composer-$(printf '%s' "$ROOT" | shasum | cut -c1-12)" composer_install

start_db

want_coverage=false
if [ "$coverage" = true ] && [ "$targeted" = false ]; then
	# Capture first: grep -q exits on the first match and, under pipefail, the
	# SIGPIPE it leaves php with would read as "not loaded".
	php_modules=$("$PHP" -m)
	if grep -qi '^pcov$' <<<"$php_modules"; then
		want_coverage=true
	else
		say "pcov is not loaded; running without the coverage floor."
	fi
fi

run_leg_body() {
	local kind=$1 wp=${2:-} cov=${3:-}
	case "$kind" in
		lint) say "composer lint"; composer lint ;;
		drift) say "composer drift:check"; composer drift:check ;;
		phpunit) if [ "$cov" = floor ]; then run_suite "$wp" "$want_coverage"; else run_suite "$wp" false; fi ;;
		multisite) run_multisite "$wp" ;;
		live-forms) run_live_forms "$wp" ;;
		live-blocks) run_live_blocks "$wp" ;;
		live-buddypress) run_live_buddypress "$wp" ;;
		live-lms) run_live_lms "$wp" ;;
		live-tablepress) run_live_tablepress "$wp" ;;
		live-crm) run_live_crm "$wp" ;;
		live-funnelkit) run_live_funnelkit "$wp" ;;
		live-polylang) run_live_polylang "$wp" ;;
		live-breakdance) run_live_breakdance "$wp" ;;
		live-avada) run_live_avada "$wp" ;;
		live-wpbakery) run_live_wpbakery "$wp" ;;
		live-oxygen) run_live_oxygen "$wp" ;;
		live-divi) run_live_divi "$wp" ;;
		live-memberpress) run_live_memberpress "$wp" ;;
		live-amelia) run_live_amelia "$wp" ;;
		live-wc-subscriptions) run_live_wc_subscriptions "$wp" ;;
		live-theme-pro) run_live_theme_pro "$wp" ;;
		live-wc-bookings) run_live_wc_bookings "$wp" ;;
		live-wp-mail-smtp) run_live_wp_mail_smtp "$wp" ;;
		live-adapter) run_live_adapter "$wp" ;;
		*) die "unknown leg: $kind" ;;
	esac
}

# Each leg runs in a subshell with errexit on, so its first failing command
# ends that leg only. The next leg still runs (issue #488: a red floor leg
# used to stop the live groups without a word), and the summary names it.
SUMMARY=()
failed=0
# A leg may leave a note for its summary line here (the adapter leg names the
# MCP Adapter release it tested).
mkdir -p "$CACHE/notes"
LEG_NOTE=$(mktemp "$CACHE/notes/leg.XXXXXX")
for leg in "${LEGS[@]}"; do
	: >"$LEG_NOTE"
	set +e
	(
		set -e
		RUN_CORES=()
		RUN_CONFIGS=()
		trap cleanup_run EXIT
		# shellcheck disable=SC2086 # the leg is "<kind> [WordPress] [coverage]".
		run_leg_body $leg
	)
	status=$?
	set -e
	note=$(paste -sd ';' "$LEG_NOTE" | sed 's/;/; /g')
	if [ "$status" -eq 0 ]; then
		SUMMARY+=("pass  $(leg_label "$leg")${note:+ ($note)}")
	else
		SUMMARY+=("FAIL  $(leg_label "$leg")${note:+ ($note)} (exit $status)")
		failed=$((failed + 1))
		printf '\033[31m[test-local]\033[0m leg failed: %s (exit %s); running the remaining legs.\n' "$(leg_label "$leg")" "$status" >&2
	fi
done

rm -f "$LEG_NOTE"
say "Summary:"
for line in "${SUMMARY[@]}"; do
	printf '  %s\n' "$line"
done
if [ "$failed" -gt 0 ]; then
	die "$failed of ${#LEGS[@]} legs failed."
fi
say "All green."
