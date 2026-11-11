<?php

namespace WPMCP\Integrations;

use WPMCP\Safety\Helpdesk_Ticket_Snapshot;
use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One help desk plugin's tickets as plugin-data ops (issue #708). The op
 * catalog, the checks and the snapshot discipline live here, once; a
 * subclass says where its plugin keeps tickets and calls the plugin's own
 * API for every write (Helpdesk_Fluent_Support, Helpdesk_Awesome_Support).
 * Every op is paid-tier and prefixed with the plugin
 * (fluent-support-list-tickets, awesome-support-reply-to-ticket, ...).
 *
 * Tickets carry personal data. Reads take the plugin's own ticket access
 * (an agent sees the tickets the plugin lets them see), and list-tickets
 * returns each customer's email masked (j***@example.com); get-ticket, a
 * read of one ticket the caller may open, returns it in full.
 *
 * Writes are off until a site opts in with wpmcp_enable_helpdesk_write, and
 * each is snapshotted first as a 'helpdesk_ticket' image whose undo takes
 * back only what the write did (Helpdesk_Ticket_Snapshot):
 *  - reply-to-ticket posts an agent reply through the plugin, which emails
 *    the customer as it does from its own screen. Mail cannot be unsent, so
 *    an unconfirmed call answers with a preview naming the (masked)
 *    recipient; rollback-operation removes the reply from the thread and
 *    puts the ticket's state back, and says the email stays sent.
 *  - add-ticket-note (where the plugin has internal notes) adds a note only
 *    agents see; nothing is mailed.
 *  - set-ticket-status changes the status through the plugin without the
 *    closing email the plugin would send, so the change is fully undoable.
 */
abstract class Helpdesk_Adapter
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    public const MAX_PER_PAGE = 100;

    public const ENABLE_FILTER = 'wpmcp_enable_helpdesk_write';

    /** Op name prefix and presence-filter slug, e.g. 'fluent-support'. */
    abstract public function slug(): string;

    /** The plugin's name, e.g. 'Fluent Support'. */
    abstract public function label(): string;

    /** The snapshot kind (Helpdesk_Ticket_Snapshot::KINDS). */
    abstract public function kind(): string;

    /** Whether the plugin is loaded (filterable per plugin). */
    abstract public function active(): bool;

    /** The statuses set-ticket-status may set. @return string[] */
    abstract public function statuses(): array;

    /** The status filters list-tickets takes. @return string[] */
    abstract public function status_filters(): array;

    /** Whether the plugin has agent-only notes. */
    abstract public function supports_notes(): bool;

    /** A refusal when the current user may not list tickets at all, or null. */
    abstract protected function list_refusal(): ?array;

    /** A refusal when the ticket is missing or the current user may not open (or, with $write, work on) it, or null. */
    abstract protected function ticket_refusal(int $id, bool $write): ?array;

    /**
     * One page of the tickets the current user may see, newest first. Each
     * row carries 'customer' => [id, name, email].
     *
     * @param array{status?: string, search?: string} $filter
     * @return array{total: int, rows: array<int, array<string, mixed>>}
     */
    abstract protected function ticket_page(array $filter, int $page, int $per_page): array;

    /** One ticket with its customer and one page of its thread, oldest first. */
    abstract protected function ticket_detail(int $id, int $page, int $per_page): array;

    /** The ticket's customer email, or ''. */
    abstract protected function customer_email(int $id): string;

    /** The ticket's status as set-ticket-status names it. */
    abstract protected function current_status(int $id): string;

    /** Post an agent reply through the plugin, as the current user; returns its id. */
    abstract protected function send_reply(int $id, string $content): int;

    /** Set the status through the plugin, without the customer email. */
    abstract protected function apply_status(int $id, string $status): void;

    /** Add an agent-only note through the plugin; returns its id. */
    protected function send_note(int $id, string $content): int
    {
        throw new Operation_Refused('not_supported', sprintf('%s has no internal notes.', $this->label()));
    }

    /** The 'objects' map for the ticket_id key: tickets that are posts are checked per post. */
    protected function ticket_objects(string $access): array
    {
        return [];
    }

    /**
     * An email with all but its first character of the local part hidden:
     * jane.doe@example.com reads j***@example.com.
     */
    public static function mask_email(string $email): string
    {
        $email = trim($email);
        $at    = strrpos($email, '@');
        if (false === $at || 0 === $at) {
            return '' === $email ? '' : '***';
        }
        return substr($email, 0, 1) . '***' . substr($email, $at);
    }

    /** @return array<string, array<string, mixed>> */
    public function operations(): array
    {
        $p      = $this->slug();
        $label  = $this->label();
        $id     = [ 'type' => 'integer', 'minimum' => 1 ];
        $paging = [
            'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
            'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
        ];
        $base   = [
            'tier'     => 'pro',
            'requires' => fn () => Ops_Status_Packs::presence($this->active(), str_replace('-', '_', $p) . '_inactive', $label),
        ];
        $write  = $base + [
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_helpdesk_write', false),
            'opt_in_filter'      => self::ENABLE_FILTER,
        ];
        $target = fn (array $args, array $extra = []): array => [
            'object_type'         => Helpdesk_Ticket_Snapshot::TYPE,
            'object_id'           => Helpdesk_Ticket_Snapshot::key($this->kind(), (int) $args['ticket_id']),
            'extra_snapshot_data' => $extra,
        ];
        $text   = [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 65535 ];

        $ops = [
            "{$p}-list-tickets"      => $base + [
                'mode'         => 'read',
                'description'  => "List {$label} tickets newest first, as the plugin lets you see them: id, title, status, priority, customer (name, email masked), agent, reply count and dates. status: " . implode(', ', $this->status_filters()) . '; search matches the title and content. Personal data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status' => [ 'type' => 'string', 'enum' => $this->status_filters() ],
                        'search' => [ 'type' => 'string', 'maxLength' => 200 ],
                    ] + $paging,
                ],
                'validate'     => fn (array $args): ?array => $this->list_refusal(),
                'handler'      => fn (array $args): array => $this->list_tickets($args),
            ],
            "{$p}-get-ticket"        => $base + [
                'mode'         => 'read',
                'objects'      => $this->ticket_objects('read'),
                'description'  => "Read one {$label} ticket with its customer (email in full) and its thread oldest first, paged (per_page up to 100). Personal data",
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'ticket_id' => $id ] + $paging, 'required' => [ 'ticket_id' ] ],
                'validate'     => fn (array $args): ?array => $this->ticket_refusal((int) $args['ticket_id'], false),
                'handler'      => fn (array $args): array => [ 'ticket' => $this->ticket_detail((int) $args['ticket_id'], (int) ($args['page'] ?? 1), (int) ($args['per_page'] ?? 50)) ],
            ],
            "{$p}-reply-to-ticket"   => $write + [
                'mode'            => 'destructive',
                'objects'         => $this->ticket_objects('write'),
                'description'     => "Post an agent reply to a {$label} ticket as you; {$label} emails it to the customer. Off unless " . self::ENABLE_FILTER . '; asks for confirmation naming the recipient. Undo (rollback-operation) removes the reply and puts the ticket back, but the email stays sent',
                'input_schema'    => [ 'type' => 'object', 'properties' => [ 'ticket_id' => $id, 'content' => $text ], 'required' => [ 'ticket_id', 'content' ] ],
                'validate'        => fn (array $args): ?array => $this->ticket_refusal((int) $args['ticket_id'], true),
                'confirm_preview' => fn (array $args): array => $this->reply_preview((int) $args['ticket_id']),
                'snapshot'        => fn (array $args): array => $target($args, [ 'notified' => true ]),
                'handler'         => fn (array $args, array $context = []): array => $this->reply($args, (string) ($context['operation_id'] ?? '')),
            ],
            "{$p}-set-ticket-status" => $write + [
                'mode'         => 'write',
                'objects'      => $this->ticket_objects('write'),
                'description'  => "Set a {$label} ticket's status (" . implode(', ', $this->statuses()) . ") through {$label}, without its closing email to the customer. Off unless " . self::ENABLE_FILTER . '. Undo: rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'ticket_id' => $id, 'status' => [ 'type' => 'string', 'enum' => $this->statuses() ] ],
                    'required'   => [ 'ticket_id', 'status' ],
                ],
                'validate'     => fn (array $args): ?array => $this->status_refusal($args),
                'snapshot'     => fn (array $args): array => $target($args),
                'handler'      => fn (array $args, array $context = []): array => $this->set_status($args, (string) ($context['operation_id'] ?? '')),
            ],
        ];
        if ($this->supports_notes()) {
            $ops[ "{$p}-add-ticket-note" ] = $write + [
                'mode'         => 'write',
                'objects'      => $this->ticket_objects('write'),
                'description'  => "Add an internal note to a {$label} ticket as you: only agents see it and nothing is mailed to the customer. Off unless " . self::ENABLE_FILTER . '. Undo: rollback-operation',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'ticket_id' => $id, 'content' => $text ], 'required' => [ 'ticket_id', 'content' ] ],
                'validate'     => fn (array $args): ?array => $this->ticket_refusal((int) $args['ticket_id'], true),
                'snapshot'     => fn (array $args): array => $target($args),
                'handler'      => fn (array $args, array $context = []): array => $this->note($args, (string) ($context['operation_id'] ?? '')),
            ];
        }
        return $ops;
    }

    // ------------------------------------------------------------ reads

    private function list_tickets(array $args): array
    {
        $filter = array_intersect_key($args, [ 'status' => true, 'search' => true ]);
        $page   = $this->ticket_page($filter, (int) ($args['page'] ?? 1), (int) ($args['per_page'] ?? 20));
        $rows   = [];
        foreach ($page['rows'] as $row) {
            if (isset($row['customer']['email'])) {
                $row['customer']['email'] = self::mask_email((string) $row['customer']['email']);
            }
            $rows[] = $row;
        }
        return [ 'tickets' => $rows, 'total' => (int) $page['total'], 'emails_masked' => true ];
    }

    // ------------------------------------------------------------ checks

    protected static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    private function status_refusal(array $args): ?array
    {
        $id = (int) $args['ticket_id'];
        $refused = $this->ticket_refusal($id, true);
        if (null !== $refused) {
            return $refused;
        }
        if ($this->current_status($id) === (string) $args['status']) {
            return self::refusal('already_done', sprintf('%s ticket %d is already %s.', $this->label(), $id, (string) $args['status']), [ 'ticket_id' => $id ]);
        }
        return null;
    }

    private function reply_preview(int $id): array
    {
        $to = self::mask_email($this->customer_email($id));
        return [
            'message' => sprintf('%s will email this reply to the customer of ticket %d (%s). Mail cannot be unsent: an undo removes the reply from the ticket only. Call again with confirm:true to send.', $this->label(), $id, '' === $to ? 'no email on file' : $to),
            'data'    => [ 'ticket_id' => $id, 'recipient' => $to ],
        ];
    }

    // ------------------------------------------------------------ writes

    /**
     * Run one write and record its after image, even when it fails half
     * way; a write that changed nothing voids its undo point.
     */
    private function tracked(string $operation_id, callable $write)
    {
        try {
            return $write();
        } finally {
            if ('' !== $operation_id) {
                Helpdesk_Ticket_Snapshot::record_after($operation_id);
                if (Helpdesk_Ticket_Snapshot::unchanged($operation_id)) {
                    Snapshot_Store::delete_operation($operation_id);
                }
            }
        }
    }

    private function reply(array $args, string $operation_id): array
    {
        $id    = (int) $args['ticket_id'];
        $reply = (int) $this->tracked($operation_id, fn (): int => $this->send_reply($id, (string) $args['content']));
        return [
            'ticket_id' => $id,
            'reply_id'  => $reply,
            'status'    => $this->current_status($id),
            'notified'  => self::mask_email($this->customer_email($id)),
            'note'      => 'The plugin emails the reply to the customer; an undo removes it from the ticket but cannot unsend it.',
        ];
    }

    private function note(array $args, string $operation_id): array
    {
        $id   = (int) $args['ticket_id'];
        $note = (int) $this->tracked($operation_id, fn (): int => $this->send_note($id, (string) $args['content']));
        return [ 'ticket_id' => $id, 'note_id' => $note ];
    }

    private function set_status(array $args, string $operation_id): array
    {
        $id   = (int) $args['ticket_id'];
        $from = $this->current_status($id);
        $this->tracked($operation_id, function () use ($id, $args): bool {
            $this->apply_status($id, (string) $args['status']);
            return true;
        });
        return [ 'ticket_id' => $id, 'from' => $from, 'status' => $this->current_status($id) ];
    }
}
