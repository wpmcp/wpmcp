<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Cpt_Builder_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Custom post type builders (issue #710): Custom Post Type UI and MB Custom
 * Post Types (Meta Box, also bundled in Meta Box AIO) on the plugin-data
 * pair, so they add no top-level tool.
 *
 * Neither plugin is loaded in the shared test core. Presence is driven
 * through wpmcp_cptui_active and wpmcp_mb_cpt_active, and the data is
 * stored the way the real plugins store it:
 *  - Custom Post Type UI 1.19.3 keeps every post type in the
 *    cptui_post_types option and every taxonomy in cptui_taxonomies, keyed
 *    by slug, booleans as the strings "true" and "false"
 *    (cptui_update_post_type(), cptui_update_taxonomy()), and sets the
 *    cptui_flush_rewrite_rules transient after a save.
 *  - MB Custom Post Types 2.13.1 keeps each definition as a post of type
 *    mb-post-type or mb-taxonomy behind manage_options, its settings as
 *    JSON in post_content, registering only published ones
 *    (PostTypeRegister::get_post_types(), TaxonomyRegister).
 *
 * Writes are off until the site opts in (wpmcp_enable_cpt_builder_write)
 * and each is undone in a test.
 */
class CptBuilderPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'cpt-list-types'       => 'read',
        'cpt-get-type'         => 'read',
        'cpt-create-post-type' => 'write',
        'cpt-update-post-type' => 'write',
        'cpt-create-taxonomy'  => 'write',
        'cpt-update-taxonomy'  => 'write',
    ];

    /** @var string[] */
    private array $registered = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        foreach ([ 'mb-post-type', 'mb-taxonomy' ] as $type) {
            register_post_type($type, [
                'public'       => false,
                'show_ui'      => true,
                'supports'     => false,
                'map_meta_cap' => true,
                'capabilities' => [ 'edit_posts' => 'manage_options', 'edit_others_posts' => 'manage_options', 'publish_posts' => 'manage_options', 'read_private_posts' => 'manage_options', 'delete_posts' => 'manage_options', 'create_posts' => 'manage_options', 'edit_published_posts' => 'manage_options', 'delete_published_posts' => 'manage_options' ],
            ]);
        }
        delete_option('cptui_post_types');
        delete_option('cptui_taxonomies');
        delete_transient('cptui_flush_rewrite_rules');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        if (is_multisite()) {
            grant_super_admin(get_current_user_id());
        }
        add_filter('wpmcp_cptui_active', '__return_true');
        add_filter('wpmcp_mb_cpt_active', '__return_true');
        add_filter('wpmcp_enable_cpt_builder_write', '__return_true');
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_cptui_active');
        remove_all_filters('wpmcp_mb_cpt_active');
        remove_all_filters('wpmcp_enable_cpt_builder_write');
        foreach ($this->registered as $type) {
            if (post_type_exists($type)) {
                unregister_post_type($type);
            }
            if (taxonomy_exists($type)) {
                unregister_taxonomy($type);
            }
        }
        unregister_post_type('mb-taxonomy');
        unregister_post_type('mb-post-type');
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

    /** Undo every operation a write returned. */
    private function undo(array $out): void
    {
        $ids = (array) ($out['operation_ids'] ?? [ $out['operation_id'] ?? '' ]);
        $this->assertNotEmpty(array_filter($ids), (string) wp_json_encode($out));
        foreach ($ids as $id) {
            $this->assertTrue(Rollback_Service::restore_operation((string) $id), (string) $id);
        }
    }

    /** A post type as cptui_update_post_type() stores it. */
    private function cptui_post_type(string $slug, array $over = []): array
    {
        $entry = $over + [
            'name' => $slug, 'label' => 'Books', 'singular_label' => 'Book', 'description' => '', 'public' => 'true',
            'publicly_queryable' => 'true', 'show_ui' => 'true', 'show_in_nav_menus' => 'true', 'delete_with_user' => 'false',
            'show_in_rest' => 'true', 'rest_base' => '', 'rest_controller_class' => '', 'rest_namespace' => '', 'has_archive' => 'false',
            'has_archive_string' => '', 'exclude_from_search' => 'false', 'capability_type' => 'post', 'hierarchical' => 'false',
            'can_export' => 'false', 'rewrite' => 'true', 'rewrite_slug' => '', 'rewrite_withfront' => 'true', 'query_var' => 'true',
            'query_var_slug' => '', 'menu_position' => '', 'show_in_menu' => 'true', 'show_in_menu_string' => '', 'menu_icon' => null,
            'register_meta_box_cb' => null, 'supports' => [ 'title', 'editor', 'thumbnail' ], 'taxonomies' => [], 'labels' => [],
            'custom_supports' => '', 'enter_title_here' => '',
        ];
        $all          = (array) get_option('cptui_post_types', []);
        $all[ $slug ] = $entry;
        update_option('cptui_post_types', $all);
        return $entry;
    }

    /** A taxonomy as cptui_update_taxonomy() stores it. */
    private function cptui_taxonomy(string $slug, array $types = [ 'post' ]): void
    {
        $all          = (array) get_option('cptui_taxonomies', []);
        $all[ $slug ] = [
            'name' => $slug, 'label' => 'Genres', 'singular_label' => 'Genre', 'description' => '', 'public' => 'true',
            'publicly_queryable' => 'true', 'hierarchical' => 'true', 'show_ui' => 'true', 'show_in_menu' => 'true',
            'show_in_nav_menus' => 'true', 'query_var' => 'true', 'query_var_slug' => '', 'rewrite' => 'true', 'rewrite_slug' => '',
            'rewrite_withfront' => '1', 'rewrite_hierarchical' => '0', 'show_admin_column' => 'false', 'show_in_rest' => 'true',
            'show_tagcloud' => 'false', 'sort' => 'false', 'show_in_quick_edit' => '', 'rest_base' => '', 'rest_controller_class' => '',
            'rest_namespace' => '', 'labels' => [], 'meta_box_cb' => '', 'default_term' => '', 'object_types' => $types,
        ];
        update_option('cptui_taxonomies', $all);
    }

    /** A Meta Box definition post as its editor saves it. */
    private function mb_definition(string $post_type, array $settings, string $status = 'publish'): int
    {
        return self::factory()->post->create([
            'post_type'    => $post_type,
            'post_status'  => $status,
            'post_title'   => (string) ($settings['labels']['name'] ?? $settings['slug']),
            'post_name'    => (string) $settings['slug'],
            'post_content' => wp_slash((string) wp_json_encode($settings)),
        ]);
    }

    private function register_code_type(string $slug): void
    {
        register_post_type($slug, [ 'public' => true, 'label' => 'Code type' ]);
        $this->registered[] = $slug;
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

    public function test_presence_defaults_to_each_plugins_own_marker(): void
    {
        remove_all_filters('wpmcp_cptui_active');
        remove_all_filters('wpmcp_mb_cpt_active');
        $this->assertSame(defined('CPTUI_VERSION'), Cpt_Builder_Pack::cptui_active());
        $this->assertSame(function_exists('mb_cpt_load'), Cpt_Builder_Pack::metabox_active());
    }

    public function test_while_neither_plugin_is_active_a_call_answers_plugin_missing_naming_both(): void
    {
        remove_all_filters('wpmcp_cptui_active');
        remove_all_filters('wpmcp_mb_cpt_active');
        add_filter('wpmcp_cptui_active', '__return_false');
        add_filter('wpmcp_mb_cpt_active', '__return_false');
        $out = $this->read('cpt-list-types');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Custom Post Type UI or MB Custom Post Types', $out['error']['message']);
        $this->assertSame(admin_url('plugins.php'), $out['error']['data']['plugins_url'] ?? null);
    }

    public function test_writes_are_off_until_the_site_opts_in(): void
    {
        $this->cptui_post_type('book');
        remove_all_filters('wpmcp_enable_cpt_builder_write');
        foreach ([
            [ 'cpt-create-post-type', [ 'slug' => 'movie', 'plugin' => 'cptui' ] ],
            [ 'cpt-update-post-type', [ 'slug' => 'book', 'label' => 'Novels' ] ],
            [ 'cpt-create-taxonomy', [ 'slug' => 'genre', 'plugin' => 'cptui' ] ],
            [ 'cpt-update-taxonomy', [ 'slug' => 'genre', 'label' => 'x' ] ],
        ] as [ $op, $args ]) {
            $out = $this->write($op, $args);
            $this->assertSame('opt_in_required', $out['error']['code'] ?? null, $op);
            $this->assertStringContainsString('wpmcp_enable_cpt_builder_write', $out['error']['message']);
        }
        $this->assertSame('Books', get_option('cptui_post_types')['book']['label']);
        $this->assertArrayNotHasKey('movie', get_option('cptui_post_types'));
    }

    public function test_only_administrators_may_read_or_write(): void
    {
        $this->cptui_post_type('book');
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $this->assertSame('operation_denied', $this->read('cpt-list-types')['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->read('cpt-get-type', [ 'kind' => 'post_type', 'slug' => 'book' ])['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->write('cpt-update-post-type', [ 'slug' => 'book', 'label' => 'x' ])['error']['code'] ?? null);
        $this->assertSame('operation_denied', $this->write('cpt-create-post-type', [ 'slug' => 'movie', 'plugin' => 'cptui' ])['error']['code'] ?? null);
        $this->assertSame('Books', get_option('cptui_post_types')['book']['label']);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_types_names_the_plugin_that_owns_each_type(): void
    {
        $this->cptui_post_type('book');
        $this->cptui_taxonomy('genre', [ 'book' ]);
        $event = $this->mb_definition('mb-post-type', [ 'slug' => 'event', 'labels' => [ 'name' => 'Events', 'singular_name' => 'Event' ], 'public' => true ]);
        $this->mb_definition('mb-post-type', [ 'slug' => 'venue', 'labels' => [ 'name' => 'Venues', 'singular_name' => 'Venue' ] ], 'draft');
        $this->mb_definition('mb-taxonomy', [ 'slug' => 'city', 'labels' => [ 'name' => 'Cities', 'singular_name' => 'City' ], 'types' => [ 'event' ] ]);
        $this->register_code_type('codetype');

        $out   = $this->ok($this->read('cpt-list-types'));
        $types = array_column($out['post_types'], null, 'slug');
        $taxes = array_column($out['taxonomies'], null, 'slug');

        $this->assertSame('cptui', $types['book']['plugin']);
        $this->assertSame('Books', $types['book']['label']);
        $this->assertSame('metabox', $types['event']['plugin']);
        $this->assertSame($event, $types['event']['definition_id']);
        $this->assertTrue($types['event']['active']);
        $this->assertSame('metabox', $types['venue']['plugin']);
        $this->assertFalse($types['venue']['active'], 'a draft definition is not registered by Meta Box');
        $this->assertSame('code', $types['codetype']['plugin'], 'a type registered in code names no builder');
        $this->assertTrue($types['codetype']['registered']);
        $this->assertArrayNotHasKey('post', $types, 'built-in types are left out');
        $this->assertArrayNotHasKey('mb-post-type', $types, 'the builders\' own storage types are left out');
        $this->assertSame('cptui', $taxes['genre']['plugin']);
        $this->assertSame([ 'book' ], $taxes['genre']['post_types']);
        $this->assertSame('metabox', $taxes['city']['plugin']);
        $this->assertSame([ 'event' ], $taxes['city']['post_types']);

        $only = $this->ok($this->read('cpt-list-types', [ 'kind' => 'taxonomy' ]));
        $this->assertArrayNotHasKey('post_types', $only);
    }

    public function test_get_type_returns_the_owning_plugins_stored_config(): void
    {
        $this->cptui_post_type('book');
        $id = $this->mb_definition('mb-taxonomy', [ 'slug' => 'city', 'labels' => [ 'name' => 'Cities', 'singular_name' => 'City' ], 'types' => [ 'post' ], 'hierarchical' => true ]);

        $book = $this->ok($this->read('cpt-get-type', [ 'kind' => 'post_type', 'slug' => 'book' ]));
        $this->assertSame('cptui', $book['plugin']);
        $this->assertSame('true', $book['config']['public']);
        $this->assertSame([ 'title', 'editor', 'thumbnail' ], $book['config']['supports']);

        $city = $this->ok($this->read('cpt-get-type', [ 'kind' => 'taxonomy', 'slug' => 'city' ]));
        $this->assertSame('metabox', $city['plugin']);
        $this->assertSame($id, $city['definition_id']);
        $this->assertTrue($city['config']['hierarchical']);

        $this->assertSame('type_not_found', $this->read('cpt-get-type', [ 'kind' => 'post_type', 'slug' => 'nosuch' ])['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // Post type writes
    // ---------------------------------------------------------------

    public function test_create_post_type_in_cptui_stores_its_shape_and_rolls_back(): void
    {
        $this->cptui_post_type('book');
        $before = get_option('cptui_post_types');

        $out = $this->ok($this->write('cpt-create-post-type', [
            'plugin' => 'cptui', 'slug' => 'movie', 'label' => 'Movies', 'singular_label' => 'Movie',
            'has_archive' => true, 'supports' => [ 'title', 'excerpt' ], 'menu_icon' => 'dashicons-video-alt',
        ]));
        $stored = get_option('cptui_post_types')['movie'];
        $this->assertSame('movie', $stored['name']);
        $this->assertSame('Movies', $stored['label']);
        $this->assertSame('Movie', $stored['singular_label']);
        $this->assertSame('true', $stored['has_archive']);
        $this->assertSame('true', $stored['public']);
        $this->assertSame([ 'title', 'excerpt' ], $stored['supports']);
        $this->assertSame('dashicons-video-alt', $stored['menu_icon']);
        $this->assertNull($stored['register_meta_box_cb']);
        $this->assertSame('true', get_transient('cptui_flush_rewrite_rules'), 'CPT UI flushes rewrite rules on the next admin load');
        $this->assertSame('cptui', $out['result']['plugin']);

        $this->undo($out);
        $this->assertSame($before, get_option('cptui_post_types'));
    }

    public function test_create_post_type_in_cptui_with_no_option_yet_rolls_back_to_none(): void
    {
        $out = $this->ok($this->write('cpt-create-post-type', [ 'plugin' => 'cptui', 'slug' => 'movie' ]));
        $this->assertSame('Movie', get_option('cptui_post_types')['movie']['singular_label']);
        $this->undo($out);
        $this->assertFalse(get_option('cptui_post_types'));
    }

    public function test_create_post_type_in_meta_box_publishes_a_definition_and_rollback_trashes_it(): void
    {
        $out = $this->ok($this->write('cpt-create-post-type', [
            'plugin' => 'metabox', 'slug' => 'event', 'label' => 'Events', 'singular_label' => 'Event',
            'hierarchical' => true, 'show_in_rest' => false, 'rewrite_slug' => 'events',
        ]));
        $id   = (int) $out['result']['definition_id'];
        $post = get_post($id);
        $this->assertSame('mb-post-type', $post->post_type);
        $this->assertSame('publish', $post->post_status);
        $this->assertSame('event', $post->post_name);
        $this->assertSame('Events', $post->post_title);
        $settings = json_decode($post->post_content, true);
        $this->assertSame('event', $settings['slug']);
        $this->assertSame('Events', $settings['labels']['name']);
        $this->assertSame('Event', $settings['labels']['singular_name']);
        $this->assertTrue($settings['hierarchical']);
        $this->assertFalse($settings['show_in_rest']);
        $this->assertSame('events', $settings['rewrite']['slug']);

        $this->undo($out);
        $this->assertSame('trash', get_post_status($id));
    }

    public function test_create_post_type_refuses_taken_reserved_and_malformed_slugs(): void
    {
        $this->cptui_post_type('book');
        $this->mb_definition('mb-post-type', [ 'slug' => 'venue', 'labels' => [ 'name' => 'Venues' ] ], 'draft');
        $this->register_code_type('codetype');
        foreach ([
            'post'      => 'slug_taken',
            'book'      => 'slug_taken',
            'venue'     => 'slug_taken',
            'codetype'  => 'slug_taken',
            'category'  => 'slug_taken',
            'author'    => 'slug_reserved',
            'Bad Slug'  => 'invalid_slug',
            str_repeat('a', 21) => 'invalid_slug',
        ] as $slug => $code) {
            $out = $this->write('cpt-create-post-type', [ 'plugin' => 'cptui', 'slug' => (string) $slug ]);
            $this->assertSame($code, $out['error']['code'] ?? null, $slug . ' ' . wp_json_encode($out));
        }
        $this->assertSame([ 'book' ], array_keys(get_option('cptui_post_types')));
    }

    public function test_create_needs_a_plugin_when_both_are_active_and_refuses_a_missing_one(): void
    {
        $out = $this->write('cpt-create-post-type', [ 'slug' => 'movie' ]);
        $this->assertSame('plugin_required', $out['error']['code'] ?? null);

        remove_all_filters('wpmcp_mb_cpt_active');
        add_filter('wpmcp_mb_cpt_active', '__return_false');
        $out = $this->write('cpt-create-post-type', [ 'slug' => 'movie', 'plugin' => 'metabox' ]);
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, 'the plugin files are not installed here');
        $this->assertStringContainsString('Install and activate MB Custom Post Types', $out['error']['message']);

        $this->undo($this->ok($this->write('cpt-create-post-type', [ 'slug' => 'movie' ])));
        $this->assertFalse(get_option('cptui_post_types'), 'with one plugin active it is the one used');
    }

    public function test_create_refuses_an_unknown_taxonomy(): void
    {
        $out = $this->write('cpt-create-post-type', [ 'plugin' => 'cptui', 'slug' => 'movie', 'taxonomies' => [ 'category', 'nosuch' ] ]);
        $this->assertSame('unknown_taxonomy', $out['error']['code'] ?? null);
        $this->assertSame([ 'nosuch' ], $out['error']['data']['unknown'] ?? null);
    }

    public function test_update_post_type_in_cptui_changes_only_the_given_settings_and_rolls_back_exactly(): void
    {
        $this->cptui_post_type('book', [ 'menu_icon' => 'dashicons-book', 'register_meta_box_cb' => 'my_cb' ]);
        $before = get_option('cptui_post_types');

        $out    = $this->ok($this->write('cpt-update-post-type', [ 'slug' => 'book', 'label' => 'Novels', 'public' => false, 'taxonomies' => [ 'category' ] ]));
        $stored = get_option('cptui_post_types')['book'];
        $this->assertSame('Novels', $stored['label']);
        $this->assertSame('false', $stored['public']);
        $this->assertSame([ 'category' ], $stored['taxonomies']);
        $this->assertSame('Book', $stored['singular_label']);
        $this->assertSame('dashicons-book', $stored['menu_icon']);
        $this->assertSame('my_cb', $stored['register_meta_box_cb'], 'a callback the site set is never touched');

        $this->undo($out);
        $this->assertSame($before, get_option('cptui_post_types'));
    }

    public function test_update_post_type_in_meta_box_merges_the_settings_and_rolls_back_exactly(): void
    {
        $id     = $this->mb_definition('mb-post-type', [ 'slug' => 'event', 'labels' => [ 'name' => 'Events', 'singular_name' => 'Event', 'menu_name' => 'Events' ], 'public' => true, 'supports' => [ 'title' ], 'archive_slug' => 'all-events' ]);
        $before = get_post($id);

        $out      = $this->ok($this->write('cpt-update-post-type', [ 'slug' => 'event', 'label' => 'Happenings', 'supports' => [ 'title', 'editor' ], 'has_archive' => true ]));
        $settings = json_decode(get_post($id)->post_content, true);
        $this->assertSame('Happenings', $settings['labels']['name']);
        $this->assertSame('Event', $settings['labels']['singular_name']);
        $this->assertSame([ 'title', 'editor' ], $settings['supports']);
        $this->assertTrue($settings['has_archive']);
        $this->assertSame('all-events', $settings['archive_slug'], 'settings the call does not name are kept');
        $this->assertSame('Happenings', get_post($id)->post_title);

        $this->undo($out);
        $this->assertSame($before->post_content, get_post($id)->post_content);
        $this->assertSame('Events', get_post($id)->post_title);
    }

    public function test_update_refuses_a_type_registered_in_code_and_an_unknown_one(): void
    {
        $this->register_code_type('codetype');
        $out = $this->write('cpt-update-post-type', [ 'slug' => 'codetype', 'label' => 'x' ]);
        $this->assertSame('not_builder_owned', $out['error']['code'] ?? null);
        $this->assertSame('type_not_found', $this->write('cpt-update-post-type', [ 'slug' => 'nosuch', 'label' => 'x' ])['error']['code'] ?? null);
        $this->assertSame('type_not_found', $this->write('cpt-update-post-type', [ 'slug' => 'post', 'label' => 'x' ])['error']['code'] ?? null);
    }

    // ---------------------------------------------------------------
    // Taxonomy writes
    // ---------------------------------------------------------------

    public function test_create_and_update_taxonomy_in_cptui_roll_back(): void
    {
        $this->cptui_post_type('book');
        $out    = $this->ok($this->write('cpt-create-taxonomy', [ 'plugin' => 'cptui', 'slug' => 'genre', 'label' => 'Genres', 'singular_label' => 'Genre', 'hierarchical' => true, 'post_types' => [ 'book', 'post' ] ]));
        $stored = get_option('cptui_taxonomies')['genre'];
        $this->assertSame('genre', $stored['name']);
        $this->assertSame('true', $stored['hierarchical']);
        $this->assertSame([ 'book', 'post' ], $stored['object_types']);
        $this->assertSame('', $stored['meta_box_cb']);

        $mid = get_option('cptui_taxonomies');
        $upd = $this->ok($this->write('cpt-update-taxonomy', [ 'slug' => 'genre', 'show_admin_column' => true, 'post_types' => [ 'book' ] ]));
        $this->assertSame('true', get_option('cptui_taxonomies')['genre']['show_admin_column']);
        $this->assertSame([ 'book' ], get_option('cptui_taxonomies')['genre']['object_types']);

        $this->undo($upd);
        $this->assertSame($mid, get_option('cptui_taxonomies'));
        $this->undo($out);
        $this->assertFalse(get_option('cptui_taxonomies'));
    }

    public function test_create_and_update_taxonomy_in_meta_box_roll_back(): void
    {
        $out  = $this->ok($this->write('cpt-create-taxonomy', [ 'plugin' => 'metabox', 'slug' => 'city', 'label' => 'Cities', 'singular_label' => 'City', 'post_types' => [ 'post' ] ]));
        $id   = (int) $out['result']['definition_id'];
        $post = get_post($id);
        $this->assertSame('mb-taxonomy', $post->post_type);
        $settings = json_decode($post->post_content, true);
        $this->assertSame('city', $settings['slug']);
        $this->assertSame([ 'post' ], $settings['types']);
        $this->assertSame('Cities', $settings['labels']['name']);

        $before = get_post($id)->post_content;
        $upd    = $this->ok($this->write('cpt-update-taxonomy', [ 'slug' => 'city', 'hierarchical' => true ]));
        $this->assertTrue(json_decode(get_post($id)->post_content, true)['hierarchical']);
        $this->undo($upd);
        $this->assertSame($before, get_post($id)->post_content);

        $this->undo($out);
        $this->assertSame('trash', get_post_status($id));
    }

    public function test_create_taxonomy_refuses_an_unknown_post_type_and_a_taken_slug(): void
    {
        $out = $this->write('cpt-create-taxonomy', [ 'plugin' => 'cptui', 'slug' => 'genre', 'post_types' => [ 'nosuch' ] ]);
        $this->assertSame('unknown_post_type', $out['error']['code'] ?? null);
        $this->assertSame('slug_taken', $this->write('cpt-create-taxonomy', [ 'plugin' => 'cptui', 'slug' => 'category' ])['error']['code'] ?? null);
        $this->assertFalse(get_option('cptui_taxonomies'));
    }
}
