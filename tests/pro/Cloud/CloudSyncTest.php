<?php

namespace WPMCP\Tests\Pro\Cloud;

use WPMCP\Pro\Gate;
use WPMCP\Cloud\Cloud_Config;
use WPMCP\Tools\Cloud\Cloud_Connect;
use WPMCP\Tools\Cloud\Cloud_Status;
use WPMCP\Tools\Cloud\Cloud_List_Assets;
use WPMCP\Tools\Cloud\Cloud_Push_Assets;
use WPMCP\Tools\Cloud\Cloud_Pull_Assets;
use WPMCP\Tools\WidgetBuilder\Widget_Spec_Store;
use WPMCP\Tools\BlockBuilder\Block_Spec_Store;

/**
 * Cloud MVP Phase A: the plugin-side sync client + abilities.
 *
 * The plugin talks to a versioned, backend-agnostic REST contract
 * (/wpmcp-cloud/v1) so the cloud backend can be reimplemented later without any
 * plugin change. HTTP is exercised through WordPress's own pre_http_request
 * filter (no live network), the same way the rest of the suite tests outbound
 * calls. The assets synced are the widget/block builder specs.
 */
class CloudSyncTest extends \WP_UnitTestCase
{
    use FakesCloudHttp;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->start_fake_cloud();
        $this->responder = [$this, 'cloud_routes'];
    }

    protected function tearDown(): void
    {
        $this->stop_fake_cloud();
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /** Canned cloud API responses keyed by path. */
    public function cloud_routes(string $path, string $method, $body): array
    {
        if ('/me' === $path) {
            return self::cloud_json(['account' => ['id' => 'acct_1', 'email' => 'user@example.com', 'plan' => 'free']]);
        }
        if ('/assets' === $path && 'POST' === $method) {
            return self::cloud_json(['asset' => ['id' => 'remote_' . $body['name'], 'type' => $body['type'], 'name' => $body['name']]]);
        }
        if ('/assets' === $path && 'GET' === $method) {
            return self::cloud_json(['assets' => [
                ['id' => 'r1', 'type' => 'widget', 'name' => 'cloud-hero', 'title' => 'Cloud Hero', 'spec' => [
                    'name' => 'cloud-hero', 'title' => 'Cloud Hero',
                    'controls' => [['name' => 'text', 'type' => 'text', 'label' => 'Text']],
                    'template' => '<div>{{text}}</div>',
                ]],
                // Pushed under older, looser widget rules: the control name
                // only became valid through sanitize_key(). Skipped on pull,
                // and the pull result must say which asset and why.
                ['id' => 'r3', 'type' => 'widget', 'name' => 'legacy-card', 'title' => 'Legacy Card', 'spec' => [
                    'name' => 'legacy-card', 'title' => 'Legacy Card',
                    'controls' => [['name' => 'My Heading', 'type' => 'text', 'label' => 'Heading']],
                    'template' => '<h3>{{myheading}}</h3>',
                ]],
                ['id' => 'r2', 'type' => 'block', 'name' => 'wpmcp/cloud-note', 'title' => 'Cloud Note', 'spec' => [
                    'name' => 'cloud-note', 'title' => 'Cloud Note',
                    'attributes' => [['name' => 'body', 'type' => 'string', 'label' => 'Body']],
                    'template' => '<p>{{body}}</p>',
                ]],
            ]]);
        }

        return self::cloud_json([]);
    }

    private function widget_spec(): array
    {
        return [
            'name' => 'local-card', 'title' => 'Local Card',
            'controls' => [['name' => 'heading', 'type' => 'text', 'label' => 'Heading']],
            'template' => '<h3>{{heading}}</h3>',
        ];
    }

    // ---- cloud-connect / cloud-status ---------------------------------------

    public function test_connect_stores_config_and_verifies_account(): void
    {
        delete_option('wpmcp_cloud_url');
        delete_option('wpmcp_cloud_key');

        $out = (new Cloud_Connect())->handle(['url' => 'https://cloud.example', 'key' => 'secret-key']);

        $this->assertIsArray($out);
        $this->assertTrue($out['connected']);
        $this->assertSame('user@example.com', $out['account']['email']);
        $this->assertSame('https://cloud.example', Cloud_Config::base_url());
        $this->assertSame('secret-key', Cloud_Config::api_key());
    }

    public function test_status_reports_connected(): void
    {
        $out = (new Cloud_Status())->handle([]);
        $this->assertTrue($out['connected']);
        $this->assertSame('https://cloud.example', $out['url']);
    }

    public function test_status_reports_disconnected_when_unconfigured(): void
    {
        delete_option('wpmcp_cloud_url');
        delete_option('wpmcp_cloud_key');
        $out = (new Cloud_Status())->handle([]);
        $this->assertFalse($out['connected']);
    }

    // ---- cloud-list-assets --------------------------------------------------

    public function test_list_assets_returns_cloud_assets(): void
    {
        $out    = (new Cloud_List_Assets())->handle([]);
        $names  = array_column($out['assets'], 'name');
        $this->assertContains('cloud-hero', $names);
        $this->assertContains('wpmcp/cloud-note', $names);
    }

    // ---- cloud-push-assets --------------------------------------------------

    public function test_push_sends_local_widget_and_block_specs(): void
    {
        Widget_Spec_Store::create($this->widget_spec());
        Block_Spec_Store::create([
            'name' => 'local-note', 'title' => 'Local Note',
            'attributes' => [['name' => 'body', 'type' => 'string', 'label' => 'Body']],
            'template' => '<p>{{body}}</p>',
        ]);

        $out = (new Cloud_Push_Assets())->handle([]);

        $this->assertSame(2, $out['pushed']);
        $posts = array_filter($this->requests, static fn ($r) => 'POST' === $r['method']);
        $types = array_map(static fn ($r) => $r['body']['type'], $posts);
        $this->assertContains('widget', $types);
        $this->assertContains('block', $types);
    }

    public function test_push_can_filter_by_type(): void
    {
        Widget_Spec_Store::create($this->widget_spec());
        Block_Spec_Store::create([
            'name' => 'b1', 'title' => 'B1',
            'attributes' => [['name' => 'x', 'type' => 'string', 'label' => 'X']],
            'template' => '<p>{{x}}</p>',
        ]);

        $out = (new Cloud_Push_Assets())->handle(['types' => ['widget']]);

        $this->assertSame(1, $out['pushed']);
        $posts = array_filter($this->requests, static fn ($r) => 'POST' === $r['method']);
        $this->assertCount(1, $posts);
        $this->assertSame('widget', array_values($posts)[0]['body']['type']);
    }

    // ---- cloud-pull-assets --------------------------------------------------

    public function test_pull_creates_local_widget_and_block_specs(): void
    {
        $out = (new Cloud_Pull_Assets())->handle([]);

        $this->assertSame(2, $out['pulled']);
        $this->assertCount(1, $out['skipped'], 'a spec the site refuses must be reported, not silently dropped');
        $this->assertSame('legacy-card', $out['skipped'][0]['name']);
        $this->assertSame('widget', $out['skipped'][0]['type']);
        $this->assertStringContainsString('Control names', $out['skipped'][0]['reason']);
        $widgets = array_column(Widget_Spec_Store::all(), 'name');
        $blocks  = array_column(Block_Spec_Store::all(), 'name');
        $this->assertContains('cloud-hero', $widgets);
        $this->assertContains('wpmcp/cloud-note', $blocks);
    }

    public function test_operations_error_when_not_configured(): void
    {
        delete_option('wpmcp_cloud_url');
        delete_option('wpmcp_cloud_key');
        $out = (new Cloud_List_Assets())->handle([]);
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('cloud_not_configured', $out->get_error_code());
    }
}
