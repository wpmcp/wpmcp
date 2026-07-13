<?php

namespace WPMCP\Tests\Pro\Analysis;

use WPMCP\Pro\Gate;
use WPMCP\Tools\Analysis\Content_Extractor;

class ContentExtractorTest extends \WP_UnitTestCase
{
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            wp_delete_post($id, true);
        }
        $this->created = [];
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function post(string $content): int
    {
        $id = $this->factory()->post->create(['post_content' => $content]);
        $this->created[] = $id;
        return $id;
    }

    public function test_extracts_headings_with_levels_and_text(): void
    {
        $id = $this->post(
            '<h1>Welcome Home</h1><p>Intro copy.</p><h2>Our Services</h2><h3>Details</h3>'
        );

        $r = Content_Extractor::extract($id);

        $by_text = [];
        foreach ($r['headings'] as $h) {
            $by_text[$h['text']] = $h['level'];
        }

        $this->assertSame(1, $by_text['Welcome Home'] ?? null);
        $this->assertSame(2, $by_text['Our Services'] ?? null);
        $this->assertSame(3, $by_text['Details'] ?? null);
    }

    public function test_extracts_links_internal_vs_external(): void
    {
        $home = wp_parse_url(home_url(), PHP_URL_HOST);
        $id   = $this->post(
            '<p>See our <a href="/about">about page</a> or <a href="https://other.example/x">external site</a> '
            . 'and <a href="' . esc_url(home_url('/contact')) . '">contact</a>.</p>'
        );

        $r = Content_Extractor::extract($id);

        $by_url = [];
        foreach ($r['links'] as $l) {
            $by_url[$l['url']] = $l['internal'];
        }

        $this->assertTrue($by_url['/about'] ?? null);
        $this->assertFalse($by_url['https://other.example/x'] ?? null);
        $this->assertTrue($by_url[home_url('/contact')] ?? null);
        $this->assertNotEmpty($home);
    }

    public function test_extracts_images_and_alt(): void
    {
        $id = $this->post(
            '<img src="a.jpg" alt="A described image"><img src="b.jpg">'
        );

        $r = Content_Extractor::extract($id);

        $by_src = [];
        foreach ($r['images'] as $img) {
            $by_src[$img['src']] = $img['alt'];
        }

        $this->assertSame('A described image', $by_src['a.jpg'] ?? null);
        $this->assertSame('', $by_src['b.jpg'] ?? null);
    }

    public function test_extracts_form_fields_with_and_without_labels(): void
    {
        // Form markup only survives post_content insertion for a user with the
        // unfiltered_html capability; wp_kses_post strips form controls
        // otherwise. An administrator has it on single-site installs.
        wp_set_current_user($this->factory()->user->create(['role' => 'administrator']));

        $id = $this->post(
            '<form><label for="email">Email</label><input id="email" type="email">'
            . '<input id="name" type="text"></form>'
        );

        $r = Content_Extractor::extract($id);

        $this->assertCount(2, $r['form_fields']);
        $labels = array_column($r['form_fields'], 'label');
        $this->assertContains('Email', $labels);
        $this->assertContains('', $labels);
    }

    public function test_word_count_and_plain_text(): void
    {
        $id = $this->post('<h1>Title</h1><p>One two three four five words here.</p>');

        $r = Content_Extractor::extract($id);

        $this->assertGreaterThan(5, $r['word_count']);
        $this->assertStringContainsString('One two three', $r['text']);
        $this->assertStringNotContainsString('<p>', $r['text']);
    }

    public function test_missing_post_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Content_Extractor::extract(0);
    }

    public function test_empty_content_is_safe(): void
    {
        $id = $this->post('');
        $r  = Content_Extractor::extract($id);

        $this->assertSame(0, $r['word_count']);
        $this->assertSame([], $r['headings']);
        $this->assertSame([], $r['images']);
        $this->assertSame([], $r['links']);
    }
}
