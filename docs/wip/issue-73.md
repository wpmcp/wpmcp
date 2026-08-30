# WIP plan: in-admin AI chat driving the governed ability surface (issue #73)

Status: skeleton slice. Demand-gated feature (XL, PRO tier); this branch lays
the security-critical plumbing so the provider loop can be built on top of an
already governed path.

## Design constraints (from the issue)

- The chat is just another MCP client with a scoped identity. Tool calls
  execute through the identical permission/governance/rate-limit/snapshot
  path as external MCP calls. No second, weaker permission path.
- Destructive tools require an explicit server-verified approval per call.
- Per-user provider keys encrypted at rest (AES-GCM) with tamper detection.
- Lazy tool-group loading to bound per-turn token cost; advertised tool
  inventory must provably match the active governed set.
- PRO via `Pro\Gate`, fail closed.

## Already in tree (previous slice)

- `src/Pro/Chat/Approval_Gate.php`: single-use, args-bound, TTL approval
  tokens (HMAC over user + ability + normalized args). Tests in
  `tests/pro/Chat/ApprovalGateTest.php`.
- `src/Pro/Chat/Key_Vault.php`: AES-256-GCM per-user key storage with tamper
  detection (`Key_Vault_Corrupted_Exception`). Tests in
  `tests/pro/Chat/KeyVaultTest.php`.
- `src/Pro/Chat/System_Prompt.php`: server-authored system prompt. Tests in
  `tests/pro/Chat/SystemPromptTest.php`.

## This slice

- `src/Pro/Chat/Conversation_Store.php`: private CPT `wpmcp_chat_convo`,
  owner-scoped read/append (no cross-admin reads), bounded message history.
- `src/Pro/Chat/Chat_Rest_Controller.php`: REST routes under `wpmcp/v1`:
  - `POST/GET/DELETE /chat/key`: Key_Vault management.
  - `POST /chat/message`: persists the user turn; provider turn is a TODO
    and returns 202 `provider_turn_not_implemented`.
  - `POST /chat/approve`: mints an Approval_Gate token for one destructive
    call with exactly the approved args.
  - Every route: `manage_options` AND `Gate::is_pro()`, per request.
- `src/Admin/Chat_Page.php`: `wpmcp-chat` submenu mount point, upsell on
  free builds.
- Wiring in `src/Plugin.php` (init CPT, rest_api_init routes, admin_menu).

## Remaining work

1. Governed executor: resolve a model tool_use through the Registrar's
   declared surface, run the identical permission/governance/rate-limit/
   snapshot chain as MCP calls, consume Approval_Gate tokens for
   destructive abilities. Failing tests first in `tests/pro/Chat`.
2. Provider loop in `send_message`: call provider API with
   `System_Prompt::build()` plus lazily loaded tool groups; persist
   assistant/tool messages; stream to the client.
3. Multi-provider key support in Key_Vault (currently Anthropic-keyed meta).
4. Server-authored prompt test proving advertised inventory matches the
   active governed set.
5. SSRF-guarded web fetch tool exposure rules.
6. Chat client bundle (admin JS) and editor embeds.
7. Adversarial security review: key storage, approval-gate bypass,
   prompt-injection-to-destructive-call paths.
