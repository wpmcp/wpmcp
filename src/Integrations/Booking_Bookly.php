<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Bookly bookings (issue #679) on the plugin-data pair.
 *
 * Bookly 28 keeps a slot as a row of {prefix}bookly_appointments (staff_id,
 * service_id or a custom service name, start_date and end_date in the site's
 * time) and each customer's place in it as a row of
 * {prefix}bookly_customer_appointments, which carries the status (pending,
 * approved, cancelled, rejected, waitlisted, done) and status_changed_at.
 * A booking here is that customer row: its id is what the ops take, and the
 * slot's own id comes back as slot_id. Services are {prefix}bookly_services
 * (duration in seconds), staff {prefix}bookly_staff and customers
 * {prefix}bookly_customers.
 *
 * Presence is filterable through wpmcp_bookly_active (default: Bookly's
 * \Bookly\Lib\Plugin class is loaded).
 */
class Booking_Bookly extends Booking_Adapter
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads Bookly's own tables live; it has no read API to cache behind.

    public function slug(): string
    {
        return 'bookly';
    }

    public function label(): string
    {
        return 'Bookly';
    }

    public function active(): bool
    {
        return (bool) apply_filters('wpmcp_bookly_active', class_exists('\Bookly\Lib\Plugin', false));
    }

    public function has_staff(): bool
    {
        return true;
    }

    protected function booking_suffix(): string
    {
        return 'customer_appointments';
    }

    protected function status_map(): array
    {
        return [
            'pending'    => 'pending',
            'approved'   => 'approved',
            'cancelled'  => self::CANCELLED,
            'rejected'   => 'rejected',
            'waitlisted' => 'waitlisted',
            'done'       => 'done',
        ];
    }

    protected function cancel_stamp(): array
    {
        $now = current_time('mysql');
        return [ 'status_changed_at' => $now, 'updated_at' => $now ];
    }

    protected function services(): array
    {
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT id, title, duration, price, visibility FROM %i ORDER BY position, id', self::table('bookly_services')), ARRAY_A);
        return array_map(static fn (array $r): array => [
            'id'               => (int) $r['id'],
            'name'             => (string) $r['title'],
            'duration_minutes' => intdiv((int) $r['duration'], 60),
            'price'            => (string) $r['price'],
            'visibility'       => (string) $r['visibility'],
        ], $rows);
    }

    protected function staff(): array
    {
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT id, full_name, email, phone, visibility FROM %i ORDER BY position, id', self::table('bookly_staff')), ARRAY_A);
        return array_map(static fn (array $r): array => [
            'id'     => (int) $r['id'],
            'name'   => (string) $r['full_name'],
            'email'  => (string) $r['email'],
            'phone'  => (string) $r['phone'],
            'status' => (string) $r['visibility'],
        ], $rows);
    }

    protected function query_parts(): array
    {
        $ca = esc_sql(self::table('bookly_customer_appointments'));
        $a  = esc_sql(self::table('bookly_appointments'));
        $s  = esc_sql(self::table('bookly_services'));
        $st = esc_sql(self::table('bookly_staff'));
        $c  = esc_sql(self::table('bookly_customers'));
        return [
            'select' => 'b.id, b.appointment_id, b.status, b.number_of_persons, b.created_at, a.start_date, a.end_date, a.service_id, '
                . 'COALESCE(s.title, a.custom_service_name) AS service_name, a.staff_id, st.full_name AS staff_name, '
                . 'b.customer_id, c.full_name AS customer_name, c.email AS customer_email, c.phone AS customer_phone',
            'from'   => "`{$ca}` b JOIN `{$a}` a ON a.id = b.appointment_id LEFT JOIN `{$s}` s ON s.id = a.service_id "
                . "LEFT JOIN `{$st}` st ON st.id = a.staff_id LEFT JOIN `{$c}` c ON c.id = b.customer_id",
            'start'  => 'a.start_date',
            'status' => 'b.status',
            'key'    => 'b.id',
        ];
    }

    protected function normalize(array $row): array
    {
        return self::booking(
            (int) $row['id'],
            $this->status_name((string) $row['status']),
            (string) $row['start_date'],
            (string) $row['end_date'],
            'site',
            [ 'id' => (int) $row['service_id'], 'name' => (string) $row['service_name'] ],
            [ 'id' => (int) $row['staff_id'], 'name' => (string) $row['staff_name'] ],
            [ 'id' => (int) $row['customer_id'], 'name' => (string) $row['customer_name'], 'email' => (string) $row['customer_email'], 'phone' => (string) $row['customer_phone'] ],
            [ 'slot_id' => (int) $row['appointment_id'], 'persons' => (int) $row['number_of_persons'], 'created' => (string) $row['created_at'] ]
        );
    }
}
