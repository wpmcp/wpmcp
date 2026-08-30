# WIP: WooCommerce depth parity (issue #195)

Plan for closing the WooCommerce ability gap (we ship ~12, free-field
competitors ship 25 to 75). Phasing follows the issue.

## Phase 1: variations and stock (this branch starts it)

Landed in this WIP:

- `src/Tools/WooCommerce/Variation_View.php`: shared summary shape for
  `WC_Product_Variation`, mirroring `Product_View` (raw price strings, the
  defining attributes map, stock fields).
- `src/Tools/WooCommerce/List_Variations.php`: `wpmcp/list-variations`,
  read-only paging listing of one variable product's variations.
- `src/Tools/WooCommerce/Update_Variation.php`: `wpmcp/update-variation`,
  prices/sku/status/stock writes through `Safe_Mutation`. Snapshot note: a
  variation IS a post (`product_variation`, parent = the variable product),
  so it rides the existing `post` snapshot type, which captures the full row
  including `post_parent` plus all postmeta. No new snapshot type needed for
  variation updates, contrary to the issue's first guess; the same holds for
  coupons (`shop_coupon` posts). Tax rates, shipping zones and webhooks are
  custom tables and DO need new snapshot types.
- `src/Tools/WooCommerce/List_Low_Stock_Products.php`:
  `wpmcp/list-low-stock-products`, threshold defaults to the store's
  `woocommerce_notify_low_stock_amount`.
- Registration wired in `Plugin::register_woocommerce_abilities()`; all
  free tier (the wp.org wedge depends on WooCommerce staying free).

Remaining for phase 1:

- [ ] `create-variation` and `delete-variation` (delete behind an opt-in
      filter plus `confirm`, like `delete-product`)
- [ ] Rollback test asserting a restored variation is still attached to its
      parent product (post_parent survives the `post` snapshot round trip)
- [ ] Bulk variation update with per-item outcome reporting
- [ ] Product attribute / attribute-term tools (needed to create variable
      products end to end)

## Phase 2: coupons and customers

- Coupons are `shop_coupon` posts: CRUD rides the `post` snapshot type.
  Add validate-coupon (read) and usage stats.
- Customers: CRUD, purchase history, lifetime value. Customer rows are
  users + usermeta; scope whether the existing user snapshot covers them.

## Phase 3: refunds and order operations

- No plain snapshot for refunds: money that left cannot be restored by a
  row write. Model (study fluent-cart, do not copy): `dry_run` first, then
  a fingerprint-bound short-TTL `confirm_token` replayed with an
  idempotency key; gateway-touching actions add a live-gateway check.
- Order address/contact updates, resend order emails, batch updates.

## Phase 4: store config

- Shipping zones/methods, tax classes/rates, payment gateway read/update,
  webhooks, settings, system status. New snapshot types required for the
  non-post objects (same pattern as `redirect` and `term`).

## Phase 5: reports breadth

- Orders, products, customers, coupon usage, top sellers (we have sales
  only).

## Cross-cutting

- tools/list payload budget: this cluster can blow the ~161KB cap on its
  own; ship compact-mode-first and consider making compact mode the
  default discovery path.
