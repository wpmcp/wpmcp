<?php

namespace WPMCP\Integrations;

use WPMCP\Privacy\Masking;
use WPMCP\Safety\FunnelKit_Automations_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * FunnelKit Automations automations, contacts, lists and tags as plugin-data
 * ops (issue #831), paid-tier, beside FluentCRM and MailPoet in CRM_Pack, so
 * they add no top-level tool. Every op is prefixed fka-.
 *
 * Presence is the Automations plugin itself (its BWFAN_Core class and
 * BWFAN_VERSION constant), not the WooFunnels core it shares with the
 * FunnelKit funnel builder, and is filterable (wpmcp_funnelkit_automations_active).
 *
 * Reads query FunnelKit's own tables: automations with their status and how
 * many contacts are in them or finished them, one automation with its step
 * count, contacts (search, status, list and tag filters, paged; email and
 * names MASKED), one contact in full with its tag and list names (masked
 * unless the user holds manage_options), lists and tags with member counts.
 * A contact's status is FunnelKit's own display status: unsubscribed when
 * its email is in bwfan_message_unsubscribe, otherwise by its status column.
 *
 * Writes go through FunnelKit's own contact and automation API, so its tag
 * and list hooks and the automations they start run: tag and untag a
 * contact, add it to and remove it from lists, and set an automation active
 * or inactive. A contact who unsubscribed, bounced or complained is never
 * added to a list. Writes are off until the site enables them with the
 * wpmcp_enable_funnelkit_automations_write filter, and each is snapshotted
 * as a 'funnelkit_automations' image (FunnelKit_Automations_Snapshot), so
 * rollback-operation takes back exactly what it changed.
 *
 * Every op takes FunnelKit's own access check on top of the pair's
 * capability (FunnelKit_Automations_Snapshot::may_manage()).
 *
 * Broadcasts live in the paid FunnelKit Automations Pro add-on and are not
 * part of this change; nothing here sends mail.
 */
class CRM_FunnelKit
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    public const MAX_PER_PAGE = 100;

    public const WRITE_FILTER = 'wpmcp_enable_funnelkit_automations_write';

    /** FunnelKit's term types (BWFCRM_Term_Type). */
    private const TERM_TYPES = [ 'tag' => 1, 'list' => 2 ];

    /** bwf_contact.status => display status, when not unsubscribed (BWFCRM_Contact). */
    private const STATUS = [ 0 => 'unverified', 1 => 'subscribed', 2 => 'bounced', 4 => 'soft_bounced', 5 => 'complaint' ];

    /** Statuses that keep a contact out of any list a write would add it to. */
    private const SUPPRESSED = [ 'unsubscribed', 'bounced', 'complaint' ];

    /** bwfan_automations.status => name. */
    private const AUTOMATION_STATUS = [ 1 => 'active', 2 => 'inactive' ];

    public function slug(): string
    {
        return 'fka';
    }

    public function label(): string
    {
        return 'FunnelKit Automations';
    }

    public function active(): bool
    {
        return (bool) apply_filters('wpmcp_funnelkit_automations_active', class_exists('\\BWFAN_Core') && defined('BWFAN_VERSION'));
    }

    // ------------------------------------------------------------------
    // Catalog
    // ------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    public function operations(): array
    {
        $label    = $this->label();
        $requires = fn () => Ops_Status_Packs::presence($this->active(), 'funnelkit_automations_inactive', $label);
        $base     = [ 'tier' => 'pro', 'requires' => $requires ];
        $enabled  = (bool) apply_filters('wpmcp_enable_funnelkit_automations_write', false);
        $write    = $base + [ 'mode' => 'write', 'enabled_by_default' => $enabled, 'opt_in_filter' => self::WRITE_FILTER ];
        $id       = [ 'type' => 'integer', 'minimum' => 1 ];
        $ids      = [ 'type' => 'array', 'items' => $id, 'minItems' => 1, 'maxItems' => 100, 'uniqueItems' => true ];
        $paging   = [
            'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
            'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
        ];
        $by_id    = [
            'type'       => 'object',
            'properties' => [ 'id' => $id ],
            'required'   => [ 'id' ],
        ];
        $access   = fn (array $args): ?array => $this->access_refusal();
        $off      = ' Off unless ' . self::WRITE_FILTER . '; undo: rollback-operation';

        $ops = [
            'fka-list-automations'      => $base + [
                'mode'         => 'read',
                'description'  => "List {$label} automations with status, trigger event and how many contacts are in or finished each; status filters; page and per_page (max 100)",
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'status' => [ 'type' => 'string', 'enum' => array_values(self::AUTOMATION_STATUS) ] ] + $paging,
                ],
                'validate'     => $access,
                'handler'      => fn (array $args): array => $this->list_automations($args),
            ],
            'fka-get-automation'        => $base + [
                'mode'         => 'read',
                'description'  => "One {$label} automation with its status, trigger event, step count and contact counts",
                'input_schema' => $by_id,
                'validate'     => fn (array $args): ?array => $this->access_refusal() ?? $this->automation_refusal((int) $args['id']),
                'handler'      => fn (array $args): array => [ 'automation' => $this->automation((int) $args['id']) ],
            ],
            'fka-set-automation-status' => $write + [
                'description'  => 'Set a FunnelKit Automations automation active or inactive through FunnelKit Automations.' . $off,
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'     => $id,
                        'status' => [ 'type' => 'string', 'enum' => array_values(self::AUTOMATION_STATUS) ],
                    ],
                    'required'   => [ 'id', 'status' ],
                ],
                'validate'     => fn (array $args): ?array => $this->status_refusal($args),
                'snapshot'     => fn (array $args): array => $this->target('automation', (int) $args['id']),
                'handler'      => fn (array $args, array $context = []): array => $this->set_automation_status($args, $context),
            ],
            'fka-list-contacts'         => $base + [
                'mode'         => 'read',
                'description'  => "List {$label} contacts newest first, email and names MASKED (fka-get-contact returns one in full). search matches email and name; status, list_id and tag_id filter; page and per_page (max 100)",
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'search'  => [ 'type' => 'string', 'maxLength' => 190 ],
                        'status'  => [ 'type' => 'string', 'enum' => array_merge(array_values(self::STATUS), [ 'unsubscribed' ]) ],
                        'list_id' => $id,
                        'tag_id'  => $id,
                    ] + $paging,
                ],
                'validate'     => $access,
                'handler'      => fn (array $args): array => $this->list_contacts($args),
            ],
            'fka-get-contact'           => $base + [
                'mode'         => 'read',
                'description'  => "One {$label} contact by id or email, with its status, tags and lists",
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'    => $id,
                        'email' => [ 'type' => 'string', 'minLength' => 3, 'maxLength' => 190 ],
                    ],
                ],
                'validate'     => fn (array $args): ?array => $this->access_refusal() ?? (isset($args['id']) || isset($args['email']) ? null : [ 'code' => 'invalid_args', 'message' => 'Name the contact by id or email.', 'data' => [] ]),
                'handler'      => fn (array $args): array => [ 'contact' => $this->full_contact($args) ],
            ],
        ];

        foreach ([ 'list' => 'lists', 'tag' => 'tags' ] as $kind => $plural) {
            $ops[ "fka-list-{$plural}" ]           = $base + [
                'mode'         => 'read',
                'description'  => "List {$label} {$plural} with how many contacts each holds; search matches the name; page and per_page (max 100)",
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'search' => [ 'type' => 'string', 'maxLength' => 190 ] ] + $paging,
                ],
                'validate'     => $access,
                'handler'      => fn (array $args): array => $this->list_terms($kind, $plural, $args),
            ];
            $ops[ "fka-update-contact-{$plural}" ] = $write + [
                'description'  => ('list' === $kind
                    ? 'Add a FunnelKit Automations contact to lists (add) and remove it from lists (remove), by list id, through FunnelKit so its hooks and automations run. A contact who unsubscribed, bounced or complained is never added.'
                    : 'Tag (add) and untag (remove) a FunnelKit Automations contact by tag id, through FunnelKit so its hooks and automations run.') . $off,
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
                'snapshot'     => fn (array $args): array => $this->target('contact', (int) $args['id']),
                'handler'      => fn (array $args, array $context = []): array => $this->update_membership($kind, $args, $context),
            ];
        }

        return $ops;
    }

    // ------------------------------------------------------------------
    // Checks
    // ------------------------------------------------------------------

    private function access_refusal(): ?array
    {
        if (FunnelKit_Automations_Snapshot::may_manage()) {
            return null;
        }
        return [
            'code'    => 'operation_denied',
            'message' => sprintf('%s allows this only to users it grants access (manage_options unless bwfan_crm_api_access_caps adds more).', $this->label()),
            'data'    => [ 'reason' => 'capability' ],
        ];
    }

    private function automation_refusal(int $id): ?array
    {
        if (null !== $this->automation($id)) {
            return null;
        }
        return [ 'code' => 'automation_not_found', 'message' => sprintf('%s automation %d does not exist.', $this->label(), $id), 'data' => [] ];
    }

    private function status_refusal(array $args): ?array
    {
        $refusal = $this->access_refusal() ?? $this->automation_refusal((int) $args['id']);
        if (null !== $refusal) {
            return $refusal;
        }
        $automation = (array) $this->automation((int) $args['id']);
        if ($automation['status'] === (string) $args['status']) {
            return [ 'code' => 'nothing_to_update', 'message' => sprintf('%s automation %d is already %s.', $this->label(), (int) $args['id'], (string) $args['status']), 'data' => [] ];
        }
        return null;
    }

    private function membership_refusal(string $kind, array $args): ?array
    {
        $refusal = $this->access_refusal();
        if (null !== $refusal) {
            return $refusal;
        }
        $contact = $this->contact_row((int) $args['id']);
        if (null === $contact) {
            return [ 'code' => 'contact_not_found', 'message' => sprintf('%s contact %d does not exist.', $this->label(), (int) $args['id']), 'data' => [] ];
        }
        $add    = array_map('intval', (array) ($args['add'] ?? []));
        $remove = array_map('intval', (array) ($args['remove'] ?? []));
        if ([] === $add && [] === $remove) {
            return [ 'code' => 'nothing_to_update', 'message' => 'Name ids to add or remove.', 'data' => [] ];
        }
        $both = array_values(array_intersect($add, $remove));
        if ([] !== $both) {
            return [ 'code' => 'invalid_args', 'message' => 'An id cannot be both added and removed.', 'data' => [ 'both' => $both ] ];
        }
        $known   = array_column($this->terms($kind, array_merge($add, $remove)), 'id');
        $missing = array_values(array_diff(array_merge($add, $remove), $known));
        if ([] !== $missing) {
            return [
                'code'    => $kind . '_not_found',
                'message' => sprintf('No such %s %s: %s.', $this->label(), $kind, implode(', ', $missing)),
                'data'    => [ 'missing' => $missing ],
            ];
        }
        $status = $this->status_of($contact);
        if ('list' === $kind && [] !== $add && in_array($status, self::SUPPRESSED, true)) {
            return [
                'code'    => 'contact_suppressed',
                'message' => sprintf('%s contact %d is %s: it is not added to lists, so it is never mailed again without its own consent.', $this->label(), (int) $contact['id'], $status),
                'data'    => [ 'status' => $status ],
            ];
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    private static function t(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} page, per_page, limit, offset */
    private static function paging(array $args): array
    {
        $page     = max(1, (int) ($args['page'] ?? 1));
        $per_page = max(1, min(self::MAX_PER_PAGE, (int) ($args['per_page'] ?? 20)));
        return [ $page, $per_page, $per_page, $per_page * ($page - 1) ];
    }

    private function list_automations(array $args): array
    {
        global $wpdb;

        [$page, $per_page, $limit, $offset] = self::paging($args);
        $where                              = '1=1';
        $params                             = [ self::t('bwfan_automations') ];
        if (isset($args['status'])) {
            $where   .= ' AND status = %d';
            $params[] = (int) array_search((string) $args['status'], self::AUTOMATION_STATUS, true);
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the plugin's own table; $where holds only placeholders.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE {$where}", ...$params));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the plugin's own table; $where holds only placeholders.
        $rows  = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE {$where} ORDER BY ID DESC LIMIT %d OFFSET %d", ...array_merge($params, [ $limit, $offset ])), ARRAY_A);

        return [
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'automations' => array_map(fn (array $row): array => $this->automation_out($row), $rows),
        ];
    }

    /** One automation with its counts, or null. */
    public function automation(int $id): ?array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE ID = %d', self::t('bwfan_automations'), $id), ARRAY_A);
        if (! is_array($row)) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
        $steps = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE aid = %d AND status != 3', self::t('bwfan_automation_step'), $id));
        return $this->automation_out($row) + [ 'steps' => $steps ];
    }

    private function automation_out(array $row): array
    {
        global $wpdb;

        $id     = (int) $row['ID'];
        $status = (int) $row['status'];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own tables.
        $active   = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE aid = %d', self::t('bwfan_automation_contact'), $id));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own tables.
        $complete = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE aid = %d', self::t('bwfan_automation_complete_contact'), $id));
        return [
            'id'                 => $id,
            'title'              => (string) ($row['title'] ?? ''),
            'status'             => self::AUTOMATION_STATUS[ $status ] ?? 'status_' . $status,
            'event'              => (string) $row['event'],
            'source'             => (string) $row['source'],
            'version'            => (int) $row['v'],
            'active_contacts'    => $active,
            'completed_contacts' => $complete,
        ];
    }

    private function list_contacts(array $args): array
    {
        global $wpdb;

        [$page, $per_page, $limit, $offset] = self::paging($args);
        $unsub                              = $wpdb->prepare('EXISTS (SELECT 1 FROM %i u WHERE u.recipient = c.email AND u.mode = 1)', self::t('bwfan_message_unsubscribe'));
        $where                              = [ '1=1' ];
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $like    = '%' . $wpdb->esc_like(trim((string) $args['search'])) . '%';
            $where[] = $wpdb->prepare('(c.email LIKE %s OR c.f_name LIKE %s OR c.l_name LIKE %s)', $like, $like, $like);
        }
        foreach ([ 'tag_id' => 'tags', 'list_id' => 'lists' ] as $key => $column) {
            if (isset($args[ $key ])) {
                $where[] = $wpdb->prepare('c.%i LIKE %s', $column, '%' . $wpdb->esc_like('"' . (int) $args[ $key ] . '"') . '%');
            }
        }
        if (isset($args['status'])) {
            $status = (string) $args['status'];
            if ('unsubscribed' === $status) {
                $where[] = $unsub;
            } else {
                $where[] = $wpdb->prepare('c.status = %d', (int) array_search($status, self::STATUS, true));
                $where[] = 'NOT ' . $unsub;
            }
        }
        $sql = implode(' AND ', $where);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table; $sql is built from prepared fragments only.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i c WHERE {$sql}", self::t('bwf_contact')));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own table; $sql is built from prepared fragments only.
        $rows  = (array) $wpdb->get_results($wpdb->prepare("SELECT c.*, {$unsub} AS unsubscribed FROM %i c WHERE {$sql} ORDER BY c.id DESC LIMIT %d OFFSET %d", self::t('bwf_contact'), $limit, $offset), ARRAY_A);

        return [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'contacts' => array_map(
                fn (array $row): array => Masking::keys(
                    [
                        'id'         => (int) $row['id'],
                        'email'      => (string) $row['email'],
                        'first_name' => (string) ($row['f_name'] ?? ''),
                        'last_name'  => (string) ($row['l_name'] ?? ''),
                        'status'     => ! empty($row['unsubscribed']) ? 'unsubscribed' : (self::STATUS[ (int) $row['status'] ] ?? 'status_' . (int) $row['status']),
                        'tags'       => array_map('intval', FunnelKit_Automations_Snapshot::decode($row['tags'] ?? null)),
                        'lists'      => array_map('intval', FunnelKit_Automations_Snapshot::decode($row['lists'] ?? null)),
                        'created'    => (string) $row['creation_date'],
                    ],
                    [ 'email', 'first_name', 'last_name' ]
                ),
                $rows
            ),
            'masked'   => true,
        ];
    }

    /** One bwf_contact row, or null. */
    private function contact_row(int $id): ?array
    {
        global $wpdb;

        if ($id <= 0) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::t('bwf_contact'), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** FunnelKit's display status of a contact row. */
    private function status_of(array $row): string
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
        $unsub = $wpdb->get_var($wpdb->prepare('SELECT ID FROM %i WHERE recipient = %s AND mode = 1 LIMIT 1', self::t('bwfan_message_unsubscribe'), (string) $row['email']));
        if (null !== $unsub) {
            return 'unsubscribed';
        }
        return self::STATUS[ (int) $row['status'] ] ?? 'status_' . (int) $row['status'];
    }

    /** One contact in full (masked below manage_options), or a contact_not_found error. */
    private function full_contact(array $args): array
    {
        global $wpdb;

        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
            $id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE email = %s', self::t('bwf_contact'), sanitize_email((string) ($args['email'] ?? ''))));
        }
        $contact = $this->contact_out($id);
        if (null === $contact) {
            throw new Operation_Error('contact_not_found', sprintf('No such %s contact.', $this->label()));
        }
        return current_user_can('manage_options') ? $contact : Masking::keys($contact, [ 'email', 'first_name', 'last_name', 'phone' ]);
    }

    private function contact_out(int $id): ?array
    {
        $row = $this->contact_row($id);
        if (null === $row) {
            return null;
        }
        $tags  = array_map('intval', FunnelKit_Automations_Snapshot::decode($row['tags'] ?? null));
        $lists = array_map('intval', FunnelKit_Automations_Snapshot::decode($row['lists'] ?? null));
        $name  = static fn (array $terms): array => array_map(static fn (array $t): array => [ 'id' => (int) $t['id'], 'name' => (string) $t['name'] ], $terms);
        return [
            'id'            => (int) $row['id'],
            'email'         => (string) $row['email'],
            'first_name'    => (string) ($row['f_name'] ?? ''),
            'last_name'     => (string) ($row['l_name'] ?? ''),
            'phone'         => (string) ($row['contact_no'] ?? ''),
            'country'       => (string) ($row['country'] ?? ''),
            'state'         => (string) ($row['state'] ?? ''),
            'timezone'      => (string) ($row['timezone'] ?? ''),
            'type'          => (string) ($row['type'] ?? ''),
            'source'        => (string) ($row['source'] ?? ''),
            'user_id'       => (int) ($row['wpid'] ?? 0),
            'status'        => $this->status_of($row),
            'tags'          => $name($this->terms('tag', $tags)),
            'lists'         => $name($this->terms('list', $lists)),
            'created'       => (string) $row['creation_date'],
            'last_modified' => (string) $row['last_modified'],
        ];
    }

    /**
     * Terms of one kind by id, in id order.
     *
     * @param int[] $ids
     * @return array<int, array{id: int, name: string}>
     */
    private function terms(string $kind, array $ids): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ([] === $ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the plugin's own table; $in holds only %d placeholders.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT ID, name FROM %i WHERE type = %d AND ID IN ($in) ORDER BY ID ASC", self::t('bwfan_terms'), self::TERM_TYPES[ $kind ], ...$ids), ARRAY_A);
        return array_map(static fn (array $row): array => [ 'id' => (int) $row['ID'], 'name' => (string) $row['name'] ], $rows);
    }

    private function list_terms(string $kind, string $plural, array $args): array
    {
        global $wpdb;

        [$page, $per_page, $limit, $offset] = self::paging($args);
        $where                              = $wpdb->prepare('type = %d', self::TERM_TYPES[ $kind ]);
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $where .= $wpdb->prepare(' AND name LIKE %s', '%' . $wpdb->esc_like(trim((string) $args['search'])) . '%');
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own tables; $where is built from prepared fragments only.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE {$where}", self::t('bwfan_terms')));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own tables; $where is built from prepared fragments only.
        $rows  = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE {$where} ORDER BY ID ASC LIMIT %d OFFSET %d", self::t('bwfan_terms'), $limit, $offset), ARRAY_A);
        $out   = [];
        foreach ($rows as $row) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own tables; $where is built from prepared fragments only.
            $count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE %i LIKE %s', self::t('bwf_contact'), $plural, '%' . $wpdb->esc_like('"' . (int) $row['ID'] . '"') . '%'));
            $out[] = [
                'id'       => (int) $row['ID'],
                'name'     => (string) $row['name'],
                'contacts' => $count,
                'created'  => (string) ($row['created_at'] ?? ''),
            ];
        }

        return [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            $plural    => $out,
        ];
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /** The snapshot target of one object. */
    private function target(string $kind, int $id): array
    {
        return [ 'object_type' => FunnelKit_Automations_Snapshot::TYPE, 'object_id' => FunnelKit_Automations_Snapshot::key($kind, $id) ];
    }

    /**
     * Run one write with the state it left recorded into its snapshot, also
     * when it throws part way, so a half-done write stays undoable.
     *
     * @param array<string, mixed> $context
     */
    private function tracked(array $context, callable $write): void
    {
        $operation_id = (string) ($context['operation_id'] ?? '');
        try {
            $write();
        } catch (Operation_Error $e) {
            FunnelKit_Automations_Snapshot::record($operation_id);
            throw new Operation_Error($e->error_code(), $e->getMessage(), $e->error_data() + [ 'operation_id' => $operation_id, 'recoverable' => '' !== $operation_id ]);
        }
        FunnelKit_Automations_Snapshot::record($operation_id);
    }

    private function set_automation_status(array $args, array $context): array
    {
        $id     = (int) $args['id'];
        $status = (int) array_search((string) $args['status'], self::AUTOMATION_STATUS, true);
        $this->tracked($context, fn () => $this->api_set_automation_status($id, $status));
        return [ 'automation' => $this->automation($id) ];
    }

    private function update_membership(string $kind, array $args, array $context): array
    {
        $id     = (int) $args['id'];
        $add    = array_map('intval', (array) ($args['add'] ?? []));
        $remove = array_map('intval', (array) ($args['remove'] ?? []));
        $this->tracked($context, function () use ($id, $kind, $add, $remove): void {
            if ([] !== $remove) {
                $this->api_detach($id, $kind, $remove);
            }
            if ([] !== $add) {
                $this->api_attach($id, $kind, $add);
            }
        });
        return [ 'contact' => $this->full_contact([ 'id' => $id ]) ];
    }

    /** A FunnelKit contact object, or an error when its contact API is not loaded. */
    private function crm_contact(int $id): object
    {
        if (! class_exists('\\BWFCRM_Contact')) {
            throw new Operation_Error('plugin_api_unavailable', sprintf('%s\'s contact API is not loaded on this site.', $this->label()));
        }
        $contact = new \BWFCRM_Contact($id);
        if (! $contact->is_contact_exists()) {
            throw new Operation_Error('contact_not_found', sprintf('No such %s contact.', $this->label()));
        }
        return $contact;
    }

    /**
     * Tag a contact or add it to lists through FunnelKit, the way its own
     * contact screen does, so its "added" hooks fire.
     *
     * @param int[] $ids
     */
    protected function api_attach(int $contact, string $kind, array $ids): void
    {
        $crm = $this->crm_contact($contact);
        $ids = array_map('strval', $ids);
        $set = 'tag' === $kind ? $crm->set_tags_v2($ids) : $crm->set_lists_v2($ids);
        if (false === $set) {
            throw new Operation_Error('plugin_refused', sprintf('%s refused the %s ids.', $this->label(), $kind));
        }
        $crm->save();
    }

    /**
     * Untag a contact or remove it from lists through FunnelKit, so its
     * "removed" hooks fire.
     *
     * @param int[] $ids
     */
    protected function api_detach(int $contact, string $kind, array $ids): void
    {
        $crm    = $this->crm_contact($contact);
        $ids    = array_map('strval', $ids);
        $result = 'tag' === $kind ? $crm->remove_tags($ids) : $crm->remove_lists($ids);
        if (is_wp_error($result)) {
            throw new Operation_Error('plugin_refused', $result->get_error_message());
        }
        $crm->save();
    }

    /** Set an automation's status through FunnelKit's automation model, the call its editor makes. */
    protected function api_set_automation_status(int $id, int $status): void
    {
        if (! class_exists('\\BWFAN_Model_Automations_V2')) {
            throw new Operation_Error('plugin_api_unavailable', sprintf('%s\'s automation model is not loaded on this site.', $this->label()));
        }
        if (false === \BWFAN_Model_Automations_V2::update_automation($id, [ 'status' => $status ])) {
            throw new Operation_Error('plugin_refused', sprintf('%s could not update automation %d.', $this->label(), $id));
        }
    }
}
