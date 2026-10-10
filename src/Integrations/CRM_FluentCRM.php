<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * FluentCRM storage and API for the CRM ops (issue #477), as FluentCRM 3.2
 * keeps it (checked against its source):
 *  - a contact is a row of {prefix}fc_subscribers, its status one of
 *    subscribed, pending, unsubscribed, transactional, bounced, complained
 *    or spammed;
 *  - list and tag memberships are rows of {prefix}fc_subscriber_pivot whose
 *    object_type is the Lists or Tag model class;
 *  - custom field definitions are the 'contact_custom_fields' option in
 *    {prefix}fc_meta, and a contact's values are fc_subscriber_meta rows of
 *    object_type 'custom_field', keyed by the field's slug;
 *  - campaigns are fc_campaigns rows of type 'campaign', automations are
 *    fc_funnels rows and their enrollments fc_funnel_subscribers rows.
 * Reads query those tables; every write goes through FluentCRM's own API
 * (FluentCrmApi('contacts'), the Subscriber, Lists and Tag models and
 * Helper::deleteContacts()), so its hooks, automations and the
 * contact_added_to_lists / contact_added_to_tags triggers fire. FluentCRM's
 * own contact update never lifts a suppressed status unless forced, and
 * nothing here forces it. A new contact is pending and is sent FluentCRM's
 * double opt-in email.
 */
class CRM_FluentCRM extends CRM_Adapter
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    private const LISTS = 'FluentCrm\\App\\Models\\Lists';

    private const TAGS = 'FluentCrm\\App\\Models\\Tag';

    /** Access => FluentCRM permission (PermissionManager::pluginPermissions()). */
    private const PERMISSIONS = [
        'read'          => 'fcrm_read_contacts',
        'write'         => 'fcrm_manage_contacts',
        'delete'        => 'fcrm_manage_contacts_delete',
        'groups'        => 'fcrm_manage_contact_cats',
        'groups_delete' => 'fcrm_manage_contact_cats_delete',
        'campaigns'     => 'fcrm_read_emails',
        'automations'   => 'fcrm_read_funnels',
    ];

    public function slug(): string
    {
        return 'fluentcrm';
    }

    public function label(): string
    {
        return 'FluentCRM';
    }

    public function active(): bool
    {
        return (bool) apply_filters('wpmcp_fluentcrm_active', defined('FLUENTCRM') && function_exists('FluentCrmApi'));
    }

    public function can(string $access): bool
    {
        $permission = self::PERMISSIONS[ $access ] ?? null;
        if (null === $permission) {
            return false;
        }
        if (class_exists('\\FluentCrm\\App\\Services\\PermissionManager')) {
            return (bool) \FluentCrm\App\Services\PermissionManager::currentUserCan($permission);
        }
        return current_user_can('manage_options');
    }

    protected function statuses(): array
    {
        return [ 'subscribed', 'pending', 'unsubscribed', 'transactional', 'bounced', 'complained', 'spammed' ];
    }

    protected function suppressed(): array
    {
        return function_exists('fluentcrm_strict_statues') ? array_values((array) fluentcrm_strict_statues()) : [ 'unsubscribed', 'bounced', 'complained', 'spammed' ];
    }

    protected function contact_kind(): string
    {
        return 'fluentcrm_contact';
    }

    protected function contact_delete_kind(): string
    {
        return 'fluentcrm_contact_full';
    }

    protected function create_status_schema(): ?array
    {
        return [ 'type' => 'string', 'enum' => [ 'pending', 'subscribed' ] ];
    }

    protected function list_rejoin_refusal(int $contact, array $lists): ?array
    {
        return null; // FluentCRM keeps one subscription status per contact, checked by the caller.
    }

    private static function t(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    protected function summary(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- FluentCRM's own tables, read live.
        $by_status = self::counts((array) $wpdb->get_results($wpdb->prepare('SELECT status, COUNT(*) AS n FROM %i GROUP BY status', self::t('fc_subscribers')), ARRAY_A), 'status');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- FluentCRM's own tables, read live.
        $campaigns = self::has_table(self::t('fc_campaigns')) ? self::counts((array) $wpdb->get_results($wpdb->prepare("SELECT status, COUNT(*) AS n FROM %i WHERE type = 'campaign' GROUP BY status", self::t('fc_campaigns')), ARRAY_A), 'status') : [];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- FluentCRM's own tables, read live.
        $funnels   = self::has_table(self::t('fc_funnels')) ? self::counts((array) $wpdb->get_results($wpdb->prepare('SELECT status, COUNT(*) AS n FROM %i GROUP BY status', self::t('fc_funnels')), ARRAY_A), 'status') : [];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- FluentCRM's own tables, read live.
        $lists     = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', self::t('fc_lists')));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- FluentCRM's own tables, read live.
        $tags      = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', self::t('fc_tags')));

        return [
            'plugin'        => $this->label(),
            'version'       => defined('FLUENTCRM_PLUGIN_VERSION') ? (string) FLUENTCRM_PLUGIN_VERSION : null,
            'contacts'      => [ 'total' => array_sum($by_status), 'by_status' => $by_status ],
            'lists'         => $lists,
            'tags'          => $tags,
            'custom_fields' => count($this->custom_fields()),
            'campaigns'     => $campaigns,
            'automations'   => $funnels,
            'opt_in'        => [
                'new_contact_status' => 'pending',
                'double_optin_email' => true,
                'direct_subscribe'   => $this->direct_subscribe_allowed(),
            ],
            'campaign_send' => false,
        ];
    }

    protected function contact_page(array $filter, int $page, int $per_page): array
    {
        global $wpdb;

        [$where, $args] = $this->contact_where($filter);
        [$limit, $offset] = self::limit_offset($page, $per_page);
        $table = self::t('fc_subscribers');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- FluentCRM's own table; $where holds only placeholders built in contact_where().
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i s WHERE ' . $where, $table, ...$args));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- FluentCRM's own table; $where holds only placeholders built in contact_where().
        $rows  = (array) $wpdb->get_results($wpdb->prepare('SELECT s.id, s.email, s.first_name, s.last_name, s.status, s.created_at FROM %i s WHERE ' . $where . ' ORDER BY s.id DESC LIMIT %d OFFSET %d', $table, ...array_merge($args, [ $limit, $offset ])), ARRAY_A);

        return [ 'total' => $total, 'rows' => array_map([ self::class, 'contact_row' ], $rows) ];
    }

    /** @return array{0: string, 1: array<int, mixed>} */
    private function contact_where(array $filter): array
    {
        global $wpdb;

        $where = [ '1 = 1' ];
        $args  = [];
        if (isset($filter['status'])) {
            $where[] = 's.status = %s';
            $args[]  = (string) $filter['status'];
        }
        if (isset($filter['search']) && '' !== trim((string) $filter['search'])) {
            $like    = '%' . $wpdb->esc_like(trim((string) $filter['search'])) . '%';
            $where[] = "(s.email LIKE %s OR s.first_name LIKE %s OR s.last_name LIKE %s OR CONCAT(COALESCE(s.first_name, ''), ' ', COALESCE(s.last_name, '')) LIKE %s)";
            array_push($args, $like, $like, $like, $like);
        }
        foreach ([ 'list_id' => self::LISTS, 'tag_id' => self::TAGS ] as $key => $type) {
            if (isset($filter[ $key ])) {
                $where[] = 'EXISTS (SELECT 1 FROM %i p WHERE p.subscriber_id = s.id AND p.object_type = %s AND p.object_id = %d)';
                array_push($args, self::t('fc_subscriber_pivot'), $type, (int) $filter[ $key ]);
            }
        }
        return [ implode(' AND ', $where), $args ];
    }

    /** @param array<string, mixed> $row */
    private static function contact_row(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'email'      => (string) $row['email'],
            'first_name' => (string) ($row['first_name'] ?? ''),
            'last_name'  => (string) ($row['last_name'] ?? ''),
            'status'     => (string) $row['status'],
            'created_at' => $row['created_at'] ?? null,
        ];
    }

    public function contact(int $id): ?array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- FluentCRM's own tables, read live.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::t('fc_subscribers'), $id), ARRAY_A);
        if (! is_array($row)) {
            return null;
        }
        $member = static fn (string $table, string $type): array => array_map(
            static fn (array $group): array => [ 'id' => (int) $group['id'], 'title' => (string) $group['title'] ],
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- FluentCRM's own tables, read live.
            (array) $wpdb->get_results($wpdb->prepare('SELECT g.id, g.title FROM %i p JOIN %i g ON g.id = p.object_id WHERE p.subscriber_id = %d AND p.object_type = %s ORDER BY g.id ASC', self::t('fc_subscriber_pivot'), self::t($table), $id, $type), ARRAY_A)
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- FluentCRM's own tables, read live.
        $meta = (array) $wpdb->get_results($wpdb->prepare("SELECT `key`, `value` FROM %i WHERE subscriber_id = %d AND object_type = 'custom_field' ORDER BY id ASC", self::t('fc_subscriber_meta'), $id), ARRAY_A);

        $custom = [];
        foreach ($meta as $value) {
            $custom[ (string) $value['key'] ] = maybe_unserialize($value['value']);
        }

        return self::contact_row($row) + [
            'phone'         => (string) ($row['phone'] ?? ''),
            'contact_type'  => (string) ($row['contact_type'] ?? ''),
            'source'        => (string) ($row['source'] ?? ''),
            'user_id'       => isset($row['user_id']) ? (int) $row['user_id'] : null,
            'updated_at'    => $row['updated_at'] ?? null,
            'last_activity' => $row['last_activity'] ?? null,
            'lists'         => $member('fc_lists', self::LISTS),
            'tags'          => $member('fc_tags', self::TAGS),
            'custom_fields' => $custom,
        ];
    }

    protected function contact_id_by_email(string $email): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- FluentCRM's own table, read live.
        return (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE email = %s', self::t('fc_subscribers'), $email));
    }

    protected function groups(string $kind): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- FluentCRM's own tables, read live.
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT g.*, (SELECT COUNT(*) FROM %i p WHERE p.object_type = %s AND p.object_id = g.id) AS contacts FROM %i g ORDER BY g.title ASC, g.id ASC', self::t('fc_subscriber_pivot'), $this->group_type($kind), $this->group_table($kind)), ARRAY_A);
        return array_map([ self::class, 'group_row' ], $rows);
    }

    protected function group(string $kind, int $id): ?array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- FluentCRM's own tables, read live.
        $row = $wpdb->get_row($wpdb->prepare('SELECT g.*, (SELECT COUNT(*) FROM %i p WHERE p.object_type = %s AND p.object_id = g.id) AS contacts FROM %i g WHERE g.id = %d', self::t('fc_subscriber_pivot'), $this->group_type($kind), $this->group_table($kind), $id), ARRAY_A);
        return is_array($row) ? self::group_row($row) : null;
    }

    protected function group_member_count(string $kind, int $id): int
    {
        return (int) ($this->group($kind, $id)['contacts'] ?? 0);
    }

    /** @param array<string, mixed> $row */
    private static function group_row(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'title'       => (string) $row['title'],
            'slug'        => (string) $row['slug'],
            'description' => (string) ($row['description'] ?? ''),
            'contacts'    => (int) $row['contacts'],
        ];
    }

    private function group_table(string $kind): string
    {
        return self::t('list' === $kind ? 'fc_lists' : 'fc_tags');
    }

    private function group_type(string $kind): string
    {
        return 'list' === $kind ? self::LISTS : self::TAGS;
    }

    protected function custom_fields(): array
    {
        global $wpdb;

        if (! self::has_table(self::t('fc_meta'))) {
            return [];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- FluentCRM's own options table, read live.
        $value  = $wpdb->get_var($wpdb->prepare("SELECT `value` FROM %i WHERE object_type = 'option' AND `key` = 'contact_custom_fields' ORDER BY id ASC LIMIT 1", self::t('fc_meta')));
        $fields = maybe_unserialize($value);
        $out    = [];
        foreach (is_array($fields) ? $fields : [] as $field) {
            if (! is_array($field) || empty($field['slug'])) {
                continue;
            }
            $out[ (string) $field['slug'] ] = [
                'key'     => (string) $field['slug'],
                'label'   => (string) ($field['label'] ?? $field['slug']),
                'type'    => (string) ($field['type'] ?? 'text'),
                'options' => array_values((array) ($field['options'] ?? [])),
            ];
        }
        return $out;
    }

    protected function campaign_statuses(): array
    {
        return [ 'draft', 'pending', 'scheduled', 'working', 'paused', 'archived', 'incomplete' ];
    }

    protected function campaign_page(?string $status, int $page, int $per_page): array
    {
        global $wpdb;

        if (! self::has_table(self::t('fc_campaigns'))) {
            return [ 'total' => 0, 'rows' => [] ];
        }
        [$limit, $offset] = self::limit_offset($page, $per_page);
        $where = "type = 'campaign'" . (null === $status ? '' : ' AND status = %s');
        $args  = null === $status ? [] : [ $status ];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- FluentCRM's own table; $where is one of two literals.
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE ' . $where, self::t('fc_campaigns'), ...$args));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- FluentCRM's own table; $where is one of two literals.
        $rows  = (array) $wpdb->get_results($wpdb->prepare('SELECT id, title, email_subject, status, scheduled_at, recipients_count, created_at, updated_at FROM %i WHERE ' . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d', self::t('fc_campaigns'), ...array_merge($args, [ $limit, $offset ])), ARRAY_A);

        return [
            'total' => $total,
            'rows'  => array_map(static fn (array $row): array => [
                'id'           => (int) $row['id'],
                'title'        => (string) $row['title'],
                'subject'      => (string) ($row['email_subject'] ?? ''),
                'status'       => (string) $row['status'],
                'scheduled_at' => $row['scheduled_at'] ?? null,
                'recipients'   => (int) $row['recipients_count'],
                'created_at'   => $row['created_at'] ?? null,
                'updated_at'   => $row['updated_at'] ?? null,
            ], $rows),
        ];
    }

    protected function automations(): array
    {
        global $wpdb;

        if (! self::has_table(self::t('fc_funnels'))) {
            return [];
        }
        $enrolled = self::has_table(self::t('fc_funnel_subscribers'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- FluentCRM's own tables, read live.
        $rows   = (array) $wpdb->get_results($wpdb->prepare('SELECT id, title, trigger_name, status, created_at FROM %i ORDER BY id DESC', self::t('fc_funnels')), ARRAY_A);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- FluentCRM's own tables, read live.
        $counts = $enrolled ? (array) $wpdb->get_results($wpdb->prepare("SELECT funnel_id, COUNT(*) AS total, SUM(status = 'active') AS active FROM %i GROUP BY funnel_id", self::t('fc_funnel_subscribers')), ARRAY_A) : [];
        $counts = array_column($counts, null, 'funnel_id');

        return array_map(static fn (array $row): array => [
            'id'             => (int) $row['id'],
            'title'          => (string) $row['title'],
            'trigger'        => (string) ($row['trigger_name'] ?? ''),
            'status'         => (string) $row['status'],
            'enrolled'       => (int) ($counts[ $row['id'] ]['active'] ?? 0),
            'enrolled_total' => (int) ($counts[ $row['id'] ]['total'] ?? 0),
            'created_at'     => $row['created_at'] ?? null,
        ], $rows);
    }

    // ------------------------------------------------------------------
    // Writes, through FluentCRM's own API
    // ------------------------------------------------------------------

    /** @return \FluentCrm\App\Models\Subscriber */
    private static function subscriber(int $id)
    {
        $contact = \FluentCrm\App\Models\Subscriber::find($id);
        if (null === $contact) {
            throw new Operation_Error('contact_not_found', sprintf('FluentCRM contact %d does not exist.', $id));
        }
        return $contact;
    }

    protected function api_create_contact(string $email, array $fields, array $custom, array $lists, array $tags, string $status): int
    {
        $data = [ 'email' => $email, 'status' => $status ] + $fields;
        // Always present, so FluentCRM does not read custom values out of the top-level keys.
        $data['custom_values'] = $custom;
        if ([] !== $lists) {
            $data['lists'] = $lists;
        }
        if ([] !== $tags) {
            $data['tags'] = $tags;
        }
        $contact = FluentCrmApi('contacts')->createOrUpdate($data, false, false);
        if (! is_object($contact) || empty($contact->id)) {
            throw new Operation_Error('write_failed', 'FluentCRM did not create the contact.');
        }
        if ('subscribed' !== $contact->status) {
            $contact->sendDoubleOptinEmail();
        }
        return (int) $contact->id;
    }

    protected function api_update_contact(int $id, array $fields, array $custom): void
    {
        $contact               = self::subscriber($id);
        $data                  = [ 'email' => (string) $contact->email ] + $fields;
        $data['custom_values'] = $custom;
        FluentCrmApi('contacts')->createOrUpdate($data, false, false);
    }

    protected function api_unsubscribe(int $id): void
    {
        self::subscriber($id)->updateStatus('unsubscribed');
    }

    protected function api_delete_contact(int $id): void
    {
        \FluentCrm\App\Services\Helper::deleteContacts([ $id ]);
    }

    protected function api_attach(int $contact, string $kind, array $ids): void
    {
        $subscriber = self::subscriber($contact);
        'list' === $kind ? $subscriber->attachLists($ids) : $subscriber->attachTags($ids);
    }

    protected function api_detach(int $contact, string $kind, array $ids): void
    {
        $subscriber = self::subscriber($contact);
        'list' === $kind ? $subscriber->detachLists($ids) : $subscriber->detachTags($ids);
    }

    /** @return class-string */
    private static function model(string $kind): string
    {
        return 'list' === $kind ? '\\FluentCrm\\App\\Models\\Lists' : '\\FluentCrm\\App\\Models\\Tag';
    }

    protected function api_create_group(string $kind, string $title, string $description): int
    {
        $model = self::model($kind);
        $slug  = sanitize_title($title, 'display');
        $base  = '' === $slug ? $kind : $slug;
        $slug  = $base;
        for ($n = 2; null !== $model::where('slug', $slug)->first(); ++$n) {
            $slug = $base . '-' . $n;
        }
        $group = $model::create([ 'title' => $title, 'slug' => $slug, 'description' => $description ]);
        do_action("fluentcrm_{$kind}_created", $group->id); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentCRM's own hook, fired as its admin fires it.
        do_action("fluent_crm/{$kind}_created", $group); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentCRM's own hook, fired as its admin fires it.
        return (int) $group->id;
    }

    protected function api_update_group(string $kind, int $id, array $changes): void
    {
        $model = self::model($kind);
        $model::where('id', $id)->update($changes);
        do_action("fluentcrm_{$kind}_updated", $id); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentCRM's own hook, fired as its admin fires it.
        do_action("fluent_crm/{$kind}_updated", $model::find($id)); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentCRM's own hook, fired as its admin fires it.
    }

    protected function api_delete_group(string $kind, int $id): void
    {
        $model = self::model($kind);
        $model::where('id', $id)->delete();
        do_action("fluent_crm/{$kind}_deleted", $id); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentCRM's own hook, fired as its admin fires it.
        do_action("fluentcrm_{$kind}_deleted", $id); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentCRM's own hook, fired as its admin fires it.
    }
}
