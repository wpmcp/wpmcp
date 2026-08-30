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
| Contact Form 7 | yes | markup | yes (via Flamingo) | mail template | read-only status filter | yes (guarded, snapshot-backed) | free |
| WPForms | yes | yes | no | no | no | no | free |
| Gravity Forms | yes | yes | yes | yes (get-notes) | status filter on list | no | free |
| Formidable | yes | yes | yes | no | no | no | free |
| Ninja Forms | yes | yes | no | no | no | no | free |
| Fluent Forms | yes | yes | no | no | no | no | free |
| Forminator | yes | yes | yes | no | no | yes (manage_options, no snapshot: custom tables) | free |
| MetForm | yes | yes | yes | no | no | yes (manage_options, snapshot-backed) | free |
| SureForms | yes | yes | yes | no | yes | yes (manage_options, no snapshot: custom tables) | free |

Note on tiering: no forms adapter overrides `Integration_Dispatcher::tier()`,
so **every** adapter listed above currently registers as free. The issue's
"PRO adapter pack" requirement is unstarted, not partially done, and nothing in
the codebase gates a forms adapter behind a license today.

## This branch (this slice)

CF7 free-tier entries via Flamingo, in
`src/Integrations/Contact_Form_7_Integration.php`:

- `list-entries`: paged newest-first listing. Paging goes through `offset`,
  not `paged`, because Flamingo's `find()` injects `offset => 0` by default and
  WP_Query prefers a numeric offset over `paged` when it builds the LIMIT
  clause. `total` comes from `Flamingo_Inbound_Message::count()`, the only
  public accessor (`$found_items` is private static), with `post_status` passed
  explicitly to both calls because `find()` defaults to `any` and `count()` to
  `publish`. A `status` filter exposes `inbox` (default), `spam`, and `trash`,
  so the shaped `spam` flag means something.
- `form_id` scoping resolves the Flamingo channel from the form's `_flamingo`
  post meta (a channel **term id**), falling back to the form slug. When
  neither resolves, the op returns an empty page with
  `reason: unresolved_form_channel` rather than running an unscoped query that
  would hand back every form's submissions.
- `get-entry`: full submission. The message is constructed the way Flamingo
  does it (`new Flamingo_Inbound_Message($post)`) after an explicit post-type
  check, because Flamingo exposes no `get_instance()` and its constructor does
  not validate the post type.
- `delete-entry`: destructive (confirm:true), default-off until the site opts
  in via `wpmcp_integration_op_enabled`, and **reversible**: a Flamingo entry
  is an ordinary `flamingo_inbound` post, so it is snapshotted (row, meta,
  terms) through `Safe_Mutation` and resurrected at its original id by
  `rollback-operation`, exactly like the MetForm adapter.
- All three entry ops carry a per-op `manage_options` capability on top of the
  pair capability. Flamingo maps every inbound-message capability
  (`flamingo_edit_inbound_messages`, `flamingo_delete_inbound_messages`) to
  `edit_users`, i.e. administrators only, so exposing submissions to Editors
  would be looser than the host plugin itself.

Dispatcher framework change in the same slice: an optional per-op `requires`
hook (`src/Integrations/Integration_Dispatcher.php`). CF7 entries need a
companion plugin (Flamingo) that the integration as a whole does not, and the
refusal must use the dispatcher's own top-level `error` channel rather than a
hand-rolled `['error' => ...]` payload returned from a handler, which the
dispatcher would have wrapped in a success envelope (and, for the destructive
op, decorated with `recoverable:false` as though the delete had run). The op
stays in the catalog flagged `available:false` so `list-operations` still
documents it.

## Tests in this slice

- `tests/support/forms-stubs.php` gains a Flamingo double whose visibility
  mirrors the real plugin exactly: `private static $found_items`, no
  `get_instance()`, a constructor that does not validate the post type, and
  `find()`/`count()` with Flamingo's own differing `post_status` defaults.
  Entries are real `flamingo_inbound` posts queried through WP_Query, so
  paging, channel scoping, status filtering, and the snapshot/rollback path are
  genuinely exercised. A convenience stub with public properties would have
  hidden both of the fatals this slice fixes.
- `tests/free/Integrations/ContactForm7EntriesTest.php`: listing, offset paging
  (page 2 must differ from page 1), full total vs page size, channel-term
  scoping with a deliberately divergent form slug, fail-closed scoping,
  status/spam filtering, get-entry on a foreign post type, capability refusals,
  default-off refusal, confirm refusal, snapshot, and rollback resurrection.
- `tests/free/Integrations/DispatcherValidationTest.php`: the new `requires`
  hook (top-level error code, handler never reached, catalog `available` flag).
- `tests/free/Integrations/FormsAdapterConformanceTest.php`: the first slice of
  the shared conformance suite, data-provided over all nine forms adapters.
  One contract, run against every adapter: list-operations answers without the
  host plugin, the list-forms/get-form vocabulary is shared, every op
  definition is well formed (mode, description, capability, object schema,
  availability flag), entry reads are scopable by form_id and read-only,
  entry deletion is uniformly destructive + confirm + manage_options, and an
  unknown op is a structured top-level error on both halves rather than a
  fatal.

## Remaining work (acceptance criteria still open)

- [ ] "Each adapter registers only when its plugin is active": satisfied at the
      pair level by `is_available()` for every adapter, and now at op level for
      CF7's Flamingo-dependent ops. No dedicated regression test exists for the
      pair-level claim across all adapters; that belongs in the conformance
      suite below.
- [ ] Shared conformance suite: the contract half now exists
      (`FormsAdapterConformanceTest`, nine adapters). Still missing the
      per-plugin fixture half, i.e. running the same behavioral scenario
      (seed a form, seed entries, list, get, delete) against each adapter's
      own fixtures rather than only against its declared catalog.
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
      pattern (destructive, `manage_options`, `snapshot` target, therefore
      `recoverable:true`) wherever the entry is a post; `recoverable:false` is
      only honest for Forminator and SureForms, whose entries live in custom
      tables the Safety layer cannot resurrect.
- [ ] Security review of entry-data exposure paths (PII) and deletion gating.
