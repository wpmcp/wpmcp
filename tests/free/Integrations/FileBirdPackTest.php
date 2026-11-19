<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\FileBird_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

require_once __DIR__ . '/../../support/filebird-tables.php';

/**
 * FileBird media folders (issue #906): free-tier ops on the plugin-data
 * dispatcher pair, so they add no top-level tools.
 *
 * FileBird is not loaded in the shared test core. Presence is driven
 * through wpmcp_filebird_active, and its two tables are built with
 * FileBird 6.5.9's own DDL (tests/support/filebird-tables.php).
 *
 * The writes are off until the site opts in (wpmcp_enable_filebird_write),
 * and each one is undone in a test. Deleting a folder never deletes an
 * attachment: its attachments become Uncategorized.
 */
class FileBirdPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'filebird-list-folders'          => 'read',
        'filebird-get-folder'            => 'read',
        'filebird-get-attachment-folder' => 'read',
        'filebird-create-folder'         => 'write',
        'filebird-rename-folder'         => 'write',
        'filebird-move-folder'           => 'write',
        'filebird-assign-attachments'    => 'write',
        'filebird-delete-folder'         => 'destructive',
    ];

    public static function wpSetUpBeforeClass(): void
    {
        wpmcp_test_create_filebird_tables();
    }

    public static function wpTearDownAfterClass(): void
    {
        wpmcp_test_drop_filebird_tables();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wpmcp_test_clear_filebird_tables();
        delete_option('fbv_folder_colors');
        delete_option('njt_fbv_folder_per_user');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_filebird_active', '__return_true');
        add_filter('wpmcp_enable_filebird_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_filebird_active');
        remove_all_filters('wpmcp_enable_filebird_write');
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        return isset($out['result']) && ! isset($out['error']) ? $out['result'] : $out;
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
        return $out;
    }

    private function folder(string $name, int $parent = 0, int $owner = 0, int $ord = 0): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'fbv', [ 'name' => $name, 'parent' => $parent, 'type' => 0, 'ord' => $ord, 'created_by' => $owner ]);
        return (int) $wpdb->insert_id;
    }

    private function put(int $folder, int $attachment): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'fbv_attachment_folder', [ 'folder_id' => $folder, 'attachment_id' => $attachment ]);
    }

    private function attachment(string $title = 'Photo'): int
    {
        return self::factory()->attachment->create([ 'post_title' => $title, 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit' ]);
    }

    /** @return array<int, array<string, string>> */
    private function folders(): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY id', $wpdb->prefix . 'fbv'), ARRAY_A);
    }

    /** @return array<int, array<string, string>> */
    private function mapping(): array
    {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM %i ORDER BY folder_id, attachment_id', $wpdb->prefix . 'fbv_attachment_folder'), ARRAY_A);
    }

    // ---------------------------------------------------------------
    // Catalog and availability
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_op_with_its_mode_and_upload_files(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
            $this->assertSame('upload_files', $ops[ $name ]['capability'], $name);
        }
    }

    public function test_presence_defaults_to_the_plugin_constant(): void
    {
        remove_all_filters('wpmcp_filebird_active');
        $this->assertSame(defined('NJFB_VERSION'), FileBird_Pack::active());
    }

    public function test_while_filebird_is_missing_a_call_answers_plugin_missing_naming_it(): void
    {
        remove_all_filters('wpmcp_filebird_active');
        add_filter('wpmcp_filebird_active', '__return_false');
        $out = $this->read('filebird-list-folders');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate FileBird', $out['error']['message']);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        remove_all_filters('wpmcp_enable_filebird_write');
        $out = $this->write('filebird-create-folder', [ 'name' => 'Brand' ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertSame([], $this->folders());
    }

    public function test_an_author_without_upload_files_is_refused(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'subscriber' ]));
        $out = $this->read('filebird-list-folders');
        $this->assertArrayHasKey('error', $out);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_folders_returns_the_tree_with_counts_and_the_uncategorized_count(): void
    {
        $brand  = $this->folder('Brand', 0, 0, 1);
        $logos  = $this->folder('Logos', $brand, 0, 0);
        $events = $this->folder('Events', 0, 0, 0);
        $a      = $this->attachment('A');
        $b      = $this->attachment('B');
        $this->attachment('Loose');
        $trashed = $this->attachment('Gone');
        wp_trash_post($trashed);
        $this->put($logos, $a);
        $this->put($logos, $b);
        $this->put($events, $trashed);

        $out = $this->ok($this->read('filebird-list-folders'));
        $this->assertSame(3, $out['total_folders']);
        $this->assertSame(1, $out['uncategorized']);
        $this->assertFalse($out['owner_scope']['per_user']);
        $this->assertSame([ 'Events', 'Brand' ], array_column($out['folders'], 'name'), 'roots come in FileBird order (ord)');
        $this->assertSame(0, $out['folders'][0]['attachment_count'], 'a trashed attachment is not counted');
        $this->assertSame('Logos', $out['folders'][1]['children'][0]['name']);
        $this->assertSame(2, $out['folders'][1]['children'][0]['attachment_count']);
        $this->assertSame($brand, $out['folders'][1]['children'][0]['parent']);
    }

    public function test_per_user_mode_only_shows_the_current_users_folders(): void
    {
        update_option('njt_fbv_folder_per_user', '1');
        $me = get_current_user_id();
        $this->folder('Mine', 0, $me);
        $this->folder('Shared', 0, 0);
        $out = $this->ok($this->read('filebird-list-folders'));
        $this->assertSame([ 'Mine' ], array_column($out['folders'], 'name'));
        $this->assertTrue($out['owner_scope']['per_user']);
        $this->assertSame($me, $out['owner_scope']['owner']);
    }

    public function test_get_folder_returns_its_path_children_and_attachments(): void
    {
        $brand = $this->folder('Brand');
        $logos = $this->folder('Logos', $brand);
        $this->folder('Old', $logos);
        $a = $this->attachment('Logo dark');
        $this->put($logos, $a);

        $out = $this->ok($this->read('filebird-get-folder', [ 'folder_id' => $logos ]));
        $this->assertSame([ 'Brand', 'Logos' ], $out['folder']['path']);
        $this->assertSame([ 'Old' ], array_column($out['folder']['children'], 'name'));
        $this->assertSame(1, $out['total']);
        $this->assertSame($a, $out['attachments'][0]['id']);
        $this->assertSame('Logo dark', $out['attachments'][0]['title']);
        $this->assertSame('image/jpeg', $out['attachments'][0]['mime_type']);
    }

    public function test_get_folder_refuses_an_unknown_folder_and_another_owners_folder(): void
    {
        $out = $this->read('filebird-get-folder', [ 'folder_id' => 999 ]);
        $this->assertSame('folder_not_found', $out['error']['code'] ?? null);

        update_option('njt_fbv_folder_per_user', '1');
        $theirs = $this->folder('Theirs', 0, get_current_user_id() + 100);
        $out    = $this->read('filebird-get-folder', [ 'folder_id' => $theirs ]);
        $this->assertSame('folder_not_found', $out['error']['code'] ?? null);
    }

    public function test_get_attachment_folder_names_the_folder_or_null(): void
    {
        $brand = $this->folder('Brand');
        $a     = $this->attachment();
        $b     = $this->attachment();
        $this->put($brand, $a);

        $out = $this->ok($this->read('filebird-get-attachment-folder', [ 'attachment_id' => $a ]));
        $this->assertSame($brand, $out['folder']['id']);
        $this->assertSame([ 'Brand' ], $out['folder']['path']);
        $out = $this->ok($this->read('filebird-get-attachment-folder', [ 'attachment_id' => $b ]));
        $this->assertNull($out['folder']);

        $post = self::factory()->post->create();
        $out  = $this->read('filebird-get-attachment-folder', [ 'attachment_id' => $post ]);
        $this->assertSame('attachment_not_found', $out['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    public function test_create_folder_appends_it_under_the_parent_and_rollback_removes_it(): void
    {
        $brand = $this->folder('Brand');
        $this->folder('Logos', $brand, 0, 4);
        $out = $this->ok($this->write('filebird-create-folder', [ 'name' => '=Icons', 'parent_id' => $brand ]));
        $id  = $out['result']['folder']['id'];
        $this->assertSame('Icons', $out['result']['folder']['name'], 'a leading formula sign is stripped as FileBird does');
        $row = array_column($this->folders(), null, 'id')[ $id ];
        $this->assertSame((string) $brand, $row['parent']);
        $this->assertSame('5', $row['ord']);
        $this->assertSame('0', $row['created_by']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertArrayNotHasKey($id, array_column($this->folders(), null, 'id'));
    }

    public function test_create_folder_refuses_a_duplicate_name_and_an_unknown_parent(): void
    {
        $this->folder('Brand');
        $out = $this->write('filebird-create-folder', [ 'name' => 'Brand' ]);
        $this->assertSame('folder_exists', $out['error']['code'] ?? null);
        $out = $this->write('filebird-create-folder', [ 'name' => 'X', 'parent_id' => 999 ]);
        $this->assertSame('folder_not_found', $out['error']['code'] ?? null);
        $this->assertCount(1, $this->folders());
    }

    public function test_rename_folder_and_rollback(): void
    {
        $brand = $this->folder('Brand');
        $out   = $this->ok($this->write('filebird-rename-folder', [ 'folder_id' => $brand, 'name' => 'Brand assets' ]));
        $this->assertSame('Brand assets', $this->folders()[0]['name']);
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame('Brand', $this->folders()[0]['name']);
    }

    public function test_move_folder_refuses_a_cycle_and_rolls_back_exactly(): void
    {
        $brand  = $this->folder('Brand');
        $logos  = $this->folder('Logos', $brand, 0, 3);
        $events = $this->folder('Events');

        $out = $this->write('filebird-move-folder', [ 'folder_id' => $brand, 'parent_id' => $logos ]);
        $this->assertSame('folder_cycle', $out['error']['code'] ?? null);
        $out = $this->write('filebird-move-folder', [ 'folder_id' => $brand, 'parent_id' => $brand ]);
        $this->assertSame('folder_cycle', $out['error']['code'] ?? null);

        $before = $this->folders();
        $out    = $this->ok($this->write('filebird-move-folder', [ 'folder_id' => $logos, 'parent_id' => $events ]));
        $this->assertSame((string) $events, array_column($this->folders(), null, 'id')[ $logos ]['parent']);
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, $this->folders());
    }

    public function test_assign_attachments_moves_them_and_one_rollback_restores_the_batch(): void
    {
        $brand  = $this->folder('Brand');
        $events = $this->folder('Events');
        $a      = $this->attachment();
        $b      = $this->attachment();
        $c      = $this->attachment();
        $this->put($brand, $a);
        $this->put($events, $c);
        $before = $this->mapping();

        $out = $this->ok($this->write('filebird-assign-attachments', [ 'folder_id' => $events, 'attachment_ids' => [ $a, $b, $c ] ]));
        $this->assertSame([ $a => 'moved', $b => 'moved', $c => 'already' ], array_column($out['result']['attachments'], 'status', 'id'));
        $this->assertSame([ (string) $events ], array_values(array_unique(array_column($this->mapping(), 'folder_id'))));
        $this->assertCount(3, $this->mapping());

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, $this->mapping());
    }

    public function test_assign_to_folder_zero_makes_attachments_uncategorized(): void
    {
        $brand = $this->folder('Brand');
        $a     = $this->attachment();
        $this->put($brand, $a);
        $this->ok($this->write('filebird-assign-attachments', [ 'folder_id' => 0, 'attachment_ids' => [ $a ] ]));
        $this->assertSame([], $this->mapping());
    }

    public function test_assign_refuses_a_post_that_is_not_an_attachment(): void
    {
        $brand = $this->folder('Brand');
        $post  = self::factory()->post->create();
        $out   = $this->write('filebird-assign-attachments', [ 'folder_id' => $brand, 'attachment_ids' => [ $post ] ]);
        $this->assertSame('attachment_not_found', $out['error']['code'] ?? null);
        $this->assertSame([], $this->mapping());
    }

    public function test_assign_in_per_user_mode_leaves_another_owners_folder_rows_alone(): void
    {
        update_option('njt_fbv_folder_per_user', '1');
        $mine   = $this->folder('Mine', 0, get_current_user_id());
        $theirs = $this->folder('Theirs', 0, get_current_user_id() + 100);
        $a      = $this->attachment();
        $this->put($theirs, $a);
        $this->ok($this->write('filebird-assign-attachments', [ 'folder_id' => $mine, 'attachment_ids' => [ $a ] ]));
        $this->assertEqualsCanonicalizing([ (string) $mine, (string) $theirs ], array_column($this->mapping(), 'folder_id'));
    }

    public function test_delete_folder_needs_confirm_and_previews_what_it_removes(): void
    {
        $brand = $this->folder('Brand');
        $this->folder('Logos', $brand);
        $a = $this->attachment();
        $this->put($brand, $a);

        $out = $this->write('filebird-delete-folder', [ 'folder_id' => $brand ]);
        $this->assertSame('confirmation_required', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Logos', (string) wp_json_encode($out));
        $this->assertSame(1, $out['error']['data']['attachments_uncategorized'] ?? null);
        $this->assertCount(2, $this->folders());
    }

    public function test_delete_folder_keeps_the_attachments_and_rollback_session_restores_everything(): void
    {
        $brand  = $this->folder('Brand');
        $logos  = $this->folder('Logos', $brand);
        $events = $this->folder('Events');
        $a      = $this->attachment();
        $b      = $this->attachment();
        $c      = $this->attachment();
        $this->put($brand, $a);
        $this->put($logos, $b);
        $this->put($events, $c);
        update_option('fbv_folder_colors', [ $brand => '#ff0000', $events => '#00ff00' ]);
        $folders = $this->folders();
        $mapping = $this->mapping();

        $out = $this->ok($this->write('filebird-delete-folder', [ 'folder_id' => $brand ], true));
        $this->assertEqualsCanonicalizing([ $brand, $logos ], $out['result']['deleted_folders']);
        $this->assertSame(2, $out['result']['attachments_uncategorized']);
        $this->assertSame([ (string) $events ], array_column($this->folders(), 'id'));
        $this->assertSame([ [ 'folder_id' => (string) $events, 'attachment_id' => (string) $c ] ], $this->mapping());
        $this->assertSame([ $events => '#00ff00' ], get_option('fbv_folder_colors'));
        foreach ([ $a, $b, $c ] as $id) {
            $this->assertInstanceOf(\WP_Post::class, get_post($id), 'deleting a folder never deletes an attachment');
            $this->assertNotSame('trash', get_post_status($id));
        }

        $this->assertTrue($out['recoverable']);
        Rollback_Service::restore_session($out['session_id']);
        $this->assertSame($folders, $this->folders());
        $this->assertSame($mapping, $this->mapping());
        $this->assertSame([ $brand => '#ff0000', $events => '#00ff00' ], get_option('fbv_folder_colors'));
    }
}
