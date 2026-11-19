---
name: Memberships: MemberPress
description: Read memberships, members, transactions and access rules; change memberships and rules after opt-in.
version: 1.0.0
tier: pro
tags: [integrations, memberships]
requires_any:
  - wpmcp/plugin-data-read#memberpress-list-memberships
---

# Memberships and access rules in MemberPress

Reads go to `wpmcp/plugin-data-read`, writes to `wpmcp/plugin-data-write`.
Memberships are what people pay for; rules decide what content each
membership unlocks. Both are read and written as MemberPress stores them.

## Read first

1. `memberpress-list-memberships`, then `memberpress-get-membership` for
   every setting (price, period, trial, expiry, pricing box, URLs) with
   MemberPress's defaults filled in.
2. `memberpress-list-rules` (filter by membership) for what each membership
   protects and who may see it.
3. `memberpress-list-members` and `memberpress-list-transactions` only when
   the request is about people or money. Logins, names and emails are
   masked.

## Writes are off by default

`memberpress-create-membership`, `memberpress-update-membership`,
`memberpress-create-rule` and `memberpress-update-rule` are off until the
site owner opts in (the refusal is `opt_in_required`). When they are on:

- A new membership starts as a draft. Show the user its settings before it
  is published with a status change.
- Only the args you pass change. A price change applies to new signups
  only; existing subscriptions keep their price.
- On a rule, `access` replaces every condition; use `add_access` and
  `remove_access` to change one condition and keep the rest.

## Worked example: protect a new course page

User: "Make the Advanced Lessons page members-only for Gold."

1. `memberpress-list-memberships`, take the Gold id.
2. `memberpress-list-rules` for Gold: is the page already covered by a
   category or tag rule?
3. If not, `memberpress-create-rule` with type single_page, the page id, and
   access for the Gold membership.
4. `memberpress-list-rules` again, and tell the user that logged-out
   visitors now see the unauthorized message.

## Undo

Each write is snapshotted and undone with `wpmcp/rollback-operation`.
Members and transactions are never written.
