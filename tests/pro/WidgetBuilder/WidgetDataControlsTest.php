<?php

namespace WPMCP\Tests\Pro\WidgetBuilder;

use WPMCP\Pro\Gate;
use WPMCP\Tools\WidgetBuilder\Create_Custom_Widget;
use WPMCP\Tools\WidgetBuilder\List_Control_Types;
use WPMCP\Tools\WidgetBuilder\Validate_Widget_Spec;
use WPMCP\Tools\WidgetBuilder\Widget_Renderer;
use WPMCP\Tools\WidgetBuilder\Widget_Spec;
use WPMCP\Tools\WidgetBuilder\Compiler\Generated_Code_Lint;
use WPMCP\Tools\WidgetBuilder\Compiler\Widget_Compiler;
use WPMCP\Tools\WidgetBuilder\Data\Widget_Data;

/**
 * Issue #296: data controls for the custom widget builder. A data control
 * pulls its value from a data source (posts, products, terms, a menu, the
 * breadcrumb trail, the cart, or remote JSON) instead of from a static
 * setting. Every source renders escaped markup, the dynamic renderer and the
 * compiled class produce the same bytes, and remote JSON only ever reaches an
 * allowlisted public https host.
 */
class WidgetDataControlsTest extends \WP_UnitTestCase
{
    private const DATA_TYPES = ['query_posts', 'query_products', 'query_terms', 'menu', 'breadcrumbs', 'cart', 'remote_json'];

    private const FEED_HOST = 'feeds.example.test';

    /** @var array<int,array{url:string,args:array}> */
    private array $requests = [];

    /** @var array|\WP_Error|null the canned response for the remote JSON mock */
    private $response = null;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $this->requests = [];
        $this->response = null;
        add_filter('pre_http_request', [$this, 'mock_http'], 10, 3);
        // Name resolution is part of the guard; pin it so no test depends on
        // (or performs) a real DNS lookup.
        add_filter('wpmcp_remote_host_addresses', [$this, 'resolve'], 10, 2);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'mock_http'], 10);
        remove_all_filters('wpmcp_remote_host_addresses');
        remove_all_filters('wpmcp_remote_json_allowed_hosts');
        remove_all_filters('wpmcp_remote_json_max_bytes');
        set_current_screen('front');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    /** @return false|array|\WP_Error */
    public function mock_http($pre, $args, $url)
    {
        $this->requests[] = ['url' => (string) $url, 'args' => (array) $args];
        if (null === $this->response) {
            return new \WP_Error('http_request_failed', 'no canned response');
        }
        return $this->response;
    }

    /** @return string[] */
    public function resolve($addresses, string $host): array
    {
        return 'rebind.example.test' === $host ? ['10.1.2.3'] : ['93.184.216.34'];
    }

    private function json_response(string $body, int $code = 200, array $headers = []): array
    {
        return [
            'headers'  => $headers,
            'body'     => $body,
            'response' => ['code' => $code, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    private function allow_feed_host(string ...$hosts): void
    {
        $hosts = [] === $hosts ? [self::FEED_HOST] : $hosts;
        add_filter('wpmcp_remote_json_allowed_hosts', static fn () => $hosts);
    }

    private function spec(array $control, string $template = '<div>{{data}}</div>'): array
    {
        return [
            'name'     => 'data-box',
            'title'    => 'Data Box',
            'controls' => [array_merge(['name' => 'data', 'label' => 'Data'], $control)],
            'template' => $template,
        ];
    }

    /**
     * Render one spec both ways and require identical output: the dynamic
     * renderer and the compiled class read the same data source.
     */
    private function render_both(array $spec, array $settings = []): string
    {
        $this->assertTrue(Widget_Spec::validate($spec), 'fixture spec must validate');
        $dynamic = Widget_Renderer::render(Widget_Spec::normalize($spec), $settings);

        $source = Widget_Compiler::compile(Widget_Spec::normalize($spec), 77);
        $this->assertIsString($source, is_wp_error($source) ? $source->get_error_message() : '');
        $this->assertTrue(Generated_Code_Lint::check($source), 'compiled data widget must pass the lint');

        $start = strpos($source, 'protected function render()');
        $body  = substr($source, (int) strpos($source, '{', (int) $start) + 1);
        $body  = substr($body, 0, (int) strrpos($body, '}'));
        $body  = substr($body, 0, (int) strrpos($body, '}'));
        $body  = str_replace('$this->get_settings_for_display()', var_export($settings, true), $body);
        ob_start();
        eval($body);
        $compiled = (string) ob_get_clean();

        $this->assertSame($dynamic, $compiled, 'dynamic and compiled renders must match');
        return $dynamic;
    }

    // ---- vocabulary ---------------------------------------------------------

    public function test_list_control_types_lists_every_data_control_as_compilable(): void
    {
        $rows = [];
        foreach ((new List_Control_Types())->handle([])['control_types'] as $row) {
            $rows[ $row['type'] ] = $row;
        }
        foreach (self::DATA_TYPES as $type) {
            $this->assertArrayHasKey($type, $rows, "{$type} must be listed");
            $this->assertTrue($rows[ $type ]['compilable'], "{$type} must compile");
            $this->assertTrue($rows[ $type ]['data'], "{$type} is a data control");
            $this->assertLessThanOrEqual(160, strlen($rows[ $type ]['description']), "{$type}: keep the description short");
        }
        $this->assertFalse($rows['text']['data'], 'static controls are not data controls');
    }

    /**
     * @dataProvider unknown_types
     */
    public function test_unknown_control_types_are_refused(string $type): void
    {
        $out = Widget_Spec::validate($this->spec(['type' => $type]));
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('controls[0].type', $out->get_error_data()['field']);
        $this->assertFalse(Widget_Spec::is_renderable($this->spec(['type' => $type])));
    }

    public function unknown_types(): array
    {
        return [
            'query users' => ['query_users'],
            'raw php'     => ['php'],
            'remote html' => ['remote_html'],
            'sql'         => ['query_sql'],
            'case'        => ['Query_Posts'],
        ];
    }

    // ---- validation per control ---------------------------------------------

    /**
     * @dataProvider valid_data_controls
     */
    public function test_valid_data_control_specs_pass(array $control): void
    {
        $this->assertTrue(Widget_Spec::validate($this->spec($control)));
        $this->assertTrue((new Validate_Widget_Spec())->handle(['spec' => $this->spec($control)])['valid']);
    }

    public function valid_data_controls(): array
    {
        return [
            'posts, bare'      => [['type' => 'query_posts']],
            'posts, full'      => [['type' => 'query_posts', 'default' => '3', 'query' => [
                'post_type' => 'page', 'taxonomy' => 'category', 'terms' => ['news', 'events'],
                'count' => 3, 'orderby' => 'title', 'order' => 'ASC',
            ]]],
            'products'         => [['type' => 'query_products', 'query' => ['category' => ['shoes'], 'orderby' => 'price', 'order' => 'DESC', 'count' => 8]]],
            'terms'            => [['type' => 'query_terms', 'query' => ['taxonomy' => 'post_tag', 'orderby' => 'count', 'order' => 'DESC', 'hide_empty' => false, 'count' => 20]]],
            'menu'             => [['type' => 'menu', 'default' => 'primary']],
            'breadcrumbs'      => [['type' => 'breadcrumbs', 'query' => ['home' => 'Start']]],
            'cart'             => [['type' => 'cart']],
            'remote json'      => [['type' => 'remote_json', 'query' => ['url' => 'https://feeds.example.test/v1/items.json', 'path' => 'data.items', 'field' => 'name', 'count' => 5, 'cache' => 600]]],
        ];
    }

    /**
     * @dataProvider invalid_data_controls
     */
    public function test_invalid_data_control_specs_are_refused(string $field, array $control): void
    {
        $out = Widget_Spec::validate($this->spec($control));
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertStringNotContainsString('Array', $out->get_error_message());
        $this->assertSame($field, $out->get_error_data()['field']);

        $created = (new Create_Custom_Widget())->handle(['spec' => $this->spec($control)]);
        $this->assertInstanceOf(\WP_Error::class, $created, 'create-custom-widget must refuse it too');
    }

    public function invalid_data_controls(): array
    {
        $q = 'controls[0].query';
        return [
            'query is a string'            => [$q, ['type' => 'query_posts', 'query' => 'post_type=page']],
            'unknown query key'            => [$q . '.meta_query', ['type' => 'query_posts', 'query' => ['meta_query' => [['key' => 'x']]]]],
            'key of another data type'     => [$q . '.url', ['type' => 'query_posts', 'query' => ['url' => 'https://feeds.example.test/']]],
            'post type with markup'        => [$q . '.post_type', ['type' => 'query_posts', 'query' => ['post_type' => '<script>']]],
            'post type is an array'        => [$q . '.post_type', ['type' => 'query_posts', 'query' => ['post_type' => ['post']]]],
            'terms without taxonomy'       => [$q . '.terms', ['type' => 'query_posts', 'query' => ['terms' => ['news']]]],
            'terms is a string'            => [$q . '.terms', ['type' => 'query_posts', 'query' => ['taxonomy' => 'category', 'terms' => 'news']]],
            'count is zero'                => [$q . '.count', ['type' => 'query_posts', 'query' => ['count' => 0]]],
            'count is too large'           => [$q . '.count', ['type' => 'query_terms', 'query' => ['count' => 5000]]],
            'count is a string'            => [$q . '.count', ['type' => 'query_posts', 'query' => ['count' => '5; DROP']]],
            'orderby is sql'               => [$q . '.orderby', ['type' => 'query_posts', 'query' => ['orderby' => 'ID DESC, (SELECT 1)']]],
            'order is not asc or desc'     => [$q . '.order', ['type' => 'query_products', 'query' => ['order' => 'sideways']]],
            'hide_empty is a string'       => [$q . '.hide_empty', ['type' => 'query_terms', 'query' => ['hide_empty' => 'no']]],
            'query on a menu'              => [$q . '.post_type', ['type' => 'menu', 'query' => ['post_type' => 'post']]],
            'query on a static control'    => [$q, ['type' => 'text', 'query' => ['count' => 3]]],
            'remote json without url'      => [$q . '.url', ['type' => 'remote_json']],
            'remote json over http'        => [$q . '.url', ['type' => 'remote_json', 'query' => ['url' => 'http://feeds.example.test/a.json']]],
            'remote json with credentials' => [$q . '.url', ['type' => 'remote_json', 'query' => ['url' => 'https://user:pw@feeds.example.test/a.json']]],
            'remote json on a custom port' => [$q . '.url', ['type' => 'remote_json', 'query' => ['url' => 'https://feeds.example.test:8443/a.json']]],
            'remote json path is code'     => [$q . '.path', ['type' => 'remote_json', 'query' => ['url' => 'https://feeds.example.test/a.json', 'path' => 'a;system(1)']]],
            'remote json cache too short'  => [$q . '.cache', ['type' => 'remote_json', 'query' => ['url' => 'https://feeds.example.test/a.json', 'cache' => 1]]],
        ];
    }

    // ---- each data source renders in a compiled widget ------------------------

    public function test_query_posts_renders_filtered_ordered_escaped_list_both_ways(): void
    {
        $news = self::factory()->category->create(['slug' => 'news', 'name' => 'News']);
        $a    = self::factory()->post->create(['post_title' => 'Alpha <b>bold</b>', 'post_category' => [$news]]);
        $c    = self::factory()->post->create(['post_title' => 'Charlie', 'post_category' => [$news]]);
        self::factory()->post->create(['post_title' => 'Bravo', 'post_category' => [$news]]);
        self::factory()->post->create(['post_title' => 'Outside the category']);

        $html = $this->render_both($this->spec([
            'type'  => 'query_posts',
            'query' => ['post_type' => 'post', 'taxonomy' => 'category', 'terms' => ['news'], 'count' => 2, 'orderby' => 'title', 'order' => 'ASC'],
        ]));

        $this->assertStringContainsString('<ul class="wpmcp-data wpmcp-posts">', $html);
        $this->assertStringContainsString(esc_url(get_permalink($a)), $html);
        $this->assertStringContainsString('Alpha &lt;b&gt;bold&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>bold</b>', $html);
        $this->assertStringContainsString('Bravo', $html);
        $this->assertStringNotContainsString('Charlie', $html, 'count 2 in title order stops before Charlie');
        $this->assertStringNotContainsString(esc_url(get_permalink($c)), $html);
        $this->assertStringNotContainsString('Outside the category', $html);
        $this->assertLessThan(strpos($html, 'Bravo'), strpos($html, 'Alpha'));
    }

    public function test_query_posts_count_comes_from_the_setting_and_is_capped(): void
    {
        self::factory()->post->create_many(3);
        $spec = $this->spec(['type' => 'query_posts', 'default' => '1']);

        $this->assertSame(1, substr_count($this->render_both($spec), '<li>'), 'the default is the count');
        $this->assertSame(2, substr_count($this->render_both($spec, ['data' => '2']), '<li>'), 'the editor setting overrides it');
        $this->assertSame(3, substr_count($this->render_both($spec, ['data' => '999999']), '<li>'), 'huge counts are capped, never unbounded');
    }

    public function test_query_posts_never_lists_private_or_draft_posts(): void
    {
        self::factory()->post->create(['post_title' => 'Public one']);
        self::factory()->post->create(['post_title' => 'Secret draft', 'post_status' => 'draft']);
        self::factory()->post->create(['post_title' => 'Secret private', 'post_status' => 'private']);

        $html = $this->render_both($this->spec(['type' => 'query_posts', 'query' => ['count' => 10]]));
        $this->assertStringContainsString('Public one', $html);
        $this->assertStringNotContainsString('Secret', $html);
    }

    public function test_query_terms_renders_linked_escaped_terms(): void
    {
        $t = self::factory()->tag->create(['name' => 'Tag & <i>Friends</i>', 'slug' => 'friends']);
        self::factory()->post->create(['tags_input' => ['friends']]);
        self::factory()->tag->create(['name' => 'Empty tag', 'slug' => 'empty-tag']);

        $html = $this->render_both($this->spec(['type' => 'query_terms', 'query' => ['taxonomy' => 'post_tag', 'orderby' => 'name']]));
        $this->assertStringContainsString('<ul class="wpmcp-data wpmcp-terms">', $html);
        $this->assertStringContainsString(esc_url(get_term_link($t)), $html);
        $this->assertStringContainsString('Tag &amp; &lt;i&gt;Friends&lt;/i&gt;', $html);
        $this->assertStringNotContainsString('Empty tag', $html, 'empty terms are hidden by default');

        $all = $this->render_both($this->spec(['type' => 'query_terms', 'query' => ['taxonomy' => 'post_tag', 'hide_empty' => false]]));
        $this->assertStringContainsString('Empty tag', $all);
    }

    public function test_query_terms_on_an_unknown_taxonomy_renders_nothing(): void
    {
        $this->assertSame('<div></div>', $this->render_both($this->spec(['type' => 'query_terms', 'query' => ['taxonomy' => 'no_such_tax']])));
    }

    public function test_menu_renders_the_named_menu_escaped(): void
    {
        $menu = wp_create_nav_menu('Main Nav');
        wp_update_nav_menu_item($menu, 0, [
            'menu-item-title'  => 'Docs <script>x</script>',
            'menu-item-url'    => 'https://example.test/docs',
            'menu-item-status' => 'publish',
        ]);

        $html = $this->render_both($this->spec(['type' => 'menu', 'default' => 'main-nav']));
        $this->assertStringContainsString('https://example.test/docs', $html);
        $this->assertStringContainsString('Docs', $html);
        $this->assertStringNotContainsString('<script>', $html);

        $this->assertSame('<div></div>', $this->render_both($this->spec(['type' => 'menu', 'default' => 'no-such-menu'])));
    }

    public function test_breadcrumbs_render_the_trail_to_the_current_page(): void
    {
        $parent = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Parent & Co']);
        $child  = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Child', 'post_parent' => $parent]);
        $this->go_to(get_permalink($child));

        $html = $this->render_both($this->spec(['type' => 'breadcrumbs', 'query' => ['home' => 'Start']]));
        $this->assertStringContainsString('<nav class="wpmcp-data wpmcp-breadcrumbs" aria-label="Breadcrumb">', $html);
        $this->assertStringContainsString(esc_url(home_url('/')), $html);
        $this->assertStringContainsString('Start', $html);
        $this->assertStringContainsString(esc_url(get_permalink($parent)), $html);
        $this->assertStringContainsString('Parent &amp; Co', $html);
        $this->assertStringContainsString('<li aria-current="page">Child</li>', $html);
        $this->assertLessThan(strpos($html, 'Child'), strpos($html, 'Parent'));
    }

    public function test_woocommerce_sources_render_nothing_without_woocommerce_data(): void
    {
        if (class_exists('WooCommerce')) {
            $this->assertIsString($this->render_both($this->spec(['type' => 'query_products'])));
            return;
        }
        $this->assertSame('<div></div>', $this->render_both($this->spec(['type' => 'query_products'])));
        $this->assertSame('<div></div>', $this->render_both($this->spec(['type' => 'cart'])));
    }

    public function test_query_products_renders_published_products(): void
    {
        if (! class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce is not active in this environment.');
        }
        $cat = self::factory()->term->create(['taxonomy' => 'product_cat', 'slug' => 'shoes', 'name' => 'Shoes']);
        $in  = new \WC_Product_Simple();
        $in->set_name('Runner <em>X</em>');
        $in->set_regular_price('49.00');
        $in->set_status('publish');
        $in->set_category_ids([$cat]);
        $in->save();
        $out = new \WC_Product_Simple();
        $out->set_name('Hat');
        $out->set_regular_price('9.00');
        $out->set_status('publish');
        $out->save();

        $html = $this->render_both($this->spec(['type' => 'query_products', 'query' => ['category' => ['shoes']]]));
        $this->assertStringContainsString('<ul class="wpmcp-data wpmcp-products">', $html);
        $this->assertStringContainsString('Runner &lt;em&gt;X&lt;/em&gt;', $html);
        $this->assertStringContainsString(esc_url(get_permalink($in->get_id())), $html);
        $this->assertStringContainsString('<span class="wpmcp-price">', $html);
        $this->assertStringContainsString('49', $html);
        $this->assertStringNotContainsString('Hat', $html);
    }

    public function test_cart_renders_count_and_total(): void
    {
        if (! class_exists('WooCommerce')) {
            $this->markTestSkipped('WooCommerce is not active in this environment.');
        }
        $html = $this->render_both($this->spec(['type' => 'cart']));
        $this->assertStringContainsString('<span class="wpmcp-data wpmcp-cart">', $html);
        $this->assertStringContainsString('<span class="wpmcp-cart-count">0</span>', $html);
        $this->assertStringContainsString('<span class="wpmcp-cart-total">', $html);
    }

    // ---- remote JSON ---------------------------------------------------------

    public function test_remote_json_renders_a_list_from_an_allowed_host_and_caches_it(): void
    {
        $this->allow_feed_host();
        $this->response = $this->json_response((string) wp_json_encode(['data' => ['items' => [
            ['name' => 'First <img src=x onerror=alert(1)>'],
            ['name' => 'Second'],
            ['name' => ['nested' => 'skipped']],
            ['name' => 'Third'],
        ]]]));

        $spec = $this->spec(['type' => 'remote_json', 'query' => [
            'url' => 'https://feeds.example.test/v1/items.json', 'path' => 'data.items', 'field' => 'name', 'count' => 2,
        ]]);
        $html = $this->render_both($spec);

        $this->assertStringContainsString('<ul class="wpmcp-data wpmcp-json">', $html);
        $this->assertStringContainsString('First &lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringContainsString('Second', $html);
        $this->assertStringNotContainsString('Third', $html, 'count caps the list');
        $this->assertStringNotContainsString('<img', $html);

        $this->assertCount(1, $this->requests, 'the second render (compiled) must be served from the transient');
        $args = $this->requests[0]['args'];
        $this->assertSame(0, (int) $args['redirection'], 'redirects are never followed');
        $this->assertLessThanOrEqual(10, (float) $args['timeout']);
        $this->assertGreaterThan(0, (int) $args['limit_response_size']);
        $this->assertTrue((bool) $args['reject_unsafe_urls'], 'the request goes through wp_safe_remote_get');
    }

    public function test_remote_json_renders_a_scalar_at_the_path_as_escaped_text(): void
    {
        $this->allow_feed_host();
        $this->response = $this->json_response('{"weather":{"temp":"21 <sup>C</sup>"}}');

        $html = $this->render_both($this->spec(
            ['type' => 'remote_json', 'query' => ['url' => 'https://feeds.example.test/w.json', 'path' => 'weather.temp']],
            '<p>{{data}}</p>'
        ));
        $this->assertSame('<p>21 &lt;sup&gt;C&lt;/sup&gt;</p>', $html);
    }

    /**
     * @dataProvider refused_remote_urls
     */
    public function test_remote_json_refuses_off_list_and_private_hosts_without_a_request(string $url, array $allowed): void
    {
        $this->allow_feed_host(...$allowed);
        $this->response = $this->json_response('{"leak":"internal secret"}');

        $html = Widget_Data::render('remote_json', ['url' => $url, 'path' => 'leak'], '');
        $this->assertSame('', $html);
        $this->assertSame([], $this->requests, "no request may leave the site for {$url}");
    }

    public function refused_remote_urls(): array
    {
        return [
            'host not on the list'           => ['https://evil.example.test/a.json', [self::FEED_HOST]],
            'suffix trick'                   => ['https://feeds.example.test.evil.example/a.json', [self::FEED_HOST]],
            'empty allowlist by default'     => ['https://feeds.example.test/a.json', ['']],
            'loopback literal on the list'   => ['https://127.0.0.1/a.json', ['127.0.0.1']],
            'rfc1918 literal on the list'    => ['https://10.0.0.5/a.json', ['10.0.0.5']],
            'link-local metadata address'    => ['https://169.254.169.254/latest/meta-data', ['169.254.169.254']],
            'localhost name on the list'     => ['https://localhost/a.json', ['localhost']],
            'ipv6 loopback on the list'      => ['https://[::1]/a.json', ['[::1]', '::1']],
            'name resolving to private ip'   => ['https://rebind.example.test/a.json', ['rebind.example.test']],
            'plain http on an allowed host'  => ['http://feeds.example.test/a.json', [self::FEED_HOST]],
            'credentials on an allowed host' => ['https://u:p@feeds.example.test/a.json', [self::FEED_HOST]],
        ];
    }

    public function test_remote_json_default_allowlist_is_empty(): void
    {
        $this->response = $this->json_response('{"a":"b"}');
        $this->assertSame('', Widget_Data::render('remote_json', ['url' => 'https://feeds.example.test/a.json', 'path' => 'a'], ''));
        $this->assertSame([], $this->requests);
    }

    public function test_remote_json_refuses_an_oversized_body(): void
    {
        $this->allow_feed_host();
        add_filter('wpmcp_remote_json_max_bytes', static fn () => 64);
        $this->response = $this->json_response((string) wp_json_encode(['a' => str_repeat('x', 500)]));

        $this->assertSame('', Widget_Data::render('remote_json', ['url' => 'https://feeds.example.test/big.json', 'path' => 'a'], ''));
    }

    public function test_remote_json_refuses_redirects_and_errors(): void
    {
        $this->allow_feed_host();
        $this->response = $this->json_response('{"a":"moved"}', 302, ['location' => 'https://10.0.0.1/']);
        $this->assertSame('', Widget_Data::render('remote_json', ['url' => 'https://feeds.example.test/r.json', 'path' => 'a'], ''));

        $this->response = $this->json_response('not json', 200);
        $this->assertSame('', Widget_Data::render('remote_json', ['url' => 'https://feeds.example.test/bad.json', 'path' => 'a'], ''));
    }

    public function test_remote_json_never_fetches_on_an_admin_screen(): void
    {
        $this->allow_feed_host();
        $this->response = $this->json_response('{"a":"fresh"}');
        set_current_screen('dashboard');

        $this->assertSame('', Widget_Data::render('remote_json', ['url' => 'https://feeds.example.test/admin.json', 'path' => 'a'], ''));
        $this->assertSame([], $this->requests, 'an admin page load must not trigger a remote fetch');

        // A value already cached by a front-end render is still shown.
        set_current_screen('front');
        $this->assertSame('fresh', Widget_Data::render('remote_json', ['url' => 'https://feeds.example.test/admin.json', 'path' => 'a'], ''));
        set_current_screen('dashboard');
        $this->assertSame('fresh', Widget_Data::render('remote_json', ['url' => 'https://feeds.example.test/admin.json', 'path' => 'a'], ''));
        $this->assertCount(1, $this->requests);
    }

    // ---- the compiled form ----------------------------------------------------

    public function test_compiled_data_placeholder_goes_through_the_data_renderer_only(): void
    {
        $source = Widget_Compiler::compile(Widget_Spec::normalize($this->spec(['type' => 'query_posts', 'query' => ['count' => 3]])), 5);
        $this->assertIsString($source);
        $this->assertStringContainsString("echo \\WPMCP\\Tools\\WidgetBuilder\\Data\\Widget_Data::render('query_posts', ", $source);
        $this->assertSame(0, preg_match('/echo\s+\$/', $source), 'no variable is echoed raw');
    }

    /**
     * @dataProvider hostile_static_calls
     */
    public function test_lint_allows_only_the_data_renderer_as_a_static_call(string $call): void
    {
        $source = "<?php\nclass X\n{\n    protected function render()\n    {\n        echo {$call};\n    }\n}\n";
        $this->assertInstanceOf(\WP_Error::class, Generated_Code_Lint::check($source), $call);
    }

    public function hostile_static_calls(): array
    {
        return [
            'another method on the data class' => ["\\WPMCP\\Tools\\WidgetBuilder\\Data\\Widget_Data::fetch('x')"],
            'another class, same method'       => ["\\WPMCP\\Evil::render('x')"],
            'relative data class'              => ["Widget_Data::render('x', [], '')"],
            'dynamic method on the data class' => ["\\WPMCP\\Tools\\WidgetBuilder\\Data\\Widget_Data::\$m('x')"],
            'property read, not a call'        => ["\\WPMCP\\Tools\\WidgetBuilder\\Data\\Widget_Data::\$cache"],
        ];
    }

    public function test_lint_accepts_the_data_renderer_call_the_emitter_produces(): void
    {
        $source = "<?php\nclass X\n{\n    protected function render()\n    {\n"
            . "        \$value = 'x';\n"
            . "        echo \\WPMCP\\Tools\\WidgetBuilder\\Data\\Widget_Data::render('query_posts', ['count' => 3], is_scalar(\$value) ? (string) \$value : '');\n"
            . "    }\n}\n";
        $this->assertTrue(Generated_Code_Lint::check($source));
    }
}
