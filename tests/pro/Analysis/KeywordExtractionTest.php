<?php

namespace WPMCP\Tests\Pro\Analysis;

use WPMCP\Pro\Gate;
use WPMCP\Tests\Free\Platform\RegisteredAbilities;
use WPMCP\Tools\Analysis\Extract_Content;

/**
 * Keyword extraction on extract-content (issue #295): passing keywords=N adds
 * the top N ranked single terms and 2 to 3 word phrases from the post's
 * builder-aware plain text, with title and headings weighted above body copy.
 *
 * Scoring contract the fixtures below pin exactly: each occurrence scores its
 * segment weight (title 3, heading 2, excerpt 1.5, body 1); a phrase's summed
 * weight is multiplied by 1.5 for two words and 2 for three. Ties break on
 * count, then on the term's byte order, so the ranking is deterministic.
 */
class KeywordExtractionTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
    }

    protected function tearDown(): void
    {
        remove_all_filters('locale');
        remove_all_filters('wpmcp_keyword_stopwords');
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function post(string $content, string $title = 'Notes', array $meta = []): int
    {
        $id = $this->factory()->post->create([
            'post_title'   => $title,
            'post_content' => $content,
            'post_excerpt' => '',
        ]);
        foreach ($meta as $key => $value) {
            update_post_meta($id, $key, $value);
        }
        return $id;
    }

    /** @return array<string,array{term:string,words:int,count:int,score:float}> keyed by term, in rank order. */
    private function keywords(int $post_id, int $limit = 50): array
    {
        $out = (new Extract_Content())->handle(['post_id' => $post_id, 'keywords' => $limit]);
        $this->assertArrayHasKey('keywords', $out);
        $keyed = [];
        foreach ($out['keywords'] as $row) {
            $keyed[ $row['term'] ] = $row;
        }
        return $keyed;
    }

    public function test_ranking_is_deterministic_on_a_fixture_post(): void
    {
        $id = $this->post(
            '<p>Coffee beans matter. Grinding coffee fresh helps. Water temperature matters for coffee.</p>'
            . '<p>Beans should be fresh. Water quality counts.</p>',
            'Coffee Brewing'
        );

        $first = (new Extract_Content())->handle(['post_id' => $id, 'keywords' => 5]);
        $again = (new Extract_Content())->handle(['post_id' => $id, 'keywords' => 5]);
        $this->assertSame($first['keywords'], $again['keywords']);

        $this->assertSame(
            ['coffee', 'brewing', 'beans', 'fresh', 'water'],
            array_column($first['keywords'], 'term')
        );
        $this->assertSame(
            ['term' => 'coffee', 'words' => 1, 'count' => 4, 'score' => 6.0],
            $first['keywords'][0]
        );
        $this->assertSame(1, $first['keywords'][1]['count']);
        $this->assertSame(3.0, $first['keywords'][1]['score']);
    }

    public function test_keywords_are_opt_in_and_limited_to_n(): void
    {
        $id = $this->post('<p>Alpha beta gamma delta epsilon.</p>');

        $plain = (new Extract_Content())->handle(['post_id' => $id]);
        $this->assertArrayNotHasKey('keywords', $plain);
        $this->assertArrayHasKey('summary', $plain);

        $two = (new Extract_Content())->handle(['post_id' => $id, 'keywords' => 2]);
        $this->assertCount(2, $two['keywords']);
    }

    public function test_heading_weight_outranks_raw_body_frequency_in_classic_html(): void
    {
        $id = $this->post('<h2>Kayak</h2><p>Paddle hard. Paddle often. Paddle daily.</p><h3>Kayak</h3>', 'Guide');

        $kw    = $this->keywords($id);
        $terms = array_keys($kw);

        $this->assertSame(2, $kw['kayak']['count']);
        $this->assertSame(4.0, $kw['kayak']['score']);
        $this->assertSame(3, $kw['paddle']['count']);
        $this->assertSame(3.0, $kw['paddle']['score']);
        $this->assertLessThan(array_search('paddle', $terms, true), array_search('kayak', $terms, true));
    }

    public function test_heading_weight_applies_to_heading_blocks_and_block_markup_does_not_leak(): void
    {
        $id = $this->post(
            '<!-- wp:heading --><h2 class="wp-block-heading">Kayak</h2><!-- /wp:heading -->'
            . '<!-- wp:paragraph --><p>Paddle hard. Paddle often. Paddle daily.</p><!-- /wp:paragraph -->'
            . '<!-- wp:heading {"level":3,"className":"is-style-fancy"} --><h3 class="wp-block-heading is-style-fancy">Kayak</h3><!-- /wp:heading -->',
            'Guide'
        );

        $kw = $this->keywords($id);

        $this->assertSame(4.0, $kw['kayak']['score']);
        $this->assertSame(3.0, $kw['paddle']['score']);
        foreach (['wp', 'heading', 'paragraph', 'block', 'wp-block-heading', 'is-style-fancy', 'fancy', 'level'] as $noise) {
            $this->assertArrayNotHasKey($noise, $kw, "block markup leaked: $noise");
        }
    }

    public function test_phrases_are_ranked_subsumed_and_never_cross_sentences(): void
    {
        $id = $this->post(
            '<p>Content marketing strategy drives growth. A content marketing strategy needs goals. '
            . 'Every content marketing strategy evolves.</p>'
            . '<p>Great writing. Coffee helps. Great writing! Coffee helps.</p>'
        );

        $kw = $this->keywords($id);

        $this->assertArrayHasKey('content marketing strategy', $kw);
        $this->assertSame(3, $kw['content marketing strategy']['words']);
        $this->assertSame(3, $kw['content marketing strategy']['count']);
        $this->assertSame(6.0, $kw['content marketing strategy']['score']);
        $this->assertSame('content marketing strategy', array_key_first($kw));

        // Both bigrams only ever occur inside the trigram, so they add nothing.
        $this->assertArrayNotHasKey('content marketing', $kw);
        $this->assertArrayNotHasKey('marketing strategy', $kw);

        $this->assertSame(2, $kw['great writing']['words']);
        $this->assertSame(2, $kw['great writing']['count']);
        $this->assertSame(3.0, $kw['great writing']['score']);
        $this->assertArrayHasKey('coffee helps', $kw);
        $this->assertArrayNotHasKey('writing coffee', $kw);
        $this->assertArrayNotHasKey('helps great', $kw);

        // A phrase seen once is noise, not a topic.
        $this->assertArrayNotHasKey('drives growth', $kw);
    }

    public function test_english_stopwords_are_dropped_from_terms_and_phrase_edges(): void
    {
        $id = $this->post('<p>The cat and the dog are in the garden with a ball. The cat is happy.</p>');

        $kw = $this->keywords($id);

        foreach (['cat', 'dog', 'garden', 'ball', 'happy'] as $word) {
            $this->assertArrayHasKey($word, $kw);
        }
        $this->assertSame(2, $kw['cat']['count']);
        foreach (['the', 'and', 'are', 'in', 'with', 'a', 'is', 'the cat'] as $stop) {
            $this->assertArrayNotHasKey($stop, $kw, "stopword kept: $stop");
        }
    }

    public function test_site_locale_stopwords_apply_and_unicode_words_stay_whole(): void
    {
        $content = '<p>Die Größe der Straße und die Größe des Hauses. Straße und Haus.</p>';

        add_filter('locale', static fn() => 'de_DE');
        $kw = $this->keywords($this->post($content));

        $this->assertSame(2, $kw['größe']['count']);
        $this->assertSame(2, $kw['straße']['count']);
        $this->assertArrayHasKey('hauses', $kw);
        foreach (['die', 'der', 'und', 'des'] as $stop) {
            $this->assertArrayNotHasKey($stop, $kw, "German stopword kept: $stop");
        }

        // The German list is the SITE locale's, not a guess: an English site keeps "und".
        remove_all_filters('locale');
        add_filter('locale', static fn() => 'en_US');
        $this->assertArrayHasKey('und', $this->keywords($this->post($content)));
    }

    public function test_language_without_a_list_still_tokenizes_and_the_filter_extends_it(): void
    {
        add_filter('locale', static fn() => 'ru_RU');
        $content = '<p>Москва большой город. Москва красивая. Большой город. Москва.</p>';

        $kw = $this->keywords($this->post($content));
        $this->assertSame('москва', array_key_first(array_filter($kw, static fn($r) => 1 === $r['words'])));
        $this->assertSame(3, $kw['москва']['count']);
        $this->assertSame(2, $kw['большой город']['count']);

        add_filter(
            'wpmcp_keyword_stopwords',
            static fn(array $words, string $language) => 'ru' === $language ? array_merge($words, ['большой']) : $words,
            10,
            2
        );
        $kw = $this->keywords($this->post($content));
        $this->assertArrayNotHasKey('большой', $kw);
        $this->assertArrayNotHasKey('большой город', $kw);
        $this->assertArrayHasKey('город', $kw);
    }

    public function test_elementor_copy_is_read_from_element_settings_without_css_noise(): void
    {
        $elements = [[
            'id'       => 's1',
            'elType'   => 'section',
            'settings' => ['background_color' => '#ff0000', '_css_classes' => 'hero-x'],
            'elements' => [[
                'id'       => 'c1',
                'elType'   => 'column',
                'settings' => ['_column_size' => 100],
                'elements' => [
                    [
                        'id'         => 'w1',
                        'elType'     => 'widget',
                        'widgetType' => 'heading',
                        'settings'   => ['title' => 'Espresso Machines', 'link' => ['url' => 'https://example.com/shop']],
                        'elements'   => [],
                    ],
                    [
                        'id'         => 'w2',
                        'elType'     => 'widget',
                        'widgetType' => 'text-editor',
                        'settings'   => ['editor' => '<p>Our espresso machines brew rich espresso.</p>', 'text_color' => '#333333'],
                        'elements'   => [],
                    ],
                ],
            ]],
        ]];

        $id = $this->post(
            // Elementor's rendered fallback copy: must not double-count.
            '<p>Our espresso machines brew rich espresso.</p>',
            'Home',
            ['_elementor_edit_mode' => 'builder', '_elementor_data' => wp_slash(wp_json_encode($elements))]
        );

        $kw = $this->keywords($id);

        $this->assertSame(3, $kw['espresso']['count']);
        $this->assertSame(4.0, $kw['espresso']['score']);
        $this->assertSame(2, $kw['espresso machines']['count']);
        $this->assertSame(4.5, $kw['espresso machines']['score']);
        foreach (['ff0000', '333333', 'hero-x', 'https', 'example', 'shop', 'com'] as $noise) {
            $this->assertArrayNotHasKey($noise, $kw, "builder noise leaked: $noise");
        }
    }

    public function test_shortcode_builder_markup_is_stripped(): void
    {
        $id = $this->post(
            '[et_pb_section fb_built="1"][et_pb_row][et_pb_column type="4_4"][et_pb_text admin_label="Text"]'
            . '<p>Hiking boots for rocky trails. Hiking boots last.</p>'
            . '[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]',
            'Gear',
            ['_et_pb_use_builder' => 'on']
        );

        $kw = $this->keywords($id);

        $this->assertSame(2, $kw['hiking boots']['count']);
        $this->assertArrayHasKey('trails', $kw);
        foreach (['et', 'pb', 'et_pb_text', 'text', 'admin', 'label', 'built', 'fb', 'column', 'section'] as $noise) {
            $this->assertArrayNotHasKey($noise, $kw, "shortcode markup leaked: $noise");
        }
    }

    public function test_extraction_is_read_only(): void
    {
        $id     = $this->post('<h2>Solar</h2><p>Solar panels save money.</p>', 'Solar Power');
        $before = get_post($id);
        $meta   = get_post_meta($id);

        $this->keywords($id);

        $after = get_post($id);
        $this->assertSame($before->post_content, $after->post_content);
        $this->assertSame($before->post_modified_gmt, $after->post_modified_gmt);
        $this->assertSame($meta, get_post_meta($id));
    }

    public function test_registered_ability_advertises_keywords_and_stays_read_only(): void
    {
        $found = null;
        foreach (RegisteredAbilities::all() as $ability) {
            if ('wpmcp/extract-content' === $ability->name) {
                $found = $ability;
            }
        }
        $this->assertNotNull($found);
        $this->assertSame('pro', $found->tier);
        $this->assertSame('integer', $found->input_schema['properties']['keywords']['type'] ?? null);
        $this->assertTrue($found->read_only_hint);
    }
}
