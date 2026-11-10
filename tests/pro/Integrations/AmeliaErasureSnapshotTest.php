<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Amelia_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Privacy\Personal_Data;
use WPMCP\Safety\Amelia_Rows_Snapshot;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tests\Support\Fake_Amelia_Api;

require_once __DIR__ . '/../../support/amelia-tables.php';
require_once __DIR__ . '/../../support/amelia-doubles.php';

/**
 * Issue #552: a personal data erasure reaches the person's Amelia rows in
 * WP MCP's undo snapshots, and an undo never writes erased fields back.
 *
 * Amelia's booking rows carry the person's name and phone ('info') and
 * their form answers ('customFields') but no email, and key the person by
 * Amelia's own customer id ('customerId'), so the generic snapshot eraser,
 * which finds a person by email or WordPress user id, never matched them.
 * The Amelia eraser now redacts them in every amelia_rows snapshot, to the
 * same values it writes to the live rows, so an undo of an earlier write
 * still works and puts nothing back. A restore also keeps an erased
 * customer's fields erased even for a snapshot the erasure did not reach.
 *
 * The real Amelia tables and write paths run in the live Amelia group
 * (AmeliaLiveTest).
 */
class AmeliaErasureSnapshotTest extends \WP_UnitTestCase
{
    /** Every other plugin-data plugin, off so only Amelia decides whether the pair is available. */
    private const OTHER_FILTERS = [ 'wpmcp_jetengine_active', 'wpmcp_pods_active', 'wpmcp_translatepress_active', 'wpmcp_buddypress_active', 'wpmcp_tutor_active', 'wpmcp_lifterlms_active', 'wpmcp_tablepress_active', 'wpmcp_fluentcrm_active', 'wpmcp_mailpoet_active' ];

    private const CAPS = [ 'amelia_read_services', 'amelia_read_employees', 'amelia_read_customers', 'amelia_read_appointments', 'amelia_read_events', 'amelia_write_services', 'amelia_write_customers', 'amelia_write_appointments', 'amelia_write_employees', 'amelia_write_events' ];

    /** What Amelia's booking form stores on a booking: the person's name and phone, and their answers. */
    private const SARAH_INFO   = '{"firstName":"Sarah","lastName":"Lane","phone":"+15550100","locale":"en_US"}';
    private const SARAH_FIELDS = '{"1":{"label":"Medical notes","value":"Pregnant, back injury","type":"text"}}';
    private const KIM_INFO     = '{"firstName":"Kim","lastName":"Park","phone":"+15550177","locale":"en_US"}';
    private const KIM_FIELDS   = '{"1":{"label":"Medical notes","value":"Kim answer","type":"text"}}';

    private int $employee;

    private int $service;

    private int $sarah;

    private int $kim;

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_amelia_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_amelia_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        Amelia_Pack::use_api_for_tests(new Fake_Amelia_Api());
        Fake_Amelia_Api::$calls     = [];
        Fake_Amelia_Api::$delivered = [];
        Fake_Amelia_Api::$edition   = 'Lite';
        update_option('timezone_string', 'America/New_York');
        update_option('amelia_settings', wp_json_encode([
            'general'       => [ 'timeSlotLength' => 1800 ],
            'payments'      => [ 'currency' => 'EUR' ],
            'notifications' => [ 'mailService' => 'wp_mail', 'senderEmail' => 'shop@example.com', 'senderName' => 'Shop' ],
        ]));
        $admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        foreach (self::CAPS as $cap) {
            (new \WP_User($admin))->add_cap($cap);
        }
        if (is_multisite()) {
            grant_super_admin($admin);
        }
        wp_set_current_user($admin);
        add_filter('wpmcp_amelia_active', '__return_true');
        foreach (self::OTHER_FILTERS as $filter) {
            add_filter($filter, '__return_false');
        }

        $this->employee = $this->insert('users', [ 'type' => 'provider', 'status' => 'visible', 'firstName' => 'Tom', 'lastName' => 'Hart', 'email' => 'tom@example.com' ]);
        foreach (range(1, 7) as $day) {
            $this->insert('providers_to_weekdays', [ 'userId' => $this->employee, 'dayIndex' => $day, 'startTime' => '09:00:00', 'endTime' => '17:00:00' ]);
        }
        $this->service = $this->insert('services', [ 'name' => 'Massage', 'price' => 50, 'status' => 'visible', 'categoryId' => 0, 'minCapacity' => 1, 'maxCapacity' => 1, 'duration' => 3600, 'priority' => 'least_expensive', 'color' => '#1788FB' ]);
        $this->insert('providers_to_services', [ 'userId' => $this->employee, 'serviceId' => $this->service, 'price' => 55, 'minCapacity' => 1, 'maxCapacity' => 1 ]);
        $this->sarah = $this->insert('users', [ 'type' => 'customer', 'status' => 'visible', 'firstName' => 'Sarah', 'lastName' => 'Lane', 'email' => 'sarah@example.com', 'phone' => '+15550100' ]);
        $this->kim   = $this->insert('users', [ 'type' => 'customer', 'status' => 'visible', 'firstName' => 'Kim', 'lastName' => 'Park', 'email' => 'kim@example.com', 'phone' => '+15550177' ]);
    }

    protected function tearDown(): void
    {
        Amelia_Pack::use_api_for_tests(null);
        Gate::set_pro_for_tests(null);
        foreach (array_merge(self::OTHER_FILTERS, [ 'wpmcp_amelia_active' ]) as $filter) {
            remove_all_filters($filter);
        }
        parent::tearDown();
    }

    private function t(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'amelia_' . $name;
    }

    private function insert(string $name, array $row): int
    {
        global $wpdb;
        $this->assertNotFalse($wpdb->insert($this->t($name), $row), $wpdb->last_error);
        return (int) $wpdb->insert_id;
    }

    private function write(string $op, array $args): array
    {
        $out = (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args, 'confirm' => false ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    /** A site-local weekday at least a week ahead, as Y-m-d. */
    private function day(int $offset = 0): string
    {
        $day = new \DateTimeImmutable('+10 days', wp_timezone());
        while ((int) $day->format('N') >= 6) {
            $day = $day->modify('+1 day');
        }
        return $day->modify('+' . $offset . ' days')->format('Y-m-d');
    }

    /** A booked appointment for $customer at $time, with what Amelia's booking form stores on the booking. */
    private function booked(int $customer, string $time, string $info, string $fields): int
    {
        global $wpdb;
        $id = (int) $this->write('amelia-book-appointment', [ 'service_id' => $this->service, 'employee_id' => $this->employee, 'customer_id' => $customer, 'start' => $this->day() . ' ' . $time ])['result']['appointment']['id'];
        $wpdb->update($this->t('customer_bookings'), [ 'info' => $info, 'customFields' => $fields ], [ 'appointmentId' => $id ]);
        return $id;
    }

    /** The whole erasure request for $email, every eraser in the order WordPress runs them, each paged until done. */
    private function erase(string $email): void
    {
        foreach (Personal_Data::erasers([]) as $key => $eraser) {
            for ($page = 1; $page < 50; $page++) {
                $res = call_user_func($eraser['callback'], $email, $page);
                if (! empty($res['done'])) {
                    continue 2;
                }
            }
            $this->fail($key . ' never finished.');
        }
    }

    /** Every amelia_rows snapshot in the store, decoded, as JSON. */
    private function amelia_snapshots(): string
    {
        $out = [];
        foreach (Snapshot_Store::page_after(0, 1000) as $row) {
            if (Amelia_Rows_Snapshot::TYPE === $row['object_type']) {
                $out[] = Snapshot_Store::get_by_operation((string) $row['operation_id'])['snapshot'];
            }
        }
        $this->assertNotEmpty($out);
        return (string) wp_json_encode($out);
    }

    private function booking(int $appointment): array
    {
        global $wpdb;
        return (array) $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE appointmentId = %d', $this->t('customer_bookings'), $appointment), ARRAY_A);
    }

    private function user(int $id): array
    {
        global $wpdb;
        return (array) $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->t('users'), $id), ARRAY_A);
    }

    public function test_erasure_leaves_no_booking_details_or_profile_of_the_person_in_any_amelia_snapshot(): void
    {
        $sarahs = $this->booked($this->sarah, '10:00', self::SARAH_INFO, self::SARAH_FIELDS);
        $kims   = $this->booked($this->kim, '14:00', self::KIM_INFO, self::KIM_FIELDS);
        $this->write('amelia-set-appointment-status', [ 'appointment_id' => $sarahs, 'status' => 'canceled' ]);
        $this->write('amelia-set-appointment-status', [ 'appointment_id' => $sarahs, 'status' => 'approved' ]);
        $this->write('amelia-reschedule-appointment', [ 'appointment_id' => $sarahs, 'start' => $this->day(1) . ' 11:00' ]);
        $this->write('amelia-update-customer', [ 'customer_id' => $this->sarah, 'note' => 'Prefers mornings' ]);
        $this->write('amelia-set-appointment-status', [ 'appointment_id' => $kims, 'status' => 'canceled' ]);
        $before = $this->amelia_snapshots();
        $this->assertStringContainsString('back injury', $before, 'The snapshots held Sarah\'s answers before the erasure.');

        $this->erase('sarah@example.com');

        $this->assertNull($this->booking($sarahs)['info'], 'The live booking was erased.');
        $after = $this->amelia_snapshots();
        foreach ([ '+15550100', 'back injury', 'sarah@example.com', 'Lane', 'Prefers mornings' ] as $erased) {
            $this->assertStringNotContainsString($erased, $after, 'An Amelia undo snapshot still holds ' . $erased);
        }
        $this->assertStringContainsString('+15550177', $after, 'Kim\'s booking details are not Sarah\'s and stay.');
        $this->assertStringContainsString('Kim answer', $after);
    }

    public function test_undoing_an_earlier_appointment_write_after_erasure_keeps_the_booking_details_erased(): void
    {
        $sarahs = $this->booked($this->sarah, '10:00', self::SARAH_INFO, self::SARAH_FIELDS);
        $cancel = $this->write('amelia-set-appointment-status', [ 'appointment_id' => $sarahs, 'status' => 'canceled' ]);
        $update = $this->write('amelia-update-customer', [ 'customer_id' => $this->sarah, 'phone' => '+15550199' ]);

        $this->erase('sarah@example.com');
        $erased = $this->user($this->sarah);

        $this->assertTrue(Rollback_Service::restore_operation((string) $update['operation_id']), (string) wp_json_encode(Rollback_Service::take_warnings()));
        $this->assertSame($erased, $this->user($this->sarah), 'Undoing the profile update put none of the erased profile back.');

        $this->assertTrue(Rollback_Service::restore_operation((string) $cancel['operation_id']), (string) wp_json_encode(Rollback_Service::take_warnings()));
        $booking = $this->booking($sarahs);
        $this->assertSame('approved', $booking['status'], 'The undo itself still works.');
        $this->assertNull($booking['info']);
        $this->assertNull($booking['customFields']);
    }

    public function test_restoring_a_snapshot_the_erasure_did_not_reach_never_writes_erased_fields_back(): void
    {
        $sarahs = $this->booked($this->sarah, '10:00', self::SARAH_INFO, self::SARAH_FIELDS);
        $cancel = $this->write('amelia-set-appointment-status', [ 'appointment_id' => $sarahs, 'status' => 'canceled' ]);
        $update = $this->write('amelia-update-customer', [ 'customer_id' => $this->sarah, 'phone' => '+15550199' ]);
        // As saved before the erasure: a copy put back from a database
        // backup, or one taken while the erasure ran.
        $saved = [];
        foreach ([ $cancel, $update ] as $out) {
            $saved[ (string) $out['operation_id'] ] = Snapshot_Store::get_by_operation((string) $out['operation_id'])['snapshot'];
        }

        $this->erase('sarah@example.com');
        $erased = $this->user($this->sarah);
        foreach ($saved as $operation => $snapshot) {
            Snapshot_Store::replace_snapshot((int) Snapshot_Store::id_for_operation($operation), $snapshot);
        }

        foreach (array_keys($saved) as $operation) {
            Rollback_Service::restore_operation((string) $operation);
        }
        $this->assertSame($erased, $this->user($this->sarah), 'No erased profile field came back.');
        $booking = $this->booking($sarahs);
        $this->assertNull($booking['info']);
        $this->assertNull($booking['customFields']);
    }
}
