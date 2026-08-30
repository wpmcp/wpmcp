# Guideline 8 submission note: the plugin and theme install abilities

Prepared for issue #179 (finding R-01). This is the written answer to the
question a wp.org reviewer is most likely to ask about the `packages` ability
group: "this plugin can install, activate and delete plugins and themes, is
that not guideline 8?" The short answer lives in `WPORG-SUBMISSION.md`
section 5.5; this note is the long form, with the complete site list, so the
answer exists before the question is asked.

## The claim, in one paragraph

Guideline 8 prohibits serving updates or installing plugins, themes or
add-ons **from servers other than WordPress.org's**. This plugin serves
nothing of its own: it has no update server, no bundled package, and no code
path that downloads from a wpmcp-controlled host.

Installs resolve the package through core's `plugins_api()` / `themes_api()`
by wordpress.org slug, and the slug is validated by a wordpress.org-style
regex first, so the tools cannot be turned into an arbitrary-zip-URL
installer. Updates do not call those APIs: like the wp-admin update button,
they hand the already-installed plugin file or stylesheet to core's
`Plugin_Upgrader::upgrade()` / `Theme_Upgrader::upgrade()`, which reads the
package URL from core's own `update_plugins` / `update_themes` site
transients. That source is whatever core itself would use for that package,
which for a wordpress.org-hosted package is wordpress.org. wpmcp does not
write those transients and does not filter them.

Nothing runs automatically: each site executes only inside an ability
handler, which the MCP registrar dispatches only after `current_user_can()`
passes for the core capability recorded on that ability
(`src/MCP/Registrar.php:93`). The capabilities are the granular core ones the
equivalent wp-admin screen uses, and where one ability performs two screens'
worth of work (the installers' optional `activate: true` step) the second
capability is checked explicitly inside the handler. Nothing is installed on
activation or in the background.

## Why the checklist's "required but not included or auto-installed" rule is satisfied

* This plugin does not require any other plugin. The package tools are a
  general administrative surface, like the Plugins screen itself; they do not
  exist to pull in a dependency of ours.
* Nothing is auto-installed. `src/Activator.php` (the only
  `register_activation_hook` callback, wired at `src/Plugin.php:421-422`)
  creates two or three database tables and schedules an OAuth GC cron. It
  never touches the upgrader, and no install site is reachable outside an
  explicit, authenticated ability invocation.
* No plugin or theme is bundled. `scripts/build-wporg-release.sh` stages
  `LICENSE`, the composer manifest, `src/`, the flavor main file and
  `readme.txt`, and then runs `composer install --no-dev` inside the stage,
  so the zip also contains a `vendor/` directory of third-party PHP libraries
  (`wordpress/mcp-adapter` and its dependencies, with the licensing SDK
  removed by a gate in the same script). Those are libraries loaded by our
  autoloader, not installable WordPress packages: there is no `wp-content/`,
  no vendored plugin or theme, and no `.zip` anywhere in the tree.

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
| 1 | `src/Plugin.php:1569` | `wpmcp/activate-plugin` | `activate_plugins` | snapshot of `active_plugins`, rollbackable |
| 2 | `src/Plugin.php:1586` | `wpmcp/deactivate-plugin` | `activate_plugins` | refuses protected plugins (`Package_Guard`) |
| 3 | `src/Plugin.php:1603` | `wpmcp/install-plugin` | `install_plugins` | slug regex, wp.org lookup only; `activate: true` additionally requires `activate_plugins` and is snapshotted |
| 4 | `src/Plugin.php:1620` | `wpmcp/update-plugin` | `update_plugins` | disabled by default (`wpmcp_enable_update_plugin`), requires `confirm: true` |
| 5 | `src/Plugin.php:1640` | `wpmcp/delete-plugin` | `delete_plugins` | disabled by default (`wpmcp_enable_delete_plugin`), requires `confirm: true`, refuses protected or active plugins |
| 6 | `src/Plugin.php:1671` | `wpmcp/switch-theme` | `switch_themes` | snapshot of `template`/`stylesheet`, rollbackable |
| 7 | `src/Plugin.php:1688` | `wpmcp/install-theme` | `install_themes` | slug regex, wp.org lookup only; `activate: true` additionally requires `switch_themes` and is snapshotted |
| 8 | `src/Plugin.php:1705` | `wpmcp/update-theme` | `update_themes` | disabled by default (`wpmcp_enable_update_theme`), requires `confirm: true` |
| 9 | `src/Plugin.php:1725` | `wpmcp/delete-theme` | `delete_themes` | disabled by default (`wpmcp_enable_delete_theme`), requires `confirm: true`, refuses the active theme or its parent |

### Execution call sites, `src/Tools/Packages/`

| # | Site | Call | Notes |
|---|------|------|-------|
| 10 | `src/Tools/Packages/Install_Plugin.php:72` | `Plugin_Upgrader::install()` | download link comes from `plugins_api('plugin_information')` at line 66, never from input |
| 11 | `src/Tools/Packages/Install_Plugin.php:107` | `activate_plugin()` | only when the caller passed `activate: true`; gated on `activate_plugins` before the download runs, and wrapped in `Safe_Mutation::run` on `active_plugins`, so it returns a rollbackable `operation_id` |
| 12 | `src/Tools/Packages/Update_Plugin.php:71` | `Plugin_Upgrader::upgrade()` | takes the installed plugin file, not a URL; core's `update_plugins` transient supplies the package, exactly as the wp-admin update button does. No-op when up to date |
| 13 | `src/Tools/Packages/Activate_Plugin.php:46` | `activate_plugin()` | already-installed plugin only, inside `Safe_Mutation::run` |
| 14 | `src/Tools/Packages/Deactivate_Plugin.php:48` | `deactivate_plugins()` | refuses protected plugins |
| 15 | `src/Tools/Packages/Delete_Plugin.php:66` | `delete_plugins()` | opt-in filter plus `confirm: true` |
| 16 | `src/Tools/Packages/Install_Theme.php:75` | `Theme_Upgrader::install()` | download link comes from `themes_api('theme_information')` at line 69 |
| 17 | `src/Tools/Packages/Install_Theme.php:116` | `switch_theme()` | only when the caller passed `activate: true`; gated on `switch_themes` before the download runs, and preceded by snapshots of `template` and `stylesheet`, so it returns rollbackable `operation_ids` |
| 18 | `src/Tools/Packages/Update_Theme.php:63` | `Theme_Upgrader::upgrade()` | takes the installed stylesheet, not a URL; core's `update_themes` transient supplies the package |
| 19 | `src/Tools/Packages/Switch_Theme.php:64` | `switch_theme()` | already-installed theme only, preceded by both option snapshots |
| 20 | `src/Tools/Packages/Delete_Theme.php:62` | `delete_theme()` | opt-in filter plus `confirm: true` |

Read-only neighbors that share the directory but change nothing:
`Search_Plugins.php:61` and `Get_Plugin_Info.php:29` call `plugins_api()`
the same way the plugin-install screen's search box does, and
`List_Plugins.php` / `List_Themes.php` only enumerate what is installed.

### The second invocation route

Every ability above is also reachable by name through the compact-surface
dispatcher `wpmcp/call-tool` (`src/Plugin.php:6692`,
`src/Tools/Dispatch/Call_Tool.php`). That route adds no privilege: it
proxies only wpmcp-registered abilities and its single invocation path is
`WP_Ability::execute()` (`Call_Tool.php:90`), the same entry point a direct
tool call uses, so the target's `permission_callback`
(`Registrar::is_permitted`), its schema validation and its Safe_Mutation
path all run identically. It is listed here so a reviewer auditing
reachability from this note finds it in the note rather than by surprise.

## Shared guardrails, in one place

* **Capability gate:** `src/MCP/Registrar.php:93` runs
  `current_user_can($ability->capability)` before any handler executes, on
  top of tier, governance and identity-scope checks in the same method. The
  capabilities are the granular core ones listed above, which on a standard
  install belong to administrators only.
* **Second capability where a handler spans two screens:** the installers'
  `activate: true` step checks `activate_plugins` / `switch_themes`
  explicitly (`Install_Plugin.php:126`, `Install_Theme.php:126`) and does so
  before the download, so a caller who may install but not activate is
  refused without leaving a package on disk.
* **wp.org only for installs:** both installers resolve the download URL
  through `plugins_api()` / `themes_api()` and validate the slug against a
  wordpress.org-style pattern first, so no caller-supplied URL or path ever
  reaches the upgrader.
* **Core machinery:** installs and updates go through
  `Plugin_Upgrader` / `Theme_Upgrader` with `Automatic_Upgrader_Skin`; the
  plugin reimplements none of the transfer, unpack or overwrite logic, and
  does not influence where core resolves an update package from.
* **Filesystem honesty:** `Package_Guard::filesystem_ready()` refuses every
  install, update and delete unless core reports `direct` filesystem access,
  rather than attempting a credential-based FTP/SSH connection.
* **Protected packages:** `Package_Guard::PROTECTED_PLUGINS` prevents the
  tools from deactivating or deleting the plugin itself or the site's page
  builder.
* **Destructive paths are opt-in twice:** update and delete abilities ship
  disabled behind `wpmcp_enable_*` filters and additionally require
  `confirm: true` in the request.
* **Every state-changing package path is rollbackable or additive:** the
  install step itself only adds files; every path that overwrites prior state
  (activate, deactivate, switch, and the installers' activate step) snapshots
  that state first through `Snapshot_Store`, so `wpmcp/rollback-operation`
  can undo it.

## Definition-of-done checklist from the issue

* **Written submission note covering all sites:** this document (20 sites in
  the current tree; the finding's count of 19 was taken against an earlier
  revision), plus the `wpmcp/call-tool` route noted above.
* **No install path fires without an explicit request:** confirmed. The only
  activation hook is `src/Activator.php` (tables and a cron). No wpmcp
  package tool is reachable from cron: `src/Tools/Packages/` registers no
  cron callback, and `wpmcp/schedule-event` cannot re-schedule the core
  update hooks, which are denylisted in `Core_Hooks::PROTECTED`
  (`wp_version_check`, `wp_update_plugins`, `wp_update_themes` and
  `wp_maybe_auto_update`, the hook that drives core's unattended
  `Plugin_Upgrader`/`Theme_Upgrader` run). The only background upgrade route
  on the site remains core's own auto-updater against wp.org, which this
  plugin neither adds nor redirects. Every handler is otherwise reachable
  only through the capability-checked registrar dispatch.
* **No plugin or theme bundled in the zip:** confirmed against
  `scripts/build-wporg-release.sh`. The zip contains `src/`, the main file,
  `readme.txt`, `LICENSE`, the composer manifest and the `composer install
  --no-dev` `vendor/` tree of PHP libraries; a tree search shows no `.zip`
  and no `wp-content/` payload anywhere.

## Maintenance

Line numbers in this note drift as `src/Plugin.php` grows. Before pasting any
part of this into a reviewer reply, re-run:

```
grep -n "wpmcp/install-plugin\|wpmcp/install-theme\|wpmcp/activate-plugin\|wpmcp/delete-plugin\|wpmcp/delete-theme" src/Plugin.php
grep -rn "Upgrader\|activate_plugin(\|delete_plugins(\|delete_theme(\|switch_theme(" src/Tools/Packages/
grep -rn "current_user_can(" src/Tools/Packages/
```
