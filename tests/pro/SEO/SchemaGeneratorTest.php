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
        delete_option(Schema_Generator::SITE_PROFILE_OPTION);
        $this->clear_store_options();
        SEO_Adapter::set_active_plugin_for_tests(null);
        parent::tearDown();
    }

    /**
     * The WooCommerce store settings are plain options that exist on any site
     * where WooCommerce has ever been installed, so cases about the profile
     * alone have to clear them first.
     */
    private function clear_store_options(): void
    {
        foreach ([
            'woocommerce_store_address', 'woocommerce_store_address_2',
            'woocommerce_store_city', 'woocommerce_store_postcode',
            'woocommerce_default_country', 'woocommerce_store_phone',
        ] as $option) {
            delete_option($option);
        }
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

    /**
     * schema.org scopes `publisher` to CreativeWork. Asserting only @type and
     * name (as this case used to) passes happily on a graph carrying an
     * out-of-domain property, so the key set is what gets asserted here.
     */
    public function test_publisher_is_emitted_only_on_the_creative_work_types(): void
    {
        $id = $this->post(['post_title' => 'The corner shop']);

        foreach (['Article', 'WebPage'] as $type) {
            $this->assertArrayHasKey(
                'publisher',
                Schema_Generator::generate($id, $type),
                "{$type} is a CreativeWork and must carry publisher"
            );
        }

        foreach (['LocalBusiness', 'Product'] as $type) {
            $this->assertArrayNotHasKey(
                'publisher',
                Schema_Generator::generate($id, $type),
                "publisher is CreativeWork-only and must not appear on {$type}"
            );
        }
    }

    /**
     * The Thing-level keys are valid on every supported type, so every type
     * gets them, and nothing outside the documented set leaks in.
     */
    public function test_every_type_emits_only_known_keys(): void
    {
        $allowed = [
            'Article'       => ['@context', '@type', 'url', 'mainEntityOfPage', 'description',
                                'image', 'headline', 'publisher', 'datePublished', 'dateModified',
                                'author'],
            'WebPage'       => ['@context', '@type', 'url', 'mainEntityOfPage', 'description',
                                'image', 'name', 'publisher', 'datePublished', 'dateModified'],
            'LocalBusiness' => ['@context', '@type', 'url', 'mainEntityOfPage', 'description',
                                'image', 'name', 'telephone', 'priceRange', 'openingHours',
                                'address'],
            'Product'       => ['@context', '@type', 'url', 'mainEntityOfPage', 'description',
                                'image', 'name', 'sku', 'offers'],
        ];

        $id = $this->post(['post_title' => 'The corner shop', 'post_excerpt' => 'Excerpt.']);

        foreach ($allowed as $type => $keys) {
            $out = Schema_Generator::generate($id, $type);

            $this->assertSame('https://schema.org', $out['@context']);
            $this->assertSame($type, $out['@type']);
            $this->assertSame(
                [],
                array_diff(array_keys($out), $keys),
                "{$type} emitted a key outside its documented set"
            );
        }
    }

    /**
     * LocalBusiness describes the business. Taking name/url from the post
     * record emits "name": "About" for an About page, which describes a
     * document rather than a business.
     */
    public function test_local_business_describes_the_business_not_the_page(): void
    {
        $id = $this->post(['post_title' => 'About']);

        $out = Schema_Generator::generate($id, 'LocalBusiness');

        $this->assertSame('LocalBusiness', $out['@type']);
        $this->assertSame(get_bloginfo('name'), $out['name']);
        $this->assertSame(home_url('/'), $out['url']);
        $this->assertSame(get_permalink($id), $out['mainEntityOfPage']);
    }

    public function test_local_business_assembles_the_address_from_the_site_profile(): void
    {
        update_option(Schema_Generator::SITE_PROFILE_OPTION, [
            'name'           => 'Corner Shop Ltd',
            'url'            => 'https://corner.example/',
            'telephone'      => '+1 555 0100',
            'priceRange'     => '$$',
            'openingHours'   => ['Mo-Fr 09:00-17:00', 'Sa 10:00-14:00'],
            'street_address' => '1 Market Street',
            'locality'       => 'Springfield',
            'region'         => 'IL',
            'postal_code'    => '62701',
            'country'        => 'US',
        ]);

        $out = Schema_Generator::generate($this->post(), 'LocalBusiness');

        $this->assertSame('Corner Shop Ltd', $out['name']);
        $this->assertSame('https://corner.example/', $out['url']);
        $this->assertSame('+1 555 0100', $out['telephone']);
        $this->assertSame('$$', $out['priceRange']);
        $this->assertSame(['Mo-Fr 09:00-17:00', 'Sa 10:00-14:00'], $out['openingHours']);
        $this->assertSame([
            '@type'           => 'PostalAddress',
            'streetAddress'   => '1 Market Street',
            'addressLocality' => 'Springfield',
            'addressRegion'   => 'IL',
            'postalCode'      => '62701',
            'addressCountry'  => 'US',
        ], $out['address']);
    }

    /** A single opening-hours specification is still a list in the graph. */
    public function test_a_scalar_opening_hours_value_becomes_a_list(): void
    {
        update_option(Schema_Generator::SITE_PROFILE_OPTION, [
            'openingHours' => 'Mo-Su 00:00-23:59',
        ]);

        $out = Schema_Generator::generate($this->post(), 'LocalBusiness');

        $this->assertSame(['Mo-Su 00:00-23:59'], $out['openingHours']);
    }

    /**
     * The profile option is agent-written, so a value can be any shape.
     * Casting an array with (string) emits the literal "Array" into the graph
     * plus a PHP warning, so non-scalars must read as absent.
     */
    public function test_non_scalar_profile_values_are_treated_as_absent(): void
    {
        // Cleared so the WooCommerce fallback cannot supply what this case is
        // asserting the profile must not: the subject here is the cast, not
        // the fallback chain.
        $this->clear_store_options();
        update_option(Schema_Generator::SITE_PROFILE_OPTION, [
            'telephone'      => ['+1 555 0100'],
            'street_address' => ['1 Market Street'],
            'name'           => ['Corner Shop Ltd'],
        ]);

        $out = Schema_Generator::generate($this->post(), 'LocalBusiness');

        $this->assertArrayNotHasKey('telephone', $out);
        $this->assertArrayNotHasKey('address', $out);
        $this->assertSame(get_bloginfo('name'), $out['name']);
        foreach ($out as $value) {
            $this->assertNotSame('Array', $value);
        }
    }

    /**
     * With no profile written, a configured WooCommerce store still supplies
     * the address, so the branch is reachable on a stock commerce site rather
     * than only on one where an agent has filled in an option nothing writes
     * yet.
     */
    public function test_local_business_falls_back_to_the_woocommerce_store_address(): void
    {
        delete_option(Schema_Generator::SITE_PROFILE_OPTION);
        update_option('woocommerce_store_address', '2 Mill Lane');
        update_option('woocommerce_store_address_2', 'Unit 4');
        update_option('woocommerce_store_city', 'Shelbyville');
        update_option('woocommerce_store_postcode', '62565');
        update_option('woocommerce_default_country', 'US:IL');
        update_option('woocommerce_store_phone', '+1 555 0199');

        $out = Schema_Generator::generate($this->post(), 'LocalBusiness');

        $this->assertSame('+1 555 0199', $out['telephone']);
        $this->assertSame([
            '@type'           => 'PostalAddress',
            'streetAddress'   => '2 Mill Lane, Unit 4',
            'addressLocality' => 'Shelbyville',
            'addressRegion'   => 'IL',
            'postalCode'      => '62565',
            'addressCountry'  => 'US',
        ], $out['address']);
    }

    /** The site profile wins over the store settings where both are set. */
    public function test_the_site_profile_wins_over_the_store_settings(): void
    {
        update_option('woocommerce_store_phone', '+1 555 0199');
        update_option(Schema_Generator::SITE_PROFILE_OPTION, ['telephone' => '+1 555 0100']);

        $out = Schema_Generator::generate($this->post(), 'LocalBusiness');

        $this->assertSame('+1 555 0100', $out['telephone']);
    }

    /** On a site with neither source, the branch omits rather than invents. */
    public function test_local_business_omits_what_no_source_supplies(): void
    {
        delete_option(Schema_Generator::SITE_PROFILE_OPTION);
        $this->clear_store_options();

        $out = Schema_Generator::generate($this->post(), 'LocalBusiness');

        $this->assertArrayNotHasKey('address', $out);
        $this->assertArrayNotHasKey('telephone', $out);
        $this->assertArrayNotHasKey('openingHours', $out);
        $this->assertSame(get_bloginfo('name'), $out['name']);
    }

    /** Product on a plain post degrades to the post fields, never throwing. */
    public function test_product_on_a_plain_post_degrades_to_the_post_fields(): void
    {
        $id = $this->post(['post_title' => 'The corner shop']);

        $out = Schema_Generator::generate($id, 'Product');

        $this->assertSame('Product', $out['@type']);
        $this->assertSame('The corner shop', $out['name']);
        $this->assertArrayNotHasKey('offers', $out);
        $this->assertArrayNotHasKey('sku', $out);
    }

    /** With WooCommerce present, a real product carries sku and an Offer. */
    public function test_product_carries_the_woocommerce_sku_and_offer(): void
    {
        if (! function_exists('wc_get_product')) {
            $this->markTestSkipped('WooCommerce is not installed in this test environment.');
        }

        $id = $this->post(['post_type' => 'product', 'post_title' => 'A kettle']);
        $product = wc_get_product($id);
        $product->set_sku('KETTLE-1');
        $product->set_regular_price('19.99');
        $product->set_stock_status('instock');
        $product->save();

        $out = Schema_Generator::generate($id, 'Product');

        $this->assertSame('KETTLE-1', $out['sku']);
        $this->assertSame('Offer', $out['offers']['@type']);
        $this->assertSame('19.99', $out['offers']['price']);
        // Cast, because get_option() returns false where WooCommerce has not
        // written the currency option and the graph must still carry a string.
        $this->assertIsString($out['offers']['priceCurrency']);
        $this->assertSame((string) get_woocommerce_currency(), $out['offers']['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $out['offers']['availability']);
        $this->assertSame(get_permalink($id), $out['offers']['url']);
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
