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
    /** Every Amelia table these tests write, directly or through Amelia. */
    private const TABLES = [ 'appointments', 'customer_bookings', 'payments', 'users', 'services', 'providers_to_services', 'categories', 'providers_to_weekdays', 'providers_to_timeouts', 'providers_to_periods', 'providers_to_daysoff', 'providers_to_specialdays', 'providers_to_locations', 'providers_views', 'services_views', 'galleries', 'notifications_log', 'events', 'events_periods', 'events_tags', 'events_to_providers', 'events_to_tickets', 'customer_bookings_to_events_periods' ];

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
        foreach (self::TABLES as $name) {
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

    /** An employee working every day 09:00 to 17:00. */
    private function employee(string $email): int
    {
        $week = array_map(static fn (string $day): array => [ 'day' => $day, 'start' => '09:00', 'end' => '17:00' ], [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ]);
        return (int) $this->call('write', 'amelia-create-employee', [ 'first_name' => 'Tom', 'last_name' => 'Hart', 'email' => $email, 'working_week' => $week ])['result']['employee']['id'];
    }

    /** Every row of every Amelia table these tests write. */
    private function all(): array
    {
        global $wpdb;
        $out = [];
        foreach (self::TABLES as $name) {
            if ('notifications_log' !== $name) {
                $out[ $name ] = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY id ASC', $wpdb->prefix . 'amelia_' . $name), ARRAY_A);
            }
        }
        return $out;
    }

    private function undo(array $out): void
    {
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']), (string) wp_json_encode(Rollback_Service::take_warnings()));
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
        // Made through Amelia's own controllers, independent of the event ops.
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

    public function test_employee_hours_breaks_and_days_off_drive_ameliass_slots_and_checks_and_roll_back(): void
    {
        $customer = $this->customer();
        $before   = $this->all();
        $day     = $this->day();
        $weekday = strtolower((new \DateTimeImmutable($day, wp_timezone()))->format('l'));
        $week    = array_map(static fn (string $name): array => [ 'day' => $name, 'start' => '09:00', 'end' => '17:00', 'breaks' => $name === $weekday ? [ [ 'start' => '12:00', 'end' => '13:00' ] ] : [] ], [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ]);

        $created = $this->call('write', 'amelia-create-employee', [ 'first_name' => 'Anna', 'last_name' => 'Ray', 'email' => 'anna@example.com', 'phone' => '+15550123', 'working_week' => $week, 'days_off' => [ [ 'name' => 'Trip', 'start' => $this->day(1) ] ] ]);
        $anna    = $created['result']['employee'];
        $this->assertSame([ [ 'start' => '12:00', 'end' => '13:00' ] ], array_values(array_filter($anna['working_week'], static fn (array $d): bool => $d['day'] === $weekday))[0]['breaks']);
        $this->assertSame([ [ 'name' => 'Trip', 'start' => $this->day(1), 'end' => $this->day(1), 'repeat' => false ] ], $anna['days_off']);
        $this->assertSame([], $this->mail, 'Creating an employee sends nothing.');
        $service = $this->call('write', 'amelia-create-service', [ 'name' => 'Facial', 'category_id' => $this->category, 'duration' => 60, 'price' => 40, 'employee_ids' => [ $anna['id'] ] ]);
        $sid     = (int) $service['result']['service']['id'];

        $slots = $this->call('read', 'amelia-get-slots', [ 'service_id' => $sid, 'from' => $day, 'days' => 2 ])['result']['slots'];
        $times = array_column($slots[0]['times'], 'time');
        $this->assertSame($day, $slots[0]['date']);
        $this->assertContains('11:00', $times);
        $this->assertNotContains('12:00', $times, 'The break is not free.');
        $this->assertNotContains('12:30', $times);
        $this->assertNotContains($this->day(1), array_column($slots, 'date'), 'The day off has no slots.');

        $error = $this->refused('amelia-book-appointment', [ 'service_id' => $sid, 'employee_id' => $anna['id'], 'customer_id' => $customer, 'start' => $day . ' 12:00' ]);
        $this->assertSame('slot_unavailable', $error['code'], 'Amelia refuses a booking over the break.');

        $hours = $this->call('write', 'amelia-update-employee', [ 'employee_id' => $anna['id'], 'first_name' => 'Ana', 'working_week' => [ [ 'day' => $weekday, 'start' => '10:00', 'end' => '14:00' ] ], 'days_off' => [] ]);
        $this->assertSame('Ana', $hours['result']['employee']['first_name']);
        $this->assertSame('+15550123', $hours['result']['employee']['phone']);
        $this->assertSame([ [ 'day' => $weekday, 'start' => '10:00', 'end' => '14:00', 'breaks' => [] ] ], $hours['result']['employee']['working_week']);
        $this->assertSame([], $hours['result']['employee']['days_off']);
        $error = $this->refused('amelia-book-appointment', [ 'service_id' => $sid, 'employee_id' => $anna['id'], 'customer_id' => $customer, 'start' => $day . ' 15:00' ]);
        $this->assertSame('slot_unavailable', $error['code'], 'Amelia refuses a time outside the new hours.');

        $this->undo($hours);
        $this->assertSame($anna, $this->call('read', 'amelia-list-employees', [ 'search' => 'anna@' ])['result']['employees'][0], 'The undo puts the week, the break and the day off back.');
        $this->undo($service);
        $this->undo($created);
        $this->assertSame($before, $this->all());
    }

    public function test_event_writes_and_attendees_with_a_capacity_check_run_through_amelia_and_roll_back(): void
    {
        $sarah  = $this->customer();
        $ann    = $this->customer('ann@example.com');
        $day    = $this->day(2);
        $before = $this->all();

        $created = $this->call('write', 'amelia-create-event', [ 'name' => 'Yoga day', 'periods' => [ [ 'start' => $day . ' 14:00', 'end' => $day . ' 16:00' ] ], 'max_capacity' => 2, 'price' => 20, 'employee_ids' => [ $this->employee ], 'description' => 'Bring a mat' ]);
        $event   = $created['result']['event'];
        $this->assertSame([ [ 'start' => $day . ' 14:00', 'end' => $day . ' 16:00' ] ], $event['periods']);
        $this->assertSame([ $this->employee ], $event['employee_ids']);
        $this->assertSame('approved', $event['status']);
        global $wpdb;
        $this->assertSame((new \DateTimeImmutable($day . ' 14:00', wp_timezone()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $wpdb->get_var($wpdb->prepare('SELECT periodStart FROM %i WHERE eventId = %d', $wpdb->prefix . 'amelia_events_periods', $event['id'])));
        $made = $this->all();

        $added = $this->call('write', 'amelia-add-event-attendee', [ 'event_id' => $event['id'], 'customer_id' => $sarah ]);
        $this->assertSame(1, $added['result']['event']['places_left']);
        $this->assertSame($sarah, $added['result']['attendee']['customer_id']);
        $this->assertSame([ 'sarah@example.com' ], array_column($added['result']['notifications']['held'], 'to'));
        $this->assertNotContains('sarah@example.com', array_column($this->mail, 'to'), 'The customer email never reached wp_mail.');

        $full = $this->refused('amelia-add-event-attendee', [ 'event_id' => $event['id'], 'customer_id' => $ann, 'persons' => 2 ]);
        $this->assertSame('event_full', $full['code']);
        $this->assertSame([ 'max_capacity' => 2, 'booked' => 1, 'places_left' => 1 ], $full['data']);

        $this->assertFalse(Rollback_Service::restore_operation((string) $created['operation_id']), 'An event booked since cannot be undone from under its attendee.');
        $this->assertStringContainsString('later customer bookings to events periods', implode(' ', Rollback_Service::take_warnings()));

        $update = $this->call('write', 'amelia-update-event', [ 'event_id' => $event['id'], 'name' => 'Yoga evening', 'price' => 25, 'periods' => [ [ 'start' => $day . ' 18:00', 'end' => $day . ' 20:00' ] ] ]);
        $this->assertSame('Yoga evening', $update['result']['event']['name']);
        $this->assertSame([ [ 'start' => $day . ' 18:00', 'end' => $day . ' 20:00' ] ], $update['result']['event']['periods']);
        $this->assertSame([ $this->employee ], $update['result']['event']['employee_ids'], 'An update keeps the employees it does not name.');
        $this->assertSame(1, $update['result']['event']['booked'], 'The attendee stays booked.');
        $this->assertNotContains('sarah@example.com', array_column($this->mail, 'to'));

        $cancel = $this->call('write', 'amelia-set-event-status', [ 'event_id' => $event['id'], 'status' => 'canceled' ]);
        $this->assertSame('rejected', $cancel['result']['event']['status']);
        $this->assertContains('sarah@example.com', array_column($cancel['result']['notifications']['held'], 'to'));
        $this->assertNotContains('sarah@example.com', array_column($this->mail, 'to'));
        $this->assertSame('event_closed', $this->refused('amelia-add-event-attendee', [ 'event_id' => $event['id'], 'customer_id' => $ann ])['code']);

        $mail = count($this->mail);
        $this->undo($cancel);
        $this->undo($update);
        $this->undo($added);
        $this->assertSame($made, $this->all());
        $this->undo($created);
        $this->assertSame($before, $this->all());
        $this->assertCount($mail, $this->mail, 'The undo sent nothing.');
    }

    public function test_deletes_are_refused_while_in_use_and_otherwise_go_through_amelia_and_roll_back(): void
    {
        $service  = $this->service();
        $customer = $this->customer();
        $this->call('write', 'amelia-book-appointment', [ 'service_id' => $service, 'employee_id' => $this->employee, 'customer_id' => $customer, 'start' => $this->day() . ' 10:00' ]);
        $before = $this->all();
        foreach ([
            [ 'amelia-delete-customer', [ 'customer_id' => $customer ] ],
            [ 'amelia-delete-employee', [ 'employee_id' => $this->employee ] ],
            [ 'amelia-delete-service', [ 'service_id' => $service ] ],
            [ 'amelia-delete-category', [ 'category_id' => $this->category ] ],
        ] as [$op, $args]) {
            $this->assertSame('amelia_in_use', $this->refused($op, $args + [ 'confirm' => true ])['code'], $op);
        }
        $this->assertSame($before, $this->all());

        $category = (int) $this->call('write', 'amelia-create-category', [ 'name' => 'Empty' ])['result']['category']['id'];
        $unused   = (int) $this->call('write', 'amelia-create-service', [ 'name' => 'Unbooked', 'category_id' => $this->category, 'duration' => 30, 'price' => 30, 'employee_ids' => [ $this->employee ] ])['result']['service']['id'];
        $ben      = $this->customer('ben@example.com');
        $anna     = (int) $this->call('write', 'amelia-create-employee', [ 'first_name' => 'Anna', 'last_name' => 'Ray', 'email' => 'anna@example.com', 'working_week' => [ [ 'day' => 'monday', 'start' => '09:00', 'end' => '17:00', 'breaks' => [ [ 'start' => '12:00', 'end' => '12:30' ] ] ] ], 'days_off' => [ [ 'name' => 'Trip', 'start' => '2031-01-02' ] ] ])['result']['employee']['id'];
        $before   = $this->all();

        $deleted = [];
        foreach ([
            [ 'amelia-delete-service', [ 'service_id' => $unused ], 'services', $unused ],
            [ 'amelia-delete-category', [ 'category_id' => $category ], 'categories', $category ],
            [ 'amelia-delete-customer', [ 'customer_id' => $ben ], 'users', $ben ],
            [ 'amelia-delete-employee', [ 'employee_id' => $anna ], 'users', $anna ],
        ] as [$op, $args, $table, $id]) {
            $out = $this->call('write', $op, $args + [ 'confirm' => true ]);
            $this->assertSame($id, $out['result']['deleted']['id'], $op);
            $this->assertNotContains((string) $id, array_column($this->all()[ $table ], 'id'), $op);
            $deleted[] = $out;
        }
        global $wpdb;
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE userId = %d', $wpdb->prefix . 'amelia_providers_to_weekdays', $anna)), 'Amelia removed the employee\'s week.');

        foreach (array_reverse($deleted) as $out) {
            $this->undo($out);
        }
        $this->assertSame($before, $this->all());
    }

    public function test_undoing_a_created_customer_service_category_or_employee_is_refused_once_booked(): void
    {
        $category = $this->call('write', 'amelia-create-category', [ 'name' => 'Bodywork' ]);
        $service  = $this->call('write', 'amelia-create-service', [ 'name' => 'Reflexology', 'category_id' => (int) $category['result']['category']['id'], 'duration' => 60, 'price' => 40 ]);
        $customer = $this->call('write', 'amelia-create-customer', [ 'first_name' => 'Ben', 'email' => 'ben@example.com' ]);
        $employee = $this->call('write', 'amelia-create-employee', [ 'first_name' => 'Anna', 'last_name' => 'Ray', 'email' => 'anna@example.com', 'working_week' => array_map(static fn (string $day): array => [ 'day' => $day, 'start' => '09:00', 'end' => '17:00' ], [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ]) ]);
        $sid      = (int) $service['result']['service']['id'];
        $this->call('write', 'amelia-update-service', [ 'service_id' => $sid, 'employee_ids' => [ (int) $employee['result']['employee']['id'] ] ]);
        $this->call('write', 'amelia-book-appointment', [ 'service_id' => $sid, 'employee_id' => (int) $employee['result']['employee']['id'], 'customer_id' => (int) $customer['result']['customer']['id'], 'start' => $this->day() . ' 10:00' ]);
        $before = $this->all();

        foreach ([ [ $customer, 'later customer bookings' ], [ $service, 'later appointments' ], [ $category, 'later services' ], [ $employee, 'later appointments' ] ] as [$out, $why]) {
            $this->assertFalse(Rollback_Service::restore_operation((string) $out['operation_id']));
            $this->assertStringContainsString($why, implode(' ', Rollback_Service::take_warnings()));
        }
        $this->assertSame($before, $this->all());
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
