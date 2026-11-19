---
name: Tables: TablePress
description: Read, edit cell by cell, import, export and place tables through TablePress, every change snapshotted.
version: 1.0.0
tier: pro
tags: [integrations, tables]
requires_any:
  - wpmcp/plugin-data-read#tablepress-list-tables
---

# Tables in TablePress

Reads go to `wpmcp/plugin-data-read`, writes to `wpmcp/plugin-data-write`.
A TablePress table is stored by TablePress as one JSON document; never edit
it through post meta or the database tools. Rows and columns are 0-based
everywhere.

## Read first

1. `tablepress-list-tables` to find the table (search matches id or name).
2. `tablepress-get-table` for its options and rows from `offset` (up to 500
   rows per call). Use the row and column numbers from this read; do not
   count rows in rendered HTML.
3. `tablepress-find-usage` before changing a table's id or deleting it: it
   lists the posts that place it.

## Worked example: update prices in a table

User: "In the Pricing table, change the Pro plan's yearly price to 199."

1. `tablepress-list-tables` with `search: "Pricing"`, take the id.
2. `tablepress-get-table` and find the Pro row and the Yearly column.
3. `tablepress-set-cells` with that one cell `{row, column, value}`. It
   refuses a row or column out of range instead of growing the table.
4. `tablepress-get-table` again for that row, or `tablepress-render-table`
   to see the HTML visitors get.

## Other writes

- `tablepress-insert-rows` and `tablepress-insert-columns` take full rows or
  columns (matching the table's size) or a count of empty ones;
  `tablepress-delete-rows` and `tablepress-delete-columns` always leave at
  least one.
- `tablepress-create-table` from data, or `tablepress-import-table` from
  CSV, JSON or HTML (a new table, or replace or append to one). Show the user
  the first rows of the result.
- `tablepress-update-table` changes name, description and options.
- `tablepress-place-table` inserts the table into a post as a block (default)
  or a shortcode.
- `tablepress-change-table-id` does not rewrite the posts that use the old
  id; the result lists them, so fix or report each one.
- `tablepress-delete-table` needs `confirm: true`, and every placement then
  renders nothing.
- `tablepress-export-table` returns CSV, JSON or HTML for the user to keep.

## Undo

Edits are undone with `wpmcp/rollback-operation`; created, copied,
imported and deleted tables with `wpmcp/rollback-session` (a deleted table
comes back at the same id).
