# WooCommerce depth parity (issue #195)

Plan for closing the WooCommerce ability gap (we ship ~15, free-field
competitors ship 25 to 75). Phasing follows the issue. Phase 1 landed in
part via PR #203; the rest is tracked here so each later PR can tick boxes
instead of re-planning.

## Phase 1: variations and stock

On main:

- `src/Tools/WooCommerce/Variation_View.php`: shared summary shape for
  `WC_Product_Variation`, mirroring `Product_View` (raw price strings, the
  defining attributes map, stock fields).
- `src/Tools/WooCommerce/List_Variations.php`: `wpmcp/list-variations`,
  read-only paged listing of one variable product's variations.
- `src/Tools/WooCommerce/Update_Variation.php`: `wpmcp/update-variation`,
  prices/sku/status/stock writes through `Safe_Mutation`. Input rules:
  status is limited to publish/private (deleting is a separate gated tool),
  a null stock_quantity is rejected rather than coerced to 0, and
  stock_status is refused while stock is managed because WooCommerce
  re-derives it from the quantity on save.
- `src/Tools/WooCommerce/List_Low_Stock_Products.php`:
  `wpmcp/list-low-stock-products`, threshold defaults to the store's
  `woocommerce_notify_low_stock_amount`. The match is a postmeta query
  (`_manage_stock = yes AND _stock <= threshold`, OR
  `_stock_status = outofstock`) so `total` and `has_more` count real
  matches, variable parents managing stock at parent level appear as one
  row, and unmanaged products still surface once marked out of stock.
- Registration in `Plugin::register_woocommerce_abilities()`; all free
  tier (the wp.org wedge depends on WooCommerce staying free).
- Tests: `tests/free/WooCommerce/ListVariationsTest.php`,
  `UpdateVariationTest.php`, `ListLowStockProductsTest.php`, plus the
  registration and capability enumerations.

Snapshot note: a variation IS a post (`product_variation`, parent = the
variable product), so it rides the existing `post` snapshot type, which
captures the full row including `post_parent` plus all postmeta. No new
snapshot type is needed for variation updates, contrary to the issue's
first guess; the same holds for coupons (`shop_coupon` posts). Tax rates,
shipping zones and webhooks are custom tables and DO need new snapshot
types.

Rollback note: the `post` restore writes wp_posts/wp_postmeta directly,
which bypasses WooCommerce's CRUD layer. `Rollback_Service` therefore
finishes a product or variation restore with a WooCommerce refresh
(product transients, the `wc_product_meta_lookup` row, and for a variation
`WC_Product_Variable::sync()` on the parent) so the parent's price range
and the lookup-driven sorting follow the restored values. This also fixes
the same gap for `update-product` rollbacks.

Remaining for phase 1:

- [ ] `create-variation` and `delete-variation` (delete behind an opt-in
      filter plus `confirm`, like `delete-product`)
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

- tools/list payload budget: this cluster can blow the ~165KB cap on its
  own; ship compact-mode-first and consider making compact mode the
  default discovery path.
