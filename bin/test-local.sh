#!/usr/bin/env bash
#
# The test gate. PHPUnit does not run on GitHub Actions; it runs here, from the
# pre-push hook (.githooks/pre-push) and by hand. GitHub keeps only the static
# gates (lint, drift, compliance, Plugin Check).
#
# What it does, in order:
#   1. Starts a private MariaDB server under the cache directory, if one is not
#      already running. It never touches a system MySQL or MariaDB.
#   2. Installs WordPress, its test library and the pinned test plugins once per
#      WordPress version into the cache, and reinstalls them only when
#      bin/install-wp-tests.sh or bin/install-test-plugins.sh changes.
#   3. Gives this checkout its own database, so worktrees can run side by side.
#   4. Runs composer lint, composer drift:check, then the suite. The default
#      leg also enforces the coverage floor when pcov is loaded.
#
# MariaDB, not MySQL: the WordPress harness turns every CREATE TABLE into a
# TEMPORARY table, and WooCommerce's order-sync query reads one of them twice
# on admin_init. MySQL refuses that ("Can't reopen table: 'orders'"), which
# breaks RestoreControllerAjaxTest. MariaDB lifted the limitation.
#
# Usage:
#   bin/test-local.sh                 lint, drift, suite on the default WordPress, coverage floor
#   bin/test-local.sh --all           the same, then the suite on every other WordPress below
#   bin/test-local.sh --wp 6.9        the suite on one WordPress version
#   bin/test-local.sh --no-coverage   skip the coverage run and floor
#   bin/test-local.sh -- --filter Foo PHPUnit arguments; skips lint, drift and coverage
#   bin/test-local.sh --stop-db       stop the private MariaDB server and exit
#
# Environment:
#   WPMCP_TEST_CACHE     cache root (default ~/.cache/wpmcp-tests)
#   WPMCP_TEST_DB_PORT   MariaDB port (default 3399)
#   WPMCP_TEST_PHP       PHP binary (default php)
#   WPMCP_MARIADB_BIN    directory holding mariadbd, if not found automatically
#   ELEMENTOR_VERSION    passed to bin/install-test-plugins.sh ('latest' tests
#                        against the newest Elementor in a separate install)

set -euo pipefail

# The WordPress releases the suite runs on. The first is the default leg and
# must be the release the shipped headers declare (TESTED_UP_TO_FLOOR); the
# `Requires at least` floor must be in the list too. ReleaseHeadersTest parses
# this exact line, so keep it one quoted, space-separated list.
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

say() { printf '\033[1m[test-local]\033[0m %s\n' "$*"; }
die() { printf '\033[31m[test-local]\033[0m %s\n' "$*" >&2; exit 1; }

# ---------------------------------------------------------------- arguments

run_all=false
only_wp=""
coverage=true
phpunit_args=()
while [ $# -gt 0 ]; do
	case "$1" in
		--all) run_all=true ;;
		--wp) shift; only_wp=${1:-}; [ -n "$only_wp" ] || die "--wp needs a version" ;;
		--no-coverage) coverage=false ;;
		--stop-db) stop_db=true ;;
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

BIN=$(mariadb_dir)

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

# Installs WordPress <version> once and prints the install directory.
install_wp() {
	local version=$1 flavor="" dir hash
	[ "${ELEMENTOR_VERSION:-}" = latest ] && flavor="-elementor-latest"
	# The installers' hash is part of the directory, not a stamp inside it:
	# branches carrying different installers (a moved plugin pin, say) get
	# separate installs, so one run never deletes an install another run is
	# testing against.
	hash=$(cat "$ROOT/bin/install-wp-tests.sh" "$ROOT/bin/install-test-plugins.sh" | shasum | cut -c1-12)
	dir="$CACHE/wp-$version$flavor-$hash"

	if [ ! -f "$dir/.ready" ]; then
		with_lock "install-$version$flavor-$hash" install_wp_locked "$version" "$flavor" "$dir"
	fi
	echo "$dir"
}

install_wp_locked() {
	local version=$1 flavor=$2 dir=$3
	[ -f "$dir/.ready" ] && return 0
	command -v svn >/dev/null 2>&1 || die "svn is required by bin/install-wp-tests.sh: brew install subversion"
	say "Installing WordPress $version$flavor into $dir" >&2
	rm -rf "$dir"
	mkdir -p "$dir/tmp"
	(
		export TMPDIR="$dir/tmp" WP_CORE_DIR="$dir/wordpress/" WP_TESTS_DIR="$dir/wordpress-tests-lib"
		bash "$ROOT/bin/install-wp-tests.sh" wpmcp_unused "$DB_USER" "$DB_PASS" "127.0.0.1:$PORT" "$version" true
		bash "$ROOT/bin/install-test-plugins.sh"
	) >"$dir/install.log" 2>&1 || die "WordPress $version install failed, see $dir/install.log"
	touch "$dir/.ready"
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
	core="$CACHE/cores/$(printf '%s|%s' "$ROOT" "$dir" | shasum | cut -c1-12)"
	mkdir -p "$CACHE/cores"
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
	name="wpmcp_test_$(printf '%s|%s' "$ROOT" "$version" | shasum | cut -c1-12)"
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
	config=$(db_config "$dir" "$version" "$core")
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

cd "$ROOT"

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

read -r -a versions <<<"$WP_VERSIONS"
default_wp=${versions[0]}

targeted=false
[ ${#phpunit_args[@]} -gt 0 ] && targeted=true

if [ "$targeted" = false ]; then
	say "composer lint"
	composer lint
	say "composer drift:check"
	composer drift:check
fi

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

if [ -n "$only_wp" ]; then
	run_suite "$only_wp" false
else
	run_suite "$default_wp" "$want_coverage"
	if [ "$run_all" = true ]; then
		for version in "${versions[@]:1}"; do
			run_suite "$version" false
		done
	fi
fi

say "All green."
