---
name: Builder pages: Bricks, Breakdance, Divi, Oxygen and others
description: Detect the builder, read its tree, and change one element per hash-checked, snapshotted write.
version: 1.0.0
tier: pro
tags: [integrations, builders]
requires_any:
  - wpmcp/get-builder-content
---

# Editing builder pages

Every builder stores a page in its own format (JSON trees, shortcodes, HTML).
The rule is the same as for Elementor: never regenerate a whole page. Read
the tree, change one element by its path, and leave everything you did not
model untouched. For Elementor use the `wpmcp-elementor-editing` skill; for
the block editor, `wpmcp-block-editing`.

## Orient first

1. `wpmcp/detect-builder` on the post. It names the builder (bricks, divi,
   wpbakery, avada, beaver-builder, breakdance, oxygen, oxygen-classic,
   thrive, cornerstone, gutenberg, classic). Pass that name as `builder` on
   every builder call.
2. `wpmcp/get-builder-content` with the post id and builder. It returns the
   element tree with a path for each element and, for most builders, a hash
   of what you read.
3. For a widget or element whose settings you have not seen, read its
   controls with `scope: "catalog"` and the element name, instead of guessing
   setting keys. A guessed key is silently ignored by most builders.

## Change one element

`wpmcp/update-builder-content` takes an `operation`:

- update: `path` plus the `attrs` and/or `text` to change. Unchanged
  settings keep their values.
- add: `to` (a parent path, empty for the top level), `index` and the new
  `element`.
- remove: `path`.
- move: `path`, `to` and `index`.

Pass `expected_hash` from your last read whenever the read returned a hash
(Divi 5, WPBakery, Avada, Oxygen, Thrive, Cornerstone and BeTheme require
it). If someone edited the page since, the write is refused: read again and
re-plan rather than forcing it. Bricks
and Divi 4 take a whole content document instead of an operation; for those,
change only the element you need inside the tree you read and send it back
with nothing else altered.

## Worked example: change a heading

User: "On the Services page, change the hero heading to Book a free call."

1. `wpmcp/detect-builder` says breakdance.
2. `wpmcp/get-builder-content`, find the heading element in the hero section
   and note its path (and the hash, if the read returned one).
3. `wpmcp/update-builder-content` with operation update, that path and the
   new text.
4. `wpmcp/get-builder-content` again, then `wpmcp/clear-cache` and
   `wpmcp/get-rendered-html` to confirm visitors see it. Builders cache CSS
   and HTML per page.

## Site-wide design

Colors, typography, global classes and variables (Bricks, Breakdance, Avada)
live in `scope: "design_system"`, and WPBakery templates in
`scope: "templates"`. A global change restyles every page: list the pages
that will change and confirm first.

## Undo

Every write is snapshotted; `wpmcp/rollback-operation` restores the page as
it was. Never fall back to `wpmcp/set-post-meta` or the database tools to
write builder data: that bypasses the hash check and the builder's own
format validation.
