<?php

namespace WPMCP\Tests\Free\Context;

use WPMCP\Tools\Context\Get_Page_Snapshot;

/**
 * Core digest shape, the size bound, the per-post read gate and the overlay
 * seam for wpmcp/get-page-snapshot (issue #81).
 */
class GetPageSnapshotTest extends \WP_UnitTestCase
{
    private Get_Page_Snapshot $tool;

    /** @var int[] */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new Get_Page_Snapshot();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_page_snapshot_sections');
        foreach ($this->created as $id) {
            wp_delete_post($id, true);
        }
        $this->created = [];
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function post(array $args = []): int
    {
        $id = self::factory()->post->create($args + ['post_status' => 'publish']);
        if (is_wp_error($id)) {
            $this->fail('post creation failed: ' . $id->get_error_message());
        }
        $this->created[] = (int) $id;
        return (int) $id;
    }

    // ------------------------------------------------------------- shape

    public function test_one_call_returns_the_core_sections(): void
    {
        $id = $this->post([
            'post_content' => '<h1>Title</h1><p>Some words here</p>'
                . '<h2>Sub</h2><img src="/a.png" alt="A"><a href="/x">In</a>'
                . '<a href="https://external-site.test/y">Out</a>',
            'post_excerpt' => 'An excerpt.',
        ]);

        $snap = $this->tool->handle(['post_id' => $id]);

        foreach (['post_id', 'builder', 'content_coverage', 'structure', 'outline', 'media', 'links', 'seo_lite', 'truncated'] as $key) {
            $this->assertArrayHasKey($key, $snap, "missing core section {$key}");
        }
        $this->assertSame($id, $snap['post_id']);
        $this->assertSame('classic', $snap['builder']);
        $this->assertFalse($snap['truncated']);
        $this->assertSame(1, $snap['media']['image_count']);
        $this->assertSame(2, $snap['links']['link_count']);
        $this->assertSame(1, $snap['links']['internal']);
        $this->assertSame(1, $snap['links']['external']);
        $this->assertSame(1, $snap['seo_lite']['h1_count']);
        $this->assertSame(0, $snap['seo_lite']['images_missing_alt']);
        $this->assertTrue($snap['seo_lite']['has_excerpt']);
        $this->assertTrue($snap['content_coverage']['complete']);
    }

    public function test_outline_is_in_document_order_not_grouped_by_level(): void
    {
        $id = $this->post([
            'post_content' => '<h2>Alpha</h2><h1>Beta</h1><h3>Gamma</h3><h2>Delta</h2>',
        ]);

        $snap = $this->tool->handle(['post_id' => $id]);

        $this->assertSame(
            [['level' => 2, 'text' => 'Alpha'], ['level' => 1, 'text' => 'Beta'], ['level' => 3, 'text' => 'Gamma'], ['level' => 2, 'text' => 'Delta']],
            $snap['outline']
        );
    }

    public function test_gutenberg_pages_report_block_counts(): void
    {
        $id = $this->post([
            'post_content' => "<!-- wp:paragraph --><p>One</p><!-- /wp:paragraph -->\n"
                . "<!-- wp:paragraph --><p>Two</p><!-- /wp:paragraph -->\n"
                . "<!-- wp:heading --><h2>Head</h2><!-- /wp:heading -->",
        ]);

        $snap = $this->tool->handle(['post_id' => $id]);

        $this->assertSame('gutenberg', $snap['builder']);
        $this->assertSame(3, $snap['structure']['block_count']);
        $this->assertSame(2, $snap['structure']['block_counts']['core/paragraph']);
    }

    // -------------------------------------------------------- size bound

    public function test_pathological_page_is_capped_and_flags_truncated(): void
    {
        $html = '';
        for ($i = 0; $i < 260; $i++) {
            $html .= '<h2>Heading ' . $i . '</h2><a href="/l' . $i . '">Link ' . $i . '</a><img src="/i' . $i . '.png" alt="i">';
        }
        $id = $this->post(['post_content' => $html]);

        $snap = $this->tool->handle(['post_id' => $id]);

        $this->assertTrue($snap['truncated'], 'a 260-item page must report truncated');
        $this->assertCount(200, $snap['outline']);
        $this->assertCount(200, $snap['links']['items']);
        $this->assertCount(200, $snap['media']['images']);
        // The uncapped totals still report the real page.
        $this->assertSame(260, $snap['links']['link_count']);
        $this->assertSame(260, $snap['media']['image_count']);
    }

    public function test_individual_strings_are_clipped_so_the_response_stays_bounded(): void
    {
        $long = str_repeat('x', 5000);
        $id   = $this->post(['post_content' => '<h2>' . $long . '</h2><a href="/' . $long . '">' . $long . '</a>']);

        $snap = $this->tool->handle(['post_id' => $id]);

        $this->assertLessThanOrEqual(310, mb_strlen($snap['outline'][0]['text']));
        $this->assertLessThanOrEqual(510, mb_strlen($snap['links']['items'][0]['url']));
        $this->assertLessThanOrEqual(310, mb_strlen($snap['links']['items'][0]['text']));
    }

    public function test_byte_budget_survives_an_overlay_that_appends_after_the_cap(): void
    {
        $html = '';
        for ($i = 0; $i < 260; $i++) {
            $html .= '<a href="/link-' . $i . '-' . str_repeat('p', 200) . '">Anchor ' . $i . '</a>';
        }
        $id = $this->post(['post_content' => $html]);

        add_filter('wpmcp_page_snapshot_sections', static function (array $s): array {
            $s['bloat'] = str_repeat('z', 400000);
            return $s;
        });

        $snap = $this->tool->handle(['post_id' => $id]);

        $this->assertTrue($snap['truncated']);
        $this->assertLessThanOrEqual(Get_Page_Snapshot::MAX_BYTES, strlen((string) wp_json_encode($snap)));
    }

    // ---------------------------------------------------- heavy sections

    public function test_heavy_sections_are_excluded_by_default(): void
    {
        $id   = $this->post(['post_content' => '<p>Hi</p>']);
        $snap = $this->tool->handle(['post_id' => $id]);

        $this->assertArrayNotHasKey('global_tokens', $snap);
        $this->assertArrayNotHasKey('responsive_overrides', $snap);
    }

    public function test_heavy_sections_render_when_requested(): void
    {
        $id = $this->post([
            'post_content' => '<p class="has-vivid-red-color">Hi</p>'
                . '<div style="color:var(--wp--preset--color--primary)">x</div>',
        ]);

        $snap = $this->tool->handle(['post_id' => $id, 'sections' => ['global_tokens', 'responsive_overrides']]);

        $this->assertArrayHasKey('global_tokens', $snap);
        $this->assertContains('primary', $snap['global_tokens']['theme_presets']['color']);
        $this->assertContains('vivid-red', $snap['global_tokens']['theme_presets']['color']);
        $this->assertArrayHasKey('responsive_overrides', $snap);
        $this->assertFalse($snap['responsive_overrides']['supported']);
    }

    public function test_elementor_responsive_overrides_count_stored_breakpoint_keys(): void
    {
        $id   = $this->post(['post_content' => '']);
        $tree = [[
            'id'       => 'abc',
            'elType'   => 'section',
            'settings' => ['padding' => 1, 'padding_tablet' => 2, 'padding_mobile' => 3],
            'elements' => [[
                'id'       => 'def',
                'elType'   => 'widget',
                'settings' => ['align_mobile' => 'center'],
            ]],
        ]];
        update_post_meta($id, '_elementor_edit_mode', 'builder');
        update_post_meta($id, '_elementor_data', wp_json_encode($tree));

        $snap = $this->tool->handle(['post_id' => $id, 'sections' => ['responsive_overrides']]);

        $this->assertSame('elementor', $snap['builder']);
        $this->assertTrue($snap['responsive_overrides']['supported']);
        $this->assertSame(1, $snap['responsive_overrides']['breakpoints']['tablet']);
        $this->assertSame(2, $snap['responsive_overrides']['breakpoints']['mobile']);
        $this->assertSame(2, $snap['structure']['element_count']);
    }

    // -------------------------------------------------- content coverage

    public function test_builder_pages_declare_that_content_sections_were_not_measured(): void
    {
        $id = $this->post(['post_content' => '']);
        update_post_meta($id, '_elementor_edit_mode', 'builder');
        update_post_meta($id, '_elementor_data', wp_json_encode([['id' => 'a', 'elType' => 'section']]));

        $snap = $this->tool->handle(['post_id' => $id]);

        $this->assertSame('elementor', $snap['builder']);
        $this->assertFalse($snap['content_coverage']['complete'], 'an elementor page must not claim complete coverage');
        $this->assertContains('media', $snap['content_coverage']['unmeasured']);
        $this->assertNotSame('', $snap['content_coverage']['note']);
    }

    // -------------------------------------------------------- read gate

    public function test_a_contributor_cannot_digest_another_authors_draft(): void
    {
        $author = self::factory()->user->create(['role' => 'author']);
        $id     = $this->post(['post_status' => 'draft', 'post_author' => $author, 'post_content' => '<h1>Secret</h1>']);

        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));

        $this->expectException(\RuntimeException::class);
        $this->tool->handle(['post_id' => $id]);
    }

    public function test_a_missing_post_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tool->handle(['post_id' => 99999999]);
    }

    // ------------------------------------------------------ overlay seam

    public function test_an_overlay_can_append_a_section_and_see_its_own_opt_in_name(): void
    {
        $id   = $this->post(['post_content' => '<p>Hi</p>']);
        $seen = null;

        add_filter('wpmcp_page_snapshot_sections', static function (array $s, int $pid, array $requested) use (&$seen): array {
            $seen = $requested;
            if (in_array('seo_audit', $requested, true)) {
                $s['seo_audit'] = ['score' => 42, 'post_id' => $pid];
            }
            return $s;
        }, 10, 3);

        $snap = $this->tool->handle(['post_id' => $id, 'sections' => ['seo_audit']]);

        $this->assertSame(['seo_audit'], $seen, 'unknown section names must reach the overlay filter');
        $this->assertSame(42, $snap['seo_audit']['score']);
    }

    public function test_free_build_renders_with_no_callbacks_attached(): void
    {
        $id = $this->post(['post_content' => '<p>Hi</p>']);

        $this->assertFalse(has_filter('wpmcp_page_snapshot_sections'));
        $snap = $this->tool->handle(['post_id' => $id]);
        $this->assertSame($id, $snap['post_id']);
    }

    public function test_a_non_array_overlay_return_cannot_destroy_the_digest(): void
    {
        $id = $this->post(['post_content' => '<h1>Kept</h1>']);

        add_filter('wpmcp_page_snapshot_sections', static fn () => null);

        $snap = $this->tool->handle(['post_id' => $id]);

        $this->assertSame($id, $snap['post_id']);
        $this->assertSame('Kept', $snap['outline'][0]['text']);
    }

    public function test_an_overlay_cannot_overwrite_the_core_keys(): void
    {
        $id = $this->post(['post_content' => '<h1>Kept</h1>']);

        add_filter('wpmcp_page_snapshot_sections', static function (array $s): array {
            $s['post_id']  = 1;
            $s['outline']  = [];
            $s['seo_lite'] = ['h1_count' => 999];
            return $s;
        });

        $snap = $this->tool->handle(['post_id' => $id]);

        $this->assertSame($id, $snap['post_id']);
        $this->assertSame('Kept', $snap['outline'][0]['text']);
        $this->assertSame(1, $snap['seo_lite']['h1_count']);
    }

    // ------------------------------------------------ byte budget (issue #81)

    public function test_the_byte_budget_holds_when_the_overlay_alone_blows_it(): void
    {
        // Nothing to shed from the inventories: a one-paragraph page whose
        // overlay section alone is 400 KB. The only way to fit the budget is
        // to drop the overlay's own contribution.
        $id = $this->post(['post_content' => '<p>Small page.</p>']);

        add_filter('wpmcp_page_snapshot_sections', static function (array $s): array {
            $s['seo_audit'] = ['blob' => str_repeat('z', 400000)];
            return $s;
        });

        $snap = $this->tool->handle(['post_id' => $id, 'sections' => ['seo_audit']]);

        $this->assertLessThanOrEqual(
            Get_Page_Snapshot::MAX_BYTES,
            strlen((string) wp_json_encode($snap)),
            'the byte budget must bound an overlay that appends after the caps'
        );
        $this->assertTrue($snap['truncated']);
        $this->assertContains('seo_audit', $snap['sections']['dropped']);
        // The core digest survives the shedding.
        $this->assertSame($id, $snap['post_id']);
        $this->assertArrayHasKey('seo_lite', $snap);
    }

    public function test_the_byte_budget_holds_when_a_heavy_section_blows_it(): void
    {
        $id = $this->post(['post_content' => '<p>Small page.</p>']);
        update_post_meta($id, '_elementor_edit_mode', 'builder');
        update_post_meta($id, '_elementor_data', wp_json_encode([[
            'id'       => 'a',
            'elType'   => 'section',
            'settings' => ['padding_tablet' => str_repeat('q', 400000)],
        ]]));

        $snap = $this->tool->handle(['post_id' => $id, 'sections' => ['global_tokens']]);

        $this->assertLessThanOrEqual(Get_Page_Snapshot::MAX_BYTES, strlen((string) wp_json_encode($snap)));
        $this->assertSame($id, $snap['post_id']);
    }

    // --------------------------------------------------- section reporting

    public function test_unknown_section_names_are_reported_rather_than_silently_ignored(): void
    {
        $id   = $this->post(['post_content' => '<p>Hi</p>']);
        $snap = $this->tool->handle(['post_id' => $id, 'sections' => ['global_tokens', 'seo_audit']]);

        $this->assertSame(['global_tokens'], $snap['sections']['rendered']);
        $this->assertSame(['seo_audit'], $snap['sections']['unknown'], 'a section no build could render must say so');
    }

    // ------------------------------------------------------ global tokens

    public function test_background_color_preset_classes_report_the_slug_not_the_suffix(): void
    {
        $id = $this->post([
            'post_content' => '<p class="has-pale-pink-background-color has-vivid-red-color '
                . 'has-large-font-size has-midnight-gradient-background">Hi</p>',
        ]);

        $snap = $this->tool->handle(['post_id' => $id, 'sections' => ['global_tokens']]);

        $colors = $snap['global_tokens']['theme_presets']['color'];
        $this->assertContains('pale-pink', $colors, 'has-pale-pink-background-color is the pale-pink color preset');
        $this->assertContains('vivid-red', $colors);
        $this->assertNotContains('pale-pink-background', $colors);
        $this->assertContains('large', $snap['global_tokens']['theme_presets']['font-size']);
        $this->assertContains('midnight', $snap['global_tokens']['theme_presets']['gradient']);
    }

    // ------------------------------------------------ responsive overrides

    public function test_bricks_responsive_overrides_count_colon_suffixed_keys(): void
    {
        $id   = $this->post(['post_content' => '']);
        $tree = [[
            'id'       => 'abc',
            'name'     => 'section',
            'settings' => [
                '_padding'                  => ['top' => 10],
                '_padding:tablet_portrait'  => ['top' => 5],
                '_padding:mobile_portrait'  => ['top' => 2],
                '_typography:mobile_portrait' => ['font-size' => 12],
            ],
        ]];
        update_post_meta($id, '_bricks_page_content_2', wp_json_encode($tree));

        $snap = $this->tool->handle(['post_id' => $id, 'sections' => ['responsive_overrides']]);

        $this->assertSame('bricks', $snap['builder']);
        $this->assertTrue($snap['responsive_overrides']['supported']);
        $this->assertSame(1, $snap['responsive_overrides']['breakpoints']['tablet_portrait']);
        $this->assertSame(2, $snap['responsive_overrides']['breakpoints']['mobile_portrait']);
    }

    // -------------------------------------------------- content coverage

    public function test_an_elementor_page_with_leftover_post_content_still_declares_incomplete_coverage(): void
    {
        // The normal state of a page converted to a builder: post_content
        // still holds the pre-conversion markup, which is NOT what renders.
        $id = $this->post([
            'post_content' => '<h1>Stale classic heading</h1><img src="/old.png" alt="old"><a href="/old">Old</a>',
        ]);
        update_post_meta($id, '_elementor_edit_mode', 'builder');
        update_post_meta($id, '_elementor_data', wp_json_encode([['id' => 'a', 'elType' => 'section']]));

        $snap = $this->tool->handle(['post_id' => $id]);

        $this->assertSame('elementor', $snap['builder']);
        $this->assertFalse(
            $snap['content_coverage']['complete'],
            'leftover post_content is not the elementor page body, so coverage is not complete'
        );
        $this->assertContains('outline', $snap['content_coverage']['unmeasured']);
        $this->assertTrue(
            $snap['content_coverage']['stale_post_content'],
            'the digest must say the inventory came from stale post_content'
        );
    }

    public function test_divi_coverage_names_seo_lite_as_unmeasured_too(): void
    {
        $id = $this->post(['post_content' => '[et_pb_section][et_pb_text]Hi[/et_pb_text][/et_pb_section]']);
        update_post_meta($id, '_et_pb_use_builder', 'on');

        $snap = $this->tool->handle(['post_id' => $id]);

        $this->assertSame('divi', $snap['builder']);
        $this->assertContains('seo_lite', $snap['content_coverage']['unmeasured']);
    }

    // -------------------------------------------------------- read gate

    public function test_a_published_post_of_a_non_public_type_still_needs_the_read_gate(): void
    {
        register_post_type('wpmcp_test_private', [
            'public'          => false,
            'capability_type' => 'post',
            'map_meta_cap'    => true,
            'capabilities'    => [
                'edit_posts'           => 'manage_options',
                'edit_others_posts'    => 'manage_options',
                'edit_published_posts' => 'manage_options',
                'read_private_posts'   => 'manage_options',
            ],
        ]);
        $id = $this->post(['post_type' => 'wpmcp_test_private', 'post_content' => '<h1>Guardrail</h1>']);

        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));

        try {
            $this->expectException(\RuntimeException::class);
            $this->tool->handle(['post_id' => $id]);
        } finally {
            unregister_post_type('wpmcp_test_private');
        }
    }

    // ------------------------------------------- password protected posts

    public function test_a_protected_post_withholds_every_content_derived_section(): void
    {
        $author = self::factory()->user->create(['role' => 'author']);
        $id     = $this->post([
            'post_author'   => $author,
            'post_password' => 'hunter2',
            'post_content'  => "<!-- wp:paragraph --><p class=\"has-vivid-red-color\">Members only</p><!-- /wp:paragraph -->",
        ]);

        // A caller who can edit content in general but not THIS post.
        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));

        $snap = $this->tool->handle([
            'post_id'  => $id,
            'sections' => ['global_tokens', 'responsive_overrides'],
        ]);

        $this->assertSame('none', $snap['content_coverage']['source']);
        $this->assertSame([], $snap['outline']);
        $this->assertSame(0, $snap['structure']['word_count']);
        $this->assertArrayNotHasKey('block_counts', $snap['structure'], 'a withheld body must not be block-counted');
        $this->assertSame([], $snap['global_tokens']['theme_presets'], 'a withheld body must not be regexed for tokens');
        $this->assertSame([], $snap['responsive_overrides']['breakpoints']);
    }

    public function test_a_caller_who_can_edit_the_protected_post_still_gets_its_digest(): void
    {
        // The password prompt is a visitor-facing cookie check, not a
        // capability. An editor of the post reads the body through
        // wpmcp/get-post anyway, so withholding it here only made the digest
        // inconsistent with the surface it summarizes.
        $id = $this->post([
            'post_password' => 'hunter2',
            'post_content'  => '<h1>Members only</h1>',
        ]);

        $snap = $this->tool->handle(['post_id' => $id]);

        $this->assertSame('post_content', $snap['content_coverage']['source']);
        $this->assertSame('Members only', $snap['outline'][0]['text']);
    }

    // ------------------------------------------------------ overlay seam

    public function test_an_overlay_cannot_rewrite_a_heavy_section_the_caller_asked_for(): void
    {
        $id = $this->post(['post_content' => '<p class="has-vivid-red-color">Hi</p>']);

        add_filter('wpmcp_page_snapshot_sections', static function (array $s): array {
            $s['global_tokens'] = ['theme_presets' => ['color' => ['forged']]];
            $s['truncated']     = true;
            return $s;
        });

        $snap = $this->tool->handle(['post_id' => $id, 'sections' => ['global_tokens']]);

        $this->assertContains('vivid-red', $snap['global_tokens']['theme_presets']['color']);
        $this->assertNotContains('forged', $snap['global_tokens']['theme_presets']['color']);
    }

    public function test_an_overlay_truncation_flag_is_not_reset_by_the_core(): void
    {
        $id = $this->post(['post_content' => '<p>Hi</p>']);

        add_filter('wpmcp_page_snapshot_sections', static function (array $s): array {
            $s['seo_audit'] = ['truncated_its_own_list' => true];
            $s['truncated'] = true;
            return $s;
        });

        $snap = $this->tool->handle(['post_id' => $id, 'sections' => ['seo_audit']]);

        $this->assertTrue($snap['truncated'], "an overlay's own truncation must survive");
    }
}
