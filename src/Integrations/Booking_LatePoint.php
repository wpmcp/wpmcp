<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * LatePoint bookings (issue #679) on the plugin-data pair.
 *
 * LatePoint 5 keeps a booking as a row of {prefix}latepoint_bookings:
 * booking_code, start_date and end_date with start_time and end_time as
 * minutes after midnight (in the site's time), status (approved, pending,
 * payment_pending, cancelled, no_show, completed), and service_id, agent_id
 * and customer_id naming rows of {prefix}latepoint_services (duration in
 * minutes), {prefix}latepoint_agents (its staff) and
 * {prefix}latepoint_customers.
 *
 * Presence is filterable through wpmcp_latepoint_active (default: the
 * LatePoint class is loaded).
 */
class Booking_LatePoint extends Booking_Adapter
{
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads LatePoint's own tables live; it has no read API to cache behind.

    public function slug(): string
    {
        return 'latepoint';
    }

    public function label(): string
    {
        return 'LatePoint';
    }

    public function active(): bool
    {
        return (bool) apply_filters('wpmcp_latepoint_active', class_exists('LatePoint', false));
    }

    public function has_staff(): bool
    {
        return true;
    }

    protected function booking_suffix(): string
    {
        return 'bookings';
    }

    protected function status_map(): array
    {
        return [
            'approved'        => 'approved',
            'pending'         => 'pending',
            'payment_pending' => 'payment_pending',
            'cancelled'       => self::CANCELLED,
            'no_show'         => 'no_show',
            'completed'       => 'completed',
        ];
    }

    protected function cancel_stamp(): array
    {
        return [ 'updated_at' => current_time('mysql') ];
    }

    protected function services(): array
    {
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT id, name, duration, charge_amount, status, visibility FROM %i ORDER BY order_number, id', self::table('latepoint_services')), ARRAY_A);
        return array_map(static fn (array $r): array => [
            'id'               => (int) $r['id'],
            'name'             => (string) $r['name'],
            'duration_minutes' => (int) $r['duration'],
            'price'            => null === $r['charge_amount'] ? null : (string) $r['charge_amount'],
            'visibility'       => $r['status'] . '/' . $r['visibility'],
        ], $rows);
    }

    private static function agent_name(?string $display, ?string $first, ?string $last): string
    {
        $display = trim((string) $display);
        return '' !== $display ? $display : trim($first . ' ' . $last);
    }

    protected function staff(): array
    {
        global $wpdb;
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT id, first_name, last_name, display_name, email, phone, status FROM %i ORDER BY id', self::table('latepoint_agents')), ARRAY_A);
        return array_map(static fn (array $r): array => [
            'id'     => (int) $r['id'],
            'name'   => self::agent_name($r['display_name'], $r['first_name'], $r['last_name']),
            'email'  => (string) $r['email'],
            'phone'  => (string) $r['phone'],
            'status' => (string) $r['status'],
        ], $rows);
    }

    protected function query_parts(): array
    {
        $b  = esc_sql(self::table('latepoint_bookings'));
        $s  = esc_sql(self::table('latepoint_services'));
        $ag = esc_sql(self::table('latepoint_agents'));
        $c  = esc_sql(self::table('latepoint_customers'));
        return [
            'select' => 'b.id, b.booking_code, b.start_date, b.end_date, b.start_time, b.end_time, b.status, b.service_id, s.name AS service_name, '
                . 'b.agent_id, ag.display_name AS agent_display, ag.first_name AS agent_first, ag.last_name AS agent_last, '
                . 'b.customer_id, c.first_name AS customer_first, c.last_name AS customer_last, c.email AS customer_email, c.phone AS customer_phone, b.created_at',
            'from'   => "`{$b}` b LEFT JOIN `{$s}` s ON s.id = b.service_id LEFT JOIN `{$ag}` ag ON ag.id = b.agent_id LEFT JOIN `{$c}` c ON c.id = b.customer_id",
            'start'  => 'b.start_date',
            'status' => 'b.status',
            'key'    => 'b.id',
        ];
    }

    /** A date and minutes after midnight as Y-m-d H:i:s. */
    private static function at(?string $date, $minutes): string
    {
        $minutes = (int) $minutes;
        return sprintf('%s %02d:%02d:00', (string) $date, intdiv($minutes, 60), $minutes % 60);
    }

    protected function normalize(array $row): array
    {
        return self::booking(
            (int) $row['id'],
            $this->status_name((string) $row['status']),
            self::at($row['start_date'], $row['start_time']),
            self::at($row['end_date'], $row['end_time']),
            'site',
            [ 'id' => (int) $row['service_id'], 'name' => (string) $row['service_name'] ],
            [ 'id' => (int) $row['agent_id'], 'name' => self::agent_name($row['agent_display'], $row['agent_first'], $row['agent_last']) ],
            [ 'id' => (int) $row['customer_id'], 'name' => trim($row['customer_first'] . ' ' . $row['customer_last']), 'email' => (string) $row['customer_email'], 'phone' => (string) $row['customer_phone'] ],
            [ 'code' => (string) $row['booking_code'], 'created' => (string) $row['created_at'] ]
        );
    }
}
