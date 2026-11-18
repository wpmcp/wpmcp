<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Simply Schedule Appointments bookings (issue #679) on the plugin-data
 * pair, ops prefixed ssa-.
 *
 * Simply Schedule Appointments 1.6 keeps a booking as a row of
 * {prefix}ssa_appointments: appointment_type_id, start_date and end_date in
 * UTC, status (booked, pending_payment, pending_form, canceled, abandoned)
 * and the customer's answers as JSON in customer_information (Name, Email,
 * Phone and any custom fields). Its services are appointment types, rows of
 * {prefix}ssa_appointment_types (duration in minutes, status publish or
 * delete). The free plugin has no staff, so there is no ssa-list-staff.
 * Its canceled status is named cancelled in the ops, like the others.
 *
 * Presence is filterable through wpmcp_ssa_active (default: the
 * Simply_Schedule_Appointments class is loaded).
 */
class Booking_Simply_Schedule extends Booking_Adapter
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads Simply Schedule Appointments' own tables live; it has no read API to cache behind.

    public function slug(): string
    {
        return 'ssa';
    }

    public function label(): string
    {
        return 'Simply Schedule Appointments';
    }

    public function active(): bool
    {
        return (bool) apply_filters('wpmcp_ssa_active', class_exists('Simply_Schedule_Appointments', false));
    }

    protected function booking_suffix(): string
    {
        return 'appointments';
    }

    protected function status_map(): array
    {
        return [
            'booked'          => 'booked',
            'pending_payment' => 'pending_payment',
            'pending_form'    => 'pending_form',
            'canceled'        => self::CANCELLED,
            'abandoned'       => 'abandoned',
        ];
    }

    protected function cancel_stamp(): array
    {
        return [ 'date_modified' => gmdate('Y-m-d H:i:s') ];
    }

    protected function services(): array
    {
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT id, title, duration, status FROM %i ORDER BY display_order, id', self::table('ssa_appointment_types')), ARRAY_A);
        return array_map(static fn (array $r): array => [
            'id'               => (int) $r['id'],
            'name'             => (string) $r['title'],
            'duration_minutes' => (int) $r['duration'],
            'price'            => null,
            'visibility'       => (string) $r['status'],
        ], $rows);
    }

    protected function query_parts(): array
    {
        $a = esc_sql(self::table('ssa_appointments'));
        $t = esc_sql(self::table('ssa_appointment_types'));
        return [
            'select' => 'b.id, b.appointment_type_id, t.title AS service_name, b.customer_id, b.customer_information, b.start_date, b.end_date, b.status, b.date_created',
            'from'   => "`{$a}` b LEFT JOIN `{$t}` t ON t.id = b.appointment_type_id",
            'start'  => 'b.start_date',
            'status' => 'b.status',
            'key'    => 'b.id',
        ];
    }

    /** A field of the customer's answers, matched without regard to case. */
    private static function answer(array $info, string $field): string
    {
        foreach ($info as $key => $value) {
            if (is_string($key) && 0 === strcasecmp($key, $field) && is_scalar($value)) {
                return (string) $value;
            }
        }
        return '';
    }

    protected function normalize(array $row): array
    {
        $info = json_decode((string) $row['customer_information'], true);
        $info = is_array($info) ? $info : [];
        return self::booking(
            (int) $row['id'],
            $this->status_name((string) $row['status']),
            (string) $row['start_date'],
            (string) $row['end_date'],
            'UTC',
            [ 'id' => (int) $row['appointment_type_id'], 'name' => (string) $row['service_name'] ],
            null,
            [ 'id' => (int) $row['customer_id'], 'name' => self::answer($info, 'Name'), 'email' => self::answer($info, 'Email'), 'phone' => self::answer($info, 'Phone') ],
            [ 'created' => (string) $row['date_created'] ]
        );
    }
}
