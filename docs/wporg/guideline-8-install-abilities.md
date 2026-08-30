# Guideline 8 submission note: the plugin and theme install abilities

Prepared for issue #179 (finding R-01). This is the written answer to the
question a wp.org reviewer is most likely to ask about the `packages` ability
group: "this plugin can install, activate and delete plugins and themes, is
that not guideline 8?" The short answer lives in `WPORG-SUBMISSION.md`
section 5.5; this note is the long form, with the complete site list, so the
answer exists before the question is asked.

## The claim, in one paragraph

Guideline 8 prohibits serving updates or installing plugins, themes or
add-ons **from servers other than WordPress.org's**. Every install and update
path in this plugin drives WordPress core's own `Plugin_Upgrader` /
`Theme_Upgrader` against the wordpress.org repository, resolved through
core's `plugins_api()` / `themes_api()`, exactly as the wp-admin Plugins and
Themes screens do. Input is a wordpress.org-style slug validated by regex, so
the tools cannot be turned into an arbitrary-zip-URL installer. Nothing runs
automatically: each site executes only inside an ability handler, which the
MCP registrar dispatches only after `current_user_can()` passes for the exact
core capability the equivalent wp-admin screen requires
(`src/Mcp/Registrar.php:93`). No plugin or theme is bundled in the zip, and
nothing is installed on activation or in the background.

## Why the checklist's "required but not included or auto-installed" rule is satisfied

* This plugin does not require any other plugin. The package tools are a
  general administrative surface, like the Plugins screen itself; they do not
  exist to pull in a dependency of ours.
* Nothing is auto-installed. `src/Activator.php` (the only
  `register_activation_hook` callback, wired at `src/Plugin.php:421-422`)
  creates two or three database tables and schedules an OAuth GC cron. It
  never touches the upgrader, cron never triggers an install, and no install
  site is reachable outside an explicit, authenticated ability invocation.
* Nothing is bundled. `scripts/build-wporg-release.sh` stages only `LICENSE`,
  the composer manifest, `src/`, the flavor main file and `readme.txt`; there
  is no `wp-content/`, no vendored plugin or theme, and no `.zip` anywhere in
  `src/`.

## Complete site list

The finding counted 19 install, activate and delete sites. Against the
current tree the same surface is 9 ability registrations plus 11 execution
call sites (two of the call sites are the optional `activate: true` branches
of the two installers). Line numbers below are as of this note's commit.

### Ability registrations, `src/Plugin.php`

Each registration carries the capability in the `Ability` constructor; the
registrar refuses dispatch without it.

| # | Line | Ability | Capability | Extra guardrails |
|---|------|---------|------------|------------------|
| 1 | `src/Plugin.php:1568` | `wpmcp/activate-plugin` | `activate_plugins` | snapshot of `active_plugins`, rollbackable |
| 2 | `src/Plugin.php:1585` | `wpmcp/deactivate-plugin` | `activate_plugins` | refuses protected plugins (`Package_Guard`) |
| 3 | `src/Plugin.php:1602` | `wpmcp/install-plugin` | `install_plugins` | slug regex, wp.org lookup only |
| 4 | `src/Plugin.php:1619` | `wpmcp/update-plugin` | `update_plugins` | disabled by default (`wpmcp_enable_update_plugin`), requires `confirm: true` |
| 5 | `src/Plugin.php:1639` | `wpmcp/delete-plugin` | `delete_plugins` | disabled by default (`wpmcp_enable_delete_plugin`), requires `confirm: true`, refuses protected or active plugins |
| 6 | `src/Plugin.php:1670` | `wpmcp/switch-theme` | `switch_themes` | snapshot of `template`/`stylesheet`, rollbackable |
| 7 | `src/Plugin.php:1687` | `wpmcp/install-theme` | `install_themes` | slug regex, wp.org lookup only |
| 8 | `src/Plugin.php:1704` | `wpmcp/update-theme` | `update_themes` | disabled by default (`wpmcp_enable_update_theme`), requires `confirm: true` |
| 9 | `src/Plugin.php:1724` | `wpmcp/delete-theme` | `delete_themes` | disabled by default (`wpmcp_enable_delete_theme`), requires `confirm: true`, refuses the active theme or its parent |

### Execution call sites, `src/Tools/Packages/`

| # | Site | Call | Notes |
|---|------|------|-------|
| 10 | `src/Tools/Packages/Install_Plugin.php:54` | `Plugin_Upgrader::install()` | download link comes from `plugins_api('plugin_information')` at line 48, never from input |
| 11 | `src/Tools/Packages/Install_Plugin.php:67` | `activate_plugin()` | only when the caller passed `activate: true` |
| 12 | `src/Tools/Packages/Update_Plugin.php:71` | `Plugin_Upgrader::upgrade()` | core update transient decides the source; no-op when up to date |
| 13 | `src/Tools/Packages/Activate_Plugin.php:46` | `activate_plugin()` | already-installed plugin only |
| 14 | `src/Tools/Packages/Deactivate_Plugin.php:48` | `deactivate_plugins()` | refuses protected plugins |
| 15 | `src/Tools/Packages/Delete_Plugin.php:66` | `delete_plugins()` | opt-in filter plus `confirm: true` |
| 16 | `src/Tools/Packages/Install_Theme.php:53` | `Theme_Upgrader::install()` | download link comes from `themes_api('theme_information')` at line 47 |
| 17 | `src/Tools/Packages/Install_Theme.php:61` | `switch_theme()` | only when the caller passed `activate: true` |
| 18 | `src/Tools/Packages/Update_Theme.php:63` | `Theme_Upgrader::upgrade()` | core update transient decides the source |
| 19 | `src/Tools/Packages/Switch_Theme.php:64` | `switch_theme()` | already-installed theme only |
| 20 | `src/Tools/Packages/Delete_Theme.php:62` | `delete_theme()` | opt-in filter plus `confirm: true` |

Read-only neighbors that share the directory but change nothing:
`Search_Plugins.php:61` and `Get_Plugin_Info.php:29` call `plugins_api()`
the same way the plugin-install screen's search box does, and
`List_Plugins.php` / `List_Themes.php` only enumerate what is installed.

## Shared guardrails, in one place

* **Capability gate:** `src/Mcp/Registrar.php:93` runs
  `current_user_can($ability->capability)` before any handler executes, on
  top of tier, governance and identity-scope checks in the same method. The
  capabilities are the granular core ones listed above, which on a standard
  install belong to administrators only.
* **wp.org only:** both installers resolve the download URL through
  `plugins_api()` / `themes_api()` and validate the slug against a
  wordpress.org-style pattern first, so no caller-supplied URL or path ever
  reaches the upgrader.
* **Core machinery:** installs and updates go through
  `Plugin_Upgrader` / `Theme_Upgrader` with `Automatic_Upgrader_Skin`; the
  plugin reimplements none of the transfer, unpack or overwrite logic.
* **Filesystem honesty:** `Package_Guard::filesystem_ready()` refuses every
  install, update and delete unless core reports `direct` filesystem access,
  rather than attempting a credential-based FTP/SSH connection.
* **Protected packages:** `Package_Guard::PROTECTED_PLUGINS` prevents the
  tools from deactivating or deleting the plugin itself or the site's page
  builder.
* **Destructive paths are opt-in twice:** update and delete abilities ship
  disabled behind `wpmcp_enable_*` filters and additionally require
  `confirm: true` in the request.

## Definition-of-done checklist from the issue

* Written submission note covering all sites: this document (20 sites in the
  current tree; the finding's count of 19 was taken against an earlier
  revision).
* No install path fires without an explicit request: confirmed. The only
  activation hook is `src/Activator.php` (tables and a cron), no cron or
  background task reaches `src/Tools/Packages/`, and every handler is
  reachable only through the capability-checked registrar dispatch.
* No plugin or theme bundled in the zip: confirmed against
  `scripts/build-wporg-release.sh` (stages `src/`, main file, readme,
  LICENSE, composer manifest; nothing else) and a tree search showing no
  `.zip` or `wp-content/` payload under `src/`.

## Maintenance

Line numbers in this note drift as `src/Plugin.php` grows. Before pasting any
part of this into a reviewer reply, re-run:

```
grep -n "wpmcp/install-plugin\|wpmcp/install-theme\|wpmcp/activate-plugin\|wpmcp/delete-plugin\|wpmcp/delete-theme" src/Plugin.php
grep -rn "Upgrader\|activate_plugin(\|delete_plugins(\|delete_theme(\|switch_theme(" src/Tools/Packages/
```
