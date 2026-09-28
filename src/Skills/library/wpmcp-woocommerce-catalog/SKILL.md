---
name: WooCommerce catalog and order work
description: Edit products through the WooCommerce tools rather than as raw posts, keep prices and stock consistent, and treat orders as financial records that are read far more often than they are written.
version: 1.0.0
tier: free
tags: [woocommerce, products, orders, ecommerce]
requires:
  - wpmcp/list-products
  - wpmcp/get-product
  - wpmcp/update-product
---

# Working on a WooCommerce store

A product is a post, but editing it as a post is wrong. Price, stock, tax
class, and visibility live in meta that WooCommerce keeps in sync with its own
lookup tables. Writing `_price` with a generic post-meta tool leaves the store
in a state where the product shows one price and sorts by another.

Use the product tools. Always.

## Catalog reads

- `wpmcp/list-products` returns safe summary rows (id, name, sku, price, stock
  status) with search, status, type, and category filters plus paging. Start
  here; do not page through the whole catalog to find one SKU.
- `wpmcp/get-product` for the full record of a single product.
- `wpmcp/list-product-categories` before you assign categories, so you use
  existing terms instead of creating near-duplicates ("T-Shirts" vs "Tshirts").

## Catalog writes

- `wpmcp/create-product` and `wpmcp/update-product` are the only correct way to
  change catalog data. Send only the fields you are changing.
- Price changes are customer-visible immediately. Confirm the intended number
  and currency with the user before writing, and read the product back after.
- Stock: decide with the user whether you are setting a stock quantity or a
  stock status. Setting a quantity on a product that does not manage stock does
  nothing visible, which reads as a broken tool.
- `wpmcp/delete-product` on a product that appears in past orders damages
  reporting. Prefer setting it to draft or out of stock and say why.

## Variations, coupons and tax rates

- A variable product's variations are listed with `wpmcp/list-variations`
  and changed with `wpmcp/update-variation`; `wpmcp/create-variation` needs
  the parent's variation attributes and one option for each (or "" for any).
- For many price or stock changes at once use `wpmcp/bulk-update-products`.
  Read its per-item results: some items can fail while the rest succeed, and
  the returned session_id undoes the whole batch with rollback-session.
- `wpmcp/create-coupon` saves a draft unless told otherwise. Run
  `wpmcp/validate-coupon` before telling a customer a code works.
- Tax rates change what every checkout charges. Confirm the rate, country
  and class with the user before `wpmcp/create-tax-rate` or
  `wpmcp/update-tax-rate`.

## Orders

Orders are financial records. Read freely, write narrowly.

- `wpmcp/list-orders` and `wpmcp/get-order` for lookups and support questions.
- `wpmcp/update-order-status` moves an order through its lifecycle. Status
  transitions fire emails and can trigger refunds or stock changes downstream,
  so state the effect before you make the call.
- `wpmcp/add-order-note` is the right tool for "record what happened". Prefer a
  note over mutating order data to leave a trail.
- Never edit order totals or line items through a generic database or meta
  tool. If a total is wrong, that is a refund or an adjustment, and it is a
  human decision.

## Bulk changes

For a price rise across a category: list the affected products first, show the
user the list and the resulting prices, get confirmation, then write one
product at a time so each write is individually reversible. A single
sweeping database update is faster and is the wrong trade on a live store.

## Reversibility

Product writes are snapshotted; see the `wpmcp-safe-writes` skill. Emails
already sent by an order status transition are not.
