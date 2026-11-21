# WordPress MCP governance: refusal codes, compact mode, new tools, IP pinning and security

Moved from the repository README; the short overview is in [README.md](../../README.md) and the index of these pages in [docs/reference/README.md](README.md).

## Refusal codes for switched-off features

Two error codes mean a feature is switched off, and they ask for different fixes:

- `opt_in_required`: the tool or operation ships off and this site has not opted in. Every such refusal uses this code: default-off tools (database, filesystem and package writes, WP-CLI, PHP execution, custom JS, the ability bridge, migrations, project memory), default-off integration operations (pack write switches such as `wpmcp_enable_memberpress_write`, `wpmcp_enable_learndash_learner_write` or `wpmcp_enable_theme_write`, and per-operation opt-ins), and destructive `woo-write` ops. The message names the exact filter (and the `wp-config.php` constant, where one exists) and the line of PHP that switches it on; `data.filter` carries the filter name.
- `operation_disabled`: the operation is on by default and this site switched it off with `wpmcp_integration_op_enabled` or `wpmcp_woo_op_enabled`. Opting in is not the fix; removing that filter is.

Before this split every default-off integration op and `woo-write` op answered `operation_disabled`, and some gates had codes of their own (`wpmcp_bridge_disabled`, `js_gate_closed`, `wpmcp_migration_receive_disabled`, `wpmcp_migration_send_disabled`, `wpmcp_memory_disabled`). They all answer `opt_in_required` now.

## Compact tool surface

wpmcp registers up to 407 tools, depending on licence and active plugins. Some MCP clients cap tool counts outright, and all of them pay token cost for every `tools/list`. **Compact mode** collapses the advertised surface to five tools: `list-tools`, `get-tool-schema`, `call-tool`, plus the connection basics `get-connection-info` and `get-site-context`, while every other tool stays reachable through `call-tool`.

Compact mode is exposure-only and off by default. It never changes which abilities are registered and it is not a permission boundary: a dispatched call runs the target tool's own capability check, governance, identity scope, license check, rate limit, input validation, and snapshot/rollback behavior exactly as a direct call would (a conformance suite sweeps the entire surface in both modes to prove it). `call-tool` refuses abilities that were not registered by wpmcp.

Choose the mode site-wide with the `wpmcp_tool_exposure_mode` option (`full` or `compact`), per scoped identity via the identity's `exposure` field (`create-identity` accepts it; it overrides the site setting for that agent), or in code via the `wpmcp_tool_exposure_mode` filter. The `wpmcp_compact_exposed_abilities` filter can add tools to the compact core (the three meta-tools are always included).

Measured `tools/list` payload in the test environment (unlicensed, optional test plugins active): **full** about 136,000 bytes across 270 tools; **compact** about 2,200 bytes across 5 tools, a **98% reduction**. A checked-in budget test (`tests/free/MCP/ToolsListBudgetTest.php`) caps the compact payload and prints these numbers on every test run.

## New tools after updates

A tool that an update ships switched off stays off until an administrator turns it on. Site owners who want every new tool without visiting the Abilities screen can turn on **Switch on new tools after updates** there, or send `update-governance-settings` with `{"auto_enable_new_tools": true}` (`get-governance-settings` reports it). The setting is off by default, every change is written to the governance audit log, and the MCP change returns an `operation_id` that `rollback-operation` undoes.

With the setting on, an update still leaves these off:

- tools that run code, write files or database rows, or manage users, roles, plugins or themes, and every tool behind an execution opt-in filter;
- any tool you switched off by hand.

After an update that added tools, the Dashboard shows which ones and the state each arrived in, until an administrator dismisses the notice.

## Pinning an identity to IP ranges

A scoped identity can be pinned to the addresses your agents run from. Pass `allowed_ips` to `create-identity` as a list of IPv4 or IPv6 addresses and CIDR ranges (for example `["203.0.113.0/24", "2001:db8::/32"]`). A request acting as that identity from any other address is refused before any tool runs, with the same generic error as any other denial, and the refusal is recorded in the governance audit log as `identity/ip-refused` with the refused address. Overwriting the identity without `allowed_ips` clears the list. Identities without a list behave as before, the Connection screen shows each identity's list, and the admin screens are never restricted.

The client address is `REMOTE_ADDR`. Forwarded headers are ignored unless you name your reverse proxies, so a client cannot claim another address:

```php
add_filter('wpmcp_trusted_proxies', function () {
    return ['10.0.0.0/8']; // your load balancer or proxy addresses
});
```

When `REMOTE_ADDR` is a listed proxy, `X-Forwarded-For` is read right to left and the first hop that is not a listed proxy is the client. A malformed hop, or no usable address, is refused. The WP-CLI stdio transport has no network peer and is not affected.

## Code execution on multisite

On a multisite network the code execution tools (`run-php-snippet`, `activate-php-snippet`, `run-wp-cli` and `dispatch-cli-job`) need a network administrator (`manage_network_options`) on top of their opt-in, because that opt-in is shared by every site and a sub-site administrator holds `manage_options` on their own site. A network that wants its sub-site administrators to run code can grant it explicitly with the `wpmcp_allow_subsite_code_exec` filter, which receives the surface (`php` or `wp-cli`), the user id and the blog id and defaults to `false`. WP-CLI commands run against the caller's own site (`--url` is set to it). A queued CLI job is re-checked against the user who queued it when it runs.
