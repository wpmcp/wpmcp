# Release checklist

Run through this list for every release, including readme-only releases
(those still get a patch version bump, see WPORG-SUBMISSION.md section 7,
which defers to this file for the sequence).

Two of these items are enforced by machine, so the boxes below document a
gate rather than substitute for one:

- `tests/free/Release/ReleaseHeadersTest.php` fails the suite when the
  compatibility headers disagree across shipped files, when `Tested up to`
  trails the pinned WordPress release, when the root `Stable tag` and
  `WPMCP_VERSION` diverge, or when a restricted tag reappears.
- `scripts/build-wporg-release.sh` gate 4b re-derives the same headers from
  the staged zip, so a build cannot ship a value the repository does not
  declare.

## Versions and headers

- [ ] Bump `Version` in `wpmcp.php` and `WPMCP_VERSION` to match.
- [ ] Bump `Stable tag` in `readme.txt` to match (the flavor readmes take
      `{{VERSION}}` from the build and cannot drift).
- [ ] Add the `== Changelog ==` and `== Upgrade Notice ==` entries.
- [ ] Check `Tested up to` against the current WordPress release, reading it
      from `https://api.wordpress.org/core/stable-check/1.0/` rather than from
      memory (issue #172, finding B-23). Plugin Check errors when it trails
      the current release and the plugin stops appearing in directory search.
      Every file that carries the header:
      - `readme.txt`
      - `scripts/flavors/wporg/readme.txt`
      - `scripts/flavors/woocommerce/readme.txt`
      - `scripts/flavors/wporg/wpmcp.php` (the only loader with the header;
        `wpmcp.php` and `scripts/flavors/woocommerce/wpmcp-for-woocommerce.php`
        carry `Requires at least` and `Requires PHP` only)

      Bump only after an actual smoke pass against that WordPress version, and
      record the pass below. Then raise `TESTED_UP_TO_FLOOR` in
      `tests/free/Release/ReleaseHeadersTest.php` to the same value.
- [ ] Update the prose that hardcodes a version: the "Version headers"
      paragraph in `WPORG-SUBMISSION.md` and the B-23 row in `COMPLIANCE.md`.
- [ ] Confirm `Requires at least` and `Requires PHP` still reflect reality in
      all three readmes and all three loaders.

## Verification

- [ ] Full test suite green (`composer test`).
- [ ] All three artifacts built, each from the readme it ships:
      - `scripts/build-wporg-release.sh` (wp.org free, `scripts/flavors/wporg/readme.txt`)
      - `scripts/build-woo-release.sh` (WooCommerce, `scripts/flavors/woocommerce/readme.txt`)
      - `scripts/build-release.sh` (general zip, root `readme.txt`)
- [ ] Plugin Check clean on each built zip.
- [ ] Smoke pass on the target WordPress version for each artifact: activate,
      run a representative MCP session, snapshot and rollback, deactivate.

## Publish

- [ ] Tag the release on GitHub.
- [ ] Push the matching SVN tag for the free plugin (a GitHub release
      without the SVN tag is the known failure mode).

## Smoke pass log

One row per artifact per WordPress version. A `Tested up to` value with no
row here is a claim nothing stands behind.

| WordPress | Plugin version | Artifact | Date | Result |
| --- | --- | --- | --- | --- |
| 7.1 | 0.8.1 | source tree, free suite | 2026-08-30 | Pass. 2784 tests against a clean WordPress 7.1 install. The four `RestoreControllerAjaxTest` failures in that run are WooCommerce's first-install path in a brand new database (`WC_Install::newly_installed` enabling HPOS mid-request); the same class passes 6/6 on the same 7.1 install without it, and on 6.9. |
| 7.1 | 0.8.1 | wp.org zip | pending | Manual pass: activate, MCP session, snapshot and rollback, deactivate. |
| 7.1 | 0.8.1 | WooCommerce zip | pending | As above. |
| 7.1 | 0.8.1 | general zip | pending | As above. |
