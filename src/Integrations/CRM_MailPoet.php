<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * MailPoet storage and API for the CRM ops (issue #477), as MailPoet 5.41
 * keeps it (checked against its source):
 *  - a subscriber is a row of {prefix}mailpoet_subscribers, its status one
 *    of subscribed, unconfirmed, unsubscribed, inactive or bounced, and a
 *    trashed one carries deleted_at;
 *  - list memberships are mailpoet_subscriber_segment rows with a status of
 *    their own (subscribed or unsubscribed) over mailpoet_segments rows of
 *    type 'default' (the WordPress and WooCommerce user lists are MailPoet's
 *    own and not writable here); tags are mailpoet_tags and
 *    mailpoet_subscriber_tag rows;
 *  - custom fields are mailpoet_custom_fields rows and their values
 *    mailpoet_subscriber_custom_field rows, named cf_<id> by MailPoet's API;
 *  - campaigns are mailpoet_newsletters rows of type 'standard', with their
 *    send in mailpoet_sending_queues and mailpoet_scheduled_tasks;
 *    automations are mailpoet_automations rows and their runs
 *    mailpoet_automation_runs rows.
 * Reads query those tables; every write goes through MailPoet's public API
 * (\MailPoet\API\API::MP('v1')), so its confirmation emails, welcome emails,
 * hooks and automation triggers run, and a new subscriber's status follows
 * MailPoet's own sign-up confirmation setting. MailPoet's subscribeToLists()
 * would resubscribe a subscriber who had unsubscribed, so a contact that is
 * suppressed, or has left one of the lists, is refused before it runs. A
 * delete moves the subscriber to MailPoet's trash, the way its admin
 * deletes one.
 */
class CRM_MailPoet extends CRM_Adapter
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    /** Access => MailPoet capability (MailPoet\Config\AccessControl). */
    private const CAPABILITIES = [
        'read'          => 'mailpoet_manage_subscribers',
        'write'         => 'mailpoet_manage_subscribers',
        'delete'        => 'mailpoet_manage_subscribers',
        'groups'        => 'mailpoet_manage_segments',
        'groups_delete' => 'mailpoet_manage_segments',
        'campaigns'     => 'mailpoet_manage_emails',
        'automations'   => 'mailpoet_manage_automations',
    ];

    public function slug(): string
    {
        return 'mailpoet';
    }

    public function label(): string
    {
        return 'MailPoet';
    }

    public function active(): bool
    {
        return (bool) apply_filters('wpmcp_mailpoet_active', defined('MAILPOET_VERSION') && class_exists('\\MailPoet\\API\\API'));
    }

    public function can(string $access): bool
    {
        $capability = self::CAPABILITIES[ $access ] ?? null;
        return null !== $capability && current_user_can($capability);
    }

    protected function statuses(): array
    {
        return [ 'subscribed', 'unconfirmed', 'unsubscribed', 'inactive', 'bounced' ];
    }

    protected function suppressed(): array
    {
        return [ 'unsubscribed', 'inactive', 'bounced' ];
    }

    protected function contact_kind(): string
    {
        return 'mailpoet_subscriber';
    }

    protected function contact_delete_kind(): string
    {
        return 'mailpoet_subscriber';
    }

    protected function create_status_schema(): ?array
    {
        return null; // MailPoet's sign-up confirmation setting decides.
    }

    protected function list_rejoin_refusal(int $contact, array $lists): ?array
    {
        global $wpdb;

        $in = implode(',', array_fill(0, count($lists), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- MailPoet's own table; $in holds only %d placeholders.
        $left = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT segment_id FROM %i WHERE subscriber_id = %d AND status = 'unsubscribed' AND segment_id IN ($in) ORDER BY segment_id ASC", self::t('mailpoet_subscriber_segment'), $contact, ...$lists)));
        if ([] === $left) {
            return null;
        }
        return [
            'code'    => 'contact_suppressed',
            'message' => sprintf('MailPoet subscriber %d unsubscribed from list(s) %s; it is not added back without its own consent.', $contact, implode(', ', $left)),
            'data'    => [ 'lists' => $left ],
        ];
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MailPoet's own tables, read live.
        $by_status   = self::counts((array) $wpdb->get_results($wpdb->prepare('SELECT status, COUNT(*) AS n FROM %i WHERE deleted_at IS NULL GROUP BY status', self::t('mailpoet_subscribers')), ARRAY_A), 'status');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MailPoet's own tables, read live.
        $campaigns   = self::has_table(self::t('mailpoet_newsletters')) ? self::counts((array) $wpdb->get_results($wpdb->prepare("SELECT status, COUNT(*) AS n FROM %i WHERE type = 'standard' AND deleted_at IS NULL GROUP BY status", self::t('mailpoet_newsletters')), ARRAY_A), 'status') : [];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MailPoet's own tables, read live.
        $automations = self::has_table(self::t('mailpoet_automations')) ? self::counts((array) $wpdb->get_results($wpdb->prepare('SELECT status, COUNT(*) AS n FROM %i WHERE deleted_at IS NULL GROUP BY status', self::t('mailpoet_automations')), ARRAY_A), 'status') : [];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MailPoet's own tables, read live.
        $confirm     = self::has_table(self::t('mailpoet_settings')) ? maybe_unserialize($wpdb->get_var($wpdb->prepare("SELECT value FROM %i WHERE name = 'signup_confirmation'", self::t('mailpoet_settings')))) : null;
        $confirm = is_array($confirm) ? ! empty($confirm['enabled']) : null;
        // MailPoet keeps a setting's default in code until it is saved once.
        if (class_exists('\\MailPoet\\Settings\\SettingsController')) {
            $confirm = (bool) \MailPoet\Settings\SettingsController::getInstance()->get('signup_confirmation.enabled');
        }

        return [
            'plugin'        => $this->label(),
            'version'       => defined('MAILPOET_VERSION') ? (string) MAILPOET_VERSION : null,
            'contacts'      => [ 'total' => array_sum($by_status), 'by_status' => $by_status ],
            'lists'         => count($this->groups('list')),
            'tags'          => count($this->groups('tag')),
            'custom_fields' => count($this->custom_fields()),
            'campaigns'     => $campaigns,
            'automations'   => $automations,
            'opt_in'        => [
                'signup_confirmation' => $confirm,
                'new_contact_status'  => false === $confirm ? 'subscribed' : 'unconfirmed',
            ],
            'campaign_send' => false,
        ];
    }

    protected function contact_page(array $filter, int $page, int $per_page): array
    {
        global $wpdb;

        $where = [ 's.deleted_at IS NULL' ];
        $args  = [];
        if (isset($filter['status'])) {
            $where[] = 's.status = %s';
            $args[]  = (string) $filter['status'];
        }
        if (isset($filter['search']) && '' !== trim((string) $filter['search'])) {
            $like    = '%' . $wpdb->esc_like(trim((string) $filter['search'])) . '%';
            $where[] = "(s.email LIKE %s OR s.first_name LIKE %s OR s.last_name LIKE %s OR CONCAT(s.first_name, ' ', s.last_name) LIKE %s)";
            array_push($args, $like, $like, $like, $like);
        }
        if (isset($filter['list_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM %i ss WHERE ss.subscriber_id = s.id AND ss.segment_id = %d)';
            array_push($args, self::t('mailpoet_subscriber_segment'), (int) $filter['list_id']);
        }
        if (isset($filter['tag_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM %i st WHERE st.subscriber_id = s.id AND st.tag_id = %d)';
            array_push($args, self::t('mailpoet_subscriber_tag'), (int) $filter['tag_id']);
        }
        $where            = implode(' AND ', $where);
        [$limit, $offset] = self::limit_offset($page, $per_page);
        $table            = self::t('mailpoet_subscribers');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- MailPoet's own table; $where holds only placeholders built above.
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i s WHERE ' . $where, $table, ...$args));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- MailPoet's own table; $where holds only placeholders built above.
        $rows  = (array) $wpdb->get_results($wpdb->prepare('SELECT s.id, s.email, s.first_name, s.last_name, s.status, s.created_at FROM %i s WHERE ' . $where . ' ORDER BY s.id DESC LIMIT %d OFFSET %d', $table, ...array_merge($args, [ $limit, $offset ])), ARRAY_A);

        return [ 'total' => $total, 'rows' => array_map([ self::class, 'contact_row' ], $rows) ];
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

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MailPoet's own tables, read live.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d AND deleted_at IS NULL', self::t('mailpoet_subscribers'), $id), ARRAY_A);
        if (! is_array($row)) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MailPoet's own tables, read live.
        $lists  = (array) $wpdb->get_results($wpdb->prepare('SELECT g.id, g.name, g.type, ss.status FROM %i ss JOIN %i g ON g.id = ss.segment_id WHERE ss.subscriber_id = %d ORDER BY g.id ASC', self::t('mailpoet_subscriber_segment'), self::t('mailpoet_segments'), $id), ARRAY_A);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MailPoet's own tables, read live.
        $tags   = (array) $wpdb->get_results($wpdb->prepare('SELECT t.id, t.name FROM %i st JOIN %i t ON t.id = st.tag_id WHERE st.subscriber_id = %d ORDER BY t.id ASC', self::t('mailpoet_subscriber_tag'), self::t('mailpoet_tags'), $id), ARRAY_A);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MailPoet's own tables, read live.
        $values = (array) $wpdb->get_results($wpdb->prepare('SELECT custom_field_id, value FROM %i WHERE subscriber_id = %d ORDER BY id ASC', self::t('mailpoet_subscriber_custom_field'), $id), ARRAY_A);

        $custom = [];
        foreach ($values as $value) {
            $custom[ 'cf_' . (int) $value['custom_field_id'] ] = (string) $value['value'];
        }

        return self::contact_row($row) + [
            'wp_user_id'         => isset($row['wp_user_id']) ? (int) $row['wp_user_id'] : null,
            'source'             => (string) ($row['source'] ?? ''),
            'confirmed_at'       => $row['confirmed_at'] ?? null,
            'last_subscribed_at' => $row['last_subscribed_at'] ?? null,
            'updated_at'         => $row['updated_at'] ?? null,
            'lists'              => array_map(static fn (array $list): array => [ 'id' => (int) $list['id'], 'title' => (string) $list['name'], 'type' => (string) $list['type'], 'status' => (string) $list['status'] ], $lists),
            'tags'               => array_map(static fn (array $tag): array => [ 'id' => (int) $tag['id'], 'title' => (string) $tag['name'] ], $tags),
            'custom_fields'      => $custom,
        ];
    }

    protected function contact_id_by_email(string $email): int
    {
        global $wpdb;

        // A trashed subscriber still holds its email in MailPoet.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MailPoet's own table, read live.
        return (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE email = %s', self::t('mailpoet_subscribers'), $email));
    }

    protected function groups(string $kind): array
    {
        global $wpdb;

        if ('list' === $kind) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MailPoet's own tables, read live.
            $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT g.id, g.name, g.description, (SELECT COUNT(*) FROM %i ss WHERE ss.segment_id = g.id AND ss.status = 'subscribed') AS contacts FROM %i g WHERE g.type = 'default' AND g.deleted_at IS NULL ORDER BY g.name ASC, g.id ASC", self::t('mailpoet_subscriber_segment'), self::t('mailpoet_segments')), ARRAY_A);
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MailPoet's own tables, read live.
            $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT g.id, g.name, g.description, (SELECT COUNT(*) FROM %i st WHERE st.tag_id = g.id) AS contacts FROM %i g ORDER BY g.name ASC, g.id ASC', self::t('mailpoet_subscriber_tag'), self::t('mailpoet_tags')), ARRAY_A);
        }

        return array_map(static fn (array $row): array => [
            'id'          => (int) $row['id'],
            'title'       => (string) $row['name'],
            'description' => (string) ($row['description'] ?? ''),
            'contacts'    => (int) $row['contacts'],
        ], $rows);
    }

    protected function group(string $kind, int $id): ?array
    {
        foreach ($this->groups($kind) as $group) {
            if ($group['id'] === $id) {
                return $group;
            }
        }
        return null;
    }

    protected function group_member_count(string $kind, int $id): int
    {
        global $wpdb;

        // Every membership row, unsubscribed ones included: the snapshot keeps them all.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MailPoet's own tables, read live.
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE %i = %d', self::t('list' === $kind ? 'mailpoet_subscriber_segment' : 'mailpoet_subscriber_tag'), 'list' === $kind ? 'segment_id' : 'tag_id', $id));
    }

    protected function custom_fields(): array
    {
        global $wpdb;

        if (! self::has_table(self::t('mailpoet_custom_fields'))) {
            return [];
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MailPoet's own table, read live.
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT id, name, type, params FROM %i WHERE deleted_at IS NULL ORDER BY id ASC', self::t('mailpoet_custom_fields')), ARRAY_A);
        $out  = [];
        foreach ($rows as $row) {
            $params = maybe_unserialize($row['params']);
            $key    = 'cf_' . (int) $row['id'];
            $out[ $key ] = [
                'key'      => $key,
                'label'    => (string) $row['name'],
                'type'     => (string) $row['type'],
                'required' => is_array($params) && ! empty($params['required']),
                'options'  => is_array($params) ? array_values(array_map(static fn ($value): string => (string) (is_array($value) ? ($value['value'] ?? '') : $value), (array) ($params['values'] ?? []))) : [],
            ];
        }
        return $out;
    }

    protected function campaign_statuses(): array
    {
        return [ 'draft', 'scheduled', 'sending', 'sent', 'active', 'paused' ];
    }

    protected function campaign_page(?string $status, int $page, int $per_page): array
    {
        global $wpdb;

        if (! self::has_table(self::t('mailpoet_newsletters'))) {
            return [ 'total' => 0, 'rows' => [] ];
        }
        [$limit, $offset] = self::limit_offset($page, $per_page);
        $where = "type = 'standard' AND deleted_at IS NULL" . (null === $status ? '' : ' AND status = %s');
        $args  = null === $status ? [] : [ $status ];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- MailPoet's own table; $where is one of two literals.
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE ' . $where, self::t('mailpoet_newsletters'), ...$args));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- MailPoet's own table; $where is one of two literals.
        $rows  = (array) $wpdb->get_results($wpdb->prepare('SELECT id, subject, status, sent_at, created_at, updated_at FROM %i WHERE ' . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d', self::t('mailpoet_newsletters'), ...array_merge($args, [ $limit, $offset ])), ARRAY_A);

        return [
            'total' => $total,
            'rows'  => array_map(fn (array $row): array => [
                'id'           => (int) $row['id'],
                'subject'      => (string) $row['subject'],
                'status'       => (string) $row['status'],
                'scheduled_at' => $this->scheduled_at((int) $row['id']),
                'sent_at'      => $row['sent_at'] ?? null,
                'recipients'   => $this->recipients((int) $row['id']),
                'lists'        => $this->campaign_lists((int) $row['id']),
                'created_at'   => $row['created_at'] ?? null,
                'updated_at'   => $row['updated_at'] ?? null,
            ], $rows),
        ];
    }

    /** When a campaign's send is scheduled, or null. */
    private function scheduled_at(int $id): ?string
    {
        global $wpdb;

        if (! self::has_table(self::t('mailpoet_sending_queues')) || ! self::has_table(self::t('mailpoet_scheduled_tasks'))) {
            return null;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MailPoet's own tables, read live.
        $at = $wpdb->get_var($wpdb->prepare('SELECT t.scheduled_at FROM %i q JOIN %i t ON t.id = q.task_id WHERE q.newsletter_id = %d ORDER BY q.id DESC LIMIT 1', self::t('mailpoet_sending_queues'), self::t('mailpoet_scheduled_tasks'), $id));
        return null === $at ? null : (string) $at;
    }

    /**
     * How many recipients a campaign has: its queue's total once MailPoet
     * queued it, otherwise how many subscribed contacts its lists hold now.
     */
    private function recipients(int $id): int
    {
        global $wpdb;

        if (self::has_table(self::t('mailpoet_sending_queues'))) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MailPoet's own tables, read live.
            $queued = $wpdb->get_var($wpdb->prepare('SELECT count_total FROM %i WHERE newsletter_id = %d ORDER BY id DESC LIMIT 1', self::t('mailpoet_sending_queues'), $id));
            if (null !== $queued) {
                return (int) $queued;
            }
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MailPoet's own tables, read live.
        $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT s.id) FROM %i ns JOIN %i ss ON ss.segment_id = ns.segment_id AND ss.status = 'subscribed' JOIN %i s ON s.id = ss.subscriber_id AND s.status = 'subscribed' AND s.deleted_at IS NULL WHERE ns.newsletter_id = %d", self::t('mailpoet_newsletter_segment'), self::t('mailpoet_subscriber_segment'), self::t('mailpoet_subscribers'), $id));
        return $count;
    }

    /** @return int[] the lists a campaign goes to */
    private function campaign_lists(int $id): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MailPoet's own table, read live.
        return array_map('intval', (array) $wpdb->get_col($wpdb->prepare('SELECT segment_id FROM %i WHERE newsletter_id = %d ORDER BY segment_id ASC', self::t('mailpoet_newsletter_segment'), $id)));
    }

    protected function automations(): array
    {
        global $wpdb;

        if (! self::has_table(self::t('mailpoet_automations'))) {
            return [];
        }
        $runs = self::has_table(self::t('mailpoet_automation_runs'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MailPoet's own tables, read live.
        $rows   = (array) $wpdb->get_results($wpdb->prepare('SELECT id, name, status, created_at, activated_at FROM %i WHERE deleted_at IS NULL ORDER BY id DESC', self::t('mailpoet_automations')), ARRAY_A);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MailPoet's own tables, read live.
        $counts = $runs ? (array) $wpdb->get_results($wpdb->prepare("SELECT automation_id, COUNT(*) AS total, SUM(status = 'running') AS active FROM %i GROUP BY automation_id", self::t('mailpoet_automation_runs')), ARRAY_A) : [];
        $counts = array_column($counts, null, 'automation_id');

        return array_map(static fn (array $row): array => [
            'id'             => (int) $row['id'],
            'title'          => (string) $row['name'],
            'status'         => (string) $row['status'],
            'enrolled'       => (int) ($counts[ $row['id'] ]['active'] ?? 0),
            'enrolled_total' => (int) ($counts[ $row['id'] ]['total'] ?? 0),
            'created_at'     => $row['created_at'] ?? null,
            'activated_at'   => $row['activated_at'] ?? null,
        ], $rows);
    }

    // ------------------------------------------------------------------
    // Writes, through MailPoet's public API
    // ------------------------------------------------------------------

    /**
     * Call MailPoet's API, turning its refusal into the op's error.
     *
     * @param callable(\MailPoet\API\MP\v1\API): mixed $call
     * @return mixed
     */
    private static function api(callable $call)
    {
        try {
            return $call(\MailPoet\API\API::MP('v1'));
        } catch (\MailPoet\API\MP\v1\APIException $e) {
            throw new Operation_Error('mailpoet_refused', $e->getMessage(), [ 'mailpoet_code' => (int) $e->getCode() ]);
        } finally {
            \WPMCP\Safety\Crm_Rows_Snapshot::flush('mailpoet');
        }
    }

    protected function api_create_contact(string $email, array $fields, array $custom, array $lists, array $tags, string $status): int
    {
        $created = self::api(static fn ($mp) => $mp->addSubscriber([ 'email' => $email ] + $fields + $custom, $lists));
        $id      = (int) ($created['id'] ?? 0);
        foreach ($tags as $tag) {
            self::api(static fn ($mp) => $mp->tagSubscriber($id, (int) $tag));
        }
        return $id;
    }

    protected function api_update_contact(int $id, array $fields, array $custom): void
    {
        self::api(static fn ($mp) => $mp->updateSubscriber($id, $fields + $custom));
    }

    protected function api_unsubscribe(int $id): void
    {
        self::api(static fn ($mp) => $mp->unsubscribe($id));
    }

    protected function api_delete_contact(int $id): void
    {
        $repository = \MailPoet\DI\ContainerWrapper::getInstance()->get(\MailPoet\Subscribers\SubscribersRepository::class);
        $repository->bulkTrash([ $id ]);
        \WPMCP\Safety\Crm_Rows_Snapshot::flush('mailpoet');
    }

    protected function api_attach(int $contact, string $kind, array $ids): void
    {
        if ('list' === $kind) {
            self::api(static fn ($mp) => $mp->subscribeToLists($contact, $ids));
            return;
        }
        foreach ($ids as $tag) {
            self::api(static fn ($mp) => $mp->tagSubscriber($contact, (int) $tag));
        }
    }

    protected function api_detach(int $contact, string $kind, array $ids): void
    {
        if ('list' === $kind) {
            self::api(static fn ($mp) => $mp->unsubscribeFromLists($contact, $ids));
            return;
        }
        foreach ($ids as $tag) {
            self::api(static fn ($mp) => $mp->untagSubscriber($contact, (int) $tag));
        }
    }

    protected function api_create_group(string $kind, string $title, string $description): int
    {
        $data  = [ 'name' => $title, 'description' => $description ];
        $group = self::api(static fn ($mp) => 'list' === $kind ? $mp->addList($data) : $mp->addTag($data));
        return (int) ($group['id'] ?? 0);
    }

    protected function api_update_group(string $kind, int $id, array $changes): void
    {
        $current = (array) $this->group($kind, $id);
        $data    = [
            'id'          => $id,
            'name'        => (string) ($changes['title'] ?? $current['title'] ?? ''),
            'description' => (string) ($changes['description'] ?? $current['description'] ?? ''),
        ];
        self::api(static fn ($mp) => 'list' === $kind ? $mp->updateList($data) : $mp->updateTag($data));
    }

    protected function api_delete_group(string $kind, int $id): void
    {
        self::api(static fn ($mp) => 'list' === $kind ? $mp->deleteList((string) $id) : $mp->deleteTag((string) $id));
    }
}
