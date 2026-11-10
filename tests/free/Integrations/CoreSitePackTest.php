<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Core_Site_Pack;
use WPMCP\Integrations\Theme_Integration;
use WPMCP\MCP\Tool_Search;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

/**
 * WordPress core site identity, homepage and permalink settings (issues #713
 * and #717) as free ops on the theme dispatcher pair, so the two features add
 * no top-level tools.
 *
 * Every write is snapshotted on the option(s) it changes (the theme_mods
 * option for the logo, site_icon, the show_on_front + page_on_front pair as
 * one option_set, page_for_posts, and the permalink structure with both
 * bases as one option_set), so rollback-operation puts the setting back
 * exactly. Writes are off until the site opts in through the
 * wpmcp_enable_core_site_write filter; the refusal names it.
 */
class CoreSitePackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'get-site-identity'       => 'read',
        'get-permalink-structure' => 'read',
        'test-url'                => 'read',
        'set-site-logo'           => 'write',
        'set-site-icon'           => 'write',
        'set-front-page'          => 'write',
        'set-posts-page'          => 'write',
        'set-permalink-structure' => 'destructive',
        'flush-rewrite-rules'     => 'write',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    protected function tearDown(): void
    {
        remove_all_filters(Core_Site_Pack::ENABLE_FILTER);
        Gate::set_pro_for_tests(null);
        $this->set_permalink_structure('');
        delete_option('category_base');
        delete_option('tag_base');
        // The permalink writes re-point the global category and post_tag
        // permastructs, which plugin test runs do not reset between tests.
        Core_Site_Pack::after_options_restored([ 'category_base' ]);
        update_option('show_on_front', 'posts');
        update_option('page_on_front', 0);
        update_option('page_for_posts', 0);
        delete_option('site_icon');
        remove_theme_mod('custom_logo');
        parent::tearDown();
    }

    private function enable_writes(): void
    {
        add_filter(Core_Site_Pack::ENABLE_FILTER, '__return_true');
    }

    private function catalog(): array
    {
        return array_column((new Theme_Integration())->catalog()['operations'], null, 'name');
    }

    private function read(string $op, array $args = []): array
    {
        return (new Theme_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function write(string $op, array $args, bool $confirm = false): array
    {
        $call = [ 'operation' => $op, 'args' => $args ];
        if ($confirm) {
            $call['confirm'] = true;
        }
        return (new Theme_Integration())->handle_write($call);
    }

    private function error_code(array $out): string
    {
        return (string) ($out['error']['code'] ?? '');
    }

    private function rollback(array $out): void
    {
        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);
    }

    private function image(int $width = 800, int $height = 800): int
    {
        $id = self::factory()->post->create([ 'post_type' => 'attachment', 'post_mime_type' => 'image/png' ]);
        update_post_meta($id, '_wp_attached_file', '2026/11/image-' . $id . '.png');
        wp_update_attachment_metadata($id, [ 'width' => $width, 'height' => $height, 'file' => '2026/11/image-' . $id . '.png' ]);
        return $id;
    }

    private function page(string $title, string $status = 'publish'): int
    {
        return self::factory()->post->create([ 'post_type' => 'page', 'post_title' => $title, 'post_status' => $status ]);
    }

    // ---------------------------------------------------------------
    // Catalog, opt-in and search
    // ---------------------------------------------------------------

    public function test_every_op_is_a_free_op_on_the_theme_pair_with_its_mode(): void
    {
        Gate::set_pro_for_tests(false);
        $ops = $this->catalog();

        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops, "{$name} must be on the theme pair without a license");
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], "{$name}: WordPress core is always present");
        }
        $this->assertTrue($ops['set-permalink-structure']['requires_confirm']);
        foreach ([ 'get-permalink-structure', 'test-url', 'set-permalink-structure', 'flush-rewrite-rules', 'set-site-icon', 'set-front-page', 'set-posts-page' ] as $name) {
            $this->assertSame('manage_options', $ops[ $name ]['capability'], "{$name} takes what its wp-admin screen takes");
        }
        $this->assertSame('edit_theme_options', $ops['set-site-logo']['capability'], 'the logo is a theme mod, the Customizer\'s own capability');
    }

    public function test_writes_are_off_until_the_site_opts_in_and_the_refusal_names_the_filter(): void
    {
        $ops = $this->catalog();
        foreach (self::OPS as $name => $mode) {
            $this->assertSame('read' === $mode, $ops[ $name ]['enabled'], $name);
        }

        $out = $this->write('set-site-icon', [ 'attachment_id' => $this->image() ]);
        $this->assertSame('operation_disabled', $this->error_code($out));
        $this->assertStringContainsString(Core_Site_Pack::ENABLE_FILTER, $out['error']['message']);
        $this->assertSame(0, (int) get_option('site_icon'), 'a refused write changes nothing');
        $this->assertSame([], Snapshot_Store::list_by_session('default'), 'a refused write snapshots nothing');

        $this->enable_writes();
        foreach (array_keys(self::OPS) as $name) {
            $this->assertTrue($this->catalog()[ $name ]['enabled'], $name);
        }
    }

    public function test_tool_search_finds_the_ops_inside_the_theme_pair(): void
    {
        [ $read, $write ] = (new Theme_Integration())->abilities();

        $this->assertContains('get-permalink-structure', Tool_Search::match($read, 'permalink'));
        $this->assertContains('set-permalink-structure', Tool_Search::match($write, 'permalink'));
        $this->assertContains('set-site-logo', Tool_Search::match($write, 'logo'));
        $this->assertContains('get-site-identity', Tool_Search::match($read, 'identity'));
    }

    // ---------------------------------------------------------------
    // Site identity reads
    // ---------------------------------------------------------------

    public function test_get_site_identity_reports_name_tagline_logo_icon_and_homepage(): void
    {
        update_option('blogname', 'Acme');
        update_option('blogdescription', 'Rockets, quickly');
        $logo = $this->image();
        $icon = $this->image(512, 512);
        set_theme_mod('custom_logo', $logo);
        update_option('site_icon', $icon);
        $front = $this->page('Home');
        $blog  = $this->page('Blog');
        update_option('show_on_front', 'page');
        update_option('page_on_front', $front);
        update_option('page_for_posts', $blog);

        $out = $this->read('get-site-identity')['result'];

        $this->assertSame('Acme', $out['name']);
        $this->assertSame('Rockets, quickly', $out['tagline']);
        $this->assertSame(home_url('/'), $out['home_url']);
        $this->assertSame($logo, $out['logo']['attachment_id']);
        $this->assertSame($icon, $out['icon']['attachment_id']);
        $this->assertSame('page', $out['homepage']['show_on_front']);
        $this->assertSame($front, $out['homepage']['front_page']['id']);
        $this->assertSame('Home', $out['homepage']['front_page']['title']);
        $this->assertSame($blog, $out['homepage']['posts_page']['id']);
        $this->assertIsBool($out['theme_supports_custom_logo']);
    }

    public function test_get_site_identity_reports_nulls_when_nothing_is_set(): void
    {
        $out = $this->read('get-site-identity')['result'];

        $this->assertNull($out['logo']);
        $this->assertNull($out['icon']);
        $this->assertSame('posts', $out['homepage']['show_on_front']);
        $this->assertNull($out['homepage']['front_page']);
        $this->assertNull($out['homepage']['posts_page']);
    }

    // ---------------------------------------------------------------
    // Logo and icon
    // ---------------------------------------------------------------

    public function test_set_site_logo_refuses_a_missing_or_non_image_attachment(): void
    {
        $this->enable_writes();

        $this->assertSame('attachment_not_found', $this->error_code($this->write('set-site-logo', [ 'attachment_id' => 999999 ])));

        $pdf = self::factory()->post->create([ 'post_type' => 'attachment', 'post_mime_type' => 'application/pdf' ]);
        update_post_meta($pdf, '_wp_attached_file', '2026/11/brochure.pdf');
        $this->assertSame('not_an_image', $this->error_code($this->write('set-site-logo', [ 'attachment_id' => $pdf ])));

        $this->assertFalse(get_theme_mod('custom_logo'));
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_set_site_logo_is_snapshotted_on_the_theme_mods_option_and_undone(): void
    {
        $this->enable_writes();
        $old = $this->image();
        $new = $this->image();
        set_theme_mod('custom_logo', $old);

        $out = $this->write('set-site-logo', [ 'attachment_id' => $new ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['recoverable']);
        $this->assertSame($new, $out['result']['custom_logo']);
        $this->assertSame($new, get_theme_mod('custom_logo'));
        $snapshot = Snapshot_Store::get_by_operation($out['operation_id'])['snapshot'];
        $this->assertSame('option_set', $snapshot['object_type']);
        $this->assertSame(Snapshot::option_set_id([ 'theme_mods_' . get_stylesheet(), 'site_logo' ]), $snapshot['object_id'], 'the site_logo option the Site Logo block reads is part of the undo point');

        $this->rollback($out);
        $this->assertSame($old, get_theme_mod('custom_logo'));
    }

    public function test_set_site_logo_null_removes_the_logo_and_the_same_value_is_a_no_op(): void
    {
        $this->enable_writes();
        $logo = $this->image();
        set_theme_mod('custom_logo', $logo);

        $this->assertSame('no_change', $this->error_code($this->write('set-site-logo', [ 'attachment_id' => $logo ])));

        $out = $this->write('set-site-logo', [ 'attachment_id' => null ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertNull($out['result']['custom_logo']);
        $this->assertFalse(get_theme_mod('custom_logo'));

        $this->rollback($out);
        $this->assertSame($logo, get_theme_mod('custom_logo'));
    }

    public function test_set_site_icon_refuses_an_image_smaller_than_512_pixels(): void
    {
        $this->enable_writes();

        $out = $this->write('set-site-icon', [ 'attachment_id' => $this->image(300, 300) ]);

        $this->assertSame('icon_too_small', $this->error_code($out));
        $this->assertSame(0, (int) get_option('site_icon'));
    }

    public function test_set_site_icon_is_snapshotted_and_undone_to_no_icon(): void
    {
        $this->enable_writes();
        $icon = $this->image(512, 512);

        $out = $this->write('set-site-icon', [ 'attachment_id' => $icon ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['recoverable']);
        $this->assertSame($icon, (int) get_option('site_icon'));
        $this->assertSame($icon, $out['result']['site_icon']);
        $this->assertSame('site_icon', Snapshot_Store::get_by_operation($out['operation_id'])['snapshot']['object_id']);

        $this->rollback($out);
        $this->assertSame(0, (int) get_option('site_icon'));

        $this->assertSame('no_change', $this->error_code($this->write('set-site-icon', [ 'attachment_id' => null ])));
    }

    // ---------------------------------------------------------------
    // Homepage settings
    // ---------------------------------------------------------------

    public function test_set_front_page_refuses_a_missing_unpublished_or_non_page_post_and_the_posts_page(): void
    {
        $this->enable_writes();
        $blog = $this->page('Blog');
        update_option('page_for_posts', $blog);

        $this->assertSame('page_not_found', $this->error_code($this->write('set-front-page', [ 'page_id' => 999999 ])));
        $this->assertSame('not_a_page', $this->error_code($this->write('set-front-page', [ 'page_id' => self::factory()->post->create() ])));
        $this->assertSame('page_not_published', $this->error_code($this->write('set-front-page', [ 'page_id' => $this->page('Draft', 'draft') ])));
        $this->assertSame('same_as_posts_page', $this->error_code($this->write('set-front-page', [ 'page_id' => $blog ])));

        $this->assertSame('posts', get_option('show_on_front'));
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_set_front_page_writes_show_on_front_and_page_on_front_as_one_undo_point(): void
    {
        $this->enable_writes();
        $home = $this->page('Home');

        $out = $this->write('set-front-page', [ 'page_id' => $home ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['recoverable']);
        $this->assertSame('page', get_option('show_on_front'));
        $this->assertSame($home, (int) get_option('page_on_front'));
        $this->assertSame($home, $out['result']['front_page']['id']);
        $snapshot = Snapshot_Store::get_by_operation($out['operation_id'])['snapshot'];
        $this->assertSame('option_set', $snapshot['object_type']);
        $this->assertSame('page_on_front,show_on_front', $snapshot['object_id']);

        $this->rollback($out);
        $this->assertSame('posts', get_option('show_on_front'));
        $this->assertSame(0, (int) get_option('page_on_front'));
    }

    public function test_set_front_page_null_shows_latest_posts_again(): void
    {
        $this->enable_writes();
        $home = $this->page('Home');
        update_option('show_on_front', 'page');
        update_option('page_on_front', $home);

        $this->assertSame('no_change', $this->error_code($this->write('set-front-page', [ 'page_id' => $home ])));

        $out = $this->write('set-front-page', [ 'page_id' => null ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame('posts', get_option('show_on_front'));
        $this->assertNull($out['result']['front_page']);

        $this->rollback($out);
        $this->assertSame('page', get_option('show_on_front'));
        $this->assertSame($home, (int) get_option('page_on_front'));
    }

    public function test_set_posts_page_refuses_the_front_page_and_is_undone(): void
    {
        $this->enable_writes();
        $home = $this->page('Home');
        $blog = $this->page('Blog');
        update_option('show_on_front', 'page');
        update_option('page_on_front', $home);

        $this->assertSame('same_as_front_page', $this->error_code($this->write('set-posts-page', [ 'page_id' => $home ])));

        $out = $this->write('set-posts-page', [ 'page_id' => $blog ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertSame($blog, (int) get_option('page_for_posts'));
        $this->assertSame($blog, $out['result']['posts_page']['id']);
        $this->assertSame('page_for_posts', Snapshot_Store::get_by_operation($out['operation_id'])['snapshot']['object_id']);

        $this->rollback($out);
        $this->assertSame(0, (int) get_option('page_for_posts'));
    }

    public function test_set_posts_page_notes_when_the_front_page_still_shows_posts(): void
    {
        $this->enable_writes();

        $out = $this->write('set-posts-page', [ 'page_id' => $this->page('Blog') ]);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertStringContainsString('set-front-page', $out['result']['note']);
    }

    // ---------------------------------------------------------------
    // Permalinks
    // ---------------------------------------------------------------

    public function test_get_permalink_structure_reports_the_structure_bases_presets_and_an_example(): void
    {
        $this->set_permalink_structure('/%postname%/');
        update_option('category_base', '/topics');
        $post = self::factory()->post->create([ 'post_name' => 'hello-world', 'post_status' => 'publish' ]);

        $out = $this->read('get-permalink-structure')['result'];

        $this->assertSame('/%postname%/', $out['structure']);
        $this->assertSame('topics', $out['category_base'], 'bases read the way core reads them, without the surrounding slashes');
        $this->assertTrue($out['using_permalinks']);
        $this->assertSame(home_url('/hello-world/'), $out['example']);
        $presets = array_column($out['presets'], null, 'key');
        $this->assertTrue($presets['post-name']['current']);
        $this->assertFalse($presets['plain']['current']);
        $this->assertSame(home_url('/?p=' . $post), $presets['plain']['example']);
        $this->assertContains('%postname%', $out['tags']);
        $this->assertGreaterThan(0, $out['rewrite_rules_count']);
    }

    public function test_test_url_names_the_rule_and_query_a_url_resolves_to(): void
    {
        $this->set_permalink_structure('/%postname%/');
        $post = self::factory()->post->create([ 'post_name' => 'hello-world', 'post_status' => 'publish' ]);
        $page = $this->page('About');

        $out = $this->read('test-url', [ 'url' => home_url('/hello-world/') ])['result'];
        $this->assertNotNull($out['matched_rule']);
        $this->assertSame('hello-world', $out['query_vars']['name']);
        $this->assertSame($post, $out['post_id']);
        $this->assertSame('post', $out['resolves_to']);

        $out = $this->read('test-url', [ 'url' => '/about/' ])['result'];
        $this->assertSame($page, $out['post_id']);
        $this->assertSame('about', $out['query_vars']['pagename']);

        $out = $this->read('test-url', [ 'url' => '/' ])['result'];
        $this->assertSame('front page', $out['resolves_to']);

        $out = $this->read('test-url', [ 'url' => '/no/such/thing/' ])['result'];
        $this->assertSame(0, $out['post_id']);
    }

    public function test_test_url_refuses_a_url_on_another_site(): void
    {
        $out = $this->read('test-url', [ 'url' => 'https://elsewhere.example/hello/' ]);

        $this->assertSame('url_not_on_site', $this->error_code($out));
    }

    public function test_set_permalink_structure_refuses_bad_structures_before_asking_for_confirmation(): void
    {
        $this->enable_writes();

        $this->assertSame('structure_tag_required', $this->error_code($this->write('set-permalink-structure', [ 'structure' => '/blog/' ])));
        $this->assertSame('invalid_structure', $this->error_code($this->write('set-permalink-structure', [ 'structure' => '/%postname%/<script>' ])));
        $this->assertSame('unknown_structure_tag', $this->error_code($this->write('set-permalink-structure', [ 'structure' => '/%nope%/' ])));
        $this->assertSame('invalid_base', $this->error_code($this->write('set-permalink-structure', [ 'structure' => '/%postname%/', 'category_base' => 'a b' ])));
        $this->assertSame('no_change', $this->error_code($this->write('set-permalink-structure', [ 'structure' => '' ])));

        $this->assertSame('', (string) get_option('permalink_structure'));
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_set_permalink_structure_asks_for_confirmation_with_a_preview_and_writes_nothing(): void
    {
        $this->enable_writes();
        self::factory()->post->create([ 'post_name' => 'hello-world', 'post_status' => 'publish' ]);

        $out = $this->write('set-permalink-structure', [ 'structure' => '%postname%/' ]);

        $this->assertSame('confirmation_required', $this->error_code($out));
        $this->assertSame('/%postname%/', $out['error']['data']['after']['permalink_structure'], 'the preview shows the normalized structure, with the leading slash core adds');
        $this->assertSame(home_url('/hello-world/'), $out['error']['data']['example_after']);
        $this->assertStringContainsString('inbound links', $out['error']['message']);
        $this->assertSame('', (string) get_option('permalink_structure'));
        $this->assertSame([], Snapshot_Store::list_by_session('default'));
    }

    public function test_set_permalink_structure_is_snapshotted_with_both_bases_flushes_the_rules_and_is_undone(): void
    {
        $this->enable_writes();
        update_option('category_base', '/old-topics');

        $out = $this->write('set-permalink-structure', [ 'structure' => '/%year%/%postname%/', 'category_base' => 'topics', 'tag_base' => 'labels' ], true);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertTrue($out['recoverable']);
        $this->assertSame('/%year%/%postname%/', get_option('permalink_structure'));
        $this->assertSame('topics', get_option('category_base'));
        $this->assertSame('labels', get_option('tag_base'));
        $this->assertSame('soft', $out['result']['rewrite_flushed']);
        $this->assertNotEmpty(get_option('rewrite_rules'), 'the rules were rebuilt for the new structure');
        $this->assertStringContainsString('flush-rewrite-rules', $out['result']['after_rollback']);
        $snapshot = Snapshot_Store::get_by_operation($out['operation_id'])['snapshot'];
        $this->assertSame('option_set', $snapshot['object_type']);
        $this->assertSame('category_base,permalink_structure,tag_base', $snapshot['object_id']);

        $this->rollback($out);
        $this->assertSame('', (string) get_option('permalink_structure'));
        $this->assertSame('old-topics', get_option('category_base'));
        $this->assertSame('', get_option('tag_base'), 'back to the empty base every install starts with');
    }

    /** The stored rewrite rules whose pattern starts with $prefix. @return string[] */
    private static function rules_starting_with(string $prefix): array
    {
        return array_values(array_filter(array_map('strval', array_keys((array) get_option('rewrite_rules', []))), static fn (string $rule): bool => str_starts_with($rule, $prefix)));
    }

    /**
     * Issue #785: the category and post_tag permastructs were registered at
     * init with the bases stored then, so a flush in the same request wrote
     * rules for the old bases. The new bases must route at once, the old
     * ones must stop, and test-url must resolve a term on the new base.
     */
    public function test_new_category_and_tag_bases_are_in_the_rules_without_a_second_flush(): void
    {
        $this->enable_writes();
        $this->set_permalink_structure('/%postname%/');
        // A site with pretty permalinks registered the default bases at init.
        Core_Site_Pack::after_options_restored([ 'permalink_structure' ]);
        $this->assertNotEmpty(self::rules_starting_with('category/'), 'precondition: the default base routes');

        $out = $this->write('set-permalink-structure', [ 'structure' => '/%postname%/', 'category_base' => 'topics', 'tag_base' => 'labels' ], true);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertNotEmpty(self::rules_starting_with('topics/'), 'the new category base routes right away');
        $this->assertNotEmpty(self::rules_starting_with('labels/'), 'the new tag base routes right away');
        $this->assertSame([], self::rules_starting_with('category/'), 'the old category base stops matching');
        $this->assertSame([], self::rules_starting_with('tag/'), 'the old tag base stops matching');

        $test = $this->read('test-url', [ 'url' => '/topics/uncategorized/' ])['result'];
        $this->assertSame('uncategorized', $test['query_vars']['category_name'] ?? null, (string) wp_json_encode($test));
        $this->assertArrayNotHasKey('attachment', $test['query_vars']);
    }

    /** A structure with a front (/blog/) moves the default bases under it at once too. */
    public function test_a_structure_with_a_front_moves_the_default_bases_under_it(): void
    {
        $this->enable_writes();
        $this->set_permalink_structure('/%postname%/');

        $out = $this->write('set-permalink-structure', [ 'structure' => '/blog/%postname%/' ], true);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertNotEmpty(self::rules_starting_with('blog/category/'));
        $this->assertSame([], self::rules_starting_with('category/'));
    }

    /**
     * The undo of a permalink change rebuilds the rules for the bases it
     * put back, with no separate flush-rewrite-rules.
     */
    public function test_a_rollback_rebuilds_the_rules_for_the_restored_bases(): void
    {
        $this->enable_writes();
        $this->set_permalink_structure('/%postname%/');

        $out = $this->write('set-permalink-structure', [ 'structure' => '/%postname%/', 'category_base' => 'topics', 'tag_base' => 'labels' ], true);
        $this->assertArrayNotHasKey('error', $out);

        $this->rollback($out);

        $this->assertSame('', (string) get_option('category_base'));
        $this->assertNotEmpty(self::rules_starting_with('category/'), 'the restored default category base routes again');
        $this->assertNotEmpty(self::rules_starting_with('tag/'), 'the restored default tag base routes again');
        $this->assertSame([], self::rules_starting_with('topics/'));
        $this->assertSame([], self::rules_starting_with('labels/'));
    }

    public function test_flush_rewrite_rules_rebuilds_the_rules_and_is_honestly_not_recoverable(): void
    {
        $this->enable_writes();
        $this->set_permalink_structure('/%postname%/');
        delete_option('rewrite_rules');

        $out = $this->write('flush-rewrite-rules', []);

        $this->assertArrayNotHasKey('error', $out);
        $this->assertFalse($out['recoverable']);
        $this->assertArrayNotHasKey('operation_id', $out);
        $this->assertSame('soft', $out['result']['flushed']);
        $this->assertGreaterThan(0, $out['result']['rules_count']);
        $this->assertNotEmpty(get_option('rewrite_rules'));
    }
}
