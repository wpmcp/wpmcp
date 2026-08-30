# WIP: Deep WooCommerce operations catalog (issue #68)

Status: first slice. Read-only catalog plus dispatcher, PRO tier, with the
route-catalog integrity test the issue asks for as acceptance criterion 1.

## Shape

Issue #68 asks for a catalog architecture, not more hand-written tool
classes: named ops template internal `wc/v3` REST routes and dispatch
in-process (rest_do_request, no HTTP loopback) as the authenticated user, so
the store API's own permission model is the gate and the surface stays
current with the installed WooCommerce automatically.

This slice ships:

- `src/Tools/WooCommerce/Catalog/Op_Catalog.php`: declarative op map (op
  name, method, `/wc/v3/...` route template, domain, capability, summary),
  route templating with `{param}` path substitution, remaining params passed
  as query params. Seeded with 21 read ops across products, orders, refunds,
  coupons, customers, shipping, taxes, webhooks and settings. Op names are
  domain-namespaced (`products.list`, `orders.get`) so they cannot be
  confused with the free tools' ability names, which return a smaller shape
  under a different tier.
- `src/Tools/WooCommerce/Catalog/Wc_Rest_Dispatch.php`: the in-process
  dispatch, GET-only by construction.
- `src/Tools/WooCommerce/Catalog/Woo_Read.php`: read dispatcher. Composes
  Wc_Rest_Dispatch (no inheritance) and applies three gates before dispatch:
  availability, per-op capability, and the read-only invariant.
- `src/Tools/WooCommerce/Catalog/Woo_Ops.php`: catalog discovery tool so
  tools/list stays one entry per dispatcher, not one per endpoint. Answers
  with `available: false` when WooCommerce is inactive.
- Both abilities registered PRO tier in `register_woocommerce_abilities`
  (Registrar already skips pro abilities unless `Pro\Gate::is_pro()`). The
  existing 11 free tools are untouched and remain the simple free surface.
- `tests/pro/WooCommerce/WooCatalogTest.php`: 21 tests.

## Why not Integration_Dispatcher

Issue #68 names the integration dispatcher framework, and
`src/Integrations/Integration_Dispatcher.php` is the right long-term home:
it already carries per-op governance, per-op capability overrides, the
`wpmcp_integration_op_enabled` opt-in, pre-dispatch schema validation, the
destructive `confirm:true` gate and Safe_Mutation snapshot routing.

It cannot be used as-is here. `scripts/build-woo-release.sh` prunes BOTH
`src/Integrations` and `src/Tools/Rest` from the wpmcp-for-woocommerce
vertical zip, while `Plugin::FLAVOR_GROUPS['woocommerce']` still runs
`register_woocommerce_abilities()`. Any class that build instantiates
therefore may not inherit from either tree; the first cut of this PR did
(`Woo_Read extends Tools\Rest\Call_Rest`) and fatally broke the vertical
build with `Class "WPMCP\Tools\Rest\Call_Rest" not found` on every request.

Two things came out of that:

1. The catalog composes a self-contained `Wc_Rest_Dispatch` and reimplements
   the gates it needs (availability, per-op capability) locally, matching the
   error shape Integration_Dispatcher returns.
2. `scripts/lib/check-dangling-inheritance.php` now fails the woo build on
   any `extends` / `implements` edge into a pruned tree, so this class of
   defect cannot ship again. Lazy `use` imports stay quiet, since the flavor
   gate makes those harmless.

Moving the whole catalog onto Integration_Dispatcher stays desirable and is
tracked below; it requires deciding whether the woo vertical build keeps
`src/Integrations` first.

## Relationship to PR #203 / issue #195

PR #203 builds dedicated free-tier tools for variations and stock, with
hand-shaped summary views and Safe_Mutation snapshots. This work is the
complementary PRO catalog layer over the raw store REST surface. Variation
ops are deliberately NOT seeded in the catalog yet, to avoid overlapping
that PR; they land after it merges so both surfaces can share one shape
description.

## Acceptance criteria (issue #68)

1. **Op catalog integrity test** - MET.
   `test_every_catalog_row_resolves_to_a_registered_wc_v3_route` matches
   every row structurally against `rest_get_server()->get_routes()`, and
   `test_resolve_route_leaves_no_unsubstituted_placeholder` proves no `{...}`
   survives resolution.
2. **Destructive ops require confirm; batch ops supported** - UNMET. This
   slice is read-only. The confirm gate is deliberately reserved for the
   write dispatcher's own dispatch rather than a caller-side check, so a
   sibling class cannot reach a mutating route around it.
3. **Internal dispatch as the authenticated identity, store permission
   checks still apply** - MET.
   `test_dispatch_is_in_process_with_no_http_loopback` asserts no outbound
   HTTP fires, and `test_woo_read_denies_an_op_whose_capability_the_user_lacks`
   covers the wpmcp-layer gate on top of the endpoint's own callback.
4. **Coverage across ten domains** - PARTIAL. Nine of ten are seeded and
   pinned by `test_the_catalog_covers_nine_of_the_ten_domains_the_issue_names`,
   which also asserts variations is still absent so the gap cannot be
   forgotten.

## Remaining work (tracked as TODO(#68) markers in Op_Catalog / Woo_Read)

1. woo-write dispatcher: create/update ops, per-op snapshot strategy where
   a snapshot type exists (products, orders and coupons are posts), honest
   recoverable:false where none does. The write gate lives in the write
   dispatcher, not in a caller.
2. Destructive ops (delete, refund creation) behind per-op `confirm` plus
   an opt-in filter (acceptance criterion 2).
3. Batch ops (`/wc/v3/.../batch`) with per-item outcomes (criterion 2).
4. Variation ops, post PR #203 (closes the criterion 4 gap).
5. Rebase the catalog onto `Integration_Dispatcher` once the woo vertical
   build's prune list is settled, replacing the locally reimplemented gates
   and folding `woo-ops` into its reserved `list-operations` op.
6. Per-op `input_schema` with pre-dispatch
   `rest_validate_value_from_schema()`, so `params` stops being an untyped
   object. Path params are scalar-checked today; that is a floor, not the
   validation the framework offers.
7. Adversarial security review before any write op ships (destructive store
   operations, internal-dispatch privilege boundaries).

## Known posture divergences, stated plainly

- **Raw bodies.** woo-read returns the endpoint's body verbatim, unlike the
  free WooCommerce tools' curated summary rows. Order and customer ops
  therefore carry personal data. List ops default to `per_page=20` and are
  capped at 50 so a single call cannot flood a model context, and the
  ability description says so, but the shape is still the raw wc/v3 record.
- **Overlap with call-rest.** `wpmcp/call-rest` is free and already
  dispatches any GET route, including these. What the PRO catalog adds today
  is discovery, route templating, the per-op capability floor and the paging
  cap; the depth that justifies the tier is the write, confirm and batch work
  still outstanding above.
