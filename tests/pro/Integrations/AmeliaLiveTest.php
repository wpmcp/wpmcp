<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Privacy\Amelia_Customers;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #499: the Amelia ops against the real Amelia Lite.
 *
 * It runs only in the local gate's live Amelia leg (bin/test-local.sh, or
 * bin/test-local.sh --live-amelia alone), which sets WPMCP_LIVE_AMELIA=1,
 * installs Amelia Lite from wordpress.org on a separate WordPress install,
 * loads it in tests/bootstrap.php and runs its own activation (tables, roles
 * and settings). Amelia's paid editions are not on wordpress.org and are not
 * tested.
 *
 * Every write goes through Amelia's own controllers, so its slot and
 * working-hours checks, its time conversions and its notifications run.
 * Amelia is set to send through wp_mail, and pre_wp_mail captures every
 * message, so no customer notification is ever delivered from here.
 *
 * Everywhere else Amelia is absent and the test is skipped; the live leg
 * runs with --fail-on-skipped so it cannot pass vacuously.
 *
 * @group amelia-live
 */
class AmeliaLiveTest extends \WP_UnitTestCase
{
    private int $admin;

    private int $employee;

    private int $category;

    /** @var array<int, array{to: string, subject: string}> every message wp_mail was asked to send */
    private array $mail = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! defined('AMELIA_PATH') || ! class_exists('AmeliaBooking\\Plugin', false)) {
            $this->markTestSkipped('Needs the real Amelia Lite (bin/test-local.sh --live-amelia, WPMCP_LIVE_AMELIA=1).');
        }
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        // Amelia commits its own transactions, which commits the test's too,
        // so rows outlive a test here: every test starts from empty tables.
        global $wpdb;
        foreach ([ 'appointments', 'customer_bookings', 'payments', 'users', 'services', 'providers_to_services', 'categories', 'providers_to_weekdays', 'providers_to_timeouts', 'providers_to_daysoff', 'notifications_log', 'events', 'events_periods', 'customer_bookings_to_events_periods' ] as $name) {
            $wpdb->query($wpdb->prepare('DELETE FROM %i', $wpdb->prefix . 'amelia_' . $name));
        }
        $wpdb->query($wpdb->prepare('DELETE FROM %i', Snapshot_Store::table_name()));
        update_option('timezone_string', 'America/New_York');
        // Amelia reads the site timezone once per request and keeps it.
        $zone = new \ReflectionProperty(\AmeliaBooking\Domain\Services\DateTime\DateTimeService::class, 'timeZone');
        $zone->setAccessible(true);
        $zone->setValue(null, null);

        $settings                                  = json_decode((string) get_option('amelia_settings'), true);
        $settings['notifications']['mailService']  = 'wp_mail';
        $settings['notifications']['senderEmail']  = 'shop@example.com';
        $settings['notifications']['senderName']   = 'Shop';
        update_option('amelia_settings', wp_json_encode($settings));
        add_filter('pre_wp_mail', [ $this, 'capture_mail' ], 10, 2);

        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        wp_set_current_user($this->admin);

        $this->category = (int) $this->call('write', 'amelia-create-category', [ 'name' => 'Spa' ])['result']['category']['id'];
        $this->employee = $this->employee('tom@example.com');
    }

    protected function tearDown(): void
    {
        remove_filter('pre_wp_mail', [ $this, 'capture_mail' ], 10);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /** Captures a message instead of delivering it. */
    public function capture_mail($return, array $atts): bool
    {
        unset($return);
        $this->mail[] = [ 'to' => is_array($atts['to']) ? implode(',', $atts['to']) : (string) $atts['to'], 'subject' => (string) $atts['subject'] ];
        return true;
    }

    private function call(string $half, string $op, array $args): array
    {
        $out = 'read' === $half ? (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]) : (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function refused(string $op, array $args): array
    {
        $out = (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
        $this->assertArrayHasKey('error', $out, (string) wp_json_encode($out));
        return $out['error'];
    }

    /** An employee working every day 09:00 to 17:00, made through Amelia's own controller (employee writes are not ops yet). */
    private function employee(string $email): int
    {
        $data = \WPMCP\Integrations\Amelia_Pack::api()->request('User\\Provider\\AddProviderController', [
            'type'                         => 'provider',
            'status'                       => 'visible',
            'firstName'                    => 'Tom',
            'lastName'                     => 'Hart',
            'email'                        => $email,
            'externalId'                   => '',
            'phone'                        => '',
            'note'                         => '',
            'description'                  => '',
            'countryPhoneIso'              => '',
            'show'                         => 1,
            'sendEmployeePanelAccessEmail' => false,
            'weekDayList'                  => array_map(static fn (int $day): array => [ 'dayIndex' => $day, 'startTime' => '09:00:00', 'endTime' => '17:00:00', 'timeOutList' => [], 'periodList' => [] ], range(1, 7)),
            'dayOffList'                   => [],
            'specialDayList'               => [],
            'serviceList'                  => [],
            'timeOutList'                  => [],
            'periodList'                   => [],
        ]);
        return (int) $data['user']['id'];
    }

    private function service(): int
    {
        return (int) $this->call('write', 'amelia-create-service', [ 'name' => 'Massage', 'category_id' => $this->category, 'duration' => 60, 'price' => 50, 'employee_ids' => [ $this->employee ] ])['result']['service']['id'];
    }

    private function customer(string $email = 'sarah@example.com'): int
    {
        return (int) $this->call('write', 'amelia-create-customer', [ 'first_name' => 'Sarah', 'last_name' => 'Lane', 'email' => $email, 'phone' => '+15550100' ])['result']['customer']['id'];
    }

    /** Every row of the tables an appointment write touches. */
    private function rows(): array
    {
        global $wpdb;
        $out = [];
        foreach ([ 'appointments', 'customer_bookings', 'payments', 'users', 'services', 'providers_to_services', 'categories' ] as $name) {
            $out[ $name ] = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY id ASC', $wpdb->prefix . 'amelia_' . $name), ARRAY_A);
        }
        return $out;
    }

    /** A site-local weekday a week or more ahead, inside Amelia's booking window. */
    private function day(int $offset = 0): string
    {
        return (new \DateTimeImmutable('+8 days', wp_timezone()))->modify('+' . $offset . ' days')->format('Y-m-d');
    }

    public function test_reads_setup_services_employees_and_free_slots_from_amelia(): void
    {
        $this->assertTrue((new Plugin_Data_Integration())->is_available());
        $service = $this->service();

        $setup = $this->call('read', 'amelia-get-setup', [])['result'];
        $this->assertSame('Lite', $setup['edition']);
        $this->assertFalse($setup['premium']);
        $this->assertSame('America/New_York', $setup['timezone']);
        $this->assertTrue($setup['mail']['sender_configured']);

        $services = $this->call('read', 'amelia-list-services', [ 'category_id' => $this->category ])['result']['services'];
        $this->assertSame(60, $services[0]['duration']);
        $this->assertSame([ $this->employee ], array_column($services[0]['employees'], 'employee_id'));

        $employees = $this->call('read', 'amelia-list-employees', [ 'search' => 'tom@' ])['result']['employees'];
        $this->assertSame([ $service ], $employees[0]['service_ids']);
        $this->assertSame([ 'day' => 'monday', 'start' => '09:00', 'end' => '17:00', 'breaks' => [] ], $employees[0]['working_week'][0]);

        $slots = $this->call('read', 'amelia-get-slots', [ 'service_id' => $service, 'from' => $this->day(), 'days' => 2 ])['result'];
        $this->assertSame($this->day(), $slots['slots'][0]['date']);
        $this->assertSame('09:00', $slots['slots'][0]['times'][0]['time']);
        $this->assertSame([ $this->employee ], $slots['slots'][0]['times'][0]['employee_ids']);
        $this->assertNotContains('16:30', array_column($slots['slots'][0]['times'], 'time'), 'A 60-minute service cannot start half an hour before closing.');
    }

    public function test_a_booking_holds_the_customer_email_runs_ameliass_slot_checks_and_rolls_back_exactly(): void
    {
        $service  = $this->service();
        $customer = $this->customer();
        $before   = $this->rows();
        $day      = $this->day();

        $out = $this->call('write', 'amelia-book-appointment', [ 'service_id' => $service, 'employee_id' => $this->employee, 'customer_id' => $customer, 'start' => $day . ' 14:00' ]);
        $appointment = $out['result']['appointment'];
        $this->assertSame($day . ' 14:00', $appointment['start']);
        $this->assertSame($day . ' 15:00', $appointment['end']);
        $this->assertSame('approved', $appointment['status']);
        $this->assertSame([ 'sarah@example.com' ], array_column($out['result']['notifications']['held'], 'to'));
        $this->assertSame([ 'tom@example.com' ], array_column($out['result']['notifications']['sent'], 'to'));
        $this->assertSame([ 'tom@example.com' ], array_column($this->mail, 'to'), 'Only the employee email reached wp_mail.');

        // Amelia's own reason for a taken slot and for a time outside working hours.
        foreach ([ $day . ' 14:00', $day . ' 20:00' ] as $start) {
            $other = $this->customer('ann' . substr($start, -5, 2) . '@example.com');
            $error = $this->refused('amelia-book-appointment', [ 'service_id' => $service, 'employee_id' => $this->employee, 'customer_id' => $other, 'start' => $start ]);
            $this->assertSame('slot_unavailable', $error['code'], $start);
            $this->assertSame('Amelia refused: Time slot is unavailable', $error['message']);
        }

        // Undo the refusal-free writes, newest first: the booking and the extra customers.
        foreach (Snapshot_Store::list_by_session('default') as $row) {
            $this->assertTrue(Rollback_Service::restore_operation((string) $row['operation_id']), (string) wp_json_encode(Rollback_Service::take_warnings()));
            if ((string) $row['operation_id'] === (string) $out['operation_id']) {
                break;
            }
        }
        $after = $this->rows();
        $this->assertSame($before['appointments'], $after['appointments']);
        $this->assertSame($before['customer_bookings'], $after['customer_bookings']);
        $this->assertSame($before['payments'], $after['payments']);
        $this->assertCount(1, $this->mail, 'The undo sent nothing.');
    }

    public function test_notify_true_lets_amelia_mail_the_customer(): void
    {
        $service  = $this->service();
        $customer = $this->customer();
        $out      = $this->call('write', 'amelia-book-appointment', [ 'service_id' => $service, 'employee_id' => $this->employee, 'customer_id' => $customer, 'start' => $this->day() . ' 10:00', 'notify' => true ]);
        $this->assertSame([], $out['result']['notifications']['held']);
        $this->assertEqualsCanonicalizing([ 'tom@example.com', 'sarah@example.com' ], array_column($this->mail, 'to'));
        $this->assertEqualsCanonicalizing([ 'tom@example.com', 'sarah@example.com' ], array_column($out['result']['notifications']['sent'], 'to'));
    }

    public function test_reschedule_and_cancel_through_amelia_round_trip_daylight_saving_and_roll_back(): void
    {
        $service  = $this->service();
        $customer = $this->customer();
        $zone     = new \DateTimeZone('America/New_York');
        $change   = null;
        foreach ($zone->getTransitions(time() + 3 * DAY_IN_SECONDS, time() + 360 * DAY_IN_SECONDS) as $transition) {
            if ($transition['ts'] > time() + 3 * DAY_IN_SECONDS) {
                $change = (new \DateTimeImmutable('@' . $transition['ts']))->setTimezone($zone);
                break;
            }
        }
        $this->assertNotNull($change);
        $before_day = $change->modify('-1 day')->format('Y-m-d');
        $after_day  = $change->modify('+1 day')->format('Y-m-d');

        $booked = $this->call('write', 'amelia-book-appointment', [ 'service_id' => $service, 'employee_id' => $this->employee, 'customer_id' => $customer, 'start' => $before_day . ' 10:00' ]);
        $id     = (int) $booked['result']['appointment']['id'];
        $state  = $this->rows();

        $moved = $this->call('write', 'amelia-reschedule-appointment', [ 'appointment_id' => $id, 'start' => $after_day . ' 10:00' ]);
        $this->assertSame($after_day . ' 10:00', $moved['result']['appointment']['start']);
        $this->assertSame($after_day . ' 11:00', $moved['result']['appointment']['end']);
        global $wpdb;
        $this->assertSame(
            (new \DateTimeImmutable($after_day . ' 10:00', $zone))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            $wpdb->get_var($wpdb->prepare('SELECT bookingStart FROM %i WHERE id = %d', $wpdb->prefix . 'amelia_appointments', $id))
        );
        $listed = $this->call('read', 'amelia-list-appointments', [ 'from' => $after_day, 'to' => $after_day ])['result']['appointments'];
        $this->assertSame($after_day . ' 10:00', $listed[0]['start']);

        $cancel = $this->call('write', 'amelia-set-appointment-status', [ 'appointment_id' => $id, 'status' => 'canceled' ]);
        $this->assertSame('canceled', $cancel['result']['appointment']['status']);
        $this->assertSame([ 'sarah@example.com' ], array_column($cancel['result']['notifications']['held'], 'to'));

        $mail = count($this->mail);
        $this->assertTrue(Rollback_Service::restore_operation((string) $cancel['operation_id']), (string) wp_json_encode(Rollback_Service::take_warnings()));
        $this->assertTrue(Rollback_Service::restore_operation((string) $moved['operation_id']), (string) wp_json_encode(Rollback_Service::take_warnings()));
        $after = $this->rows();
        $this->assertSame($state['appointments'], $after['appointments']);
        $this->assertSame($state['customer_bookings'], $after['customer_bookings']);
        $this->assertSame($state['payments'], $after['payments']);
        $this->assertCount($mail, $this->mail, 'The undo sent nothing.');
    }

    public function test_undoing_a_cancel_is_refused_once_the_slot_is_booked_again(): void
    {
        $service = $this->service();
        $sarah   = $this->customer();
        $ann     = $this->customer('ann@example.com');
        $start   = $this->day(1) . ' 11:00';

        $first  = $this->call('write', 'amelia-book-appointment', [ 'service_id' => $service, 'employee_id' => $this->employee, 'customer_id' => $sarah, 'start' => $start ]);
        $cancel = $this->call('write', 'amelia-set-appointment-status', [ 'appointment_id' => (int) $first['result']['appointment']['id'], 'status' => 'canceled' ]);
        $this->call('write', 'amelia-book-appointment', [ 'service_id' => $service, 'employee_id' => $this->employee, 'customer_id' => $ann, 'start' => $start ]);

        $before = $this->rows();
        $this->assertFalse(Rollback_Service::restore_operation((string) $cancel['operation_id']));
        $this->assertStringContainsString('would overlap', implode(' ', Rollback_Service::take_warnings()));
        $this->assertSame($before, $this->rows());
    }

    public function test_service_and_customer_updates_keep_what_they_do_not_name_and_roll_back(): void
    {
        $service  = $this->service();
        $customer = $this->customer();
        $before   = $this->rows();

        $update = $this->call('write', 'amelia-update-service', [ 'service_id' => $service, 'price' => 65, 'duration' => 90 ]);
        $this->assertSame(65.0, $update['result']['service']['price']);
        $this->assertSame(90, $update['result']['service']['duration']);
        $this->assertSame([ $this->employee ], array_column($update['result']['service']['employees'], 'employee_id'));
        $this->assertSame(50.0, $update['result']['service']['employees'][0]['price'], 'The employee keeps their own price.');
        $global = $this->call('write', 'amelia-update-service', [ 'service_id' => $service, 'price' => 70, 'apply_to_employees' => true ]);
        $this->assertSame(70.0, $global['result']['service']['employees'][0]['price']);

        $rename = $this->call('write', 'amelia-update-customer', [ 'customer_id' => $customer, 'first_name' => 'Sara' ]);
        $this->assertSame('Sara', $rename['result']['customer']['first_name']);
        $this->assertSame('+15550100', $rename['result']['customer']['phone']);
        $this->assertSame('sarah@example.com', $rename['result']['customer']['email']);

        $error = $this->refused('amelia-create-service', [ 'name' => 'Group class', 'category_id' => $this->category, 'duration' => 60, 'price' => 10, 'max_capacity' => 6 ]);
        $this->assertSame('amelia_lite_limit', $error['code']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $rename['operation_id']), (string) wp_json_encode(Rollback_Service::take_warnings()));
        $this->assertTrue(Rollback_Service::restore_operation((string) $global['operation_id']), (string) wp_json_encode(Rollback_Service::take_warnings()));
        $this->assertTrue(Rollback_Service::restore_operation((string) $update['operation_id']), (string) wp_json_encode(Rollback_Service::take_warnings()));
        $this->assertSame($before, $this->rows());
    }

    public function test_events_read_with_periods_places_left_and_attendees(): void
    {
        $customer = $this->customer();
        $day      = $this->day(2);
        // Event writes are not ops yet: the event and its booking are made
        // through Amelia's own controllers.
        $event = (int) \WPMCP\Integrations\Amelia_Pack::api()->request('Booking\\Event\\AddEventController', [
            'name'           => 'Yoga day',
            'price'          => 20,
            'maxCapacity'    => 10,
            'color'          => '#1a84ee',
            'show'           => true,
            'depositPayment' => 'disabled',
            'deposit'        => 0,
            'periods'        => [ [ 'periodStart' => $day . ' 14:00:00', 'periodEnd' => $day . ' 16:00:00' ] ],
        ])['events'][0]['id'];
        \WPMCP\Integrations\Amelia_Pack::api()->request('Booking\\Appointment\\AddBookingController', [
            'type'                         => 'event',
            'eventId'                      => $event,
            'notifyParticipants'           => 0,
            'runInstantPostBookingActions' => true,
            'isBackendOrCabinet'           => true,
            'payment'                      => [ 'gateway' => 'onSite' ],
            'bookings'                     => [ [ 'eventId' => $event, 'customerId' => $customer, 'persons' => 1, 'status' => 'approved', 'customer' => [ 'id' => $customer, 'firstName' => 'Sarah', 'lastName' => 'Lane', 'email' => 'sarah@example.com', 'phone' => '+15550100', 'externalId' => '', 'countryPhoneIso' => '' ], 'customFields' => [], 'extras' => [], 'utcOffset' => null, 'couponCode' => null, 'deposit' => false ] ],
        ]);

        $events = $this->call('read', 'amelia-list-events', [ 'from' => $day, 'to' => $day ])['result']['events'];
        $this->assertCount(1, $events);
        $this->assertSame('Yoga day', $events[0]['name']);
        $this->assertSame([ [ 'start' => $day . ' 14:00', 'end' => $day . ' 16:00' ] ], $events[0]['periods'], 'Amelia stores event periods in UTC; they read back site-local.');
        $this->assertSame(1, $events[0]['booked']);
        $this->assertSame(9, $events[0]['places_left']);

        $attendees = $this->call('read', 'amelia-list-event-attendees', [ 'event_id' => $event ])['result']['attendees'];
        $this->assertSame([ $customer ], array_column($attendees, 'customer_id'));
        $this->assertSame('sarah@example.com', $attendees[0]['email']);
    }

    public function test_customer_records_export_and_erase_on_ameliass_own_tables(): void
    {
        $service  = $this->service();
        $customer = $this->customer();
        $this->call('write', 'amelia-book-appointment', [ 'service_id' => $service, 'employee_id' => $this->employee, 'customer_id' => $customer, 'start' => $this->day() . ' 09:00' ]);

        $this->assertTrue(Amelia_Customers::available());
        $export = Amelia_Customers::export('sarah@example.com');
        $this->assertCount(2, $export['data']);
        $this->assertContains([ 'name' => 'Service', 'value' => 'Massage' ], $export['data'][1]['data']);

        $erase = Amelia_Customers::erase('sarah@example.com');
        $this->assertTrue($erase['items_removed']);
        $this->assertTrue($erase['items_retained']);
        $this->assertSame([], Amelia_Customers::export('sarah@example.com')['data']);
        $listed = $this->call('read', 'amelia-list-appointments', [ 'from' => $this->day(), 'to' => $this->day() ])['result']['appointments'];
        $this->assertCount(1, $listed, 'The booking itself is kept.');
        $this->assertStringNotContainsString('Lane', (string) wp_json_encode($listed));
    }
}
