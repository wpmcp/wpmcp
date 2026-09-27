<?php

namespace WPMCP\Tests\Pro\SEO;

use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\SEO\Get_Term_SEO_Meta;
use WPMCP\Tools\SEO\SEO_Adapter;
use WPMCP\Tools\SEO\Term_SEO;
use WPMCP\Tools\SEO\Update_Term_SEO_Meta;

/**
 * Term-level SEO (issue #67): the same neutral input schema update-seo-meta
 * takes for posts, written to each plugin's term storage and read back in
 * one shape, with structured "unsupported" answers where a plugin lacks a
 * field or term storage altogether. Plus the safety half: every write is
 * snapshotted and rollback-operation restores it.
 */
class TermSeoTest extends \WP_UnitTestCase
{
    private const INPUT = [
        'title'         => 'Term SEO title',
        'description'   => 'Term SEO description',
        'focus_keyword' => 'term keyword',
        'canonical'     => 'https://example.com/canonical-term/',
        'noindex'       => true,
        'nofollow'      => true,
    ];

    /** Plugins with mapped term storage, and the fields each cannot keep. */
    private const TERM_PLUGINS = [
        'yoast'        => ['nofollow'],
        'rankmath'     => [],
        'seopress'     => ['focus_keyword'],
        'seoframework' => ['focus_keyword'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        SEO_Adapter::set_active_plugin_for_tests(null);
        Gate::set_pro_for_tests(null);
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function term(): \WP_Term
    {
        $id = self::factory()->category->create(['name' => 'Term ' . wp_generate_password(6, false)]);

        return get_term($id, 'category');
    }

    /**
     * The conformance guarantee for terms: one payload, every mapped plugin,
     * and every supported field reads back identically adapter to adapter.
     */
    public function test_same_input_reads_back_identically_on_every_mapped_plugin(): void
    {
        foreach (self::TERM_PLUGINS as $plugin => $unsupported) {
            SEO_Adapter::set_active_plugin_for_tests($plugin);
            $term = $this->term();

            Term_SEO::update($term, self::INPUT);
            $out = Term_SEO::get($term);

            $this->assertTrue($out['supported'], $plugin);
            $this->assertSame($plugin, $out['plugin']);
            $this->assertSame(Term_SEO::FIELDS, array_keys($out['fields']), "{$plugin}: fixed key set");
            $this->assertSame($unsupported, $out['unsupported_fields'], "{$plugin}: unsupported fields");

            foreach (self::INPUT as $field => $value) {
                $expected = in_array($field, $unsupported, true)
                    ? (is_bool($value) ? false : '')
                    : $value;
                $this->assertSame($expected, $out['fields'][$field], "{$plugin}: {$field}");
            }
        }
    }

    public function test_partial_write_leaves_other_fields_untouched_everywhere(): void
    {
        foreach (array_keys(self::TERM_PLUGINS) as $plugin) {
            SEO_Adapter::set_active_plugin_for_tests($plugin);
            $term = $this->term();

            Term_SEO::update($term, self::INPUT);
            Term_SEO::update($term, ['noindex' => false]);
            $out = Term_SEO::get($term)['fields'];

            $this->assertFalse($out['noindex'], $plugin);
            $this->assertSame('Term SEO title', $out['title'], $plugin);
            $this->assertSame('Term SEO description', $out['description'], $plugin);
        }
    }

    /** The key strings themselves, per plugin, so a wrong map cannot pass. */
    public function test_writes_land_in_each_plugins_real_storage(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('yoast');
        $term = $this->term();
        Term_SEO::update($term, ['title' => 'Y', 'noindex' => true]);
        $option = get_option('wpseo_taxonomy_meta');
        $this->assertSame('Y', $option['category'][$term->term_id]['wpseo_title']);
        $this->assertSame('noindex', $option['category'][$term->term_id]['wpseo_noindex']);

        SEO_Adapter::set_active_plugin_for_tests('rankmath');
        $term = $this->term();
        update_term_meta($term->term_id, 'rank_math_robots', ['noarchive']);
        Term_SEO::update($term, ['title' => 'R', 'noindex' => true]);
        $this->assertSame('R', get_term_meta($term->term_id, 'rank_math_title', true));
        $robots = get_term_meta($term->term_id, 'rank_math_robots', true);
        $this->assertContains('noindex', $robots);
        $this->assertContains('noarchive', $robots, 'directives outside the vocabulary are kept');

        SEO_Adapter::set_active_plugin_for_tests('seopress');
        $term = $this->term();
        Term_SEO::update($term, ['description' => 'S', 'nofollow' => true]);
        $this->assertSame('S', get_term_meta($term->term_id, '_seopress_titles_desc', true));
        $this->assertSame('yes', get_term_meta($term->term_id, '_seopress_robots_follow', true));

        SEO_Adapter::set_active_plugin_for_tests('seoframework');
        $term = $this->term();
        Term_SEO::update($term, ['title' => 'T', 'noindex' => true]);
        $data = get_term_meta($term->term_id, 'autodescription-term-settings', true);
        $this->assertSame('T', $data['doctitle']);
        $this->assertSame(1, $data['noindex']);
    }

    public function test_plugin_without_term_storage_answers_unsupported_not_error(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('surerank');
        $term = $this->term();

        $read = (new Get_Term_SEO_Meta())->handle(['taxonomy' => 'category', 'term_id' => $term->term_id]);
        $this->assertFalse($read['supported']);
        $this->assertSame('surerank', $read['plugin']);
        $this->assertNotEmpty($read['reason']);

        $write = (new Update_Term_SEO_Meta())->handle(['taxonomy' => 'category', 'term_id' => $term->term_id, 'title' => 'x']);
        $this->assertFalse($write['supported']);
        $this->assertSame([], $write['written']);
        $this->assertSame(['title'], $write['skipped_fields']);
        $this->assertArrayNotHasKey('operation_id', $write, 'nothing written, so nothing snapshotted');
        $this->assertSame([], get_term_meta($term->term_id));
    }

    public function test_unsupported_field_is_skipped_and_reported_while_the_rest_is_written(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('seopress');
        $term = $this->term();

        $out = (new Update_Term_SEO_Meta())->handle([
            'taxonomy'      => 'category',
            'slug'          => $term->slug,
            'title'         => 'Written',
            'focus_keyword' => 'not stored on terms',
        ]);

        $this->assertTrue($out['supported']);
        $this->assertSame(['title'], $out['written']);
        $this->assertSame(['focus_keyword'], $out['skipped_fields']);
        $this->assertSame('Written', $out['fields']['title']);
        $this->assertArrayHasKey('operation_id', $out);
    }

    /** Term-meta plugins: the term snapshot restores the prior values. */
    public function test_term_meta_write_is_snapshotted_and_rolled_back(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('rankmath');
        $term = $this->term();
        Term_SEO::update($term, ['title' => 'Before', 'description' => 'Before desc']);

        $out = (new Update_Term_SEO_Meta())->handle([
            'taxonomy'    => 'category',
            'term_id'     => $term->term_id,
            'title'       => 'After',
            'noindex'     => true,
        ]);

        $this->assertNotNull(Snapshot_Store::get_by_operation($out['operation_id']));
        $this->assertSame('After', Term_SEO::get($term)['fields']['title']);
        $this->assertTrue($out['recoverable']);

        $rolled = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($rolled['restored']);

        $restored = Term_SEO::get($term)['fields'];
        $this->assertSame('Before', $restored['title']);
        $this->assertSame('Before desc', $restored['description']);
        $this->assertFalse($restored['noindex']);
    }

    /** Yoast: the write snapshots the whole taxonomy option and restores it. */
    public function test_yoast_option_write_is_snapshotted_and_rolled_back(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('yoast');
        $term = $this->term();
        Term_SEO::update($term, ['title' => 'Yoast before']);

        $out = (new Update_Term_SEO_Meta())->handle([
            'taxonomy' => 'category',
            'term_id'  => $term->term_id,
            'title'    => 'Yoast after',
        ]);

        $snapshot = Snapshot_Store::get_by_operation($out['operation_id']);
        $this->assertNotNull($snapshot);
        $this->assertSame('Yoast after', Term_SEO::get($term)['fields']['title']);

        (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);

        $this->assertSame('Yoast before', Term_SEO::get($term)['fields']['title']);
    }

    public function test_write_refuses_a_user_who_cannot_edit_the_term(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('rankmath');
        $term = $this->term();

        wp_set_current_user(self::factory()->user->create(['role' => 'author']));

        $this->expectException(\RuntimeException::class);
        (new Update_Term_SEO_Meta())->handle(['taxonomy' => 'category', 'term_id' => $term->term_id, 'title' => 'x']);
    }

    public function test_write_requires_a_field(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('rankmath');
        $term = $this->term();

        $this->expectException(\InvalidArgumentException::class);
        (new Update_Term_SEO_Meta())->handle(['taxonomy' => 'category', 'term_id' => $term->term_id]);
    }

    public function test_read_returns_the_term_identity_and_fields(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('rankmath');
        $term = $this->term();
        Term_SEO::update($term, ['canonical' => 'https://example.com/c/']);

        wp_set_current_user(self::factory()->user->create(['role' => 'author']));
        $out = (new Get_Term_SEO_Meta())->handle(['taxonomy' => 'category', 'term_id' => $term->term_id]);

        $this->assertSame((int) $term->term_id, $out['term_id']);
        $this->assertSame('category', $out['taxonomy']);
        $this->assertSame('https://example.com/c/', $out['fields']['canonical']);
    }

    public function test_read_of_a_private_taxonomy_term_needs_edit_term(): void
    {
        SEO_Adapter::set_active_plugin_for_tests('rankmath');
        register_taxonomy('wpmcp_private_tax', 'post', ['public' => false, 'publicly_queryable' => false]);
        $id = self::factory()->term->create(['taxonomy' => 'wpmcp_private_tax', 'name' => 'Hidden']);

        wp_set_current_user(self::factory()->user->create(['role' => 'author']));

        try {
            $this->expectException(\RuntimeException::class);
            (new Get_Term_SEO_Meta())->handle(['taxonomy' => 'wpmcp_private_tax', 'term_id' => $id]);
        } finally {
            unregister_taxonomy('wpmcp_private_tax');
        }
    }
}
