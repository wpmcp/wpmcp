<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;
use WPMCP\Safety\Amelia_Rows_Snapshot;
use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Amelia bookings (issue #499) on the plugin-data pair, paid-tier, so they
 * add no top-level tools where the pair already registers.
 *
 * Reads come straight from Amelia's tables (a setup summary, categories,
 * services with each employee's price and capacity, employees with their
 * working week, breaks and days off, customers, appointments, events with
 * places left and event attendees), except free slots, which are Amelia's
 * own slot calculation (its GetTimeSlots controller).
 *
 * Writes run through Amelia's own application layer (Amelia_Api), the
 * controllers its admin pages call, so Amelia's permission, slot and
 * working-hours checks, its events and integrations all run, and a taken
 * slot or a time outside working hours is refused with Amelia's own reason:
 * category, service and customer create and update, and appointment book,
 * reschedule and status (approved, pending, canceled, rejected).
 * Appointments are never deleted here.
 *
 * Times are site-local "Y-m-d H:i" in and out (Amelia stores appointments in
 * UTC; a local time that does not exist, inside a daylight-saving jump, is
 * refused), and durations are minutes. What Amelia Lite cannot store (group
 * capacity, buffer time before and after, more than one person per booking)
 * is refused by name instead of being silently dropped.
 *
 * Notifications: customer emails and SMS that Amelia sends during a write
 * are held unless the call says notify:true, and every write answers with
 * the notifications Amelia sent and the ones held. Employee and admin
 * notifications are Amelia's to send as configured.
 *
 * Every write is snapshotted first as an 'amelia_rows' image
 * (Amelia_Rows_Snapshot), so rollback-operation restores Amelia's rows
 * exactly, without re-sending anything, and refuses while later bookings
 * depend on what it would undo.
 *
 * Capabilities are Amelia's own, on top of the pair's: amelia_read_* for
 * reads and amelia_write_services, amelia_write_customers and
 * amelia_write_appointments for writes. Customers are personal data, so
 * every op that returns them needs amelia_read_customers or
 * amelia_read_appointments.
 *
 * Presence is filterable (wpmcp_amelia_active); while Amelia is not loaded
 * the ops stay documented in list-operations (dependency_met:false) and
 * answer amelia_inactive.
 */
final class Amelia_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads Amelia's own tables live; there is no Amelia read API to cache behind.

    public const MAX_PER_PAGE = 100;

    public const MAX_SLOT_DAYS = 31;

    /** The appointment statuses a write may set. */
    public const STATUSES = [ 'approved', 'pending', 'canceled', 'rejected' ];

    /** Appointment and booking statuses that hold a place. */
    private const ACTIVE = [ 'approved', 'pending' ];

    private const DAYS = [ 1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday', 7 => 'sunday' ];

    /** Amelia's own tables this pack reads, unprefixed (after "amelia_"). */
    private const TABLES = [
        'categories',
        'services',
        'providers_to_services',
        'users',
        'appointments',
        'customer_bookings',
        'providers_to_weekdays',
        'providers_to_timeouts',
        'providers_to_daysoff',
        'events',
        'events_periods',
        'customer_bookings_to_events_periods',
    ];

    private static ?Amelia_Api $api = null;

    public static function api(): Amelia_Api
    {
        return self::$api ?? new Amelia_Api();
    }

    /**
     * A stand-in bridge for the test suite, which has no Amelia installed: it
     * keeps the catalog, checks, reads and snapshots and replaces only the
     * calls into Amelia's controllers. Null restores the real one.
     */
    public static function use_api_for_tests(?Amelia_Api $api): void
    {
        if (! defined('WPMCP_TESTING') || ! WPMCP_TESTING) {
            return;
        }
        self::$api = $api;
    }

    /** Whether Amelia is loaded (filterable) and this install may run the paid tier. */
    public static function active(): bool
    {
        return Registrar::tier_permitted('pro')
            && (bool) apply_filters('wpmcp_amelia_active', self::api()->active());
    }

    // ------------------------------------------------------------------
    // Catalog
    // ------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id       = [ 'type' => 'integer', 'minimum' => 1 ];
        $date     = [ 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ];
        $time     = [ 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$' ];
        $page     = [
            'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
            'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
        ];
        $minutes  = [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 1440 ];
        $visible  = [ 'type' => 'string', 'enum' => [ 'visible', 'hidden' ] ];
        $notify   = [ 'notify' => [ 'type' => 'boolean' ] ];
        $base     = [ 'tier' => 'pro', 'requires' => static fn () => Ops_Status_Packs::presence(self::active(), 'amelia_inactive', 'Amelia') ];
        $rows     = static fn (string $kind, string $key): \Closure => static fn (array $args): array => [ 'object_type' => Amelia_Rows_Snapshot::TYPE, 'object_id' => Amelia_Rows_Snapshot::key($kind, '' === $key ? 0 : (int) ($args[ $key ] ?? 0)) ];
        $service  = [
            'name'          => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 255 ],
            'category_id'   => $id,
            'duration'      => [ 'type' => 'integer', 'minimum' => 5, 'maximum' => 1440 ],
            'price'         => [ 'type' => 'number', 'minimum' => 0 ],
            'employee_ids'  => [ 'type' => 'array', 'items' => $id ],
            'description'   => [ 'type' => 'string' ],
            'status'        => $visible,
            'min_capacity'  => [ 'type' => 'integer', 'minimum' => 1 ],
            'max_capacity'  => [ 'type' => 'integer', 'minimum' => 1 ],
            'time_before'   => $minutes,
            'time_after'    => $minutes,
        ];
        $customer = [
            'first_name' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 255 ],
            'last_name'  => [ 'type' => 'string', 'maxLength' => 255 ],
            'email'      => [ 'type' => 'string', 'format' => 'email' ],
            'phone'      => [ 'type' => 'string', 'maxLength' => 63 ],
            'note'       => [ 'type' => 'string' ],
        ];

        return [
            'amelia-get-setup'          => $base + [
                'mode'         => 'read',
                'capability'   => 'amelia_read_services',
                'description'  => 'Amelia setup: edition, site timezone, currency, slot length (minutes), whether a mail sender is set, and counts',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => static fn (): array => self::setup(),
            ],
            'amelia-list-categories'    => $base + [
                'mode'         => 'read',
                'capability'   => 'amelia_read_services',
                'description'  => 'Amelia service categories with their service counts',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => static fn (): array => [ 'categories' => self::categories() ],
            ],
            'amelia-list-services'      => $base + [
                'mode'         => 'read',
                'capability'   => 'amelia_read_services',
                'description'  => 'Amelia services (duration and buffers in minutes) with each employee\'s price and capacity. Filter by category_id or search the name',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'category_id' => $id, 'search' => [ 'type' => 'string' ] ],
                ],
                'handler'      => static fn (array $args): array => [ 'services' => self::services($args) ],
            ],
            'amelia-list-employees'     => $base + [
                'mode'         => 'read',
                'capability'   => 'amelia_read_employees',
                'description'  => 'Amelia employees with their services, working week, breaks and days off (site-local times)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'search' => [ 'type' => 'string' ] ],
                ],
                'handler'      => static fn (array $args): array => [ 'employees' => self::employees($args) ],
            ],
            'amelia-list-customers'     => $base + [
                'mode'         => 'read',
                'capability'   => 'amelia_read_customers',
                'description'  => 'Amelia customers with their booking counts. search matches name, email or phone; page, per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'search' => [ 'type' => 'string' ] ] + $page,
                ],
                'handler'      => static fn (array $args): array => self::customers($args),
            ],
            'amelia-list-appointments'  => $base + [
                'mode'         => 'read',
                'capability'   => 'amelia_read_appointments',
                'description'  => 'Amelia appointments starting from..to (site-local dates, default the next 30 days) with their customers. Filter by employee_id, service_id, customer_id, status; page, per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'from'        => $date,
                        'to'          => $date,
                        'employee_id' => $id,
                        'service_id'  => $id,
                        'customer_id' => $id,
                        'status'      => [ 'type' => 'string', 'enum' => array_merge(self::STATUSES, [ 'no-show' ]) ],
                    ] + $page,
                ],
                'validate'     => static fn (array $args): ?array => self::range_refusal($args),
                'handler'      => static fn (array $args): array => self::appointments($args),
            ],
            'amelia-get-slots'          => $base + [
                'mode'         => 'read',
                'capability'   => 'amelia_read_appointments',
                'description'  => 'Free slots for a service from a site-local date for days (default 7, max 31), by Amelia\'s own slot calculation; employee_id narrows it',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'service_id'  => $id,
                        'from'        => $date,
                        'days'        => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_SLOT_DAYS ],
                        'employee_id' => $id,
                    ],
                    'required'   => [ 'service_id', 'from' ],
                ],
                'validate'     => static fn (array $args): ?array => self::service_refusal((int) $args['service_id']) ?? self::date_refusal((string) $args['from']) ?? (isset($args['employee_id']) ? self::employee_refusal((int) $args['employee_id'], (int) $args['service_id']) : null),
                'handler'      => static fn (array $args): array => self::slots($args),
            ],
            'amelia-list-events'        => $base + [
                'mode'         => 'read',
                'capability'   => 'amelia_read_events',
                'description'  => 'Amelia events with a period from..to (site-local dates, default the next 90 days), their periods and places left. Filter by status',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'from'   => $date,
                        'to'     => $date,
                        'status' => [ 'type' => 'string', 'enum' => self::STATUSES ],
                    ],
                ],
                'validate'     => static fn (array $args): ?array => self::range_refusal($args),
                'handler'      => static fn (array $args): array => [ 'events' => self::events($args) ],
            ],
            'amelia-list-event-attendees' => $base + [
                'mode'         => 'read',
                'capability'   => 'amelia_read_events',
                'description'  => 'The customers booked on an Amelia event, with status and persons',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'event_id' => $id ],
                    'required'   => [ 'event_id' ],
                ],
                'validate'     => static fn (array $args): ?array => self::exists('events', (int) $args['event_id'], 'Event'),
                'handler'      => static fn (array $args): array => self::attendees((int) $args['event_id']),
            ],
            'amelia-create-category'    => $base + [
                'mode'         => 'write',
                'capability'   => 'amelia_write_services',
                'description'  => 'Create an Amelia service category. Undo: rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'name' => $service['name'], 'status' => $visible ] + $notify,
                    'required'   => [ 'name' ],
                ],
                'snapshot'     => $rows('amelia_category', ''),
                'handler'      => static fn (array $args, array $context): array => self::save_category(0, $args, $context),
            ],
            'amelia-update-category'    => $base + [
                'mode'         => 'write',
                'capability'   => 'amelia_write_services',
                'description'  => 'Rename an Amelia category or set its status. Undo: rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'category_id' => $id, 'name' => $service['name'], 'status' => $visible ] + $notify,
                    'required'   => [ 'category_id' ],
                ],
                'validate'     => static fn (array $args): ?array => self::exists('categories', (int) $args['category_id'], 'Category'),
                'snapshot'     => $rows('amelia_category', 'category_id'),
                'handler'      => static fn (array $args, array $context): array => self::save_category((int) $args['category_id'], $args, $context),
            ],
            'amelia-create-service'     => $base + [
                'mode'         => 'write',
                'capability'   => 'amelia_write_services',
                'description'  => 'Create an Amelia service: name, category_id, duration (minutes), price, employee_ids. Undo: rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => $service + $notify,
                    'required'   => [ 'name', 'category_id', 'duration', 'price' ],
                ],
                'validate'     => static fn (array $args): ?array => self::service_args_refusal($args),
                'snapshot'     => $rows('amelia_service', ''),
                'handler'      => static fn (array $args, array $context): array => self::save_service(0, $args, $context),
            ],
            'amelia-update-service'     => $base + [
                'mode'         => 'write',
                'capability'   => 'amelia_write_services',
                'description'  => 'Change an Amelia service; employee_ids replaces its employees, apply_to_employees:true also sets their own price and capacity. Undo: rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'service_id' => $id, 'apply_to_employees' => [ 'type' => 'boolean' ] ] + $service + $notify,
                    'required'   => [ 'service_id' ],
                ],
                'validate'     => static fn (array $args): ?array => self::service_refusal((int) $args['service_id']) ?? self::service_args_refusal($args),
                'snapshot'     => $rows('amelia_service', 'service_id'),
                'handler'      => static fn (array $args, array $context): array => self::save_service((int) $args['service_id'], $args, $context),
            ],
            'amelia-create-customer'    => $base + [
                'mode'         => 'write',
                'capability'   => 'amelia_write_customers',
                'description'  => 'Create an Amelia customer (an email already in use is refused). Undo: rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => $customer + $notify,
                    'required'   => [ 'first_name' ],
                ],
                'snapshot'     => $rows('amelia_customer', ''),
                'handler'      => static fn (array $args, array $context): array => self::save_customer(0, $args, $context),
            ],
            'amelia-update-customer'    => $base + [
                'mode'         => 'write',
                'capability'   => 'amelia_write_customers',
                'description'  => 'Change an Amelia customer\'s name, email, phone or note. Undo: rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'customer_id' => $id ] + $customer + $notify,
                    'required'   => [ 'customer_id' ],
                ],
                'validate'     => static fn (array $args): ?array => self::customer_refusal((int) $args['customer_id']),
                'snapshot'     => $rows('amelia_customer', 'customer_id'),
                'handler'      => static fn (array $args, array $context): array => self::save_customer((int) $args['customer_id'], $args, $context),
            ],
            'amelia-book-appointment'   => $base + [
                'mode'         => 'write',
                'capability'   => 'amelia_write_appointments',
                'description'  => 'Book a customer on a service with an employee at start (site-local Y-m-d H:i), through Amelia\'s slot and working-hours checks. Customer messages held unless notify:true. Undo: rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'service_id'  => $id,
                        'employee_id' => $id,
                        'customer_id' => $id,
                        'start'       => $time,
                        'persons'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'status'      => [ 'type' => 'string', 'enum' => self::ACTIVE ],
                        'notes'       => [ 'type' => 'string' ],
                    ] + $notify,
                    'required'   => [ 'service_id', 'employee_id', 'customer_id', 'start' ],
                ],
                'validate'     => static fn (array $args): ?array => self::book_refusal($args),
                'snapshot'     => static fn (array $args): array => [ 'object_type' => Amelia_Rows_Snapshot::TYPE, 'object_id' => Amelia_Rows_Snapshot::key('amelia_appointment', self::joinable($args)) ],
                'handler'      => static fn (array $args, array $context): array => self::book($args, $context),
            ],
            'amelia-reschedule-appointment' => $base + [
                'mode'         => 'write',
                'capability'   => 'amelia_write_appointments',
                'description'  => 'Move an Amelia appointment to start (site-local Y-m-d H:i), through Amelia\'s slot and working-hours checks. Customer messages held unless notify:true. Undo: rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'appointment_id' => $id, 'start' => $time ] + $notify,
                    'required'   => [ 'appointment_id', 'start' ],
                ],
                'validate'     => static fn (array $args): ?array => self::exists('appointments', (int) $args['appointment_id'], 'Appointment') ?? self::time_refusal((string) $args['start']),
                'snapshot'     => $rows('amelia_appointment', 'appointment_id'),
                'handler'      => static fn (array $args, array $context): array => self::reschedule($args, $context),
            ],
            'amelia-set-appointment-status' => $base + [
                'mode'         => 'write',
                'capability'   => 'amelia_write_appointments',
                'description'  => 'Approve, set pending, cancel or reject an Amelia appointment (never deleted). Customer messages held unless notify:true. Undo: rollback-operation',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'appointment_id' => $id, 'status' => [ 'type' => 'string', 'enum' => self::STATUSES ] ] + $notify,
                    'required'   => [ 'appointment_id', 'status' ],
                ],
                'validate'     => static fn (array $args): ?array => self::exists('appointments', (int) $args['appointment_id'], 'Appointment') ?? self::status_refusal($args),
                'snapshot'     => $rows('amelia_appointment', 'appointment_id'),
                'handler'      => static fn (array $args, array $context): array => self::set_status($args, $context),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Tables and times
    // ------------------------------------------------------------------

    /** The prefixed name of one of Amelia's tables. */
    private static function t(string $name): string
    {
        global $wpdb;

        if (! in_array($name, self::TABLES, true)) {
            throw new \InvalidArgumentException('Not an Amelia table this pack reads.');
        }
        return $wpdb->prefix . 'amelia_' . $name;
    }

    /**
     * A site-local "Y-m-d H:i" as the UTC "Y-m-d H:i:s" Amelia stores, or
     * null when it is not a time that exists in the site's timezone (a
     * daylight-saving gap, or a malformed date).
     */
    public static function to_utc(string $local): ?string
    {
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $local, wp_timezone());
        if (false === $time || $time->format('Y-m-d H:i') !== $local) {
            return null;
        }
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** A UTC "Y-m-d H:i:s" from Amelia's tables as site-local "Y-m-d H:i". */
    public static function to_local(?string $utc): ?string
    {
        if (null === $utc || '' === $utc) {
            return null;
        }
        try {
            $time = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            unset($e);
            return null;
        }
        return $time->setTimezone(wp_timezone())->format('Y-m-d H:i');
    }

    /** The UTC start of a site-local day, offset by whole days. */
    private static function day_start_utc(string $date, int $days = 0): string
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, wp_timezone());
        $day = false === $day ? new \DateTimeImmutable('today', wp_timezone()) : $day;
        return $day->modify(sprintf('%+d days', $days))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function today(): string
    {
        return (new \DateTimeImmutable('now', wp_timezone()))->format('Y-m-d');
    }

    private static function minutes($seconds): int
    {
        return (int) round(((int) $seconds) / 60);
    }

    // ------------------------------------------------------------------
    // Refusals
    // ------------------------------------------------------------------

    /** @return array{code: string, message: string, data: array} */
    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    private static function exists(string $table, int $id, string $label): ?array
    {
        global $wpdb;

        $found = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE id = %d', self::t($table), $id));
        return $found > 0 ? null : self::refusal('not_found', sprintf('%s %d does not exist in Amelia.', $label, $id));
    }

    private static function service_refusal(int $id): ?array
    {
        return self::exists('services', $id, 'Service');
    }

    private static function customer_refusal(int $id): ?array
    {
        global $wpdb;

        $type = $wpdb->get_var($wpdb->prepare('SELECT type FROM %i WHERE id = %d', self::t('users'), $id));
        return 'customer' === $type ? null : self::refusal('not_found', sprintf('Customer %d does not exist in Amelia.', $id));
    }

    /** Refuses an employee that does not exist, or (with a service) does not provide it. */
    private static function employee_refusal(int $id, int $service_id = 0): ?array
    {
        global $wpdb;

        $type = $wpdb->get_var($wpdb->prepare('SELECT type FROM %i WHERE id = %d', self::t('users'), $id));
        if ('provider' !== $type) {
            return self::refusal('not_found', sprintf('Employee %d does not exist in Amelia.', $id));
        }
        if ($service_id > 0 && 0 === (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE userId = %d AND serviceId = %d', self::t('providers_to_services'), $id, $service_id))) {
            return self::refusal('employee_not_assigned', sprintf('Employee %d does not provide service %d in Amelia.', $id, $service_id));
        }
        return null;
    }

    private static function date_refusal(string $date): ?array
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return false !== $day && $day->format('Y-m-d') === $date ? null : self::refusal('invalid_date', sprintf('"%s" is not a date (Y-m-d).', $date));
    }

    private static function range_refusal(array $args): ?array
    {
        foreach ([ 'from', 'to' ] as $key) {
            if (isset($args[ $key ])) {
                $refused = self::date_refusal((string) $args[ $key ]);
                if (null !== $refused) {
                    return $refused;
                }
            }
        }
        if (isset($args['from'], $args['to']) && (string) $args['to'] < (string) $args['from']) {
            return self::refusal('invalid_range', '"to" is before "from".');
        }
        return null;
    }

    private static function time_refusal(string $start): ?array
    {
        if (null !== self::to_utc($start)) {
            return null;
        }
        return self::refusal('invalid_time', sprintf(
            '"%s" is not a time that exists in the site timezone (%s); a time inside a daylight-saving jump does not exist.',
            $start,
            wp_timezone_string()
        ));
    }

    /** Whether this is Amelia Lite, which keeps no group capacity, buffers or multi-person bookings. */
    private static function lite(): bool
    {
        return 'Lite' === self::api()->edition();
    }

    /** @param array<string, string> $limits arg => what it is */
    private static function lite_refusal(array $limits): ?array
    {
        if ([] === $limits || ! self::lite()) {
            return null;
        }
        return self::refusal(
            'amelia_lite_limit',
            sprintf('Amelia Lite cannot store %s; it needs a paid Amelia edition.', implode(', ', $limits)),
            [ 'fields' => array_keys($limits) ]
        );
    }

    private static function service_args_refusal(array $args): ?array
    {
        if (isset($args['category_id'])) {
            $refused = self::exists('categories', (int) $args['category_id'], 'Category');
            if (null !== $refused) {
                return $refused;
            }
        }
        foreach ((array) ($args['employee_ids'] ?? []) as $employee) {
            $refused = self::employee_refusal((int) $employee);
            if (null !== $refused) {
                return $refused;
            }
        }
        if (isset($args['min_capacity'], $args['max_capacity']) && (int) $args['min_capacity'] > (int) $args['max_capacity']) {
            return self::refusal('invalid_capacity', 'min_capacity is above max_capacity.');
        }
        $limits = [];
        foreach ([ 'min_capacity' => 'a minimum capacity above 1', 'max_capacity' => 'a maximum capacity above 1' ] as $key => $label) {
            if (isset($args[ $key ]) && 1 !== (int) $args[ $key ]) {
                $limits[ $key ] = $label;
            }
        }
        foreach ([ 'time_before' => 'buffer time before', 'time_after' => 'buffer time after' ] as $key => $label) {
            if (! empty($args[ $key ])) {
                $limits[ $key ] = $label;
            }
        }
        return self::lite_refusal($limits);
    }

    private static function book_refusal(array $args): ?array
    {
        return self::service_refusal((int) $args['service_id'])
            ?? self::employee_refusal((int) $args['employee_id'], (int) $args['service_id'])
            ?? self::customer_refusal((int) $args['customer_id'])
            ?? self::time_refusal((string) $args['start'])
            ?? self::lite_refusal((int) ($args['persons'] ?? 1) > 1 ? [ 'persons' => 'more than one person per booking' ] : []);
    }

    private static function status_refusal(array $args): ?array
    {
        $current = self::appointment_row((int) $args['appointment_id']);
        if (null !== $current && (string) $current['status'] === (string) $args['status']) {
            return self::refusal('no_change', sprintf('Appointment %d is already %s.', (int) $args['appointment_id'], (string) $args['status']));
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function settings(): array
    {
        $settings = json_decode((string) get_option('amelia_settings', ''), true);
        return is_array($settings) ? $settings : [];
    }

    private static function setup(): array
    {
        global $wpdb;

        $settings = self::settings();
        $mail     = (array) ($settings['notifications'] ?? []);
        $count    = static fn (string $table, string $where = '1=1'): int => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE ' . $where, self::t($table))); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where is one of the literals below.
        $edition  = self::api()->edition();

        return [
            'edition'             => $edition,
            'premium'             => 'Lite' !== $edition,
            'timezone'            => wp_timezone_string(),
            'currency'            => (string) ($settings['payments']['currency'] ?? 'USD'),
            'slot_length_minutes' => self::minutes($settings['general']['timeSlotLength'] ?? 1800),
            'mail'                => [
                'service'           => (string) ($mail['mailService'] ?? '') ?: 'php',
                'sender_configured' => '' !== (string) ($mail['senderEmail'] ?? '') && '' !== (string) ($mail['senderName'] ?? ''),
            ],
            'counts'              => [
                'categories' => $count('categories'),
                'services'   => $count('services'),
                'employees'  => $count('users', "type = 'provider'"),
                'customers'  => $count('users', "type = 'customer'"),
            ],
        ];
    }

    private static function categories(): array
    {
        global $wpdb;

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT c.*, (SELECT COUNT(*) FROM %i s WHERE s.categoryId = c.id) AS services FROM %i c ORDER BY c.position ASC, c.id ASC',
            self::t('services'),
            self::t('categories')
        ), ARRAY_A);
        return array_map(static fn (array $row): array => self::category_shape($row), $rows);
    }

    private static function category_shape(array $row): array
    {
        return [
            'id'       => (int) $row['id'],
            'name'     => (string) $row['name'],
            'status'   => (string) $row['status'],
            'position' => (int) $row['position'],
            'services' => (int) ($row['services'] ?? 0),
        ];
    }

    private static function services(array $args): array
    {
        global $wpdb;

        $where = '1=1';
        $vars  = [ self::t('services') ];
        if (isset($args['category_id'])) {
            $where .= ' AND categoryId = %d';
            $vars[] = (int) $args['category_id'];
        }
        if ('' !== trim((string) ($args['search'] ?? ''))) {
            $where .= ' AND name LIKE %s';
            $vars[] = '%' . $wpdb->esc_like(trim((string) $args['search'])) . '%';
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where holds only placeholders.
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE ' . $where . ' ORDER BY position ASC, id ASC', ...$vars), ARRAY_A);
        return array_map(static fn (array $row): array => self::service_shape($row), $rows);
    }

    private static function service_row(int $id): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::t('services'), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private static function service_shape(array $row): array
    {
        global $wpdb;

        $employees = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE serviceId = %d ORDER BY userId ASC', self::t('providers_to_services'), (int) $row['id']), ARRAY_A);
        return [
            'id'           => (int) $row['id'],
            'name'         => (string) $row['name'],
            'category_id'  => (int) $row['categoryId'],
            'status'       => (string) $row['status'],
            'description'  => (string) ($row['description'] ?? ''),
            'duration'     => self::minutes($row['duration']),
            'price'        => (float) $row['price'],
            'min_capacity' => (int) $row['minCapacity'],
            'max_capacity' => (int) $row['maxCapacity'],
            'time_before'  => self::minutes($row['timeBefore'] ?? 0),
            'time_after'   => self::minutes($row['timeAfter'] ?? 0),
            'employees'    => array_map(static fn (array $link): array => [
                'employee_id'  => (int) $link['userId'],
                'price'        => (float) $link['price'],
                'min_capacity' => (int) $link['minCapacity'],
                'max_capacity' => (int) $link['maxCapacity'],
            ], $employees),
        ];
    }

    private static function employees(array $args): array
    {
        global $wpdb;

        $where = "type = 'provider'";
        $vars  = [ self::t('users') ];
        if ('' !== trim((string) ($args['search'] ?? ''))) {
            $like   = '%' . $wpdb->esc_like(trim((string) $args['search'])) . '%';
            $where .= ' AND (firstName LIKE %s OR lastName LIKE %s OR email LIKE %s)';
            array_push($vars, $like, $like, $like);
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where holds only placeholders and literals.
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE ' . $where . ' ORDER BY id ASC', ...$vars), ARRAY_A);

        $out = [];
        foreach ($rows as $row) {
            $id    = (int) $row['id'];
            $days  = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE userId = %d ORDER BY dayIndex ASC', self::t('providers_to_weekdays'), $id), ARRAY_A);
            $week  = [];
            foreach ($days as $day) {
                $breaks = (array) $wpdb->get_results($wpdb->prepare('SELECT startTime, endTime FROM %i WHERE weekDayId = %d ORDER BY startTime ASC', self::t('providers_to_timeouts'), (int) $day['id']), ARRAY_A);
                $week[] = [
                    'day'    => self::DAYS[ (int) $day['dayIndex'] ] ?? (string) $day['dayIndex'],
                    'start'  => substr((string) $day['startTime'], 0, 5),
                    'end'    => substr((string) $day['endTime'], 0, 5),
                    'breaks' => array_map(static fn (array $b): array => [ 'start' => substr((string) $b['startTime'], 0, 5), 'end' => substr((string) $b['endTime'], 0, 5) ], $breaks),
                ];
            }
            $off   = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE userId = %d AND type = 'dayOff' ORDER BY startDate ASC", self::t('providers_to_daysoff'), $id), ARRAY_A);
            $out[] = [
                'id'          => $id,
                'first_name'  => (string) $row['firstName'],
                'last_name'   => (string) $row['lastName'],
                'email'       => (string) ($row['email'] ?? ''),
                'phone'       => (string) ($row['phone'] ?? ''),
                'status'      => (string) $row['status'],
                'service_ids' => array_map('intval', (array) $wpdb->get_col($wpdb->prepare('SELECT serviceId FROM %i WHERE userId = %d ORDER BY serviceId ASC', self::t('providers_to_services'), $id))),
                'working_week' => $week,
                'days_off'    => array_map(static fn (array $d): array => [
                    'name'   => (string) $d['name'],
                    'start'  => substr((string) $d['startDate'], 0, 10),
                    'end'    => substr((string) $d['endDate'], 0, 10),
                    'repeat' => (bool) $d['repeat'],
                ], $off),
            ];
        }
        return $out;
    }

    private static function customers(array $args): array
    {
        global $wpdb;

        $per   = (int) ($args['per_page'] ?? 20);
        $page  = (int) ($args['page'] ?? 1);
        $where = "type = 'customer'";
        $vars  = [];
        if ('' !== trim((string) ($args['search'] ?? ''))) {
            $like   = '%' . $wpdb->esc_like(trim((string) $args['search'])) . '%';
            $where .= ' AND (firstName LIKE %s OR lastName LIKE %s OR email LIKE %s OR phone LIKE %s)';
            array_push($vars, $like, $like, $like, $like);
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where holds only placeholders and literals.
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE ' . $where, self::t('users'), ...$vars));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where holds only placeholders and literals.
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE ' . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d', self::t('users'), ...array_merge($vars, [ $per, ($page - 1) * $per ])), ARRAY_A);

        return [
            'total'     => $total,
            'page'      => $page,
            'customers' => array_map(static fn (array $row): array => self::customer_shape($row), $rows),
        ];
    }

    private static function customer_row(int $id): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE id = %d AND type = 'customer'", self::t('users'), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private static function customer_shape(array $row): array
    {
        global $wpdb;

        return [
            'id'         => (int) $row['id'],
            'first_name' => (string) $row['firstName'],
            'last_name'  => (string) $row['lastName'],
            'email'      => (string) ($row['email'] ?? ''),
            'phone'      => (string) ($row['phone'] ?? ''),
            'note'       => (string) ($row['note'] ?? ''),
            'status'     => (string) $row['status'],
            'bookings'   => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE customerId = %d', self::t('customer_bookings'), (int) $row['id'])),
        ];
    }

    private static function appointments(array $args): array
    {
        global $wpdb;

        $from  = (string) ($args['from'] ?? self::today());
        $to    = (string) ($args['to'] ?? gmdate('Y-m-d', (int) strtotime($from . ' +30 days')));
        $per   = (int) ($args['per_page'] ?? 50);
        $page  = (int) ($args['page'] ?? 1);
        $where = 'a.bookingStart >= %s AND a.bookingStart < %s';
        $vars  = [ self::day_start_utc($from), self::day_start_utc($to, 1) ];
        foreach ([ 'employee_id' => 'a.providerId', 'service_id' => 'a.serviceId' ] as $key => $column) {
            if (isset($args[ $key ])) {
                $where .= ' AND ' . $column . ' = %d';
                $vars[] = (int) $args[ $key ];
            }
        }
        if (isset($args['status'])) {
            $where .= ' AND a.status = %s';
            $vars[] = (string) $args['status'];
        }
        if (isset($args['customer_id'])) {
            $where .= ' AND EXISTS (SELECT 1 FROM %i cb WHERE cb.appointmentId = a.id AND cb.customerId = %d)';
            array_push($vars, self::t('customer_bookings'), (int) $args['customer_id']);
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where holds only placeholders.
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i a WHERE ' . $where, self::t('appointments'), ...$vars));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where holds only placeholders.
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT a.* FROM %i a WHERE ' . $where . ' ORDER BY a.bookingStart ASC, a.id ASC LIMIT %d OFFSET %d', self::t('appointments'), ...array_merge($vars, [ $per, ($page - 1) * $per ])), ARRAY_A);

        return [
            'from'         => $from,
            'to'           => $to,
            'timezone'     => wp_timezone_string(),
            'total'        => $total,
            'page'         => $page,
            'appointments' => array_map(static fn (array $row): array => self::appointment_shape($row), $rows),
        ];
    }

    private static function appointment_row(int $id): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::t('appointments'), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private static function appointment_shape(array $row): array
    {
        global $wpdb;

        $bookings = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT cb.id, cb.customerId, cb.status, cb.persons, u.firstName, u.lastName FROM %i cb LEFT JOIN %i u ON u.id = cb.customerId WHERE cb.appointmentId = %d ORDER BY cb.id ASC',
            self::t('customer_bookings'),
            self::t('users'),
            (int) $row['id']
        ), ARRAY_A);

        return [
            'id'          => (int) $row['id'],
            'start'       => self::to_local((string) $row['bookingStart']),
            'end'         => self::to_local((string) $row['bookingEnd']),
            'status'      => (string) $row['status'],
            'service_id'  => (int) $row['serviceId'],
            'employee_id' => (int) $row['providerId'],
            'notes'       => (string) ($row['internalNotes'] ?? ''),
            'customers'   => array_map(static fn (array $b): array => [
                'booking_id'  => (int) $b['id'],
                'customer_id' => (int) $b['customerId'],
                'name'        => trim((string) $b['firstName'] . ' ' . (string) $b['lastName']),
                'status'      => (string) $b['status'],
                'persons'     => (int) $b['persons'],
            ], $bookings),
        ];
    }

    private static function slots(array $args): array
    {
        $service = (int) $args['service_id'];
        $days    = (int) ($args['days'] ?? 7);
        $from    = (string) $args['from'];
        $params  = [
            'serviceId'     => $service,
            'startDateTime' => $from . ' 00:00',
            'persons'       => 1,
        ];
        if (isset($args['employee_id'])) {
            $params['providerIds'] = [ (int) $args['employee_id'] ];
        } else {
            global $wpdb;
            $params['providerIds'] = array_map('intval', (array) $wpdb->get_col($wpdb->prepare('SELECT userId FROM %i WHERE serviceId = %d ORDER BY userId ASC', self::t('providers_to_services'), $service)));
        }

        $data  = self::api()->request('Booking\\Appointment\\GetTimeSlotsController', $params, [], 'GET');
        $last  = gmdate('Y-m-d', (int) strtotime($from . ' +' . ($days - 1) . ' days'));
        $slots = [];
        foreach ((array) ($data['slots'] ?? []) as $date => $times) {
            $date = (string) $date;
            if ($date < $from || $date > $last) {
                continue;
            }
            $list = [];
            foreach ((array) $times as $time => $holders) {
                $list[] = [
                    'time'         => (string) $time,
                    'employee_ids' => array_values(array_unique(array_map(static fn ($holder): int => (int) ((array) $holder)[0], (array) $holders))),
                ];
            }
            $slots[] = [ 'date' => $date, 'times' => $list ];
        }
        $row = self::service_row($service);

        return [
            'service_id' => $service,
            'duration'   => null === $row ? null : self::minutes($row['duration']),
            'from'       => $from,
            'to'         => $last,
            'timezone'   => wp_timezone_string(),
            'slots'      => $slots,
        ];
    }

    private static function events(array $args): array
    {
        global $wpdb;

        $from  = (string) ($args['from'] ?? self::today());
        $to    = (string) ($args['to'] ?? gmdate('Y-m-d', (int) strtotime($from . ' +90 days')));
        $where = 'EXISTS (SELECT 1 FROM %i p WHERE p.eventId = e.id AND p.periodStart < %s AND p.periodEnd >= %s)';
        $vars  = [ self::t('events'), self::t('events_periods'), self::day_start_utc($to, 1), self::day_start_utc($from) ];
        if (isset($args['status'])) {
            $where .= ' AND e.status = %s';
            $vars[] = (string) $args['status'];
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where holds only placeholders.
        $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT e.* FROM %i e WHERE ' . $where . ' ORDER BY e.id ASC', ...$vars), ARRAY_A);

        $out = [];
        foreach ($rows as $row) {
            $id      = (int) $row['id'];
            $periods = (array) $wpdb->get_results($wpdb->prepare('SELECT periodStart, periodEnd FROM %i WHERE eventId = %d ORDER BY periodStart ASC', self::t('events_periods'), $id), ARRAY_A);
            $booked  = self::event_booked($id);
            $out[]   = [
                'id'           => $id,
                'name'         => (string) $row['name'],
                'status'       => (string) $row['status'],
                'price'        => (float) $row['price'],
                'max_capacity' => (int) $row['maxCapacity'],
                'booked'       => $booked,
                'places_left'  => max(0, (int) $row['maxCapacity'] - $booked),
                'periods'      => array_map(static fn (array $p): array => [ 'start' => self::to_local((string) $p['periodStart']), 'end' => self::to_local((string) $p['periodEnd']) ], $periods),
            ];
        }
        return $out;
    }

    /** The event's bookings, each once. @return array<int, array<string, string|null>> */
    private static function event_bookings(int $event_id): array
    {
        global $wpdb;

        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT DISTINCT cb.id, cb.customerId, cb.status, cb.persons, u.firstName, u.lastName, u.email FROM %i cb JOIN %i link ON link.customerBookingId = cb.id JOIN %i p ON p.id = link.eventPeriodId LEFT JOIN %i u ON u.id = cb.customerId WHERE p.eventId = %d ORDER BY cb.id ASC',
            self::t('customer_bookings'),
            self::t('customer_bookings_to_events_periods'),
            self::t('events_periods'),
            self::t('users'),
            $event_id
        ), ARRAY_A);
    }

    private static function event_booked(int $event_id): int
    {
        $persons = 0;
        foreach (self::event_bookings($event_id) as $booking) {
            if (in_array((string) $booking['status'], self::ACTIVE, true)) {
                $persons += (int) $booking['persons'];
            }
        }
        return $persons;
    }

    private static function attendees(int $event_id): array
    {
        return [
            'event_id'  => $event_id,
            'attendees' => array_map(static fn (array $b): array => [
                'booking_id'  => (int) $b['id'],
                'customer_id' => (int) $b['customerId'],
                'name'        => trim((string) $b['firstName'] . ' ' . (string) $b['lastName']),
                'email'       => (string) ($b['email'] ?? ''),
                'status'      => (string) $b['status'],
                'persons'     => (int) $b['persons'],
            ], self::event_bookings($event_id)),
        ];
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /**
     * Run one write through Amelia with its rows tracked into the snapshot
     * and Amelia's customer notifications held unless notify is true. When
     * Amelia refuses and nothing changed, the undo point is voided; when
     * some rows did change, the refusal carries the undo point.
     *
     * @param callable(): mixed            $write
     * @param (callable(mixed): int)|null $id_of
     * @return array{0: mixed, 1: array{sent: array, held: array}}
     */
    private static function run(array $args, array $context, callable $write, ?callable $id_of = null): array
    {
        $operation_id = (string) ($context['operation_id'] ?? '');
        $sent         = [];
        $held         = [];
        $notify       = ! empty($args['notify']);
        $email        = static function ($data) use ($notify, &$sent, &$held) {
            $data = (array) $data;
            $to   = (string) ($data['email'] ?? '');
            $item = [ 'channel' => 'email', 'to' => $to, 'subject' => (string) ($data['subject'] ?? '') ];
            if (! $notify && self::is_customer('email', $to)) {
                $data['skipSending'] = true;
                $held[]              = $item;
            } elseif (empty($data['skipSending'])) {
                $sent[] = $item;
            }
            return $data;
        };
        $sms          = static function ($data) use ($notify, &$sent, &$held) {
            $data = (array) $data;
            $to   = (string) ($data['to'] ?? '');
            $item = [ 'channel' => 'sms', 'to' => $to, 'subject' => '' ];
            if (! $notify && self::is_customer('phone', $to)) {
                $data['skipSending'] = true;
                $held[]              = $item;
            } elseif (empty($data['skipSending'])) {
                $sent[] = $item;
            }
            return $data;
        };

        add_filter('amelia_manipulate_email_data', $email, PHP_INT_MAX);
        add_filter('amelia_manipulate_sms_data', $sms, PHP_INT_MAX);
        try {
            $result = Amelia_Rows_Snapshot::tracked($operation_id, $write, $id_of);
        } catch (Operation_Error $e) {
            if ('' !== $operation_id && Amelia_Rows_Snapshot::unchanged($operation_id)) {
                Snapshot_Store::delete_operation($operation_id);
                throw $e;
            }
            throw new Operation_Error($e->error_code(), $e->getMessage(), $e->error_data() + [ 'operation_id' => $operation_id, 'recoverable' => '' !== $operation_id ]);
        } finally {
            remove_filter('amelia_manipulate_email_data', $email, PHP_INT_MAX);
            remove_filter('amelia_manipulate_sms_data', $sms, PHP_INT_MAX);
        }
        return [ $result, [ 'sent' => $sent, 'held' => $held ] ];
    }

    /** Whether an email address or phone number belongs to an Amelia customer. */
    private static function is_customer(string $field, string $value): bool
    {
        global $wpdb;

        if ('' === trim($value)) {
            return false;
        }
        $column = 'phone' === $field ? 'phone' : 'email';
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE type = 'customer' AND %i = %s", self::t('users'), $column, $value)) > 0;
    }

    /** The id Amelia gave the created entity, from its answer. */
    private static function id_from(string $entity): \Closure
    {
        return static fn ($data): int => (int) (((array) $data)[ $entity ]['id'] ?? 0);
    }

    private static function save_category(int $id, array $args, array $context): array
    {
        global $wpdb;

        $current = 0 === $id ? null : $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::t('categories'), $id), ARRAY_A);
        $fields  = [
            'name'     => sanitize_text_field((string) ($args['name'] ?? $current['name'] ?? '')),
            'status'   => (string) ($args['status'] ?? $current['status'] ?? 'visible'),
            'position' => null === $current ? 1 + (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(position), 0) FROM %i', self::t('categories'))) : (int) $current['position'],
            'color'    => (string) ($current['color'] ?? '#1788FB'),
        ];
        if (0 === $id) {
            [$data, $notes] = self::run($args, $context, static fn (): array => self::api()->request('Bookable\\Category\\AddCategoryController', $fields + [ 'serviceList' => [] ]), self::id_from('category'));
            $id             = (int) ($data['category']['id'] ?? 0);
        } else {
            [, $notes] = self::run($args, $context, static fn (): array => self::api()->request('Bookable\\Category\\UpdateCategoryController', $fields, [ 'id' => $id ]));
        }
        $row = $wpdb->get_row($wpdb->prepare('SELECT c.*, (SELECT COUNT(*) FROM %i s WHERE s.categoryId = c.id) AS services FROM %i c WHERE c.id = %d', self::t('services'), self::t('categories'), $id), ARRAY_A);
        return [ 'category' => is_array($row) ? self::category_shape($row) : null, 'notifications' => $notes ];
    }

    private static function save_service(int $id, array $args, array $context): array
    {
        global $wpdb;

        $map = [
            'name'         => 'name',
            'category_id'  => 'categoryId',
            'price'        => 'price',
            'description'  => 'description',
            'status'       => 'status',
            'min_capacity' => 'minCapacity',
            'max_capacity' => 'maxCapacity',
        ];
        if (0 === $id) {
            $fields = [
                'color'            => '#1788FB',
                'status'           => 'visible',
                'show'             => true,
                'description'      => '',
                'minCapacity'      => 1,
                'maxCapacity'      => 1,
                'providers'        => [],
                'depositPayment'   => 'disabled',
                'deposit'          => 0,
                'recurringCycle'   => 'disabled',
                'recurringSub'     => 'future',
                'recurringPayment' => 0,
            ];
        } else {
            $fields = (array) (self::api()->request('Bookable\\Service\\GetServiceController', [], [ 'id' => $id ], 'GET')['service'] ?? []);
            unset($fields['id'], $fields['category'], $fields['coupons'], $fields['priority']);
            $fields['applyGlobally'] = ! empty($args['apply_to_employees']);
            $fields['providers'] = array_map('intval', (array) $wpdb->get_col($wpdb->prepare('SELECT userId FROM %i WHERE serviceId = %d ORDER BY userId ASC', self::t('providers_to_services'), $id)));
        }
        foreach ($map as $key => $field) {
            if (array_key_exists($key, $args)) {
                $fields[ $field ] = is_string($args[ $key ]) ? sanitize_textarea_field($args[ $key ]) : $args[ $key ];
            }
        }
        foreach ([ 'duration' => 'duration', 'time_before' => 'timeBefore', 'time_after' => 'timeAfter' ] as $key => $field) {
            if (array_key_exists($key, $args)) {
                $fields[ $field ] = 60 * (int) $args[ $key ];
            }
        }
        if (array_key_exists('employee_ids', $args)) {
            $fields['providers'] = array_values(array_unique(array_map('intval', (array) $args['employee_ids'])));
        }

        if (0 === $id) {
            [$data, $notes] = self::run($args, $context, static fn (): array => self::api()->request('Bookable\\Service\\AddServiceController', $fields), self::id_from('service'));
            $id             = (int) ($data['service']['id'] ?? 0);
        } else {
            [, $notes] = self::run($args, $context, static fn (): array => self::api()->request('Bookable\\Service\\UpdateServiceController', $fields, [ 'id' => $id ]));
        }
        $row = self::service_row($id);
        return [ 'service' => null === $row ? null : self::service_shape($row), 'notifications' => $notes ];
    }

    private static function save_customer(int $id, array $args, array $context): array
    {
        $current = 0 === $id ? [] : (array) self::customer_row($id);
        $fields  = [
            'type'       => 'customer',
            'status'     => (string) ($current['status'] ?? 'visible'),
            'externalId' => (string) ($current['externalId'] ?? ''),
            'firstName'  => (string) ($current['firstName'] ?? ''),
            'lastName'   => (string) ($current['lastName'] ?? ''),
            'email'      => (string) ($current['email'] ?? ''),
            'phone'      => (string) ($current['phone'] ?? ''),
            'note'       => (string) ($current['note'] ?? ''),
        ];
        foreach ([ 'first_name' => 'firstName', 'last_name' => 'lastName', 'phone' => 'phone' ] as $key => $field) {
            if (array_key_exists($key, $args)) {
                $fields[ $field ] = sanitize_text_field((string) $args[ $key ]);
            }
        }
        if (array_key_exists('email', $args)) {
            $fields['email'] = sanitize_email((string) $args['email']);
        }
        if (array_key_exists('note', $args)) {
            $fields['note'] = sanitize_textarea_field((string) $args['note']);
        }
        foreach ([ 'birthday', 'gender', 'countryPhoneIso' ] as $field) {
            if (isset($current[ $field ])) {
                $fields[ $field ] = (string) $current[ $field ];
            }
        }

        if (0 === $id) {
            [$data, $notes] = self::run($args, $context, static fn (): array => self::api()->request('User\\Customer\\AddCustomerController', $fields), self::id_from('user'));
            $id             = (int) ($data['user']['id'] ?? 0);
        } else {
            [, $notes] = self::run($args, $context, static fn (): array => self::api()->request('User\\Customer\\UpdateCustomerController', $fields, [ 'id' => $id ]));
        }
        $row = self::customer_row($id);
        return [ 'customer' => null === $row ? null : self::customer_shape($row), 'notifications' => $notes ];
    }

    /**
     * The appointment a booking would join: Amelia adds a booking to an
     * appointment already at that time for the same service and employee
     * instead of making a new one. 0 when there is none.
     */
    private static function joinable(array $args): int
    {
        global $wpdb;

        $utc = self::to_utc((string) ($args['start'] ?? ''));
        if (null === $utc) {
            return 0;
        }
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE serviceId = %d AND providerId = %d AND bookingStart = %s AND status IN (%s, %s) ORDER BY id ASC LIMIT 1',
            self::t('appointments'),
            (int) $args['service_id'],
            (int) $args['employee_id'],
            $utc,
            self::ACTIVE[0],
            self::ACTIVE[1]
        ));
    }

    private static function book(array $args, array $context): array
    {
        $service = (array) self::service_row((int) $args['service_id']);
        $status  = (string) ($args['status'] ?? 'approved');
        $payload = [
            'serviceId'          => (int) $args['service_id'],
            'providerId'         => (int) $args['employee_id'],
            'bookingStart'       => (string) $args['start'],
            'notifyParticipants' => 1,
            'internalNotes'      => sanitize_textarea_field((string) ($args['notes'] ?? '')),
            'locationId'         => null,
            'recurring'          => [],
            'bookings'           => [
                [
                    'customerId'   => (int) $args['customer_id'],
                    'persons'      => (int) ($args['persons'] ?? 1),
                    'status'       => $status,
                    'duration'     => (int) ($service['duration'] ?? 0),
                    'extras'       => [],
                    'customFields' => [],
                ],
            ],
        ];
        [$data, $notes] = self::run($args, $context, static fn (): array => self::api()->request('Booking\\Appointment\\AddAppointmentController', $payload), self::id_from('appointment'));
        $row            = self::appointment_row((int) ($data['appointment']['id'] ?? 0));
        return [ 'appointment' => null === $row ? null : self::appointment_shape($row), 'notifications' => $notes ];
    }

    private static function reschedule(array $args, array $context): array
    {
        $id        = (int) $args['appointment_id'];
        [, $notes] = self::run($args, $context, static fn (): array => self::api()->request('Booking\\Appointment\\UpdateAppointmentTimeController', [ 'bookingStart' => (string) $args['start'] ], [ 'id' => $id ]));
        $row       = self::appointment_row($id);
        return [ 'appointment' => null === $row ? null : self::appointment_shape($row), 'notifications' => $notes ];
    }

    private static function set_status(array $args, array $context): array
    {
        $id        = (int) $args['appointment_id'];
        [, $notes] = self::run($args, $context, static fn (): array => self::api()->request('Booking\\Appointment\\UpdateAppointmentStatusController', [ 'status' => (string) $args['status'] ], [ 'id' => $id ]));
        $row       = self::appointment_row($id);
        return [ 'appointment' => null === $row ? null : self::appointment_shape($row), 'notifications' => $notes ];
    }
}
