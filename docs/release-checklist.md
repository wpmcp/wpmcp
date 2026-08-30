# Release checklist

Run through this list for every release, including readme-only releases
(those still get a patch version bump, see WPORG-SUBMISSION.md).

## Versions and headers

- [ ] Bump `Version` in `wpmcp.php` and `WPMCP_VERSION` to match.
- [ ] Bump `Stable tag` in `readme.txt` to match.
- [ ] Check `Tested up to` in every readme against the current WordPress
      release (issue #172, finding B-23):
      - `readme.txt`
      - `scripts/flavors/wporg/readme.txt` (also `scripts/flavors/wporg/wpmcp.php`)
      - `scripts/flavors/woocommerce/readme.txt`
      Plugin Check errors when it trails the current release and the plugin
      stops appearing in directory search. Bump only after an actual smoke
      pass against that WordPress version, and record the pass below.
- [ ] Confirm `Requires at least` and `Requires PHP` still reflect reality.

## Verification

- [ ] Full test suite green.
- [ ] Plugin Check clean on the built zip (`scripts/build-wporg-release.sh`).
- [ ] Smoke pass on the target WordPress version: activate, run a
      representative MCP session, snapshot and rollback, deactivate.

## Publish

- [ ] Tag the release on GitHub.
- [ ] Push the matching SVN tag for the free plugin (a GitHub release
      without the SVN tag is the known failure mode).

## Smoke pass log

| WordPress | Plugin version | Date | Result |
| --- | --- | --- | --- |
| 7.0 | 0.8.0 | pending | pending, tracked in issue #172 |
