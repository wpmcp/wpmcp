# shellcheck shell=bash
#
# The shared download cache, sourced by bin/install-wp-tests.sh and
# bin/install-test-plugins.sh.
#
# With WPMCP_DOWNLOAD_CACHE set (bin/test-local.sh sets it to
# $WPMCP_TEST_CACHE/downloads), every download is kept there under a name
# that pins its content: core/wordpress-<version>.tar.gz,
# tests-lib/<version>-includes and -data (the svn exports of the WordPress
# test library), tests-lib/wp-tests-config-sample-<version>.php,
# plugins/<slug>-<version>.zip, and for the two files that have no version,
# themes/<theme>.zip and misc/wp-mysqli-db.php, the URL, fetched again once
# they are older than WPMCP_TEST_LATEST_TTL_HOURS (default 24).
#
# A file is used straight from the cache and fetched only on a miss. Fetching
# and reading both happen under a per-file lock (.locks/<name>), so parallel
# runs never fetch the same file twice, never read a half-written one, and
# bin/test-local.sh never prunes one being read. A fetch goes to <name>.tmp.<pid>,
# is checked (not empty, and unzip or tar can list it), then renamed into
# place; a cached file that fails the check is fetched again, once. A file's
# mtime is its last use, which bin/test-local.sh prunes by
# (WPMCP_TEST_DOWNLOAD_TTL_DAYS, WPMCP_TEST_DOWNLOAD_MAX_GB).
#
# Without WPMCP_DOWNLOAD_CACHE, every file is downloaded as before.

# Downloads <url> to <file>.
dc_download() {
	if command -v curl >/dev/null 2>&1; then
		curl -fsSL --retry 5 --retry-all-errors "$1" -o "$2"
	elif command -v wget >/dev/null 2>&1; then
		wget -nv -O "$2" "$1"
	else
		echo "Neither curl nor wget is available." >&2
		return 1
	fi
}

# svn exports <url> to <dir>.
dc_svn_export() { svn export --quiet "$1" "$2"; }

# True when <path> is a usable <kind>: zip, tgz, file or dir.
dc_valid() {
	case "$2" in
		zip) [ -s "$1" ] && unzip -l "$1" >/dev/null 2>&1 ;;
		tgz) [ -s "$1" ] && tar -tzf "$1" >/dev/null 2>&1 ;;
		file) [ -f "$1" ] && [ -s "$1" ] ;;
		dir) [ -d "$1" ] && [ -n "$(ls -A "$1" 2>/dev/null)" ] ;;
		*) return 1 ;;
	esac
}

dc_lock_path() { echo "$WPMCP_DOWNLOAD_CACHE/.locks/${1//\//__}"; }

# Takes the lock on cache entry <name>; a lock whose holder died is taken over.
dc_lock() {
	local lock holder _
	lock=$(dc_lock_path "$1")
	mkdir -p "$WPMCP_DOWNLOAD_CACHE/.locks"
	for _ in $(seq 1 1800); do
		if mkdir "$lock" 2>/dev/null; then
			echo $$ >"$lock/pid"
			return 0
		fi
		holder=$(cat "$lock/pid" 2>/dev/null || true)
		if [ -n "$holder" ] && ! kill -0 "$holder" 2>/dev/null; then
			rm -rf "$lock"
			continue
		fi
		sleep 1
	done
	echo "timed out waiting for the download lock $lock" >&2
	return 1
}

dc_unlock() { rm -rf "$(dc_lock_path "$1")"; }

# Makes sure cache entry <name> of <kind> is present and valid, fetching it on
# a miss with "<fetcher...> <tmp path>", and leaves its lock held: the caller
# reads DC_PATH, then calls dc_unlock <name>. With <refresh> (hours, for the
# unversioned entries) an entry fetched longer ago than that is fetched again.
dc_get() {
	local name=$1 kind=$2 refresh=$3 path tmp attempt stamp
	shift 3
	path="$WPMCP_DOWNLOAD_CACHE/$name"
	stamp="$path.fetched"
	mkdir -p "$(dirname "$path")"
	dc_lock "$name" || return 1
	if [ -n "$refresh" ] && [ -e "$path" ]; then
		if [ ! -e "$stamp" ] || [ -n "$(find "$stamp" -mmin +"$((refresh * 60))" 2>/dev/null)" ]; then
			rm -rf "$path"
		fi
	fi
	for attempt in 1 2; do
		dc_valid "$path" "$kind" && break
		[ ! -e "$path" ] || echo "The cached $name is corrupt; fetching it again." >&2
		rm -rf "$path"
		tmp="$path.tmp.$$"
		rm -rf "$tmp"
		if "$@" "$tmp" && dc_valid "$tmp" "$kind"; then
			mv "$tmp" "$path"
			touch "$stamp"
			break
		fi
		rm -rf "$tmp"
		[ "$attempt" = 2 ] || echo "Fetching $name failed or gave a corrupt file; trying once more." >&2
	done
	if ! dc_valid "$path" "$kind"; then
		dc_unlock "$name"
		echo "Could not fetch a valid $name." >&2
		return 1
	fi
	touch "$path"
	DC_PATH=$path
}

# The installers' entry points. Each takes the cache entry name (empty: do not
# cache, for content no version pins), the URL, and where it goes.

# Unpacks zip <url> into <dir>.
dc_unzip() {
	local name=$1 url=$2 dir=$3 refresh=${4:-}
	if [ -z "${WPMCP_DOWNLOAD_CACHE:-}" ] || [ -z "$name" ]; then
		local zip="${TMPDIR:-/tmp}/dc.$$.zip"
		dc_download "$url" "$zip" && unzip -q -o "$zip" -d "$dir"
		local status=$?
		rm -f "$zip"
		return $status
	fi
	dc_get "$name" zip "$refresh" dc_download "$url" || return 1
	unzip -q -o "$DC_PATH" -d "$dir" || { dc_unlock "$name"; return 1; }
	dc_unlock "$name"
}

# Unpacks tar.gz <url> into <dir>, dropping its top directory.
dc_untar() {
	local name=$1 url=$2 dir=$3
	if [ -z "${WPMCP_DOWNLOAD_CACHE:-}" ] || [ -z "$name" ]; then
		local tgz="${TMPDIR:-/tmp}/dc.$$.tar.gz"
		dc_download "$url" "$tgz" && tar --strip-components=1 -zxmf "$tgz" -C "$dir"
		local status=$?
		rm -f "$tgz"
		return $status
	fi
	dc_get "$name" tgz "" dc_download "$url" || return 1
	tar --strip-components=1 -zxmf "$DC_PATH" -C "$dir" || { dc_unlock "$name"; return 1; }
	dc_unlock "$name"
}

# Copies file <url> to <file>.
dc_copy() {
	local name=$1 url=$2 file=$3 refresh=${4:-}
	if [ -z "${WPMCP_DOWNLOAD_CACHE:-}" ] || [ -z "$name" ]; then
		dc_download "$url" "$file"
		return
	fi
	dc_get "$name" file "$refresh" dc_download "$url" || return 1
	cp "$DC_PATH" "$file" || { dc_unlock "$name"; return 1; }
	dc_unlock "$name"
}

# svn exports <url> to <dir>, from a cached export: an APFS clone where it can.
dc_export() {
	local name=$1 url=$2 dir=$3
	if [ -z "${WPMCP_DOWNLOAD_CACHE:-}" ] || [ -z "$name" ]; then
		dc_svn_export "$url" "$dir"
		return
	fi
	dc_get "$name" dir "" dc_svn_export "$url" || return 1
	rm -rf "$dir"
	{ cp -cR "$DC_PATH" "$dir" 2>/dev/null || { rm -rf "$dir"; cp -R "$DC_PATH" "$dir"; }; } || { dc_unlock "$name"; return 1; }
	dc_unlock "$name"
}
