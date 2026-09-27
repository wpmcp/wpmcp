<?php

namespace WPMCP\Tests\Pro\Cloud;

use WPMCP\Pro\Gate;
use WPMCP\Tools\BlockBuilder\Block_Spec_Store;
use WPMCP\Tools\Cloud\Cloud_Marketplace_Browse;
use WPMCP\Tools\Cloud\Cloud_Marketplace_Install;
use WPMCP\Tools\WidgetBuilder\Widget_Spec_Store;

/**
 * Cloud phase B (issue #135): marketplace browse and install.
 *
 * Installs land as INACTIVE drafts and only after the spec passes the same
 * validators validate-widget-spec / validate-block-spec run, the gate
 * cloud-pull-assets already enforces. HTTP is faked through pre_http_request
 * like the rest of the cloud suite, so no live network is involved.
 */
class CloudMarketplaceTest extends \WP_UnitTestCase
{
    /** @var array<int,array{url:string,method:string,body:mixed,headers:array}> */
    private array $requests = [];

    /** @var array<string,mixed> path suffix => response body (or [code, body]) */
    private array $routes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option('wpmcp_cloud_url', 'https://cloud.example');
        update_option('wpmcp_cloud_key', 'secret-key');
        $this->requests = [];
        $this->routes   = [];
        add_filter('pre_http_request', [$this, 'fake_http'], 10, 3);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'fake_http'], 10);
        delete_option('wpmcp_cloud_url');
        delete_option('wpmcp_cloud_key');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    public function fake_http($pre, $args, $url)
    {
        $body = $args['body'] ?? null;
        $this->requests[] = [
            'url'     => $url,
            'method'  => strtoupper((string) ($args['method'] ?? 'GET')),
            'body'    => is_string($body) ? json_decode($body, true) : $body,
            'headers' => $args['headers'] ?? [],
        ];

        $path = (string) substr($url, strlen('https://cloud.example/wpmcp-cloud/v1'));
        $hit  = $this->routes[ $path ] ?? ['code' => 404, 'body' => ['message' => 'not found']];
        $code = $hit['code'] ?? 200;
        $data = $hit['body'] ?? $hit;

        return [
            'headers'  => [],
            'body'     => wp_json_encode($data),
            'response' => ['code' => $code, 'message' => 'OK'],
        ];
    }

    private static function widget_listing(array $spec_overrides = [], array $listing_overrides = []): array
    {
        return array_merge([
            'slug'        => 'hero-banner',
            'type'        => 'widget',
            'title'       => 'Hero Banner',
            'description' => 'A hero banner.',
            'author'      => 'Acme',
            'version'     => '1.2.0',
            'spec'        => array_merge([
                'name'     => 'hero-banner',
                'title'    => 'Hero Banner',
                'controls' => [['name' => 'heading', 'type' => 'text', 'label' => 'Heading']],
                'template' => '<h2>{{heading}}</h2>',
            ], $spec_overrides),
        ], $listing_overrides);
    }

    private static function block_listing(array $spec_overrides = []): array
    {
        return [
            'slug'    => 'callout',
            'type'    => 'block',
            'title'   => 'Callout',
            'version' => '0.1.0',
            'spec'    => array_merge([
                'name'       => 'callout',
                'title'      => 'Callout',
                'attributes' => [['name' => 'body', 'type' => 'string', 'label' => 'Body']],
                'template'   => '<p>{{body}}</p>',
            ], $spec_overrides),
        ];
    }

    // ---- browse ------------------------------------------------------------

    public function test_browse_lists_listings_without_their_specs(): void
    {
        $this->routes['/marketplace'] = ['listings' => [
            self::widget_listing(),
            self::block_listing(),
            'garbage',
            ['type' => 'widget'],
        ]];

        $out = (new Cloud_Marketplace_Browse())->handle([]);

        $this->assertNotInstanceOf(\WP_Error::class, $out);
        $this->assertSame(2, $out['count']);
        $this->assertSame('hero-banner', $out['listings'][0]['slug']);
        $this->assertSame('widget', $out['listings'][0]['type']);
        $this->assertSame('1.2.0', $out['listings'][0]['version']);
        $this->assertArrayNotHasKey('spec', $out['listings'][0]);
        $this->assertSame('GET', $this->requests[0]['method']);
        $this->assertSame('Bearer secret-key', $this->requests[0]['headers']['Authorization']);
    }

    public function test_browse_forwards_a_type_filter_and_search_term(): void
    {
        $this->routes['/marketplace?type=block&search=call%20out'] = ['listings' => [self::block_listing()]];

        $out = (new Cloud_Marketplace_Browse())->handle(['type' => 'block', 'search' => 'call out']);

        $this->assertSame(1, $out['count']);
        $this->assertStringEndsWith('/marketplace?type=block&search=call%20out', $this->requests[0]['url']);
    }

    public function test_browse_rejects_an_unknown_type(): void
    {
        $out = (new Cloud_Marketplace_Browse())->handle(['type' => 'plugin']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_type', $out->get_error_code());
        $this->assertSame([], $this->requests);
    }

    public function test_browse_requires_a_configured_cloud(): void
    {
        delete_option('wpmcp_cloud_key');

        $out = (new Cloud_Marketplace_Browse())->handle([]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('cloud_not_configured', $out->get_error_code());
    }

    // ---- install -----------------------------------------------------------

    public function test_install_lands_a_widget_as_an_inactive_draft(): void
    {
        $this->routes['/marketplace/hero-banner'] = ['listing' => self::widget_listing()];

        $out = (new Cloud_Marketplace_Install())->handle(['slug' => 'hero-banner']);

        $this->assertNotInstanceOf(\WP_Error::class, $out);
        $this->assertSame('widget', $out['type']);
        $this->assertSame('draft', $out['status']);
        $this->assertSame('draft', get_post_status($out['id']));
        $this->assertTrue(Widget_Spec_Store::is_widget($out['id']));
        $this->assertSame('hero-banner', Widget_Spec_Store::get($out['id'])['name']);
        $this->assertSame([], Widget_Spec_Store::all(true), 'nothing is active after install');
        $this->assertSame(
            ['slug' => 'hero-banner', 'version' => '1.2.0'],
            array_intersect_key(get_post_meta($out['id'], '_wpmcp_marketplace_source', true), ['slug' => 1, 'version' => 1])
        );
    }

    public function test_install_lands_a_block_as_an_inactive_draft(): void
    {
        $this->routes['/marketplace/callout'] = ['listing' => self::block_listing()];

        $out = (new Cloud_Marketplace_Install())->handle(['slug' => 'callout']);

        $this->assertNotInstanceOf(\WP_Error::class, $out);
        $this->assertSame('block', $out['type']);
        $this->assertSame('draft', get_post_status($out['id']));
        $this->assertSame('wpmcp/callout', Block_Spec_Store::get($out['id'])['name']);
        $this->assertSame([], Block_Spec_Store::all(true));
    }

    public function test_install_refuses_a_spec_that_fails_the_widget_validator(): void
    {
        $this->routes['/marketplace/hero-banner'] = ['listing' => self::widget_listing(['controls' => [['name' => 'x', 'type' => 'php', 'label' => 'X']]])];

        $out = (new Cloud_Marketplace_Install())->handle(['slug' => 'hero-banner']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('marketplace_invalid_spec', $out->get_error_code());
        $this->assertSame([], Widget_Spec_Store::all());
    }

    public function test_install_refuses_a_spec_that_fails_the_block_validator(): void
    {
        $this->routes['/marketplace/callout'] = ['listing' => self::block_listing(['template' => ''])];

        $out = (new Cloud_Marketplace_Install())->handle(['slug' => 'callout']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('marketplace_invalid_spec', $out->get_error_code());
        $this->assertSame([], Block_Spec_Store::all());
    }

    public function test_install_refuses_an_unknown_listing_type(): void
    {
        $this->routes['/marketplace/hero-banner'] = ['listing' => self::widget_listing([], ['type' => 'plugin'])];

        $out = (new Cloud_Marketplace_Install())->handle(['slug' => 'hero-banner']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('marketplace_invalid_listing', $out->get_error_code());
    }

    public function test_install_refuses_a_listing_whose_slug_does_not_match_the_request(): void
    {
        $this->routes['/marketplace/hero-banner'] = ['listing' => self::widget_listing([], ['slug' => 'something-else'])];

        $out = (new Cloud_Marketplace_Install())->handle(['slug' => 'hero-banner']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('marketplace_invalid_listing', $out->get_error_code());
    }

    /**
     * The slug is interpolated into the request path, so it must not be able
     * to point the authenticated request at another cloud endpoint.
     *
     * @dataProvider hostile_slugs
     */
    public function test_install_rejects_a_slug_that_is_not_a_plain_slug(string $slug): void
    {
        $out = (new Cloud_Marketplace_Install())->handle(['slug' => $slug]);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('invalid_slug', $out->get_error_code());
        $this->assertSame([], $this->requests);
    }

    /** @return array<string,array{0:string}> */
    public static function hostile_slugs(): array
    {
        return [
            'empty'     => [''],
            'traversal' => ['../me'],
            'query'     => ['x?admin=1'],
            'slash'     => ['a/b'],
            'upper'     => ['Hero'],
            'too long'  => [str_repeat('a', 101)],
        ];
    }

    public function test_install_refuses_a_name_that_already_exists_locally(): void
    {
        Widget_Spec_Store::create(self::widget_listing()['spec']);
        $this->routes['/marketplace/hero-banner'] = ['listing' => self::widget_listing()];

        $out = (new Cloud_Marketplace_Install())->handle(['slug' => 'hero-banner']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('marketplace_name_taken', $out->get_error_code());
        $this->assertCount(1, Widget_Spec_Store::all());
    }

    /**
     * A listing is third-party markup. It goes through wp_kses_post even for
     * an administrator who holds unfiltered_html, because nobody on this site
     * wrote it.
     */
    public function test_install_filters_listing_markup_even_for_unfiltered_html_users(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        grant_super_admin($admin);
        wp_set_current_user($admin);
        $this->assertTrue(current_user_can('unfiltered_html'));

        $this->routes['/marketplace/hero-banner'] = ['listing' => self::widget_listing(['template' => '<h2>{{heading}}</h2><script>alert(1)</script>'])];
        $this->routes['/marketplace/callout']     = ['listing' => self::block_listing(['template' => '<p onclick="x()">{{body}}</p><script>alert(2)</script>'])];

        $widget = (new Cloud_Marketplace_Install())->handle(['slug' => 'hero-banner']);
        $block  = (new Cloud_Marketplace_Install())->handle(['slug' => 'callout']);

        $this->assertTrue($widget['template_filtered']);
        $this->assertTrue($block['template_filtered']);
        $this->assertStringNotContainsString('<script', Widget_Spec_Store::get($widget['id'])['template']);
        $this->assertStringNotContainsString('<script', Block_Spec_Store::get($block['id'])['template']);
        $this->assertStringNotContainsString('onclick', Block_Spec_Store::get($block['id'])['template']);
    }

    public function test_install_surfaces_a_missing_listing(): void
    {
        $out = (new Cloud_Marketplace_Install())->handle(['slug' => 'nope']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('cloud_error', $out->get_error_code());
    }

    public function test_install_requires_manage_options(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $out = (new Cloud_Marketplace_Install())->handle(['slug' => 'hero-banner']);

        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('marketplace_forbidden', $out->get_error_code());
        $this->assertSame([], $this->requests);
    }
}
