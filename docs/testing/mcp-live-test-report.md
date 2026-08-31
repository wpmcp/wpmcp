# wpmcp Live MCP Test Report

> RECONSTRUCTED 2026-08-29 (partial): the original was deleted by an rsync --delete that
> followed the Studio plugins symlink. The summary, all nine findings with full detail,
> and the finding #1 resolution below are verbatim from the original (read into session
> context minutes before deletion). The per-domain result tables that followed the
> Legend are LOST beyond the first heading.

**Test site:** Studio local WordPress (`http://localhost:8884`), WP 7.0.1, PHP 8.3, wpmcp v0.7.32
**Method:** Direct calls through WordPress core's Abilities REST transport (`POST /wp-json/wp-abilities/v1/abilities/{name}/run`), authenticated as admin via Application Password - the same call shape an MCP client uses.
**Started:** 2026-07-14

**No live or production site was ever touched.** Everything ran against a disposable local WordPress install created via WordPress Studio CLI at `~/Studio/wpmcp-test`, with a copy of this repo's code synced into its `wp-content/plugins/wpmcp`. All 153 wpmcp abilities across all 34 domains were reached through real HTTP calls to that local site's REST transport, with several supporting plugins (Elementor, ACF, WooCommerce, Polylang, Classic Editor) installed live from wordpress.org via wpmcp's own tools.

**The site was also exposed on a genuine public URL** (`https://wpmcp-test-session.loca.lt`, via `localtunnel`) and a representative pass of reads, a full create-read-update-delete cycle, and `get-connection-info` were re-run through that public URL.

## Summary

- **~145 individual tool-call checks** across every domain, covering all 153 registered abilities directly, including every guarded default-off tool's disabled AND enabled state (`delete-menu`, `delete-media`, `import-content`, `update-option`, `run-event`, DB/FS writes, plugin/theme update/delete, `run-wp-cli`, `run-php-snippet`), plus the public-URL confirmation pass. No known gaps remained.
- **9 real findings**, ranked:
  1. No actual MCP protocol transport existed - root-caused to a missing `wordpress/mcp-adapter` dependency.
  2. **Fixed during that session**: every tool was invisible to REST/MCP discovery (`show_in_rest` never set) - resolved in `src/MCP/Registrar.php`.
  3. Rollback silently fails to restore state on WordPress's SQLite integration (confirmed real-MySQL unaffected). Root cause: `Snapshot::serialize()` gzips to binary; the SQLite integration's value-processing rejects the binary payload where MySQL LONGBLOB accepts it; `Snapshot_Store::save()` never checked the insert return, so `$wpdb->insert_id` 0 was handed back as a real operation id ("before_blob... may be too long or contains invalid data" in last_error; insert_id 1 and empty last_error on real MySQL).
  4. `trigger-backup`'s `type`/`scope` are ignored - `Run_Backup_Job::handle()` (src/Tools/Backup/Run_Backup_Job.php:51) always calls `Export_Content`; every "backup" is a WXR content export mislabeled as the requested type. Trust-relevant for a plugin whose pitch is recoverability.
  5. `edit-comment`/`delete-comment` unusable by ANY role including Administrator: registered with capability `edit_comments` (src/Plugin.php:873, :891) which no default role has; WP core gates via per-comment `edit_comment` or `moderate_comments`. Every call 403s (`rest_ability_cannot_execute`). Fix: register with `moderate_comments` like the working `moderate-comment`.
  6. Every non-success response surfaces as HTTP 500 - raw thrown exceptions become generic `ability_callback_exception` @ 500, and even idiomatic `WP_Error`s (e.g. `wpmcp_analytics_not_connected`) come back 500 because their data never sets `status`. Fix plugin-wide in one place: map known refusal exception types to 400 in the REST layer, or set `['status' => 4xx]` in WP_Error data.
  7. `run-wp-cli` binary auto-detection misses Apple Silicon Homebrew: `Wp_Cli_Guard::default_binary_candidates()` checks only `/usr/local/bin/wp` and `/usr/bin/wp`, not `/opt/homebrew/bin/wp`.
  8. Elementor/ACF tools can edit but not bootstrap: `add-widget` requires an existing `parent_id` and no tool creates a section/column/container, so an agent cannot build a new Elementor page from scratch (verified all edit tools work once a parent is seeded). ACF `update-fields` writes via postmeta fallback but `get-fields` reads empty without a registered field group - looks like silent data loss to a caller.
  9. Zero test coverage at the transport boundary - all ~1580 passing tests asserted in-process registry state; how #1/#2 shipped unnoticed across 46 releases.
- Everything else tested worked, including the hardest safety-critical logic: SSRF protection, flag-injection refusal in guarded WP-CLI, protected-table/protected-path enforcement even with write filters enabled, the two-gate (enable + environment) design for RCE-adjacent tools, and governance narrowing/widening reversibility.

## Finding #1 resolution: what MCP-Adapter actually is, and the fix

Confirmed via Packagist + GitHub: **`wordpress/mcp-adapter`** (github.com/WordPress/mcp-adapter, part of the official "AI Building Blocks for WordPress" initiative, 211k Packagist downloads, 1421 stars) bridges the Abilities API to actual MCP JSON-RPC servers (HTTP + STDIO).

- `wp_register_ability()` alone is not enough. Either (a) `'meta' => ['mcp' => ['public' => true]]` per ability, reachable via the adapter's default server's `discover-abilities`/`execute-ability` meta-tools at `/wp-json/mcp/mcp-adapter-default-server`, or (b) register a **custom MCP server** (server id `wpmcp-server`) that lists wpmcp's abilities as direct, individually-named MCP tools - the shape wpmcp's design assumes. `Get_Connection_Info.php`'s hardcoded `/wp-json/mcp/wpmcp-server` was a guess that becomes correct only under (b).
- Official STDIO proxy for desktop clients: `@automattic/mcp-wordpress-remote` (npm).
- Recommended: vendor the adapter, register `wpmcp-server` on `plugins_loaded`, update `Get_Connection_Info`.

## Legend

pass / fail-bug / pass-with-caveat / blocked-skipped

## Results

### Structure / Content (Task #2)

> LOST: the per-domain result tables from here down (all 34 domains, ~145 rows) did not
> survive. The summary counts above are the durable record. Re-run the live pass to
> regenerate; the method section at the top is sufficient to reproduce.
