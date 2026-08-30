# Issue 182: wp.org compliance, direct database query warnings

Plugin Check reports 72 combined `WordPress.DB.DirectDatabaseQuery.DirectQuery`
and `NoCaching` warnings plus one `SlowDBQuery`. Definition of done:

- Zero unjustified `DirectDatabaseQuery` warnings
- Every ignore names why the query is direct and why it is not cached
- `SlowDBQuery` resolved or justified

## Approach

Two buckets, decided per call site:

1. **Justified scoped ignore** where the query must be direct and uncached
   (schema introspection, live diagnostics, atomic writes, before-image
   snapshots). Style follows the existing precedent in
   `src/Tools/Backup/Db_Dumper.php`: a single `phpcs:ignore` line naming both
   sniff codes and a concrete justification.
2. **Caching** where a cache is genuinely appropriate (repeated read-only
   lookups whose staleness window is acceptable), via
   `wp_cache_get()`/`wp_cache_set()` with a plugin cache group.

## Done in this branch

Justified ignores at every call site where a direct, uncached query is the
point of the code:

- `src/Tools/Database/Database_Guard.php`: sql_mode probe (memoized in a
  static), SHOW TABLES validation, before-image capture, SHOW KEYS,
  SHOW COLUMNS
- `src/Tools/Database/Query.php`, `List_Tables.php`, `Describe_Table.php`:
  read tools whose purpose is live direct access
- `src/Tools/Database/Insert_Row.php`, `Update_Rows.php`, `Delete_Rows.php`:
  parameterized writes, nothing to cache
- `src/Tools/Diagnostics/List_Transients.php`: no core API to enumerate
  transients; listing must be live
- `src/Auth/Code_Store.php`: atomic compare-and-swap that must bypass the
  options cache for single-redemption

## Remaining work

Audit and resolve (ignore with justification, or add caching) the remaining
`$wpdb` call sites:

- `src/Safety/Snapshot_Store.php`, `src/Safety/Snapshot.php`,
  `src/Safety/Rollback_Service.php` (likely justified: snapshot and rollback
  correctness depends on live reads and direct writes)
- `src/Tools/Search/Search_Index_Store.php`, `src/Tools/Redirects/Redirect_Store.php`
  (custom tables; reads here are caching candidates)
- `src/Tools/Elementor/Global_Class_Usage.php`, `src/Tools/List_Operations.php`,
  `src/Tools/Performance/Server_Audit.php` (read-only scans; decide per site)
- `src/Tools/Cache/Clear_Cache.php` (cache flush is inherently direct)
- `src/Tools/Backup/Site_Archive_Builder.php`
- `src/Plugin.php`
- `src/Integrations/SureForms_Integration.php`,
  `Paid_Memberships_Pro_Integration.php`, `Gravity_Tables_Integration.php`
- Locate the single `SlowDBQuery` (candidates: `meta_query` usage in
  `src/Tools/Elementor/List_Theme_Templates.php`, `src/Memory/Memory_Store.php`,
  `src/Integrations/MetForm_Integration.php`) and resolve or justify it
- Run Plugin Check / phpcs with the WordPress ruleset to confirm zero
  unjustified warnings remain
