---
name: Memberships: Paid Memberships Pro, Paid Member Subscriptions
description: Read levels, plans and member subscriptions (masked) to answer questions and audit pricing.
version: 1.0.0
tier: free
tags: [integrations, memberships]
requires_any:
  - wpmcp/pmpro-read
  - wpmcp/plugin-data-read#pms-list-plans
---

# Membership levels and plans

These are read-only. Membership prices and access drive real billing, so
wpmcp reads them and leaves changes to the plugin's own screens.

## Paid Memberships Pro

`wpmcp/pmpro-read` answers:

- `list-levels`: id, name, initial and recurring price, billing cycle,
  whether signups are open, active member count.
- `get-level`: one level's full configuration.

## Paid Member Subscriptions

On `wpmcp/plugin-data-read`:

- `pms-list-plans`: name, status, price, sign-up fee, duration, trial,
  recurring, role and active member count.
- `pms-list-members`: subscriptions newest first, with each member's name
  and email masked, plan, status, dates, billing and gateway.

## Worked example: pricing audit

User: "Which levels still allow signups, and what do they cost per year?"

1. `list-levels` on `wpmcp/pmpro-read`.
2. For each level with signups allowed, convert the recurring price and
   billing cycle to a yearly figure, and use `get-level` when a level has an
   expiration or trial that changes the answer.
3. Report the table. If the user wants a price changed, say that this is
   done in Paid Memberships Pro's level editor, and that a change applies to
   new signups only.

## Personal data

Member lists are masked. Do not try to unmask members by joining with the
user tools; if the user needs one member's details, ask which member and why,
and read that one user with `wpmcp/get-user`.
