---
name: Performance and caching
description: Measure first, find the cache layers, clear the right one, and change settings with a before and after check.
version: 1.0.0
tier: free
tags: [performance, cache]
requires:
  - wpmcp/analyze-performance
  - wpmcp/get-cache-status
  - wpmcp/clear-cache
---

# Performance checks and caching

Two different requests arrive as "the site is slow" and "my change is not
showing". Both start by finding out which caches exist.

## Find the cache layers

`wpmcp/get-cache-status` reports the object cache backend, OPcache and any
page cache plugin it detects. On W3 Total Cache, `get-w3tc-status` on
`wpmcp/theme-read` shows each of its caches and its engine. Other cache
plugins' settings operations, where this site has them, are listed by
`list-operations` on `wpmcp/theme-read`.

## "My change is not showing"

1. Read the change back with the tool that made it. If the data is right,
   it is a cache.
2. `wpmcp/get-rendered-html` for the page: is the old version still served?
3. `wpmcp/clear-cache` flushes the object cache, transients, OPcache and the
   detected page cache through its own API, and reports what each layer
   cleared. It is safe to repeat and has nothing to undo.
4. For W3 Total Cache, `purge-w3tc-cache` purges all of its caches; it needs
   `confirm: true`.
5. Read the page again. If it is still stale, the cache is outside
   WordPress (a CDN or the host); tell the user rather than clearing again.

Clearing caches on a busy site makes the next page loads slow while the
cache refills. Clear once, not in a loop.

## "The site is slow"

Worked example. User: "Why is the home page slow?"

1. `wpmcp/analyze-performance` for the front page. It checks the server,
   WordPress internals (database size, autoloaded options, cron backlog,
   object cache, OPcache, plugin count) and the page, and ranks fixes.
2. `wpmcp/get-site-health` for the WordPress Site Health view.
3. Report the top findings with their evidence. Do not change settings yet.
4. For each fix the user approves, make one change, then run
   `wpmcp/analyze-performance` again so the user sees its effect. Settings
   writes are snapshotted and undone with `wpmcp/rollback-operation`.

Do not deactivate plugins to "test" speed on a live site without asking:
deactivation can change what visitors see.
