# Static HTML export

WP MCP Pro can turn the site's public pages into plain HTML files with their CSS, JS, images and fonts, links rewritten to relative paths, as one ZIP. Uses: an archive of a finished campaign site, a static mirror on a CDN, a handoff copy, or a fallback while the live site is repaired.

It is off by default and needs an administrator (manage_options; manage_network_options on multisite). Switch it on in wp-config.php or an mu-plugin:

```php
define('WPMCP_ENABLE_STATIC_EXPORT', true);
// or
add_filter('wpmcp_enable_static_export', '__return_true');
```

The site needs pretty permalinks (any structure other than plain `?p=` links).

## Calls

There is no separate tool. The export is a kind of backup job:

- `trigger-backup` with `type: "static", plan: true` lists the URLs that would be exported (home page, published public posts and pages, non-empty public term archives, internal nav menu links), with counts per source, a rough size estimate and the URLs it refuses with a reason. Nothing is queued or fetched.
- `trigger-backup` with `type: "static"` queues the export and returns a `job_id`. Narrow it with `post_types` (comma separated) or `urls` (a list of URLs on this site). Without either, the export also follows the internal links of each page it renders, up to 500 pages (filter `wpmcp_static_export_max_pages`).
- `get-backup-status` with the `job_id` reports progress while it runs. A completed export carries `result` (ZIP path, size, pages, assets, skipped URLs with reasons, pages with forms, search or comments) and `download_url`: a private link that works only for the administrator who asked, while signed in, for 15 minutes (filter `wpmcp_static_export_download_ttl`).
- `cancel-backup-job` stops it and removes its work files. `delete-backup-archive` with the `job_id` removes the ZIP.

## How it works

Each page is fetched through a loopback request with no cookies, so it is exactly what an anonymous visitor sees. Assets on this site are copied from disk when they are a plain file type under the WordPress folder (never from a dot folder or one of WP MCP's own data folders) and fetched over HTTP otherwise. Off-site assets keep pointing at their origin. Admin, login, REST, feed and comment links, and any page URL with a query string such as search, keep pointing at the live site.

The ZIP mirrors the site's URL space: `/about/` is `about/index.html`, the home page is `index.html`, and links point at the explicit `index.html` so the copy opens from disk. A redirect becomes a small refresh page; a redirect loop, an error page and a non-HTML response are skipped and reported. `wpmcp-static-report.json` in the ZIP lists every page, every skip and every page whose forms, search or comments will not work statically.

The job runs on WP-Cron in steps, each within the backup step budget (filter `wpmcp_backup_step_limits`), and saves its state after every page and asset. If the host kills a step, a watchdog event resumes from the last saved state; three killed steps in a row fail the job. Each finished export keeps the newest three ZIPs (filter `wpmcp_static_export_keep`) and removes older ones.

Nothing on the site changes: the export only reads public pages and writes inside the protected site-backup folder (`uploads/wpmcp-site-backups`). Serving the static copy from WordPress itself is not part of this feature.
