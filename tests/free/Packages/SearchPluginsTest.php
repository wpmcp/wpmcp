<?php

namespace WPMCP\Tests\Free\Packages;

use WPMCP\Tools\Packages\Search_Plugins;

class SearchPluginsTest extends \WP_UnitTestCase
{
    private $canned_result;

    public function setUp(): void
    {
        parent::setUp();

        $this->canned_result = (object) [
            'plugins' => [
                (object) [
                    'name'             => 'Contact Form 7',
                    'slug'             => 'contact-form-7',
                    'version'          => '5.9.8',
                    'rating'           => 84,
                    'num_ratings'      => 1234,
                    'active_installs'  => 5000000,
                    'short_description'=> 'Just another contact form plugin.',
                    'author'           => 'Takayuki Miyoshi',
                    'requires'         => '6.0',
                    'tested'           => '6.9',
                ],
            ],
            'info'    => ['page' => 1, 'pages' => 1, 'results' => 1],
        ];

        add_filter('plugins_api', [$this, 'mock_plugins_api'], 10, 3);
    }

    public function tearDown(): void
    {
        remove_filter('plugins_api', [$this, 'mock_plugins_api'], 10);
        parent::tearDown();
    }

    private $captured_args;

    public function mock_plugins_api($result, $action, $args)
    {
        if ('query_plugins' === $action) {
            $this->captured_args = $args;
            return $this->canned_result;
        }

        return $result;
    }

    public function test_search_respects_per_page_cap(): void
    {
        (new Search_Plugins())->handle(['query' => 'contact form', 'per_page' => 500]);

        $this->assertSame(50, $this->captured_args->per_page);
    }

    public function test_search_returns_mocked_results_with_expected_fields(): void
    {
        $out = (new Search_Plugins())->handle(['query' => 'contact form']);

        $this->assertArrayHasKey('plugins', $out);
        $this->assertCount(1, $out['plugins']);

        $plugin = $out['plugins'][0];
        $this->assertSame('Contact Form 7', $plugin['name']);
        $this->assertSame('contact-form-7', $plugin['slug']);
        $this->assertSame('5.9.8', $plugin['version']);
        $this->assertSame(84, $plugin['rating']);
        $this->assertSame(1234, $plugin['num_ratings']);
        $this->assertSame(5000000, $plugin['active_installs']);
        $this->assertSame('Just another contact form plugin.', $plugin['short_description']);
        $this->assertSame('Takayuki Miyoshi', $plugin['author']);
        $this->assertSame('6.0', $plugin['requires']);
        $this->assertSame('6.9', $plugin['tested']);
    }

    /**
     * Issue #599: plugins_api('query_plugins') decodes the wordpress.org
     * response with json_decode(..., true) and casts only the top level to
     * an object, so each row in ->plugins is an array. Reading rows as
     * objects turned every field into its empty default.
     */
    public function test_search_reads_the_array_rows_plugins_api_returns(): void
    {
        $this->canned_result = (object) [
            'info'    => ['page' => 1, 'pages' => 4, 'results' => 37],
            'plugins' => [
                [
                    'name'              => 'Hello Dolly',
                    'slug'              => 'hello-dolly',
                    'version'           => '1.7.2',
                    'author'            => '<a href="https://ma.tt/">Matt Mullenweg</a>',
                    'requires'          => '4.6',
                    'tested'            => '6.8.3',
                    'requires_php'      => false,
                    'rating'            => 50,
                    'ratings'           => ['5' => 100, '4' => 10, '3' => 4, '2' => 3, '1' => 20],
                    'num_ratings'       => 137,
                    'active_installs'   => 200000,
                    'short_description' => 'This is not just a plugin, it symbolizes the hope and enthusiasm of an entire generation.',
                ],
                [
                    'name'              => 'Dolly Duplicator',
                    'slug'              => 'dolly-duplicator',
                    'version'           => '0.3',
                    'author'            => 'Someone',
                    'requires'          => '6.0',
                    'tested'            => '7.0',
                    'rating'            => 0,
                    'num_ratings'       => 0,
                    'active_installs'   => 10,
                    'short_description' => 'Second row.',
                ],
            ],
        ];

        $out = (new Search_Plugins())->handle(['query' => 'Hello Dolly', 'per_page' => 3]);

        $this->assertCount(2, $out['plugins']);
        $this->assertSame([
            'name'              => 'Hello Dolly',
            'slug'              => 'hello-dolly',
            'version'           => '1.7.2',
            'rating'            => 50,
            'num_ratings'       => 137,
            'active_installs'   => 200000,
            'short_description' => 'This is not just a plugin, it symbolizes the hope and enthusiasm of an entire generation.',
            'author'            => '<a href="https://ma.tt/">Matt Mullenweg</a>',
            'requires'          => '4.6',
            'tested'            => '6.8.3',
        ], $out['plugins'][0]);
        $this->assertSame('dolly-duplicator', $out['plugins'][1]['slug']);
        foreach ($out['plugins'] as $row) {
            $this->assertNotSame('', $row['slug'], 'No row may come back blank');
        }
    }

    public function test_search_surfaces_wp_error_cleanly(): void
    {
        add_filter('plugins_api', [$this, 'mock_plugins_api_error'], 10, 3);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Plugin search failed: wordpress.org API unreachable');

        try {
            (new Search_Plugins())->handle(['query' => 'contact form']);
        } finally {
            remove_filter('plugins_api', [$this, 'mock_plugins_api_error'], 10);
        }
    }

    public function mock_plugins_api_error($result, $action, $args)
    {
        if ('query_plugins' === $action) {
            return new \WP_Error('plugins_api_failed', 'wordpress.org API unreachable');
        }

        return $result;
    }
}
