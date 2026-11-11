<?php

namespace WPMCP\Integrations;

use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Awesome Support tickets (issue #708) on the plugin-data pair.
 *
 * Awesome Support 6.4 keeps a ticket as a `ticket` post: its author is the
 * customer (a WordPress user, whose user_email is the address it mails),
 * its post status is the ticket state (queued, processing, hold, or any the
 * site adds through wpas_ticket_statuses), and whether it is open or closed
 * is the _wpas_status meta. Agents are named in _wpas_assignee (plus the
 * secondary and tertiary assignees). The thread is ticket_reply child posts
 * (status read or unread); ticket_history and ticket_log children are its
 * own activity lines and are not returned. Reads are kept to what Awesome
 * Support lets the current agent see: every ticket when it lets them see
 * all (wpas_can_user_see_all_tickets()), otherwise the tickets they are
 * assigned to.
 *
 * Writes go through its own functions as the current user: wpas_add_reply()
 * for a reply (its wpas_add_reply_complete hook emails the customer),
 * wpas_close_ticket() and wpas_reopen_ticket() for open and closed, and
 * wpas_update_ticket_status() for the state. A close runs without its
 * wpas_notify_close email, so it stays undoable. The free plugin has no
 * internal notes.
 *
 * Capabilities are Awesome Support's: edit_ticket (an agent) for every op,
 * reply_ticket for a reply and close_ticket for a close. Presence is
 * filterable through wpmcp_awesome_support_active (default: WPAS_VERSION is
 * defined).
 */
class Helpdesk_Awesome_Support extends Helpdesk_Adapter
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the thread of one ticket the caller may open, read live.
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const TICKET_TYPE = 'ticket';

    private const ASSIGNEE_KEYS = [ '_wpas_assignee', '_wpas_secondary_assignee', '_wpas_tertiary_assignee' ];

    /** Awesome Support's default ticket states (wpas_get_post_status()). */
    private const DEFAULT_STATES = [ 'queued', 'processing', 'hold' ];

    public function slug(): string
    {
        return 'awesome-support';
    }

    public function label(): string
    {
        return 'Awesome Support';
    }

    public function kind(): string
    {
        return 'awesome_support';
    }

    public function active(): bool
    {
        return (bool) apply_filters('wpmcp_awesome_support_active', defined('WPAS_VERSION'));
    }

    /** The ticket states (post statuses) this site has. @return string[] */
    protected function states(): array
    {
        $states = function_exists('wpas_get_post_status') ? array_keys((array) wpas_get_post_status()) : self::DEFAULT_STATES;
        return array_values(array_filter(array_map('strval', $states), static fn (string $s): bool => 1 === preg_match('/^[a-z0-9_-]{1,20}$/', $s)));
    }

    public function statuses(): array
    {
        return array_merge($this->states(), [ 'closed' ]);
    }

    public function status_filters(): array
    {
        return array_merge([ 'open', 'closed', 'all' ], $this->states());
    }

    public function supports_notes(): bool
    {
        return false;
    }

    protected function ticket_objects(string $access): array
    {
        return [ 'ticket_id' => [ 'type' => 'post', 'own_type' => true, 'access' => $access ] ];
    }

    // ------------------------------------------------------------ access

    protected function sees_all(): bool
    {
        if (function_exists('wpas_can_user_see_all_tickets')) {
            return (bool) wpas_can_user_see_all_tickets();
        }
        return current_user_can('manage_options') || current_user_can('view_all_tickets');
    }

    protected function list_refusal(): ?array
    {
        if (current_user_can('edit_ticket')) {
            return null;
        }
        return self::refusal('operation_denied', 'This needs Awesome Support\'s edit_ticket capability (a support agent).', [ 'reason' => 'capability' ]);
    }

    private function assigned_to_me(int $id): bool
    {
        $me = get_current_user_id();
        foreach (self::ASSIGNEE_KEYS as $key) {
            if ((int) get_post_meta($id, $key, true) === $me) {
                return true;
            }
        }
        return false;
    }

    protected function ticket_refusal(int $id, bool $write): ?array
    {
        $post = get_post($id);
        if (! $post instanceof \WP_Post || self::TICKET_TYPE !== $post->post_type || 'trash' === $post->post_status) {
            return self::refusal('ticket_not_found', sprintf('%d is not an Awesome Support ticket.', $id), [ 'ticket_id' => $id ]);
        }
        $refused = $this->list_refusal();
        if (null !== $refused) {
            return $refused;
        }
        if (! $this->sees_all() && ! $this->assigned_to_me($id)) {
            return self::refusal('operation_denied', sprintf('Awesome Support does not let you see ticket %d: it is not assigned to you.', $id), [ 'reason' => 'capability', 'ticket_id' => $id ]);
        }
        if ($write && ! current_user_can('edit_post', $id)) {
            return self::refusal('operation_denied', sprintf('You may not edit ticket %d.', $id), [ 'reason' => 'capability', 'ticket_id' => $id ]);
        }
        return null;
    }

    // ------------------------------------------------------------ reads

    private static function person(int $user_id): ?array
    {
        $user = $user_id > 0 ? get_userdata($user_id) : false;
        if (! $user instanceof \WP_User) {
            return null;
        }
        return [ 'id' => (int) $user->ID, 'name' => (string) $user->display_name, 'email' => (string) $user->user_email ];
    }

    private function ticket_row(\WP_Post $post): array
    {
        $agent = self::person((int) get_post_meta($post->ID, '_wpas_assignee', true));
        if (null !== $agent) {
            unset($agent['email']);
        }
        $terms = [];
        foreach ([ 'product', 'department', 'ticket_priority', 'ticket_channel', 'ticket_type' ] as $taxonomy) {
            if (taxonomy_exists($taxonomy)) {
                $names = wp_get_post_terms($post->ID, $taxonomy, [ 'fields' => 'names' ]);
                if (is_array($names) && [] !== $names) {
                    $terms[ $taxonomy ] = array_values($names);
                }
            }
        }
        return [
            'id'              => (int) $post->ID,
            'title'           => (string) $post->post_title,
            'status'          => 'closed' === get_post_meta($post->ID, '_wpas_status', true) ? 'closed' : 'open',
            'state'           => (string) $post->post_status,
            'customer'        => self::person((int) $post->post_author),
            'agent'           => $agent,
            'reply_count'     => (int) get_post_meta($post->ID, '_wpas_ttl_replies', true),
            'waiting_on'      => get_post_meta($post->ID, '_wpas_is_waiting_client_reply', true) ? 'customer' : 'agent',
            'last_reply_date' => (string) get_post_meta($post->ID, '_wpas_last_reply_date', true),
            'created_at'      => (string) $post->post_date,
            'terms'           => (object) $terms,
        ];
    }

    protected function ticket_page(array $filter, int $page, int $per_page): array
    {
        $states = $this->states();
        $status = (string) ($filter['status'] ?? 'all');
        $query  = [
            'post_type'      => self::TICKET_TYPE,
            'post_status'    => in_array($status, $states, true) ? [ $status ] : $states,
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'meta_query'     => [], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Awesome Support filters its own list by _wpas_status and the assignee meta the same way.
        ];
        if (in_array($status, [ 'open', 'closed' ], true)) {
            $query['meta_query'][] = [ 'key' => '_wpas_status', 'value' => $status ];
        }
        if (! $this->sees_all()) {
            $mine = [ 'relation' => 'OR' ];
            foreach (self::ASSIGNEE_KEYS as $key) {
                $mine[] = [ 'key' => $key, 'value' => (string) get_current_user_id() ];
            }
            $query['meta_query'][] = $mine;
        }
        $search = trim((string) ($filter['search'] ?? ''));
        if ('' !== $search) {
            $query['s'] = $search;
        }
        $q = Readable_Posts::query($query);
        return [ 'total' => (int) $q->found_posts, 'rows' => array_map(fn (\WP_Post $post): array => $this->ticket_row($post), $q->posts) ];
    }

    protected function ticket_detail(int $id, int $page, int $per_page): array
    {
        global $wpdb;
        $post   = get_post($id);
        $ticket = $this->ticket_row($post) + [ 'content' => (string) $post->post_content ];
        $total  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'ticket_reply' AND post_status <> 'trash'", $id));
        $rows   = (array) $wpdb->get_results($wpdb->prepare("SELECT ID, post_author, post_content, post_status, post_date FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'ticket_reply' AND post_status <> 'trash' ORDER BY post_date ASC, ID ASC LIMIT %d OFFSET %d", $id, $per_page, ($page - 1) * $per_page), ARRAY_A);
        $thread = [];
        foreach ($rows as $row) {
            $by = self::person((int) $row['post_author']);
            if (null !== $by) {
                unset($by['email']);
                $by['type'] = (int) $row['post_author'] === (int) $post->post_author ? 'customer' : 'agent';
            }
            $thread[] = [
                'id'      => (int) $row['ID'],
                'type'    => 'reply',
                'by'      => $by,
                'content' => (string) $row['post_content'],
                'read'    => 'read' === $row['post_status'],
                'date'    => (string) $row['post_date'],
            ];
        }
        return $ticket + [ 'thread' => $thread, 'thread_total' => $total ];
    }

    protected function customer_email(int $id): string
    {
        $user = get_userdata((int) get_post_field('post_author', $id));
        return $user instanceof \WP_User ? (string) $user->user_email : '';
    }

    protected function current_status(int $id): string
    {
        return 'closed' === get_post_meta($id, '_wpas_status', true) ? 'closed' : (string) get_post_status($id);
    }

    // ------------------------------------------------------------ writes (the plugin's own functions)

    private static function loaded(string $function): void
    {
        if (! function_exists($function)) {
            throw new Operation_Refused('awesome_support_inactive', 'Awesome Support\'s ticket functions are not loaded.');
        }
    }

    protected function send_reply(int $id, string $content): int
    {
        self::loaded('wpas_add_reply');
        if (! current_user_can('reply_ticket')) {
            throw new Operation_Refused('operation_denied', 'This needs Awesome Support\'s reply_ticket capability.');
        }
        $reply = wpas_add_reply([ 'post_content' => wp_kses_post($content) ], $id, get_current_user_id());
        if (! $reply || is_wp_error($reply)) {
            throw new Operation_Refused('write_failed', 'Awesome Support did not take the reply.');
        }
        return (int) $reply;
    }

    protected function apply_status(int $id, string $status): void
    {
        if ('closed' === $status) {
            self::loaded('wpas_close_ticket');
            if (! current_user_can('close_ticket')) {
                throw new Operation_Refused('operation_denied', 'This needs Awesome Support\'s close_ticket capability.');
            }
            $notify = has_action('wpas_after_close_ticket', 'wpas_notify_close');
            if (false !== $notify) {
                remove_action('wpas_after_close_ticket', 'wpas_notify_close', (int) $notify);
            }
            try {
                wpas_close_ticket($id, get_current_user_id(), true);
            } finally {
                if (false !== $notify) {
                    add_action('wpas_after_close_ticket', 'wpas_notify_close', (int) $notify, 3);
                }
            }
            return;
        }
        self::loaded('wpas_update_ticket_status');
        if ('closed' === get_post_meta($id, '_wpas_status', true)) {
            wpas_reopen_ticket($id);
        }
        if ((string) get_post_status($id) !== $status) {
            wpas_update_ticket_status($id, $status);
        }
    }
}
