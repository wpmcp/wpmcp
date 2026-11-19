---
name: Events: The Events Calendar, Modern Events Calendar
description: Look up events and their schedules to answer questions or audit a calendar.
version: 1.0.0
tier: free
tags: [integrations, events]
requires_any:
  - wpmcp/tec-read
  - wpmcp/mec-read
---

# Events in The Events Calendar and Modern Events Calendar

Each plugin has its own pair: `wpmcp/tec-read` for The Events Calendar and
`wpmcp/mec-read` for Modern Events Calendar. Both answer the same two
operations, and both are read-only.

- `list-events`: id, title, status and start and end date, newest first,
  paged (page_size up to 100).
- `get-event`: one event with its schedule. The Events Calendar gives start
  and end, the UTC start, all-day, venue and organizer ids, cost and URL;
  Modern Events Calendar gives dates and times, all-day, location and
  organizer ids and cost.

## Worked example: audit next month's calendar

User: "Which events next month have no cost set?"

1. `list-events` and page until the start dates pass the end of next
   month (results are newest first, so keep going until you are before the
   start of next month).
2. `get-event` for each event in range and check the cost field.
3. Report the list with ids and dates. Do not change anything unless the
   user asks.

## Changing an event

There are no event write operations: the date, venue and cost fields are
owned by the calendar plugin's own data model, and writing them as raw meta
can desynchronise its tables. You may edit an event's title or description
with `wpmcp/update-post` (events are posts), which is snapshotted and undone
with `wpmcp/rollback-operation`. For dates, venues, recurrence and tickets,
tell the user to use the plugin's own editor, and give them the event id.
