---
name: Whole-page translation, Weglot, GTranslate
description: Create a linked translation draft and translate every segment of a page in one all-or-nothing write; Weglot and GTranslate languages.
version: 1.0.0
tier: pro
tags: [integrations, translation]
requires_any:
  - wpmcp/plugin-data-read#get-page-text
  - wpmcp/plugin-data-read#weglot-get-settings
  - wpmcp/plugin-data-read#gtranslate-get-settings
---

# Translating whole pages

On Polylang and WPML sites the page text flow translates a post, its SEO
title and description, block content and Elementor widget text, without you
having to rebuild the page. Reads go to `wpmcp/plugin-data-read`, writes to
`wpmcp/plugin-data-write`. For languages and links see the
`integrations/translation` skill.

## The page text flow

Worked example. User: "Translate the About page into French."

1. `find-missing-translations` (or `wpmcp/get-post-translations` on the
   page) to confirm there is no French version yet.
2. `create-translation` with the post id and the language. It copies the
   page into a linked French draft: content, builder data, meta, template,
   featured image, and terms mapped to their French translations.
3. `get-page-text` on the new draft. It returns segments `{key, kind, text,
   where}`, paged, and a content hash.
4. Translate each segment's text. Keep markup, placeholders and shortcodes
   exactly as they are; translate only the words.
5. `apply-page-text` with the segments `{key, text}` and the hash. It writes
   all of them as one change or none. A stale hash (someone edited the page
   meanwhile) is refused: read again rather than passing `force: true`.
6. Show the user the draft with `wpmcp/get-preview-link`. Publishing is the
   user's decision.

Work in pages of segments for a long post, but apply them in one call per
read so the hash still matches.

## Weglot and GTranslate

- `weglot-get-settings` reads the original and destination languages,
  exclusions and connection status. Weglot keeps them in its cloud project,
  so there is nothing to write here: point the user at their Weglot
  dashboard for changes.
- `gtranslate-get-settings` reads the default language and the languages
  shown. `gtranslate-set-languages` replaces the dropdown or flag language
  lists; it is off until the site owner opts in (`opt_in_required`).

## Undo

`create-translation` and `apply-page-text` are snapshotted:
`wpmcp/rollback-operation` removes the draft or restores the previous text.
A GTranslate change is restored the same way.
