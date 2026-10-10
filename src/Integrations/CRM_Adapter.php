<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Crm_Rows_Snapshot;
use WPMCP\Safety\Safe_Mutation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One email marketing or CRM plugin's contacts, lists, tags, campaigns and
 * automations as plugin-data ops (issue #477). The op catalog, the argument
 * and consent checks and the snapshot discipline live here, once; a subclass
 * says where its plugin keeps things and calls the plugin's own API for every
 * write (CRM_FluentCRM, CRM_MailPoet). Every op is paid-tier and prefixed
 * with the plugin (fluentcrm-get-contact, mailpoet-tag-contact, ...).
 *
 * Reads: a setup summary, contacts (search, filter by list, tag and status,
 * paged), one contact with its lists, tags and custom field values, lists,
 * tags, custom field definitions, campaigns (status, schedule, recipient
 * count) and automations (status, enrolled count).
 *
 * Writes run through the plugin's API, so its hooks, automations and opt-in
 * rules run, and each is snapshotted first as a 'crm_rows' image
 * (rollback-operation): create, update and delete a contact, tag and untag
 * it, add it to and remove it from lists, and create, rename and delete
 * lists and tags. Consent is never overridden:
 *  - a new contact is created the way the plugin's own opt-in rules create
 *    one (FluentCRM: pending, with its double opt-in email, unless the site
 *    allows direct subscribes through wpmcp_crm_direct_subscribe; MailPoet:
 *    by its sign-up confirmation setting), and an email that already belongs
 *    to a contact is refused rather than merged;
 *  - the only status a write may set on an existing contact is
 *    unsubscribed, and a contact who unsubscribed, bounced or complained is
 *    refused from any list it would be added to;
 *  - rollback never sets anyone back to a mailable status (see
 *    Crm_Rows_Snapshot).
 *
 * Bulk import (import-contacts) creates each new contact the way
 * create-contact does and, with update_existing, updates an existing one;
 * a contact who unsubscribed, bounced or complained, or left a list it
 * would join, is skipped untouched. Each contact is its own snapshot, so
 * rollback-session undoes the whole import.
 *
 * Campaigns: create, update and duplicate a draft through the plugin, each
 * undone by rollback-operation while the campaign is still that draft; send
 * a test of one to the current user; and send or schedule one to its lists
 * (send-campaign). A campaign goes only to subscribed contacts, as the
 * plugin counts them. send-campaign is off until a site enables it with the
 * wpmcp_enable_crm_send filter, and an unconfirmed call answers with the
 * recipient count through the confirmation path (elicitation where the
 * client supports it). Mail cannot be unsent: a send or a test is
 * snapshotted, but its undo is refused (Crm_Rows_Snapshot::refusal()).
 * Automations: set one's status, undone by rollback-operation.
 *
 * Capabilities are the plugin's own, on top of the pair's: FluentCRM's
 * permission sets (fcrm_read_contacts, fcrm_manage_contacts, ...) and
 * MailPoet's capabilities (mailpoet_manage_subscribers, ...). Contacts are
 * personal data: every op that returns one needs the plugin's contact read
 * permission.
 */
abstract class CRM_Adapter
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    public const MAX_PER_PAGE = 100;

    /** Members a list or tag may hold for its delete to be snapshotted. */
    public const MAX_GROUP_MEMBERS = 5000;

    /** Contact fields a write may set, beyond custom fields. */
    protected const CONTACT_FIELDS = [ 'first_name', 'last_name' ];

    /** Contacts one import-contacts call takes. */
    public const MAX_IMPORT = 100;

    /** Campaign fields a write may set (title is FluentCRM's alone). */
    protected const CAMPAIGN_FIELDS = [ 'title', 'subject', 'preheader', 'body', 'lists' ];

    /** Op name prefix and presence-filter slug, e.g. 'fluentcrm'. */
    abstract public function slug(): string;

    /** The plugin's name, e.g. 'FluentCRM'. */
    abstract public function label(): string;

    /** Whether the plugin is loaded (filterable per plugin). */
    abstract public function active(): bool;

    /**
     * Whether the current user holds the plugin's own permission for one
     * kind of access: read, write, delete, groups, groups_delete, campaigns,
     * campaigns_write, automations or automations_write.
     */
    abstract public function can(string $access): bool;

    /** Every contact status the plugin knows. @return string[] */
    abstract protected function statuses(): array;

    /** The statuses that stop mail: unsubscribed, bounced and the like. @return string[] */
    abstract protected function suppressed(): array;

    /** The snapshot kind of a contact write. */
    abstract protected function contact_kind(): string;

    /** The snapshot kind of a contact delete. */
    abstract protected function contact_delete_kind(): string;

    /** @return array<string, mixed> the setup summary */
    abstract protected function summary(): array;

    /**
     * One page of contacts, newest first.
     *
     * @param array{search?: string, status?: string, list_id?: int, tag_id?: int} $filter
     * @return array{total: int, rows: array<int, array<string, mixed>>}
     */
    abstract protected function contact_page(array $filter, int $page, int $per_page): array;

    /** One contact with its lists, tags and custom field values, or null. */
    abstract public function contact(int $id): ?array;

    /** The id of the contact holding an email address, or 0. */
    abstract protected function contact_id_by_email(string $email): int;

    /**
     * Every list or tag with its member count.
     *
     * @return array<int, array<string, mixed>>
     */
    abstract protected function groups(string $kind): array;

    /** One list or tag, or null. */
    abstract protected function group(string $kind, int $id): ?array;

    /** How many contacts a list or tag holds. */
    abstract protected function group_member_count(string $kind, int $id): int;

    /**
     * Custom field definitions keyed by the key a write names them by.
     *
     * @return array<string, array<string, mixed>>
     */
    abstract protected function custom_fields(): array;

    /**
     * One page of campaigns, newest first.
     *
     * @return array{total: int, rows: array<int, array<string, mixed>>}
     */
    abstract protected function campaign_page(?string $status, int $page, int $per_page): array;

    /** @return string[] campaign statuses a listing may filter on */
    abstract protected function campaign_statuses(): array;

    /** @return array<int, array<string, mixed>> automations with status and enrolled count */
    abstract protected function automations(): array;

    /** The input schema of create-contact's status, or null when the plugin decides it alone. */
    abstract protected function create_status_schema(): ?array;

    /**
     * A refusal for adding a contact to lists the plugin itself records it
     * as having left, or null.
     *
     * @param int[] $lists
     */
    abstract protected function list_rejoin_refusal(int $contact, array $lists): ?array;

    /**
     * Create a contact through the plugin's API and return its id.
     *
     * @param array<string, string> $fields
     * @param array<string, mixed>  $custom
     * @param int[]                 $lists
     * @param int[]                 $tags
     */
    abstract protected function api_create_contact(string $email, array $fields, array $custom, array $lists, array $tags, string $status): int;

    /**
     * @param array<string, string> $fields
     * @param array<string, mixed>  $custom
     */
    abstract protected function api_update_contact(int $id, array $fields, array $custom): void;

    abstract protected function api_unsubscribe(int $id): void;

    abstract protected function api_delete_contact(int $id): void;

    /** @param int[] $ids */
    abstract protected function api_attach(int $contact, string $kind, array $ids): void;

    /** @param int[] $ids */
    abstract protected function api_detach(int $contact, string $kind, array $ids): void;

    abstract protected function api_create_group(string $kind, string $title, string $description): int;

    /** @param array{title?: string, description?: string} $changes */
    abstract protected function api_update_group(string $kind, int $id, array $changes): void;

    abstract protected function api_delete_group(string $kind, int $id): void;

    /** The snapshot kind of a campaign draft write. */
    abstract protected function campaign_kind(): string;

    /** The snapshot kind of a test or a send. */
    abstract protected function campaign_send_kind(): string;

    /**
     * One campaign (title, subject, preheader, body, status, lists,
     * recipients, scheduled_at), or null.
     */
    abstract public function campaign(int $id): ?array;

    /** How many contacts a campaign would go to now: subscribed ones only. */
    abstract protected function campaign_recipients(int $id): int;

    /** The schema of create-campaign's title, or null when the plugin has none. */
    abstract protected function campaign_title_schema(): ?array;

    /** @param array<string, mixed> $fields title, subject, preheader, body, lists */
    abstract protected function api_create_campaign(array $fields): int;

    /** @param array<string, mixed> $fields */
    abstract protected function api_update_campaign(int $id, array $fields): void;

    abstract protected function api_duplicate_campaign(int $id): int;

    abstract protected function api_send_test(int $id, string $email): void;

    /** Send now (null) or schedule at a site-local 'Y-m-d H:i:s'. */
    abstract protected function api_send_campaign(int $id, ?string $at): void;

    /** The snapshot kind of an automation status change. */
    abstract protected function automation_kind(): string;

    /** @return string[] the statuses set-automation-status may set */
    abstract protected function automation_statuses(): array;

    abstract protected function api_set_automation_status(int $id, string $status): void;

    /**
     * A plugin's own refusal of a campaign's fields (FluentCRM: a title
     * another campaign holds), or null.
     *
     * @param array<string, mixed> $fields
     */
    protected function campaign_fields_refusal(array $fields, int $except): ?array
    {
        return null;
    }

    /** One automation by id, from the automations read. */
    protected function automation(int $id): ?array
    {
        foreach ($this->automations() as $automation) {
            if ((int) $automation['id'] === $id) {
                return $automation;
            }
        }
        return null;
    }

    /** Whether the site lets an agent send campaigns to real recipients. */
    protected function send_enabled(): bool
    {
        return (bool) apply_filters('wpmcp_enable_crm_send', false, $this->slug());
    }

    /** The snapshot kind of a list or tag. */
    protected function group_kind(string $kind): string
    {
        return $this->slug() . '_' . $kind;
    }

    /**
     * Whether a new contact may be created already subscribed, skipping
     * double opt-in. Off unless the site opts in.
     */
    protected function direct_subscribe_allowed(): bool
    {
        return (bool) apply_filters('wpmcp_crm_direct_subscribe', false, $this->slug());
    }

    // ------------------------------------------------------------------
    // Catalog
    // ------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    public function operations(): array
    {
        $p        = $this->slug();
        $label    = $this->label();
        $requires = fn () => Ops_Status_Packs::presence($this->active(), $p . '_inactive', $label);
        $base     = [ 'tier' => 'pro', 'requires' => $requires ];
        $id       = [ 'type' => 'integer', 'minimum' => 1 ];
        $ids      = [ 'type' => 'array', 'items' => $id, 'minItems' => 1, 'maxItems' => 100, 'uniqueItems' => true ];
        $paging   = [
            'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
            'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
        ];
        $name     = [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 190 ];
        $fields   = [
            'first_name'    => [ 'type' => 'string', 'maxLength' => 190 ],
            'last_name'     => [ 'type' => 'string', 'maxLength' => 190 ],
            'custom_fields' => [ 'type' => 'object' ],
        ];
        $create   = [
            'email' => [ 'type' => 'string', 'format' => 'email', 'minLength' => 3, 'maxLength' => 150 ],
            'lists' => $ids,
            'tags'  => $ids,
        ] + $fields;
        $status   = $this->create_status_schema();
        if (null !== $status) {
            $create['status'] = $status;
        }
        $read = fn (string $access): callable => fn (array $args): ?array => $this->permission_refusal($access);

        $ops = [
            "{$p}-summary"            => $base + [
                'mode'         => 'read',
                'description'  => "{$label} setup: contact counts by status, lists, tags, campaigns and automations, and how a new contact is opted in",
                'input_schema' => [ 'type' => 'object' ],
                'validate'     => $read('read'),
                'handler'      => fn (array $args): array => $this->summary(),
            ],
            "{$p}-list-contacts"      => $base + [
                'mode'         => 'read',
                'description'  => "List {$label} contacts newest first. search matches email and name; status, list_id and tag_id filter; page and per_page (max 100)",
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'search'  => [ 'type' => 'string', 'maxLength' => 190 ],
                        'status'  => [ 'type' => 'string', 'enum' => $this->statuses() ],
                        'list_id' => $id,
                        'tag_id'  => $id,
                    ] + $paging,
                ],
                'validate'     => $read('read'),
                'handler'      => fn (array $args): array => $this->list_contacts($args),
            ],
            "{$p}-get-contact"        => $base + [
                'mode'         => 'read',
                'description'  => "One {$label} contact by id or email, with its lists, tags and custom field values",
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'    => $id,
                        'email' => [ 'type' => 'string', 'minLength' => 3 ],
                    ],
                ],
                'validate'     => fn (array $args): ?array => $this->permission_refusal('read') ?? $this->identity_refusal($args),
                'handler'      => fn (array $args): array => $this->require_contact($this->resolve_contact($args)),
            ],
            "{$p}-list-custom-fields" => $base + [
                'mode'         => 'read',
                'description'  => "{$label} contact custom field definitions, keyed by the key create-contact and update-contact take",
                'input_schema' => [ 'type' => 'object' ],
                'validate'     => $read('read'),
                'handler'      => fn (array $args): array => [ 'fields' => array_values($this->custom_fields()) ],
            ],
            "{$p}-list-campaigns"     => $base + [
                'mode'         => 'read',
                'description'  => "List {$label} campaigns newest first with status, schedule and recipient count; status filters; page and per_page (max 100)",
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'status' => [ 'type' => 'string', 'enum' => $this->campaign_statuses() ] ] + $paging,
                ],
                'validate'     => $read('campaigns'),
                'handler'      => fn (array $args): array => $this->list_campaigns($args),
            ],
            "{$p}-list-automations"   => $base + [
                'mode'         => 'read',
                'description'  => "List {$label} automations with status and how many contacts are enrolled",
                'input_schema' => [ 'type' => 'object' ],
                'validate'     => $read('automations'),
                'handler'      => fn (array $args): array => [ 'automations' => $this->automations() ],
            ],
            "{$p}-create-contact"     => $base + [
                'mode'         => 'write',
                'description'  => "Create a {$label} contact through {$label}, so its opt-in rules, hooks and automations run; lists and tags by id; custom_fields by key. An email that already exists is refused",
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => $create,
                    'required'   => [ 'email' ],
                ],
                'validate'     => fn (array $args): ?array => $this->create_refusal($args),
                'snapshot'     => fn (array $args): array => $this->target($this->contact_kind(), 0),
                'handler'      => fn (array $args, array $context = []): array => $this->create_contact($args, $context),
            ],
            "{$p}-update-contact"     => $base + [
                'mode'         => 'write',
                'description'  => "Update a {$label} contact's name and custom field values, or unsubscribe it (status: unsubscribed, the only status a write sets)",
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'     => $id,
                        'status' => [ 'type' => 'string', 'enum' => [ 'unsubscribed' ] ],
                    ] + $fields,
                    'required'   => [ 'id' ],
                ],
                'validate'     => fn (array $args): ?array => $this->update_refusal($args),
                'snapshot'     => fn (array $args): array => $this->target($this->contact_kind(), (int) $args['id']),
                'handler'      => fn (array $args, array $context = []): array => $this->update_contact($args, $context),
            ],
            "{$p}-delete-contact"     => $base + [
                'mode'         => 'destructive',
                'description'  => "Delete a {$label} contact the way {$label} deletes one; rollback-operation puts it back with its memberships",
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'id' => $id ],
                    'required'   => [ 'id' ],
                ],
                'validate'     => fn (array $args): ?array => $this->permission_refusal('delete') ?? $this->contact_refusal((int) $args['id']),
                'snapshot'     => fn (array $args): array => $this->target($this->contact_delete_kind(), (int) $args['id']),
                'handler'      => fn (array $args, array $context = []): array => $this->delete_contact((int) $args['id'], $context),
            ],
        ];

        $ops += $this->campaign_operations($base, $id, $ids);

        foreach ([ 'list' => 'lists', 'tag' => 'tags' ] as $kind => $plural) {
            $ops[ "{$p}-list-{$plural}" ]   = $base + [
                'mode'         => 'read',
                'description'  => "List {$label} {$plural} with how many contacts each holds",
                'input_schema' => [ 'type' => 'object' ],
                'validate'     => $read('read'),
                'handler'      => fn (array $args): array => [ $plural => $this->groups($kind) ],
            ];
            $ops[ "{$p}-update-contact-{$plural}" ] = $base + [
                'mode'         => 'write',
                'description'  => 'list' === $kind
                    ? "Add a {$label} contact to lists (add) and remove it from lists (remove), by list id, through {$label} so its hooks and automations run. A contact who unsubscribed or bounced is never added"
                    : "Tag (add) and untag (remove) a {$label} contact by tag id, through {$label} so its hooks and automations run",
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'     => $id,
                        'add'    => $ids,
                        'remove' => $ids,
                    ],
                    'required'   => [ 'id' ],
                ],
                'validate'     => fn (array $args): ?array => $this->membership_refusal($kind, $args),
                'snapshot'     => fn (array $args): array => $this->target($this->contact_kind(), (int) $args['id']),
                'handler'      => fn (array $args, array $context = []): array => $this->update_membership($kind, $args, $context),
            ];
            $ops[ "{$p}-create-{$kind}" ]   = $base + [
                'mode'         => 'write',
                'description'  => "Create a {$label} {$kind}",
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'title'       => $name,
                        'description' => [ 'type' => 'string', 'maxLength' => 250 ],
                    ],
                    'required'   => [ 'title' ],
                ],
                'validate'     => fn (array $args): ?array => $this->permission_refusal('groups') ?? $this->title_refusal($kind, (string) $args['title'], 0),
                'snapshot'     => fn (array $args): array => $this->target($this->group_kind($kind), 0),
                'handler'      => fn (array $args, array $context = []): array => $this->create_group($kind, $args, $context),
            ];
            $ops[ "{$p}-update-{$kind}" ]   = $base + [
                'mode'         => 'write',
                'description'  => "Rename a {$label} {$kind} or change its description",
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'          => $id,
                        'title'       => $name,
                        'description' => [ 'type' => 'string', 'maxLength' => 250 ],
                    ],
                    'required'   => [ 'id' ],
                ],
                'validate'     => fn (array $args): ?array => $this->group_update_refusal($kind, $args),
                'snapshot'     => fn (array $args): array => $this->target($this->group_kind($kind), (int) $args['id']),
                'handler'      => fn (array $args, array $context = []): array => $this->update_group($kind, $args, $context),
            ];
            $ops[ "{$p}-delete-{$kind}" ]   = $base + [
                'mode'         => 'destructive',
                'description'  => "Delete a {$label} {$kind} and its memberships the way {$label} does; rollback-operation puts both back (up to " . self::MAX_GROUP_MEMBERS . ' members)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'id' => $id ],
                    'required'   => [ 'id' ],
                ],
                'validate'     => fn (array $args): ?array => $this->group_delete_refusal($kind, (int) $args['id']),
                'snapshot'     => fn (array $args): array => $this->target($this->group_kind($kind), (int) $args['id']),
                'handler'      => fn (array $args, array $context = []): array => $this->delete_group($kind, (int) $args['id'], $context),
            ];
        }

        return $ops;
    }

    /**
     * The import, campaign and automation ops.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $id
     * @param array<string, mixed> $ids
     * @return array<string, array<string, mixed>>
     */
    private function campaign_operations(array $base, array $id, array $ids): array
    {
        $p      = $this->slug();
        $label  = $this->label();
        $fields = [
            'subject'   => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 190 ],
            'preheader' => [ 'type' => 'string', 'maxLength' => 190 ],
            'body'      => [ 'type' => 'string', 'minLength' => 1 ],
            'lists'     => $ids,
        ];
        $title  = $this->campaign_title_schema();
        if (null !== $title) {
            $fields = [ 'title' => $title ] + $fields;
        }
        $by_id  = [
            'type'       => 'object',
            'properties' => [ 'id' => $id ],
            'required'   => [ 'id' ],
        ];
        $import = [
            'contacts'        => [
                'type'     => 'array',
                'minItems' => 1,
                'maxItems' => self::MAX_IMPORT,
                'items'    => [
                    'type'       => 'object',
                    'properties' => [
                        'email'         => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 150 ],
                        'first_name'    => [ 'type' => 'string', 'maxLength' => 190 ],
                        'last_name'     => [ 'type' => 'string', 'maxLength' => 190 ],
                        'custom_fields' => [ 'type' => 'object' ],
                    ],
                    'required'   => [ 'email' ],
                ],
            ],
            'lists'           => $ids,
            'tags'            => $ids,
            'update_existing' => [ 'type' => 'boolean' ],
        ];
        $status = $this->create_status_schema();
        if (null !== $status) {
            $import['status'] = $status;
        }

        return [
            "{$p}-import-contacts"       => $base + [
                'mode'              => 'write',
                'self_snapshotting' => true,
                'description'       => "Import up to " . self::MAX_IMPORT . " {$label} contacts into lists and tags, each created like create-contact (double opt-in). An existing contact is skipped, or updated with update_existing; one who unsubscribed or bounced is never touched. Undo: rollback-session",
                'input_schema'      => [
                    'type'       => 'object',
                    'properties' => $import,
                    'required'   => [ 'contacts' ],
                ],
                'validate'          => fn (array $args): ?array => $this->import_refusal($args),
                'handler'           => fn (array $args, array $context): array => $this->import_contacts($args, $context),
            ],
            "{$p}-get-campaign"          => $base + [
                'mode'         => 'read',
                'description'  => "One {$label} campaign with its subject, body, lists, status and recipient count",
                'input_schema' => $by_id,
                'validate'     => fn (array $args): ?array => $this->permission_refusal('campaigns') ?? $this->campaign_refusal((int) $args['id'], false),
                'handler'      => fn (array $args): array => [ 'campaign' => $this->campaign((int) $args['id']) ],
            ],
            "{$p}-create-campaign"       => $base + [
                'mode'         => 'write',
                'description'  => "Create a {$label} campaign draft: subject, HTML body, preheader and the lists it goes to (subscribed contacts only). Nothing is sent",
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => $fields,
                    'required'   => [ 'subject', 'body' ],
                ],
                'validate'     => fn (array $args): ?array => $this->campaign_write_refusal($args, 0),
                'snapshot'     => fn (array $args): array => $this->target($this->campaign_kind(), 0),
                'handler'      => fn (array $args, array $context = []): array => $this->create_campaign($args, $context),
            ],
            "{$p}-update-campaign"       => $base + [
                'mode'         => 'write',
                'description'  => "Update a {$label} campaign draft's subject, body, preheader or lists. Only a draft is edited",
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'id' => $id ] + $fields,
                    'required'   => [ 'id' ],
                ],
                'validate'     => fn (array $args): ?array => $this->campaign_write_refusal($args, (int) $args['id']),
                'snapshot'     => fn (array $args): array => $this->target($this->campaign_kind(), (int) $args['id']),
                'handler'      => fn (array $args, array $context = []): array => $this->update_campaign($args, $context),
            ],
            "{$p}-duplicate-campaign"    => $base + [
                'mode'         => 'write',
                'description'  => "Copy a {$label} campaign into a new draft the way {$label} duplicates one",
                'input_schema' => $by_id,
                'validate'     => fn (array $args): ?array => $this->permission_refusal('campaigns_write') ?? $this->campaign_refusal((int) $args['id'], false),
                'snapshot'     => fn (array $args): array => $this->target($this->campaign_kind(), 0),
                'handler'      => fn (array $args, array $context = []): array => $this->duplicate_campaign((int) $args['id'], $context),
            ],
            "{$p}-send-test-campaign"    => $base + [
                'mode'         => 'write',
                'undoable'     => false,
                'description'  => "Send a test of a {$label} campaign to the current user's email only. Mail cannot be unsent",
                'input_schema' => $by_id,
                'validate'     => fn (array $args): ?array => $this->permission_refusal('campaigns_write') ?? $this->campaign_refusal((int) $args['id'], false) ?? $this->test_recipient_refusal(),
                'snapshot'     => fn (array $args): array => $this->target($this->campaign_send_kind(), (int) $args['id']) + [ 'extra_snapshot_data' => [ 'sent' => 'test', 'to' => $this->test_recipient() ] ],
                'handler'      => fn (array $args, array $context = []): array => $this->send_test((int) $args['id'], $context),
            ],
            "{$p}-send-campaign"         => $base + [
                'mode'               => 'destructive',
                'undoable'           => false,
                'enabled_by_default' => $this->send_enabled(),
                'enable_hint'        => 'A site enables sending to real recipients with the wpmcp_enable_crm_send filter.',
                'description'        => "Send a {$label} campaign draft to its lists' subscribed contacts now, or at send_at (Y-m-d H:i:s, site time). Off unless wpmcp_enable_crm_send; asks for confirmation with the recipient count. Mail cannot be unsent",
                'input_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'id'      => $id,
                        'send_at' => [ 'type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}(:\\d{2})?$' ],
                    ],
                    'required'   => [ 'id' ],
                ],
                'validate'           => fn (array $args): ?array => $this->send_refusal($args),
                'confirm_preview'    => fn (array $args): array => $this->send_preview($args),
                'snapshot'           => fn (array $args): array => $this->target($this->campaign_send_kind(), (int) $args['id']) + [ 'extra_snapshot_data' => [ 'sent' => isset($args['send_at']) ? 'schedule' : 'now', 'recipients' => $this->campaign_recipients((int) $args['id']) ] ],
                'handler'            => fn (array $args, array $context = []): array => $this->send_campaign($args, $context),
            ],
            "{$p}-set-automation-status" => $base + [
                'mode'         => 'write',
                'description'  => "Set a {$label} automation's status (" . implode(', ', $this->automation_statuses()) . ") through {$label}",
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'     => $id,
                        'status' => [ 'type' => 'string', 'enum' => $this->automation_statuses() ],
                    ],
                    'required'   => [ 'id', 'status' ],
                ],
                'validate'     => fn (array $args): ?array => $this->automation_refusal($args),
                'snapshot'     => fn (array $args): array => $this->target($this->automation_kind(), (int) $args['id']),
                'handler'      => fn (array $args, array $context = []): array => $this->set_automation_status($args, $context),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Checks
    // ------------------------------------------------------------------

    /** A refusal when the current user lacks the plugin's permission for $access, or null. */
    protected function permission_refusal(string $access): ?array
    {
        if ($this->can($access)) {
            return null;
        }
        return [
            'code'    => 'operation_denied',
            'message' => sprintf('Your %s permissions do not allow this (%s access).', $this->label(), $access),
            'data'    => [ 'reason' => 'capability' ],
        ];
    }

    private function identity_refusal(array $args): ?array
    {
        if (! isset($args['id']) && ! isset($args['email'])) {
            return [ 'code' => 'invalid_args', 'message' => 'Name the contact by id or email.', 'data' => [] ];
        }
        return null;
    }

    private function contact_refusal(int $id): ?array
    {
        if (null !== $this->contact($id)) {
            return null;
        }
        return [ 'code' => 'contact_not_found', 'message' => sprintf('%s contact %d does not exist.', $this->label(), $id), 'data' => [] ];
    }

    /**
     * A refusal naming ids that are not lists (or tags) of the plugin, or null.
     *
     * @param int[] $ids
     */
    private function groups_refusal(string $kind, array $ids): ?array
    {
        $missing = array_values(array_filter($ids, fn (int $id): bool => null === $this->group($kind, $id)));
        if ([] === $missing) {
            return null;
        }
        return [
            'code'    => $kind . '_not_found',
            'message' => sprintf('Not a %s %s: %s.', $this->label(), $kind, implode(', ', $missing)),
            'data'    => [ 'missing' => $missing ],
        ];
    }

    /** A refusal naming custom field keys the plugin does not define, or null. */
    private function custom_refusal(array $custom): ?array
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($custom)), array_map('strval', array_keys($this->custom_fields()))));
        if ([] === $unknown) {
            return null;
        }
        return [
            'code'    => 'unknown_field',
            'message' => sprintf('Not a %s custom field: %s.', $this->label(), implode(', ', $unknown)),
            'data'    => [ 'unknown' => $unknown, 'fields' => array_keys($this->custom_fields()) ],
        ];
    }

    private function suppressed_refusal(array $contact): ?array
    {
        if (! in_array((string) $contact['status'], $this->suppressed(), true)) {
            return null;
        }
        return [
            'code'    => 'contact_suppressed',
            'message' => sprintf('%s contact %d is %s: it is not added to lists, so it is never mailed again without its own consent.', $this->label(), (int) $contact['id'], (string) $contact['status']),
            'data'    => [ 'status' => (string) $contact['status'] ],
        ];
    }

    private function create_refusal(array $args): ?array
    {
        $refusal = $this->permission_refusal('write');
        if (null !== $refusal) {
            return $refusal;
        }
        $email = sanitize_email((string) $args['email']);
        if (! is_email($email)) {
            return [ 'code' => 'invalid_args', 'message' => 'email is not a valid email address.', 'data' => [] ];
        }
        $existing = $this->contact_id_by_email($email);
        if ($existing > 0) {
            return [
                'code'    => 'contact_exists',
                'message' => sprintf('%s already has a contact with this email (%d); update it instead.', $this->label(), $existing),
                'data'    => [ 'id' => $existing ],
            ];
        }
        return $this->status_refusal($args)
            ?? $this->groups_refusal('list', array_map('intval', (array) ($args['lists'] ?? [])))
            ?? $this->groups_refusal('tag', array_map('intval', (array) ($args['tags'] ?? [])))
            ?? $this->custom_refusal((array) ($args['custom_fields'] ?? []));
    }

    /** A refusal of the status a new contact is asked to start in, or null. */
    private function status_refusal(array $args): ?array
    {
        if (isset($args['status']) && null === $this->create_status_schema()) {
            return [
                'code'    => 'invalid_args',
                'message' => sprintf('%s sets a new contact\'s status by its own sign-up confirmation setting; leave status out.', $this->label()),
                'data'    => [],
            ];
        }
        if ('subscribed' === ($args['status'] ?? null) && ! $this->direct_subscribe_allowed()) {
            return [
                'code'    => 'double_optin_required',
                'message' => sprintf('A new %s contact is created pending and confirms by double opt-in; a site allows direct subscribes with the wpmcp_crm_direct_subscribe filter.', $this->label()),
                'data'    => [],
            ];
        }
        return null;
    }

    private function import_refusal(array $args): ?array
    {
        $custom = [];
        foreach ((array) $args['contacts'] as $row) {
            $custom += (array) ($row['custom_fields'] ?? []);
        }
        return $this->permission_refusal('write')
            ?? $this->status_refusal($args)
            ?? $this->groups_refusal('list', array_map('intval', (array) ($args['lists'] ?? [])))
            ?? $this->groups_refusal('tag', array_map('intval', (array) ($args['tags'] ?? [])))
            ?? $this->custom_refusal($custom);
    }

    private function campaign_refusal(int $id, bool $draft): ?array
    {
        $campaign = $this->campaign($id);
        if (null === $campaign) {
            return [ 'code' => 'campaign_not_found', 'message' => sprintf('%s campaign %d does not exist.', $this->label(), $id), 'data' => [] ];
        }
        if ($draft && 'draft' !== $campaign['status']) {
            return [
                'code'    => 'campaign_not_draft',
                'message' => sprintf('%s campaign %d is %s, not a draft; only a draft is changed or sent.', $this->label(), $id, (string) $campaign['status']),
                'data'    => [ 'status' => (string) $campaign['status'] ],
            ];
        }
        return null;
    }

    private function campaign_write_refusal(array $args, int $id): ?array
    {
        $refusal = $this->permission_refusal('campaigns_write') ?? ($id > 0 ? $this->campaign_refusal($id, true) : null);
        if (null !== $refusal) {
            return $refusal;
        }
        $fields = $this->campaign_fields($args);
        if ($id > 0 && [] === $fields) {
            return [ 'code' => 'nothing_to_update', 'message' => 'Name at least one of ' . implode(', ', array_keys($this->campaign_operations_fields())) . '.', 'data' => [] ];
        }
        return $this->groups_refusal('list', (array) ($fields['lists'] ?? [])) ?? $this->campaign_fields_refusal($fields, $id);
    }

    /** The campaign fields this plugin takes. @return array<string, true> */
    private function campaign_operations_fields(): array
    {
        $fields = array_fill_keys(self::CAMPAIGN_FIELDS, true);
        if (null === $this->campaign_title_schema()) {
            unset($fields['title']);
        }
        return $fields;
    }

    private function test_recipient(): string
    {
        return (string) wp_get_current_user()->user_email;
    }

    private function test_recipient_refusal(): ?array
    {
        if (is_email($this->test_recipient())) {
            return null;
        }
        return [ 'code' => 'invalid_args', 'message' => 'The current user has no email address to send a test to.', 'data' => [] ];
    }

    private function send_refusal(array $args): ?array
    {
        $id      = (int) $args['id'];
        $refusal = $this->permission_refusal('campaigns_write') ?? $this->campaign_refusal($id, true);
        if (null !== $refusal) {
            return $refusal;
        }
        if (isset($args['send_at']) && null === self::send_at((string) $args['send_at'])) {
            return [ 'code' => 'invalid_args', 'message' => 'send_at must be a future date and time, Y-m-d H:i:s in the site\'s timezone; leave it out to send now.', 'data' => [] ];
        }
        if (0 === $this->campaign_recipients($id)) {
            return [
                'code'    => 'no_recipients',
                'message' => sprintf('%s campaign %d has no subscribed contact on its lists, so nobody would get it.', $this->label(), $id),
                'data'    => [ 'lists' => (array) ($this->campaign($id)['lists'] ?? []) ],
            ];
        }
        return null;
    }

    /** What a send would do, for the person to confirm. @return array{message: string, data: array<string, mixed>} */
    private function send_preview(array $args): array
    {
        $id       = (int) $args['id'];
        $campaign = (array) $this->campaign($id);
        $count    = $this->campaign_recipients($id);
        $at       = isset($args['send_at']) ? self::send_at((string) $args['send_at']) : null;
        return [
            'message' => sprintf(
                'It sends %s campaign %d ("%s") to %d subscribed contact%s on list(s) %s, %s. Mail cannot be unsent.',
                $this->label(),
                $id,
                (string) ($campaign['subject'] ?? ''),
                $count,
                1 === $count ? '' : 's',
                implode(', ', (array) ($campaign['lists'] ?? [])),
                null === $at ? 'now' : 'at ' . $at . ' site time'
            ),
            'data'    => [
                'campaign'   => $id,
                'subject'    => (string) ($campaign['subject'] ?? ''),
                'lists'      => (array) ($campaign['lists'] ?? []),
                'recipients' => $count,
                'send_at'    => $at,
            ],
        ];
    }

    /** A site-local 'Y-m-d H:i:s' in the future, normalized, or null. */
    private static function send_at(string $value): ?string
    {
        $value = 16 === strlen($value) ? $value . ':00' : $value;
        $date  = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, wp_timezone());
        if (false === $date || $date->format('Y-m-d H:i:s') !== $value || $date->getTimestamp() <= time()) {
            return null;
        }
        return $value;
    }

    private function automation_refusal(array $args): ?array
    {
        $refusal = $this->permission_refusal('automations_write');
        if (null !== $refusal) {
            return $refusal;
        }
        $automation = $this->automation((int) $args['id']);
        if (null === $automation) {
            return [ 'code' => 'automation_not_found', 'message' => sprintf('%s automation %d does not exist.', $this->label(), (int) $args['id']), 'data' => [] ];
        }
        if ((string) $automation['status'] === (string) $args['status']) {
            return [ 'code' => 'nothing_to_update', 'message' => sprintf('%s automation %d is already %s.', $this->label(), (int) $args['id'], (string) $args['status']), 'data' => [] ];
        }
        return null;
    }

    private function update_refusal(array $args): ?array
    {
        $refusal = $this->permission_refusal('write') ?? $this->contact_refusal((int) $args['id']);
        if (null !== $refusal) {
            return $refusal;
        }
        if ([] === array_intersect([ 'first_name', 'last_name', 'custom_fields', 'status' ], array_keys($args))) {
            return [ 'code' => 'nothing_to_update', 'message' => 'Name at least one of first_name, last_name, custom_fields or status.', 'data' => [] ];
        }
        return $this->custom_refusal((array) ($args['custom_fields'] ?? []));
    }

    private function membership_refusal(string $kind, array $args): ?array
    {
        $refusal = $this->permission_refusal('write') ?? $this->contact_refusal((int) $args['id']);
        if (null !== $refusal) {
            return $refusal;
        }
        $add    = array_map('intval', (array) ($args['add'] ?? []));
        $remove = array_map('intval', (array) ($args['remove'] ?? []));
        if ([] === $add && [] === $remove) {
            return [ 'code' => 'nothing_to_update', 'message' => 'Name ids to add or remove.', 'data' => [] ];
        }
        if ([] !== array_intersect($add, $remove)) {
            return [ 'code' => 'invalid_args', 'message' => 'An id cannot be both added and removed.', 'data' => [ 'both' => array_values(array_intersect($add, $remove)) ] ];
        }
        $refusal = $this->groups_refusal($kind, array_merge($add, $remove));
        if (null !== $refusal || [] === $add || 'list' !== $kind) {
            return $refusal;
        }
        return $this->suppressed_refusal((array) $this->contact((int) $args['id'])) ?? $this->list_rejoin_refusal((int) $args['id'], $add);
    }

    private function title_refusal(string $kind, string $title, int $except): ?array
    {
        foreach ($this->groups($kind) as $group) {
            if ((int) $group['id'] !== $except && 0 === strcasecmp(trim((string) $group['title']), trim($title))) {
                return [
                    'code'    => $kind . '_exists',
                    'message' => sprintf('%s already has a %s titled "%s" (%d).', $this->label(), $kind, $title, (int) $group['id']),
                    'data'    => [ 'id' => (int) $group['id'] ],
                ];
            }
        }
        return null;
    }

    private function group_update_refusal(string $kind, array $args): ?array
    {
        $refusal = $this->permission_refusal('groups') ?? $this->groups_refusal($kind, [ (int) $args['id'] ]);
        if (null !== $refusal) {
            return $refusal;
        }
        if (! isset($args['title']) && ! isset($args['description'])) {
            return [ 'code' => 'nothing_to_update', 'message' => 'Name a title or a description.', 'data' => [] ];
        }
        return isset($args['title']) ? $this->title_refusal($kind, (string) $args['title'], (int) $args['id']) : null;
    }

    private function group_delete_refusal(string $kind, int $id): ?array
    {
        $refusal = $this->permission_refusal('groups_delete') ?? $this->groups_refusal($kind, [ $id ]);
        if (null !== $refusal) {
            return $refusal;
        }
        $members = $this->group_member_count($kind, $id);
        if ($members > self::MAX_GROUP_MEMBERS) {
            return [
                'code'    => 'too_many_members',
                'message' => sprintf('%s %s %d holds %d contacts, more than the %d a rollback snapshot keeps; delete it in %s itself.', $this->label(), $kind, $id, $members, self::MAX_GROUP_MEMBERS, $this->label()),
                'data'    => [ 'members' => $members ],
            ];
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    private function list_contacts(array $args): array
    {
        $page     = (int) ($args['page'] ?? 1);
        $per_page = (int) ($args['per_page'] ?? 20);
        $filter   = array_intersect_key($args, array_flip([ 'search', 'status', 'list_id', 'tag_id' ]));
        $found    = $this->contact_page($filter, $page, $per_page);
        return [
            'total'    => $found['total'],
            'page'     => $page,
            'per_page' => $per_page,
            'contacts' => $found['rows'],
        ];
    }

    private function list_campaigns(array $args): array
    {
        $page     = (int) ($args['page'] ?? 1);
        $per_page = (int) ($args['per_page'] ?? 20);
        $found    = $this->campaign_page(isset($args['status']) ? (string) $args['status'] : null, $page, $per_page);
        return [
            'total'     => $found['total'],
            'page'      => $page,
            'per_page'  => $per_page,
            'campaigns' => $found['rows'],
        ];
    }

    private function resolve_contact(array $args): int
    {
        if (isset($args['id'])) {
            return (int) $args['id'];
        }
        return $this->contact_id_by_email(sanitize_email((string) $args['email']));
    }

    private function require_contact(int $id): array
    {
        $contact = $id > 0 ? $this->contact($id) : null;
        if (null === $contact) {
            throw new Operation_Error('contact_not_found', sprintf('No such %s contact.', $this->label()));
        }
        return $contact;
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /**
     * Run one write through the plugin's API with its rows tracked into the
     * snapshot. When the plugin refuses part way, the refusal carries the
     * undo point, since some rows may already have changed.
     *
     * @param array<string, mixed>          $context
     * @param callable(): mixed             $write
     * @param (callable(mixed): int)|null  $id_of
     * @return mixed
     */
    private function tracked(array $context, callable $write, ?callable $id_of = null)
    {
        $operation_id = (string) ($context['operation_id'] ?? '');
        try {
            return Crm_Rows_Snapshot::tracked($operation_id, $write, $id_of);
        } catch (Operation_Error $e) {
            throw new Operation_Error($e->error_code(), $e->getMessage(), $e->error_data() + [ 'operation_id' => $operation_id, 'recoverable' => '' !== $operation_id ]);
        }
    }

    /** The 'crm_rows' snapshot target for one object (0: a create). */
    private function target(string $kind, int $id): array
    {
        return [ 'object_type' => Crm_Rows_Snapshot::TYPE, 'object_id' => Crm_Rows_Snapshot::key($kind, $id) ];
    }

    /** The name fields an op named, trimmed. @return array<string, string> */
    private function name_fields(array $args): array
    {
        $out = [];
        foreach (self::CONTACT_FIELDS as $field) {
            if (isset($args[ $field ])) {
                $out[ $field ] = sanitize_text_field((string) $args[ $field ]);
            }
        }
        return $out;
    }

    private function create_contact(array $args, array $context): array
    {
        $email  = sanitize_email((string) $args['email']);
        $status = (string) ($args['status'] ?? 'pending');
        $id     = (int) $this->tracked(
            $context,
            fn (): int => $this->api_create_contact(
                $email,
                $this->name_fields($args),
                (array) ($args['custom_fields'] ?? []),
                array_map('intval', (array) ($args['lists'] ?? [])),
                array_map('intval', (array) ($args['tags'] ?? [])),
                $status
            ),
            static fn ($id): int => (int) $id
        );
        return [ 'contact' => $this->require_contact($id) ];
    }

    private function update_contact(array $args, array $context): array
    {
        $id = (int) $args['id'];
        $this->tracked($context, function () use ($id, $args): bool {
            $fields = $this->name_fields($args);
            $custom = (array) ($args['custom_fields'] ?? []);
            if ([] !== $fields || [] !== $custom) {
                $this->api_update_contact($id, $fields, $custom);
            }
            if ('unsubscribed' === ($args['status'] ?? null) && 'unsubscribed' !== ($this->contact($id)['status'] ?? null)) {
                $this->api_unsubscribe($id);
            }
            return true;
        });
        return [ 'contact' => $this->require_contact($id) ];
    }

    private function delete_contact(int $id, array $context): array
    {
        $this->tracked($context, function () use ($id): bool {
            $this->api_delete_contact($id);
            return true;
        });
        return [ 'deleted' => $id ];
    }

    private function update_membership(string $kind, array $args, array $context): array
    {
        $id     = (int) $args['id'];
        $add    = array_map('intval', (array) ($args['add'] ?? []));
        $remove = array_map('intval', (array) ($args['remove'] ?? []));
        $this->tracked($context, function () use ($id, $kind, $add, $remove): bool {
            if ([] !== $remove) {
                $this->api_detach($id, $kind, $remove);
            }
            if ([] !== $add) {
                $this->api_attach($id, $kind, $add);
            }
            return true;
        });
        return [ 'contact' => $this->require_contact($id) ];
    }

    private function create_group(string $kind, array $args, array $context): array
    {
        $id = (int) $this->tracked(
            $context,
            fn (): int => $this->api_create_group($kind, sanitize_text_field((string) $args['title']), sanitize_textarea_field((string) ($args['description'] ?? ''))),
            static fn ($id): int => (int) $id
        );
        return [ $kind => $this->group($kind, $id) ];
    }

    private function update_group(string $kind, array $args, array $context): array
    {
        $id      = (int) $args['id'];
        $changes = [];
        if (isset($args['title'])) {
            $changes['title'] = sanitize_text_field((string) $args['title']);
        }
        if (isset($args['description'])) {
            $changes['description'] = sanitize_textarea_field((string) $args['description']);
        }
        $this->tracked($context, function () use ($kind, $id, $changes): bool {
            $this->api_update_group($kind, $id, $changes);
            return true;
        });
        return [ $kind => $this->group($kind, $id) ];
    }

    private function delete_group(string $kind, int $id, array $context): array
    {
        $this->tracked($context, function () use ($kind, $id): bool {
            $this->api_delete_group($kind, $id);
            return true;
        });
        return [ 'deleted' => $id ];
    }

    /**
     * The campaign fields an op named, cleaned: subject, preheader and
     * title as text, the body as post HTML, lists as ids.
     *
     * @return array<string, mixed>
     */
    private function campaign_fields(array $args): array
    {
        $out = [];
        foreach (array_keys($this->campaign_operations_fields()) as $field) {
            if (! isset($args[ $field ])) {
                continue;
            }
            if ('lists' === $field) {
                $out['lists'] = array_values(array_map('intval', (array) $args['lists']));
            } elseif ('body' === $field) {
                $out['body'] = wp_kses_post((string) $args['body']);
            } else {
                $out[ $field ] = sanitize_text_field((string) $args[ $field ]);
            }
        }
        return $out;
    }

    private function require_campaign(int $id): array
    {
        $campaign = $id > 0 ? $this->campaign($id) : null;
        if (null === $campaign) {
            throw new Operation_Error('campaign_not_found', sprintf('No such %s campaign.', $this->label()));
        }
        return $campaign;
    }

    private function create_campaign(array $args, array $context): array
    {
        $fields = $this->campaign_fields($args);
        $id     = (int) $this->tracked($context, fn (): int => $this->api_create_campaign($fields), static fn ($id): int => (int) $id);
        return [ 'campaign' => $this->require_campaign($id) ];
    }

    private function update_campaign(array $args, array $context): array
    {
        $id     = (int) $args['id'];
        $fields = $this->campaign_fields($args);
        $this->tracked($context, function () use ($id, $fields): bool {
            $this->api_update_campaign($id, $fields);
            return true;
        });
        return [ 'campaign' => $this->require_campaign($id) ];
    }

    private function duplicate_campaign(int $id, array $context): array
    {
        $copy = (int) $this->tracked($context, fn (): int => $this->api_duplicate_campaign($id), static fn ($id): int => (int) $id);
        return [ 'campaign' => $this->require_campaign($copy) ];
    }

    private function send_test(int $id, array $context): array
    {
        $to = $this->test_recipient();
        $this->tracked($context, function () use ($id, $to): bool {
            $this->api_send_test($id, $to);
            return true;
        });
        return [ 'sent_to' => $to, 'campaign' => $id ];
    }

    private function send_campaign(array $args, array $context): array
    {
        $id    = (int) $args['id'];
        $at    = isset($args['send_at']) ? self::send_at((string) $args['send_at']) : null;
        $count = $this->campaign_recipients($id);
        $this->tracked($context, function () use ($id, $at): bool {
            $this->api_send_campaign($id, $at);
            return true;
        });
        return [ 'recipients' => $count, 'send_at' => $at, 'campaign' => $this->require_campaign($id) ];
    }

    private function set_automation_status(array $args, array $context): array
    {
        $id = (int) $args['id'];
        $this->tracked($context, function () use ($id, $args): bool {
            $this->api_set_automation_status($id, (string) $args['status']);
            return true;
        });
        return [ 'automation' => $this->automation($id) ];
    }

    /**
     * Import contacts, each under its own snapshot in the call's session:
     * a new one created like create-contact, an existing one updated only
     * with update_existing, and a suppressed one, or one who left a list it
     * would join, skipped untouched.
     *
     * @return array{result: array<string, mixed>, operation_ids: string[]}
     */
    private function import_contacts(array $args, array $context): array
    {
        $lists  = array_map('intval', (array) ($args['lists'] ?? []));
        $tags   = array_map('intval', (array) ($args['tags'] ?? []));
        $status = (string) ($args['status'] ?? 'pending');
        $out    = [ 'created' => [], 'updated' => [], 'skipped' => [], 'failed' => [] ];
        $ops    = [];
        $seen   = [];

        foreach ((array) $args['contacts'] as $row) {
            $row   = (array) $row;
            $raw   = trim((string) $row['email']);
            $email = sanitize_email($raw);
            if (! is_email($email)) {
                $out['skipped'][] = [ 'email' => $raw, 'reason' => 'invalid_email' ];
                continue;
            }
            if (isset($seen[ strtolower($email) ])) {
                $out['skipped'][] = [ 'email' => $raw, 'reason' => 'duplicate' ];
                continue;
            }
            $seen[ strtolower($email) ] = true;

            $fields   = $this->name_fields($row);
            $custom   = (array) ($row['custom_fields'] ?? []);
            $existing = $this->contact_id_by_email($email);
            if ($existing > 0) {
                $contact = $this->contact($existing);
                $refusal = null === $contact ? null : ($this->suppressed_refusal($contact) ?? ([] === $lists ? null : $this->list_rejoin_refusal($existing, $lists)));
                if (null !== $refusal || null === $contact || empty($args['update_existing'])) {
                    $out['skipped'][] = [ 'email' => $raw, 'reason' => null === $refusal ? 'contact_exists' : (string) $refusal['code'], 'id' => $existing ];
                    continue;
                }
                $write = function () use ($existing, $fields, $custom, $lists, $tags): int {
                    if ([] !== $fields || [] !== $custom) {
                        $this->api_update_contact($existing, $fields, $custom);
                    }
                    foreach ([ 'list' => $lists, 'tag' => $tags ] as $kind => $ids) {
                        if ([] !== $ids) {
                            $this->api_attach($existing, $kind, $ids);
                        }
                    }
                    return $existing;
                };
                $this->import_one($context, $args, $existing, $write, $email, 'updated', $out, $ops);
                continue;
            }
            $write = fn (): int => $this->api_create_contact($email, $fields, $custom, $lists, $tags, $status);
            $this->import_one($context, $args, 0, $write, $email, 'created', $out, $ops);
        }

        return [ 'result' => $out, 'operation_ids' => $ops ];
    }

    /**
     * One imported contact under its own snapshot in the import's session.
     * A refusal by the plugin is reported, and its undo point kept.
     *
     * @param array<string, mixed>      $context
     * @param callable(): int           $write
     * @param array<string, array>      $out
     * @param string[]                  $ops
     */
    private function import_one(array $context, array $args, int $id, callable $write, string $email, string $bucket, array &$out, array &$ops): void
    {
        $operation_id = wp_generate_uuid4();
        try {
            $done  = Safe_Mutation::run(
                [
                    'operation_id' => $operation_id,
                    'object_type'  => Crm_Rows_Snapshot::TYPE,
                    'object_id'    => Crm_Rows_Snapshot::key($this->contact_kind(), $id),
                    'session_id'   => (string) $context['session_id'],
                    'tool_name'    => (string) $context['tool_name'],
                    'args'         => [ 'operation' => (string) $context['operation'], 'args' => $args ],
                ],
                fn () => $this->tracked([ 'operation_id' => $operation_id ], $write, static fn ($id): int => (int) $id)
            );
            $ops[]            = $operation_id;
            $out[ $bucket ][] = [ 'id' => (int) $done['result'], 'email' => $email ];
        } catch (Operation_Error $e) {
            $ops[]           = $operation_id;
            $out['failed'][] = [ 'email' => $email, 'code' => $e->error_code(), 'message' => $e->getMessage() ];
        }
    }

    // ------------------------------------------------------------------
    // Shared read helpers for the subclasses
    // ------------------------------------------------------------------

    /** Clamp paging into an SQL offset. @return array{0: int, 1: int} [limit, offset] */
    protected static function limit_offset(int $page, int $per_page): array
    {
        $per_page = max(1, min(self::MAX_PER_PAGE, $per_page));
        return [ $per_page, ($per_page * (max(1, $page) - 1)) ];
    }

    /** Whether one table exists on this site (SHOW COLUMNS, which also sees temporary tables). */
    protected static function has_table(string $table): bool
    {
        global $wpdb;

        $suppress = $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- probing a third-party table's live shape; nothing to cache.
        $columns = $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', $table));
        $wpdb->suppress_errors($suppress);
        return [] !== (array) $columns;
    }

    /**
     * Rows of "column => count" from a GROUP BY, as a map.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, int>
     */
    protected static function counts(array $rows, string $key): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[ (string) $row[ $key ] ] = (int) $row['n'];
        }
        return $out;
    }
}
