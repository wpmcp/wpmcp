# Site backup archive format (v1)

Status: phase 1 shipped (backup engine). Restore, migration and local-live
sync build on this format and are specified in the phased issues that
reference this document.

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
    "bytes": 18234123
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
