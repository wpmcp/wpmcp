# Issue 182: wp.org compliance, direct database query warnings

Plugin Check reports 72 combined `WordPress.DB.DirectDatabaseQuery.DirectQuery`
and `NoCaching` warnings plus `SlowDBQuery`. Definition of done:

- Zero unjustified `DirectDatabaseQuery` warnings
- Every ignore names why the query is direct and why it is not cached
- `SlowDBQuery` resolved or justified

## How the numbers here were measured

WPCS is **not installed on this branch** and there is no `phpcs-wporg.xml.dist`;
`composer lint` runs the PSR-12 ruleset only. So the counts below are *not*
phpcs output. They are `$wpdb` query call sites (`query`, `get_results`,
`get_row`, `get_col`, `get_var`, `insert`, `replace`, `update`, `delete`)
counted over `src/` by the same scanner that
`tests/free/Compliance/DirectDatabaseQueryAnnotationTest.php` uses.

A call site is not the same unit as a warning: one site can raise both
`DirectQuery` and `NoCaching`, and the sniff exempts some shapes entirely
(notably `wpdb::insert()`, which is on the sniff's noncachable list and so
never raises `NoCaching`). The issue's "72" is a warning count from the built
zip; treat the two figures as different units rather than reconciling them.

Measured at this branch head:

| | call sites |
|---|---|
| total in `src/` | 72 |
| annotated | 22 |
| remaining | 50 (across 11 files) |

## Approach

Three buckets, decided per call site:

1. **Justified scoped ignore** where the query must be direct and uncached
   (schema introspection, live diagnostics, atomic writes, before-image
   snapshots). Style follows `src/Tools/Backup/Db_Dumper.php`: one
   `phpcs:ignore` line naming every code it needs, with a concrete
   justification. Where the statement interpolates an identifier, that line
   also carries `WordPress.DB.PreparedSQL.NotPrepared`, because a table name
   cannot be bound with `prepare()`.
2. **Invalidate** after a direct write. This is what the `NoCaching` sniff
   actually asks for on `query`/`update`/`replace`/`delete`: its remedy list
   is `wp_cache_delete()`, not memoization. A raw write that leaves the object
   cache untouched is a staleness bug, not a documentation problem.
3. **Cache** where a cache is genuinely appropriate (repeated read-only
   lookups whose staleness window is acceptable).

## Done in this branch

### Justified ignores

- `src/Tools/Database/Database_Guard.php`: sql_mode probe (memoized in a
  static), SHOW TABLES validation, before-image capture, SHOW KEYS,
  SHOW COLUMNS
- `src/Tools/Database/Query.php`, `List_Tables.php`, `Describe_Table.php`:
  read tools whose purpose is live direct access. `Query.php` carries its own
  rationale, since there the SQL is genuinely caller-supplied and is gated by
  `Database_Guard::is_read_only_sql()` rather than by `prepare()`
- `src/Tools/Diagnostics/List_Transients.php`: no core API enumerates
  transients; the listing must be live
- `src/Auth/Code_Store.php`: the conditional UPDATE behind the
  compare-and-swap, plus the uncached row read that feeds it

### Real fixes, not just annotations

Two of the justifications written in the first pass were false, and the code
underneath them was wrong. Both are fixed here.

- **Row writes did not invalidate anything.** The original justification said
  "a write has nothing to cache". That is not true: only `wp_users` and
  `wp_usermeta` are protected, so `wp_options`, `wp_posts`, `wp_postmeta` and
  the term tables are all writable through these tools, and none of the core
  write APIs that normally clear those caches ever run. After an update the
  site kept serving the pre-write value from `get_option()`/`get_post()`.
  `Database_Guard::invalidate_caches()` now dispatches by table
  (`clean_post_cache`, post-meta delete, `clean_term_cache`, the options keys
  including the autoloaded `alloptions` blob) and falls back to dropping the
  runtime cache for an unrecognised table. `Insert_Row`, `Update_Rows` and
  `Delete_Rows` call it after a successful write.
- **`Code_Store::consume()` could redeem a code twice.** `consume()` re-read
  `$before` through `get_option()`, but `wpmcp_oauth_codes` is autoloaded, so
  `get_option()` serves it from the `alloptions` blob, which the existing
  `wp_cache_delete(self::OPTION, 'options')` does not touch. Two consequences,
  both now covered by tests: every retry in the CAS loop compared the same
  stale snapshot, so a caller that lost a race could never see the fresh row;
  and after a winning swap the consumed hash survived in cache, so the next
  `issue()`/`gc()` read-modify-write wrote the redeemed code back into
  `wp_options`, making it redeemable again inside its 60s TTL. `consume()` now
  reads the row directly, and the winning swap invalidates `alloptions`.

`Insert_Row`'s ignore no longer names `NoCaching`: `wpdb::insert()` is on the
sniff's noncachable list, so that code is never emitted there.

### SlowDBQuery

`src/Tools/Database/Database_Guard.php` `mask_user_secrets()` trips
`slow_db_query_meta_value` on `$rows[$index]['meta_value'] = ...`. That is an
array write on already-fetched result rows, not a meta query, so it is a false
positive and carries a justified ignore. It cannot be "fixed", only justified.

### Verification

`composer lint` is PSR-12 and would not notice any of this, so
`tests/free/Compliance/DirectDatabaseQueryAnnotationTest.php` is the guard:
for every file in its append-only covered list, each `$wpdb` call site must
carry a `DirectDatabaseQuery` ignore, each ignore must carry a real
justification, and the three row-write tools must invalidate. Behavior is
pinned separately by `tests/free/Database/DbWriteCacheInvalidationTest.php` and
the new `CodeStoreTest` cases.

This is a guard, not a substitute for the real ruleset. Landing WPCS plus a
`phpcs-wporg.xml.dist` run in CI is still outstanding (see below).

## Remaining work

50 call sites across 11 files, largest first:

- `src/Tools/Redirects/Redirect_Store.php` (11) and
  `src/Tools/Search/Search_Index_Store.php` (9): custom tables, and the reads
  here are the best genuine caching candidates in the tree
- `src/Safety/Snapshot_Store.php` (7), `src/Safety/Rollback_Service.php` (2):
  snapshot and rollback correctness depends on live reads and direct writes,
  so these are likely justified ignores. Note `Rollback_Service` already calls
  `clean_term_cache()` after its insert, which is the pattern to follow
- `src/Tools/Performance/Server_Audit.php` (5)
- `src/Integrations/Paid_Memberships_Pro_Integration.php` (4),
  `Gravity_Tables_Integration.php` (3)
- `src/Plugin.php` (3)
- `src/Tools/List_Operations.php` (2), `src/Tools/Cache/Clear_Cache.php` (2),
  `src/Tools/Elementor/Global_Class_Usage.php` (2)

Corrections to the first draft of this list, so the next pass does not chase
ghosts:

- `src/Safety/Snapshot.php`, `src/Tools/Backup/Site_Archive_Builder.php` and
  `src/Integrations/SureForms_Integration.php` have **no** `$wpdb` query call
  sites (their `$wpdb` references are property reads such as `$wpdb->prefix`).
  They were listed by mistake and are dropped.
- The `SlowDBQuery` is **not** a single hit to hunt down in
  `List_Theme_Templates` / `Memory_Store` / `MetForm_Integration`. The one that
  survives into the wp.org build is the `Database_Guard` false positive
  handled above; `src/Memory` is stripped by
  `scripts/flavors/wporg/strip.php`. Enumerate the rest with the real ruleset
  rather than by grep before acting on them.
- `src/Tools/Backup/Db_Dumper.php` is cited as the style precedent but is
  itself incomplete: its ignores name `DirectQuery`, `NoCaching` and
  `NotPrepared` but not `SchemaChange`, which still stands at its `CREATE`
  statement.

Also outstanding:

- Add WPCS to `require-dev` and land a `phpcs-wporg.xml.dist` run in CI.
  Until then nothing in CI verifies these annotations; the PHPUnit guard above
  is a stopgap that only covers files already on its list.
- Reconcile with `origin/compliance/wporg`. That branch annotates the **same
  call sites in the same 8 files** with different wording, so whichever lands
  second conflicts on every annotated line. See the PR body.
