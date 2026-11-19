---
name: Bookings: Amelia
description: Services, staff, free slots and appointments; book, reschedule or cancel through Amelia's own checks, messages held unless asked.
version: 1.0.0
tier: pro
tags: [integrations, bookings]
requires_any:
  - wpmcp/plugin-data-read#amelia-get-setup
---

# Appointments and events in Amelia

Reads go to `wpmcp/plugin-data-read`, writes to `wpmcp/plugin-data-write`.
Every write goes through Amelia's own application layer, so its slot rules,
working hours and capacity checks apply. Times are site-local (Y-m-d H:i);
`amelia-get-setup` tells you the site timezone.

## Read first

1. `amelia-get-setup`: edition, timezone, currency, slot length, whether a
   mail sender is set.
2. `amelia-list-services` and `amelia-list-employees` for ids, durations,
   prices and working weeks.
3. `amelia-get-slots` for a service from a date: the free slots exactly as
   Amelia computes them. Never offer a time this did not return.
4. `amelia-list-appointments` (default the next 30 days) and
   `amelia-list-customers`. Customer names, emails, phones and notes are
   masked in lists.

## Worked example: rebook a customer

User: "Move Sara's massage on Friday to the next free slot on Monday."

1. `amelia-list-appointments` for Friday with the service, find the
   appointment and its employee.
2. `amelia-get-slots` for the service and that employee from Monday.
3. Tell the user the slot you will use and whether the customer should be
   told. Messages to customers are held unless you pass `notify: true`.
4. `amelia-reschedule-appointment` with the appointment id and the new
   start.
5. `amelia-list-appointments` for Monday to confirm it.

## Other writes

- `amelia-book-appointment` books a customer (create one first with
  `amelia-create-customer` if needed; an email already in use is refused).
- `amelia-set-appointment-status` approves, sets pending, cancels or
  rejects. Appointments are never deleted.
- Services, categories, employees and events have create and update
  operations. `amelia-update-employee` with a working week replaces the whole
  week: send every working day, not only the changed one.
- Events: `amelia-create-event`, `amelia-set-event-status` (open or cancel)
  and `amelia-add-event-attendee`, which refuses when too few places are
  left.
- Deletes (`amelia-delete-service` and the others) need `confirm: true` and
  are refused while bookings depend on the record.

## Undo

Every write is snapshotted and undone with `wpmcp/rollback-operation`. A
message already sent to a customer (with `notify: true`) is not undone.
