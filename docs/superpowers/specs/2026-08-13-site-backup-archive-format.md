# Site backup archive format (v1)

Status: phase 1 shipped (backup engine), and in-place restore (issue #190,
see "Restore" below). Migration and local-live sync build on this format
and are specified in the phased issues that reference this document.

## Why a format at all

A backup that only this exact version of this plugin on this exact site can
read is not a backup, it is a coincidence. The archive is defined here so
that restore, site-to-site migration and local-live sync are three consumers
of one artifact rather than three independent pipelines, and so a human with
`unzip` and a `mysql` client can always recover a site without the plugin.

## Layout

```
wpmcp-<scope>-<UTC timestamp>-<random>.zip
  manifest.json          origin description, always present
  db.sql                 full SQL dump (scope: all, database)
  wp-content/...         site files (scope: all, files, uploads)
```

The archive lives in `wp-content/uploads/wpmcp-site-backups/`, which carries
an `.htaccess` deny rule, an empty `index.php` and a README explaining the
exposure. The random suffix in the filename is a security control, not
decoration: the archive contains every password hash and secret key on the
site, and on a server that ignores `.htaccess` a predictable name is a
download link.

## Scopes

| Scope | db.sql | wp-content | Use |
|---|---|---|---|
| `all` | yes | yes | Move or rebuild a whole site |
| `database` | yes | no | Fast pre-change safety net |
| `files` | no | yes | Media and code only |
| `uploads` | no | uploads only | Media only |

`Run_Backup_Job::archive_scope()` maps a queued job's `type`/`scope` onto
these. The `content` type predates archives and still produces a WXR export.

## manifest.json

```json
{
  "format": "wpmcp-site-backup",
  "format_version": 1,
  "created_at": "2026-08-13T00:00:00+00:00",
  "scope": "all",
  "site": {
    "site_url": "https://origin.example",
    "home_url": "https://origin.example",
    "table_prefix": "wp_",
    "base_prefix": "wp_",
    "multisite": false,
    "charset": "utf8mb4",
    "locale": "en_US"
  },
  "versions": { "wordpress": "6.9", "php": "8.3.0", "plugin": "0.8.0" },
  "database": {
    "tables": { "wp_posts": 412 },
    "row_count": 8123,
    "blob_tables": ["wp_some_plugin_cache"],
    "bytes": 18234123,
    "percent": "literal"
  },
  "files": { "count": 4211 }
}
```

`site_url` and `home_url` are what a migration rewrites *from*; `table_prefix`
is what a restore checks against the target. `blob_tables` exists because of
the dump's one honest limitation (below), so a restore can warn instead of
silently producing a corrupt row.

## The dump

Generated entirely through `$wpdb`. No `mysqldump`: it is absent or disabled
on most managed WordPress hosts, and the directory guidelines forbid shipping
code that shells out to it.

- Tables are scoped by prefix, so a shared database hosting several installs
  only dumps this one. On multisite the *base* prefix is used, capturing the
  global tables plus every sub-site.
- Rows are read in batches of `Db_Dumper::BATCH` and statements are capped at
  `MAX_STATEMENT_BYTES`, so peak memory tracks the batch, not the database,
  and no generated statement approaches `max_allowed_packet`.
- Values are escaped through `$wpdb::prepare()`. NULL is emitted as a real
  SQL `NULL`, never the string `'NULL'`.
- The preamble suspends `FOREIGN_KEY_CHECKS` and `UNIQUE_CHECKS` (tables
  arrive alphabetically, not in dependency order) and pins `sql_mode`, so a
  dump taken on a permissive server imports on a strict one. The footer
  restores both.

**Known limitation, deliberately surfaced rather than hidden:** values are
emitted as escaped string literals. That round-trips every column type
WordPress and its plugin ecosystem actually use, but a true binary BLOB
containing invalid UTF-8 can be mangled. Affected tables are listed in
`manifest.database.blob_tables`.

Writes go through buffered `file_put_contents(..., FILE_APPEND)`, not an
`fopen` handle: `WordPress.WP.AlternativeFunctions` is an error under the
directory review ruleset and forbids the handle functions while excluding
`file_put_contents`. The 1MB buffer keeps that from meaning one filesystem
call per statement.

## File selection

Skipped everywhere in the tree: the plugin's own backup, export and snapshot
directories (a backup containing previous backups grows without bound and, on
the second run, reads the file it is writing), plus `cache`, `node_modules`,
`.git`, `.svn`, `upgrade`, and `.log`/`.zip`/`.gz`/`.tar`/`.sql` files.

Symlinks are skipped rather than followed: following them can walk out of
`wp-content` entirely (or into a loop) and pull unrelated server files into an
archive the user may hand to someone else.

## Failure behavior

A build that fails at any point deletes both the partial zip and the scratch
`.sql`. A truncated archive that still looks like a backup is more dangerous
than an obvious failure, because it is the file someone reaches for during an
incident.

## Reading and pruning

`get-backup-manifest` and `delete-backup-archive` both resolve their target
through `Archive_Locator`, which enforces containment on the **realpath**
against the realpath of the backup directory. These tools take a path from an
MCP client driven by a model acting on text it read somewhere on the site, so
`../../../wp-config.php` is a request that will eventually be made; resolving
symlinks is what stops a link inside the backup directory from satisfying a
naive string-prefix check while pointing anywhere on disk.

## What restore and migration add

`Url_Rewriter` ships with this phase although nothing calls it yet, because
it is the hard part of both consumers and is worth landing under test early.
It is serialization-aware: WordPress stores PHP-serialized arrays as text, a
serialized string carries its own byte length, and a naive SQL `REPLACE`
changes the bytes but not the length, so `unserialize()` rejects the value and
the option reads back as `false`. That is how "the migration worked but the
widgets are gone" happens.

It walks the decoded structure instead, and:

- never instantiates objects (`allowed_classes => false`), so a restore is
  not a PHP object injection sink;
- refuses to rewrite any value whose structure contains an object anywhere,
  because re-serializing a `__PHP_Incomplete_Class` does not reliably
  reproduce the original bytes (private and protected property names carry
  NUL-delimited class prefixes that do not survive the round trip). A missed
  URL is recoverable; a mangled object is not. `would_rewrite()` lets a
  caller report what it skipped;
- replaces the plain, JSON-escaped (`https:\/\/host`, as block editor content
  stores it), percent-encoded and scheme-relative forms, longest first, so a
  shorter form cannot consume a longer one and leave a half-rewritten URL.

## Restore (issue #190)

`restore-site-backup` consumes this format in place, on the same site. The
order is the design:

1. **Gate on manifest.json.** Unknown `format`, a newer `format_version`, a
   scope without `db.sql`, a `table_prefix` or `multisite` mismatch, or a
   manifest missing any of those fields is a refusal. A WordPress downgrade
   and non-empty `blob_tables` are warnings.
2. **Validate the whole dump before writing.** `db.sql` is extracted to a
   scratch file (libzip streams it and checks its CRC; `getFromName()` on a
   large dump is the memory blowup the dumper exists to avoid), its size is
   compared with `database.bytes`, and every statement is parsed
   (`Sql_Statement_Reader`, bounded, quote-aware) and held to
   `Sql_Import_Policy`: only the `SET`, `DROP TABLE IF EXISTS`,
   `CREATE TABLE` and literal-only `INSERT` statements this dumper writes,
   only on this site's prefix, only on tables the manifest lists. A dump
   that ends mid-statement, or a statement over `max_allowed_packet`, is
   refused here. A dry run stops after this step.
3. **Safety archive.** A `database`-scope archive of the current site, taken
   through the normal backup job machinery. If it fails, nothing starts.
4. **Maintenance mode** through `Maintenance_Guard`'s option, re-asserted as
   soon as the dump has replaced the options table (the imported row is
   what the site keeps afterwards).
5. **Import statement by statement**, stopping at the first failure with
   its ordinal, byte offset, kind, table and database error, then importing
   the safety archive to put the site back. A progress file next to the
   archives records where a restore is, so a request that dies part-way is
   reported by the next call.
6. **Afterwards:** the acting user's credentials (password hash, session
   tokens, application passwords, their own MCP tokens) are written back so
   they stay signed in, or the result says a re-login is needed; the backup
   job history is kept (it describes files on disk); with `include_files`,
   the wp-content tree staged under `wp-content/wpmcp-restore/` is swapped
   in entry by entry with a journalled rollback, the replaced entries are
   kept under `wp-content/wpmcp-restore/previous-*`, and the running plugin
   plus the backup, export and file-backup directories are carried across.

Dumps written before this change stored every `%` in the data as
`$wpdb->prepare()`'s per-request placeholder token. `Db_Dumper` now removes
it and the manifest says so (`database.percent: "literal"`). For an archive
without that key, the importer converts the token back to `%`, but only if
no value in the dump contains a literal `%` (a dump that does was written
after the fix, so a `{64 hex}` string in it is real data).
