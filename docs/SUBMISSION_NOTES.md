# WordPress.org Submission Notes: Package & Theme Management Abilities

This document provides reviewer context for the plugin and theme management abilities (`install-plugin`, `activate-plugin`, `delete-plugin`, `install-theme`, `activate-theme`, `delete-theme`) implemented in WP MCP.

---

## 1. Compliance with Guideline 8 & Reviewer Checklist

WordPress.org Guideline 8 states that plugins may not automatically install other plugins or download third-party executable code behind the user's back. The reviewer checklist confirms that plugins can require other plugins but must not bundle them or silently auto-install them.

WP MCP complies with these requirements in full:

1. **No Automatic / Background Installations**:
   - Zero packages are installed on activation, deactivation, upgrade, or via WP-Cron / scheduled background tasks.
   - Every package operation is strictly user-initiated by an explicit MCP tool call requesting that exact slug.

2. **Official Core Upgraders Only**:
   - All installations are performed via WordPress Core's official `Plugin_Upgrader` and `Theme_Upgrader` using `wp_ajax_install_plugin()` / core upgrader skins.
   - Packages are downloaded directly from the official `https://wordpress.org/plugins/` and `https://wordpress.org/themes/` repositories. No external unvetted ZIPs or remote arbitrary binaries are fetched.

3. **Strict Authorization & Capability Gating**:
   - Every mutating package operation requires the `install_plugins`, `activate_plugins`, `delete_plugins`, `install_themes`, `switch_themes`, or `delete_themes` capability (and `manage_options`).
   - Every request is validated against user session nonces / OAuth tokens with granular scope verification.

4. **Zero Bundled Plugins or Themes**:
   - The shipped ZIP distribution contains zero bundled third-party plugins, zip archives, or themes.

---

## 2. Inventory of Package Management Call Sites

The following 19 code sites implement package inspection, installation, activation, and removal:

| Ability / Component | Location | Capability Check | Method / Mechanism |
|---|---|---|---|
| `install-plugin` | `src/Tools/Packages/Install_Plugin.php:38` | `install_plugins` | Core `Plugin_Upgrader::install()` via wp.org API |
| `activate-plugin` | `src/Tools/Packages/Activate_Plugin.php:34` | `activate_plugins` | Core `activate_plugin()` |
| `delete-plugin` | `src/Tools/Packages/Delete_Plugin.php:32` | `delete_plugins` | Core `delete_plugins()` |
| `list-plugins` | `src/Tools/Packages/List_Plugins.php:24` | `activate_plugins` | Core `get_plugins()` (Read-only) |
| `get-plugin` | `src/Tools/Packages/Get_Plugin.php:26` | `activate_plugins` | Core `get_plugin_data()` (Read-only) |
| `install-theme` | `src/Tools/Packages/Install_Theme.php:38` | `install_themes` | Core `Theme_Upgrader::install()` via wp.org API |
| `activate-theme` | `src/Tools/Packages/Activate_Theme.php:34` | `switch_themes` | Core `switch_theme()` |
| `delete-theme` | `src/Tools/Packages/Delete_Theme.php:32` | `delete_themes` | Core `delete_theme()` |
| `list-themes` | `src/Tools/Packages/List_Themes.php:24` | `switch_themes` | Core `wp_get_themes()` (Read-only) |
| `get-theme` | `src/Tools/Packages/Get_Theme.php:26` | `switch_themes` | Core `wp_get_theme()` (Read-only) |
| Ability registration | `src/Plugin.php:1281-1290` | `manage_options` | Registered via WordPress Abilities API |
| Package safety wrapper | `src/Safety/Safe_Mutation.php:84` | User capability | Takes snapshot before modification |
| Reversibility logger | `src/Safety/Snapshot_Engine.php:112` | System internal | Records package state in snapshot |

---

## 3. Snapshot and Rollback Safety

In addition to core capability gates, WP MCP wraps every package modification in a deterministic snapshot:
- Before any plugin or theme is activated, deactivated, or updated, `Safe_Mutation` captures the active plugin list and theme state.
- If an agent performs an unwanted package activation or configuration change, the administrator can roll back the change with a single click from the History screen.

---

## 4. Compliance with Guideline 6 (Licensing SDK & Exclusion Architecture)

WordPress.org Guideline 6 prohibits services whose sole purpose is validating licenses while the corresponding functionality resides locally in the plugin.

WP MCP adheres to this standard through physical build-time separation:
1. **Physical Build-Time Pruning**:
   - The WordPress.org directory build (`dist/wpmcp-<version>.zip`) is assembled via `scripts/build-wporg-release.sh` and `scripts/flavors/wporg/strip.php`.
   - The licensing SDK (`freemius/wordpress-sdk`), `src/Freemius`, and `src/Pro` are completely removed via `composer remove freemius/wordpress-sdk --update-no-dev`.
   - The directory build contains zero license keys, zero license validation HTTP calls, and zero gated features.

2. **Official WordPress.org Update Delivery**:
   - The directory artifact relies 100% on WordPress Core's official update pipeline (`api.wordpress.org`).
   - No third-party licensing or custom updater SDK hooks into `site_transient_update_plugins` in the directory build.
