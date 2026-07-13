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
}
