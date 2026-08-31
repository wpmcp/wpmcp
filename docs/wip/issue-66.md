# Issue #66: Forms plugin integrations (CF7 free, pro adapter pack)

Status: WIP. This branch ships one slice; the checklist below tracks what is
still open against the issue's acceptance criteria.

## What already exists on main

The integration dispatcher framework (issue #65,
`src/Integrations/Integration_Dispatcher.php`) plus per-plugin adapters. The
table is generated from the op names actually registered in
`src/Integrations/*_Integration.php`, not from memory.

| Adapter | forms | fields | entries | notifications | entry status | entry delete | tier |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Contact Form 7 | yes | markup | yes (via Flamingo) | mail template | read-only status filter | yes (default-off, edit_users, snapshot-backed) | free |
| WPForms | yes | yes | no | no | no | no | free |
| Gravity Forms | yes | yes | yes | yes (get-notes) | status filter on list | no | free |
| Formidable | yes | yes | yes | no | no | no | free |
| Ninja Forms | yes | yes | no | no | no | no | free |
| Fluent Forms | yes | yes | no | no | no | no | free |
| Forminator | yes | yes | yes | no | no | yes (default-off, manage_options, no snapshot: custom tables) | free |
| MetForm | yes | yes | yes | no | no | yes (default-off, manage_options, snapshot-backed) | free |
| SureForms | yes | yes | yes | no | yes | yes (default-off, manage_options, no snapshot: custom tables) | free |

Note on tiering: no forms adapter overrides `Integration_Dispatcher::tier()`,
so **every** adapter listed above currently registers as free. The issue's
"PRO adapter pack" requirement is unstarted, not partially done, and nothing in
the codebase gates a forms adapter behind a license today.

## This branch (this slice)

CF7 free-tier entries via Flamingo, in
`src/Integrations/Contact_Form_7_Integration.php`:

- `list-entries`: paged newest-first listing. `form_id` is **required**, the
  same as every other forms adapter: an optional one means an omitted one dumps
  every form's submissions site-wide. Paging is `page_size` + `offset`, the
  pack's one paging vocabulary, and `offset` rather than `paged` because
  Flamingo's `find()` injects `offset => 0` by default and WP_Query prefers a
  numeric offset over `paged` when it builds the LIMIT clause. `total` comes
  from `Flamingo_Inbound_Message::count()`, the only public accessor
  (`$found_items` is private static), called **without** the page window:
  `count()` forwards its args straight to WP_Query and
  `WP_Query::set_found_posts()` bails early on an empty result set, so counting
  with the offset of a page past the last one reports `total: 0` instead of the
  real total. Dropping the window also stops the same tax+status query running
  twice per call. `post_status` is passed explicitly to both calls because
  `find()` defaults to `any` and `count()` to `publish`. A `status` filter
  exposes `inbox` (default), `spam`, and `trash`, so the shaped `spam` flag
  means something.
- `form_id` scoping resolves the Flamingo channel from the form's `_flamingo`
  post meta, which holds a channel **term id**, and there is deliberately **no
  slug fallback**. CF7 seeds the channel slug from the form's `name()`, but
  `wp_unique_term_slug` suffixes it on collision and
  `wpcf7_flamingo_update_channel` re-slugs it on rename, so one form's `name()`
  can be another form's channel slug: falling back would be a way to hand a
  caller a *different* form's submissions. A form with entries but no meta is
  exactly the case where the slug is least trustworthy. Unresolvable scope is a
  top-level `unresolved_form_channel` error on the dispatcher's own error
  channel, not an empty success envelope an agent would read as "this form has
  no submissions".
- `get-entry`: full submission. The message is constructed the way Flamingo
  does it (`new Flamingo_Inbound_Message($post)`) after an explicit post-type
  check, because Flamingo exposes no `get_instance()` and its constructor does
  not validate the post type.
- `delete-entry`: destructive (confirm:true), default-off until the site opts
  in via `wpmcp_integration_op_enabled`, and **reversible**: a Flamingo entry
  is an ordinary `flamingo_inbound` post, so it is snapshotted (row, meta,
  terms) through `Safe_Mutation` and resurrected at its original id by
  `rollback-operation`, exactly like the MetForm adapter. The snapshot callable
  returns `null` for an id that is not a `flamingo_inbound` post, so a call the
  handler will refuse as `not_found` does not first persist a full copy of an
  unrelated live post and hand back an `operation_id` that would later revert
  it.
- All three entry ops carry a per-op **`edit_users`** capability on top of the
  pair capability: exactly the cap Flamingo maps `flamingo_edit_inbound_messages`
  and `flamingo_delete_inbound_messages` to. `manage_options` was the wrong
  choice and is now fixed: under `is_multisite()` core's `map_meta_cap` turns
  `edit_users` into `manage_network_users` (super admins only) while a site
  administrator keeps `manage_options`, so `manage_options` would have made
  wpmcp a **looser** door onto submissions than Flamingo's own UI.
- Deletion is reversible, which means it is **not erasure**: the snapshot holds
  a verbatim plaintext copy of the submission until `Snapshot_Store::prune`
  evicts it, and the op description says so. To stop that from making the
  capability gate one-way, `Rollback_Service` now refuses to restore a snapshot
  of a PII-bearing post type below the capability that guards it
  (`flamingo_inbound` => `edit_users`, `metform-entry` => `manage_options`,
  extensible via the `wpmcp_pii_snapshot_capabilities` filter). Without it an
  Editor could have used `wpmcp/rollback-operation` (an `edit_posts` ability)
  to resurrect a submission they are not allowed to read.

Dispatcher framework changes in the same slice
(`src/Integrations/Integration_Dispatcher.php`):

- An optional per-op `requires` hook. CF7 entries need a companion plugin
  (Flamingo) that the integration as a whole does not, and the refusal must use
  the dispatcher's own top-level `error` channel rather than a hand-rolled
  `['error' => ...]` payload returned from a handler, which the dispatcher
  would have wrapped in a success envelope (and, for the destructive op,
  decorated with `recoverable:false` as though the delete had run). The op
  stays in the catalog flagged `dependency_met:false` so `list-operations`
  still documents it.
- `requires` **fails closed**. A present-but-non-callable value (the easy
  misreading is `'requires' => self::check()`, which stores the result) is
  `dependency_check_invalid`, not a silently deleted gate, and a check that
  throws is `dependency_unavailable` rather than a fatal that would take
  `list-operations` (the one op promised to always answer) down with it.
- The per-op flag is named `dependency_met`, not `available`. The catalog's
  top-level `available` means "the host plugin is loaded"; overloading one word
  for two questions left a consumer no way to tell which was meant.
- `Operation_Error` (`src/Integrations/Operation_Error.php`): a handler can now
  raise a refusal that surfaces on the dispatcher's top-level `error` channel,
  which is what `list-entries` uses for `unresolved_form_channel`. This closes
  the in-band-refusal anti-pattern on the handler side too, not just for
  dependency checks.

## Tests in this slice

- `tests/support/forms-stubs.php` gains a Flamingo double matched to the real
  plugin on every surface this adapter touches, including the ones that bite:
  `private static $found_items`, no `get_instance()`, a private `$id` behind a
  `__get()` shim, `find()` defaulting to `ID`/`ASC`, a constructor that does not
  validate the post type, `find()`/`count()` with Flamingo's own differing
  `post_status` defaults, and a `count()` that neither resets `posts_per_page`
  nor strips `offset`, so the out-of-range-page `found_posts` trap is
  reproduced rather than papered over. Entries are real `flamingo_inbound`
  posts queried through WP_Query. The double's remaining divergences from
  Flamingo 2.x (per-key `_field_{key}` postmeta, `find()`'s `s`/`hash`/caller
  `tax_query` support, `channel_id` appended alongside `channel`) are listed at
  the top of the file rather than claimed away: the adapter reads none of them.
- `tests/free/Integrations/ContactForm7EntriesTest.php`: listing, offset
  paging, full total vs page size, **total across an offset past the last
  entry** (the `set_found_posts` trap), one shared paging vocabulary,
  `form_id` required, channel-term scoping with a deliberately divergent form
  slug, **fail-closed scoping against a form whose slug is another form's
  channel slug**, status/spam filtering, get-entry on a foreign post type,
  capability refusals, default-off refusal, confirm refusal, snapshot,
  rollback resurrection, **no snapshot written for a refused delete**, and the
  three entry ops refusing with `flamingo_unavailable` (plus
  `dependency_met:false` in the catalog) when Flamingo is switched off through
  the `wpmcp_contactform7_flamingo_active` filter, while the form ops keep
  working.
- `tests/free/Safety/PiiSnapshotRestoreGuardTest.php`: an Editor cannot
  resurrect a deleted submission through `rollback-operation` or
  `rollback-session`, an administrator still can, an ordinary post snapshot is
  not gated, and a site can declare its own PII post types.
- `tests/free/Integrations/DispatcherValidationTest.php`: the `requires` hook
  (top-level error code, handler never reached, `dependency_met` flag), a
  non-callable `requires` failing closed, a throwing `requires` degrading to
  unavailable without taking the catalog down, and `Operation_Error` surfacing
  as a top-level error with its own data.
- `tests/free/Integrations/FormsAdapterConformanceTest.php`: the shared
  conformance suite, data-provided over all nine forms adapters, with every
  host-plugin double required by the file itself so it cannot silently degrade
  into skips. One contract, run against every adapter: list-operations answers
  for every adapter *and* with the host plugin forced absent (full op list,
  `available:false`, every other op a structured `integration_unavailable`
  error), the list-forms/get-form vocabulary is shared, entry paging uses one
  vocabulary, every op definition is well formed, entry reads **require**
  `form_id` and are read-only, entry deletion is uniformly destructive +
  confirm + **off by default** + an administrator-only capability strictly
  above the pair's own, and an unknown op is a structured top-level error on
  both halves rather than a fatal or a skip.
- Entry deletion is now `enabled_by_default => false` on **every** forms
  adapter (CF7, Forminator, MetForm, SureForms), with a per-adapter
  off-by-default test alongside the conformance assertion.

## Remaining work (acceptance criteria still open)

- [x] "Entry deletion requires confirm and is off by default": every forms
      adapter's `delete-entry` is `mode: destructive` (so `confirm:true` is
      enforced by the dispatcher) and `enabled_by_default: false`, asserted in
      the conformance suite and per adapter.
- [ ] "Each adapter registers only when its plugin is active": **as literally
      worded this is not satisfied and by design cannot be.** Dispatcher pairs
      register unconditionally (`IntegrationAbilitiesRegistrationTest` asserts
      exactly that) so `list-operations` can answer for a plugin that is not
      installed; what `is_available()` changes is the runtime answer, not
      whether the ability exists. What the branch does satisfy, and what the
      conformance suite now proves for all nine adapters, is: the pair always
      registers, `list-operations` still answers with `available:false`, and
      every other op returns a structured `integration_unavailable` error. The
      criterion needs rewording with the issue author rather than ticking.
- [ ] Shared conformance suite: the contract half exists
      (`FormsAdapterConformanceTest`, nine adapters, no skips). Still missing
      the per-plugin fixture half, i.e. running the same behavioral scenario
      (seed a form, seed entries, list, get, delete) against each adapter's own
      fixtures rather than only against its declared catalog.
- [ ] PRO adapter pack: no forms adapter overrides `tier()` today. Deciding
      which adapters move to `pro` and updating
      `tests/support/ability-manifest.php` accordingly is untouched work.
- [ ] CI: live CF7 + Flamingo install coverage. This slice is stub-backed only;
      paid plugins still need documented-API stub tests plus a
      live-verification note in release notes.
- [ ] Entries for WPForms (Pro entry handler), Ninja Forms, and Fluent Forms;
      entry-status update ops (spam/ham/trash) where the host plugin models
      them (Flamingo, Gravity Forms, Fluent Forms). CF7 currently exposes entry
      status as a read filter only.
- [ ] Notifications ops for WPForms, Ninja Forms, Fluent Forms, Formidable
      (Gravity Forms already surfaces notifications).
- [ ] Guarded entry deletion for the remaining adapters. Mirror **MetForm's**
      pattern (destructive, default-off, administrator-only capability,
      `snapshot` target, therefore `recoverable:true`) wherever the entry is a
      post; `recoverable:false` is only honest for Forminator and SureForms,
      whose entries live in custom tables the Safety layer cannot resurrect.
- [ ] Security review of entry-data exposure paths (PII): the two paths found
      so far are closed (the capability now matches Flamingo's own on
      multisite, and the snapshot cannot be restored below that capability),
      but a deliberate review of the remaining adapters has not been done.

## A note on the closing keyword

The PR body says `Closes #66`. Two of the issue's four acceptance criteria are
genuinely met (entry deletion is confirm-gated and off by default; a shared
conformance suite runs against every adapter), one needs rewording rather than
work (per-adapter registration, above), and one is open (live-CI coverage of
the free-tier target, plus the PRO adapter pack). If the milestone should stay
open for the pack, change the keyword to `Refs #66` on merge.
