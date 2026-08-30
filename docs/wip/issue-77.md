# WIP plan: stdio transport and multi-site proxy (issue #77)

## Goal

Serve MCP clients that only speak stdio, without any HTTP setup, and give
multi-site operators one proxy process that routes to named WordPress sites.
No new permission surface: the stdio server runs under WP-CLI (site-owner
context, same trust model as the guarded CLI work from issue #44) and the
proxy reuses application password auth against the existing HTTP endpoint.

## What this branch contains

- `src/MCP/Stdio_Transport.php`: WP-CLI-invoked stdio transport
  (`wp mcp-stdio serve`). Newline-delimited JSON-RPC on stdin/stdout. All
  protocol dispatch lives in the pure `handle_request()` method so tests
  never spawn a process. Tools come from the live Abilities registry with
  the same `wpmcp/` filter as `Server::tool_names()`, so governance, tier
  and exposure gating apply unchanged.
- Wiring in `src/Plugin.php` next to `Mcp_Server::register()`; no-op
  outside WP-CLI.
- `bin/wpmcp-proxy.php`: zero-dependency stdio-to-HTTP proxy. Named sites
  from `WPMCP_SITES` (JSON) or `WPMCP_SITE_<NAME>_URL/_USER/_APP_PASSWORD`,
  selection via `WPMCP_SITE`, debug via `WPMCP_PROXY_DEBUG=1`. 401/403 map
  to explicit auth error messages. Pure functions are includable under a
  `WPMCP_PROXY_NO_RUN` guard for testing.
- `tests/free/Mcp/StdioTransportTest.php`: handshake, notification,
  unknown-method, tools/list filtering, unknown-tool call.
- `tests/free/Proxy/ProxySiteResolutionTest.php`: N-site resolution from
  both env shapes, selection rules, clear auth error text.

## Remaining work

- Full tools/call round-trip test with a registered fixture ability
  (mirroring CallToolConformanceTest), per the issue's acceptance criteria.
- Reuse `Handshake_Instructions` in the stdio initialize result so both
  transports hand clients identical guidance.
- Confirm tool-name mapping (`wpmcp/x` to MCP-safe name) matches the
  adapter's exact scheme used by the HTTP transport.
- Session header passthrough in the proxy if/when the HTTP transport
  requires MCP session ids.
- Proxy end-to-end test against a stubbed HTTP endpoint (php -S or a
  stream wrapper) covering forward() and the error envelope.
- README section documenting both entry points.
