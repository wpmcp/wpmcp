# WIP plan: stdio transport and multi-site proxy (issue #77)

## Goal

Serve MCP clients that only speak stdio, without any HTTP setup, and give
multi-site operators one proxy process that routes to named WordPress sites.
No new permission surface: the stdio server runs under WP-CLI with an
explicit `--user`, so every call goes through the same ability permission
chain as the HTTP route, and the proxy reuses application password auth
against the existing HTTP endpoint.

## What this branch contains

- `src/MCP/Stdio_Transport.php`: WP-CLI-invoked stdio transport
  (`wp mcp-stdio serve --user=admin`). Newline-delimited JSON-RPC on
  stdin/stdout. All protocol dispatch lives in the pure `handle_request()`
  method so tests never spawn a process.
  - Requires a user context: WP-CLI runs as user 0 by default and every
    ability's `permission_callback` starts with `current_user_can()`, so
    without `--user` every tool call would be denied. `serve()` refuses to
    start and says so.
  - Parity with the HTTP route is explicit, not assumed. The adapter's
    pipeline does not run here, so the transport calls the same pieces
    directly: protocol version through the adapter's `McpVersionNegotiator`
    (no hardcoded version), tool names through the adapter's
    `McpNameSanitizer`, compact-mode trimming through `Tool_Exposure`,
    handshake text through `Handshake_Instructions`, and results through
    `Structured_Result::normalize()` with a `structuredContent` field.
  - Framing is protected the way the HTTP route is: `Transport_Guard::
    suppress_error_display()` before the first read (issue #133 failure
    mode 2) and `fwrite(STDOUT, ...)` for protocol output.
  - The loop is long-lived, so `Operation_Context` and the object cache are
    reset between messages; otherwise a revoked ability or a flipped
    exposure mode would stay frozen for the life of the process, and the
    rate-limit counter would read one stale value forever.
- Wiring in `src/Plugin.php` next to `Mcp_Server::register()`; no-op
  outside WP-CLI.
- `bin/wpmcp-proxy.php`: zero-dependency stdio-to-HTTP proxy. Named sites
  from `WPMCP_SITES` (JSON) or `WPMCP_SITE_<NAME>_URL/_USER/_APP_PASSWORD`,
  selection via `WPMCP_SITE`, debug via `WPMCP_PROXY_DEBUG=1`. 401/403 map
  to explicit auth error messages. Pure functions are includable under a
  `WPMCP_PROXY_NO_RUN` guard for testing.
  - Sessions: the adapter issues an `Mcp-Session-Id` on the initialize
    response and rejects every later method without it, so the proxy
    captures that header and replays it (plus the negotiated
    `MCP-Protocol-Version`) on every subsequent POST.
  - Credential handling: redirects are not followed (the http wrapper would
    resend the `Authorization` header to the redirect target), and a plain
    `http://` site is refused unless `WPMCP_ALLOW_INSECURE=1`, since
    WordPress refuses application-password auth over non-SSL anyway.
  - Framing: response bodies are re-encoded onto one line, and a
    non-JSON body is reported rather than forwarded. Failures for a
    notification (no `id`) go to stderr only, never as an `id: null`
    response.
- `tests/free/MCP/StdioTransportTest.php`: handshake, version negotiation
  (echo and fallback), instructions, notification handling, unknown method,
  tools/list contents, compact-mode exposure, unknown-tool call, the
  tools/call round trip, structuredContent shape, a permission denial, and
  the user-context requirement.
- `tests/free/Proxy/ProxySiteResolutionTest.php`: N-site resolution from
  both env shapes, selection rules, clear auth error text.
- `tests/free/Proxy/ProxyProtocolTest.php`: end-to-end against a stubbed
  MCP endpoint (a `php -S` router that enforces the session-header rule),
  plus the framing, credential-guard and config-error cases.

## Acceptance criteria

- [x] stdio server passes an MCP handshake + tools/list + tool call
      round-trip in a test
      (`StdioTransportTest::test_tools_call_round_trip_returns_the_ability_result`).
- [x] Proxy resolves N sites from env config and routes by site name; auth
      failures produce clear errors (`ProxySiteResolutionTest`, plus the
      malformed-config and missing-credential cases in `ProxyProtocolTest`).
- [x] Proxy has its own test files and no runtime dependencies (plain PHP
      streams; no Composer, no WordPress load).

## Distribution note

`bin/wpmcp-proxy.php` is a REPO-ONLY tool. None of the three release
scripts (`scripts/build-release.sh`, `scripts/build-wporg-release.sh`,
`scripts/build-woo-release.sh`) stage `bin/`; they ship `wpmcp.php`,
`LICENSE`, the composer files, `src/` and `languages/`. Operators run the
proxy from a clone. Shipping it inside the plugin zip would mean putting an
executable credential-handling script into every WordPress install that
does not need one, which is the wrong trade; if that changes, it needs an
entry in all three staging steps, not just one.

## Remaining work

- README section documenting both entry points (`wp mcp-stdio serve
  --user=admin` and the proxy's env config).
- The stdio transport implements the tools methods only. `resources/*` and
  `prompts/*` are unimplemented on both transports today; if the adapter's
  surface grows, delegating to its `RequestRouter` (or to its own
  `wp mcp-adapter serve` bridge) becomes the cheaper option than extending
  this dispatch.
