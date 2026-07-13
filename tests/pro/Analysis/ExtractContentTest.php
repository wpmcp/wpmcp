<?php

namespace WPMCP\Tests\Pro\Analysis;

use WPMCP\MCP\{Ability, Registrar};
use WPMCP\Pro\Gate;
use WPMCP\Tools\Analysis\Extract_Content;

class ExtractContentTest extends \WP_UnitTestCase
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

    public function test_returns_text_and_structural_summary(): void
    {
        $id = $this->post(
            '<h1>Welcome Home</h1><p>Some intro copy for the reader.</p>'
            . '<h2>Our Services</h2><a href="/about">About</a><img src="x.jpg" alt="An image">'
        );

        $out = (new Extract_Content())->handle(['post_id' => $id]);

        $this->assertSame($id, $out['post_id']);
        $this->assertStringContainsString('Welcome Home', $out['text']);

        $heading_texts = array_column($out['summary']['headings'], 'text');
        $this->assertContains('Welcome Home', $heading_texts);
        $this->assertContains('Our Services', $heading_texts);

        $this->assertGreaterThan(0, $out['summary']['word_count']);
        $this->assertSame(1, $out['summary']['link_count']);
        $this->assertSame(1, $out['summary']['image_count']);
    }

    public function test_missing_post_id_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Extract_Content())->handle([]);
    }

    private function make_ability(): Ability
    {
        return new Ability(
            'wpmcp/extract-content',
            'pro',
            'Extract a post\'s readable text and structural summary.',
            [
                'type'       => 'object',
                'properties' => ['post_id' => ['type' => 'integer']],
                'required'   => ['post_id'],
            ],
            [new Extract_Content(), 'handle'],
            'edit_posts',
            'analysis',
            'read'
        );
    }

    public function test_registrar_skips_when_free(): void
    {
        Gate::set_pro_for_tests(false);
        $registrar = new Registrar();
        $registrar->register($this->make_ability());
        $this->assertCount(0, $registrar->all());
    }

    public function test_registrar_keeps_when_pro(): void
    {
        Gate::set_pro_for_tests(true);
        $registrar = new Registrar();
        $registrar->register($this->make_ability());
        $names = array_map(fn($a) => $a->name, $registrar->all());
        $this->assertContains('wpmcp/extract-content', $names);
    }
}
