<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\FooGallery_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * FooGallery (issue #705, the first plugin of the galleries and sliders
 * issue): free-tier ops on the plugin-data dispatcher pair, so they add no
 * top-level tools.
 *
 * FooGallery is not loaded in the shared test core. Presence is driven
 * through wpmcp_foogallery_active, and its post type is registered here the
 * way FooGallery 3.3 does in includes/class-posttypes.php: a gallery is a
 * non-public `foogallery` post behind its own capabilities (edit_foogalleries
 * and the rest, FooGallery_PostTypes::GALLERY_CAPABILITIES), which the
 * plugin grants to the gallery creator role and above. A gallery keeps its
 * images as the foogallery_attachments post meta (a list of attachment ids
 * in display order), its layout as foogallery_template, its source as
 * foogallery_datasource (media_library by default) and its sort as
 * foogallery_sort (includes/constants.php, gallery-management-functions.php).
 *
 * The writes are off until the site opts in (wpmcp_enable_foogallery_write)
 * and each is undone in a test.
 */
class FooGalleryPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'foogallery-list-galleries' => 'read',
        'foogallery-get-gallery'    => 'read',
        'foogallery-create-gallery' => 'write',
        'foogallery-add-images'     => 'write',
        'foogallery-remove-images'  => 'write',
        'foogallery-reorder-images' => 'write',
    ];

    private const CAPS = [
        'edit_foogalleries', 'edit_others_foogalleries', 'delete_foogalleries', 'publish_foogalleries',
        'read_private_foogalleries', 'create_foogalleries',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        register_post_type('foogallery', [
            'public'       => false,
            'show_ui'      => true,
            'supports'     => [ 'title', 'thumbnail' ],
            'map_meta_cap' => true,
            'capabilities' => [
                'edit_post'          => 'edit_foogallery',
                'read_post'          => 'read_foogallery',
                'delete_post'        => 'delete_foogallery',
                'edit_posts'         => 'edit_foogalleries',
                'edit_others_posts'  => 'edit_others_foogalleries',
                'delete_posts'       => 'delete_foogalleries',
                'publish_posts'      => 'publish_foogalleries',
                'read_private_posts' => 'read_private_foogalleries',
                'create_posts'       => 'create_foogalleries',
            ],
        ]);
        $role = get_role('administrator');
        foreach (self::CAPS as $cap) {
            $role->add_cap($cap);
        }
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        add_filter('wpmcp_foogallery_active', '__return_true');
        add_filter('wpmcp_enable_foogallery_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_foogallery_active');
        remove_all_filters('wpmcp_enable_foogallery_write');
        $role = get_role('administrator');
        foreach (self::CAPS as $cap) {
            $role->remove_cap($cap);
        }
        unregister_post_type('foogallery');
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        $out = (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
        return isset($out['result']) && ! isset($out['error']) ? $out['result'] : $out;
    }

    private function write(string $op, array $args): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    private function image(string $title = 'Lake'): int
    {
        return self::factory()->attachment->create_object([
            'file'           => sanitize_title($title) . '.jpg',
            'post_mime_type' => 'image/jpeg',
            'post_title'     => $title,
            'post_status'    => 'inherit',
        ]);
    }

    /** A gallery as foogallery_insert_gallery() stores it. */
    private function gallery(string $title = 'Summer', array $images = [], string $status = 'publish', string $template = 'default'): int
    {
        $id = self::factory()->post->create([ 'post_type' => 'foogallery', 'post_title' => $title, 'post_status' => $status ]);
        update_post_meta($id, 'foogallery_template', $template);
        update_post_meta($id, '_foogallery_settings', [ 'default_thumbnail_dimensions' => [ 'width' => 150, 'height' => 150 ] ]);
        update_post_meta($id, 'foogallery_attachments', $images);
        update_post_meta($id, 'foogallery_datasource', 'media_library');
        update_post_meta($id, 'foogallery_sort', '');
        return $id;
    }

    // ---------------------------------------------------------------
    // Catalog and availability
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_op_with_its_mode(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
        }
    }

    public function test_while_foogallery_is_missing_a_call_answers_plugin_missing_with_the_plugins_link(): void
    {
        remove_all_filters('wpmcp_foogallery_active');
        add_filter('wpmcp_foogallery_active', '__return_false');
        $out = $this->read('foogallery-list-galleries');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate FooGallery', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
    }

    public function test_with_the_plugin_files_installed_but_inactive_it_answers_plugin_inactive(): void
    {
        remove_all_filters('wpmcp_foogallery_active');
        add_filter('wpmcp_foogallery_active', '__return_false');
        $dir     = WP_PLUGIN_DIR . '/foogallery';
        $created = ! is_dir($dir);
        if ($created) {
            wp_mkdir_p($dir);
        }
        $file  = $dir . '/foogallery.php';
        $owned = ! file_exists($file);
        if ($owned) {
            file_put_contents($file, "<?php\n// Plugin Name: FooGallery\n");
        }
        try {
            $out = $this->read('foogallery-list-galleries');
        } finally {
            if ($owned) {
                unlink($file);
            }
            if ($created) {
                rmdir($dir);
            }
        }
        $this->assertSame('plugin_inactive', $out['error']['code'] ?? null);
    }

    public function test_presence_defaults_to_the_plugin_constant(): void
    {
        remove_all_filters('wpmcp_foogallery_active');
        $this->assertSame(defined('FOOGALLERY_VERSION'), FooGallery_Pack::active());
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $img = $this->image();
        $id  = $this->gallery('Summer', [ $img ]);
        remove_all_filters('wpmcp_enable_foogallery_write');
        foreach ([
            [ 'foogallery-create-gallery', [ 'title' => 'x' ] ],
            [ 'foogallery-add-images', [ 'id' => $id, 'image_ids' => [ $this->image('Other') ] ] ],
            [ 'foogallery-remove-images', [ 'id' => $id, 'image_ids' => [ $img ] ] ],
            [ 'foogallery-reorder-images', [ 'id' => $id, 'image_ids' => [ $img ] ] ],
        ] as [ $op, $args ]) {
            $out = $this->write($op, $args);
            $this->assertSame('opt_in_required', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString('wpmcp_enable_foogallery_write', $out['error']['message']);
        }
        $this->assertSame([ $img ], get_post_meta($id, 'foogallery_attachments', true));
    }

    public function test_only_users_with_the_foogallery_capability_may_read_or_write(): void
    {
        $img = $this->image();
        $id  = $this->gallery('Summer', [ $img ]);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertSame('operation_denied', $this->read('foogallery-list-galleries')['error']['code'] ?? null, 'an editor lacks edit_foogalleries');
        $this->assertSame('operation_denied', $this->read('foogallery-get-gallery', [ 'id' => $id ])['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->write('foogallery-create-gallery', [ 'title' => 'x' ])['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->write('foogallery-remove-images', [ 'id' => $id, 'image_ids' => [ $img ] ])['error']['code'] ?? null);
        $this->assertSame([ $img ], get_post_meta($id, 'foogallery_attachments', true));
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_galleries_returns_each_gallery_with_its_image_count_and_filters(): void
    {
        $a = $this->gallery('Alpha', [ $this->image('One'), $this->image('Two') ]);
        $b = $this->gallery('Beta', [], 'draft', 'masonry');

        $out = $this->ok($this->read('foogallery-list-galleries'));
        $this->assertSame(2, $out['total']);
        $rows = array_column($out['galleries'], null, 'id');
        $this->assertSame('Alpha', $rows[ $a ]['title']);
        $this->assertSame(2, $rows[ $a ]['image_count']);
        $this->assertSame('default', $rows[ $a ]['template']);
        $this->assertSame('media_library', $rows[ $a ]['datasource']);
        $this->assertSame('[foogallery id="' . $a . '"]', $rows[ $a ]['shortcode']);
        $this->assertSame('masonry', $rows[ $b ]['template']);
        $this->assertArrayNotHasKey('images', $rows[ $a ], 'lists leave the images out');
        $this->assertSame([ $b ], array_column($this->read('foogallery-list-galleries', [ 'status' => 'draft' ])['galleries'], 'id'));
        $this->assertSame([ $a ], array_column($this->read('foogallery-list-galleries', [ 'search' => 'Alpha' ])['galleries'], 'id'));
    }

    public function test_get_gallery_returns_the_images_in_display_order(): void
    {
        $one = $this->image('One');
        $two = $this->image('Two');
        $id  = $this->gallery('Alpha', [ $two, $one ]);
        update_post_meta($one, '_wp_attachment_image_alt', 'First alt');

        $g = $this->ok($this->read('foogallery-get-gallery', [ 'id' => $id ]))['gallery'];
        $this->assertSame('Alpha', $g['title']);
        $this->assertSame([ $two, $one ], $g['image_ids']);
        $this->assertSame([ $two, $one ], array_column($g['images'], 'id'));
        $this->assertSame('One', $g['images'][1]['title']);
        $this->assertSame('First alt', $g['images'][1]['alt']);
        $this->assertSame('', $g['sort']);
        $this->assertSame('gallery_not_found', $this->read('foogallery-get-gallery', [ 'id' => self::factory()->post->create() ])['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // Writes
    // ---------------------------------------------------------------

    public function test_create_gallery_stores_a_draft_with_its_images_and_rollback_session_trashes_it(): void
    {
        $one = $this->image('One');
        $two = $this->image('Two');
        $out = $this->ok($this->write('foogallery-create-gallery', [ 'title' => 'Wedding', 'image_ids' => [ $two, $one, $two ] ]));
        $g   = $out['result']['gallery'];
        $post = get_post($g['id']);
        $this->assertSame('foogallery', $post->post_type);
        $this->assertSame('draft', $post->post_status, 'a new gallery is a draft unless status says otherwise');
        $this->assertSame('Wedding', $post->post_title);
        $this->assertSame([ $two, $one ], get_post_meta($post->ID, 'foogallery_attachments', true), 'duplicates dropped, order kept');
        $this->assertSame('default', get_post_meta($post->ID, 'foogallery_template', true));
        $this->assertSame('media_library', get_post_meta($post->ID, 'foogallery_datasource', true));
        $this->assertSame('[foogallery id="' . $post->ID . '"]', $g['shortcode']);

        $published = $this->ok($this->write('foogallery-create-gallery', [ 'title' => 'Live', 'status' => 'publish', 'template' => 'masonry' ]));
        $this->assertSame('publish', get_post_status($published['result']['gallery']['id']));
        $this->assertSame('masonry', get_post_meta($published['result']['gallery']['id'], 'foogallery_template', true));

        $this->assertNotEmpty($out['operation_ids'] ?? []);
        foreach ((array) $out['operation_ids'] as $operation) {
            Rollback_Service::restore_operation((string) $operation);
        }
        $this->assertSame('trash', get_post_status($post->ID));
    }

    public function test_create_gallery_refuses_an_id_that_is_not_an_attachment(): void
    {
        $out = $this->write('foogallery-create-gallery', [ 'title' => 'x', 'image_ids' => [ self::factory()->post->create() ] ]);
        $this->assertSame('image_not_found', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertSame(0, (int) (new \WP_Query([ 'post_type' => 'foogallery', 'post_status' => 'any', 'fields' => 'ids' ]))->found_posts);
    }

    public function test_add_images_appends_without_duplicates_and_rolls_back(): void
    {
        $one = $this->image('One');
        $two = $this->image('Two');
        $id  = $this->gallery('Alpha', [ $one ]);
        update_post_meta($id, 'foogallery_cache', '<div>cached</div>');

        $out = $this->ok($this->write('foogallery-add-images', [ 'id' => $id, 'image_ids' => [ $one, $two ] ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame([ $one, $two ], get_post_meta($id, 'foogallery_attachments', true));
        $this->assertSame('', get_post_meta($id, 'foogallery_cache', true), 'the rendered cache is cleared');
        $this->assertSame(2, $out['result']['gallery']['image_count']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame([ $one ], get_post_meta($id, 'foogallery_attachments', true));
    }

    public function test_remove_images_drops_them_and_rolls_back(): void
    {
        $one = $this->image('One');
        $two = $this->image('Two');
        $id  = $this->gallery('Alpha', [ $one, $two ]);

        $out = $this->ok($this->write('foogallery-remove-images', [ 'id' => $id, 'image_ids' => [ $one ] ]));
        $this->assertSame([ $two ], get_post_meta($id, 'foogallery_attachments', true));
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame([ $one, $two ], get_post_meta($id, 'foogallery_attachments', true));
    }

    public function test_reorder_images_needs_exactly_the_current_images_and_rolls_back(): void
    {
        $one   = $this->image('One');
        $two   = $this->image('Two');
        $three = $this->image('Three');
        $id    = $this->gallery('Alpha', [ $one, $two, $three ]);

        $this->assertSame('images_mismatch', $this->write('foogallery-reorder-images', [ 'id' => $id, 'image_ids' => [ $three, $one ] ])['error']['code'] ?? null);
        $this->assertSame('images_mismatch', $this->write('foogallery-reorder-images', [ 'id' => $id, 'image_ids' => [ $three, $one, $one ] ])['error']['code'] ?? null);

        $out = $this->ok($this->write('foogallery-reorder-images', [ 'id' => $id, 'image_ids' => [ $three, $one, $two ] ]));
        $this->assertSame([ $three, $one, $two ], get_post_meta($id, 'foogallery_attachments', true));
        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame([ $one, $two, $three ], get_post_meta($id, 'foogallery_attachments', true));
    }

    public function test_image_writes_refuse_a_non_gallery_and_a_gallery_fed_by_another_source(): void
    {
        $img = $this->image();
        $this->assertSame('gallery_not_found', $this->write('foogallery-add-images', [ 'id' => self::factory()->post->create(), 'image_ids' => [ $img ] ])['error']['code'] ?? null);
        $id = $this->gallery('Folder', []);
        update_post_meta($id, 'foogallery_datasource', 'folders');
        $out = $this->write('foogallery-add-images', [ 'id' => $id, 'image_ids' => [ $img ] ]);
        $this->assertSame('datasource_unsupported', $out['error']['code'] ?? null);
        $this->assertSame([], get_post_meta($id, 'foogallery_attachments', true));
    }
}
