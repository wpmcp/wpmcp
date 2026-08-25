# WordPress.org submission notes

Answers prepared for the review questions the directory build is most likely
to draw, with file references into the staged zip (paths are identical in the
source tree). Issue #179 tracks this document; the findings themselves are
enumerated by `composer compliance:wporg`.

## 1. The plugin/theme install, activate and delete abilities (guideline 8)

The zip contains 19 call sites that install, activate, update or remove
plugins and themes. Every one of them is a deliberate, user-initiated agent
ability; none is a bundling or promotion mechanism.

**What they are.** MCP abilities under the `packages` domain:
`install-plugin`, `activate-plugin`, `deactivate-plugin`, `delete-plugin`,
`update-plugin`, `install-theme`, `switch-theme`, `delete-theme`,
`update-theme` (registration: `src/Plugin.php`, `register_package_abilities`;
handlers: `src/Tools/Packages/*.php`).

**Why they do not trip guideline 8's concerns:**

- **Nothing installs on activation, in the background, or on a schedule.**
  The only path to any of these call sites is an authenticated MCP `tools/call`
  or REST invocation of the ability by a connected client. The plugin
  registers no cron task and no activation-time install of anything
  (`src/Activator.php` creates database tables only).
- **Nothing is bundled.** The zip contains no other plugin or theme, and the
  installer cannot be pointed at one: `src/Tools/Packages/Install_Plugin.php`
  validates the slug against `[a-z0-9-]` and resolves it through core's own
  `plugins_api()`, so only the wordpress.org repository can be installed from.
  There is no arbitrary-zip-URL path.
- **Core's own machinery does the work.** `Plugin_Upgrader` /
  `Theme_Upgrader` with `Automatic_Upgrader_Skin`; no bundled copy of the
  upgrader, no direct filesystem writes of plugin code.
- **Capability checks are the granular core ones, per ability**:
  `install_plugins`, `activate_plugins`, `delete_plugins`, `update_plugins`,
  `install_themes`, `switch_themes`, `delete_themes`, `update_themes` -
  enforced by the ability registrar before the handler runs, on top of the
  authenticated WordPress user the request maps to (application password or
  OAuth). An agent can never do more than the user it authenticates as.
- **Governance narrows further.** Every ability passes the plugin's
  governance layer (`src/Governance`), where each layer can only narrow
  permissions; site owners can disable any of these abilities wholesale, and
  `update-plugin` additionally ships default-disabled behind the
  `wpmcp_enable_update_plugin` filter and requires `confirm: true` per call.

## 2. Licensing SDK and updater (guideline 6 / Plugin_Updater_Check)

The directory build contains **no licensing SDK at all**. The build script
(`scripts/build-wporg-release.sh`) removes `src/Freemius`, `src/Pro`, and the
`freemius/wordpress-sdk` composer dependency (manifest and vendor tree), and
its gates fail the build if any `[Ff]reemius`, `fs_dynamic_init` or paid
predicate survives staging. `Plugin_Updater_Check` therefore has nothing to
find: there is no updater code, vendored or otherwise, in the zip.

## 3. The admin notice (`src/Admin/Announcements.php`)

- Renders only announcements fetched from the optional cloud service, and the
  directory build ships **no tool that can configure that service**
  (`Cloud_Config::save()` has no caller in the staged tree), so on a stock
  install this notice never renders at all.
- When it does render (the off-directory add-on configures the cloud), each
  notice carries a nonce-protected per-user **Dismiss** action persisted in
  user meta, and the feed is capability-gated. It carries no upgrade or
  purchase copy; content is the changelog-style feed the site owner opted
  into by connecting.

## 4. Scoped sniff suppressions that ship in the zip

Each `phpcs:ignore` in the zip names the sniff and carries a justification;
the two a reviewer will meet first:

- `src/MCP/Transport_Guard.php` - `ini_set('display_errors', '0')` scoped to
  the MCP request: a printed PHP notice corrupts JSON-RPC framing. Logging is
  untouched. Same pattern core uses for XML-RPC.
- `src/Tools/Performance/Curl_Dns_Pin.php` - `curl_setopt(CURLOPT_RESOLVE)`
  inside an `http_api_curl` filter callback: deliberate SSRF defence pinning
  the audited URL's DNS so a re-resolve cannot swap in a private address
  after validation. The request itself still goes through
  `wp_safe_remote_get()`; the HTTP API has no way to express this option.

## 5. Execution constructs

`eval()` and `proc_open()` do not exist in the zip. The two guarded pro-tier
abilities that used them (`run-php-snippet`, WP-CLI execution) are removed at
staging with the rest of the paid tier, and gate 2 of the build script
re-scans the staged tree at token level for the whole execution family
(eval, proc_open, shell_exec, passthru, popen, exec, system, pcntl_exec,
create_function, str_rot13, move_uploaded_file, assert) and fails the build
if any survives.

## 6. External services

Every fixed host (`api.wordpress.org`, `api.openverse.org`, `api.pexels.com`,
`api.unsplash.com`) and every dynamic destination (stock-image downloads,
`analyze-performance`'s caller-supplied URL behind a private-address refusal,
loopback self-test and analytics, the inert cloud client) is documented in
the `== External services ==` section of the shipped `readme.txt`, with
trigger, payload, and the provider's terms and privacy links. Nothing fires
outside the tool call that needs it; nothing fires on activation or on a
schedule.
