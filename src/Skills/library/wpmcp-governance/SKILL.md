---
name: Governance, scoped identities and the audit log
description: Read what this site actually allows before you plan work, understand why a tool is missing or denied, and request the narrowest scope that gets the job done.
version: 1.0.0
tier: free
tags: [governance, permissions, identity, audit, core]
requires:
  - wpmcp/get-governance-settings
  - wpmcp/list-identities
  - wpmcp/list-governance-audit-log
---

# Governance on a wpmcp site

The tool list you can see is not the same thing as the tool list you are
allowed to run, and neither is fixed. Check before you plan, not after you
fail.

## Six layers, all narrowing

A call is permitted only if EVERY layer allows it. No layer can re-enable
something a narrower layer switched off:

1. the ability toggle for that exact tool,
2. the `wpmcp_ability_enabled` filter,
3. the domain toggle (e.g. all of `users`, all of `filesystem`),
4. the `wpmcp_domain_enabled` filter,
5. the operation toggle (`read`, `create`, `update`, `delete`),
6. the `wpmcp_operation_enabled` filter.

On top of that: the caller's WordPress capability must allow the tool, the
scoped identity attached to the connection must include the tool's domain and
operation, and pro-tier tools re-check the licence on every call.

## Before you plan a job

Call `wpmcp/get-governance-settings`. It reports the stored toggles, so you can
tell the user "deletes are disabled site-wide on this install" before you write
a plan that depends on deleting things. If a tool you expected is absent from
tools/list, that is usually a governance toggle or a missing licence, not a bug.

## When a call is denied

Do not retry the same call. Do not look for another tool that does the same
thing through a different door: if `wpmcp/delete-post` is denied, reaching the
same delete through `wpmcp/query` or `wpmcp/call-rest` is a policy violation,
not a clever workaround. Report the denial and what would need to change.

`wpmcp/list-governance-audit-log` shows recent allow and deny decisions with
the identity that made them, which is the fastest way to explain to a human
why an agent could not finish a task. Each entry names its `source`: `mcp` for
this endpoint, `rest`, `server`, `cli` or `php` for calls made outside it, which
are logged (and, with enforcement on, governed) only when the site owner turns
on `site_wide`.

## Scoped identities

`wpmcp/list-identities` shows the scoped identities configured on the site. An
identity narrows a connection to a set of domains and operations, so a content
agent can be limited to `content` + `read`/`update` and cannot touch users,
files, or plugins at all. An identity can also be pinned to client addresses
(`allowed_ips`); a call from anywhere else is refused before any tool runs.

When you need more access than you have, ask for the narrowest widening that
unblocks the specific task, name the domain and operation, and say what you
will do with it. `wpmcp/create-identity` and `wpmcp/delete-identity` exist, but
minting yourself a broader identity to get around a denial is exactly the
behaviour the audit log is there to catch.

## Reporting

Governance decisions are recorded whether they allow or deny. Assume every call
you make is attributable. That is a feature: it is what lets a site owner hand
an agent real access instead of a sandbox.
