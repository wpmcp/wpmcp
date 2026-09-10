<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Tools\Elementor\Resolve_Theme_Template;

/**
 * Resolver-style read for the theme builder (issue #61): which theme part wins
 * for a location, and why. Covers the three rules the meta-based resolver is
 * responsible for without Elementor Pro installed: a conditionless template
 * displays nowhere, a more specific include beats a general one, and an
 * exclude only disqualifies a candidate when the requested context actually
 * matches it.
 */
class ResolveThemeTemplateTest extends Structural_Harness
{
    /** @param array<int,string> $conditions */
    private function make_theme_template(string $type, array $conditions = [], string $title = 'Part'): int
    {
        $id = self::factory()->post->create([
            'post_type'  => 'elementor_library',
            'post_title' => $title,
        ]);
        update_post_meta($id, '_elementor_edit_mode', 'builder');
        update_post_meta($id, '_elementor_template_type', $type);
        if ($conditions) {
            update_post_meta($id, '_elementor_conditions', $conditions);
        }
        return $id;
    }

    public function test_rejects_a_location_that_is_not_a_theme_location(): void
    {
        $out = (new Resolve_Theme_Template())->handle(['location' => 'popup']);
        $this->assertWPError($out);
        $this->assertSame('invalid_location', $out->get_error_code());
    }

    public function test_requires_a_location(): void
    {
        $out = (new Resolve_Theme_Template())->handle([]);
        $this->assertWPError($out);
        $this->assertSame('missing_location', $out->get_error_code());
    }

    public function test_a_template_without_conditions_never_wins(): void
    {
        $bare = $this->make_theme_template('header');

        $out = (new Resolve_Theme_Template())->handle(['location' => 'header']);

        $this->assertIsArray($out);
        $this->assertNull($out['winner']);
        $this->assertSame(-1, $this->candidate($out, $bare)['score']);
    }

    public function test_a_more_specific_include_beats_a_general_one(): void
    {
        $general  = $this->make_theme_template('header', ['include/general'], 'General');
        $specific = $this->make_theme_template('header', ['include/singular/post'], 'Posts');

        $out = (new Resolve_Theme_Template())->handle([
            'location'  => 'header',
            'post_type' => 'post',
        ]);

        $this->assertSame($specific, $out['winner']);
        $this->assertGreaterThan(
            $this->candidate($out, $general)['score'],
            $this->candidate($out, $specific)['score']
        );
    }

    public function test_an_exclude_that_does_not_match_the_context_still_wins(): void
    {
        // The exact fixture ThemeBuilderTest writes. Elementor displays this
        // header everywhere except pages, so it must win for a post context.
        $tid = $this->make_theme_template('header', ['include/general', 'exclude/singular/page']);

        $out = (new Resolve_Theme_Template())->handle([
            'location'  => 'header',
            'post_type' => 'post',
        ]);

        $this->assertSame($tid, $out['winner']);
        $this->assertNull($this->candidate($out, $tid)['excluded_by']);
    }

    public function test_an_exclude_that_matches_the_context_disqualifies_the_candidate(): void
    {
        $tid = $this->make_theme_template('header', ['include/general', 'exclude/singular/page']);

        $out = (new Resolve_Theme_Template())->handle([
            'location'  => 'header',
            'post_type' => 'page',
        ]);

        $this->assertNull($out['winner']);
        $this->assertSame('exclude/singular/page', $this->candidate($out, $tid)['excluded_by']);
    }

    public function test_an_exclude_is_not_fatal_without_a_context(): void
    {
        $tid = $this->make_theme_template('header', ['include/general', 'exclude/singular/page']);

        $out = (new Resolve_Theme_Template())->handle(['location' => 'header']);

        $this->assertSame($tid, $out['winner']);
    }

    public function test_an_include_pinned_to_another_post_type_does_not_match(): void
    {
        $this->make_theme_template('header', ['include/singular/page'], 'Pages only');

        $out = (new Resolve_Theme_Template())->handle([
            'location'  => 'header',
            'post_type' => 'post',
        ]);

        $this->assertNull($out['winner']);
    }

    public function test_an_exact_post_id_include_outranks_a_post_type_include(): void
    {
        $target = self::factory()->post->create();
        $broad  = $this->make_theme_template('single', ['include/singular/post'], 'All posts');
        $exact  = $this->make_theme_template('single', ["include/singular/post/{$target}"], 'One post');

        $out = (new Resolve_Theme_Template())->handle([
            'location' => 'single',
            'post_id'  => $target,
        ]);

        $this->assertSame($exact, $out['winner']);
        $this->assertGreaterThan(
            $this->candidate($out, $broad)['score'],
            $this->candidate($out, $exact)['score']
        );
    }

    public function test_the_single_location_covers_the_single_post_template_type(): void
    {
        // Elementor stores "single" parts as single, single-post or
        // single-page; querying the bare location string misses the latter two.
        $tid = $this->make_theme_template('single-post', ['include/general'], 'Single post');

        $out = (new Resolve_Theme_Template())->handle(['location' => 'single']);

        $this->assertSame($tid, $out['winner']);
        $this->assertSame('single-post', $this->candidate($out, $tid)['template_type']);
    }

    public function test_the_archive_location_covers_search_results_and_404(): void
    {
        $search = $this->make_theme_template('search-results', ['include/general'], 'Search');
        $notfnd = $this->make_theme_template('error-404', ['include/general'], '404');

        $out = (new Resolve_Theme_Template())->handle(['location' => 'archive']);

        $ids = array_column($out['candidates'], 'template_id');
        $this->assertContains($search, $ids);
        $this->assertContains($notfnd, $ids);
    }

    public function test_a_draft_template_is_listed_but_never_wins(): void
    {
        $draft = $this->make_theme_template('footer', ['include/general'], 'Draft footer');
        wp_update_post(['ID' => $draft, 'post_status' => 'draft']);

        $out = (new Resolve_Theme_Template())->handle(['location' => 'footer']);

        $this->assertSame($draft, $this->candidate($out, $draft)['template_id']);
        $this->assertNull($out['winner']);
    }

    public function test_the_result_reports_totals_and_truncation(): void
    {
        $this->make_theme_template('footer', ['include/general']);

        $out = (new Resolve_Theme_Template())->handle(['location' => 'footer']);

        $this->assertSame(1, $out['total']);
        $this->assertFalse($out['truncated']);
    }

    public function test_post_id_alone_supplies_the_post_type_context(): void
    {
        // A caller that only knows the target id should get the same answer as
        // one that spells the post type out; the resolver derives it.
        $target = self::factory()->post->create(['post_type' => 'post']);
        $posts  = $this->make_theme_template('single', ['include/singular/post'], 'Posts');
        $pages  = $this->make_theme_template('single', ['include/singular/page'], 'Pages');

        $out = (new Resolve_Theme_Template())->handle([
            'location' => 'single',
            'post_id'  => $target,
        ]);

        $this->assertSame('post', $out['context']['post_type']);
        $this->assertSame($target, $out['context']['post_id']);
        $this->assertSame($posts, $out['winner']);
        $this->assertSame(-1, $this->candidate($out, $pages)['score']);
    }

    public function test_conditions_stored_as_part_arrays_are_scored(): void
    {
        // Conditions written by hand (or by an older Pro release) can arrive as
        // arrays of parts rather than slash strings; both shapes must score.
        $tid = $this->make_theme_template('header', [['include', 'singular', 'post']], 'Array parts');

        $out = (new Resolve_Theme_Template())->handle([
            'location'  => 'header',
            'post_type' => 'post',
        ]);

        $this->assertSame($tid, $out['winner']);
        $this->assertSame(2, $this->candidate($out, $tid)['score']);
    }

    public function test_a_matching_exclude_names_the_condition_that_disqualified_it(): void
    {
        $tid = $this->make_theme_template('header', ['include/general', 'exclude/singular/page']);

        $out = (new Resolve_Theme_Template())->handle([
            'location'  => 'header',
            'post_type' => 'page',
        ]);

        $this->assertNull($out['winner']);
        $this->assertSame('exclude/singular/page', $this->candidate($out, $tid)['excluded_by']);
    }

    /** @return array<string,mixed> */
    private function candidate(array $out, int $template_id): array
    {
        foreach ($out['candidates'] as $candidate) {
            if ($template_id === $candidate['template_id']) {
                return $candidate;
            }
        }
        $this->fail("Template {$template_id} is missing from the candidate list.");
    }
}
