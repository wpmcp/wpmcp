<?php

namespace WPMCP\Tests\Free\Backup;

use WPMCP\Maintenance\Maintenance_Guard;
use WPMCP\Tools\Backup\Restore_Maintenance;

/**
 * Maintenance mode held across a restore through Maintenance_Guard's own
 * option (issue #190).
 */
class RestoreMaintenanceTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        delete_option(Restore_Maintenance::OPTION);
        parent::tearDown();
    }

    public function test_enter_blocks_visitors_through_the_existing_guard(): void
    {
        $maintenance = new Restore_Maintenance();
        $maintenance->enter();

        wp_set_current_user(0);
        $this->assertTrue((new Maintenance_Guard())->should_block());
        $this->assertTrue($maintenance->is_active());
    }

    public function test_leave_deletes_a_row_that_did_not_exist_before(): void
    {
        $maintenance = new Restore_Maintenance();
        $maintenance->enter();
        $maintenance->leave();

        $this->assertFalse(get_option(Restore_Maintenance::OPTION));
        $this->assertFalse($maintenance->is_active());
    }

    public function test_leave_puts_back_the_value_from_before(): void
    {
        $before = ['enabled' => false, 'message' => 'x', 'retry_after' => 0];
        update_option(Restore_Maintenance::OPTION, $before);

        $maintenance = new Restore_Maintenance();
        $maintenance->enter();
        $maintenance->leave();

        $this->assertSame($before, get_option(Restore_Maintenance::OPTION));
    }

    public function test_after_the_options_table_is_replaced_the_imported_value_wins_and_the_guard_is_reasserted(): void
    {
        global $wpdb;
        $maintenance = new Restore_Maintenance();
        $maintenance->enter();

        // What the dump's wp_options leaves behind: the backup's own value,
        // written underneath the object cache.
        $imported = ['enabled' => false, 'message' => 'from the backup', 'retry_after' => 0];
        $wpdb->update($wpdb->options, ['option_value' => maybe_serialize($imported)], ['option_name' => Restore_Maintenance::OPTION]);

        $maintenance->after_options_imported();
        $this->assertTrue(get_option(Restore_Maintenance::OPTION)['enabled'], 'The guard is back on for the rest of the import.');

        $maintenance->leave();
        $this->assertSame($imported, get_option(Restore_Maintenance::OPTION));
    }

    public function test_leave_without_enter_is_a_no_op(): void
    {
        update_option(Restore_Maintenance::OPTION, ['enabled' => true]);

        (new Restore_Maintenance())->leave();
        (new Restore_Maintenance())->after_options_imported();

        $this->assertSame(['enabled' => true], get_option(Restore_Maintenance::OPTION));
    }
}
