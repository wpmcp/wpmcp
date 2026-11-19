<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Booking_Adapter;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/booking-tables.php';

/**
 * Appointment booking plugins (issue #679): Bookly, Simply Schedule
 * Appointments, BookingPress and LatePoint as paid-tier ops on the
 * plugin-data pair, so they add no top-level tools.
 *
 * None of the four plugins is loaded in the shared test core. Their tables
 * are built from their own CREATE TABLE statements
 * (tests/support/booking-tables.php) and presence is driven through each
 * plugin's filter. Every op reads or writes those tables, so the same code
 * runs here as on a real site.
 *
 * Bookings carry personal data: lists mask the customer, a single read
 * returns it in full, every op takes manage_options, and the one write
 * (cancel-appointment) is off until wpmcp_enable_booking_write, asks for
 * confirmation naming the masked customer, sends no email and is undone by
 * rollback-operation.
 */
class BookingPluginsPackTest extends \WP_UnitTestCase
{
    private const PRESENCE = [ 'wpmcp_bookly_active', 'wpmcp_ssa_active', 'wpmcp_bookingpress_active', 'wpmcp_latepoint_active' ];

    private const PLUGINS = [ 'bookly', 'ssa', 'bookingpress', 'latepoint' ];

    /** @var array{bookly: int, ssa: int, bookingpress: int, latepoint: int} */
    private array $ids;

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_booking_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_booking_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        foreach (self::PRESENCE as $filter) {
            add_filter($filter, '__return_true');
        }
        $this->ids = wpmcp_test_seed_bookings();
    }

    protected function tearDown(): void
    {
        foreach (self::PRESENCE as $filter) {
            remove_all_filters($filter);
        }
        remove_all_filters(Booking_Adapter::ENABLE_FILTER);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args, bool $confirm = false): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        if ($confirm) {
            $call['confirm'] = true;
        }
        return (new Plugin_Data_Integration())->handle_write($call);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out['result'];
    }

    /** The raw status column of one booking, read straight from its table. */
    private function raw_status(string $plugin, int $id): string
    {
        global $wpdb;
        [ $table, $key, $column ] = [
            'bookly'       => [ 'bookly_customer_appointments', 'id', 'status' ],
            'ssa'          => [ 'ssa_appointments', 'id', 'status' ],
            'bookingpress' => [ 'bookingpress_appointment_bookings', 'bookingpress_appointment_booking_id', 'bookingpress_appointment_status' ],
            'latepoint'    => [ 'latepoint_bookings', 'id', 'status' ],
        ][ $plugin ];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (string) $wpdb->get_var($wpdb->prepare('SELECT %i FROM %i WHERE %i = %d', $column, $wpdb->prefix . $table, $key, $id));
    }

    /** @return array<string, array{0: string}> */
    public static function plugins(): array
    {
        return array_combine(self::PLUGINS, array_map(static fn (string $p): array => [ $p ], self::PLUGINS));
    }

    // ---------------------------------------------------------------
    // Catalog and gating
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_op_with_its_mode_on_the_plugin_data_pair(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::PLUGINS as $p) {
            foreach ([ 'list-services' => 'read', 'list-appointments' => 'read', 'get-appointment' => 'read', 'cancel-appointment' => 'destructive' ] as $op => $mode) {
                $this->assertArrayHasKey("{$p}-{$op}", $ops, "{$p}-{$op}");
                $this->assertSame($mode, $ops[ "{$p}-{$op}" ]['mode'], "{$p}-{$op}");
            }
        }
        $this->assertSame('read', $ops['bookly-list-staff']['mode']);
        $this->assertSame('read', $ops['latepoint-list-staff']['mode']);
        $this->assertArrayNotHasKey('ssa-list-staff', $ops, 'the free Simply Schedule Appointments has no staff');
        $this->assertArrayNotHasKey('bookingpress-list-staff', $ops, 'the free BookingPress has no staff');
    }

    public function test_without_the_paid_tier_no_op_is_offered(): void
    {
        Gate::set_pro_for_tests(false);
        $names = array_column((new Plugin_Data_Integration())->catalog()['operations'], 'name');
        $this->assertSame([], preg_grep('/^(bookly|ssa|bookingpress|latepoint)-/', $names));
    }

    public function test_an_inactive_plugin_answers_with_its_own_name(): void
    {
        remove_all_filters('wpmcp_latepoint_active');
        add_filter('wpmcp_latepoint_active', '__return_false');
        $out = $this->read('latepoint-list-appointments');
        $this->assertSame('latepoint_inactive', $out['error']['code'] ?? null);
        $this->assertStringContainsString('LatePoint', $out['error']['message']);
    }

    public function test_every_op_takes_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $out = $this->read('bookly-list-appointments');
        $this->assertArrayHasKey('error', $out);
        $this->assertArrayHasKey('error', $this->read('ssa-get-appointment', [ 'appointment_id' => $this->ids['ssa'] ]));
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    /** @dataProvider plugins */
    public function test_list_services_reads_each_plugins_services(string $plugin): void
    {
        $out = $this->ok($this->read("{$plugin}-list-services"));
        $this->assertCount(1, $out['services']);
        $service = $out['services'][0];
        $this->assertSame('Haircut', $service['name']);
        $this->assertSame(60, $service['duration_minutes'], $plugin);
    }

    public function test_list_staff_masks_contact_details(): void
    {
        foreach ([ 'bookly', 'latepoint' ] as $plugin) {
            $out   = $this->ok($this->read("{$plugin}-list-staff"));
            $staff = $out['staff'][0];
            $this->assertSame('Sam Stylist', $staff['name'], $plugin);
            $this->assertSame('s***@example.com', $staff['email'], $plugin);
            $this->assertSame('+***', $staff['phone'], $plugin);
            $this->assertTrue($out['masked']);
        }
    }

    /** @dataProvider plugins */
    public function test_list_appointments_masks_the_customer(string $plugin): void
    {
        $out = $this->ok($this->read("{$plugin}-list-appointments"));
        $this->assertSame(1, $out['total']);
        $row = $out['appointments'][0];
        $this->assertSame($this->ids[ $plugin ], $row['id']);
        $this->assertSame('Haircut', $row['service']['name']);
        $this->assertSame('2026-12-01 10:00:00', $row['start']);
        $this->assertSame('2026-12-01 11:00:00', $row['end']);
        $this->assertSame('j***@example.com', $row['customer']['email']);
        $this->assertSame('J***', $row['customer']['name']);
        $this->assertSame('+***', $row['customer']['phone']);
        $this->assertTrue($out['masked']);
        $this->assertStringNotContainsString('jane.doe', (string) wp_json_encode($out));
    }

    /** @dataProvider plugins */
    public function test_list_appointments_filters_by_status_and_date(string $plugin): void
    {
        $this->assertSame(0, $this->ok($this->read("{$plugin}-list-appointments", [ 'status' => 'cancelled' ]))['total']);
        $this->assertSame(0, $this->ok($this->read("{$plugin}-list-appointments", [ 'from' => '2026-12-02' ]))['total']);
        $this->assertSame(1, $this->ok($this->read("{$plugin}-list-appointments", [ 'from' => '2026-12-01', 'to' => '2026-12-01' ]))['total']);
    }

    /** @dataProvider plugins */
    public function test_get_appointment_returns_the_customer_in_full(string $plugin): void
    {
        $row = $this->ok($this->read("{$plugin}-get-appointment", [ 'appointment_id' => $this->ids[ $plugin ] ]))['appointment'];
        $this->assertSame('jane.doe@example.com', $row['customer']['email']);
        $this->assertSame('Jane Doe', $row['customer']['name']);
        $this->assertSame('+15551234', $row['customer']['phone']);
        $this->assertSame('ssa' === $plugin ? 'booked' : 'approved', $row['status']);
    }

    public function test_get_appointment_refuses_an_unknown_id(): void
    {
        $out = $this->read('bookly-get-appointment', [ 'appointment_id' => 999999 ]);
        $this->assertSame('appointment_not_found', $out['error']['code'] ?? null);
    }

    public function test_staff_and_service_names_come_with_each_booking(): void
    {
        $bookly = $this->ok($this->read('bookly-get-appointment', [ 'appointment_id' => $this->ids['bookly'] ]))['appointment'];
        $this->assertSame('Sam Stylist', $bookly['staff']['name']);
        $late = $this->ok($this->read('latepoint-get-appointment', [ 'appointment_id' => $this->ids['latepoint'] ]))['appointment'];
        $this->assertSame('Sam Stylist', $late['staff']['name']);
        $this->assertSame('LP1', $late['code']);
    }

    // ---------------------------------------------------------------
    // Cancel
    // ---------------------------------------------------------------

    public function test_cancel_is_off_until_the_site_opts_in(): void
    {
        $out = $this->write('bookly-cancel-appointment', [ 'appointment_id' => $this->ids['bookly'] ], true);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null);
        $this->assertStringContainsString(Booking_Adapter::ENABLE_FILTER, $out['error']['message']);
        $this->assertSame('approved', $this->raw_status('bookly', $this->ids['bookly']));
    }

    public function test_cancel_asks_for_confirmation_naming_the_masked_customer(): void
    {
        add_filter(Booking_Adapter::ENABLE_FILTER, '__return_true');
        $out = $this->write('ssa-cancel-appointment', [ 'appointment_id' => $this->ids['ssa'] ]);
        $this->assertSame('confirmation_required', $out['error']['code'] ?? null);
        $this->assertStringContainsString('j***@example.com', $out['error']['message']);
        $this->assertStringNotContainsString('jane.doe', (string) wp_json_encode($out));
        $this->assertSame('booked', $this->raw_status('ssa', $this->ids['ssa']));
    }

    /** @dataProvider plugins */
    public function test_cancel_sets_the_plugins_cancelled_status_and_undo_puts_it_back(string $plugin): void
    {
        add_filter(Booking_Adapter::ENABLE_FILTER, '__return_true');
        $id     = $this->ids[ $plugin ];
        $before = $this->raw_status($plugin, $id);

        $out = $this->write("{$plugin}-cancel-appointment", [ 'appointment_id' => $id ], true);
        $result = $this->ok($out);
        $this->assertSame('cancelled', $result['status']);
        $this->assertFalse($result['notified']);
        $this->assertSame([ 'ssa' => 'canceled', 'bookingpress' => '3' ][ $plugin ] ?? 'cancelled', $this->raw_status($plugin, $id));
        $this->assertSame(1, $this->ok($this->read("{$plugin}-list-appointments", [ 'status' => 'cancelled' ]))['total']);

        $this->assertTrue(Rollback_Service::restore_operation((string) $out['operation_id']));
        $this->assertSame($before, $this->raw_status($plugin, $id));
    }

    public function test_cancelling_a_cancelled_booking_is_refused(): void
    {
        add_filter(Booking_Adapter::ENABLE_FILTER, '__return_true');
        $this->ok($this->write('bookingpress-cancel-appointment', [ 'appointment_id' => $this->ids['bookingpress'] ], true));
        $out = $this->write('bookingpress-cancel-appointment', [ 'appointment_id' => $this->ids['bookingpress'] ], true);
        $this->assertSame('already_done', $out['error']['code'] ?? null);
    }

    public function test_cancel_refuses_an_unknown_booking_without_a_snapshot(): void
    {
        add_filter(Booking_Adapter::ENABLE_FILTER, '__return_true');
        $out = $this->write('latepoint-cancel-appointment', [ 'appointment_id' => 999999 ], true);
        $this->assertSame('appointment_not_found', $out['error']['code'] ?? null);
        $this->assertArrayNotHasKey('operation_id', $out);
    }
}
