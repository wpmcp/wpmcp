<?php

namespace WPMCP\Tests\Free\Packages;

use WPMCP\Tools\Packages\Get_Plugin_Info;

class GetPluginInfoTest extends \WP_UnitTestCase
{
    private $canned_info;

    public function setUp(): void
    {
        parent::setUp();

        $this->canned_info = (object) [
            'name'             => 'Contact Form 7',
            'slug'             => 'contact-form-7',
            'version'          => '5.9.8',
            'rating'           => 84,
            'num_ratings'      => 1234,
            'active_installs'  => 5000000,
            'homepage'         => 'https://contactform7.com/',
            'download_link'    => 'https://downloads.wordpress.org/plugin/contact-form-7.5.9.8.zip',
            'requires'         => '6.0',
            'tested'           => '6.9',
            'sections'         => [
                'description' => 'Just another contact form plugin. Simple but flexible.',
            ],
        ];

        add_filter('plugins_api', [$this, 'mock_plugins_api'], 10, 3);
    }

    public function tearDown(): void
    {
        remove_filter('plugins_api', [$this, 'mock_plugins_api'], 10);
        parent::tearDown();
    }

    public function mock_plugins_api($result, $action, $args)
    {
        if ('plugin_information' === $action) {
            return $this->canned_info;
        }

        return $result;
    }

    public function test_returns_mocked_info_for_slug(): void
    {
        $out = (new Get_Plugin_Info())->handle(['slug' => 'contact-form-7']);

        $this->assertSame('Contact Form 7', $out['name']);
        $this->assertSame('contact-form-7', $out['slug']);
        $this->assertSame('5.9.8', $out['version']);
        $this->assertSame(84, $out['rating']);
        $this->assertSame(1234, $out['num_ratings']);
        $this->assertSame(5000000, $out['active_installs']);
        $this->assertSame('https://contactform7.com/', $out['homepage']);
        $this->assertSame('https://downloads.wordpress.org/plugin/contact-form-7.5.9.8.zip', $out['download_link']);
        $this->assertSame('6.0', $out['requires']);
        $this->assertSame('6.9', $out['tested']);
        $this->assertSame('Just another contact form plugin. Simple but flexible.', $out['short_description']);
    }

    public function test_requires_slug(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Get_Plugin_Info())->handle([]);
    }
}
