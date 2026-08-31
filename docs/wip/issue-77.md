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
  - Errors never cost the session. `serve()` catches `Throwable` around
    dispatch and answers `-32603` on the pending id, and `call_tool()`
    catches `Throwable` around `execute()` and returns an `isError` result,
    which is the parity the adapter's `ToolsHandler` gives the HTTP route
    (`WP_Ability::execute()` does not catch `Throwable` on the WordPress
    versions this plugin targets, and `Registrar::throttled()` re-throws on
    purpose).
  - The loop is long-lived, so per-message state is reset, but the reset is
    narrower than "flush everything": `wp_cache_flush()` on a site with a
    persistent object-cache drop-in would wipe the whole shared cache once
    per message AND delete the transient the rate limiter counts in, which
    disabled per-client rate limiting on this transport entirely. The reset
    now flushes the object cache only when it is process-local, deletes the
    narrow `alloptions`/`notoptions` keys otherwise, and clears
    `Memory_Store::$rules_cache`, a plain static that no cache layer can
    reach and that backs a `severity=block` guardrail.
  - What the reset does and does not buy, stated precisely: abilities are
    registered once per process and `Registrar::register()` skips
    governance-disabled ones at registration time, so the ADVERTISED tool
    list is fixed for the process lifetime (hence `listChanged: false`).
    What re-evaluates per message is the call path: compact-mode exposure,
    the Governance and project-memory gates, capability checks and the
    rate-limit budget. A mid-process revocation still denies at execute.
  - `serverInfo.name` is `Server::SERVER_NAME` ('wpmcp'), the same string
    the adapter is handed in `Server::create_server()`, not the server id.
    Clients key config and display off that field.
  - JSON-RPC framing details: a message is a notification when it has no
    `id`, full stop (a `notifications/*` method WITH an id still gets a
    response, or a synchronous client blocks forever); `ping` answers a JSON
    object, not `[]`; and an encode failure in `emit()` writes a `-32603`
    envelope carrying the same id rather than a bare newline.
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
    response. Nothing at all is written for a notification: the adapter
    answers one with HTTP 202 and a body of literally `null`, which is
    valid JSON, so the loop suppresses it explicitly.
  - Protocol errors reach the client. The adapter carries JSON-RPC errors
    on 4xx statuses (`METHOD_NOT_FOUND`, `TOOL_NOT_FOUND` and
    `SESSION_NOT_FOUND` all map to 404), so a body that decodes to a
    JSON-RPC envelope is forwarded verbatim regardless of status, and only
    a non-JSON-RPC body becomes a synthetic `-32000` transport diagnosis.
    Before this, a client's routine post-handshake `resources/list` probe
    surfaced as "failed with HTTP 404" and a capability denial surfaced as
    an application-password problem.
  - The read loop is `pump($in, $out, $site, $env)`, extracted from
    `main()` so tests drive it over in-memory streams. Both framing bugs
    above lived only in there and were invisible to a suite that tested
    `forward()` in isolation.
- `tests/free/MCP/StdioTransportTest.php`: handshake, version negotiation
  (echo and fallback), instructions, notification handling, unknown method,
  tools/list contents, compact-mode exposure, unknown-tool call, the
  tools/call round trip, structuredContent shape, a permission denial, and
  the user-context requirement.
- `tests/free/Proxy/ProxySiteResolutionTest.php`: N-site resolution from
  both env shapes, selection rules, clear auth error text.
- `tests/free/Proxy/ProxyProtocolTest.php`: end-to-end against a stubbed
  MCP endpoint (a `php -S` router that enforces the session-header rule,
  answers a notification the way the adapter does, and carries a
  `METHOD_NOT_FOUND` on a 404), driving `pump()` over real streams, plus
  the framing, credential-guard and config-error cases. The stub binds port
  0 and reads back the assigned port, and the readiness probe asserts the
  stub's own session header, so a foreign listener causes a skip rather
  than a false pass.
- Both proxy test classes are plain `PHPUnit\Framework\TestCase`, which is
  what the proxy's "no WordPress load" claim is worth only if it is true.
- `phpcs.xml.dist` now lints `bin/` to the same standard as `src/`, and
  `Forbidden_Functions_Rule` honours a justified `phpcs:ignore` on
  `WordPress.WP.AlternativeFunctions` the way PHPCS and Plugin Check do
  (the bare-annotation form still suppresses nothing). The stdio
  transport's four `fopen`/`fclose`/`fwrite` sites are annotated with
  reasons: STDIN and STDOUT are the protocol stream, and `WP_Filesystem`
  has no equivalent.

## Acceptance criteria

- [x] stdio server passes an MCP handshake + tools/list + tool call
      round-trip in a test
      (`StdioTransportTest::test_tools_call_round_trip_returns_the_ability_result`).
- [x] Proxy resolves N sites from env config and routes by site name; auth
      failures produce clear errors (`ProxySiteResolutionTest`, plus the
      malformed-config and missing-credential cases in `ProxyProtocolTest`).
- [x] Proxy has its own test files and no runtime dependencies (plain PHP
      streams; no Composer, no WordPress load at runtime, and the test
      classes are plain PHPUnit so the claim is actually demonstrated).

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
  `prompts/*` are unimplemented on both transports today and answer
  `-32601`; if the adapter's surface grows, delegating to its
  `RequestRouter` (or to its own `wp mcp-adapter serve` bridge) becomes the
  cheaper option than extending this dispatch.
- Session expiry has no recovery path in the proxy. The error now reaches
  the client intact (`SESSION_NOT_FOUND` on a 404), so a client that
  re-initializes recovers, but the proxy does not re-handshake by itself.
