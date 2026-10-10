<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Amelia_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\MCP\Registrar;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;
use WPMCP\Privacy\Amelia_Customers;
use WPMCP\Privacy\Personal_Data;
use WPMCP\Safety\Amelia_Rows_Snapshot;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tests\Support\Fake_Amelia_Api;

require_once __DIR__ . '/../../support/amelia-tables.php';
require_once __DIR__ . '/../../support/amelia-doubles.php';

/**
 * Amelia bookings (issue #499): paid-tier ops on the plugin-data dispatcher
 * pair, so they add no top-level tools.
 *
 * Amelia is not loaded in the shared test core. Its tables are built from
 * Amelia Lite's own schema (tests/support/amelia-tables.php), presence is
 * driven through wpmcp_amelia_active, and the calls into Amelia's
 * controllers are replaced by a stand-in that writes those tables the way
 * Amelia does (tests/support/amelia-doubles.php). The real controllers run
 * in the live Amelia group (AmeliaLiveTest).
 */
class AmeliaPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'amelia-get-setup'              => 'read',
        'amelia-list-categories'        => 'read',
        'amelia-list-services'          => 'read',
        'amelia-list-employees'         => 'read',
        'amelia-list-customers'         => 'read',
        'amelia-list-appointments'      => 'read',
        'amelia-get-slots'              => 'read',
        'amelia-list-events'            => 'read',
        'amelia-list-event-attendees'   => 'read',
        'amelia-create-category'        => 'write',
        'amelia-update-category'        => 'write',
        'amelia-create-service'         => 'write',
        'amelia-update-service'         => 'write',
        'amelia-create-customer'        => 'write',
        'amelia-update-customer'        => 'write',
        'amelia-book-appointment'       => 'write',
        'amelia-reschedule-appointment' => 'write',
        'amelia-set-appointment-status' => 'write',
        'amelia-create-employee'        => 'write',
        'amelia-update-employee'        => 'write',
        'amelia-create-event'           => 'write',
        'amelia-update-event'           => 'write',
        'amelia-set-event-status'       => 'write',
        'amelia-add-event-attendee'     => 'write',
        'amelia-delete-category'        => 'destructive',
        'amelia-delete-service'         => 'destructive',
        'amelia-delete-employee'        => 'destructive',
        'amelia-delete-customer'        => 'destructive',
    ];

    /** Every other plugin-data plugin, off so only Amelia decides whether the pair is available. */
    private const OTHER_FILTERS = [ 'wpmcp_jetengine_active', 'wpmcp_pods_active', 'wpmcp_translatepress_active', 'wpmcp_buddypress_active', 'wpmcp_tutor_active', 'wpmcp_lifterlms_active', 'wpmcp_tablepress_active', 'wpmcp_fluentcrm_active', 'wpmcp_mailpoet_active' ];

    private const CAPS = [ 'amelia_read_services', 'amelia_read_employees', 'amelia_read_customers', 'amelia_read_appointments', 'amelia_read_events', 'amelia_write_services', 'amelia_write_customers', 'amelia_write_appointments', 'amelia_write_employees', 'amelia_write_events', 'amelia_delete_services', 'amelia_delete_employees', 'amelia_delete_customers' ];

    private int $admin;

    private int $category;

    private int $employee;

    private int $service;

    private int $customer;

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
        $this->admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        $user        = get_userdata($this->admin);
        foreach (self::CAPS as $cap) {
            $user->add_cap($cap);
        }
        wp_set_current_user($this->admin);
        add_filter('wpmcp_amelia_active', '__return_true');
        foreach (self::OTHER_FILTERS as $filter) {
            add_filter($filter, '__return_false');
        }

        $this->category = $this->insert('categories', [ 'name' => 'Spa', 'status' => 'visible', 'position' => 1, 'color' => '#1788FB' ]);
        $this->employee = $this->insert('users', [ 'type' => 'provider', 'status' => 'visible', 'firstName' => 'Tom', 'lastName' => 'Hart', 'email' => 'tom@example.com' ]);
        foreach (range(1, 7) as $day) {
            $weekday = $this->insert('providers_to_weekdays', [ 'userId' => $this->employee, 'dayIndex' => $day, 'startTime' => '09:00:00', 'endTime' => '17:00:00' ]);
            if (1 === $day) {
                $this->insert('providers_to_timeouts', [ 'weekDayId' => $weekday, 'startTime' => '12:00:00', 'endTime' => '13:00:00' ]);
            }
        }
        $this->insert('providers_to_daysoff', [ 'userId' => $this->employee, 'name' => 'Holiday', 'startDate' => '2030-12-25 00:00:00', 'endDate' => '2030-12-26 00:00:00', 'type' => 'dayOff', 'repeat' => 1 ]);
        $this->service = $this->insert('services', [ 'name' => 'Massage', 'price' => 50, 'status' => 'visible', 'categoryId' => $this->category, 'minCapacity' => 1, 'maxCapacity' => 1, 'duration' => 3600, 'priority' => 'least_expensive', 'color' => '#1788FB' ]);
        $this->insert('providers_to_services', [ 'userId' => $this->employee, 'serviceId' => $this->service, 'price' => 55, 'minCapacity' => 1, 'maxCapacity' => 1 ]);
        $this->customer = $this->insert('users', [ 'type' => 'customer', 'status' => 'visible', 'firstName' => 'Sarah', 'lastName' => 'Lane', 'email' => 'sarah@example.com', 'phone' => '+15550100' ]);
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

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out['result'];
    }

    private function code(array $out): string
    {
        return (string) ($out['error']['code'] ?? '');
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

    /** Every row of the tables an appointment write touches. */
    private function booking_state(): array
    {
        global $wpdb;
        $out = [];
        foreach ([ 'appointments', 'customer_bookings', 'payments', 'users', 'services', 'providers_to_services', 'categories' ] as $name) {
            $out[ $name ] = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY id ASC', $this->t($name)), ARRAY_A);
        }
        return $out;
    }

    /** Every row of the tables an employee write touches. */
    private function schedule_state(): array
    {
        global $wpdb;
        $out = [];
        foreach ([ 'users', 'providers_to_services', 'providers_to_weekdays', 'providers_to_timeouts', 'providers_to_periods', 'providers_to_daysoff', 'appointments' ] as $name) {
            $out[ $name ] = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY id ASC', $this->t($name)), ARRAY_A);
        }
        return $out;
    }

    /** Every row of the tables an event write touches. */
    private function event_state(): array
    {
        global $wpdb;
        $out = [];
        foreach ([ 'events', 'events_periods', 'events_tags', 'events_to_providers', 'customer_bookings', 'customer_bookings_to_events_periods', 'payments' ] as $name) {
            $out[ $name ] = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY id ASC', $this->t($name)), ARRAY_A);
        }
        return $out;
    }

    /** Every Amelia row a delete could touch. */
    private function all_state(): array
    {
        global $wpdb;
        $out = [];
        foreach (array_keys(wpmcp_test_amelia_tables()) as $name) {
            $out[ $name ] = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY id ASC', $this->t($name)), ARRAY_A);
        }
        return $out;
    }

    private function undo(array $out): void
    {
        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']), (string) wp_json_encode(Rollback_Service::take_warnings()));
    }

    /** A site-local weekday (Mon-Fri) at least a week ahead, as Y-m-d. */
    private function future_day(int $offset = 0): string
    {
        $day = new \DateTimeImmutable('+10 days', wp_timezone());
        while ((int) $day->format('N') >= 6) {
            $day = $day->modify('+1 day');
        }
        return $day->modify('+' . $offset . ' days')->format('Y-m-d');
    }

    /** The English weekday name of future_day(). */
    private function day_name(): string
    {
        return (new \DateTimeImmutable($this->future_day(), wp_timezone()))->format('l');
    }

    private function book(string $start, array $extra = []): array
    {
        return $this->write('amelia-book-appointment', [ 'service_id' => $this->service, 'employee_id' => $this->employee, 'customer_id' => $this->customer, 'start' => $start ] + $extra);
    }

    // ---------------------------------------------------------------
    // Catalog, presence and budget
    // ---------------------------------------------------------------

    public function test_every_amelia_op_is_paid_tier_on_the_pair_with_ameliass_own_capability(): void
    {
        $ops = [];
        foreach ((new Plugin_Data_Integration())->catalog()['operations'] as $op) {
            $ops[ $op['name'] ] = $op;
        }
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops, $name);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
            $this->assertStringStartsWith('amelia_', (string) $ops[ $name ]['capability'], $name);
        }
        $this->assertSame('amelia_read_customers', $ops['amelia-list-customers']['capability']);
        $this->assertSame('amelia_write_appointments', $ops['amelia-book-appointment']['capability']);
        $this->assertArrayNotHasKey('amelia-delete-appointment', $ops, 'Appointments are cancelled, never deleted.');
        $this->assertArrayNotHasKey('amelia-delete-event', $ops, 'Events are cancelled, never deleted.');
        $this->assertSame('amelia_write_employees', $ops['amelia-update-employee']['capability']);
        $this->assertSame('amelia_write_events', $ops['amelia-add-event-attendee']['capability']);
        $this->assertSame('amelia_delete_customers', $ops['amelia-delete-customer']['capability']);
        $this->assertTrue($ops['amelia-delete-service']['requires_confirm']);
    }

    public function test_the_pair_is_available_with_amelia_alone_and_not_without_it_or_without_pro(): void
    {
        $this->assertTrue((new Plugin_Data_Integration())->is_available());

        remove_all_filters('wpmcp_amelia_active');
        add_filter('wpmcp_amelia_active', '__return_false');
        $this->assertFalse((new Plugin_Data_Integration())->is_available());

        remove_all_filters('wpmcp_amelia_active');
        add_filter('wpmcp_amelia_active', '__return_true');
        Gate::set_pro_for_tests(false);
        $integration = new Plugin_Data_Integration();
        $this->assertFalse($integration->is_available());
        $this->assertNotContains('amelia-list-services', array_column($integration->catalog()['operations'], 'name'));
    }

    public function test_while_amelia_is_inactive_its_ops_answer_amelia_inactive_and_touch_nothing(): void
    {
        remove_all_filters('wpmcp_amelia_active');
        add_filter('wpmcp_amelia_active', '__return_false');
        add_filter('wpmcp_buddypress_active', '__return_true');
        $before = $this->booking_state();
        $this->assertSame('amelia_inactive', $this->code($this->read('amelia-list-services')));
        $this->assertSame('amelia_inactive', $this->code($this->book($this->future_day() . ' 10:00')));
        $this->assertSame($before, $this->booking_state());
        $this->assertSame([], Fake_Amelia_Api::$calls);
    }

    /**
     * On a site where Amelia is the only plugin-data plugin, the pair
     * registers (two tools) through the real registration path, and the whole
     * tools/list payload stays within the byte budget ToolsListBudgetTest
     * pins.
     */
    public function test_with_amelia_alone_the_pair_registers_and_tools_list_stays_within_budget(): void
    {
        $plugin   = Plugin::instance();
        $prop     = new \ReflectionProperty(Plugin::class, 'registrar');
        $original = $prop->getValue($plugin);
        $prop->setValue($plugin, new Registrar());
        try {
            $plugin->register_abilities();
            $abilities = $plugin->registrar()->all();
        } finally {
            $prop->setValue($plugin, $original);
        }
        $names = array_map(static fn ($a) => $a->name, $abilities);
        $this->assertContains('wpmcp/plugin-data-read', $names);
        $this->assertContains('wpmcp/plugin-data-write', $names);

        $payload = [];
        foreach ($abilities as $ability) {
            $payload[] = [
                'name'        => $ability->name,
                'description' => $ability->description,
                'inputSchema' => $ability->input_schema,
                'annotations' => [ 'readOnlyHint' => $ability->read_only_hint, 'destructiveHint' => $ability->destructive_hint, 'idempotentHint' => $ability->idempotent_hint ],
            ];
        }
        $budget = (new \ReflectionClassConstant(\WPMCP\Tests\Free\Platform\ToolsListBudgetTest::class, 'TOOLS_LIST_BYTE_BUDGET'))->getValue();
        $bytes  = strlen((string) wp_json_encode($payload));
        $this->assertLessThanOrEqual($budget, $bytes, sprintf('%d bytes over %d tools', $bytes, count($payload)));
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_setup_reports_edition_timezone_currency_slot_length_and_mail_sender(): void
    {
        $setup = $this->ok($this->read('amelia-get-setup'));
        $this->assertSame('Lite', $setup['edition']);
        $this->assertFalse($setup['premium']);
        $this->assertSame('America/New_York', $setup['timezone']);
        $this->assertSame('EUR', $setup['currency']);
        $this->assertSame(30, $setup['slot_length_minutes']);
        $this->assertTrue($setup['mail']['sender_configured']);
        $this->assertSame('wp_mail', $setup['mail']['service']);
        $this->assertSame([ 'categories' => 1, 'services' => 1, 'employees' => 1, 'customers' => 1 ], $setup['counts']);
    }

    public function test_categories_services_and_employees_read_with_minutes_prices_and_working_week(): void
    {
        $this->assertSame([ [ 'id' => $this->category, 'name' => 'Spa', 'status' => 'visible', 'position' => 1, 'services' => 1 ] ], $this->ok($this->read('amelia-list-categories'))['categories']);

        $services = $this->ok($this->read('amelia-list-services', [ 'category_id' => $this->category ]))['services'];
        $this->assertCount(1, $services);
        $this->assertSame(60, $services[0]['duration']);
        $this->assertSame(50.0, $services[0]['price']);
        $this->assertSame([ [ 'employee_id' => $this->employee, 'price' => 55.0, 'min_capacity' => 1, 'max_capacity' => 1 ] ], $services[0]['employees']);
        $this->assertSame([], $this->ok($this->read('amelia-list-services', [ 'search' => 'Yoga' ]))['services']);

        $employees = $this->ok($this->read('amelia-list-employees', [ 'search' => 'tom@' ]))['employees'];
        $this->assertCount(1, $employees);
        $this->assertSame([ $this->service ], $employees[0]['service_ids']);
        $this->assertSame([ 'day' => 'monday', 'start' => '09:00', 'end' => '17:00', 'breaks' => [ [ 'start' => '12:00', 'end' => '13:00' ] ] ], $employees[0]['working_week'][0]);
        $this->assertCount(7, $employees[0]['working_week']);
        $this->assertSame([ [ 'name' => 'Holiday', 'start' => '2030-12-25', 'end' => '2030-12-26', 'repeat' => true ] ], $employees[0]['days_off']);
    }

    public function test_customers_and_appointments_read_in_site_local_time_with_filters(): void
    {
        $other = $this->insert('users', [ 'type' => 'customer', 'status' => 'visible', 'firstName' => 'Ann', 'lastName' => 'Bell', 'email' => 'ann@example.com' ]);
        $day   = $this->future_day();
        $this->ok($this->book($day . ' 10:00'));
        $this->ok($this->write('amelia-book-appointment', [ 'service_id' => $this->service, 'employee_id' => $this->employee, 'customer_id' => $other, 'start' => $day . ' 14:00' ]));

        $customers = $this->ok($this->read('amelia-list-customers', [ 'search' => 'lane' ]));
        $this->assertSame(1, $customers['total']);
        $this->assertSame([ 'id' => $this->customer, 'first_name' => 'Sarah', 'last_name' => 'Lane', 'email' => 'sarah@example.com', 'phone' => '+15550100', 'note' => '', 'status' => 'visible', 'bookings' => 1 ], $customers['customers'][0]);
        $this->assertCount(1, $this->ok($this->read('amelia-list-customers', [ 'per_page' => 1, 'page' => 2 ]))['customers']);

        $all = $this->ok($this->read('amelia-list-appointments', [ 'from' => $day, 'to' => $day ]));
        $this->assertSame(2, $all['total']);
        $this->assertSame($day . ' 10:00', $all['appointments'][0]['start']);
        $this->assertSame($day . ' 11:00', $all['appointments'][0]['end']);
        $this->assertSame([ [ 'booking_id' => $all['appointments'][0]['customers'][0]['booking_id'], 'customer_id' => $this->customer, 'name' => 'Sarah Lane', 'status' => 'approved', 'persons' => 1 ] ], $all['appointments'][0]['customers']);

        $mine = $this->ok($this->read('amelia-list-appointments', [ 'from' => $day, 'to' => $day, 'customer_id' => $other ]));
        $this->assertSame(1, $mine['total']);
        $this->assertSame($day . ' 14:00', $mine['appointments'][0]['start']);
        $this->assertSame(0, $this->ok($this->read('amelia-list-appointments', [ 'from' => $day, 'to' => $day, 'status' => 'canceled' ]))['total']);
        $this->assertSame('invalid_range', $this->code($this->read('amelia-list-appointments', [ 'from' => $day, 'to' => '2000-01-01' ])));
    }

    public function test_slots_come_from_ameliass_slot_calculation_grouped_by_day(): void
    {
        $day = $this->future_day();
        $this->ok($this->book($day . ' 10:00'));
        $slots = $this->ok($this->read('amelia-get-slots', [ 'service_id' => $this->service, 'from' => $day, 'days' => 2 ]));
        $this->assertSame('Booking\\Appointment\\GetTimeSlotsController', end(Fake_Amelia_Api::$calls)[0]);
        $this->assertSame([ $this->employee ], end(Fake_Amelia_Api::$calls)[1]['providerIds']);
        $this->assertCount(2, $slots['slots']);
        $this->assertSame($day, $slots['slots'][0]['date']);
        $times = array_column($slots['slots'][0]['times'], 'time');
        $this->assertContains('09:00', $times);
        $this->assertNotContains('10:00', $times, 'A booked time is not free.');
        $this->assertSame([ $this->employee ], $slots['slots'][0]['times'][0]['employee_ids']);
        $this->assertSame(60, $slots['duration']);

        $this->assertSame('not_found', $this->code($this->read('amelia-get-slots', [ 'service_id' => 999, 'from' => $day ])));
        $loner = $this->insert('users', [ 'type' => 'provider', 'status' => 'visible', 'firstName' => 'Anna', 'lastName' => 'Ray', 'email' => 'anna@example.com' ]);
        $this->assertSame('employee_not_assigned', $this->code($this->read('amelia-get-slots', [ 'service_id' => $this->service, 'from' => $day, 'employee_id' => $loner ])));
    }

    public function test_events_report_periods_and_places_left_and_list_their_attendees(): void
    {
        $day    = $this->future_day();
        $event  = $this->insert('events', [ 'name' => 'Yoga day', 'status' => 'approved', 'maxCapacity' => 10, 'price' => 20, 'notifyParticipants' => 1, 'created' => gmdate('Y-m-d H:i:s') ]);
        $period = $this->insert('events_periods', [ 'eventId' => $event, 'periodStart' => $day . ' 14:00:00', 'periodEnd' => $day . ' 16:00:00' ]);
        foreach ([ [ 'approved', 3 ], [ 'canceled', 2 ] ] as [$status, $persons]) {
            $booking = $this->insert('customer_bookings', [ 'customerId' => $this->customer, 'status' => $status, 'price' => 20, 'persons' => $persons ]);
            $this->insert('customer_bookings_to_events_periods', [ 'customerBookingId' => $booking, 'eventPeriodId' => $period ]);
        }

        $events = $this->ok($this->read('amelia-list-events', [ 'from' => $day, 'to' => $day ]))['events'];
        $this->assertCount(1, $events);
        $this->assertSame(3, $events[0]['booked']);
        $this->assertSame(7, $events[0]['places_left']);
        $this->assertSame([ [ 'start' => (new \DateTimeImmutable($day . ' 14:00', new \DateTimeZone('UTC')))->setTimezone(wp_timezone())->format('Y-m-d H:i'), 'end' => (new \DateTimeImmutable($day . ' 16:00', new \DateTimeZone('UTC')))->setTimezone(wp_timezone())->format('Y-m-d H:i') ] ], $events[0]['periods']);
        $this->assertSame([], $this->ok($this->read('amelia-list-events', [ 'from' => $day, 'to' => $day, 'status' => 'canceled' ]))['events']);

        $attendees = $this->ok($this->read('amelia-list-event-attendees', [ 'event_id' => $event ]))['attendees'];
        $this->assertCount(2, $attendees);
        $this->assertSame([ 'approved', 'canceled' ], array_column($attendees, 'status'));
        $this->assertSame('sarah@example.com', $attendees[0]['email']);
        $this->assertSame('not_found', $this->code($this->read('amelia-list-event-attendees', [ 'event_id' => 999 ])));
    }

    // ---------------------------------------------------------------
    // Category, service and customer writes
    // ---------------------------------------------------------------

    public function test_category_service_and_customer_writes_go_through_amelia_and_roll_back_exactly(): void
    {
        $before = $this->booking_state();

        $category = $this->write('amelia-create-category', [ 'name' => 'Massages' ]);
        $this->assertSame('Bookable\\Category\\AddCategoryController', Fake_Amelia_Api::$calls[0][0]);
        $this->assertSame(2, $this->ok($category)['category']['position']);
        $cat_id = $this->ok($category)['category']['id'];

        $service = $this->write('amelia-create-service', [ 'name' => 'Deep tissue', 'category_id' => $cat_id, 'duration' => 45, 'price' => 70, 'employee_ids' => [ $this->employee ] ]);
        $created = $this->ok($service)['service'];
        $this->assertSame(45, $created['duration']);
        $this->assertSame(2700, (int) end(Fake_Amelia_Api::$calls)[1]['duration'], 'Minutes go to Amelia as seconds.');
        $this->assertSame([ $this->employee ], array_column($created['employees'], 'employee_id'));

        $update = $this->write('amelia-update-service', [ 'service_id' => $created['id'], 'price' => 75, 'duration' => 60 ]);
        $this->assertSame(75.0, $this->ok($update)['service']['price']);
        $this->assertSame([ $this->employee ], array_column($this->ok($update)['service']['employees'], 'employee_id'), 'An update keeps the employees it did not name.');
        $this->assertSame(70.0, $this->ok($update)['service']['employees'][0]['price'], 'An employee keeps their own price.');
        $this->assertFalse(end(Fake_Amelia_Api::$calls)[1]['applyGlobally']);
        $global = $this->write('amelia-update-service', [ 'service_id' => $created['id'], 'price' => 80, 'apply_to_employees' => true ]);
        $this->assertSame(80.0, $this->ok($global)['service']['employees'][0]['price']);

        $customer = $this->write('amelia-create-customer', [ 'first_name' => 'Ben', 'last_name' => 'Ode', 'email' => 'ben@example.com' ]);
        $this->assertSame('ben@example.com', $this->ok($customer)['customer']['email']);
        $rename = $this->write('amelia-update-customer', [ 'customer_id' => $this->customer, 'phone' => '+15550199' ]);
        $this->assertSame('Sarah', $this->ok($rename)['customer']['first_name'], 'An update keeps the fields it did not name.');
        $this->assertSame('+15550199', $this->ok($rename)['customer']['phone']);
        $this->assertSame('amelia_refused', $this->code($this->write('amelia-create-customer', [ 'first_name' => 'Dup', 'email' => 'ben@example.com' ])));
        $this->ok($this->write('amelia-update-category', [ 'category_id' => $cat_id, 'name' => 'Bodywork', 'status' => 'hidden' ]));

        foreach ([ $category, $service, $update, $global, $customer, $rename ] as $out) {
            $this->assertTrue($out['recoverable']);
        }
        $this->assertSame([], array_column($this->ok($customer)['notifications'], 'to'));

        foreach (Snapshot_Store::list_by_session('default') as $row) {
            $this->assertTrue(Rollback_Service::restore_operation((string) $row['operation_id']), (string) wp_json_encode(Rollback_Service::take_warnings()));
        }
        $this->assertSame($before, $this->booking_state());
    }

    public function test_what_amelia_lite_cannot_store_is_refused_by_name_and_allowed_on_a_paid_edition(): void
    {
        $out = $this->write('amelia-create-service', [ 'name' => 'Group', 'category_id' => $this->category, 'duration' => 60, 'price' => 10, 'max_capacity' => 5, 'time_after' => 15 ]);
        $this->assertSame('amelia_lite_limit', $this->code($out));
        $this->assertSame([ 'max_capacity', 'time_after' ], $out['error']['data']['fields']);
        $this->assertStringContainsString('buffer time after', $out['error']['message']);
        $this->assertSame('amelia_lite_limit', $this->code($this->book($this->future_day() . ' 10:00', [ 'persons' => 2 ])));
        $this->assertSame([], Fake_Amelia_Api::$calls, 'Nothing reaches Amelia.');
        $this->assertSame([], Snapshot_Store::list_by_session('default'), 'No undo point is left.');

        Fake_Amelia_Api::$edition = 'Pro';
        $paid = $this->ok($this->write('amelia-create-service', [ 'name' => 'Group', 'category_id' => $this->category, 'duration' => 60, 'price' => 10, 'max_capacity' => 5, 'time_after' => 15 ]))['service'];
        $this->assertSame(5, $paid['max_capacity']);
        $this->assertSame(15, $paid['time_after']);
        $this->assertTrue($this->ok($this->read('amelia-get-setup'))['premium']);
    }

    // ---------------------------------------------------------------
    // Appointments
    // ---------------------------------------------------------------

    public function test_a_booking_holds_customer_messages_unless_notify_and_says_what_was_sent(): void
    {
        $day = $this->future_day();
        $out = $this->ok($this->book($day . ' 10:00'));
        $this->assertSame($day . ' 10:00', $out['appointment']['start']);
        $this->assertSame([ [ 'channel' => 'email', 'to' => 'tom@example.com', 'subject' => 'Massage Appointment Approved' ] ], $out['notifications']['sent']);
        $this->assertSame([ [ 'channel' => 'email', 'to' => 'sarah@example.com', 'subject' => 'Massage Appointment Approved' ] ], $out['notifications']['held']);
        $this->assertSame([ 'tom@example.com' ], array_column(Fake_Amelia_Api::$delivered, 'to'), 'The customer email never left.');
        $this->assertSame(1, (int) end(Fake_Amelia_Api::$calls)[1]['notifyParticipants'], 'Amelia\'s own customer setting on the appointment is left on.');

        Fake_Amelia_Api::$delivered = [];
        $loud                        = $this->ok($this->book($day . ' 14:00', [ 'notify' => true ]));
        $this->assertSame([ 'tom@example.com', 'sarah@example.com' ], array_column($loud['notifications']['sent'], 'to'));
        $this->assertSame([], $loud['notifications']['held']);
        $this->assertSame([ 'tom@example.com', 'sarah@example.com' ], array_column(Fake_Amelia_Api::$delivered, 'to'));
        $this->assertFalse(has_filter('amelia_manipulate_email_data'), 'The hold is lifted after the write.');
    }

    public function test_a_taken_slot_or_a_time_outside_working_hours_is_refused_with_ameliass_reason_and_no_undo_point(): void
    {
        $day = $this->future_day();
        $this->ok($this->book($day . ' 10:00'));
        $before = $this->booking_state();
        $count  = count(Snapshot_Store::list_by_session('default'));

        foreach ([ $day . ' 10:30', $day . ' 20:00' ] as $start) {
            $out = $this->book($start);
            $this->assertSame('slot_unavailable', $this->code($out), $start);
            $this->assertSame('Amelia refused: Time slot is unavailable', $out['error']['message']);
            $this->assertTrue($out['error']['data']['amelia']['timeSlotUnavailable']);
        }
        $this->assertSame($before, $this->booking_state());
        $this->assertCount($count, Snapshot_Store::list_by_session('default'), 'A refused write leaves no undo point.');

        $loner = $this->insert('users', [ 'type' => 'provider', 'status' => 'visible', 'firstName' => 'Anna', 'lastName' => 'Ray', 'email' => 'anna@example.com' ]);
        $this->assertSame('employee_not_assigned', $this->code($this->write('amelia-book-appointment', [ 'service_id' => $this->service, 'employee_id' => $loner, 'customer_id' => $this->customer, 'start' => $day . ' 11:00' ])));
        $this->assertSame('not_found', $this->code($this->write('amelia-book-appointment', [ 'service_id' => $this->service, 'employee_id' => $this->employee, 'customer_id' => $this->employee, 'start' => $day . ' 11:00' ])));
    }

    public function test_times_round_trip_across_a_daylight_saving_change(): void
    {
        $zone        = new \DateTimeZone('America/New_York');
        $transitions = $zone->getTransitions(time() + 2 * DAY_IN_SECONDS, time() + 400 * DAY_IN_SECONDS);
        $change      = (new \DateTimeImmutable('@' . $transitions[1]['ts']))->setTimezone($zone);
        $before      = $change->modify('-1 day')->format('Y-m-d') . ' 10:00';
        $after       = $change->modify('+1 day')->format('Y-m-d') . ' 10:00';

        $first  = $this->ok($this->book($before))['appointment'];
        $second = $this->ok($this->book($after))['appointment'];
        $this->assertSame($before, $first['start']);
        $this->assertSame($after, $second['start']);

        global $wpdb;
        $stored = $wpdb->get_col($wpdb->prepare('SELECT bookingStart FROM %i ORDER BY id ASC', $this->t('appointments')));
        $this->assertNotSame(substr($stored[0], 11), substr($stored[1], 11), 'The same wall-clock time is a different UTC time on each side of the change.');
        $this->assertSame((new \DateTimeImmutable($before, $zone))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $stored[0]);

        $moved = $this->ok($this->write('amelia-reschedule-appointment', [ 'appointment_id' => $first['id'], 'start' => $change->modify('+2 days')->format('Y-m-d') . ' 10:00' ]))['appointment'];
        $this->assertSame($change->modify('+2 days')->format('Y-m-d') . ' 10:00', $moved['start']);
        $this->assertSame($change->modify('+2 days')->format('Y-m-d') . ' 11:00', $moved['end']);

        // A wall-clock time inside the spring-forward gap does not exist.
        $spring = null;
        foreach ($zone->getTransitions(time(), time() + 400 * DAY_IN_SECONDS) as $transition) {
            if (! empty($transition['isdst']) && $transition['ts'] > time()) {
                $spring = (new \DateTimeImmutable('@' . $transition['ts']))->setTimezone($zone);
                break;
            }
        }
        $this->assertNotNull($spring);
        $this->assertSame('invalid_time', $this->code($this->book($spring->format('Y-m-d') . ' 02:30')));
    }

    public function test_reschedule_and_status_go_through_amelia_and_roll_back_exactly_without_messages(): void
    {
        $day         = $this->future_day();
        $appointment = $this->ok($this->book($day . ' 10:00'))['appointment'];
        $before      = $this->booking_state();

        $moved = $this->write('amelia-reschedule-appointment', [ 'appointment_id' => $appointment['id'], 'start' => $day . ' 15:00' ]);
        $this->assertSame($day . ' 15:00', $this->ok($moved)['appointment']['start']);
        $cancel = $this->write('amelia-set-appointment-status', [ 'appointment_id' => $appointment['id'], 'status' => 'canceled' ]);
        $this->assertSame('canceled', $this->ok($cancel)['appointment']['status']);
        $this->assertSame([ 'sarah@example.com' ], array_column($this->ok($cancel)['notifications']['held'], 'to'));
        $this->assertSame('no_change', $this->code($this->write('amelia-set-appointment-status', [ 'appointment_id' => $appointment['id'], 'status' => 'canceled' ])));
        $this->assertSame('not_found', $this->code($this->write('amelia-reschedule-appointment', [ 'appointment_id' => 999, 'start' => $day . ' 15:00' ])));

        Fake_Amelia_Api::$delivered = [];
        $calls                      = count(Fake_Amelia_Api::$calls);
        $this->assertTrue(Rollback_Service::restore_operation((string) $cancel['operation_id']), (string) wp_json_encode(Rollback_Service::take_warnings()));
        $this->assertTrue(Rollback_Service::restore_operation((string) $moved['operation_id']), (string) wp_json_encode(Rollback_Service::take_warnings()));
        $this->assertSame($before, $this->booking_state());
        $this->assertSame([], Fake_Amelia_Api::$delivered, 'An undo never sends anything.');
        $this->assertCount($calls, Fake_Amelia_Api::$calls, 'An undo never calls Amelia.');
    }

    public function test_undo_is_refused_while_later_bookings_depend_on_the_row(): void
    {
        $day = $this->future_day();

        // A customer created and then booked: undoing the create would orphan the booking.
        $created = $this->write('amelia-create-customer', [ 'first_name' => 'Ben', 'email' => 'ben@example.com' ]);
        $ben     = $this->ok($created)['customer']['id'];
        $this->ok($this->write('amelia-book-appointment', [ 'service_id' => $this->service, 'employee_id' => $this->employee, 'customer_id' => $ben, 'start' => $day . ' 09:00' ]));
        $this->assertFalse(Rollback_Service::restore_operation((string) $created['operation_id']));
        $this->assertStringContainsString('later customer bookings', implode(' ', Rollback_Service::take_warnings()));

        // A cancelled slot booked by someone else since: undoing the cancel would double-book it.
        $first  = $this->ok($this->book($day . ' 11:00'))['appointment'];
        $cancel = $this->write('amelia-set-appointment-status', [ 'appointment_id' => $first['id'], 'status' => 'canceled' ]);
        $this->ok($this->write('amelia-book-appointment', [ 'service_id' => $this->service, 'employee_id' => $this->employee, 'customer_id' => $ben, 'start' => $day . ' 11:00' ]));
        $before = $this->booking_state();
        $this->assertFalse(Rollback_Service::restore_operation((string) $cancel['operation_id']));
        $this->assertStringContainsString('would overlap', implode(' ', Rollback_Service::take_warnings()));
        $this->assertSame($before, $this->booking_state());

        // A user without Amelia's appointment capability may not restore one.
        $other = self::factory()->user->create([ 'role' => 'administrator' ]);
        wp_set_current_user($other);
        $snapshot = Snapshot_Store::get_by_operation((string) $cancel['operation_id'])['snapshot'];
        $this->assertSame('amelia_write_appointments', Amelia_Rows_Snapshot::capability((string) $snapshot['data']['kind']));
        $this->assertFalse(Rollback_Service::restore_operation((string) $cancel['operation_id']));
    }

    // ---------------------------------------------------------------
    // Employees
    // ---------------------------------------------------------------

    public function test_employee_create_and_update_set_hours_breaks_and_days_off_through_amelia_and_roll_back_exactly(): void
    {
        $before = $this->schedule_state();

        $created = $this->write('amelia-create-employee', [
            'first_name'   => 'Anna',
            'last_name'    => 'Ray',
            'email'        => 'anna@example.com',
            'phone'        => '+15550123',
            'working_week' => [
                [ 'day' => 'monday', 'start' => '09:00', 'end' => '17:00', 'breaks' => [ [ 'start' => '12:00', 'end' => '13:00' ] ] ],
                [ 'day' => 'tuesday', 'start' => '10:00', 'end' => '14:00' ],
            ],
            'days_off'     => [ [ 'name' => 'Trip', 'start' => '2031-01-02', 'end' => '2031-01-03' ] ],
        ]);
        $anna = $this->ok($created)['employee'];
        $this->assertSame('User\\Provider\\AddProviderController', end(Fake_Amelia_Api::$calls)[0]);
        $sent = end(Fake_Amelia_Api::$calls)[1];
        $this->assertSame([ 'dayIndex' => 1, 'startTime' => '09:00:00', 'endTime' => '17:00:00', 'timeOutList' => [ [ 'startTime' => '12:00:00', 'endTime' => '13:00:00' ] ], 'periodList' => [] ], $sent['weekDayList'][0]);
        $this->assertFalse($sent['sendEmployeePanelAccessEmail'], 'No panel access email goes out.');
        $this->assertSame('Anna', $anna['first_name']);
        $this->assertSame([ 'day' => 'monday', 'start' => '09:00', 'end' => '17:00', 'breaks' => [ [ 'start' => '12:00', 'end' => '13:00' ] ] ], $anna['working_week'][0]);
        $this->assertSame([ [ 'name' => 'Trip', 'start' => '2031-01-02', 'end' => '2031-01-03', 'repeat' => false ] ], $anna['days_off']);
        $this->assertSame($anna, $this->ok($this->read('amelia-list-employees', [ 'search' => 'anna@' ]))['employees'][0], 'The answer is the list-employees shape.');

        $rename = $this->write('amelia-update-employee', [ 'employee_id' => $anna['id'], 'first_name' => 'Ana' ]);
        $this->assertSame('Ana', $this->ok($rename)['employee']['first_name']);
        $this->assertSame('+15550123', $this->ok($rename)['employee']['phone'], 'An update keeps the fields it does not name.');
        $this->assertCount(2, $this->ok($rename)['employee']['working_week']);
        $this->assertArrayNotHasKey('weekDayList', end(Fake_Amelia_Api::$calls)[1], 'Hours it does not name are left to Amelia as they are.');

        $hours = $this->write('amelia-update-employee', [ 'employee_id' => $anna['id'], 'working_week' => [ [ 'day' => 'monday', 'start' => '08:00', 'end' => '12:00' ], [ 'day' => 'wednesday', 'start' => '13:00', 'end' => '18:00', 'breaks' => [ [ 'start' => '15:00', 'end' => '15:15' ] ] ] ] ]);
        $week  = $this->ok($hours)['employee']['working_week'];
        $this->assertSame([ 'monday', 'wednesday' ], array_column($week, 'day'), 'working_week replaces the whole week.');
        $this->assertSame([], $week[0]['breaks']);
        $this->assertSame([ [ 'start' => '15:00', 'end' => '15:15' ] ], $week[1]['breaks']);
        $this->assertSame((int) $this->schedule_state()['providers_to_weekdays'][0]['id'], (int) end(Fake_Amelia_Api::$calls)[1]['weekDayList'][0]['id'], 'A day the employee already works keeps its Amelia row.');

        $off = $this->write('amelia-update-employee', [ 'employee_id' => $anna['id'], 'days_off' => [ [ 'name' => 'Holiday', 'start' => '2031-12-25', 'repeat' => true ] ] ]);
        $this->assertSame([ [ 'name' => 'Holiday', 'start' => '2031-12-25', 'end' => '2031-12-25', 'repeat' => true ] ], $this->ok($off)['employee']['days_off'], 'days_off replaces the list; a single day ends where it starts.');

        $this->assertSame('amelia_refused', $this->code($this->write('amelia-create-employee', [ 'first_name' => 'Tom', 'last_name' => 'Two', 'email' => 'tom@example.com' ])));
        $this->assertSame([], $this->ok($off)['notifications']['sent']);

        $this->undo($off);
        $this->undo($hours);
        $this->undo($rename);
        $this->undo($created);
        $this->assertSame($before, $this->schedule_state());
    }

    public function test_malformed_hours_and_days_off_are_refused_before_amelia(): void
    {
        $bad = [
            [ [ 'day' => 'monday', 'start' => '17:00', 'end' => '09:00' ] ],
            [ [ 'day' => 'monday', 'start' => '09:00', 'end' => '25:00' ] ],
            [ [ 'day' => 'monday', 'start' => '09:00', 'end' => '17:00', 'breaks' => [ [ 'start' => '08:00', 'end' => '10:00' ] ] ] ],
            [ [ 'day' => 'monday', 'start' => '09:00', 'end' => '17:00', 'breaks' => [ [ 'start' => '12:00', 'end' => '13:00' ], [ 'start' => '12:30', 'end' => '14:00' ] ] ] ],
            [ [ 'day' => 'monday', 'start' => '09:00', 'end' => '12:00' ], [ 'day' => 'monday', 'start' => '13:00', 'end' => '17:00' ] ],
        ];
        foreach ($bad as $week) {
            $this->assertSame('invalid_hours', $this->code($this->write('amelia-update-employee', [ 'employee_id' => $this->employee, 'working_week' => $week ])), (string) wp_json_encode($week));
        }
        $this->assertSame('invalid_date', $this->code($this->write('amelia-update-employee', [ 'employee_id' => $this->employee, 'days_off' => [ [ 'name' => 'X', 'start' => '2031-01-05', 'end' => '2031-01-02' ] ] ])));
        $this->assertSame('not_found', $this->code($this->write('amelia-update-employee', [ 'employee_id' => $this->customer, 'first_name' => 'X' ])));
        $this->assertSame([], Fake_Amelia_Api::$calls, 'Nothing reaches Amelia.');
        $this->assertSame([], Snapshot_Store::list_by_session('default'), 'No undo point is left.');
    }

    public function test_undoing_a_created_employee_service_category_or_customer_is_refused_once_booked(): void
    {
        $category = $this->write('amelia-create-category', [ 'name' => 'Bodywork' ]);
        $service  = $this->write('amelia-create-service', [ 'name' => 'Reflexology', 'category_id' => $this->ok($category)['category']['id'], 'duration' => 60, 'price' => 40 ]);
        $employee = $this->write('amelia-create-employee', [ 'first_name' => 'Anna', 'last_name' => 'Ray', 'email' => 'anna@example.com', 'working_week' => [ [ 'day' => strtolower($this->day_name()), 'start' => '09:00', 'end' => '17:00' ] ] ]);
        $customer = $this->write('amelia-create-customer', [ 'first_name' => 'Ben', 'email' => 'ben@example.com' ]);
        $service_id = $this->ok($service)['service']['id'];
        $this->ok($this->write('amelia-update-service', [ 'service_id' => $service_id, 'employee_ids' => [ $this->ok($employee)['employee']['id'] ] ]));
        $this->ok($this->write('amelia-book-appointment', [ 'service_id' => $service_id, 'employee_id' => $this->ok($employee)['employee']['id'], 'customer_id' => $this->ok($customer)['customer']['id'], 'start' => $this->future_day() . ' 10:00' ]));
        $before = $this->all_state();

        foreach ([ [ $employee, 'later appointments' ], [ $customer, 'later customer bookings' ], [ $service, 'later appointments' ], [ $category, 'later services' ] ] as [$out, $why]) {
            $this->assertFalse(Rollback_Service::restore_operation((string) $out['operation_id']));
            $this->assertStringContainsString($why, implode(' ', Rollback_Service::take_warnings()));
        }
        $this->assertSame($before, $this->all_state());
    }

    // ---------------------------------------------------------------
    // Events
    // ---------------------------------------------------------------

    public function test_event_create_update_and_cancel_go_through_amelia_and_roll_back_exactly(): void
    {
        $before = $this->event_state();
        $day    = $this->future_day();

        $created = $this->write('amelia-create-event', [ 'name' => 'Yoga day', 'periods' => [ [ 'start' => $day . ' 14:00', 'end' => $day . ' 16:00' ] ], 'max_capacity' => 10, 'price' => 20, 'employee_ids' => [ $this->employee ] ]);
        $event   = $this->ok($created)['event'];
        $this->assertSame('Booking\\Event\\AddEventController', end(Fake_Amelia_Api::$calls)[0]);
        $this->assertSame($day . ' 14:00:00', end(Fake_Amelia_Api::$calls)[1]['periods'][0]['periodStart'], 'Amelia takes site-local times.');
        $this->assertSame([ [ 'start' => $day . ' 14:00', 'end' => $day . ' 16:00' ] ], $event['periods']);
        $this->assertSame([ $this->employee ], $event['employee_ids']);
        $this->assertSame(10, $event['places_left']);
        global $wpdb;
        $this->assertSame((new \DateTimeImmutable($day . ' 14:00', wp_timezone()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $wpdb->get_var($wpdb->prepare('SELECT periodStart FROM %i WHERE eventId = %d', $this->t('events_periods'), $event['id'])));

        $update = $this->write('amelia-update-event', [ 'event_id' => $event['id'], 'name' => 'Yoga morning', 'periods' => [ [ 'start' => $day . ' 09:00', 'end' => $day . ' 11:00' ] ] ]);
        $moved  = $this->ok($update)['event'];
        $this->assertSame('Yoga morning', $moved['name']);
        $this->assertSame(20.0, $moved['price'], 'An update keeps the fields it does not name.');
        $this->assertSame([ $this->employee ], $moved['employee_ids']);
        $this->assertSame([ [ 'start' => $day . ' 09:00', 'end' => $day . ' 11:00' ] ], $moved['periods']);
        $this->assertSame($event['id'], (int) end(Fake_Amelia_Api::$calls)[1]['id']);
        $this->assertSame((int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE eventId = %d', $this->t('events_periods'), $event['id'])), (int) end(Fake_Amelia_Api::$calls)[1]['periods'][0]['id'], 'A period keeps its Amelia row.');

        $cancel = $this->write('amelia-set-event-status', [ 'event_id' => $event['id'], 'status' => 'canceled' ]);
        $this->assertSame('rejected', $this->ok($cancel)['event']['status'], 'Amelia cancels an event as rejected.');
        $this->assertSame([ 'status' => 'rejected', 'applyGlobally' => false ], end(Fake_Amelia_Api::$calls)[1]);
        $this->assertSame('no_change', $this->code($this->write('amelia-set-event-status', [ 'event_id' => $event['id'], 'status' => 'canceled' ])));
        $this->assertSame('invalid_period', $this->code($this->write('amelia-update-event', [ 'event_id' => $event['id'], 'periods' => [ [ 'start' => $day . ' 11:00', 'end' => $day . ' 10:00' ] ] ])));

        $this->undo($cancel);
        $this->undo($update);
        $this->undo($created);
        $this->assertSame($before, $this->event_state());
    }

    public function test_an_attendee_is_added_only_while_places_are_left_with_customer_mail_held_and_rolls_back(): void
    {
        $day   = $this->future_day();
        $event = $this->ok($this->write('amelia-create-event', [ 'name' => 'Yoga day', 'periods' => [ [ 'start' => $day . ' 14:00', 'end' => $day . ' 16:00' ], [ 'start' => $this->future_day(1) . ' 14:00', 'end' => $this->future_day(1) . ' 16:00' ] ], 'max_capacity' => 3, 'price' => 20, 'employee_ids' => [ $this->employee ] ]))['event'];
        $ann   = $this->insert('users', [ 'type' => 'customer', 'status' => 'visible', 'firstName' => 'Ann', 'lastName' => 'Bell', 'email' => 'ann@example.com' ]);
        $before = $this->event_state();

        $added = $this->write('amelia-add-event-attendee', [ 'event_id' => $event['id'], 'customer_id' => $this->customer, 'persons' => 2 ]);
        $this->assertSame('Booking\\Appointment\\AddBookingController', end(Fake_Amelia_Api::$calls)[0]);
        $this->assertSame(1, $this->ok($added)['event']['places_left']);
        $this->assertSame([ [ 'booking_id' => $this->ok($added)['attendee']['booking_id'], 'customer_id' => $this->customer, 'name' => 'Sarah Lane', 'email' => 'sarah@example.com', 'status' => 'approved', 'persons' => 2 ] ], [ $this->ok($added)['attendee'] ]);
        $this->assertSame([ 'sarah@example.com' ], array_column($this->ok($added)['notifications']['held'], 'to'));
        $this->assertSame([ 'tom@example.com' ], array_column(Fake_Amelia_Api::$delivered, 'to'), 'Only the employee email left.');

        $calls = count(Fake_Amelia_Api::$calls);
        $full  = $this->write('amelia-add-event-attendee', [ 'event_id' => $event['id'], 'customer_id' => $ann, 'persons' => 2 ]);
        $this->assertSame('event_full', $this->code($full));
        $this->assertSame([ 'max_capacity' => 3, 'booked' => 2, 'places_left' => 1 ], $full['error']['data']);
        $this->assertCount($calls, Fake_Amelia_Api::$calls, 'Amelia does not check capacity for a backend booking, so nothing reaches it.');

        // Fewer periods would drop the attendee's booked periods.
        $this->assertSame('amelia_in_use', $this->code($this->write('amelia-update-event', [ 'event_id' => $event['id'], 'periods' => [ [ 'start' => $day . ' 15:00', 'end' => $day . ' 17:00' ] ] ])));

        // An event created and then booked cannot be undone from under its attendee.
        $create_op = Snapshot_Store::list_by_session('default');
        $this->assertFalse(Rollback_Service::restore_operation((string) end($create_op)['operation_id']));
        $this->assertStringContainsString('later customer bookings to events periods', implode(' ', Rollback_Service::take_warnings()));

        $loud = $this->write('amelia-add-event-attendee', [ 'event_id' => $event['id'], 'customer_id' => $ann, 'notify' => true ]);
        $this->assertSame([ 'ann@example.com', 'tom@example.com' ], array_column($this->ok($loud)['notifications']['sent'], 'to'));

        $this->ok($this->write('amelia-set-event-status', [ 'event_id' => $event['id'], 'status' => 'canceled' ]));
        $this->assertSame('event_closed', $this->code($this->write('amelia-add-event-attendee', [ 'event_id' => $event['id'], 'customer_id' => $ann ])));

        $sessions = Snapshot_Store::list_by_session('default');
        $this->undo($sessions[0]);
        $this->undo($loud);
        $this->undo($added);
        $this->assertSame($before, $this->event_state());
    }

    // ---------------------------------------------------------------
    // Deletes
    // ---------------------------------------------------------------

    public function test_deletes_need_confirm_are_refused_while_in_use_and_roll_back_exactly(): void
    {
        $this->ok($this->book($this->future_day() . ' 10:00'));
        $before = $this->all_state();

        $this->assertSame('confirmation_required', $this->code($this->write('amelia-delete-customer', [ 'customer_id' => $this->customer ])));
        foreach ([
            [ 'amelia-delete-customer', [ 'customer_id' => $this->customer ], 'booking' ],
            [ 'amelia-delete-employee', [ 'employee_id' => $this->employee ], 'appointment' ],
            [ 'amelia-delete-service', [ 'service_id' => $this->service ], 'appointment' ],
            [ 'amelia-delete-category', [ 'category_id' => $this->category ], 'service' ],
        ] as [$op, $args, $what]) {
            $out = $this->write($op, $args + [ 'confirm' => true ]);
            $this->assertSame('amelia_in_use', $this->code($out), $op);
            $this->assertStringContainsString($what, $out['error']['message'], $op);
        }
        $this->assertSame([], array_filter(Fake_Amelia_Api::$calls, static fn (array $call): bool => false !== strpos($call[0], 'Delete')), 'Nothing in use reaches Amelia.');
        $this->assertSame($before, $this->all_state());

        // Unused ones go through Amelia's own delete and come back exactly.
        $category = $this->insert('categories', [ 'name' => 'Empty', 'status' => 'visible', 'position' => 2, 'color' => '#1788FB' ]);
        $service  = $this->insert('services', [ 'name' => 'Unbooked', 'price' => 30, 'status' => 'visible', 'categoryId' => $this->category, 'minCapacity' => 1, 'maxCapacity' => 1, 'duration' => 1800, 'priority' => 'least_expensive', 'color' => '#1788FB' ]);
        $this->insert('providers_to_services', [ 'userId' => $this->employee, 'serviceId' => $service, 'price' => 30, 'minCapacity' => 1, 'maxCapacity' => 1 ]);
        $this->insert('services_views', [ 'serviceId' => $service, 'date' => '2026-01-01', 'views' => 4 ]);
        $customer = $this->insert('users', [ 'type' => 'customer', 'status' => 'visible', 'firstName' => 'Ben', 'lastName' => 'Ode', 'email' => 'ben@example.com' ]);
        $employee = $this->insert('users', [ 'type' => 'provider', 'status' => 'visible', 'firstName' => 'Anna', 'lastName' => 'Ray', 'email' => 'anna@example.com' ]);
        $weekday  = $this->insert('providers_to_weekdays', [ 'userId' => $employee, 'dayIndex' => 2, 'startTime' => '09:00:00', 'endTime' => '17:00:00' ]);
        $this->insert('providers_to_timeouts', [ 'weekDayId' => $weekday, 'startTime' => '12:00:00', 'endTime' => '13:00:00' ]);
        $this->insert('providers_to_daysoff', [ 'userId' => $employee, 'name' => 'Trip', 'startDate' => '2031-01-02 00:00:00', 'endDate' => '2031-01-02 00:00:00', 'type' => 'dayOff', 'repeat' => 0 ]);
        // Amelia's user delete also clears every appointment left without a booking.
        $orphan = $this->insert('appointments', [ 'status' => 'canceled', 'bookingStart' => '2026-01-05 15:00:00', 'bookingEnd' => '2026-01-05 15:00:00', 'notifyParticipants' => 1, 'serviceId' => $this->service, 'providerId' => $this->employee ]);
        $before = $this->all_state();

        $deleted = [];
        foreach ([
            [ 'amelia-delete-service', [ 'service_id' => $service ], 'service', $service ],
            [ 'amelia-delete-category', [ 'category_id' => $category ], 'category', $category ],
            [ 'amelia-delete-customer', [ 'customer_id' => $customer ], 'customer', $customer ],
            [ 'amelia-delete-employee', [ 'employee_id' => $employee ], 'employee', $employee ],
        ] as [$op, $args, $type, $id]) {
            $out = $this->write($op, $args + [ 'confirm' => true ]);
            $this->assertSame([ 'type' => $type, 'id' => $id ], array_intersect_key($this->ok($out)['deleted'], [ 'type' => 1, 'id' => 1 ]), $op);
            $this->assertTrue($out['recoverable'], $op);
            $deleted[] = $out;
        }
        global $wpdb;
        $this->assertNull($wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE id = %d', $this->t('appointments'), $orphan)), 'Amelia removed the empty appointment.');
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE userId = %d', $this->t('providers_to_weekdays'), $employee)));
        $this->assertSame('not_found', $this->code($this->write('amelia-delete-service', [ 'service_id' => $service, 'confirm' => true ])));

        foreach (array_reverse($deleted) as $out) {
            $this->undo($out);
        }
        $this->assertSame($before, $this->all_state());
    }

    public function test_undoing_a_customer_delete_is_refused_once_the_email_is_taken_again(): void
    {
        $customer = $this->insert('users', [ 'type' => 'customer', 'status' => 'visible', 'firstName' => 'Ben', 'lastName' => 'Ode', 'email' => 'ben@example.com' ]);
        $deleted  = $this->write('amelia-delete-customer', [ 'customer_id' => $customer, 'confirm' => true ]);
        $this->ok($deleted);
        $this->ok($this->write('amelia-create-customer', [ 'first_name' => 'Benjamin', 'email' => 'ben@example.com' ]));
        $before = $this->all_state();
        $this->assertFalse(Rollback_Service::restore_operation((string) $deleted['operation_id']));
        $this->assertStringContainsString('ben@example.com', implode(' ', Rollback_Service::take_warnings()));
        $this->assertSame($before, $this->all_state());
    }

    // ---------------------------------------------------------------
    // Privacy
    // ---------------------------------------------------------------

    public function test_customer_records_are_in_the_personal_data_export_and_erasure(): void
    {
        $this->ok($this->book($this->future_day() . ' 10:00'));
        $exporters = Personal_Data::exporters([]);
        $erasers   = Personal_Data::erasers([]);
        $this->assertSame(Amelia_Customers::KEY, array_key_first($exporters), 'Amelia\'s records come before WP MCP\'s snapshots.');
        $this->assertSame(Amelia_Customers::KEY, array_key_first($erasers));

        $export = Amelia_Customers::export('sarah@example.com');
        $this->assertTrue($export['done']);
        $this->assertCount(2, $export['data'], 'The profile and the booking.');
        $this->assertContains([ 'name' => 'Email', 'value' => 'sarah@example.com' ], $export['data'][0]['data']);
        $this->assertContains([ 'name' => 'Service', 'value' => 'Massage' ], $export['data'][1]['data']);
        $this->assertSame([], Amelia_Customers::export('nobody@example.com')['data']);

        $erase = Amelia_Customers::erase('sarah@example.com');
        $this->assertTrue($erase['items_removed']);
        $this->assertTrue($erase['items_retained']);
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', $this->t('users'), $this->customer), ARRAY_A);
        $this->assertNull($row['email']);
        $this->assertNull($row['phone']);
        $this->assertNotSame('Sarah', $row['firstName']);
        $this->assertSame(1, (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE customerId = %d', $this->t('customer_bookings'), $this->customer)), 'The booking itself is kept.');
        $this->assertSame([], Amelia_Customers::export('sarah@example.com')['data']);

        remove_all_filters('wpmcp_amelia_active');
        add_filter('wpmcp_amelia_active', '__return_false');
        $this->assertArrayNotHasKey(Amelia_Customers::KEY, Personal_Data::exporters([]));
    }
}
