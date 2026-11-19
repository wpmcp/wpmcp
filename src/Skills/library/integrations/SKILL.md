---
name: Plugins: which skill to load
description: Map a plugin request (courses, contacts, translations, events, tables, bookings, memberships, funnels, builders) to its skill, and the call order they share.
version: 1.0.0
tier: free
tags: [integrations, router]
requires_any:
  - wpmcp/plugin-data-read
  - wpmcp/theme-read
  - wpmcp/tec-read
  - wpmcp/mec-read
  - wpmcp/pmpro-read
  - wpmcp/get-builder-content
---

# Working with the site's plugins

Most plugin support in wpmcp is not a tool of its own. It is a set of named
operations inside a dispatcher pair: a read half (`wpmcp/plugin-data-read`,
`wpmcp/theme-read`, ...) and a write half (`wpmcp/plugin-data-write`,
`wpmcp/theme-write`, ...). Each call names one operation and its args:

    {"operation": "list-events", "args": {"page_size": 10}}

## Pick the skill

`wpmcp/list-skills` only lists the skills whose plugin is active on this
site, so what it returns is also a map of what you can do here. Load the one
that matches the request with `wpmcp/get-skill`:

- Block editor (Gutenberg) pages: `wpmcp-block-editing`.
- Security alerts, a hacked site, lockouts: `wpmcp-security-incident`.
- A slow site, caches, stale pages: `wpmcp-performance-caching`.
- Translated content with TranslatePress, Polylang or WPML: `integrations/translation`.
- Events in The Events Calendar or Modern Events Calendar: `integrations/events`.
- Paid Memberships Pro levels, Paid Member Subscriptions plans: `integrations/memberships`.
- Pages built with Elementor: `wpmcp-elementor-editing`.
- Pages built with Bricks, Breakdance, Divi, Oxygen, WPBakery, Avada, Beaver Builder: `integrations/page-builders`.
- Courses in Tutor LMS, LifterLMS or LearnDash: `integrations/lms`.
- Contacts, lists and campaigns in FluentCRM or MailPoet: `integrations/crm`.
- Whole-page translation, Weglot and GTranslate: `integrations/page-translation`.
- TablePress tables: `integrations/tablepress`.
- Amelia appointments and events: `integrations/bookings`.
- MemberPress memberships and access rules: `integrations/memberpress`.
- FunnelKit funnels and checkout steps: `integrations/funnels`.

If nothing fits, search the tools: `wpmcp/list-tools` with a search term
also matches operation names, so "pods" or "wordfence" finds the pair that
holds them.

## The order every integration shares

1. Find the operation. `list-operations` on the read half returns every
   operation with its input schema, whether it is enabled, whether it needs
   confirm, and `dependency_met` (is its plugin active).
2. Read before you write. Every area has a list and a get operation; use
   them to find ids instead of guessing.
3. Write on the write half. Send only the fields you are changing.
4. Destructive operations need `confirm: true`. Some cannot be undone (mail
   sent, a cache purged); say so before you call them.
5. Some writes are off until the site owner opts in. The refusal is
   `opt_in_required` and names the switch. Tell the user; do not work around
   it with raw meta or database tools.
6. Read the object back, then tell the user what changed. Writes are
   snapshotted: `wpmcp/rollback-operation` undoes one, and
   `wpmcp/rollback-session` undoes a batch.

## Refusals worth recognising

- `plugin_inactive` or `plugin_missing`: the plugin is not active. Report it;
  do not activate plugins unless the user asked.
- `operation_denied`: the current user lacks the capability the operation
  needs, or governance turned the operation off.
- Personal data (emails, names, IPs) is masked in lists. A single-record get
  returns it in full only to users allowed to see it.
