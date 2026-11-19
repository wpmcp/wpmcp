---
name: Contacts and campaigns: FluentCRM, MailPoet
description: Contacts, lists and tags through the CRM API, campaigns as drafts, and no mail sent without an explicit yes.
version: 1.0.0
tier: pro
tags: [integrations, crm, email]
requires_any:
  - wpmcp/plugin-data-read#fluentcrm-summary
  - wpmcp/plugin-data-read#mailpoet-summary
---

# Contacts and campaigns in FluentCRM and MailPoet

Both plugins share one set of operations, prefixed `fluentcrm-` or
`mailpoet-`. Reads go to `wpmcp/plugin-data-read`, writes to
`wpmcp/plugin-data-write`. The examples use FluentCRM; swap the prefix for
MailPoet.

Every write goes through the CRM's own API, so its double opt-in, hooks and
automations run exactly as if a person had made the change in wp-admin. That
is also why a contact write can send an email (a confirmation, an automation
step): say so before bulk changes.

## Read first

1. `fluentcrm-summary`: counts by status, the lists, tags, campaigns and
   automations, and how a new contact is opted in.
2. `fluentcrm-list-lists` and `fluentcrm-list-tags` for ids. Use existing
   lists and tags rather than creating near-duplicates.
3. `fluentcrm-list-contacts` masks emails and names; `fluentcrm-get-contact`
   returns one contact in full, by id or email.
4. `fluentcrm-list-custom-fields` before you set a custom field: keys are
   fixed by the CRM.

## Worked example: draft a campaign

User: "Write a newsletter about the spring sale for the Customers list."

1. `fluentcrm-list-lists`, take the Customers list id.
2. `fluentcrm-create-campaign` with subject, HTML body, preheader and the
   list. This creates a draft; nothing is sent.
3. `fluentcrm-get-campaign` to read it back, and show the user the subject,
   the body and the recipient count.
4. `fluentcrm-send-test-campaign` sends a test to the current user's own
   address only. Mail cannot be unsent, so ask first even for a test.
5. Stop there unless the user explicitly asks to send.

## Sending

`fluentcrm-send-campaign` mails every subscribed contact on the lists, now or
at `send_at`. It is off until the site owner opts in (the refusal is
`opt_in_required`), it needs `confirm: true`, and it cannot be undone. State
the recipient count and the time, and get an explicit yes in this
conversation before the call.

## Contacts, lists and tags

- `fluentcrm-create-contact` refuses an email that already exists; use
  `fluentcrm-update-contact` for changes. The only status a write sets is
  unsubscribed.
- `fluentcrm-import-contacts` takes up to 100 contacts per call; each is
  created like a single contact, opt-in included.
- `fluentcrm-update-contact-lists` and `fluentcrm-update-contact-tags` add
  and remove memberships by id. A contact who unsubscribed or bounced is
  never added back to a list.
- `fluentcrm-set-automation-status` publishes or pauses an automation.
  Publishing one starts it for every contact who matches its trigger.

## Verify and undo

Read the contact, list or campaign back after a write. Contact, list and tag
writes are snapshotted: `wpmcp/rollback-operation` restores one, and a
deleted contact, list or tag (`confirm: true`) comes back with its
memberships. Emails already sent are not undone by any rollback.
