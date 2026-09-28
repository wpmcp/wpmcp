<?php

namespace WPMCP\Tests\Free\Packages;

use WPMCP\Tools\Packages\Search_Themes;

/**
 * search-themes (issue #282): a read-only mirror of search-plugins over the
 * wordpress.org theme directory. The HTTP call core's themes_api() makes is
 * answered by a pre_http_request stub, so nothing leaves the test run and
 * the request core built can be inspected.
 */
class SearchThemesTest extends \WP_UnitTestCase
{
    private ?string $requested_url = null;

    private int $requests = 0;

    /** @var array<string, mixed>|null */
    private ?array $response_body = null;

    public function setUp(): void
    {
        parent::setUp();

        $this->response_body = [
            'info'   => ['page' => 1, 'pages' => 1, 'results' => 1],
            'themes' => [
                [
                    'name'           => 'Twenty Twenty-Five',
                    'slug'           => 'twentytwentyfive',
                    'version'        => '1.3',
                    'rating'         => 72,
                    'num_ratings'    => 88,
                    'active_installs' => 1000000,
                    'author'         => ['user_nicename' => 'wordpressdotorg', 'display_name' => 'WordPress.org'],
                    'requires'       => '6.7',
                    'requires_php'   => '7.2',
                    'description'    => 'A block theme.',
                ],
            ],
        ];

        add_filter('pre_http_request', [$this, 'mock_http'], 10, 3);
    }

    public function tearDown(): void
    {
        remove_filter('pre_http_request', [$this, 'mock_http'], 10);
        parent::tearDown();
    }

    public function mock_http($preempt, $args, $url)
    {
        if (false === strpos((string) $url, 'api.wordpress.org/themes/info/')) {
            return $preempt;
        }
        $this->requests++;
        $this->requested_url = (string) $url;

        // An API-level error body rather than a transport WP_Error: core
        // retries a failed https request over http and raises a notice
        // first, which is core's behavior, not this tool's.
        $body = $this->response_body ?? ['error' => 'wordpress.org API unreachable'];

        return [
            'headers'  => ['content-type' => 'application/json'],
            'body'     => (string) wp_json_encode($body),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    private function request_arg(string $key)
    {
        $query = [];
        wp_parse_str((string) wp_parse_url((string) $this->requested_url, PHP_URL_QUERY), $query);
        return $query['request'][ $key ] ?? null;
    }

    public function test_search_returns_name_slug_version_and_rating(): void
    {
        $out = (new Search_Themes())->handle(['query' => 'block']);

        $this->assertSame(1, $this->requests);
        $this->assertSame('query_themes', $this->query_action());
        $this->assertSame('block', $this->request_arg('search'));

        $this->assertCount(1, $out['themes']);
        $theme = $out['themes'][0];
        $this->assertSame('Twenty Twenty-Five', $theme['name']);
        $this->assertSame('twentytwentyfive', $theme['slug']);
        $this->assertSame('1.3', $theme['version']);
        $this->assertSame(72, $theme['rating']);
        $this->assertSame(88, $theme['num_ratings']);
        $this->assertSame(1000000, $theme['active_installs']);
        $this->assertSame('WordPress.org', $theme['author']);
        $this->assertSame('6.7', $theme['requires']);
        $this->assertSame('7.2', $theme['requires_php']);
    }

    public function test_search_caps_per_page(): void
    {
        (new Search_Themes())->handle(['query' => 'block', 'per_page' => 500]);

        $this->assertSame('50', (string) $this->request_arg('per_page'));
    }

    public function test_tag_and_author_filters_are_passed_through(): void
    {
        (new Search_Themes())->handle(['query' => 'block', 'tag' => 'full-site-editing', 'author' => 'wordpressdotorg']);

        $this->assertSame(['full-site-editing'], (array) $this->request_arg('tag'));
        $this->assertSame('wordpressdotorg', $this->request_arg('author'));
    }

    public function test_query_is_required(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Search_Themes())->handle(['query' => '  ']);
    }

    public function test_search_surfaces_api_failure_cleanly(): void
    {
        $this->response_body = null;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Theme search failed');

        (new Search_Themes())->handle(['query' => 'block']);
    }

    private function query_action(): ?string
    {
        $query = [];
        wp_parse_str((string) wp_parse_url((string) $this->requested_url, PHP_URL_QUERY), $query);
        return $query['action'] ?? null;
    }
}
