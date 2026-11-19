---
name: Security checks and incident response
description: Scan, read the security plugin's findings, then contain step by step with confirmation.
version: 1.0.0
tier: free
tags: [security, incident]
requires:
  - wpmcp/scan-security
  - wpmcp/incident-response
---

# Security checks and incident response

Work in this order: find out, contain, clean, verify. Containment steps log
people out and cannot all be undone, so never start them on a hunch; show
the user the evidence first.

## 1. Find out

- `wpmcp/scan-security`: malware signatures (`deep: true` for the whole
  tree), core file integrity, hardening gaps and outdated software, with a
  score and ranked fixes. `vulnerabilities: true` checks known
  vulnerabilities and sends plugin slugs and versions to an outside service;
  say so before you use it.
- The site's security plugin, on `wpmcp/theme-read`:
  `get-wordfence-status`, `list-wordfence-scan-issues`,
  `list-wordfence-blocked-attacks` and `list-wordfence-login-failures` for
  Wordfence; `get-sucuri-status`, `get-solid-security-status` and
  `get-aios-status` for the others. They return no keys; emails and tried
  usernames are masked.
- `wpmcp/list-users` for unexpected administrators, `wpmcp/list-plugins` for
  plugins nobody installed, `wpmcp/get-debug-log` for recent errors.

## 2. Contain

`wpmcp/incident-response` takes an `action`. Every write needs
`confirm: true`, and your own session and app password are kept unless you
pass `include_current`.

- "list-sessions" and "list-app-passwords" first, to see what exists.
- "end-sessions" (one user or all) and "revoke-app-password" cannot be
  undone: the users log in again.
- "rotate-salts" logs everyone out, you included. Do it last, and warn the
  user they will need to reconnect.
- "reinstall-core-files" replaces the core files the scan flagged with the
  official, checksum-verified copies. It never touches wp-content, and
  `wpmcp/rollback-operation` undoes it.

For Wordfence, `block-wordfence-ip` blocks an attacking IP from the
blocked-attacks or login-failures lists, and `unblock-wordfence-ip` removes
it. Both need `confirm: true` and are undone with
`wpmcp/rollback-operation`. Block the IPs that the evidence shows, not
ranges you guessed.

## 3. Clean and verify

Deactivate (`wpmcp/deactivate-plugin`) or update (`wpmcp/update-plugin`)
the vulnerable or rogue plugin, both undoable. Do not delete files or
plugins during an incident: they are evidence, and a deletion cannot be
reviewed. Run `wpmcp/scan-security` again and compare the score and the
findings with the first run.

## Worked example

User: "Wordfence says there are critical issues."

1. `get-wordfence-status`, then `list-wordfence-scan-issues` filtered to
   critical ones.
2. `wpmcp/scan-security` for an independent view of core integrity.
3. Report what you found and the containment you recommend, and wait for a
   yes before any write.
4. With the yes: "reinstall-core-files" for modified core files, then
   `ignore-wordfence-scan-issue` only for issues the user confirms are false
   positives.
