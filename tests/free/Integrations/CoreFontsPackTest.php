<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Core_Fonts_Pack;
use WPMCP\Integrations\Theme_Integration;
use WPMCP\MCP\Tool_Search;
use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;

/**
 * WordPress core's Font Library (issue #712) as free ops on the theme
 * dispatcher pair, so it adds no top-level tool.
 *
 * Reads list the installed font families (wp_font_family posts) with their
 * faces (wp_font_face children) and whether global styles has them active.
 * Writes install a family from the Google Fonts collection (the files are
 * downloaded to the site's fonts directory, as the Font Library does) or
 * from a font file, and delete a family with its faces and files. Every
 * write is undone in a test: an install records what it created, so
 * rollback-operation deletes those posts and their files; a delete
 * snapshots every face with a copy of its file and the family, so
 * rollback-session puts back the posts at their ids and the file bytes.
 */
class CoreFontsPackTest extends \WP_UnitTestCase
{
    private const OPS = [
        'list-font-families'       => 'read',
        'get-font-family'          => 'read',
        'install-google-font'      => 'write',
        'install-font-from-upload' => 'write',
        'delete-font-family'       => 'destructive',
    ];

    /** URL prefix the stub collection serves its faces from. */
    private const CDN = 'https://fonts.gstatic.com/s/stub/';

    /** @var string[] URLs the stubbed HTTP layer was asked for. */
    private array $fetched = [];

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        $GLOBALS['wpdb']->query('DELETE FROM ' . Snapshot_Store::table_name());
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
        $this->fetched = [];
    }

    protected function tearDown(): void
    {
        remove_all_filters(Core_Fonts_Pack::ENABLE_FILTER);
        remove_all_filters('pre_http_request');
        Gate::set_pro_for_tests(null);
        foreach (get_posts([ 'post_type' => [ 'wp_font_family', 'wp_font_face' ], 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ]) as $id) {
            wp_delete_post($id, true);
        }
        if (null !== \WP_Font_Library::get_instance()->get_font_collection('google-fonts')) {
            wp_unregister_font_collection('google-fonts');
        }
        _wp_register_default_font_collections();
        parent::tearDown();
    }

    private function font_bytes(): string
    {
        $file = DIR_TESTDATA . '/fonts/OpenSans-Regular.woff2';
        if (! is_file($file)) {
            $this->markTestSkipped('The WordPress test data font files are not available.');
        }
        return (string) file_get_contents($file);
    }

    private function enable_writes(): void
    {
        add_filter(Core_Fonts_Pack::ENABLE_FILTER, '__return_true');
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

    private function font_dir(): string
    {
        return untrailingslashit(wp_get_font_dir()['basedir']);
    }

    /** @return int[] */
    private function family_ids(): array
    {
        return array_map('intval', get_posts([ 'post_type' => 'wp_font_family', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ]));
    }

    /** @return int[] */
    private function face_ids(int $family_id): array
    {
        return array_map('intval', get_posts([ 'post_type' => 'wp_font_face', 'post_parent' => $family_id, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ]));
    }

    /** Install one family from a file, the way an agent would. */
    private function upload(string $family = 'Open Sans', string $weight = '400', string $style = 'normal'): array
    {
        $this->enable_writes();
        return $this->write('install-font-from-upload', [
            'font_family'    => $family,
            'font_weight'    => $weight,
            'font_style'     => $style,
            'filename'       => 'OpenSans-Regular.woff2',
            'content_base64' => base64_encode($this->font_bytes()),
        ]);
    }

    /** Serve a stub google-fonts collection and its font files over a stubbed HTTP layer. */
    private function stub_google_collection(): void
    {
        $bytes = $this->font_bytes();
        wp_unregister_font_collection('google-fonts');
        wp_register_font_collection('google-fonts', [
            'name'          => 'Google Fonts',
            'font_families' => [
                [
                    'font_family_settings' => [
                        'name'       => 'Stub Sans',
                        'slug'       => 'stub-sans',
                        'fontFamily' => '"Stub Sans", sans-serif',
                        'fontFace'   => [
                            [ 'fontFamily' => 'Stub Sans', 'fontStyle' => 'normal', 'fontWeight' => '400', 'src' => self::CDN . 'stub-400-normal.woff2' ],
                            [ 'fontFamily' => 'Stub Sans', 'fontStyle' => 'normal', 'fontWeight' => '700', 'src' => self::CDN . 'stub-700-normal.woff2' ],
                            [ 'fontFamily' => 'Stub Sans', 'fontStyle' => 'italic', 'fontWeight' => '400', 'src' => self::CDN . 'stub-400-italic.woff2' ],
                        ],
                    ],
                    'categories'           => [ 'sans-serif' ],
                ],
                [
                    'font_family_settings' => [
                        'name'       => 'Stub Serif',
                        'slug'       => 'stub-serif',
                        'fontFamily' => '"Stub Serif", serif',
                        'fontFace'   => [
                            [ 'fontFamily' => 'Stub Serif', 'fontStyle' => 'normal', 'fontWeight' => '400', 'src' => self::CDN . 'serif-400-normal.woff2' ],
                        ],
                    ],
                    'categories'           => [ 'serif' ],
                ],
            ],
        ]);
        add_filter('pre_http_request', function ($pre, $args, $url) use ($bytes) {
            if (! str_starts_with((string) $url, self::CDN)) {
                return $pre;
            }
            $this->fetched[] = (string) $url;
            if (! empty($args['stream']) && ! empty($args['filename'])) {
                file_put_contents($args['filename'], $bytes);
            }
            return [ 'headers' => [], 'body' => '', 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => $args['filename'] ?? null ];
        }, 10, 3);
    }

    // ---------------------------------------------------------------
    // Catalog, opt-in and availability
    // ---------------------------------------------------------------

    public function test_every_op_is_a_free_op_on_the_theme_pair_with_its_mode(): void
    {
        Gate::set_pro_for_tests(false);
        $ops = $this->catalog();

        foreach (self::OPS as $name => $mode) {
            $this->assertArrayHasKey($name, $ops, "{$name} must be on the theme pair without a license");
            $this->assertSame($mode, $ops[ $name ]['mode'], $name);
            $this->assertTrue($ops[ $name ]['dependency_met'], "{$name}: the Font Library is in core");
        }
        $this->assertTrue($ops['delete-font-family']['requires_confirm']);
    }

    public function test_writes_are_off_until_the_site_opts_in_and_the_refusal_names_the_filter(): void
    {
        $ops = $this->catalog();
        foreach (self::OPS as $name => $mode) {
            $this->assertSame('read' === $mode, $ops[ $name ]['enabled'], $name);
        }

        $out = $this->write('install-font-from-upload', [ 'font_family' => 'Open Sans', 'filename' => 'a.woff2', 'content_base64' => base64_encode('x') ]);
        $this->assertSame('opt_in_required', $this->error_code($out));
        $this->assertStringContainsString(Core_Fonts_Pack::ENABLE_FILTER, $out['error']['message']);
        $this->assertSame([], $this->family_ids(), 'a refused write changes nothing');
    }

    public function test_tool_search_finds_the_ops_inside_the_theme_pair(): void
    {
        [ $read, $write ] = (new Theme_Integration())->abilities();

        $this->assertContains('list-font-families', Tool_Search::match($read, 'font'));
        $this->assertContains('install-google-font', Tool_Search::match($write, 'font'));
        $this->assertContains('delete-font-family', Tool_Search::match($write, 'font'));
    }

    public function test_a_site_without_the_font_library_gets_plugin_missing(): void
    {
        $saved = $GLOBALS['wp_post_types']['wp_font_family'];
        unset($GLOBALS['wp_post_types']['wp_font_family']);
        try {
            $out = $this->read('list-font-families');
        } finally {
            $GLOBALS['wp_post_types']['wp_font_family'] = $saved;
        }
        $this->assertSame('plugin_missing', $this->error_code($out));
        $this->assertStringContainsString('WordPress core 6.5+', $out['error']['message']);
    }

    // ---------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------

    public function test_list_and_get_report_families_with_their_faces(): void
    {
        $made = $this->upload();
        $this->assertArrayNotHasKey('error', $made, wp_json_encode($made));
        $family_id = (int) $made['result']['family']['id'];

        $list = $this->read('list-font-families');
        $this->assertArrayNotHasKey('error', $list, wp_json_encode($list));
        $this->assertSame(1, $list['result']['total']);
        $row = $list['result']['families'][0];
        $this->assertSame($family_id, $row['id']);
        $this->assertSame('Open Sans', $row['name']);
        $this->assertSame('open-sans', $row['slug']);
        $this->assertSame(1, $row['face_count']);
        $this->assertFalse($row['active'], 'installing does not activate the family in global styles');

        foreach ([ [ 'id' => $family_id ], [ 'slug' => 'open-sans' ] ] as $args) {
            $got = $this->read('get-font-family', $args);
            $this->assertArrayNotHasKey('error', $got, wp_json_encode($got));
            $family = $got['result'];
            $this->assertSame($family_id, $family['id']);
            $this->assertCount(1, $family['faces']);
            $face = $family['faces'][0];
            $this->assertSame('400', $face['font_weight']);
            $this->assertSame('normal', $face['font_style']);
            $this->assertTrue($face['local']);
            $this->assertStringStartsWith(wp_get_font_dir()['baseurl'], $face['src'][0]);
        }

        $this->assertSame('font_family_not_found', $this->error_code($this->read('get-font-family', [ 'slug' => 'nope' ])));
    }

    // ---------------------------------------------------------------
    // install-font-from-upload
    // ---------------------------------------------------------------

    public function test_upload_installs_the_file_locally_and_rollback_removes_posts_and_file(): void
    {
        $out = $this->upload();
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertTrue($out['result']['family']['created']);

        $family_id = (int) $out['result']['family']['id'];
        $faces     = $this->face_ids($family_id);
        $this->assertCount(1, $faces);
        $file = $this->font_dir() . '/' . get_post_meta($faces[0], '_wp_font_face_file', true);
        $this->assertFileExists($file);
        $this->assertSame($this->font_bytes(), file_get_contents($file));

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_ids'][0] ]);

        $this->assertNull(get_post($family_id), 'the created family is deleted, not trashed: core has no trash for fonts');
        $this->assertNull(get_post($faces[0]));
        $this->assertFileDoesNotExist($file, 'the downloaded file goes with its face');
    }

    public function test_upload_to_an_existing_family_adds_a_face_and_rollback_removes_only_it(): void
    {
        $first     = $this->upload();
        $family_id = (int) $first['result']['family']['id'];

        $second = $this->upload('Open Sans', '700');
        $this->assertArrayNotHasKey('error', $second, wp_json_encode($second));
        $this->assertFalse($second['result']['family']['created']);
        $this->assertSame($family_id, (int) $second['result']['family']['id']);
        $this->assertCount(2, $this->face_ids($family_id));

        (new Rollback_Operation())->handle([ 'operation_id' => $second['operation_ids'][0] ]);

        $this->assertNotNull(get_post($family_id), 'a family that existed before stays');
        $this->assertCount(1, $this->face_ids($family_id));
    }

    public function test_a_duplicate_face_and_a_file_that_is_not_a_font_are_refused_without_writing(): void
    {
        $this->upload();

        $dup = $this->upload();
        $this->assertSame('font_face_exists', $this->error_code($dup));

        $bad = $this->write('install-font-from-upload', [
            'font_family'    => 'Fake',
            'filename'       => 'fake.woff2',
            'content_base64' => base64_encode('<?php echo 1; ?>'),
        ]);
        $this->assertSame('not_a_font', $this->error_code($bad));

        $ext = $this->write('install-font-from-upload', [
            'font_family'    => 'Fake',
            'filename'       => 'fake.php',
            'content_base64' => base64_encode($this->font_bytes()),
        ]);
        $this->assertSame('invalid_font_file', $this->error_code($ext));

        $this->assertCount(1, $this->family_ids(), 'nothing but the first install exists');
    }

    public function test_upload_from_a_url_downloads_the_file(): void
    {
        $this->stub_google_collection();
        $this->enable_writes();

        $out = $this->write('install-font-from-upload', [ 'font_family' => 'Url Font', 'url' => self::CDN . 'stub-400-normal.woff2' ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertSame([ self::CDN . 'stub-400-normal.woff2' ], $this->fetched);

        $http = $this->write('install-font-from-upload', [ 'font_family' => 'Url Font', 'url' => 'http://example.com/a.woff2' ]);
        $this->assertSame('invalid_font_file', $this->error_code($http), 'only https sources');
    }

    // ---------------------------------------------------------------
    // install-google-font
    // ---------------------------------------------------------------

    public function test_google_font_install_downloads_the_requested_faces_and_rolls_back(): void
    {
        $this->stub_google_collection();
        $this->enable_writes();

        $out = $this->write('install-google-font', [ 'slug' => 'stub-sans', 'faces' => [ [ 'weight' => '400', 'style' => 'normal' ], [ 'weight' => '700' ] ] ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertCount(2, $this->fetched, 'only the requested faces are downloaded');

        $family_id = (int) $out['result']['family']['id'];
        $family    = get_post($family_id);
        $this->assertSame('Stub Sans', $family->post_title);
        $this->assertSame('stub-sans', $family->post_name);
        $faces = $this->face_ids($family_id);
        $this->assertCount(2, $faces);
        foreach ($faces as $face_id) {
            $settings = json_decode(get_post($face_id)->post_content, true);
            $this->assertStringStartsWith(wp_get_font_dir()['baseurl'], $settings['src'], 'served from this site, not the CDN');
        }

        (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_ids'][0] ]);
        $this->assertSame([], $this->family_ids());
    }

    public function test_google_font_install_without_faces_takes_all_and_by_name(): void
    {
        $this->stub_google_collection();
        $this->enable_writes();

        $out = $this->write('install-google-font', [ 'name' => 'stub sans' ]);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertCount(3, $this->face_ids((int) $out['result']['family']['id']));
    }

    public function test_an_unknown_google_font_is_refused_with_suggestions(): void
    {
        $this->stub_google_collection();
        $this->enable_writes();

        $out = $this->write('install-google-font', [ 'slug' => 'stub' ]);
        $this->assertSame('font_not_in_collection', $this->error_code($out));
        $this->assertContains('stub-sans', $out['error']['data']['suggestions']);

        $none = $this->write('install-google-font', [ 'slug' => 'stub-sans', 'faces' => [ [ 'weight' => '900' ] ] ]);
        $this->assertSame('faces_not_in_collection', $this->error_code($none));
        $this->assertSame([], $this->fetched, 'a refused install downloads nothing');
        $this->assertSame([], $this->family_ids());
    }

    // ---------------------------------------------------------------
    // delete-font-family
    // ---------------------------------------------------------------

    public function test_delete_needs_confirm_and_rollback_session_restores_posts_and_files(): void
    {
        $this->upload();
        $made      = $this->upload('Open Sans', '700');
        $family_id = (int) $made['result']['family']['id'];
        $faces     = $this->face_ids($family_id);
        sort($faces);
        $files = [];
        foreach ($faces as $face_id) {
            $files[ $face_id ] = $this->font_dir() . '/' . get_post_meta($face_id, '_wp_font_face_file', true);
        }

        $unconfirmed = $this->write('delete-font-family', [ 'id' => $family_id ]);
        $this->assertSame('confirmation_required', $this->error_code($unconfirmed));
        $this->assertNotNull(get_post($family_id));

        $out = $this->write('delete-font-family', [ 'id' => $family_id ], true);
        $this->assertArrayNotHasKey('error', $out, wp_json_encode($out));
        $this->assertTrue($out['recoverable']);
        $this->assertNull(get_post($family_id));
        foreach ($files as $face_id => $file) {
            $this->assertNull(get_post($face_id));
            $this->assertFileDoesNotExist($file);
        }

        (new Rollback_Session())->handle([ 'session_id' => $out['session_id'] ]);

        $family = get_post($family_id);
        $this->assertNotNull($family, 'the family is back at its id');
        $this->assertSame('wp_font_family', $family->post_type);
        $restored = $this->face_ids($family_id);
        sort($restored);
        $this->assertSame($faces, $restored, 'every face is back at its id under the family');
        foreach ($files as $file) {
            $this->assertFileExists($file);
            $this->assertSame($this->font_bytes(), file_get_contents($file));
        }
    }

    public function test_delete_refuses_a_post_that_is_not_a_font_family(): void
    {
        $this->enable_writes();
        $page = self::factory()->post->create([ 'post_type' => 'page' ]);

        $out = $this->write('delete-font-family', [ 'id' => $page ], true);
        $this->assertSame('font_family_not_found', $this->error_code($out));
        $this->assertNotNull(get_post($page));
    }
}
