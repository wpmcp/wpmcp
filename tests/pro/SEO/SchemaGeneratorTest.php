<?php

namespace WPMCP\Tests\Pro\SEO;

use WPMCP\Tools\SEO\SEO_Adapter;
use WPMCP\Tools\SEO\Schema_Generator;

/**
 * Shape and edge-case coverage for the JSON-LD assembly behind
 * wpmcp/generate-schema-markup (issue #67). The generator is pure data
 * assembly, so every case here is a plain post factory fixture: no HTTP, no
 * postmeta writes, no theme hooks.
 */
class SchemaGeneratorTest extends \WP_UnitTestCase
{
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            wp_delete_post($id, true);
        }
        $this->created = [];
        SEO_Adapter::set_active_plugin_for_tests(null);
        parent::tearDown();
    }

    private function post(array $args = []): int
    {
        $id = $this->factory()->post->create($args);
        $this->created[] = $id;
        return $id;
    }

    public function test_article_has_the_expected_shape(): void
    {
        $id = $this->post([
            'post_title'   => 'A dependable title',
            'post_excerpt' => 'A dependable excerpt.',
        ]);

        $out = Schema_Generator::generate($id, 'Article');

        $this->assertSame('https://schema.org', $out['@context']);
        $this->assertSame('Article', $out['@type']);
        $this->assertSame('A dependable title', $out['headline']);
        $this->assertSame(get_permalink($id), $out['url']);
        $this->assertSame(get_permalink($id), $out['mainEntityOfPage']);
        $this->assertSame('A dependable excerpt.', $out['description']);
        $this->assertSame('Organization', $out['publisher']['@type']);
        $this->assertArrayHasKey('author', $out);
        $this->assertNotEmpty($out['author']['name']);
    }

    public function test_web_page_uses_name_rather_than_headline(): void
    {
        $id = $this->post(['post_title' => 'Contact us']);

        $out = Schema_Generator::generate($id, 'WebPage');

        $this->assertSame('WebPage', $out['@type']);
        $this->assertSame('Contact us', $out['name']);
        $this->assertArrayNotHasKey('headline', $out);
    }

    public function test_local_business_and_product_are_supported(): void
    {
        $id = $this->post(['post_title' => 'The corner shop']);

        $business = Schema_Generator::generate($id, 'LocalBusiness');
        $this->assertSame('LocalBusiness', $business['@type']);
        $this->assertSame('The corner shop', $business['name']);

        $product = Schema_Generator::generate($id, 'Product');
        $this->assertSame('Product', $product['@type']);
        $this->assertSame('The corner shop', $product['name']);
    }

    public function test_unknown_type_throws(): void
    {
        $id = $this->post();

        $this->expectException(\InvalidArgumentException::class);
        Schema_Generator::generate($id, 'Recipe');
    }

    public function test_missing_post_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Schema_Generator::generate(99999999, 'Article');
    }

    /**
     * Drafts store post_date_gmt as '0000-00-00 00:00:00', so the GMT
     * accessors return false. A schema graph must never carry a boolean
     * date: either a real ISO 8601 string or no key at all.
     */
    public function test_draft_dates_are_never_boolean(): void
    {
        $id = $this->post(['post_status' => 'draft', 'post_title' => 'Unpublished']);

        foreach (['Article', 'WebPage'] as $type) {
            $out = Schema_Generator::generate($id, $type);

            foreach (['datePublished', 'dateModified'] as $key) {
                if (! array_key_exists($key, $out)) {
                    continue;
                }
                $this->assertIsString($out[$key], "{$type}.{$key} must be a string when present");
                $this->assertNotSame('', $out[$key]);
                $this->assertMatchesRegularExpression(
                    '/^\d{4}-\d{2}-\d{2}T/',
                    $out[$key],
                    "{$type}.{$key} must be ISO 8601"
                );
            }
        }
    }

    /**
     * get_the_title() runs the `the_title` filter, which prepends "Private: "
     * on private posts. Schema output is machine-read, not UI, so the raw
     * post_title is the correct source.
     */
    public function test_private_post_headline_has_no_wordpress_ui_prefix(): void
    {
        $id = $this->post(['post_status' => 'private', 'post_title' => 'Internal notes']);

        $out = Schema_Generator::generate($id, 'Article');

        $this->assertSame('Internal notes', $out['headline']);
    }

    public function test_seo_description_wins_over_the_excerpt(): void
    {
        if ('' === wpmcp_seo_plugin()) {
            $this->markTestSkipped('No SEO plugin active');
        }

        $id = $this->post(['post_excerpt' => 'The raw excerpt.']);
        SEO_Adapter::update_meta($id, ['description' => 'The curated description.']);

        $out = Schema_Generator::generate($id, 'Article');

        $this->assertSame('The curated description.', $out['description']);
    }

    /**
     * Yoast and RankMath store unrendered template variables in the meta
     * description ('%%excerpt%%', '%sep% %sitename%'). Emitting those
     * literally would be worse than falling back to the excerpt.
     */
    public function test_unrendered_template_variables_fall_back_to_the_excerpt(): void
    {
        if ('' === wpmcp_seo_plugin()) {
            $this->markTestSkipped('No SEO plugin active');
        }

        $id = $this->post(['post_excerpt' => 'The raw excerpt.']);
        SEO_Adapter::update_meta($id, ['description' => '%%excerpt%%']);

        $out = Schema_Generator::generate($id, 'Article');

        $this->assertSame('The raw excerpt.', $out['description']);
    }

    /** A deleted author must not drop the required Article author field. */
    public function test_article_falls_back_to_an_organization_author(): void
    {
        $id = $this->post(['post_author' => 0]);

        $out = Schema_Generator::generate($id, 'Article');

        $this->assertArrayHasKey('author', $out);
        $this->assertSame('Organization', $out['author']['@type']);
        $this->assertSame(get_bloginfo('name'), $out['author']['name']);
    }
}
