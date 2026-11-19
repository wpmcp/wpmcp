---
name: Translations: TranslatePress, Polylang, WPML
description: Languages, post language and translation links, and TranslatePress string fixes, each undoable.
version: 1.0.0
tier: free
tags: [integrations, translation]
requires_any:
  - wpmcp/list-languages
  - wpmcp/plugin-data-read#translatepress-list-languages
---

# Multilingual content

There are two models, and the plugin decides which one you are in:

- Polylang and WPML keep one post per language, linked as translations.
  Use the language tools: `wpmcp/list-languages`,
  `wpmcp/get-post-translations`, `wpmcp/set-post-language` and
  `wpmcp/link-post-translations`.
- TranslatePress keeps one post and a dictionary of translated strings.
  Use the operations on `wpmcp/plugin-data-read` and
  `wpmcp/plugin-data-write`.

Check which one the site runs before you plan: `wpmcp/list-languages`
answers only on Polylang or WPML, and `translatepress-list-languages` only on
TranslatePress.

## Polylang and WPML

1. `wpmcp/list-languages` for the codes and the default language.
2. `wpmcp/get-post-translations` on the source post: which languages already
   have a translation, and their post ids.
3. A new translation is its own post. Create it as a draft
   (`wpmcp/create-post` or `wpmcp/duplicate-post`), then
   `wpmcp/set-post-language` on it and `wpmcp/link-post-translations` with
   both `{language, post_id}` pairs.
4. Read `wpmcp/get-post-translations` again to confirm the link.

`wpmcp/link-post-translations` snapshots only the first post in the list, so
put the post whose links you are changing first; rollback restores that
post's links, not the others'.

## TranslatePress strings

Worked example. User: "The German checkout button says Kasse, it should say
Zur Kasse."

1. `translatepress-list-languages`, take the German code (for example
   de_DE).
2. `translatepress-get-strings` for that language with a search on the
   original ("Checkout"). Each string has an id, the original, the
   translation and a status (0 none, 1 machine, 2 reviewed).
3. `translatepress-update-strings` with the id and the new translation. An
   empty translation clears it.
4. Read the string back, and check the page in that language with
   `wpmcp/get-rendered-html` if the user wants to see it.

Strings shared by many pages (menus, buttons) change everywhere at once:
say so before you write.

## Undo

Every write here is snapshotted; `wpmcp/rollback-operation` restores the
previous language, links or string translation exactly.
