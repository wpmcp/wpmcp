<?php

namespace WPMCP\Integrations;

use WPMCP\Privacy\Masking;
use WPMCP\Safety\Plugin_Table_Rows_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One appointment booking plugin as plugin-data ops (issue #679). The op
 * catalog, the masking, the checks and the cancel write live here, once; a
 * subclass says where its plugin keeps services, staff and bookings
 * (Booking_Bookly, Booking_Simply_Schedule, Booking_BookingPress,
 * Booking_LatePoint). Every op is paid-tier, takes manage_options (the
 * capability each plugin's own appointment screens are behind for an
 * administrator) and is prefixed with the plugin (bookly-list-services,
 * latepoint-cancel-appointment, ...).
 *
 * Reads: list-services, list-staff (where the free plugin has staff),
 * list-appointments and get-appointment. A booking is one customer's place
 * in a slot, with its service, staff member, start and end, status and
 * customer. Bookings carry personal data: list-appointments masks the
 * customer's name, email and phone, and list-staff a staff member's email
 * and phone (Privacy\Masking); get-appointment returns one booking in full.
 *
 * Write: cancel-appointment sets the booking's own status column to the
 * plugin's cancelled status, off until a site opts in with
 * wpmcp_enable_booking_write. It writes the row directly rather than through
 * the plugin, so the plugin sends no cancellation email and runs none of its
 * cancel hooks (calendar sync, payment refunds, waiting lists): the change is
 * the one column (and the plugin's own updated stamp), fully undone by
 * rollback-operation from a 'plugin_table_rows' before-image of the row. It
 * still frees the customer's slot, so an unconfirmed call answers with a
 * preview naming the (masked) customer and the slot.
 *
 * Booking, rescheduling and open slots go through each plugin's own
 * availability engine and are a follow-up (issue #679).
 */
abstract class Booking_Adapter
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads and writes the booking plugin's own tables live; it has no read API to cache behind.
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const MAX_PER_PAGE = 100;

    public const ENABLE_FILTER = 'wpmcp_enable_booking_write';

    /** The status name every adapter maps its own cancelled status to. */
    public const CANCELLED = 'cancelled';

    /** Op name prefix and presence-filter slug, e.g. 'bookly'. */
    abstract public function slug(): string;

    /** The plugin's name, e.g. 'Bookly'. */
    abstract public function label(): string;

    /** Whether the plugin is loaded (filterable per plugin through wpmcp_<slug>_active). */
    abstract public function active(): bool;

    /** The booking table, unprefixed, under the snapshot kind of slug() (Plugin_Table_Rows_Snapshot::KINDS). */
    abstract protected function booking_suffix(): string;

    /** The booking table's key column. */
    protected function key_column(): string
    {
        return 'id';
    }

    /** The booking table's status column. */
    protected function status_column(): string
    {
        return 'status';
    }

    /** The plugin's own status values => the names the ops use. @return array<string, string> */
    abstract protected function status_map(): array;

    /** Other columns the cancel stamps, the way the plugin does (updated_at, ...). @return array<string, string> */
    protected function cancel_stamp(): array
    {
        return [];
    }

    /** Whether the free plugin has staff members. */
    public function has_staff(): bool
    {
        return false;
    }

    /** @return array<int, array<string, mixed>> every service, in the plugin's own order */
    abstract protected function services(): array;

    /** @return array<int, array<string, mixed>> every staff member, contact details in full */
    protected function staff(): array
    {
        return [];
    }

    /**
     * The FROM clause (booking table aliased b, plus joins) and the SELECT
     * list of a booking row, the start expression the date filter and the
     * order use, and the status column, qualified.
     *
     * @return array{select: string, from: string, start: string, status: string, key: string}
     */
    abstract protected function query_parts(): array;

    /** One raw booking row as the ops return it, customer in full. */
    abstract protected function normalize(array $row): array;

    // ------------------------------------------------------------ helpers

    protected static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    /** A status the ops use for a raw plugin value. */
    protected function status_name(string $raw): string
    {
        return $this->status_map()[ $raw ] ?? $raw;
    }

    /** The plugin's own value for a status name, or null. */
    private function status_value(string $name): ?string
    {
        $raw = array_search($name, $this->status_map(), true);
        return false === $raw ? null : (string) $raw;
    }

    /** One booking row shaped the same for every plugin. */
    protected static function booking(int $id, string $status, string $start, string $end, string $timezone, array $service, ?array $staff, array $customer, array $extra = []): array
    {
        return [
            'id'       => $id,
            'status'   => $status,
            'start'    => $start,
            'end'      => $end,
            'timezone' => $timezone,
            'service'  => $service,
            'staff'    => $staff,
            'customer' => $customer,
        ] + $extra;
    }

    private static function mask_customer(array $row): array
    {
        $row['customer'] = Masking::keys((array) ($row['customer'] ?? []), [ 'name', 'email', 'phone' ]);
        return $row;
    }

    // ------------------------------------------------------------ catalog

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
        $date   = [ 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ];
        $base   = [
            'tier'       => 'pro',
            'capability' => 'manage_options',
            'requires'   => fn () => Ops_Status_Packs::presence($this->active(), $p . '_inactive', $label),
        ];
        $names  = array_values(array_unique(array_values($this->status_map())));

        $ops = [
            "{$p}-list-services"     => $base + [
                'mode'         => 'read',
                'description'  => "List {$label} services: id, name, duration in minutes, price and visibility",
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => fn (array $args): array => [ 'services' => $this->services() ],
            ],
            "{$p}-list-appointments" => $base + [
                'mode'         => 'read',
                'description'  => "List {$label} bookings newest start first: id, status, start and end, service, staff, customer (name, email and phone MASKED). status: " . implode(', ', $names) . '; from and to (Y-m-d) bound the start date. Personal data',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'status' => [ 'type' => 'string', 'enum' => $names ],
                        'from'   => $date,
                        'to'     => $date,
                    ] + $paging,
                ],
                'handler'      => fn (array $args): array => $this->list_appointments($args),
            ],
            "{$p}-get-appointment"   => $base + [
                'mode'         => 'read',
                'description'  => "Read one {$label} booking with its customer in full. Personal data",
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'appointment_id' => $id ], 'required' => [ 'appointment_id' ] ],
                'validate'     => fn (array $args): ?array => $this->exists_refusal((int) $args['appointment_id']),
                'handler'      => fn (array $args): array => [ 'appointment' => $this->appointment((int) $args['appointment_id']) ],
            ],
            "{$p}-cancel-appointment" => $base + [
                'mode'               => 'destructive',
                'enabled_by_default' => (bool) apply_filters('wpmcp_enable_booking_write', false),
                'opt_in_filter'      => self::ENABLE_FILTER,
                'description'        => "Cancel a {$label} booking: sets its status to cancelled and frees the slot, without {$label}'s cancellation email or cancel hooks (calendar sync, refunds). Off unless " . self::ENABLE_FILTER . '; asks for confirmation naming the customer. Undo: rollback-operation',
                'input_schema'       => [ 'type' => 'object', 'properties' => [ 'appointment_id' => $id ], 'required' => [ 'appointment_id' ] ],
                'validate'           => fn (array $args): ?array => $this->cancel_refusal((int) $args['appointment_id']),
                'confirm_preview'    => fn (array $args): array => $this->cancel_preview((int) $args['appointment_id']),
                'snapshot'           => fn (array $args): array => [
                    'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
                    'object_id'   => Plugin_Table_Rows_Snapshot::key($p, $this->booking_suffix(), [ (int) $args['appointment_id'] ]),
                ],
                'handler'            => fn (array $args): array => $this->cancel((int) $args['appointment_id']),
            ],
        ];
        if ($this->has_staff()) {
            $ops[ "{$p}-list-staff" ] = $base + [
                'mode'         => 'read',
                'description'  => "List {$label} staff members: id, name, email and phone (MASKED), and status",
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => fn (array $args): array => [
                    'staff'  => array_map(static fn (array $row): array => Masking::keys($row, [ 'email', 'phone' ]), $this->staff()),
                    'masked' => true,
                ],
            ];
        }
        return $ops;
    }

    // ------------------------------------------------------------ reads

    /**
     * The WHERE clause and its arguments for a status name and a start
     * date range.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    private function where(array $args, array $parts): array
    {
        $sql  = [];
        $vals = [];
        if (isset($args['status'])) {
            $sql[]  = $parts['status'] . ' = %s';
            $vals[] = (string) $this->status_value((string) $args['status']);
        }
        if (isset($args['from'])) {
            $sql[]  = $parts['start'] . ' >= %s';
            $vals[] = $args['from'] . ' 00:00:00';
        }
        if (isset($args['to'])) {
            $sql[]  = $parts['start'] . ' <= %s';
            $vals[] = $args['to'] . ' 23:59:59';
        }
        return [ [] === $sql ? '1=1' : implode(' AND ', $sql), $vals ];
    }

    private function list_appointments(array $args): array
    {
        global $wpdb;

        $parts            = $this->query_parts();
        [ $where, $vals ] = $this->where($args, $parts);
        $per_page         = (int) ($args['per_page'] ?? 20);
        $offset           = ((int) ($args['page'] ?? 1) - 1) * $per_page;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the FROM and SELECT are built from the site prefix and fixed column names; every value is a placeholder.
        $count_sql = "SELECT COUNT(*) FROM {$parts['from']} WHERE {$where}";
        $total     = (int) $wpdb->get_var([] === $vals ? $count_sql : $wpdb->prepare($count_sql, ...$vals));
        $rows      = (array) $wpdb->get_results(
            $wpdb->prepare("SELECT {$parts['select']} FROM {$parts['from']} WHERE {$where} ORDER BY {$parts['start']} DESC, {$parts['key']} DESC LIMIT %d OFFSET %d", ...array_merge($vals, [ $per_page, $offset ])),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        return [
            'appointments' => array_map(fn (array $row): array => self::mask_customer($this->normalize($row)), $rows),
            'total'        => $total,
            'masked'       => true,
        ];
    }

    /** One booking, customer in full, or null when there is none. */
    protected function appointment(int $id): ?array
    {
        global $wpdb;

        $parts = $this->query_parts();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the FROM and SELECT are built from the site prefix and fixed column names.
        $row = $wpdb->get_row($wpdb->prepare("SELECT {$parts['select']} FROM {$parts['from']} WHERE {$parts['key']} = %d", $id), ARRAY_A);
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return is_array($row) ? $this->normalize($row) : null;
    }

    // ------------------------------------------------------------ checks

    private function exists_refusal(int $id): ?array
    {
        if (null !== $this->appointment($id)) {
            return null;
        }
        return [ 'code' => 'appointment_not_found', 'message' => sprintf('%s has no booking %d.', $this->label(), $id), 'data' => [ 'appointment_id' => $id ] ];
    }

    private function cancel_refusal(int $id): ?array
    {
        $refused = $this->exists_refusal($id);
        if (null !== $refused) {
            return $refused;
        }
        if (self::CANCELLED === ($this->appointment($id)['status'] ?? '')) {
            return [ 'code' => 'already_done', 'message' => sprintf('%s booking %d is already cancelled.', $this->label(), $id), 'data' => [ 'appointment_id' => $id ] ];
        }
        return null;
    }

    private function cancel_preview(int $id): array
    {
        $row = self::mask_customer((array) $this->appointment($id));
        $who = (string) ($row['customer']['email'] ?? '');
        return [
            'message' => sprintf(
                'This cancels %s booking %d (%s, %s) for %s and frees the slot. %s sends no email; tell the customer yourself. Undo: rollback-operation. Call again with confirm:true to cancel.',
                $this->label(),
                $id,
                (string) ($row['service']['name'] ?? ''),
                (string) ($row['start'] ?? ''),
                '' === $who ? 'a customer with no email on file' : $who,
                $this->label()
            ),
            'data'    => [ 'appointment_id' => $id, 'customer' => $row['customer'] ?? [], 'start' => $row['start'] ?? '' ],
        ];
    }

    // ------------------------------------------------------------ write

    private function cancel(int $id): array
    {
        global $wpdb;

        $from    = (string) ($this->appointment($id)['status'] ?? '');
        $updated = $wpdb->update(
            self::table($this->booking_suffix_table()),
            [ $this->status_column() => (string) $this->status_value(self::CANCELLED) ] + $this->cancel_stamp(),
            [ $this->key_column() => $id ]
        );
        if (false === $updated) {
            throw new Operation_Refused('update_failed', sprintf('Could not cancel %s booking %d: %s', $this->label(), $id, $wpdb->last_error));
        }
        return [
            'appointment_id' => $id,
            'from'           => $from,
            'status'         => (string) ($this->appointment($id)['status'] ?? ''),
            'notified'       => false,
            'note'           => sprintf('%s sent no cancellation email and ran none of its cancel hooks; tell the customer yourself.', $this->label()),
        ];
    }

    /** The booking table name after the site prefix. */
    private function booking_suffix_table(): string
    {
        return (string) (Plugin_Table_Rows_Snapshot::KINDS[ $this->slug() ] ?? '') . $this->booking_suffix();
    }
}
