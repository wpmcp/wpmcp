<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Helpdesk_Ticket_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Fluent Support tickets (issue #708) on the plugin-data pair.
 *
 * Fluent Support 2.5 keeps a ticket as a row of {prefix}fs_tickets
 * (status new, active or closed; priority; customer_id and agent_id naming
 * rows of {prefix}fs_persons, where customers and agents both live) and its
 * thread as rows of {prefix}fs_conversations (conversation_type response,
 * note, internal_info for its own activity lines, plus draft_response and
 * schedule rows that are not part of the thread). Reads come straight from
 * those tables, kept to the tickets the plugin lets the current user see:
 * everything for an administrator, otherwise its own agent visibility
 * (all tickets, assigned and unassigned, or assigned only) and mailbox
 * restrictions. The ticket's hash and secret_content are never returned.
 *
 * Writes go through the plugin's own services as the current user's agent
 * record (fs_persons, person_type agent), the way its ticket screen does:
 * ResponseService::createResponse() for a reply (which emails the customer
 * through its response_added_by_agent hook) and for a note, and
 * TicketService for a status change. A close runs silently, so neither the
 * closing email nor the attachment purge on close (del_files_on_close) runs
 * and the close stays undoable; a reopen sends no mail anyway. Writes need
 * the plugin's ticket management permission and access to that ticket.
 *
 * Presence is filterable through wpmcp_fluent_support_active (default:
 * FLUENT_SUPPORT_VERSION is defined).
 */
class Helpdesk_Fluent_Support extends Helpdesk_Adapter
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads Fluent Support's own tables live; there is no read API to cache behind outside its REST controllers.
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    /** list-tickets status filter => the statuses it covers (Helper::ticketStatusGroups()). */
    private const GROUPS = [
        'open'   => [ 'new', 'active' ],
        'new'    => [ 'new' ],
        'active' => [ 'active' ],
        'closed' => [ 'closed' ],
        'all'    => [],
    ];

    /** Conversation types that are not part of the thread. */
    private const HIDDEN_TYPES = [ 'draft_response', 'schedule' ];

    public function slug(): string
    {
        return 'fluent-support';
    }

    public function label(): string
    {
        return 'Fluent Support';
    }

    public function kind(): string
    {
        return 'fluent_support';
    }

    public function active(): bool
    {
        return (bool) apply_filters('wpmcp_fluent_support_active', defined('FLUENT_SUPPORT_VERSION'));
    }

    public function statuses(): array
    {
        return [ 'new', 'active', 'closed' ];
    }

    public function status_filters(): array
    {
        return array_keys(self::GROUPS);
    }

    public function supports_notes(): bool
    {
        return true;
    }

    protected static function table(string $name): string
    {
        return Helpdesk_Ticket_Snapshot::fs_table($name);
    }

    // ------------------------------------------------------------ access

    /** The current user's Fluent Support agent person id, or 0. */
    protected function agent_id(): int
    {
        global $wpdb;
        $user = get_current_user_id();
        if ($user <= 0) {
            return 0;
        }
        return (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM %i WHERE user_id = %d AND person_type = 'agent' ORDER BY id ASC LIMIT 1", self::table('persons'), $user));
    }

    /**
     * Which tickets the current user may see: null for none, ['all' =>
     * true], or the agent's own scope (agent id, whether unassigned ones
     * count, mailboxes kept from them).
     *
     * @return array{all?: bool, agent?: int, unassigned?: bool, exclude_mailboxes?: int[]}|null
     */
    protected function scope(): ?array
    {
        if (current_user_can('manage_options')) {
            return [ 'all' => true ];
        }
        $manager = '\\FluentSupport\\App\\Modules\\PermissionManager';
        $agent   = $this->agent_id();
        if ($agent <= 0 || ! class_exists($manager) || ! $manager::userCan([ 'fst_view_tickets', 'fst_manage_own_tickets', 'fst_manage_unassigned_tickets', 'fst_manage_other_tickets', 'fst_draft_reply' ])) {
            return null;
        }
        $visibility = (string) $manager::getAgentTicketVisibility(get_current_user_id());
        $exclude    = array_map('intval', (array) $manager::getRestrictedMailboxIds());
        if (defined($manager . '::VISIBILITY_ALL') && constant($manager . '::VISIBILITY_ALL') === $visibility) {
            return [ 'all' => true, 'exclude_mailboxes' => $exclude ];
        }
        $unassigned = defined($manager . '::VISIBILITY_ASSIGNED_AND_UNASSIGNED') && constant($manager . '::VISIBILITY_ASSIGNED_AND_UNASSIGNED') === $visibility;
        return [ 'agent' => $agent, 'unassigned' => $unassigned, 'exclude_mailboxes' => $exclude ];
    }

    /** Whether the current user may manage (reply, note, status) tickets at all. */
    protected function may_manage(): bool
    {
        $manager = '\\FluentSupport\\App\\Modules\\PermissionManager';
        return current_user_can('manage_options') || (class_exists($manager) && $manager::canManageTickets());
    }

    /** SQL and args restricting tickets alias t to a scope. @return array{0: string, 1: array} */
    private static function scope_sql(array $scope): array
    {
        $sql  = '';
        $args = [];
        if (empty($scope['all'])) {
            $sql   .= ' AND (t.agent_id = %d' . (empty($scope['unassigned']) ? '' : ' OR t.agent_id IS NULL OR t.agent_id = 0') . ')';
            $args[] = (int) ($scope['agent'] ?? 0);
        }
        $exclude = array_values(array_filter(array_map('intval', (array) ($scope['exclude_mailboxes'] ?? []))));
        if ([] !== $exclude) {
            $sql .= ' AND (t.mailbox_id IS NULL OR t.mailbox_id NOT IN (' . implode(',', array_fill(0, count($exclude), '%d')) . '))';
            $args = array_merge($args, $exclude);
        }
        return [ $sql, $args ];
    }

    protected function list_refusal(): ?array
    {
        if (null !== $this->scope()) {
            return null;
        }
        return self::refusal('operation_denied', 'You are not a Fluent Support agent who may see tickets.', [ 'reason' => 'capability' ]);
    }

    protected function ticket_refusal(int $id, bool $write): ?array
    {
        global $wpdb;
        $scope = $this->scope();
        $found = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE id = %d', self::table('tickets'), $id));
        if (0 === $found) {
            return self::refusal('ticket_not_found', sprintf('%d is not a Fluent Support ticket.', $id), [ 'ticket_id' => $id ]);
        }
        $visible = false;
        if (null !== $scope) {
            [$sql, $args] = self::scope_sql($scope);
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $sql holds only %d placeholders built by scope_sql().
            $visible = 1 === (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i t WHERE t.id = %d{$sql}", self::table('tickets'), $id, ...$args));
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        }
        if (! $visible) {
            return self::refusal('operation_denied', sprintf('Fluent Support does not let you see ticket %d.', $id), [ 'reason' => 'capability', 'ticket_id' => $id ]);
        }
        if ($write && ! $this->may_manage()) {
            return self::refusal('operation_denied', 'This needs Fluent Support\'s permission to manage tickets.', [ 'reason' => 'capability' ]);
        }
        if ($write && $this->agent_id() <= 0) {
            return self::refusal('not_an_agent', 'Your user is not a Fluent Support agent. Add it under Fluent Support > Settings > Agents, then try again.', [ 'reason' => 'agent' ]);
        }
        return null;
    }

    // ------------------------------------------------------------ reads

    private static function person(?array $row, string $prefix): ?array
    {
        if (null === $row || empty($row[ $prefix . 'id' ])) {
            return null;
        }
        return [
            'id'    => (int) $row[ $prefix . 'id' ],
            'name'  => trim((string) $row[ $prefix . 'first_name' ] . ' ' . (string) $row[ $prefix . 'last_name' ]),
            'email' => (string) $row[ $prefix . 'email' ],
        ];
    }

    private static function ticket_select(): string
    {
        return 'SELECT t.id, t.title, t.status, t.priority, t.client_priority, t.mailbox_id, t.product_id, t.response_count, t.created_at, t.updated_at, t.last_customer_response, t.last_agent_response, t.waiting_since, t.resolved_at,'
            . ' c.id AS c_id, c.first_name AS c_first_name, c.last_name AS c_last_name, c.email AS c_email,'
            . ' a.id AS a_id, a.first_name AS a_first_name, a.last_name AS a_last_name, a.email AS a_email'
            . ' FROM %i t LEFT JOIN %i c ON c.id = t.customer_id LEFT JOIN %i a ON a.id = t.agent_id';
    }

    private static function ticket_row(array $row): array
    {
        $agent = self::person($row, 'a_');
        if (null !== $agent) {
            unset($agent['email']);
        }
        return [
            'id'                     => (int) $row['id'],
            'title'                  => (string) $row['title'],
            'status'                 => (string) $row['status'],
            'priority'               => (string) $row['priority'],
            'client_priority'        => (string) $row['client_priority'],
            'customer'               => self::person($row, 'c_'),
            'agent'                  => $agent,
            'mailbox_id'             => (int) $row['mailbox_id'],
            'product_id'             => (int) $row['product_id'],
            'response_count'         => (int) $row['response_count'],
            'created_at'             => (string) $row['created_at'],
            'updated_at'             => (string) $row['updated_at'],
            'last_customer_response' => $row['last_customer_response'],
            'last_agent_response'    => $row['last_agent_response'],
            'waiting_since'          => $row['waiting_since'],
            'resolved_at'            => $row['resolved_at'],
        ];
    }

    protected function ticket_page(array $filter, int $page, int $per_page): array
    {
        global $wpdb;
        [$sql, $args] = self::scope_sql((array) $this->scope());
        $statuses     = self::GROUPS[ (string) ($filter['status'] ?? 'all') ] ?? [];
        if ([] !== $statuses) {
            $sql .= ' AND t.status IN (' . implode(',', array_fill(0, count($statuses), '%s')) . ')';
            $args = array_merge($args, $statuses);
        }
        $search = trim((string) ($filter['search'] ?? ''));
        if ('' !== $search) {
            $like   = '%' . $wpdb->esc_like($search) . '%';
            $sql   .= ' AND (t.title LIKE %s OR t.content LIKE %s)';
            $args[] = $like;
            $args[] = $like;
        }
        $tables = [ self::table('tickets'), self::table('persons'), self::table('persons') ];
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- ticket_select() and $sql hold only %i, %d and %s placeholders.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i t WHERE 1=1{$sql}", self::table('tickets'), ...$args));
        $rows  = (array) $wpdb->get_results($wpdb->prepare(self::ticket_select() . " WHERE 1=1{$sql} ORDER BY t.id DESC LIMIT %d OFFSET %d", ...array_merge($tables, $args, [ $per_page, ($page - 1) * $per_page ])), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        return [ 'total' => $total, 'rows' => array_map([ self::class, 'ticket_row' ], $rows) ];
    }

    protected function ticket_detail(int $id, int $page, int $per_page): array
    {
        global $wpdb;
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- ticket_select() holds only %i placeholders.
        $row     = (array) $wpdb->get_row($wpdb->prepare(self::ticket_select() . ' WHERE t.id = %d', self::table('tickets'), self::table('persons'), self::table('persons'), $id), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $ticket  = self::ticket_row($row);
        $content = (string) $wpdb->get_var($wpdb->prepare('SELECT content FROM %i WHERE id = %d', self::table('tickets'), $id));
        $hidden  = "'" . implode("','", self::HIDDEN_TYPES) . "'";
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $hidden is a fixed list of literals.
        $total   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE ticket_id = %d AND conversation_type NOT IN ({$hidden})", self::table('conversations'), $id));
        $rows    = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT v.id, v.conversation_type, v.content, v.source, v.created_at, p.id AS p_id, p.first_name AS p_first_name, p.last_name AS p_last_name, p.person_type FROM %i v LEFT JOIN %i p ON p.id = v.person_id WHERE v.ticket_id = %d AND v.conversation_type NOT IN ({$hidden}) ORDER BY v.id ASC LIMIT %d OFFSET %d",
            self::table('conversations'),
            self::table('persons'),
            $id,
            $per_page,
            ($page - 1) * $per_page
        ), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $thread = [];
        foreach ($rows as $item) {
            $thread[] = [
                'id'      => (int) $item['id'],
                'type'    => (string) $item['conversation_type'],
                'by'      => empty($item['p_id']) ? null : [ 'id' => (int) $item['p_id'], 'name' => trim((string) $item['p_first_name'] . ' ' . (string) $item['p_last_name']), 'type' => (string) $item['person_type'] ],
                'content' => (string) $item['content'],
                'source'  => (string) $item['source'],
                'date'    => (string) $item['created_at'],
            ];
        }
        return $ticket + [ 'content' => $content, 'thread' => $thread, 'thread_total' => $total ];
    }

    protected function customer_email(int $id): string
    {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare('SELECT c.email FROM %i t JOIN %i c ON c.id = t.customer_id WHERE t.id = %d', self::table('tickets'), self::table('persons'), $id));
    }

    protected function current_status(int $id): string
    {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare('SELECT status FROM %i WHERE id = %d', self::table('tickets'), $id));
    }

    // ------------------------------------------------------------ writes (the plugin's own services)

    /** @return array{0: object, 1: object} the ticket and agent models */
    private function models(int $id): array
    {
        $ticket = class_exists('\\FluentSupport\\App\\Models\\Ticket') ? \FluentSupport\App\Models\Ticket::find($id) : null;
        $agent  = class_exists('\\FluentSupport\\App\\Models\\Agent') ? \FluentSupport\App\Models\Agent::where('user_id', get_current_user_id())->first() : null;
        if (! $ticket || ! $agent) {
            throw new Operation_Refused('fluent_support_inactive', 'Fluent Support\'s ticket services are not loaded.');
        }
        return [ $ticket, $agent ];
    }

    private function respond(int $id, string $content, string $type): int
    {
        [$ticket, $agent] = $this->models($id);
        $out = ( new \FluentSupport\App\Services\Tickets\ResponseService() )->createResponse([ 'content' => $content, 'conversation_type' => $type ], $agent, $ticket);
        if (! is_array($out) || empty($out['response']->id)) {
            throw new Operation_Refused('write_failed', 'Fluent Support did not take the reply (empty after its content filter?).');
        }
        return (int) $out['response']->id;
    }

    protected function send_reply(int $id, string $content): int
    {
        return $this->respond($id, $content, 'response');
    }

    protected function send_note(int $id, string $content): int
    {
        return $this->respond($id, $content, 'note');
    }

    protected function apply_status(int $id, string $status): void
    {
        [$ticket, $agent] = $this->models($id);
        $service = new \FluentSupport\App\Services\Tickets\TicketService();
        if ('closed' === $status) {
            $service->close($ticket, $agent, '', 'yes');
            return;
        }
        $service->change($ticket, [ 'status' => $status ], $agent);
    }
}
