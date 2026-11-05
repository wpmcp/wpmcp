<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Amelia_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Tests\Support\Fake_Amelia_Api;

require_once __DIR__ . '/../../support/amelia-tables.php';
require_once __DIR__ . '/../../support/amelia-doubles.php';

/**
 * Issue #549: the Amelia appointment and event reads are scoped the way
 * Amelia scopes its own lists (GetAppointmentsCommandHandler and
 * GetEventsCommandHandler in Amelia Lite).
 *
 * Amelia Lite grants amelia_read_appointments and amelia_read_events to its
 * wpamelia-customer and wpamelia-provider roles, and adds the customer role
 * to an existing WP user who books with the same email. So holding those
 * capabilities says nothing about whose bookings a user may see:
 *
 * - an administrator (or anyone with manage_options) and an Amelia manager
 *   see everything;
 * - an Amelia employee sees only the appointments assigned to them (unless
 *   they also hold amelia_read_others_appointments) and only the events
 *   assigned to them, with those events' attendees;
 * - anyone else is a customer, and sees only the appointments and events
 *   they booked, with only their own booking on each.
 *
 * The roles here carry Amelia Lite's own capability lists
 * (src/Infrastructure/WP/config/Roles.php). The live Amelia group checks the
 * same against the real plugin (AmeliaLiveTest).
 */
class AmeliaViewerScopeTest extends \WP_UnitTestCase
{
    /** Every other plugin-data plugin, off so only Amelia decides whether the pair is available. */
    private const OTHER_FILTERS = [ 'wpmcp_jetengine_active', 'wpmcp_pods_active', 'wpmcp_translatepress_active', 'wpmcp_buddypress_active', 'wpmcp_tutor_active', 'wpmcp_lifterlms_active', 'wpmcp_tablepress_active', 'wpmcp_fluentcrm_active', 'wpmcp_mailpoet_active' ];

    /** Amelia Lite's wpamelia-customer capabilities. */
    private const CUSTOMER_CAPS = [ 'amelia_read_menu', 'amelia_read_calendar', 'amelia_read_appointments', 'amelia_read_events', 'amelia_write_time_appointments' ];

    /** Amelia Lite's wpamelia-provider capabilities (the ones it grants). */
    private const PROVIDER_CAPS = [ 'amelia_delete_events', 'amelia_read_menu', 'amelia_read_calendar', 'amelia_read_appointments', 'amelia_read_events', 'amelia_read_employees', 'amelia_read_others_customers', 'amelia_write_employees', 'amelia_write_status_appointments', 'amelia_write_status_events', 'amelia_write_time_appointments', 'amelia_write_appointments', 'amelia_write_events' ];

    /** Amelia Lite's wpamelia-manager read capabilities. */
    private const MANAGER_CAPS = [ 'amelia_read_menu', 'amelia_read_calendar', 'amelia_read_appointments', 'amelia_read_events', 'amelia_read_employees', 'amelia_read_services', 'amelia_read_customers', 'amelia_read_others_appointments', 'amelia_read_others_customers', 'amelia_read_others_employees' ];

    private int $service;

    /** Amelia employee rows: Tom (someone else) and Mia (the employee under test). */
    private int $tom;

    private int $mia;

    /** Amelia customer rows: Sarah (someone else) and Kim (the customer under test). */
    private int $sarah;

    private int $kim;

    /** Appointments: Tom's with Sarah, Mia's with Sarah, and Tom's group class with Sarah and Kim. */
    private int $toms;

    private int $mias;

    private int $group;

    /** Events: one Tom runs that Sarah and Kim booked, one Mia runs that Sarah booked. */
    private int $tom_event;

    private int $mia_event;

    private string $day;

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
        Gate::set_pro_for_tests(true);
        Amelia_Pack::use_api_for_tests(new Fake_Amelia_Api());
        update_option('timezone_string', 'UTC');
        add_filter('wpmcp_amelia_active', '__return_true');
        foreach (self::OTHER_FILTERS as $filter) {
            add_filter($filter, '__return_false');
        }
        foreach ([ 'wpamelia-customer' => self::CUSTOMER_CAPS, 'wpamelia-provider' => self::PROVIDER_CAPS, 'wpamelia-manager' => self::MANAGER_CAPS ] as $role => $caps) {
            remove_role($role);
            add_role($role, $role, array_fill_keys($caps, true) + [ 'read' => true ]);
        }

        $this->day     = (new \DateTimeImmutable('+10 days', wp_timezone()))->format('Y-m-d');
        $this->tom     = $this->insert('users', [ 'type' => 'provider', 'status' => 'visible', 'firstName' => 'Tom', 'lastName' => 'Hart', 'email' => 'tom@example.com' ]);
        $this->mia     = $this->insert('users', [ 'type' => 'provider', 'status' => 'visible', 'firstName' => 'Mia', 'lastName' => 'Cole', 'email' => 'mia@example.com' ]);
        $this->sarah   = $this->insert('users', [ 'type' => 'customer', 'status' => 'visible', 'firstName' => 'Sarah', 'lastName' => 'Lane', 'email' => 'sarah@example.com', 'phone' => '+15550100' ]);
        $this->kim     = $this->insert('users', [ 'type' => 'customer', 'status' => 'visible', 'firstName' => 'Kim', 'lastName' => 'Park', 'email' => 'kim@example.com' ]);
        $this->service = $this->insert('services', [ 'name' => 'Massage', 'price' => 50, 'status' => 'visible', 'categoryId' => 0, 'minCapacity' => 1, 'maxCapacity' => 5, 'duration' => 3600, 'priority' => 'least_expensive', 'color' => '#1788FB' ]);

        $this->toms  = $this->appointment($this->tom, '09:00', [ $this->sarah ]);
        $this->mias  = $this->appointment($this->mia, '11:00', [ $this->sarah ]);
        $this->group = $this->appointment($this->tom, '14:00', [ $this->sarah, $this->kim ]);

        $this->tom_event = $this->event('Yoga day', $this->tom, [ $this->sarah, $this->kim ]);
        $this->mia_event = $this->event('Pottery', $this->mia, [ $this->sarah ]);
    }

    protected function tearDown(): void
    {
        Amelia_Pack::use_api_for_tests(null);
        Gate::set_pro_for_tests(null);
        foreach (array_merge(self::OTHER_FILTERS, [ 'wpmcp_amelia_active' ]) as $filter) {
            remove_all_filters($filter);
        }
        foreach ([ 'wpamelia-customer', 'wpamelia-provider', 'wpamelia-manager' ] as $role) {
            remove_role($role);
        }
        parent::tearDown();
    }

    private function insert(string $name, array $row): int
    {
        global $wpdb;
        $this->assertNotFalse($wpdb->insert($wpdb->prefix . 'amelia_' . $name, $row), $wpdb->last_error);
        return (int) $wpdb->insert_id;
    }

    /** @param int[] $customers */
    private function appointment(int $provider, string $time, array $customers): int
    {
        $start = $this->day . ' ' . $time . ':00';
        $id    = $this->insert('appointments', [ 'status' => 'approved', 'bookingStart' => $start, 'bookingEnd' => gmdate('Y-m-d H:i:s', strtotime($start) + 3600), 'notifyParticipants' => 0, 'serviceId' => $this->service, 'providerId' => $provider ]);
        foreach ($customers as $customer) {
            $this->insert('customer_bookings', [ 'appointmentId' => $id, 'customerId' => $customer, 'status' => 'approved', 'price' => 50, 'persons' => 1 ]);
        }
        return $id;
    }

    /** @param int[] $customers */
    private function event(string $name, int $provider, array $customers): int
    {
        $id     = $this->insert('events', [ 'name' => $name, 'status' => 'approved', 'maxCapacity' => 10, 'price' => 20, 'notifyParticipants' => 1, 'created' => gmdate('Y-m-d H:i:s') ]);
        $period = $this->insert('events_periods', [ 'eventId' => $id, 'periodStart' => $this->day . ' 14:00:00', 'periodEnd' => $this->day . ' 16:00:00' ]);
        $this->insert('events_to_providers', [ 'eventId' => $id, 'userId' => $provider ]);
        foreach ($customers as $customer) {
            $booking = $this->insert('customer_bookings', [ 'customerId' => $customer, 'status' => 'approved', 'price' => 20, 'persons' => 1 ]);
            $this->insert('customer_bookings_to_events_periods', [ 'customerBookingId' => $booking, 'eventPeriodId' => $period ]);
        }
        return $id;
    }

    /** A WP user with $wp_role plus $amelia_role, linked to Amelia user row $amelia (0 for none) the way Amelia links them (externalId). */
    private function wp_user(string $wp_role, string $amelia_role, int $amelia = 0): int
    {
        $id = self::factory()->user->create([ 'role' => $wp_role ]);
        if ('' !== $amelia_role) {
            (new \WP_User($id))->add_role($amelia_role);
        }
        if ($amelia > 0) {
            global $wpdb;
            $wpdb->update($wpdb->prefix . 'amelia_users', [ 'externalId' => $id ], [ 'id' => $amelia ]);
        }
        return $id;
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out['result'];
    }

    /** appointment id => customer ids, and the total, from amelia-list-appointments over the test day. */
    private function appointments(array $args = []): array
    {
        $result = $this->ok($this->read('amelia-list-appointments', [ 'from' => $this->day, 'to' => $this->day ] + $args));
        $out    = [];
        foreach ($result['appointments'] as $a) {
            $out[ $a['id'] ] = array_column($a['customers'], 'customer_id');
        }
        return [ 'appointments' => $out, 'total' => $result['total'] ];
    }

    /** @return int[] the event ids amelia-list-events returns over the test day. */
    private function events(): array
    {
        return array_column($this->ok($this->read('amelia-list-events', [ 'from' => $this->day, 'to' => $this->day ]))['events'], 'id');
    }

    public function test_an_amelia_customer_who_is_a_contributor_sees_only_their_own_appointments_and_bookings(): void
    {
        wp_set_current_user($this->wp_user('contributor', 'wpamelia-customer', $this->kim));

        $seen = $this->appointments();
        $this->assertSame([ $this->group => [ $this->kim ] ], $seen['appointments'], 'Only the appointment Kim booked, and only Kim\'s booking on it.');
        $this->assertSame(1, $seen['total']);
        $this->assertSame([], $this->appointments([ 'customer_id' => $this->sarah ])['appointments'], 'Naming another customer reveals nothing.');

        $text = (string) wp_json_encode($this->read('amelia-list-appointments', [ 'from' => $this->day, 'to' => $this->day ]));
        $this->assertStringNotContainsString('Sarah', $text);
    }

    public function test_an_amelia_customer_sees_only_the_events_they_booked_and_only_their_own_attendance(): void
    {
        wp_set_current_user($this->wp_user('contributor', 'wpamelia-customer', $this->kim));

        $this->assertSame([ $this->tom_event ], $this->events());

        $attendees = $this->ok($this->read('amelia-list-event-attendees', [ 'event_id' => $this->tom_event ]))['attendees'];
        $this->assertSame([ $this->kim ], array_column($attendees, 'customer_id'));
        $this->assertSame([ 'kim@example.com' ], array_column($attendees, 'email'));

        $out = $this->read('amelia-list-event-attendees', [ 'event_id' => $this->mia_event ]);
        $this->assertSame('capability', (string) ($out['error']['data']['reason'] ?? ''), (string) wp_json_encode($out));
        $this->assertStringNotContainsString('sarah@example.com', (string) wp_json_encode($out));
    }

    public function test_a_user_with_the_customer_role_but_no_amelia_customer_row_sees_no_bookings(): void
    {
        wp_set_current_user($this->wp_user('contributor', 'wpamelia-customer'));

        $this->assertSame([ 'appointments' => [], 'total' => 0 ], $this->appointments());
        $this->assertSame([], $this->events());
        $out = $this->read('amelia-list-event-attendees', [ 'event_id' => $this->tom_event ]);
        $this->assertSame('capability', (string) ($out['error']['data']['reason'] ?? ''), (string) wp_json_encode($out));
    }

    public function test_an_amelia_employee_sees_only_the_appointments_and_events_assigned_to_them(): void
    {
        wp_set_current_user($this->wp_user('subscriber', 'wpamelia-provider', $this->mia));

        $seen = $this->appointments();
        $this->assertSame([ $this->mias => [ $this->sarah ] ], $seen['appointments'], 'Mia sees her own appointment with its customer.');
        $this->assertSame(1, $seen['total']);
        $this->assertSame([], $this->appointments([ 'employee_id' => $this->tom ])['appointments'], 'Naming another employee reveals nothing.');

        $this->assertSame([ $this->mia_event ], $this->events());
        $attendees = $this->ok($this->read('amelia-list-event-attendees', [ 'event_id' => $this->mia_event ]))['attendees'];
        $this->assertSame([ $this->sarah ], array_column($attendees, 'customer_id'), 'An employee sees the attendees of their own event.');

        $out = $this->read('amelia-list-event-attendees', [ 'event_id' => $this->tom_event ]);
        $this->assertSame('capability', (string) ($out['error']['data']['reason'] ?? ''), (string) wp_json_encode($out));
        $this->assertStringNotContainsString('kim@example.com', (string) wp_json_encode($out));
    }

    public function test_an_amelia_employee_granted_read_others_appointments_sees_every_appointment_as_in_amelia(): void
    {
        $user = $this->wp_user('subscriber', 'wpamelia-provider', $this->mia);
        (new \WP_User($user))->add_cap('amelia_read_others_appointments');
        wp_set_current_user($user);

        $this->assertSame([ $this->toms, $this->mias, $this->group ], array_keys($this->appointments()['appointments']));
        $this->assertSame([ $this->mia_event ], $this->events(), 'Amelia scopes an employee\'s events to their own either way.');
    }

    public function test_an_administrator_and_an_amelia_manager_see_everything(): void
    {
        $admin = self::factory()->user->create([ 'role' => 'administrator' ]);
        // Amelia's activation grants its capabilities to administrators.
        foreach (self::MANAGER_CAPS as $cap) {
            (new \WP_User($admin))->add_cap($cap);
        }
        if (is_multisite()) {
            grant_super_admin($admin);
        }
        foreach ([ $admin, $this->wp_user('subscriber', 'wpamelia-manager') ] as $user) {
            wp_set_current_user($user);
            $seen = $this->appointments();
            $this->assertSame([ $this->toms => [ $this->sarah ], $this->mias => [ $this->sarah ], $this->group => [ $this->sarah, $this->kim ] ], $seen['appointments']);
            $this->assertSame(3, $seen['total']);
            $this->assertSame([ $this->tom_event, $this->mia_event ], $this->events());
            $attendees = $this->ok($this->read('amelia-list-event-attendees', [ 'event_id' => $this->tom_event ]))['attendees'];
            $this->assertSame([ $this->sarah, $this->kim ], array_column($attendees, 'customer_id'));
        }
    }
}
