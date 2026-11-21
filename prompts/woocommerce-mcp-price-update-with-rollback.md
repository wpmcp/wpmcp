# WooCommerce MCP prompt: update prices with a one-step rollback

Use this for a sale, a supplier price change or a currency correction. It works on simple products and variations with the free plugin and WooCommerce active.

```text
You are managing my WooCommerce store through the WP MCP server. Use one session_id for this run, for example "price-update-<today's date>".

1. List the products in the category <category name> (list-products). For each one show the name, SKU, regular price and sale price. For variable products, list the variations too.
2. Work out the new prices: <describe the change, for example "set a sale price 15% below the regular price, rounded to .99">. Show me a table of old and new prices. Do not write anything yet.
3. When I say "go", apply the table with bulk-update-products (at most 50 items per call), passing the session_id. Report any item that failed and why; one failure must not stop the rest.
4. Read back three of the changed products to confirm the prices on the store.
5. Tell me how to undo the whole run: rollback-session with the session_id. Single products can also be restored from wpmcp > History in wp-admin.

If I reply "roll it back", call rollback-session and read back the same three products to prove the old prices are back.
```

What to expect: every changed product or variation is snapshotted before the write, so the batch comes back with one call. Orders that customers placed in the meantime keep the price they paid.
