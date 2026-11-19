---
name: Funnels: FunnelKit
description: Funnels, steps and checkout products with real prices; dry_run first, then snapshotted writes.
version: 1.0.0
tier: pro
tags: [integrations, funnels]
requires_any:
  - wpmcp/theme-read#list-funnelkit-funnels
---

# Sales funnels in FunnelKit

The FunnelKit operations live on `wpmcp/theme-read` and `wpmcp/theme-write`.
A funnel is an ordered list of steps (landing, optin, checkout, thank you),
each backed by a page. Checkout steps hold the products a buyer pays for, so
a checkout change is a price change.

## Read first

1. `list-funnelkit-funnels` to find the funnel.
2. `get-funnelkit-funnel` for its steps in order: type, page id, status,
   products, upsells, and views and conversions where recorded.
3. `get-funnelkit-checkout-products` for a checkout step: quantity,
   discount, and the unit and line price a buyer actually pays.

## Preview with dry_run

Most writes take `dry_run: true`, which returns what would change without
changing it. Use it for anything a buyer would see, show the user the
preview, then repeat the call without it.

## Worked example: discount the checkout product

User: "Give 20 percent off the course on the Spring funnel checkout."

1. `list-funnelkit-funnels` with `search: "Spring"`, then
   `get-funnelkit-funnel` for the checkout step id.
2. `get-funnelkit-checkout-products` for the current price.
3. `set-funnelkit-checkout-products` with the product, discount type
   percent_discount_sale and 20, as a dry run. Show the new line price.
4. The same call without `dry_run`, then read the checkout products again.

## Other writes

- `create-funnelkit-funnel` creates an empty funnel; `add-funnelkit-step`
  adds a step at the end; `reorder-funnelkit-steps` takes every step id once.
- `set-funnelkit-step-status` enables or disables one step;
  `publish-funnelkit-steps` publishes every step at once. A published funnel
  takes real orders: confirm first.
- `delete-funnelkit-funnel`, `delete-funnelkit-step` and
  `remove-funnelkit-checkout-products` need `confirm: true`.

## Undo

Every write is snapshotted; `wpmcp/rollback-operation` brings a deleted
funnel or step back at its own ids. Orders already placed through a step are
not affected by a rollback.
