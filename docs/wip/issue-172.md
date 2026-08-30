# Issue #172: wp.org compliance, bump Tested up to

Finding B-23. Plugin Check errors when `Tested up to` trails the current
WordPress release, and a plugin that trails stops appearing in directory
search. The check is live-version by nature, so the offline compliance engine
cannot see it.

## State

| Definition of done | State |
| --- | --- |
| `Tested up to` matches the current WordPress release | Done. All four shipped headers declare 7.1, which api.wordpress.org stable-check reports as `latest` (7.0.4 is `outdated`). |
| Smoke pass recorded against that version | Done for the automated half, partial for the manual half. See below. |
| Release checklist includes the bump | Done, `docs/release-checklist.md`, plus two machine gates so the checkbox documents a gate instead of substituting for one. |

## What changed

- `Tested up to: 7.1` in `readme.txt`, `scripts/flavors/wporg/readme.txt`,
  `scripts/flavors/woocommerce/readme.txt` and the `scripts/flavors/wporg/wpmcp.php`
  loader header. The first version of this branch bumped to 7.0 and left the
  two wporg-flavor files alone, which are the only two files that reach the
  directory zip, so the artifact Plugin Check runs against was unchanged.
- Version bumped 0.8.0 to 0.8.1 (`wpmcp.php` header, `WPMCP_VERSION`,
  `readme.txt` stable tag, changelog and upgrade notice). WPORG-SUBMISSION.md
  section 7 requires a patch bump for a readme-only release, so the SVN trunk
  change has a tag to pair with.
- `claude` removed from the root and WooCommerce tag lists. It is a
  third-party trademark; issue #169 had already removed it from the wporg
  flavor. Finding B-20 in COMPLIANCE.md.
- `tests/free/Release/ReleaseHeadersTest.php`: the recurrence gate. Fails when
  the four headers disagree, when any of them trails the pinned
  `TESTED_UP_TO_FLOOR`, when the root `Stable tag` and `WPMCP_VERSION` diverge,
  or when a restricted tag reappears.
- Gate 4b in `scripts/build-wporg-release.sh`, re-deriving the same values from
  the staged zip rather than trusting the checkout, matching the design of the
  other gates in that script.
- COMPLIANCE.md B-23 and B-20 rows updated in the same change that falsifies
  them; WPORG-SUBMISSION.md sections 3, 4 and 7 now point at
  `docs/release-checklist.md` as the single authoritative release sequence.

## Smoke pass, honestly

Run: the full free suite (2784 tests) against a clean WordPress 7.1 install
(core 7.1 from wordpress.org, matching `tests/phpunit` lib from the 7.1 SVN
tag), same plugin code, on 2026-08-30.

Result: green apart from four failures in `RestoreControllerAjaxTest`, all of
which are an artifact of WooCommerce running its first-install path against a
brand new test database (`WC_Install::newly_installed` enabling HPOS inside the
ajax request, which errors on `Can't reopen table: 'orders'`). Re-running the
same class on the same 7.1 install without that first-install path passes 6/6,
and the same four tests pass on 6.9. Nothing in the four traces back to
WordPress 7.1 behaviour.

Not yet done, and the reason the checklist's smoke pass log stays empty: the
manual half of the pass (activate on a real 7.1 site, drive a representative
MCP session through a client, snapshot and roll back from the History screen,
deactivate) on each of the three built artifacts. The automated suite covers
snapshot and rollback at the service level, not the admin flow.
