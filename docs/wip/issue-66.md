# Issue #66: Forms plugin integrations (CF7 free, pro adapter pack)

Status: WIP. This branch ships the first slice; the checklist below tracks the
rest of the acceptance criteria from the issue.

## What already exists on main

The integration dispatcher framework (issue #65, `src/Integrations/Integration_Dispatcher.php`)
plus per-plugin adapters that already cover part of this issue's surface:

| Adapter | forms | fields | entries | notifications | entry status | entry delete |
| --- | --- | --- | --- | --- | --- | --- |
| Contact Form 7 | yes | markup | no (CF7 stores none) | mail template | n/a | n/a |
| WPForms | yes | yes | no | no | no | no |
| Gravity Forms | yes | yes | yes | yes | no | no |
| Formidable | yes | yes | yes | no | no | no |
| Ninja Forms | yes | yes | no | no | no | no |
| Fluent Forms | yes | yes | no | no | no | no |
| Forminator | yes | yes | yes | no | no | yes (guarded) |

## This branch (first slice)

CF7 free-tier entries via Flamingo, in `src/Integrations/Contact_Form_7_Integration.php`:

- `list-entries`: paged, newest first, optional `form_id` scoping (form slug
  matched to the Flamingo channel). Structured `flamingo_unavailable` error
  when Flamingo is not active.
- `get-entry`: full submission (subject, sender, channel, fields, meta).
- `delete-entry`: destructive (confirm:true via the dispatcher), default-off
  until the site opts in with `wpmcp_integration_op_enabled`, hard-delete
  flagged `recoverable:false`.
- Entry ops carry a per-op `edit_others_posts` capability on top of the pair
  capability because submissions are user PII. All ops flow through the
  dispatcher's governance evaluation and audit logging unchanged.

## Remaining work

- [ ] Flamingo stub in `tests/support/forms-stubs.php` plus CF7 entry cases in
      `tests/free/Integrations/FormsIntegrationsTest.php` (list/get/delete,
      capability refusal, default-off refusal, flamingo_unavailable path).
- [ ] Shared conformance suite: one contract test run against every adapter
      (same op names, paging shape, guard behavior), per-plugin fixtures.
- [ ] Entries for WPForms (Pro entry handler), Ninja Forms, Fluent Forms in
      the pro pack; entry-status update ops (spam/ham/trash) where the host
      plugin models them (Flamingo, Gravity Forms, Fluent Forms).
- [ ] Notifications ops for WPForms, Ninja Forms, Fluent Forms, Formidable
      (Gravity Forms already surfaces notifications).
- [ ] Guarded entry deletion for the remaining adapters, mirroring the
      Forminator/CF7 pattern (destructive, default-off, recoverable:false).
- [ ] CI: live CF7+Flamingo install coverage; documented-API stub tests for
      paid plugins with a live-verification note in release notes.
- [ ] Security review of entry-data exposure paths (PII) and deletion gating.
