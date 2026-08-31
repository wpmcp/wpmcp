# Plan: in-admin AI chat driving the governed ability surface (issue #73)

Status: skeleton slice. Demand-gated feature (XL, PRO tier); this branch lays
the security-critical plumbing so the provider loop can be built on top of an
already governed path.

Lives here rather than in a `docs/wip/` directory of its own so there is one
place a reviewer looks for the design record, next to
`2026-07-12-wpmcp-mvp.md` and the specs.

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
  `tests/pro/Chat/ApprovalGateTest.php`. Nothing calls it yet: it is the
  mechanism the executor slice will use, not a live surface.
- `src/Pro/Chat/Key_Vault.php`: AES-256-GCM per-user key storage with tamper
  detection (`Key_Vault_Corrupted_Exception`). Tests in
  `tests/pro/Chat/KeyVaultTest.php`.
- `src/Pro/Chat/System_Prompt.php`: server-authored system prompt. Tests in
  `tests/pro/Chat/SystemPromptTest.php`.

## This slice

- `src/Pro/Chat/Conversation_Store.php`: private CPT `wpmcp_chat_convo`,
  owner-scoped read/append (no cross-admin reads), history bounded by message
  count, by serialized bytes measured on the slashed array that is actually
  written, and per entry so one oversized tool result cannot park above the
  ceiling. Writes are `wp_slash()`ed so backslashes in code snippets and tool
  arguments survive. Appends are idempotent on an optional client message id,
  and that id is also indexed as its own meta key so the lookup works across
  a user's conversations, which is the only form of it a retrying client can
  use. Ownership is defended in three independent places, because each covers
  a hole the others do not:
  - every primitive capability narrowed to `manage_options` and
    `can_export => false`, since `WP_Query` skips the private-post permission
    clause on the `'any'` status branch and the default `capability_type`
    `'post'` would hand every Editor `read_others_posts` over conversations;
  - `wpmcp_chat_convo` listed in `Content_Guard` and `List_Post_Types`
    internal types, so the generic content tools are not a second read path;
  - a purge on `delete_user` / `wpmu_delete_user` / `remove_user_from_blog`.
    `delete_with_user` alone does NOT cover the reassigning deletion: core
    reads that flag only when `$reassign === null`, and the reassign branch is
    a raw `UPDATE wp_posts SET post_author`. `delete_user` fires before that
    UPDATE, which is what makes the purge win. There is no `wp_delete_user`
    action; the earlier draft hooked one and therefore purged nothing.
- `src/Pro/Chat/Chat_Rest_Controller.php`: REST routes under `wpmcp/v1`:
  - `POST/GET/DELETE /chat/key`: Key_Vault management, with a length bound on
    the key and a 503 rather than a fatal on hosts without aes-256-gcm.
  - `POST /chat/message`: persists the user turn; the provider turn is a TODO
    and the route answers 202 `provider_turn_not_implemented`. Key presence is
    read through `Key_Vault::get_status()`, never `get_key()`, so a rotated
    `wp_salt('auth')` or a tampered ciphertext returns 409 with a
    machine-readable `key_status` instead of an uncaught exception. Length
    bounds are measured in characters with `mb_strlen`, the unit the schema's
    `maxLength` uses, so a multibyte message under the advertised limit is not
    rejected. A lost meta write answers 500 `store_failed`, not the 404 that
    means the conversation does not exist.
  - `GET /chat/conversations`, `GET|DELETE /chat/conversations/<id>`: the read
    and delete paths, owner-scoped with the same answer for "not yours" and
    "does not exist". Without them the store would be write-only and its
    per-user scoping would be asserted but never observable.
  - Every route: `manage_options` AND `Gate::is_pro()`, per request.
  - Dependencies are built lazily inside the callbacks. `Key_Vault`'s
    constructor throws when aes-256-gcm is missing, so eager construction from
    a hook would fatal a whole site over a feature it cannot use.
- `src/Pro/Chat/Chat_Page.php`: `wpmcp-chat` submenu. Renders the provider-key
  management the `/chat/key` routes already back, so the entry does something
  the day it appears; a screen whose only content is "not available yet" is a
  dead entry with extra steps. Under `src/Pro` so the WordPress.org build does
  not contain the screen at all: no locked screen, no upsell copy in that
  build. The conversation view arrives with the executor slice.
- `src/MCP/Transport_Guard.php`: `/wpmcp/v1/chat` joins the guarded prefixes,
  so the no-store/LiteSpeed/X-Accel-Buffering headers and the display_errors
  suppression cover a GET route that reports provider-key status.
- `scripts/flavors/wporg/strip.php`: exact-string removals for the chat
  imports, the runtime hooks, the submenu registration, and the
  `Transport_Guard` chat prefix constant, classifier and docblock, so the
  directory build neither names a class it does not ship nor describes a route
  it cannot register.
- Wiring in `src/Plugin.php`: the CPT registration and the user-deletion purge
  are UNGATED, on the same reasoning as the memory CPT ten lines above ("a
  safety rule must not stop applying because a license lapsed"). A lapsed
  license must not strand existing conversations as unregistered, reassignable
  posts. Only `rest_api_init` and the submenu resolve `Gate::is_pro()`.

## Deliberately NOT in this slice

`POST /chat/approve` was removed. It minted a valid `Approval_Gate` token for
any client-supplied ability name and arguments, with no model proposal behind
it, no conversation binding, no check that the ability exists or is
destructive or is permitted for the caller, and nothing anywhere calling
`Approval_Gate::validate_and_consume()`. That is not an approval gate, it is a
credential vending machine for a gate that does not exist yet. The endpoint
comes back with the executor, minting from a server-stored proposal.

## Remaining work

1. Governed executor: resolve a model tool_use through the Registrar's
   declared surface, run the identical permission/governance/rate-limit/
   snapshot chain as MCP calls, consume Approval_Gate tokens for destructive
   abilities. Failing tests first in `tests/pro/Chat`.
2. Provider loop in `send_message`: call the provider API with
   `System_Prompt::build()` plus lazily loaded tool groups; persist
   assistant/tool messages; stream to the client.
3. Re-introduce `POST /chat/approve` bound to a stored, server-recorded model
   proposal inside a conversation the caller owns.
4. Wire the chat routes into the existing governance rate-limit path (the
   route-level bounds here cap one request, not a request rate).
5. Multi-provider key support in Key_Vault (currently Anthropic-keyed meta).
6. Server-authored prompt test proving advertised inventory matches the
   active governed set.
7. SSRF-guarded web fetch tool exposure rules.
8. Chat client bundle (admin JS) and editor embeds. The key form on the chat
   screen is inline script; the executor slice replaces it with a registered
   bundle.
9. A per-user cap on the number of conversations. The idempotency lookup now
   prevents the retry path from minting orphans, but nothing bounds ordinary
   accumulation.
10. Adversarial security review: key storage, approval-gate bypass,
    prompt-injection-to-destructive-call paths. Two rounds done on this slice;
    the executor needs its own.

## Acceptance criteria status (issue #73)

- Chat tool calls execute through the identical governed path: NOT MET. No
  executor exists yet; nothing in this branch executes an ability.
- Destructive tools require server-verified approval per call: NOT MET. The
  mechanism (`Approval_Gate`) is built and tested; nothing consumes it, and
  the endpoint that minted tokens without a proposal has been removed rather
  than shipped.
- Provider keys encrypted at rest per user, tamper detection tested: MET.
  `Key_Vault` plus `tests/pro/Chat/KeyVaultTest.php`, and the REST surface now
  reports the corrupted and salt-rotated states instead of throwing.
- Advertised tool inventory provably matches the active governed set: NOT MET.
  Follows the executor.
