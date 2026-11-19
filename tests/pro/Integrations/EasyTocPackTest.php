<?php

namespace WPMCP\Tests\Pro\Integrations;

use WPMCP\Integrations\Easy_TOC_Pack;
use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;

/**
 * Issue #904: Easy Table of Contents global settings and per-post
 * overrides as paid-tier ops on the plugin-data pair.
 *
 * The plugin is not loaded in the shared test core; presence is driven
 * through wpmcp_easy_toc_active. The 'ez-toc-settings' option is seeded in
 * the shape Easy Table of Contents 2.0.90 stores (ezTOC_Option::getDefaults()
 * written on admin_init), and the per-post flags are the post meta its
 * metabox saves: _ez-toc-disabled and _ez-toc-insert, '1' when ticked and
 * '' when not (update_post_meta() with true or false).
 */
class EasyTocPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'easy-toc-get-settings'    => [ 'read', 'manage_options' ],
        'easy-toc-update-settings' => [ 'write', 'manage_options' ],
        'easy-toc-list-posts'      => [ 'read', 'edit_posts' ],
        'easy-toc-set-posts'       => [ 'write', 'edit_posts' ],
    ];

    /** @var int[] */
    private array $posts = [];

    private int $page = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        add_filter('wpmcp_easy_toc_active', '__return_true');
        add_filter(Easy_TOC_Pack::ENABLE_FILTER, '__return_true');
        update_option('ez-toc-settings', [
            'fragment_prefix'        => 'i',
            'position'               => 'before',
            'start'                  => 2,
            'word_count_limit'       => 0,
            'show_heading_text'      => true,
            'heading_text'           => 'Table of Contents',
            'enabled_post_types'     => [ 'post', 'page' ],
            'auto_insert_post_types' => [ 'post' ],
            'heading_levels'         => [ '1', '2', '3', '4', '5', '6' ],
            'exclude'                => '',
            'restrict_path'          => '',
            'include_homepage'       => false,
            'include_category'       => false,
            'include_tag'            => false,
            'theme'                  => 'grey',
        ]);

        $news = self::factory()->category->create([ 'slug' => 'news' ]);
        for ($i = 0; $i < 3; $i++) {
            $this->posts[] = self::factory()->post->create([ 'post_title' => 'Post ' . $i, 'post_category' => $i < 2 ? [ $news ] : [] ]);
        }
        $this->page = self::factory()->post->create([ 'post_type' => 'page', 'post_title' => 'A page' ]);
        update_post_meta($this->posts[2], '_ez-toc-disabled', true);
    }

    protected function tearDown(): void
    {
        foreach ([ 'wpmcp_easy_toc_active', Easy_TOC_Pack::ENABLE_FILTER ] as $hook) {
            remove_all_filters($hook);
        }
        delete_option('ez-toc-settings');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_write([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out;
    }

    public function test_the_catalog_lists_every_op_with_its_capability(): void
    {
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], null, 'name');
        foreach (self::OPS as $name => [ $mode, $cap ]) {
            $this->assertArrayHasKey($name, $ops);
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], $name);
            $this->assertSame($cap, $ops[ $name ]['capability'], $name);
        }
    }

    public function test_without_pro_the_ops_are_not_in_the_catalog(): void
    {
        Gate::set_pro_for_tests(false);
        $ops = array_column((new Plugin_Data_Integration())->catalog()['operations'], 'name');
        foreach (array_keys(self::OPS) as $name) {
            $this->assertNotContains($name, $ops);
        }
    }

    public function test_a_missing_plugin_answers_plugin_missing_naming_it(): void
    {
        remove_all_filters('wpmcp_easy_toc_active');
        add_filter('wpmcp_easy_toc_active', '__return_false');
        $out = $this->read('easy-toc-get-settings');
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString('Install and activate Easy Table of Contents', $out['error']['message']);

        $out = $this->write('easy-toc-set-posts', [ 'post_ids' => [ $this->posts[0] ], 'disabled' => true ]);
        $this->assertSame('plugin_missing', $out['error']['code'] ?? null);
        $this->assertSame('', get_post_meta($this->posts[0], '_ez-toc-disabled', true));
    }

    public function test_get_settings_reports_the_placement_settings(): void
    {
        $out = $this->ok($this->read('easy-toc-get-settings'))['result'];

        $this->assertSame([ 'post', 'page' ], $out['settings']['enabled_post_types']);
        $this->assertSame([ 'post' ], $out['settings']['auto_insert_post_types']);
        $this->assertSame('before', $out['settings']['position']);
        $this->assertSame(2, $out['settings']['start']);
        $this->assertSame([ 1, 2, 3, 4, 5, 6 ], $out['settings']['heading_levels']);
        $this->assertSame('', $out['settings']['exclude']);
        $this->assertFalse($out['settings']['include_homepage']);
        $this->assertArrayNotHasKey('theme', $out['settings'], 'only the allowlisted placement settings are returned');
        $this->assertContains('before', $out['positions']);
        $this->assertTrue($out['writes_enabled']);
    }

    public function test_update_settings_is_off_until_the_site_opts_in(): void
    {
        remove_all_filters(Easy_TOC_Pack::ENABLE_FILTER);
        $out = $this->write('easy-toc-update-settings', [ 'start' => 4 ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertStringContainsString(Easy_TOC_Pack::ENABLE_FILTER, $out['error']['message']);
        $this->assertSame(2, get_option('ez-toc-settings')['start']);
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_update_settings_merges_only_the_given_keys_and_rolls_back_exactly(): void
    {
        $before = get_option('ez-toc-settings');

        $out = $this->ok($this->write('easy-toc-update-settings', [
            'auto_insert_post_types' => [ 'post', 'page' ],
            'start'                  => 4,
            'heading_levels'         => [ 2, 3, 3 ],
            'position'               => 'afterpara',
            'exclude'                => 'Related*|FAQ',
        ]));
        $this->assertTrue($out['recoverable']);
        $this->assertSame(4, $out['result']['settings']['start']);
        $this->assertSame([ 2, 3 ], $out['result']['settings']['heading_levels']);

        $stored = get_option('ez-toc-settings');
        $this->assertSame([ 'post', 'page' ], $stored['auto_insert_post_types']);
        $this->assertSame(4, $stored['start']);
        $this->assertSame([ '2', '3' ], $stored['heading_levels'], 'stored as strings, as the settings screen saves them');
        $this->assertSame('afterpara', $stored['position']);
        $this->assertSame('Related*|FAQ', $stored['exclude']);
        $this->assertSame('grey', $stored['theme'], 'other settings are untouched');
        $this->assertSame('i', $stored['fragment_prefix']);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame($before, get_option('ez-toc-settings'));
    }

    public function test_update_settings_refuses_bad_values_and_no_change(): void
    {
        $cases = [
            'unknown_post_type' => [ 'enabled_post_types' => [ 'post', 'nope_type' ] ],
            'no_change'         => [ 'start' => 2 ],
        ];
        foreach ($cases as $code => $args) {
            $out = $this->write('easy-toc-update-settings', $args);
            $this->assertSame($code, $out['error']['code'] ?? null, $code . ': ' . wp_json_encode($out));
        }
        foreach ([ [ 'start' => 11 ], [ 'position' => 'middle' ], [ 'heading_levels' => [ 7 ] ], [] ] as $args) {
            $out = $this->write('easy-toc-update-settings', $args);
            $this->assertArrayHasKey('error', $out, (string) wp_json_encode($args));
        }
        $this->assertSame(2, get_option('ez-toc-settings')['start']);
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_update_settings_refuses_before_the_plugin_saved_its_defaults(): void
    {
        delete_option('ez-toc-settings');
        $out = $this->write('easy-toc-update-settings', [ 'start' => 3 ]);
        $this->assertSame('settings_not_initialised', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertFalse(get_option('ez-toc-settings'));
    }

    public function test_list_posts_reports_flags_and_whether_the_toc_shows(): void
    {
        $out = $this->ok($this->read('easy-toc-list-posts', [ 'post_type' => 'post' ]))['result'];
        $this->assertSame(3, $out['total']);
        $rows = array_column($out['posts'], null, 'id');
        $this->assertFalse($rows[ $this->posts[0] ]['disabled']);
        $this->assertTrue($rows[ $this->posts[0] ]['toc_shown'], 'post is an auto-insert type');
        $this->assertTrue($rows[ $this->posts[2] ]['disabled']);
        $this->assertFalse($rows[ $this->posts[2] ]['toc_shown']);

        $out = $this->ok($this->read('easy-toc-list-posts', [ 'post_type' => 'page' ]))['result'];
        $this->assertFalse($out['posts'][0]['toc_shown'], 'page is enabled but not auto-insert');

        $out = $this->ok($this->read('easy-toc-list-posts', [ 'category' => 'news' ]))['result'];
        $this->assertEqualsCanonicalizing([ $this->posts[0], $this->posts[1] ], array_column($out['posts'], 'id'));
    }

    public function test_list_posts_hides_other_users_drafts_from_a_contributor(): void
    {
        $draft = self::factory()->post->create([ 'post_status' => 'draft', 'post_author' => self::factory()->user->create() ]);
        wp_set_current_user(self::factory()->user->create([ 'role' => 'contributor' ]));
        $out = $this->ok($this->read('easy-toc-list-posts', [ 'post_type' => 'post' ]))['result'];
        $this->assertNotContains($draft, array_column($out['posts'], 'id'));
    }

    public function test_set_posts_by_category_writes_the_flag_and_one_session_undoes_it(): void
    {
        $out = $this->ok($this->write('easy-toc-set-posts', [ 'category' => 'news', 'disabled' => true ]));
        $this->assertTrue($out['recoverable']);
        $this->assertEqualsCanonicalizing([ $this->posts[0], $this->posts[1] ], $out['result']['changed']);
        $this->assertCount(2, $out['operation_ids']);
        $this->assertSame('1', get_post_meta($this->posts[0], '_ez-toc-disabled', true));
        $this->assertSame('1', get_post_meta($this->posts[1], '_ez-toc-disabled', true));
        $this->assertSame('', get_post_meta($this->page, '_ez-toc-disabled', true));

        $this->assertSame(2, Rollback_Service::restore_session($out['session_id']));
        $this->assertSame([], get_post_meta($this->posts[0], '_ez-toc-disabled'));
        $this->assertSame([], get_post_meta($this->posts[1], '_ez-toc-disabled'));
    }

    public function test_set_posts_by_ids_skips_posts_already_set(): void
    {
        $out = $this->ok($this->write('easy-toc-set-posts', [ 'post_ids' => [ $this->posts[0], $this->posts[2] ], 'disabled' => true, 'insert' => false ]));
        $this->assertSame([ $this->posts[0] ], $out['result']['changed']);
        $this->assertSame('', get_post_meta($this->posts[0], '_ez-toc-insert', true));
        $this->assertSame('1', get_post_meta($this->posts[0], '_ez-toc-disabled', true));

        $out = $this->write('easy-toc-set-posts', [ 'post_ids' => [ $this->posts[2] ], 'disabled' => true ]);
        $this->assertSame('no_change', $out['error']['code'] ?? null, (string) wp_json_encode($out));
    }

    public function test_set_posts_refuses_bad_selectors_and_is_off_by_default(): void
    {
        $cases = [
            'invalid_args' => [ 'post_ids' => [ $this->posts[0] ] ],
            'no_match'     => [ 'category' => 'no-such-category', 'disabled' => true ],
        ];
        foreach ($cases as $code => $args) {
            $out = $this->write('easy-toc-set-posts', $args);
            $this->assertSame($code, $out['error']['code'] ?? null, $code . ': ' . wp_json_encode($out));
        }
        $out = $this->write('easy-toc-set-posts', [ 'post_ids' => [ $this->posts[0] ], 'post_type' => 'post', 'disabled' => true ]);
        $this->assertSame('invalid_args', $out['error']['code'] ?? null);

        remove_all_filters(Easy_TOC_Pack::ENABLE_FILTER);
        $out = $this->write('easy-toc-set-posts', [ 'post_ids' => [ $this->posts[0] ], 'disabled' => true ]);
        $this->assertSame('opt_in_required', $out['error']['code'] ?? null);
        $this->assertSame('', get_post_meta($this->posts[0], '_ez-toc-disabled', true));
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_set_posts_refuses_the_batch_when_a_post_is_not_editable(): void
    {
        $author = self::factory()->user->create([ 'role' => 'author' ]);
        $own    = self::factory()->post->create([ 'post_author' => $author ]);
        wp_set_current_user($author);
        $out = $this->write('easy-toc-set-posts', [ 'post_type' => 'post', 'disabled' => true ]);
        $this->assertSame('not_editable', $out['error']['code'] ?? null, (string) wp_json_encode($out));
        $this->assertSame('', get_post_meta($own, '_ez-toc-disabled', true));
    }

    public function test_an_editor_cannot_change_global_settings(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        $out = $this->write('easy-toc-update-settings', [ 'start' => 5 ]);
        $this->assertArrayHasKey('error', $out);
        $this->assertSame(2, get_option('ez-toc-settings')['start']);
    }
}
