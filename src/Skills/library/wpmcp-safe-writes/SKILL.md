---
name: Safe writes, snapshots and rollback
description: How wpmcp snapshots every mutation before it runs, how to verify a write landed, and how to undo one operation or a whole session when it did not.
version: 1.0.0
tier: free
tags: [safety, snapshots, rollback, core]
requires:
  - wpmcp/list-operations
  - wpmcp/rollback-operation
  - wpmcp/rollback-session
---

# Safe writes with wpmcp

Every mutating wpmcp tool captures a snapshot of the affected object BEFORE it
writes. If the write throws, the mutation is reported as failed and nothing is
half-applied. If the write succeeds but the result is wrong, you can put the
object back. You do not need to build your own undo buffer, and you should not
copy content into a scratch post "just in case".

## The loop to follow for any change

1. Read the current state first (`wpmcp/get-post`, `wpmcp/get-page`,
   `wpmcp/get-product`, ...). Never write blind.
2. Make the smallest possible write. Prefer a targeted tool over a broad one:
   `wpmcp/update-block` over `wpmcp/update-blocks`, `wpmcp/set-post-meta` over
   a whole-post rewrite.
3. Read back and confirm the change is what the user asked for.
4. If it is wrong, roll back immediately rather than writing a correction on
   top of a bad state. Corrections stack; rollbacks do not.

## Finding what you changed

`wpmcp/list-operations` returns the recent snapshot history, newest first. Each
entry carries an operation id, the tool that ran, the object it touched, and a
session id grouping every operation from one connection.

Call it before you roll anything back. Rolling back by guessing an id is how
you undo somebody else's work.

## Undoing

- `wpmcp/rollback-operation` with an operation id restores that one object to
  its pre-write state. Use this for "that last edit was wrong".
- `wpmcp/rollback-session` with a session id restores every object touched in
  that session, in reverse order. Use this for "start over, put the site back
  the way it was before we began".

Both are themselves ordinary tools: they are permission checked and audited
like any other call.

## Things that are NOT rolled back

Be explicit with the user about these instead of implying total reversibility:

- Cache flushes (`wpmcp/clear-cache`). There is no meaningful before-image.
- External services. An analytics call or a cloud sync leaves the site.
- Anything done through an escape hatch (`wpmcp/run-wp-cli`,
  `wpmcp/run-php-snippet`). Those run outside the safety net on purpose, they
  are default-off and development-environment only, and you should say so
  before proposing them.
- File writes are backed up separately (`wpmcp/edit-file`, `wpmcp/write-file`
  keep a file backup), but a rollback of a theme file does not undo whatever
  the site did while the broken file was live. A `.php` write is refused if it
  does not parse, and reverted in the same call if the front end or admin then
  hits a fatal error. When that site check cannot run, the write is refused;
  pass `unchecked: true` only after telling the user the file will not be
  checked.

## Free tier history limit

On an unlicensed site the snapshot history is capped at the most recent 20
operations. A long unattended run can therefore push its own earliest
operations out of the history. For a large batch of changes, work in smaller
sessions and confirm each one, or tell the user up front that only the last 20
steps will be individually reversible.

## What to tell the user

When you propose a risky change, say which tool you will use, that it is
snapshotted, and how you would undo it. "I will update the hero block; if it
looks wrong I can roll that single operation back" is the sentence that makes
an agent trustworthy on someone else's production site.
