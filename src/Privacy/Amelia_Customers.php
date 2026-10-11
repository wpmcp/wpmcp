<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols -- ABSPATH guard is an intentional side effect.
// phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps -- WP-style snake_case class name is intentional.
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- WP-style snake_case method names are intentional.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Amelia keeps its customers in its own tables and registers no privacy exporter of its own; they are read and redacted live.

namespace WPMCP\Privacy;

use WPMCP\Safety\Amelia_Rows_Snapshot;
use WPMCP\Safety\Snapshot;
use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Amelia's customer records in WordPress's personal data export and erasure
 * (issue #499, on the #478 framework). Amelia keeps its customers in its own
 * tables and registers no exporter or eraser for them, and WP MCP's Amelia
 * ops create and change those records, so Personal_Data registers this pair
 * while Amelia is present (wpmcp_amelia_active).
 *
 * The export lists every Amelia customer record under the email: the
 * profile, each appointment booking (service, site-local start, status) and
 * each event booking. The erasure removes the person from those records:
 * name, email, phone, birthday, gender, note and custom field answers on
 * the profile, and the custom field answers and contact details Amelia
 * copies into each booking. The bookings themselves stay, as the business's
 * record of what was booked and paid, so the eraser reports them retained.
 *
 * Amelia's booking rows key the person by Amelia's own customer id and hold
 * no email, so WP MCP's generic undo snapshot eraser cannot find them
 * (issue #552). This eraser therefore also rewrites the person's rows in
 * every Amelia undo snapshot (amelia_rows) to exactly what it wrote live,
 * a page of snapshots at a time: an undo of an earlier write still works
 * and puts none of it back. Amelia_Rows_Snapshot::restore() also keeps an
 * erased customer's fields erased for a snapshot this did not reach. It
 * runs before WP MCP's undo snapshot eraser.
 */
class Amelia_Customers
{
    public const KEY = 'wpmcp-amelia-customers';

    /** What the erasure writes over an Amelia customer profile (amelia_users). */
    public const ERASED_PROFILE = [
        'firstName'    => Snapshot_Redactor::REDACTED,
        'lastName'     => '',
        'email'        => null,
        'phone'        => null,
        'birthday'     => null,
        'gender'       => null,
        'note'         => null,
        'customFields' => null,
        'externalId'   => null,
    ];

    /** What the erasure writes over each of their bookings (amelia_customer_bookings). */
    public const ERASED_BOOKING = [ 'customFields' => null, 'info' => null ];

    private const IDS_PREFIX = 'wpmcp_privacy_amelia_ids_';

    /** Whether Amelia's customer tables are on this site. */
    public static function available(): bool
    {
        return (bool) apply_filters('wpmcp_amelia_active', defined('AMELIA_PATH')) && self::table_exists('users');
    }

    public static function name(): string
    {
        return __('Amelia customer records', 'wpmcp');
    }

    private static function t(string $name): string
    {
        global $wpdb;

        if (! in_array($name, [ 'users', 'customer_bookings', 'appointments', 'services', 'events', 'events_periods', 'customer_bookings_to_events_periods' ], true)) {
            throw new \InvalidArgumentException('Not an Amelia table.');
        }
        return $wpdb->prefix . 'amelia_' . $name;
    }

    private static function table_exists(string $name): bool
    {
        global $wpdb;

        $suppress = $wpdb->suppress_errors(true);
        $columns  = $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', self::t($name)));
        $wpdb->suppress_errors($suppress);
        return [] !== (array) $columns;
    }

    /** A UTC time from Amelia's tables as site-local "Y-m-d H:i". */
    private static function to_local(string $utc): string
    {
        try {
            return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(wp_timezone())->format('Y-m-d H:i');
        } catch (\Exception $e) {
            unset($e);
            return $utc;
        }
    }

    /** @return array<int, array<string, string|null>> the customer rows under one email. */
    private static function customers(string $email): array
    {
        global $wpdb;

        if ('' === $email || ! self::available()) {
            return [];
        }
        return (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE type = 'customer' AND email = %s ORDER BY id ASC", self::t('users'), $email), ARRAY_A);
    }

    public static function export($email, $page = 1): array
    {
        global $wpdb;

        unset($page);
        $items = [];
        $group = self::name();
        $about = __('Customer profiles and bookings kept by the Amelia booking plugin.', 'wpmcp');
        foreach (self::customers(trim((string) $email)) as $customer) {
            $id      = (int) $customer['id'];
            $items[] = Personal_Data::item(self::KEY, $group, $about, 'amelia-customer-' . $id, [
                __('First name', 'wpmcp') => $customer['firstName'],
                __('Last name', 'wpmcp')  => $customer['lastName'],
                __('Email', 'wpmcp')      => $customer['email'],
                __('Phone', 'wpmcp')      => $customer['phone'],
                __('Birthday', 'wpmcp')   => $customer['birthday'],
                __('Gender', 'wpmcp')     => $customer['gender'],
                __('Note', 'wpmcp')       => $customer['note'],
            ]);
            $bookings = (array) $wpdb->get_results($wpdb->prepare(
                'SELECT cb.*, a.bookingStart, s.name AS serviceName, e.name AS eventName FROM %i cb LEFT JOIN %i a ON a.id = cb.appointmentId LEFT JOIN %i s ON s.id = a.serviceId LEFT JOIN %i link ON link.customerBookingId = cb.id LEFT JOIN %i p ON p.id = link.eventPeriodId LEFT JOIN %i e ON e.id = p.eventId WHERE cb.customerId = %d GROUP BY cb.id ORDER BY cb.id ASC',
                self::t('customer_bookings'),
                self::t('appointments'),
                self::t('services'),
                self::t('customer_bookings_to_events_periods'),
                self::t('events_periods'),
                self::t('events'),
                $id
            ), ARRAY_A);
            foreach ($bookings as $booking) {
                $fields = [
                    __('Service', 'wpmcp') => $booking['serviceName'],
                    __('Event', 'wpmcp')   => $booking['eventName'],
                    __('Start', 'wpmcp')   => null === $booking['bookingStart'] ? null : self::to_local((string) $booking['bookingStart']),
                    __('Status', 'wpmcp')  => $booking['status'],
                    __('Persons', 'wpmcp') => $booking['persons'],
                    __('Price', 'wpmcp')   => $booking['price'],
                ];
                foreach ([ 'customFields' => __('Custom fields', 'wpmcp'), 'info' => __('Booking details', 'wpmcp') ] as $column => $label) {
                    $decoded = json_decode((string) $booking[ $column ], true);
                    foreach (is_array($decoded) ? Personal_Data::flatten($decoded, $label) : [] as $name => $value) {
                        $fields[ $name ] = $value;
                    }
                }
                $items[] = Personal_Data::item(self::KEY, $group, $about, 'amelia-booking-' . (int) $booking['id'], $fields);
            }
        }
        return [ 'data' => $items, 'done' => true ];
    }

    /**
     * Page 1 erases the live records and notes the person's Amelia customer
     * ids; every page then rewrites their rows in one page of undo
     * snapshots, until the snapshots run out.
     */
    public static function erase($email, $page = 1): array
    {
        global $wpdb;

        $email    = trim((string) $email);
        $page     = (int) $page;
        $after    = Personal_Data::cursor('erase-amelia-snapshots', $email, $page);
        $key      = self::IDS_PREFIX . md5(strtolower($email));
        $removed  = false;
        $retained = false;
        if ($page <= 1) {
            $ids = [];
            foreach (self::customers($email) as $customer) {
                $id    = (int) $customer['id'];
                $ids[] = $id;
                $wpdb->update(self::t('users'), self::ERASED_PROFILE, [ 'id' => $id ]);
                $removed = true;
                $count   = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE customerId = %d', self::t('customer_bookings'), $id));
                if ($count > 0) {
                    $wpdb->update(self::t('customer_bookings'), self::ERASED_BOOKING, [ 'customerId' => $id ]);
                    $retained = true;
                }
            }
            set_transient($key, $ids, HOUR_IN_SECONDS);
        } else {
            $ids = array_map('intval', (array) get_transient($key));
        }

        $rows = [] === $ids ? [] : Snapshot_Store::page_after($after, Personal_Data::SNAPSHOT_PAGE);
        $last = 0;
        foreach ($rows as $row) {
            $last = (int) $row['id'];
            if (Amelia_Rows_Snapshot::TYPE !== (string) $row['object_type']) {
                continue;
            }
            try {
                $snapshot = Snapshot::unserialize((string) $row['before_blob']);
            } catch (\RuntimeException $e) {
                unset($e);
                continue;
            }
            $erased = Amelia_Rows_Snapshot::erase_customers($snapshot, $ids);
            if (null !== $erased) {
                Snapshot_Store::replace_snapshot($last, $erased);
                $removed = true;
            }
        }
        $done = count($rows) < Personal_Data::SNAPSHOT_PAGE;
        Personal_Data::advance('erase-amelia-snapshots', $email, $last, $done);
        if ($done) {
            delete_transient($key);
        }

        return [
            'items_removed'  => $removed,
            'items_retained' => $retained,
            'messages'       => $retained ? [ __('Amelia bookings were kept as the business record of what was booked, without the person\'s name, contact details or form answers.', 'wpmcp') ] : [],
            'done'           => $done,
        ];
    }
}
