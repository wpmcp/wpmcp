<?php

namespace WPMCP\Tests\Free\Migration;

use WPMCP\Tests\Free\Backup\RestoreArchiveFixtures;
use WPMCP\Tools\Backup\Backup_Job_Store;
use WPMCP\Tools\Backup\Site_Backup_Dir;
use WPMCP\Tools\Migration\Incoming_Archive_Store;
use WPMCP\Tools\Migration\Migration_Target_Client;
use WPMCP\Tools\Migration\Push_Site_Archive;

/**
 * The source half of a site-to-site push (issue #191, phase 2), end to end.
 *
 * The "target" is this same test site: every outbound request the push
 * makes is intercepted at pre_http_request and dispatched through the real
 * core Abilities REST run controller (rest_do_request), so the wire shape
 * (route, method, {"input": ...} body), the ability's permission callback,
 * input-schema validation and the receive ability itself are all the real
 * thing. Only the network hop and the Authorization header check (which
 * core performs before routing) are simulated; the header the push sends
 * is recorded and asserted.
 */
class PushSiteArchiveTest extends \WP_UnitTestCase
{
    use RestoreArchiveFixtures;

    private const SOURCE = 'https://old.example';
    private const TARGET = 'https://target.example';

    /** @var array<int, array{route: string, input: array, authorization: string}> */
    private array $requests = [];

    /** @var callable|null Returns a WP_Error to fail a request, or null. */
    private $fault = null;

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Backup_Job_Store::OPTION);
        delete_option('wpmcp_maintenance');
        add_filter('wpmcp_accept_incoming_migrations', '__return_true');
        add_filter('wpmcp_allow_outgoing_migrations', '__return_true');
        add_filter('wpmcp_rate_limit_enabled', '__return_false');
        add_filter('pre_http_request', [$this, 'route_to_this_site'], 10, 3);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'route_to_this_site'], 10);
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
        remove_all_filters('wpmcp_allow_outgoing_migrations');
        remove_all_filters('wpmcp_rate_limit_enabled');
        parent::tearDown();
    }

    /**
     * @param false|array $pre
     * @return array|\WP_Error
     */
    public function route_to_this_site($pre, array $args, string $url)
    {
        $this->assertStringStartsWith(self::TARGET . '/', $url, 'The push must contact only the target it was given.');
        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(Migration_Target_Client::ROUTE, $query['rest_route'] ?? null);
        $this->assertSame('POST', $args['method']);

        $body  = json_decode((string) $args['body'], true);
        $input = (array) ($body['input'] ?? []);
        $this->requests[] = [
            'route'         => (string) $query['rest_route'],
            'input'         => $input,
            'authorization' => (string) ($args['headers']['Authorization'] ?? ''),
        ];

        if (null !== $this->fault) {
            $error = ($this->fault)($input, count($this->requests));
            if (null !== $error) {
                return $error;
            }
        }

        $request = new \WP_REST_Request('POST', (string) $query['rest_route']);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) $args['body']);
        $response = rest_do_request($request);
        $data     = rest_get_server()->response_to_data($response, false);

        return [
            'headers'  => [],
            'body'     => (string) wp_json_encode($data),
            'response' => ['code' => $response->get_status(), 'message' => ''],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    private function source_archive(): string
    {
        global $wpdb;

        // Incompressible filler so the zip spans many small chunks.
        self::factory()->post->create(['post_title' => 'Filler', 'post_content' => wp_generate_password(24000, false)]);

        return $this->archive_of([$wpdb->posts, $wpdb->postmeta], [
            'site.home_url' => self::SOURCE,
            'site.site_url' => self::SOURCE,
        ]);
    }

    private function args(string $archive, array $extra = []): array
    {
        return array_merge([
            'path'                => $archive,
            'target_url'          => self::TARGET,
            'target_user'         => 'admin',
            'target_app_password' => 'abcd efgh ijkl mnop',
        ], $extra);
    }

    /** @return array<string, mixed> */
    private function push(array $args, ?callable $clock = null): array
    {
        $out = (new Push_Site_Archive($clock))->handle($args);
        $this->assertIsArray($out, is_wp_error($out) ? $out->get_error_message() : '');

        return $out;
    }

    public function test_nothing_is_sent_while_the_outgoing_gate_is_closed(): void
    {
        remove_all_filters('wpmcp_allow_outgoing_migrations');

        $out = (new Push_Site_Archive())->handle($this->args($this->source_archive()));

        $this->assertWPError($out);
        $this->assertSame('wpmcp_migration_send_disabled', $out->get_error_code());
        $this->assertSame([], $this->requests);
    }

    public function test_a_plain_http_target_is_refused(): void
    {
        $out = (new Push_Site_Archive())->handle($this->args($this->source_archive(), ['target_url' => 'http://target.example']));

        $this->assertWPError($out);
        $this->assertStringContainsString('https', $out->get_error_message());
        $this->assertSame([], $this->requests);
    }

    public function test_credentials_are_required(): void
    {
        $out = (new Push_Site_Archive())->handle($this->args($this->source_archive(), ['target_app_password' => '']));

        $this->assertWPError($out);
        $this->assertStringContainsString('target_app_password', $out->get_error_message());
        $this->assertSame([], $this->requests);
    }

    public function test_sending_requires_confirm(): void
    {
        $out = (new Push_Site_Archive())->handle($this->args($this->source_archive(), ['dry_run' => false]));

        $this->assertWPError($out);
        $this->assertStringContainsString('confirm:true', $out->get_error_message());
        $this->assertSame([], $this->requests);
    }

    public function test_the_default_dry_run_asks_the_target_and_uploads_nothing(): void
    {
        $out = $this->push($this->args($this->source_archive()));

        $this->assertSame('ready', $out['status']);
        $this->assertTrue($out['dry_run']);
        $this->assertSame(self::SOURCE, $out['rewrite']['pairs'][0]['from']);
        $this->assertCount(1, $this->requests);
        $this->assertSame('start', $this->requests[0]['input']['action']);
        $this->assertSame('Basic ' . base64_encode('admin:abcd efgh ijkl mnop'), $this->requests[0]['authorization']);
        $this->assertSame([], glob(Incoming_Archive_Store::dir() . '/*.json'));
    }

    public function test_a_bearer_token_is_sent_when_given(): void
    {
        $this->push($this->args($this->source_archive(), ['target_user' => '', 'target_app_password' => '', 'target_token' => 'tok123']));

        $this->assertSame('Bearer tok123', $this->requests[0]['authorization']);
    }

    public function test_an_incompatible_target_refuses_before_any_chunk(): void
    {
        global $wpdb;

        $archive = $this->archive_of([$wpdb->posts], ['site.home_url' => self::SOURCE, 'site.site_url' => self::SOURCE, 'site.table_prefix' => 'zz_']);

        $out = (new Push_Site_Archive())->handle($this->args($archive, ['dry_run' => false, 'confirm' => true]));

        $this->assertWPError($out);
        $this->assertStringContainsString('Table prefix mismatch', $out->get_error_message());
        $this->assertCount(1, $this->requests);
    }

    /**
     * The whole phase-2 flow over the real REST controller: upload in
     * several chunks, then the target verifies, restores behind a safety
     * archive and rewrites the source URLs to its own.
     */
    public function test_a_full_push_with_apply_migrates_the_archive(): void
    {
        global $wpdb;

        $target = untrailingslashit((string) get_option('home'));
        $post   = self::factory()->post->create(['post_title' => 'From the source', 'post_content' => 'x']);
        $wpdb->update($wpdb->posts, ['post_content' => '<a href="' . self::SOURCE . '/about/">About</a>'], ['ID' => $post]);
        $archive = $this->source_archive();
        wp_update_post(['ID' => $post, 'post_title' => 'Target before the move']);

        $out = $this->push($this->args($archive, ['dry_run' => false, 'confirm' => true, 'apply' => true, 'chunk_bytes' => 2048]));

        $this->assertSame('migrated', $out['status'], (string) wp_json_encode($out));
        $this->assertSame(filesize($archive), $out['received_bytes']);
        $this->assertGreaterThan(1, $out['chunks_sent']);
        $this->assertSame('restored', $out['target_result']['steps']['restore']['status']);
        $this->assertIsInt($out['safety_archive']['job_id']);
        $this->assertStringStartsWith('On the target: ', $out['undo']);

        clean_post_cache($post);
        wp_cache_flush();
        $this->assertSame('From the source', get_post($post)->post_title);
        $this->assertSame('<a href="' . $target . '/about/">About</a>', get_post($post)->post_content);

        foreach ($this->requests as $request) {
            $this->assertStringStartsWith('Basic ', $request['authorization'], 'Every call to the target is authenticated.');
        }

        // Running the same push again reports the earlier outcome instead
        // of restoring a second time.
        $again = $this->push($this->args($archive, ['dry_run' => false, 'confirm' => true, 'apply' => true, 'chunk_bytes' => 2048]));
        $this->assertSame('already_applied', $again['status']);
        $this->assertSame('migrated', $again['target_result']['status']);
    }

    public function test_the_time_budget_stops_the_upload_and_the_next_call_resumes_it(): void
    {
        $archive = $this->source_archive();
        $now     = 1000.0;
        $clock   = static function () use (&$now): float {
            $now += 10.0;
            return $now;
        };

        $first = $this->push($this->args($archive, ['dry_run' => false, 'confirm' => true, 'chunk_bytes' => 1024, 'max_seconds' => 25]), $clock);

        $this->assertSame('uploading', $first['status']);
        $this->assertTrue($first['resumable']);
        $this->assertGreaterThan(0, $first['received_bytes']);
        $this->assertLessThan(filesize($archive), $first['received_bytes']);

        $second = $this->push($this->args($archive, ['dry_run' => false, 'confirm' => true, 'chunk_bytes' => 65536]));

        $this->assertTrue($second['resumed']);
        $this->assertSame($first['upload_id'], $second['upload_id']);
        $this->assertSame('uploaded', $second['status'], (string) wp_json_encode($second));
        $this->assertSame(filesize($archive), $second['received_bytes']);
        $this->assertTrue($second['target_result']['dry_run']);
        $this->assertTrue($second['target_result']['steps']['verify']['ok']);
        $this->assertSame([], Backup_Job_Store::list(), 'Without apply nothing is restored.');
    }

    public function test_a_lost_response_is_resynced_and_the_upload_continues(): void
    {
        $archive = $this->source_archive();
        $failed  = false;
        // The second chunk reaches the target (it is dispatched) but its
        // response is lost; the push must re-sync from status, not resend
        // from a stale offset or give up.
        $this->fault = function (array $input) use (&$failed) {
            if ('chunk' === ($input['action'] ?? '') && ! $failed && $input['offset'] > 0) {
                $failed = true;
                $request = new \WP_REST_Request('POST', Migration_Target_Client::ROUTE);
                $request->set_header('Content-Type', 'application/json');
                $request->set_body((string) wp_json_encode(['input' => $input]));
                rest_do_request($request);
                return new \WP_Error('http_request_failed', 'Operation timed out');
            }
            return null;
        };

        $out = $this->push($this->args($archive, ['dry_run' => false, 'confirm' => true, 'chunk_bytes' => 2048]));

        $this->assertTrue($failed);
        $this->assertSame('uploaded', $out['status'], (string) wp_json_encode($out));
        $this->assertTrue($out['target_result']['steps']['verify']['ok'], 'The resynced upload must still hash correctly.');
        $this->assertContains('status', array_column(array_column($this->requests, 'input'), 'action'));
    }

    public function test_a_target_that_stays_unreachable_leaves_a_resumable_upload(): void
    {
        $archive     = $this->source_archive();
        $this->fault = static function (array $input) {
            return in_array($input['action'] ?? '', ['chunk', 'status'], true) && ($input['offset'] ?? 1) !== 0
                ? new \WP_Error('http_request_failed', 'Connection refused')
                : null;
        };

        $out = $this->push($this->args($archive, ['dry_run' => false, 'confirm' => true, 'chunk_bytes' => 1024]));

        $this->assertSame('interrupted', $out['status']);
        $this->assertTrue($out['resumable']);
        $this->assertStringContainsString('Connection refused', $out['error']);

        $this->fault = null;
        $resumed = $this->push($this->args($archive, ['dry_run' => false, 'confirm' => true, 'chunk_bytes' => 65536]));
        $this->assertSame('uploaded', $resumed['status']);
        $this->assertSame($out['upload_id'], $resumed['upload_id']);
    }
}
