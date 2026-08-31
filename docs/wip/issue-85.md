# WIP: PHP snippet lifecycle store (issue #85)

## Goal

Turn one-shot snippet execution into a governed lifecycle: snippets become
stored, checked, inactive-by-default objects that admins can review, while
every existing execution gate stays exactly as it is.

## What this slice implements

- `src/Tools/Code/Php_Snippet_Store.php`: option-backed store
  (`wpmcp_php_snippets`, autoload off). Pure storage layer; no validation,
  no execution. Record shape: id, name, code, status, last validation
  report, created_at/updated_at. Bounded (filterable max snippet count and
  max code length) and defensive about malformed records.
- `src/Tools/Code/Create_Php_Snippet.php`: always creates INACTIVE; runs
  `Php_Snippet_Validator::validate()` first and blocks on syntax errors or
  critical findings; sanitizes the name; snapshot-first via `Safe_Mutation`.
- `src/Tools/Code/List_Php_Snippets.php`: read-only summaries (no code
  bodies), every field defaulted.
- `src/Tools/Code/Get_Php_Snippet.php`: read-only; returns code + status +
  last validation report.
- `src/Tools/Code/Update_Php_Snippet.php`: re-validates changed code with
  the same blocking rules; a code change forces status back to inactive;
  snapshot-first.
- `src/Tools/Code/Delete_Php_Snippet.php`: snapshot-first, reversible.
- `src/Tools/Code/Activate_Php_Snippet.php` (PRO): the distinct governed
  activation operation. Calls the shared
  `Php_Snippet_Guard::assert_execution_allowed()`, audits every attempt to
  `Governance_Audit_Log`, and flips only the status field.
- `src/Tools/Code/Deactivate_Php_Snippet.php` (free, ungated): the
  revocation counterpart. Deliberately not behind the exec gate so an
  activated snippet can always be turned off.
- Registration: free CRUD + deactivation in
  `Plugin::register_snippet_store_abilities()`; activation in
  `Plugin::register_php_exec_abilities()` next to run-php-snippet.

## Design decisions

- **Per-record snapshots, not per-option.** The records share one option,
  but `Safe_Mutation` runs with `object_type` `php_snippet`
  (`Snapshot::capture_php_snippet()` / `Rollback_Service::
  apply_php_snippet_snapshot()`), keyed by snippet id, following the
  `redirect` precedent. Snapshotting the whole option instead would mean
  rolling back one snippet's creation deleted every snippet created after
  it, which is collateral damage, not reversibility.
- **The gate chain is shared, literally.** Enablement + environment live in
  `Php_Snippet_Guard::assert_execution_allowed()`, called by both
  `Run_Php_Snippet::guard()` and `Activate_Php_Snippet`, so a third gate
  added there cannot apply to one surface and not the other.
- **The validator is advisory, and the ability descriptions say so.** It is
  the same line-based regex scanner `Run_Php_Snippet` documents as a
  usability speed-bump, trivially evaded by a caller who already holds
  `manage_options`. Capability + enablement + environment are the real
  gates. The persisted `status` / `validation` fields are bookkeeping: any
  future executor must re-run the guard and the validator at call time.
- **Activation is registered with the executor.** Every pro ability has to
  live in a method the wp.org strip deletes wholesale, or the directory
  build's "no `'pro',` in this zip" gate fails.
- **`wpmcp_php_snippets` is on `Option_Guard`'s denylist**, so the generic
  option tools cannot rewrite a status or blank a validation report.
- **Single-writer assumption**, stated in the store docblock rather than
  pretended away: the mutating tools re-read the record inside the
  `Safe_Mutation` closure and touch only the fields they own, which closes
  the stale-write window; a per-row table would close it entirely.
- `execution unchanged`: `Run_Php_Snippet` and `Php_Snippet_Runner` behave
  exactly as before; only the location of the two guard calls moved.

## Distribution

- wp.org: the free store ships and stores PHP source but nothing in that
  build can execute it. `Activate_Php_Snippet.php` is stripped along with
  the runner. `WPORG-SUBMISSION.md` 5.4 discloses this rather than leaving
  it to be discovered.
- Woo flavor: the whole `code` group is absent, and the new files are on
  that build's removal list so the artifact matches the gate.

## Tests

- `tests/free/Code/PhpSnippetStoreToolsTest.php`: created-inactive,
  validation-blocking, name sanitization, both caps, update forcing
  inactive, malformed-record handling, deactivation without the exec gate,
  the option denylist, the opt-in gate listing, and three per-record
  rollback cases (create / delete / update) proving a rollback does not
  disturb sibling snippets.
- `tests/pro/Code/ActivatePhpSnippetTest.php`: refusal while disabled,
  refusal on production, a refused activation leaving the snippet inactive,
  the successful flip, no resurrection of pre-update code, refusal after a
  concurrent delete, audit-trail coverage of a refused attempt, and pro
  tier registration.

## What the second adversarial pass changed

Nine findings, all in the same family: a claim in a docblock or an ability
description that the code did not quite honor.

- **"Every attempt, allowed or refused, is audited" is now true.**
  `Activate_Php_Snippet::handle()` and `Deactivate_Php_Snippet::handle()`
  wrap their whole body in one `try/catch (\Throwable)`. Before, the
  `Safe_Mutation` call and the empty-id refusal both sat outside the catch,
  so a `Mutation_Failed`, a rejected store write and a concurrent delete
  produced no trail entry at all: exactly the attempts an admin most wants
  to see.
- **Activation cannot activate code it never validated.** The code is
  validated outside the `Safe_Mutation` closure and the record re-read
  inside it. The re-read closes the stale-write window but opened a worse
  one, so the validated code is hashed and the hash re-checked inside the
  closure; a mismatch aborts the mutation.
- **Rollback cannot re-arm a snippet the exec gate would refuse.**
  `rollback-operation` is free, is not in `Opt_In_Gates`, and never asks the
  execution gate, so restoring a captured `status: active` verbatim let any
  `manage_options` caller undo a deactivation and re-activate with
  `wpmcp_allow_php_exec` closed, on production. `Rollback_Service` now
  clamps a captured ACTIVE status to INACTIVE unless
  `Php_Snippet_Guard::assert_execution_allowed()` passes right now. Every
  other field is still restored exactly.
- **`list-operations` agrees with the handlers again.** Its hand-kept
  `RESTORABLE_OBJECT_TYPES` had gone stale for six object types at once, so
  writes the tools reported as `recoverable: true` were listed as
  un-undoable and the audit screen rendered no Restore button. The list is
  now `Rollback_Service::restorable_object_types()`, with a test that pins
  it against `apply_snapshot()`'s own dispatch.
- **The woo build no longer ships a dangling class reference.**
  `src/Safety/Snapshot.php` and `src/Safety/Rollback_Service.php` are
  always-loaded and name `Php_Snippet_Store` unconditionally, so pruning it
  built green and fataled at runtime on any pre-existing `php_snippet`
  snapshot row. The store stays (it is a pure option store, the same
  reasoning that keeps the guards) and the wp.org build's class-existence
  gate is ported into `build-woo-release.sh` so the next one is caught.
- **The write path stops destroying what the read path merely filters.**
  `save()` and `delete()` mutate one key of the RAW option instead of
  writing back the filtered result of `all()`, so a malformed sibling
  record is tolerated on read rather than purged by the next unrelated
  write.
- **A write that did not land is not reported as a success.** `save()` and
  `delete()` settle the no-op case first and then require `update_option()`
  to return true, and a filterable aggregate byte cap
  (`wpmcp_php_snippet_max_total_bytes`, 1 MB) bounds the option: the
  per-snippet and per-count caps multiply out to ~12.8 MB in one row, past
  what a default MySQL packet accepts.
- **Blank is not absent.** `update-php-snippet` refuses a present-but-blank
  `name` or `code` instead of treating it as "leave this alone", so
  `{id, name, code: ""}` can no longer rename and report success while the
  old code stays put.
- **`list-php-snippets` publishes the id the other tools resolve** (the
  array key, the store's actual lookup key), not the record's own `id`
  field, which a hand edit or partial restore can leave disagreeing.
- **wp.org prose.** `strip.php` now scrubs the docblock naming
  `register_php_exec_abilities()`, the `deactivate-php-snippet` description
  naming an ability that build does not contain, and `Php_Snippet_Guard`'s
  own docblock naming two stripped classes. Deactivation itself stays in
  that build on purpose: a site that carried an active snippet over from
  another build must be able to clear the flag without installing anything.
- **`WPORG-SUBMISSION.md` 5.4** now names the snapshot table as the second
  place snippet source is persisted, rather than leaving the option looking
  like the only copy.

## Remaining work

- [ ] Execute-stored-snippet by id through the same `Php_Snippet_Runner`
      bounds as `run-php-snippet` (pro + environment-gated), so agents stop
      re-sending code on every run. It MUST re-run `Php_Snippet_Guard` and
      `Php_Snippet_Validator` against the stored code at call time and must
      not trust the persisted `status` or `validation`.
      **Until it lands, `status` has exactly two consumers**: the
      `get`/`list` output projection, and `Rollback_Service`, which refuses
      to restore an ACTIVE record while the exec gate is closed. Nothing
      executes from the flag. That is worth saying plainly about the tier
      split too: in this slice the free tier does the substantive work
      (persisting and reading back arbitrary PHP source) and the pro,
      RCE-class-gated ability governs a flag whose only current effect is on
      the rollback path. The gate is placed now, before the executor, on
      purpose; it should not be described as more than it is.
- [ ] Admin-facing listing/review UI hook-in, if desired.
- [ ] Move to a per-row store if snippet volume ever makes the
      read-modify-write window worth closing outright.
