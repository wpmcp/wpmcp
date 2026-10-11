<?php

namespace WPMCP\Tests\Free\Multisite;

use WPMCP\Activator;
use WPMCP\Data_Registry;
use WPMCP\Site_Tables;
use WPMCP\Tools\Content\Update_Post;

/**
 * Every per-site table WP MCP owns exists on every site of a network
 * (issue #527): after a network activation, on a site created later, and
 * lazily on a site that is still missing them.
 *
 * Runs under WP_MULTISITE=1 and skips on a single site. Inside a test, the
 * WordPress harness turns CREATE and DROP TABLE into their TEMPORARY forms,
 * so the DDL here does not end the test's transaction. A sub-site's tables
 * are dropped first so a table left over from an earlier test that got the
 * same blog id cannot make an assertion pass by accident.
 */
class NetworkSiteTablesTest extends \WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        if (! is_multisite()) {
            $this->markTestSkipped('Runs under WP_MULTISITE=1.');
        }
    }

    public function test_an_undoable_write_on_a_sub_site_works_after_network_activation(): void
    {
        $blog = $this->bare_sub_site();

        Activator::activate(true);

        switch_to_blog($blog);
        $admin = (int) self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin);
        $post = (int) self::factory()->post->create(['post_title' => 'Before']);

        try {
            (new Update_Post())->handle(['post_id' => $post, 'title' => 'After']);
            $error = null;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
        $title = get_post_field('post_title', $post);
        restore_current_blog();

        $this->assertNull($error, 'update-post failed on a sub-site of a network-activated install: ' . (string) $error);
        $this->assertSame('After', $title);
    }

    public function test_network_activation_creates_every_registered_table_on_every_site(): void
    {
        $blog = $this->bare_sub_site();

        Activator::activate(true);

        $this->assertSame([], $this->missing_tables($blog));
        $this->assertSame([], $this->missing_tables(get_main_site_id()));
    }

    public function test_a_site_created_while_network_active_gets_every_table(): void
    {
        $this->network_activate_in_options();

        $this->setExpectedDeprecated('wpmu_new_blog');
        $blog = (int) self::factory()->blog->create();

        $this->assertSame([], $this->missing_tables($blog));
    }

    public function test_a_site_missing_its_tables_gets_them_on_next_use(): void
    {
        $blog = $this->bare_sub_site();

        switch_to_blog($blog);
        Site_Tables::maybe_install();
        restore_current_blog();

        $this->assertSame([], $this->missing_tables($blog));
    }

    public function test_the_lazy_install_runs_on_the_admin_and_rest_entry_points(): void
    {
        $this->assertNotFalse(has_action('admin_init', [Site_Tables::class, 'maybe_install']));
        $this->assertNotFalse(has_action('rest_api_init', [Site_Tables::class, 'maybe_install']));
        $this->assertNotFalse(has_action('wp_initialize_site', [Site_Tables::class, 'initialize_site']));
    }

    public function test_the_lazy_install_is_a_no_op_once_the_site_is_current(): void
    {
        $blog = $this->bare_sub_site();
        switch_to_blog($blog);
        Site_Tables::maybe_install();

        global $wpdb;
        $before = $wpdb->num_queries;
        Site_Tables::maybe_install();
        $after = $wpdb->num_queries;
        restore_current_blog();

        $this->assertSame($before, $after, 'A current site must not query the database to decide nothing needs installing.');
    }

    public function test_every_registered_table_has_an_installer(): void
    {
        $this->assertSame(Data_Registry::TABLES, array_keys(Site_Tables::installers()));
    }

    /** A new sub-site with none of WP MCP's tables and no recorded version. */
    private function bare_sub_site(): int
    {
        $this->setExpectedDeprecated('wpmu_new_blog');
        $blog = (int) self::factory()->blog->create();

        global $wpdb;
        switch_to_blog($blog);
        foreach (Data_Registry::TABLES as $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture: remove this plugin's own table.
            $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
        }
        delete_option(Site_Tables::VERSION_OPTION);
        restore_current_blog();

        $this->assertSame(Data_Registry::TABLES, $this->missing_tables($blog), 'fixture: the sub-site should start without the tables');

        return $blog;
    }

    /** Make the plugin look network-active, as core records it. */
    private function network_activate_in_options(): void
    {
        $active = (array) get_site_option('active_sitewide_plugins', []);
        $active[ plugin_basename(WPMCP_FILE) ] = time();
        update_site_option('active_sitewide_plugins', $active);
    }

    /**
     * Registered tables that do not exist on $blog. SHOW TABLES does not
     * list temporary tables, so existence is probed with a query instead.
     *
     * @return list<string>
     */
    private function missing_tables(int $blog): array
    {
        global $wpdb;
        switch_to_blog($blog);
        $missing  = [];
        $suppress = $wpdb->suppress_errors(true);
        foreach (Data_Registry::TABLES as $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test probe on this plugin's own table.
            if (false === $wpdb->query("SELECT 1 FROM {$wpdb->prefix}{$table} LIMIT 0")) {
                $missing[] = $table;
            }
        }
        $wpdb->suppress_errors($suppress);
        restore_current_blog();

        return $missing;
    }
}
