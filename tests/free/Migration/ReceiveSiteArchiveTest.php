<?php

namespace WPMCP\Tests\Free\Migration;

use WPMCP\Tests\Free\Backup\RestoreArchiveFixtures;
use WPMCP\Tools\Backup\Archive_Locator;
use WPMCP\Tools\Backup\Backup_Job_Store;
use WPMCP\Tools\Backup\Site_Backup_Dir;
use WPMCP\Tools\Migration\Incoming_Archive_Store;
use WPMCP\Tools\Migration\Receive_Site_Archive;

/**
 * The target half of a site-to-site push (issue #191, phase 2).
 *
 * The contract under test: nothing happens unless the site opted in; an
 * archive this site could never restore is refused before any byte is
 * sent; uploads resume and tolerate retried chunks; nothing is applied
 * until the whole file matches its sha256 and its own manifest matches the
 * declared one; an apply restores (safety archive first), then rewrites
 * the source's URLs to this site's, and reports every step.
 */
class ReceiveSiteArchiveTest extends \WP_UnitTestCase
{
    use RestoreArchiveFixtures;

    private const SOURCE = 'https://old.example';

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Backup_Job_Store::OPTION);
        delete_option('wpmcp_maintenance');
        add_filter('wpmcp_accept_incoming_migrations', '__return_true');
    }

    protected function tearDown(): void
    {
        $this->clean_restore_fixtures();
        foreach (Backup_Job_Store::list() as $job) {
            $file = (string) ($job['result']['file'] ?? '');
            if ('' !== $file && is_file($file)) {
                unlink($file);
            }
        }
        foreach ((array) glob(Incoming_Archive_Store::dir() . '/*') as $file) {
            if (is_file((string) $file) && ! in_array(basename((string) $file), ['.htaccess', 'index.php', 'README.txt'], true)) {
                unlink((string) $file);
            }
        }
        foreach ((array) glob(Site_Backup_Dir::path() . '/wpmcp-migration-*.zip') as $file) {
            unlink((string) $file);
        }
        delete_option(Backup_Job_Store::OPTION);
        delete_option('wpmcp_maintenance');
        remove_all_filters('wpmcp_accept_incoming_migrations');
        parent::tearDown();
    }

    private function tool(): Receive_Site_Archive
    {
        global $wpdb;

        return new Receive_Site_Archive($this->safety_producer([$wpdb->posts, $wpdb->postmeta]));
    }

    /** A real archive of wp_posts + wp_postmeta that claims to come from SOURCE. */
    private function source_archive(): string
    {
        global $wpdb;

        return $this->archive_of([$wpdb->posts, $wpdb->postmeta], [
            'site.home_url' => self::SOURCE,
            'site.site_url' => self::SOURCE,
        ]);
    }

    /** @return array<string, mixed> */
    private function start(string $archive, array $extra = []): array
    {
        $out = $this->tool()->handle(array_merge([
            'action'   => 'start',
            'sha256'   => hash_file('sha256', $archive),
            'bytes'    => filesize($archive),
            'manifest' => Archive_Locator::read_manifest($archive),
        ], $extra));
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');

        return $out;
    }

    /** Upload the whole archive in $size-byte chunks; returns the upload id. */
    private function upload(string $archive, int $size = 4096): string
    {
        $start = $this->start($archive);
        $bytes = (string) file_get_contents($archive);
        for ($offset = $start['received_bytes']; $offset < strlen($bytes); $offset += $size) {
            $out = $this->tool()->handle([
                'action'    => 'chunk',
                'upload_id' => $start['upload_id'],
                'offset'    => $offset,
                'data'      => base64_encode(substr($bytes, $offset, $size)),
            ]);
            $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        }

        return $start['upload_id'];
    }

    public function test_nothing_is_accepted_while_the_gate_is_closed(): void
    {
        remove_all_filters('wpmcp_accept_incoming_migrations');

        $out = $this->tool()->handle(['action' => 'status', 'upload_id' => str_repeat('a', 20)]);

        $this->assertWPError($out);
        $this->assertSame('wpmcp_migration_receive_disabled', $out->get_error_code());
    }

    public function test_an_incompatible_manifest_is_refused_before_any_upload(): void
    {
        $archive  = $this->source_archive();
        $manifest = Archive_Locator::read_manifest($archive);
        $manifest['site']['table_prefix'] = 'other_';
        $manifest['scope']                = 'uploads';

        $out = $this->tool()->handle([
            'action'   => 'start',
            'sha256'   => hash_file('sha256', $archive),
            'bytes'    => filesize($archive),
            'manifest' => $manifest,
        ]);

        $this->assertWPError($out);
        $this->assertSame('wpmcp_migration_incompatible', $out->get_error_code());
        $this->assertStringContainsString('Table prefix mismatch', $out->get_error_message());
        $this->assertStringContainsString('uploads', $out->get_error_message());
        $this->assertSame([], glob(Incoming_Archive_Store::dir() . '/*.json'), 'A refused start must leave no upload record.');
    }

    public function test_a_dry_run_start_reports_the_plan_and_records_nothing(): void
    {
        $archive = $this->source_archive();

        $out = $this->start($archive, ['dry_run' => true]);

        $this->assertTrue($out['dry_run']);
        $this->assertTrue($out['accepted']);
        $this->assertSame([['from' => self::SOURCE, 'to' => untrailingslashit(get_option('home'))]], $out['rewrite']['pairs']);
        $this->assertGreaterThanOrEqual(65536, $out['chunk_bytes_max']);
        $this->assertSame([], glob(Incoming_Archive_Store::dir() . '/*.json'));
    }

    public function test_a_second_start_resumes_where_the_first_upload_stopped(): void
    {
        $archive = $this->source_archive();
        $first   = $this->start($archive);
        $bytes   = (string) file_get_contents($archive);

        $this->tool()->handle(['action' => 'chunk', 'upload_id' => $first['upload_id'], 'offset' => 0, 'data' => base64_encode(substr($bytes, 0, 1000))]);

        $again = $this->start($archive);

        $this->assertTrue($again['resumed']);
        $this->assertSame($first['upload_id'], $again['upload_id']);
        $this->assertSame(1000, $again['received_bytes']);
    }

    public function test_restart_discards_the_partial_upload(): void
    {
        $archive = $this->source_archive();
        $first   = $this->start($archive);
        $this->tool()->handle(['action' => 'chunk', 'upload_id' => $first['upload_id'], 'offset' => 0, 'data' => base64_encode('PK')]);

        $again = $this->start($archive, ['restart' => true]);

        $this->assertFalse($again['resumed']);
        $this->assertNotSame($first['upload_id'], $again['upload_id']);
        $this->assertSame(0, $again['received_bytes']);
    }

    public function test_a_retried_chunk_is_acknowledged_not_appended_twice(): void
    {
        $archive = $this->source_archive();
        $start   = $this->start($archive);
        $chunk   = ['action' => 'chunk', 'upload_id' => $start['upload_id'], 'offset' => 0, 'data' => base64_encode(substr((string) file_get_contents($archive), 0, 500))];

        $this->tool()->handle($chunk);
        $retry = $this->tool()->handle($chunk);

        $this->assertTrue($retry['duplicate']);
        $this->assertSame(500, $retry['received_bytes']);
    }

    public function test_an_out_of_order_chunk_is_refused_with_the_offset_to_resume_from(): void
    {
        $archive = $this->source_archive();
        $start   = $this->start($archive);

        $out = $this->tool()->handle(['action' => 'chunk', 'upload_id' => $start['upload_id'], 'offset' => 10, 'data' => base64_encode('abc')]);

        $this->assertWPError($out);
        $this->assertStringContainsString('resume from offset 0', $out->get_error_message());
    }

    public function test_a_chunk_past_the_declared_size_is_refused(): void
    {
        $archive = $this->source_archive();
        $start   = $this->start($archive);

        $out = $this->tool()->handle(['action' => 'chunk', 'upload_id' => $start['upload_id'], 'offset' => 0, 'data' => base64_encode(str_repeat('x', filesize($archive) + 1))]);

        $this->assertWPError($out);
    }

    public function test_an_invalid_upload_id_is_refused_without_touching_the_filesystem(): void
    {
        $out = $this->tool()->handle(['action' => 'status', 'upload_id' => '../../wp-config']);

        $this->assertWPError($out);
        $this->assertStringContainsString('not a valid upload id', $out->get_error_message());
    }

    public function test_a_corrupted_upload_is_refused_at_apply_and_discarded(): void
    {
        $archive = $this->source_archive();
        $start   = $this->start($archive);
        $bytes   = (string) file_get_contents($archive);
        $bytes[100] = chr(ord($bytes[100]) ^ 0xFF);
        $this->tool()->handle(['action' => 'chunk', 'upload_id' => $start['upload_id'], 'offset' => 0, 'data' => base64_encode($bytes)]);

        $out = $this->tool()->handle(['action' => 'apply', 'upload_id' => $start['upload_id'], 'dry_run' => false, 'confirm' => true]);

        $this->assertWPError($out);
        $this->assertStringContainsString('sha256', $out->get_error_message());
        $status = $this->tool()->handle(['action' => 'status', 'upload_id' => $start['upload_id']]);
        $this->assertSame(0, $status['received_bytes'], 'Bytes known to be wrong are not kept.');
        $this->assertSame([], Backup_Job_Store::list(), 'No safety archive, no restore: nothing was trusted.');
    }

    public function test_an_archive_whose_manifest_differs_from_the_declared_one_is_refused(): void
    {
        $archive  = $this->source_archive();
        $manifest = Archive_Locator::read_manifest($archive);
        $manifest['site']['home_url'] = 'https://someone-else.example';
        $bytes    = (string) file_get_contents($archive);

        $start = $this->tool()->handle([
            'action'   => 'start',
            'sha256'   => hash('sha256', $bytes),
            'bytes'    => strlen($bytes),
            'manifest' => $manifest,
        ]);
        $this->tool()->handle(['action' => 'chunk', 'upload_id' => $start['upload_id'], 'offset' => 0, 'data' => base64_encode($bytes)]);

        $out = $this->tool()->handle(['action' => 'apply', 'upload_id' => $start['upload_id'], 'dry_run' => false, 'confirm' => true]);

        $this->assertWPError($out);
        $this->assertStringContainsString('site.home_url', $out->get_error_message());
        $this->assertSame([], glob(Site_Backup_Dir::path() . '/wpmcp-migration-*.zip'));
    }

    public function test_apply_without_confirm_is_refused(): void
    {
        $upload = $this->upload($this->source_archive());

        $out = $this->tool()->handle(['action' => 'apply', 'upload_id' => $upload, 'dry_run' => false]);

        $this->assertWPError($out);
        $this->assertStringContainsString('confirm:true', $out->get_error_message());
    }

    public function test_apply_dry_run_verifies_and_reports_without_restoring(): void
    {
        $post   = self::factory()->post->create(['post_title' => 'Live']);
        $upload = $this->upload($this->source_archive());
        wp_update_post(['ID' => $post, 'post_title' => 'Still live']);

        $out = $this->tool()->handle(['action' => 'apply', 'upload_id' => $upload]);

        $this->assertTrue($out['dry_run']);
        $this->assertTrue($out['steps']['verify']['ok']);
        $this->assertTrue($out['steps']['restore']['compatible']);
        $this->assertSame(self::SOURCE, $out['steps']['rewrite']['pairs'][0]['from']);
        clean_post_cache($post);
        $this->assertSame('Still live', get_post($post)->post_title);
        $this->assertSame([], Backup_Job_Store::list());
    }

    /**
     * The whole phase-2 target flow: a pushed archive from another URL is
     * verified, restored behind a safety archive, and its URLs (plain and
     * JSON-escaped, and inside serialized meta) rewritten to this site's.
     */
    public function test_apply_restores_then_rewrites_the_source_urls_to_this_site(): void
    {
        $target = untrailingslashit((string) get_option('home'));
        $post   = self::factory()->post->create(['post_title' => 'From the source', 'post_content' => 'x']);
        global $wpdb;
        $wpdb->update($wpdb->posts, ['post_content' => '<img src="' . self::SOURCE . '/a.png" /> {"u":"https:\\/\\/old.example\\/b.png"}'], ['ID' => $post]);
        add_post_meta($post, 'gallery', ['one' => self::SOURCE . '/c.png']);
        $archive = $this->source_archive();

        wp_update_post(['ID' => $post, 'post_title' => 'Target content before the move']);
        $upload = $this->upload($archive, 3000);

        $out = $this->tool()->handle(['action' => 'apply', 'upload_id' => $upload, 'dry_run' => false, 'confirm' => true]);

        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');
        $this->assertSame('migrated', $out['status'], wp_json_encode($out));
        $this->assertTrue($out['steps']['verify']['ok']);
        $this->assertSame('restored', $out['steps']['restore']['status']);
        $this->assertTrue($out['steps']['rewrite']['ok']);
        $this->assertSame(1, $out['steps']['rewrite']['results'][0]['tables']['posts']['rows_changed']);

        $job = Backup_Job_Store::get($out['safety_archive']['job_id']);
        $this->assertSame('pre-restore safety archive', $job['purpose']);
        $this->assertStringContainsString('job_id ' . $out['safety_archive']['job_id'], $out['undo']);
        $this->assertCount(1, Backup_Job_Store::list(), 'The rewrite reuses the pre-restore archive instead of taking a second one.');

        clean_post_cache($post);
        wp_cache_flush();
        $this->assertSame('From the source', get_post($post)->post_title);
        $this->assertSame(
            '<img src="' . $target . '/a.png" /> {"u":"' . str_replace('/', '\\/', $target) . '\\/b.png"}',
            get_post($post)->post_content
        );
        $this->assertSame(['one' => $target . '/c.png'], get_post_meta($post, 'gallery', true));

        // The source can read the outcome later (e.g. after a timeout).
        $status = $this->tool()->handle(['action' => 'status', 'upload_id' => $upload]);
        $this->assertSame('applied', $status['status']);
        $this->assertSame('migrated', $status['result']['status']);

        // And a repeated apply reports, it does not restore again.
        $again = $this->tool()->handle(['action' => 'apply', 'upload_id' => $upload, 'dry_run' => false, 'confirm' => true]);
        $this->assertTrue($again['already_applied']);
        $this->assertCount(1, Backup_Job_Store::list());
    }

    public function test_a_failed_safety_archive_means_nothing_is_restored_and_the_failure_is_recorded(): void
    {
        $post   = self::factory()->post->create(['post_title' => 'Untouched']);
        $upload = $this->upload($this->source_archive());
        wp_update_post(['ID' => $post, 'post_title' => 'Live value']);

        $tool = new Receive_Site_Archive(static function (): array {
            throw new \RuntimeException('disk full');
        });
        $out = $tool->handle(['action' => 'apply', 'upload_id' => $upload, 'dry_run' => false, 'confirm' => true]);

        $this->assertSame('restore_failed', $out['status']);
        $this->assertStringContainsString('disk full', $out['steps']['restore']['error']);
        $this->assertNull($out['steps']['rewrite']);
        clean_post_cache($post);
        $this->assertSame('Live value', get_post($post)->post_title);
        $this->assertSame('failed', $this->tool()->handle(['action' => 'status', 'upload_id' => $upload])['status']);

        // The archive is still on disk: a retry applies it without a re-upload.
        $retry = $this->tool()->handle(['action' => 'apply', 'upload_id' => $upload, 'dry_run' => false, 'confirm' => true]);
        $this->assertSame('migrated', $retry['status'], (string) wp_json_encode($retry));
        clean_post_cache($post);
        $this->assertSame('Untouched', get_post($post)->post_title);
    }

    public function test_an_upload_being_applied_cannot_be_applied_again_concurrently(): void
    {
        $upload = $this->upload($this->source_archive());
        Incoming_Archive_Store::finalize($upload);
        Incoming_Archive_Store::begin_apply($upload);

        $out = $this->tool()->handle(['action' => 'apply', 'upload_id' => $upload, 'dry_run' => false, 'confirm' => true]);

        $this->assertWPError($out);
        $this->assertSame('wpmcp_migration_busy', $out->get_error_code());
        $this->assertSame([], Backup_Job_Store::list());
    }

    public function test_rewrite_pairs_cover_site_url_through_the_home_pair_when_the_layout_matches(): void
    {
        $plan = Receive_Site_Archive::rewrite_pairs(
            ['home_url' => 'https://a.example', 'site_url' => 'https://a.example/wp'],
            'https://b.example',
            'https://b.example/wp'
        );

        $this->assertSame([['from' => 'https://a.example', 'to' => 'https://b.example']], $plan['pairs']);
    }

    public function test_rewrite_pairs_run_longest_from_first_when_the_layout_differs(): void
    {
        $plan = Receive_Site_Archive::rewrite_pairs(
            ['home_url' => 'https://a.example', 'site_url' => 'https://a.example/wp'],
            'https://b.example',
            'https://b.example/core'
        );

        $this->assertSame(
            [
                ['from' => 'https://a.example/wp', 'to' => 'https://b.example/core'],
                ['from' => 'https://a.example', 'to' => 'https://b.example'],
            ],
            $plan['pairs']
        );
    }

    public function test_rewrite_pairs_drop_a_pair_that_would_rewrite_fresh_urls_again(): void
    {
        $plan = Receive_Site_Archive::rewrite_pairs(
            ['home_url' => 'https://a.example', 'site_url' => 'https://a.example/wp'],
            'https://a.example/staging',
            'https://a.example/staging/core'
        );

        $this->assertSame([['from' => 'https://a.example', 'to' => 'https://a.example/staging']], $plan['pairs']);
        $this->assertStringContainsString('Not rewriting https://a.example/wp -> https://a.example/staging/core', $plan['warnings'][0]);
    }

    public function test_rewrite_pairs_report_when_nothing_needs_rewriting(): void
    {
        $plan = Receive_Site_Archive::rewrite_pairs(
            ['home_url' => 'https://a.example/', 'site_url' => 'https://a.example'],
            'https://a.example',
            'https://a.example'
        );

        $this->assertSame([], $plan['pairs']);
        $this->assertNotEmpty($plan['warnings']);
    }
}
