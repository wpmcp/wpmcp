---
name: Block editor pages, one block at a time
description: Parse a page into blocks and change single blocks by path with a hash check; the rest stay byte-identical.
version: 1.0.0
tier: free
tags: [blocks, editing]
requires:
  - wpmcp/parse-blocks
  - wpmcp/add-block
  - wpmcp/update-block
---

# Editing block editor pages

A block editor page is block markup in post_content. Rewriting the whole
content to change one paragraph loses attributes you did not model and makes
the change impossible to review. Edit single blocks by path instead.

## Orient first

1. `wpmcp/parse-blocks` with the post id. It returns the block tree
   (blockName, attrs, innerBlocks, a short innerHTML) and the content_hash
   of what you read.
2. A path is the list of zero-based indexes from the top of that tree down
   through innerBlocks: `[2, 0]` is the first child of the third top-level
   block.
3. Before you invent attributes, read the block type with
   `wpmcp/get-block-type` (its attribute schema and supports), or find one
   with `wpmcp/list-block-types`.

## Change one block

Every write takes `expected_hash` (the content_hash from your last
parse-blocks) and is refused if the page changed since. Read again on a
refusal; do not retry blindly.

- `wpmcp/update-block`: replace a block's attrs, and the inner HTML of a
  leaf block. Change a container's children through their own paths.
- `wpmcp/add-block`: insert one block's markup at a path (the last index may
  equal the sibling count to append).
- `wpmcp/move-block` within the same parent; to move across parents, remove
  and add.
- `wpmcp/remove-block` and `wpmcp/duplicate-block`.
- `wpmcp/insert-pattern` inserts a registered pattern
  (`wpmcp/list-patterns`) at a path.

`wpmcp/update-blocks` replaces the whole content. Keep it for a page you are
building from scratch, for example after `wpmcp/convert-html-to-blocks` or
`wpmcp/serialize-blocks`.

## Worked example: change a button

User: "Change the Sign up button on the Pricing page to Start free trial."

1. `wpmcp/parse-blocks` on the page; find the core/button inside its
   core/buttons parent and note the path and the hash.
2. `wpmcp/update-block` at that path with the new inner HTML and the hash.
3. `wpmcp/parse-blocks` again to confirm only that block changed, then
   `wpmcp/get-rendered-html` if the user wants to see the result.

## Undo

Every block write is snapshotted; `wpmcp/rollback-operation` restores the
page content exactly. For a published page with an editorial process, do the
change on a draft copy (`wpmcp/duplicate-post`) and share
`wpmcp/get-preview-link` instead.
