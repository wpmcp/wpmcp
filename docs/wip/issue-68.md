# WIP: Deep WooCommerce operations catalog (issue #68)

Status: first slice. Read-only catalog plus dispatcher, PRO tier.

## Shape

Issue #68 asks for a catalog architecture, not more hand-written tool
classes: named ops template internal `wc/v3` REST routes and dispatch
in-process (rest_do_request, no HTTP loopback) as the authenticated user, so
the store API's own permission model is the gate and the surface stays
current with the installed WooCommerce automatically.

This slice ships:

- `src/Tools/WooCommerce/Catalog/Op_Catalog.php`: declarative op map
  (op name, method, `/wc/v3/...` route template, domain, summary), route
  templating with `{param}` path substitution, remaining params passed as
  query params. Seeded with 21 read ops across products, orders, refunds,
  coupons, customers, shipping, taxes, webhooks and settings.
- `src/Tools/WooCommerce/Catalog/Woo_Read.php`: read dispatcher. Extends
  `Call_Rest` to reuse its in-process dispatch; refuses any catalog row
  whose method is not GET.
- `src/Tools/WooCommerce/Catalog/Woo_Ops.php`: catalog discovery tool so
  tools/list stays one entry per dispatcher, not one per endpoint.
- Both abilities registered PRO tier in `register_woocommerce_abilities`
  (Registrar already skips pro abilities unless `Pro\Gate::is_pro()`),
  capability `manage_woocommerce`. The existing 11 free tools are untouched
  and remain the simple free surface.

## Relationship to PR #203 / issue #195

PR #203 builds dedicated free-tier tools for variations and stock, with
hand-shaped summary views and Safe_Mutation snapshots. This work is the
complementary PRO catalog layer over the raw store REST surface. Variation
ops are deliberately NOT seeded in the catalog yet, to avoid overlapping
that PR; they land after it merges so both surfaces can share one shape
description.

## Remaining work (tracked as TODO(#68) markers in Op_Catalog / Woo_Read)

1. woo-write dispatcher: create/update ops, per-op snapshot strategy where
   a snapshot type exists (products, orders and coupons are posts), honest
   recoverable:false where none does.
2. Destructive ops (delete, refund creation) behind per-op `confirm` plus
   an opt-in filter, mirroring `Call_Rest`'s write posture.
3. Batch ops (`/wc/v3/.../batch`) with per-item outcomes.
4. Variation ops (post PR #203).
5. Tests in tests/pro, failing-first per the issue's TDD notes:
   - route-catalog integrity: every op resolves to a registered wc/v3 route
     (like the existing REST passthrough tests);
   - per-domain op tests covering all ten domains (acceptance criterion 4);
   - confirm-gate tests once write/destructive ops exist.
6. Adversarial security review before any write op ships (destructive store
   operations, internal-dispatch privilege boundaries).
