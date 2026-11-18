<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Appointment booking plugins (issue #679) on the plugin-data pair,
 * paid-tier, so they add no top-level tools: Bookly (Booking_Bookly),
 * Simply Schedule Appointments (Booking_Simply_Schedule, ops prefixed
 * ssa-), BookingPress (Booking_BookingPress) and LatePoint
 * (Booking_LatePoint). The op catalog, the masking and the cancel write
 * they share are Booking_Adapter.
 *
 * Presence is filterable per plugin (wpmcp_bookly_active, wpmcp_ssa_active,
 * wpmcp_bookingpress_active, wpmcp_latepoint_active); an op whose plugin is
 * not loaded stays documented in list-operations (dependency_met:false) and
 * answers <slug>_inactive.
 */
final class Booking_Pack
{
    /** @return Booking_Adapter[] */
    public static function adapters(): array
    {
        return [ new Booking_Bookly(), new Booking_Simply_Schedule(), new Booking_BookingPress(), new Booking_LatePoint() ];
    }

    /** Whether a supported plugin is loaded and this install may run the paid tier. */
    public static function active(): bool
    {
        if (! Registrar::tier_permitted('pro')) {
            return false;
        }
        foreach (self::adapters() as $adapter) {
            if ($adapter->active()) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $ops = [];
        foreach (self::adapters() as $adapter) {
            $ops = array_merge($ops, $adapter->operations());
        }
        return $ops;
    }

    /**
     * One [plugin, several, ops] group per adapter, for
     * Plugin_Data_Integration::operation_hosts().
     *
     * @return array<int, array{0: string, 1: bool, 2: array<string, array<string, mixed>>}>
     */
    public static function host_groups(): array
    {
        return array_map(static fn (Booking_Adapter $adapter): array => [ $adapter->label(), false, $adapter->operations() ], self::adapters());
    }
}
