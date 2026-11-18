<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * BookingPress bookings (issue #679) on the plugin-data pair.
 *
 * BookingPress 1.1 keeps a booking as a row of
 * {prefix}bookingpress_appointment_bookings, keyed by
 * bookingpress_appointment_booking_id, with the service name, the date and
 * time (in the site's time) and the customer's name, email and phone copied
 * onto it, and the status as a number: 1 approved, 2 pending, 3 cancelled,
 * 4 rejected (5 no-show and 6 completed with its add-ons). Services are rows
 * of {prefix}bookingpress_services, their duration a value and a unit (m,
 * h or d). The free plugin has no staff, so there is no
 * bookingpress-list-staff.
 *
 * Presence is filterable through wpmcp_bookingpress_active (default: the
 * BookingPress class is loaded).
 */
class Booking_BookingPress extends Booking_Adapter
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads BookingPress's own tables live; it has no read API to cache behind.

    public function slug(): string
    {
        return 'bookingpress';
    }

    public function label(): string
    {
        return 'BookingPress';
    }

    public function active(): bool
    {
        return (bool) apply_filters('wpmcp_bookingpress_active', class_exists('BookingPress', false));
    }

    protected function booking_suffix(): string
    {
        return 'appointment_bookings';
    }

    protected function key_column(): string
    {
        return 'bookingpress_appointment_booking_id';
    }

    protected function status_column(): string
    {
        return 'bookingpress_appointment_status';
    }

    protected function status_map(): array
    {
        return [
            '1' => 'approved',
            '2' => 'pending',
            '3' => self::CANCELLED,
            '4' => 'rejected',
            '5' => 'no_show',
            '6' => 'completed',
        ];
    }

    /** A duration value and unit (m, h, d) in minutes. */
    private static function minutes(int $value, string $unit): int
    {
        return $value * ([ 'h' => 60, 'd' => 1440 ][ $unit ] ?? 1);
    }

    protected function services(): array
    {
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT bookingpress_service_id, bookingpress_service_name, bookingpress_service_price, bookingpress_service_duration_val, bookingpress_service_duration_unit FROM %i ORDER BY bookingpress_service_position, bookingpress_service_id', self::table('bookingpress_services')), ARRAY_A);
        return array_map(static fn (array $r): array => [
            'id'               => (int) $r['bookingpress_service_id'],
            'name'             => (string) $r['bookingpress_service_name'],
            'duration_minutes' => self::minutes((int) $r['bookingpress_service_duration_val'], (string) $r['bookingpress_service_duration_unit']),
            'price'            => (string) $r['bookingpress_service_price'],
            'visibility'       => null,
        ], $rows);
    }

    protected function query_parts(): array
    {
        $b = esc_sql(self::table('bookingpress_appointment_bookings'));
        return [
            'select' => 'b.*',
            'from'   => "`{$b}` b",
            'start'  => 'b.bookingpress_appointment_date',
            'status' => 'b.bookingpress_appointment_status',
            'key'    => 'b.bookingpress_appointment_booking_id',
        ];
    }

    protected function normalize(array $row): array
    {
        $name = trim((string) $row['bookingpress_customer_name']);
        if ('' === $name) {
            $name = trim($row['bookingpress_customer_firstname'] . ' ' . $row['bookingpress_customer_lastname']);
        }
        return self::booking(
            (int) $row['bookingpress_appointment_booking_id'],
            $this->status_name((string) $row['bookingpress_appointment_status']),
            $row['bookingpress_appointment_date'] . ' ' . $row['bookingpress_appointment_time'],
            $row['bookingpress_appointment_end_date'] . ' ' . $row['bookingpress_appointment_end_time'],
            'site',
            [ 'id' => (int) $row['bookingpress_service_id'], 'name' => (string) $row['bookingpress_service_name'] ],
            null,
            [ 'id' => (int) $row['bookingpress_customer_id'], 'name' => $name, 'email' => (string) $row['bookingpress_customer_email'], 'phone' => (string) $row['bookingpress_customer_phone'] ],
            [ 'code' => (string) $row['bookingpress_booking_id'], 'created' => (string) $row['bookingpress_created_at'] ]
        );
    }
}
