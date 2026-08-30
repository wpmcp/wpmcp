# WIP: PHP snippet lifecycle store (issue #85)

## Goal

Turn one-shot snippet execution into a governed lifecycle: snippets become
stored, validated, inactive-by-default objects that admins can review, while
every existing execution gate stays exactly as it is.

## What this slice implements

- `src/Tools/Code/Php_Snippet_Store.php`: option-backed store
  (`wpmcp_php_snippets`, autoload off). Pure storage layer; no validation,
  no execution. Record shape: id, name, code, status, last validation
  report, created_at/updated_at.
- `src/Tools/Code/Create_Php_Snippet.php`: always creates INACTIVE; runs
  `Php_Snippet_Validator::validate()` first and blocks on syntax errors or
  critical findings; write is snapshot-first via `Safe_Mutation`
  (object_type `option`).
- `src/Tools/Code/List_Php_Snippets.php`: read-only summaries (no code
  bodies).
- `src/Tools/Code/Get_Php_Snippet.php`: read-only; returns code + status +
  last validation report.
- `src/Tools/Code/Update_Php_Snippet.php`: re-validates changed code with
  the same blocking rules; a code change forces status back to inactive;
  snapshot-first.
- `src/Tools/Code/Delete_Php_Snippet.php`: snapshot-first, reversible.
- `src/Tools/Code/Activate_Php_Snippet.php`: the distinct governed
  activation operation. Refuses unless `Php_Snippet_Guard::is_enabled()`
  and `is_allowed_on_environment()` both pass (the exact gates
  `Run_Php_Snippet` already enforces, shared, not duplicated).
  Re-validates stored code at activation time. Only flips the status flag;
  never executes.
- Registration in `Plugin::register_snippet_store_abilities()`: CRUD as
  free-tier abilities, activation as pro, all at `manage_options`, domain
  `code`.

## Design decisions

- Option store over a CPT: a single option rides the existing
  `Snapshot::capture('option', ...)` path, so CRUD reversibility comes for
  free from `Safe_Mutation` with zero new snapshot code. A CPT would need
  its own capture logic. Revisit if snippet counts get large.
- Activation shares `Php_Snippet_Guard` checks rather than adding a
  parallel gate, so the default-off, environment-refusal guarantees of the
  execution surface cannot drift from the activation surface.
- `execution unchanged`: `Run_Php_Snippet` and `Php_Snippet_Runner` are
  untouched by this slice.

## Remaining work

- [ ] Execute-stored-snippet by id through the same `Php_Snippet_Runner`
      bounds as `run-php-snippet` (pro + environment-gated), so agents stop
      re-sending code on every run.
- [ ] Deactivation operation (activate exists; the reverse flip is trivial
      but should land with its own tests).
- [ ] Tests: created-inactive invariant and validation-blocking in
      tests/free; execution-gates-unchanged in tests/pro shared with the
      existing runner tests (per the issue's TDD notes).
- [ ] Adversarial security review of the store surface (validation-bypass
      attempts, e.g. update-then-activate races, stored code mutated
      outside the tools).
- [ ] Admin-facing listing/review UI hook-in, if desired.
