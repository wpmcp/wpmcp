<?php

namespace WPMCP\Tests\Free\Maintenance;

use WPMCP\Maintenance\Maintenance_Guard;
use WPMCP\Tools\Backup\Site_Backup_Dir;

class MaintenanceGuardTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        $state = Site_Backup_Dir::path() . '/restore-in-progress.json';
        if (is_file($state)) {
            unlink($state);
        }
        delete_option('wpmcp_maintenance');
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_does_not_block_when_maintenance_mode_is_off(): void
    {
        delete_option('wpmcp_maintenance');
        wp_set_current_user(0);

        $this->assertFalse((new Maintenance_Guard())->should_block());
    }

    public function test_blocks_a_logged_out_visitor_when_maintenance_mode_is_on(): void
    {
        update_option('wpmcp_maintenance', ['enabled' => true, 'message' => 'Brb.', 'retry_after' => 3600]);
        wp_set_current_user(0);

        $this->assertTrue((new Maintenance_Guard())->should_block());
    }

    public function test_allows_an_administrator_when_maintenance_mode_is_on(): void
    {
        update_option('wpmcp_maintenance', ['enabled' => true, 'message' => 'Brb.', 'retry_after' => 3600]);

        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);

        $this->assertFalse((new Maintenance_Guard())->should_block());
    }

    public function test_blocks_a_logged_in_subscriber_when_maintenance_mode_is_on(): void
    {
        update_option('wpmcp_maintenance', ['enabled' => true, 'message' => 'Brb.', 'retry_after' => 3600]);

        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);

        $this->assertTrue((new Maintenance_Guard())->should_block());
    }

    /**
     * Issue #813: while a restore rebuilds wp_options the wpmcp_maintenance
     * row is missing, so the guard must also see the restore itself: its
     * progress file on disk plus the restore lock another connection holds.
     */
    public function test_blocks_while_a_restore_is_running_even_without_the_option(): void
    {
        delete_option('wpmcp_maintenance');
        wp_set_current_user(0);
        $other = $this->hold_restore_lock();

        try {
            $this->assertTrue((new Maintenance_Guard())->should_block(), 'A running restore must block visitors with no option row.');

            $response = (new Maintenance_Guard())->response();
            $this->assertNotSame('', $response['message']);
            $this->assertGreaterThan(0, $response['retry_after']);
        } finally {
            $other->close();
        }
    }

    public function test_a_progress_file_left_by_a_dead_restore_does_not_lock_the_site(): void
    {
        // The lock is gone with the process that held it; disable-maintenance
        // must stay the way out, so a stale file alone never blocks.
        delete_option('wpmcp_maintenance');
        wp_set_current_user(0);
        $this->write_progress_file();

        $this->assertFalse((new Maintenance_Guard())->should_block());
    }

    public function test_an_administrator_still_passes_during_a_restore(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        $other = $this->hold_restore_lock();

        try {
            $this->assertFalse((new Maintenance_Guard())->should_block());
        } finally {
            $other->close();
        }
    }

    private function write_progress_file(): void
    {
        Site_Backup_Dir::protect(Site_Backup_Dir::path());
        file_put_contents(Site_Backup_Dir::path() . '/restore-in-progress.json', (string) wp_json_encode([
            'started_at' => gmdate('c'),
            'statement'  => 3,
            'table'      => 'wp_options',
        ]));
    }

    /** A second connection holding the restore lock, as the restore's own request does. */
    private function hold_restore_lock(): \wpdb
    {
        global $wpdb;
        $this->write_progress_file();
        $other = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $name  = 'wpmcp_restore_' . substr(md5(DB_NAME . '|' . $wpdb->base_prefix), 0, 16);
        $this->assertSame('1', (string) $other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $name)));

        return $other;
    }
}
