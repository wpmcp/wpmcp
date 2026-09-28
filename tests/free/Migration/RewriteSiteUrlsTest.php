<?php

namespace WPMCP\Tests\Free\Migration;

use WPMCP\Tests\Free\Backup\RestoreArchiveFixtures;
use WPMCP\Tools\Backup\Backup_Job_Store;
use WPMCP\Tools\Backup\Restore_Site_Backup;
use WPMCP\Tools\Database\Database_Guard;
use WPMCP\Tools\Migration\Rewrite_Site_Urls;

/**
 * The phase-1 migration pass (issue #191): a serialization-aware URL
 * rewrite over the core tables. What matters here is the safety contract:
 * a dry run writes nothing, applying needs confirm:true, serialized values
 * survive the rewrite, object-bearing values are refused and reported,
 * escaped-slash JSON that carries no plain form is still found, and a
 * second pass over distinct hosts is a no-op.
 */
class RewriteSiteUrlsTest extends \WP_UnitTestCase
{
    use RestoreArchiveFixtures;

    private const FROM = 'https://old.example';
    private const TO   = 'https://new.example';

    private Rewrite_Site_Urls $tool;

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        delete_option(Backup_Job_Store::OPTION);
        // The safety archive is real (a genuine dump in a real zip), but of
        // wp_posts only: a whole-database dump per test would be slow and
        // tells these tests nothing the backup suite does not.
        $this->tool = new Rewrite_Site_Urls($this->safety_producer([$wpdb->posts]));
        delete_option(Database_Guard::AUDIT_OPTION);
    }

    protected function tearDown(): void
    {
        $this->clean_restore_fixtures();
        delete_option(Backup_Job_Store::OPTION);
        delete_option('wpmcp_maintenance');
        parent::tearDown();
    }

    public function test_requires_absolute_http_urls(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tool->handle(['from_url' => 'old.example', 'to_url' => self::TO]);
    }

    public function test_refuses_identical_urls(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tool->handle(['from_url' => self::FROM, 'to_url' => self::FROM]);
    }

    public function test_apply_without_confirm_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tool->handle(['from_url' => self::FROM, 'to_url' => self::TO, 'dry_run' => false]);
    }

    public function test_string_confirm_is_not_confirmation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tool->handle(['from_url' => self::FROM, 'to_url' => self::TO, 'dry_run' => false, 'confirm' => 'false']);
    }

    public function test_unknown_tables_filter_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tool->handle(['from_url' => self::FROM, 'to_url' => self::TO, 'tables' => ['users']]);
    }

    /**
     * The stored PHP snippet corpus (issue #85) holds PHP source, and a code
     * change is only allowed through update-php-snippet, which re-validates
     * it and forces the snippet back to inactive. A site-wide text rewrite
     * with a caller-chosen replacement must not splice into that code behind
     * the snippet tools' back, so the store is excluded from the pass.
     */
    public function test_the_php_snippet_store_is_never_rewritten(): void
    {
        $code  = "<?php return '" . self::FROM . "/hook';";
        $store = [
            'abc' => [
                'id'     => 'abc',
                'name'   => 'n',
                'code'   => $code,
                'status' => 'active',
            ],
        ];
        update_option('wpmcp_php_snippets', $store, false);

        $this->tool->handle(['from_url' => self::FROM, 'to_url' => self::TO, 'dry_run' => false, 'confirm' => true, 'tables' => ['options']]);

        wp_cache_flush();
        $this->assertSame($code, get_option('wpmcp_php_snippets')['abc']['code']);
        delete_option('wpmcp_php_snippets');
    }

    public function test_dry_run_is_the_default_and_writes_nothing(): void
    {
        global $wpdb;

        $ids = $this->seed();

        $out = $this->tool->handle(['from_url' => self::FROM, 'to_url' => self::TO]);

        $this->assertTrue($out['dry_run']);
        $this->assertArrayNotHasKey('recoverable', $out);
        $this->assertSame([], Backup_Job_Store::list(), 'A dry run must not take a safety archive.');
        $this->assertSame(1, $out['tables']['options']['rows_changed']);
        $this->assertSame(1, $out['tables']['options']['rows_skipped_object']);
        $this->assertSame(1, $out['tables']['posts']['rows_changed']);
        $this->assertSame(1, $out['tables']['postmeta']['rows_changed']);
        $this->assertSame(1, $out['tables']['comments']['rows_changed']);

        $this->assertSame(
            serialize(['url' => self::FROM . '/a', 'n' => 3]),
            $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_id = %d", $ids['option']))
        );
        $this->assertStringContainsString(
            'https:\\/\\/old.example\\/a.png',
            $wpdb->get_var($wpdb->prepare("SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $ids['post']))
        );
        $this->assertSame([], get_option(Database_Guard::AUDIT_OPTION, []), 'A dry run must not audit a write.');
    }

    public function test_apply_rewrites_every_form_and_reports_its_safety_archive(): void
    {
        global $wpdb;

        $ids = $this->seed();

        $out = $this->tool->handle(['from_url' => self::FROM, 'to_url' => self::TO, 'dry_run' => false, 'confirm' => true]);

        $this->assertFalse($out['dry_run']);
        $this->assertTrue($out['recoverable']);
        $this->assertFileExists($out['safety_archive']['file']);
        $job = Backup_Job_Store::get($out['safety_archive']['job_id']);
        $this->assertSame('completed', $job['status']);
        $this->assertSame('pre-rewrite safety archive', $job['purpose']);
        $this->assertStringContainsString('job_id ' . $out['safety_archive']['job_id'], $out['undo']);
        $this->assertSame(1, $out['tables']['options']['rows_changed']);
        $this->assertSame(0, $out['tables']['options']['rows_failed']);

        // Serialized option: still unserializable, length corrected.
        $option = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_id = %d", $ids['option']));
        $this->assertSame(['url' => self::TO . '/a', 'n' => 3], unserialize($option));

        // Block content: JSON-escaped attribute and plain HTML attribute both rewritten.
        $content = $wpdb->get_var($wpdb->prepare("SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $ids['post']));
        $this->assertSame(
            '<!-- wp:image {"url":"https:\\/\\/new.example\\/a.png"} --><img src="https://new.example/a.png" /><!-- /wp:image -->',
            $content
        );

        // Elementor-style meta carrying ONLY the escaped-slash form (no
        // plain form anywhere): the pre-filter must still find it.
        $meta = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d", $ids['meta']));
        $this->assertSame('[{"settings":{"image":{"url":"https:\\/\\/new.example\\/b.png"}}}]', $meta);

        // GUIDs are never rewritten.
        $this->assertSame(self::FROM . '/?p=' . $ids['post'], get_post($ids['post'])->guid);

        // Comment author URL and content.
        $comment = get_comment($ids['comment']);
        $this->assertSame(self::TO . '/author', $comment->comment_author_url);
        $this->assertSame('see https://new.example/c', $comment->comment_content);

        // Object-bearing option: refused, reported, byte-for-byte untouched.
        $this->assertSame(1, $out['tables']['options']['rows_skipped_object']);
        $object = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_id = %d", $ids['object']));
        $this->assertSame(serialize((object) ['url' => self::FROM . '/o']), $object);

        // Every written table lands in the Database_Guard audit log.
        $audited = array_column(get_option(Database_Guard::AUDIT_OPTION, []), 'table');
        $this->assertContains($wpdb->options, $audited);
        $this->assertContains($wpdb->posts, $audited);
        $this->assertContains($wpdb->postmeta, $audited);
        $this->assertContains($wpdb->comments, $audited);
    }

    public function test_second_apply_over_distinct_hosts_changes_nothing(): void
    {
        $this->seed();

        $this->tool->handle(['from_url' => self::FROM, 'to_url' => self::TO, 'dry_run' => false, 'confirm' => true]);
        $again = $this->tool->handle(['from_url' => self::FROM, 'to_url' => self::TO, 'dry_run' => false, 'confirm' => true]);

        foreach ($again['tables'] as $key => $report) {
            if (! empty($report['skipped'])) {
                continue;
            }
            $this->assertSame(0, $report['rows_changed'], "{$key} must not change on a second pass");
        }
        $this->assertSame(1, $again['tables']['options']['rows_skipped_object'], 'The refused object row is still reported on the second pass.');
    }

    public function test_to_url_containing_from_url_is_applied_once_per_occurrence(): void
    {
        global $wpdb;

        $post_id = self::factory()->post->create(['post_content' => 'x']);
        $wpdb->update($wpdb->posts, ['post_content' => '<img src="http://localhost/i.png" /> //localhost/j'], ['ID' => $post_id]);

        $this->tool->handle(['from_url' => 'http://localhost', 'to_url' => 'http://localhost:8080', 'dry_run' => false, 'confirm' => true, 'tables' => ['posts']]);

        $this->assertSame(
            '<img src="http://localhost:8080/i.png" /> //localhost:8080/j',
            $wpdb->get_var($wpdb->prepare("SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post_id))
        );
    }

    public function test_tables_filter_limits_the_walk(): void
    {
        $this->seed();

        $out = $this->tool->handle(['from_url' => self::FROM, 'to_url' => self::TO, 'tables' => ['posts']]);

        $this->assertSame(['posts'], array_keys($out['tables']));
    }

    public function test_protected_tables_are_reported_as_skipped_not_written(): void
    {
        global $wpdb;

        $user_id = self::factory()->user->create();
        $meta_id = add_user_meta($user_id, 'wpmcp_test_url', self::FROM . '/u');

        $out = $this->tool->handle(['from_url' => self::FROM, 'to_url' => self::TO, 'dry_run' => false, 'confirm' => true, 'tables' => ['usermeta']]);

        $this->assertTrue($out['tables']['usermeta']['skipped']);
        $this->assertStringContainsString('protected', $out['tables']['usermeta']['skipped_reason']);
        $this->assertSame(
            self::FROM . '/u',
            $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->usermeta} WHERE umeta_id = %d", $meta_id))
        );
    }

    public function test_lifting_protection_lets_usermeta_be_rewritten(): void
    {
        global $wpdb;

        add_filter('wpmcp_db_protected_tables', static fn () => [ $wpdb->users ]);

        $user_id = self::factory()->user->create();
        $meta_id = add_user_meta($user_id, 'wpmcp_test_url', self::FROM . '/u');

        $out = $this->tool->handle(['from_url' => self::FROM, 'to_url' => self::TO, 'dry_run' => false, 'confirm' => true, 'tables' => ['usermeta']]);

        $this->assertSame(1, $out['tables']['usermeta']['rows_changed']);
        $this->assertSame(
            self::TO . '/u',
            $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->usermeta} WHERE umeta_id = %d", $meta_id))
        );
    }

    public function test_partially_refused_row_counts_both_change_and_skip(): void
    {
        global $wpdb;

        // Two columns on one row: content is rewritable, the excerpt holds
        // a serialized object the rewriter must refuse. The row is changed
        // AND reported as skipped, not one or the other.
        $post_id = self::factory()->post->create(['post_content' => 'x', 'post_excerpt' => 'y']);
        $wpdb->update(
            $wpdb->posts,
            [
                'post_content' => 'see ' . self::FROM . '/p',
                'post_excerpt' => serialize((object) ['url' => self::FROM . '/e']),
            ],
            ['ID' => $post_id]
        );

        $out = $this->tool->handle(['from_url' => self::FROM, 'to_url' => self::TO, 'dry_run' => false, 'confirm' => true, 'tables' => ['posts']]);

        $this->assertSame(1, $out['tables']['posts']['rows_changed']);
        $this->assertSame(1, $out['tables']['posts']['rows_skipped_object']);
        $row = $wpdb->get_row($wpdb->prepare("SELECT post_content, post_excerpt FROM {$wpdb->posts} WHERE ID = %d", $post_id), ARRAY_A);
        $this->assertSame('see ' . self::TO . '/p', $row['post_content']);
        $this->assertSame(serialize((object) ['url' => self::FROM . '/e']), $row['post_excerpt']);
    }

    public function test_a_failed_safety_archive_refuses_the_pass_and_changes_nothing(): void
    {
        global $wpdb;

        $ids  = $this->seed();
        $tool = new Rewrite_Site_Urls(static function (): array {
            throw new \RuntimeException('disk full');
        });

        try {
            $tool->handle(['from_url' => self::FROM, 'to_url' => self::TO, 'dry_run' => false, 'confirm' => true]);
            $this->fail('A rewrite without a safety archive must not run.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('pre-rewrite safety archive', $e->getMessage());
            $this->assertStringContainsString('disk full', $e->getMessage());
        }

        $this->assertSame(
            serialize(['url' => self::FROM . '/a', 'n' => 3]),
            $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_id = %d", $ids['option']))
        );
        $this->assertSame([], get_option(Database_Guard::AUDIT_OPTION, []));
    }

    public function test_the_pass_rolls_back_through_its_safety_archive(): void
    {
        global $wpdb;

        $ids  = $this->seed();
        $tool = new Rewrite_Site_Urls($this->safety_producer([$wpdb->posts, $wpdb->postmeta]));

        $out = $tool->handle(['from_url' => self::FROM, 'to_url' => self::TO, 'dry_run' => false, 'confirm' => true, 'tables' => ['posts', 'postmeta']]);
        $this->assertStringContainsString('new.example', (string) $wpdb->get_var($wpdb->prepare("SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $ids['post'])));

        $restore = (new Restore_Site_Backup($this->safety_producer([$wpdb->posts, $wpdb->postmeta])))->handle([
            'job_id'  => $out['safety_archive']['job_id'],
            'dry_run' => false,
        ]);

        $this->assertSame('restored', $restore['status']);
        $this->assertSame(
            '<!-- wp:image {"url":"https:\\/\\/old.example\\/a.png"} --><img src="https://old.example/a.png" /><!-- /wp:image -->',
            $wpdb->get_var($wpdb->prepare("SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $ids['post']))
        );
        $this->assertSame(
            '[{"settings":{"image":{"url":"https:\\/\\/old.example\\/b.png"}}}]',
            $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d", $ids['meta']))
        );
    }

    public function test_a_supplied_recovery_point_is_reported_instead_of_a_second_archive(): void
    {
        $this->seed();

        $out = $this->tool->handle(
            ['from_url' => self::FROM, 'to_url' => self::TO, 'dry_run' => false, 'confirm' => true],
            ['job_id' => 41, 'file' => '/tmp/pre-migration.zip']
        );

        $this->assertSame([], Backup_Job_Store::list(), 'A caller that already holds a whole-database archive must not pay for another.');
        $this->assertTrue($out['recoverable']);
        $this->assertSame(41, $out['safety_archive']['job_id']);
    }

    /**
     * The definition of done in user terms: after a move, theme mods and
     * widget settings (both PHP-serialized arrays in wp_options) still read
     * back as arrays, now carrying the new URL.
     */
    public function test_theme_mods_and_widget_settings_survive_with_the_new_url(): void
    {
        set_theme_mod('header_image', self::FROM . '/wp-content/uploads/header.jpg');
        set_theme_mod('wpmcp_test_nested', ['logo' => ['src' => self::FROM . '/logo.png', 'w' => 120]]);
        update_option('widget_text', [
            2              => ['title' => 'Hi', 'text' => '<img src="' . self::FROM . '/w.png" />', 'filter' => true],
            '_multiwidget' => 1,
        ]);

        $out = $this->tool->handle(['from_url' => self::FROM, 'to_url' => self::TO, 'dry_run' => false, 'confirm' => true, 'tables' => ['options']]);

        $this->assertSame(0, $out['tables']['options']['rows_failed']);
        wp_cache_flush();
        $this->assertSame(self::TO . '/wp-content/uploads/header.jpg', get_theme_mod('header_image'));
        $this->assertSame(['logo' => ['src' => self::TO . '/logo.png', 'w' => 120]], get_theme_mod('wpmcp_test_nested'));
        $widgets = get_option('widget_text');
        $this->assertIsArray($widgets);
        $this->assertSame('<img src="' . self::TO . '/w.png" />', $widgets[2]['text']);
        $this->assertTrue($widgets[2]['filter']);
    }

    /**
     * Seed one row of every interesting shape and return their primary keys.
     *
     * @return array{option: int, object: int, post: int, meta: int, comment: int}
     */
    private function seed(): array
    {
        global $wpdb;

        update_option('wpmcp_test_serialized', ['url' => self::FROM . '/a', 'n' => 3]);
        $option_id = (int) $wpdb->get_var("SELECT option_id FROM {$wpdb->options} WHERE option_name = 'wpmcp_test_serialized'");

        update_option('wpmcp_test_object', (object) ['url' => self::FROM . '/o']);
        $object_id = (int) $wpdb->get_var("SELECT option_id FROM {$wpdb->options} WHERE option_name = 'wpmcp_test_object'");

        $post_id = self::factory()->post->create(['post_content' => 'placeholder']);
        // Written raw: wp_insert_post() unslashes, which would turn the
        // JSON-escaped \/ into a plain slash and hide the case under test.
        $wpdb->update(
            $wpdb->posts,
            [
                'post_content' => '<!-- wp:image {"url":"https:\\/\\/old.example\\/a.png"} --><img src="https://old.example/a.png" /><!-- /wp:image -->',
                'guid'         => self::FROM . '/?p=' . $post_id,
            ],
            ['ID' => $post_id]
        );
        clean_post_cache($post_id);

        // Elementor stores its data as wp_slash(wp_json_encode()): escaped
        // slashes only, no plain form of the URL anywhere in the value.
        $meta_id = (int) add_post_meta($post_id, '_wpmcp_test_elementor', wp_slash('[{"settings":{"image":{"url":"https:\/\/old.example\/b.png"}}}]'));

        $comment_id = (int) self::factory()->comment->create([
            'comment_post_ID'    => $post_id,
            'comment_content'    => 'see https://old.example/c',
            'comment_author_url' => self::FROM . '/author',
        ]);

        return [
            'option'  => $option_id,
            'object'  => $object_id,
            'post'    => $post_id,
            'meta'    => $meta_id,
            'comment' => $comment_id,
        ];
    }
}
