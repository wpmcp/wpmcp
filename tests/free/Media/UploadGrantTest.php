<?php

namespace WPMCP\Tests\Free\Media;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\MCP\Transport_Guard;
use WPMCP\Plugin;
use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Media\Upload_Grant;
use WPMCP\Tools\Media\Upload_Media;

/**
 * upload-media grant:true (issue #471): the tool hands back a single-use,
 * short-lived upload URL bound to the calling user, and the file bytes go
 * straight to a REST route instead of through the model. Abuse cases each
 * have a test: reuse, sharing with another account, expiry, a user who lost
 * upload_files, oversized and partial bodies, wrong types, and temp files
 * left on disk after any refusal.
 */
class UploadGrantTest extends \WP_UnitTestCase
{
    private int $admin = 0;

    private string $temp_dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        delete_option(Governance_Audit_Log::OPTION);
        Upload_Grant::set_clock_for_tests(null);

        $this->admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin);

        $this->temp_dir = trailingslashit(get_temp_dir()) . 'wpmcp-grant-test-' . wp_generate_password(8, false) . '/';
        wp_mkdir_p($this->temp_dir);
        add_filter('wpmcp_upload_media_temp_dir', [$this, 'temp_dir']);

        do_action('rest_api_init', rest_get_server());
    }

    protected function tearDown(): void
    {
        Upload_Grant::set_clock_for_tests(null);
        remove_filter('wpmcp_upload_media_temp_dir', [$this, 'temp_dir']);
        foreach ((array) glob($this->temp_dir . '*') as $file) {
            @unlink((string) $file); // phpcs:ignore
        }
        @rmdir($this->temp_dir); // phpcs:ignore
        parent::tearDown();
    }

    public function temp_dir(): string
    {
        return $this->temp_dir;
    }

    private function pdf(): string
    {
        return (string) file_get_contents(DIR_TESTDATA . '/images/wordpress-gsoc-flyer.pdf');
    }

    private function grant(array $args = []): array
    {
        return (new Upload_Media())->handle($args + ['grant' => true, 'filename' => 'brochure.pdf']);
    }

    /** Send the bytes to the grant's URL as an anonymous client, the way curl -T does. */
    private function put(array $grant, string $body, array $headers = [], string $method = 'PUT'): \WP_REST_Response
    {
        $parts = wp_parse_url($grant['upload_url']);
        $path  = (string) ($parts['path'] ?? '');
        $route = substr($path, (int) strpos($path, '/wpmcp/v1/'));
        if (! empty($parts['query'])) {
            parse_str((string) $parts['query'], $query);
            if (isset($query['rest_route'])) {
                $route = (string) $query['rest_route'];
            }
        }

        $request = new \WP_REST_Request($method, $route);
        $request->set_body($body);
        $request->set_header('Content-Type', 'application/octet-stream');
        $request->set_header('Content-Length', (string) strlen($body));
        foreach ($headers as $name => $value) {
            $request->set_header($name, $value);
        }

        return rest_get_server()->dispatch($request);
    }

    private function attachment_count(): int
    {
        return count(get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1]));
    }

    private function grant_rows(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like(Upload_Grant::OPTION_PREFIX) . '%'));
    }

    /** @return string[] audit reasons recorded for upload-media, oldest first. */
    private function audit_reasons(): array
    {
        $rows = array_filter(Governance_Audit_Log::list(), static fn($row) => 'wpmcp/upload-media' === ($row['ability'] ?? ''));
        return array_reverse(array_values(array_map(static fn($row) => (string) $row['reason'], $rows)));
    }

    private function assert_nothing_left(): void
    {
        $this->assertSame(0, $this->attachment_count(), 'A refused upload must store no attachment.');
        $this->assertSame([], (array) glob($this->temp_dir . '*'), 'A refused upload must leave no temp file.');
    }

    public function test_grant_returns_a_short_lived_upload_url_and_curl_command_without_storing_anything(): void
    {
        $out = $this->grant();

        $this->assertStringContainsString('/wpmcp/v1/media-upload/', $out['upload_url']);
        $this->assertSame('PUT', $out['method']);
        $this->assertGreaterThan(0, $out['expires_in']);
        $this->assertLessThanOrEqual(900, $out['expires_in']);
        $this->assertNotEmpty($out['expires_at']);
        $this->assertSame(Upload_Media::max_bytes(), $out['max_bytes']);
        $this->assertStringContainsString('curl', $out['curl']);
        $this->assertStringContainsString('-T', $out['curl']);
        $this->assertStringContainsString('brochure.pdf', $out['curl']);
        $this->assertStringContainsString($out['upload_url'], $out['curl']);

        $this->assertSame(0, $this->attachment_count());
        $this->assertSame(['upload-grant:issued'], $this->audit_reasons());
    }

    public function test_the_secret_token_is_not_stored_in_the_database(): void
    {
        global $wpdb;
        $out   = $this->grant();
        $this->assertSame(1, preg_match('#/media-upload/([a-f0-9]{48})#', $out['upload_url'], $m));
        $token = $m[1];

        $hits = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_value LIKE %s",
            '%' . $wpdb->esc_like($token) . '%',
            '%' . $wpdb->esc_like($token) . '%'
        ));
        $this->assertSame(0, $hits);
    }

    public function test_one_put_creates_the_attachment_as_the_granting_user(): void
    {
        $parent = self::factory()->post->create();
        $grant  = $this->grant(['title' => 'Spring brochure', 'alt' => 'Cover', 'caption' => 'Cap', 'post_id' => $parent]);

        wp_set_current_user(0);
        $response = $this->put($grant, $this->pdf());

        $this->assertSame(201, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $data = $response->get_data();
        $post = get_post($data['media_id']);
        $this->assertSame('application/pdf', $post->post_mime_type);
        $this->assertSame($this->admin, (int) $post->post_author);
        $this->assertSame('Spring brochure', $post->post_title);
        $this->assertSame('Cap', $post->post_excerpt);
        $this->assertSame($parent, (int) $post->post_parent);
        $this->assertSame($this->pdf(), file_get_contents((string) get_attached_file($data['media_id'])));
        $this->assertNotEmpty($data['operation_id']);
        $this->assertSame(0, get_current_user_id(), 'The handler must restore the request user.');

        $this->assertSame(['upload-grant:issued', 'upload-grant:used'], $this->audit_reasons());
        $this->assertSame([], (array) glob($this->temp_dir . '*'));
    }

    public function test_an_mp4_uploads_through_a_grant(): void
    {
        $video = (string) file_get_contents(DIR_TESTDATA . '/uploads/small-video.mp4');
        $grant = $this->grant(['filename' => 'clip.mp4']);

        wp_set_current_user(0);
        $response = $this->put($grant, $video, [], 'POST');

        $this->assertSame(201, $response->get_status());
        $this->assertSame('video/mp4', get_post_mime_type($response->get_data()['media_id']));
    }

    public function test_a_grant_upload_is_undone_by_rollback_operation(): void
    {
        $grant = $this->grant();
        wp_set_current_user(0);
        $data = $this->put($grant, $this->pdf())->get_data();
        $file = (string) get_attached_file($data['media_id']);
        $this->assertFileExists($file);

        wp_set_current_user($this->admin);
        $this->assertTrue(Rollback_Service::restore_operation($data['operation_id']));
        $this->assertNull(get_post($data['media_id']));
        $this->assertFileDoesNotExist($file);
    }

    public function test_a_grant_accepts_exactly_one_file(): void
    {
        $grant = $this->grant();
        wp_set_current_user(0);

        $this->assertSame(201, $this->put($grant, $this->pdf())->get_status());
        $again = $this->put($grant, $this->pdf());

        $this->assertSame(404, $again->get_status());
        $this->assertSame(1, $this->attachment_count());
        $this->assertSame(0, $this->grant_rows(), 'A used grant must not stay in the database.');
    }

    public function test_a_failed_attempt_also_spends_the_grant(): void
    {
        $grant = $this->grant();
        wp_set_current_user(0);

        $this->assertSame(400, $this->put($grant, "<?php echo 1; ?>\n")->get_status());
        $this->assertSame(404, $this->put($grant, $this->pdf())->get_status());
        $this->assert_nothing_left();
    }

    public function test_an_expired_grant_is_refused(): void
    {
        Upload_Grant::set_clock_for_tests(1_000_000);
        $grant = $this->grant();

        Upload_Grant::set_clock_for_tests(1_000_000 + $grant['expires_in'] + 1);
        wp_set_current_user(0);
        $response = $this->put($grant, $this->pdf());

        $this->assertSame(410, $response->get_status());
        $this->assert_nothing_left();
        $this->assertContains('upload-grant:expired', $this->audit_reasons());
    }

    public function test_the_lifetime_filter_is_clamped_to_a_short_window(): void
    {
        $long = static fn(): int => DAY_IN_SECONDS;
        add_filter('wpmcp_upload_grant_ttl', $long);
        try {
            $this->assertSame(900, $this->grant()['expires_in']);
        } finally {
            remove_filter('wpmcp_upload_grant_ttl', $long);
        }
    }

    public function test_another_signed_in_user_cannot_use_the_grant_and_it_is_burned(): void
    {
        $grant = $this->grant();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $response = $this->put($grant, $this->pdf());
        $this->assertSame(403, $response->get_status());

        wp_set_current_user(0);
        $this->assertSame(404, $this->put($grant, $this->pdf())->get_status(), 'A shared link that another account touched must be dead.');
        $this->assert_nothing_left();
        $this->assertContains('upload-grant:wrong-user', $this->audit_reasons());
    }

    public function test_a_user_who_lost_upload_files_cannot_use_their_grant(): void
    {
        $grant = $this->grant();
        (new \WP_User($this->admin))->set_role('subscriber');

        wp_set_current_user(0);
        $this->assertSame(403, $this->put($grant, $this->pdf())->get_status());
        $this->assert_nothing_left();
    }

    public function test_issuing_a_grant_needs_a_user_who_can_upload(): void
    {
        wp_set_current_user(0);
        try {
            $this->grant();
            $this->fail('Expected an anonymous grant to be refused.');
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            $this->assertSame(0, $this->grant_rows());
        }

        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->expectException(\RuntimeException::class);
        $this->grant();
    }

    public function test_blocked_names_are_refused_when_the_grant_is_issued(): void
    {
        foreach (['shell.php', 'shell.php.pdf', 'page.html', 'icon.svg', 'noext'] as $name) {
            try {
                $this->grant(['filename' => $name]);
                $this->fail("Expected a grant for {$name} to be refused.");
            } catch (\InvalidArgumentException $e) {
                // Expected.
            }
        }
        $this->assertSame(0, $this->grant_rows());
    }

    public function test_declared_size_over_the_limit_is_refused_before_the_body_is_used(): void
    {
        $grant = $this->grant();
        wp_set_current_user(0);

        $response = $this->put($grant, $this->pdf(), ['Content-Length' => (string) (Upload_Media::max_bytes() + 1)]);
        $this->assertSame(413, $response->get_status());
        $this->assert_nothing_left();
    }

    public function test_a_body_over_the_limit_is_refused(): void
    {
        $cap = static fn(): int => 1000;
        add_filter('wpmcp_upload_media_max_bytes', $cap);
        try {
            $grant = $this->grant();
            $this->assertSame(1000, $grant['max_bytes']);
            wp_set_current_user(0);
            $this->assertSame(413, $this->put($grant, $this->pdf(), ['Content-Length' => ''])->get_status());
        } finally {
            remove_filter('wpmcp_upload_media_max_bytes', $cap);
        }
        $this->assert_nothing_left();
    }

    public function test_remaining_upload_space_caps_the_grant(): void
    {
        // Multisite folds the site's remaining quota into wp_max_upload_size()
        // through upload_size_limit; checked again when the bytes arrive, so
        // space used up after the grant was issued still counts.
        $grant = $this->grant();
        $quota = static fn(): int => 2048;
        add_filter('upload_size_limit', $quota);
        try {
            wp_set_current_user(0);
            $this->assertSame(413, $this->put($grant, $this->pdf())->get_status());
        } finally {
            remove_filter('upload_size_limit', $quota);
        }
        $this->assert_nothing_left();
    }

    public function test_a_partial_body_is_refused_and_cleaned_up(): void
    {
        $grant = $this->grant();
        wp_set_current_user(0);

        $pdf      = $this->pdf();
        $response = $this->put($grant, substr($pdf, 0, 500), ['Content-Length' => (string) strlen($pdf)]);

        $this->assertSame(400, $response->get_status());
        $this->assertStringContainsString('incomplete', strtolower((string) $response->get_data()['message']));
        $this->assert_nothing_left();
    }

    public function test_empty_and_multipart_bodies_are_refused(): void
    {
        $grant = $this->grant();
        wp_set_current_user(0);
        $this->assertSame(400, $this->put($grant, '')->get_status());

        $grant = $this->grant();
        wp_set_current_user(0);
        $this->assertSame(415, $this->put($grant, $this->pdf(), ['Content-Type' => 'multipart/form-data; boundary=x'])->get_status());
        $this->assert_nothing_left();
    }

    public function test_bytes_that_do_not_match_the_granted_type_are_refused(): void
    {
        $grant = $this->grant(['filename' => 'photo.jpg']);
        wp_set_current_user(0);

        $response = $this->put($grant, "<?php system(\$_GET['c']); ?>\n");
        $this->assertSame(400, $response->get_status());
        $this->assert_nothing_left();
        $this->assertContains('upload-grant:rejected', $this->audit_reasons());
    }

    public function test_unknown_tokens_are_refused_without_filling_the_audit_log(): void
    {
        $grant = $this->grant();
        $fake  = preg_replace('#/[a-f0-9]{48}#', '/' . str_repeat('a', 48), $grant['upload_url']);
        $before = count(Governance_Audit_Log::list());

        wp_set_current_user(0);
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(404, $this->put(['upload_url' => $fake], $this->pdf())->get_status());
        }

        $this->assertSame($before, count(Governance_Audit_Log::list()));
        $this->assert_nothing_left();
    }

    public function test_expired_grants_are_purged_when_a_new_one_is_issued(): void
    {
        Upload_Grant::set_clock_for_tests(1_000_000);
        $this->grant();
        $this->grant();
        $this->assertSame(2, $this->grant_rows());

        Upload_Grant::set_clock_for_tests(1_000_000 + 901);
        $this->grant();
        $this->assertSame(1, $this->grant_rows());
    }

    public function test_route_is_registered_and_transport_guarded(): void
    {
        $routes = rest_get_server()->get_routes();
        $this->assertNotEmpty(array_filter(array_keys($routes), static fn($r) => str_starts_with($r, '/wpmcp/v1/media-upload/')));
        $this->assertTrue(Transport_Guard::is_guarded_route('/wpmcp/v1/media-upload/' . str_repeat('a', 48)));
    }

    public function test_schema_advertises_url_and_grant_without_requiring_data(): void
    {
        $ability = Plugin::instance()->registrar()->get('wpmcp/upload-media');
        $this->assertNotNull($ability);
        $this->assertSame('string', $ability->input_schema['properties']['url']['type'] ?? null);
        $this->assertSame('boolean', $ability->input_schema['properties']['grant']['type'] ?? null);
        $this->assertNotContains('data', $ability->input_schema['required'] ?? []);
    }

    public function test_base64_uploads_still_work(): void
    {
        $out = (new Upload_Media())->handle([
            'filename' => 'pixel.png',
            'data'     => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
        ]);
        $this->assertSame('image/png', get_post_mime_type($out['media_id']));
    }
}
