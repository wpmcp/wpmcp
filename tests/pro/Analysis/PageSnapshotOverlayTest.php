<?php

namespace WPMCP\Tests\Pro\Analysis;

use WPMCP\Tools\Analysis\Analyze_Seo;
use WPMCP\Tools\Context\Get_Page_Snapshot;
use WPMCP\Pro\Gate;

/**
 * The pro side of the issue #81 seam: an audit overlay attaches its section
 * to the free digest through wpmcp_page_snapshot_sections without the free
 * tool knowing anything about it.
 */
class PageSnapshotOverlayTest extends \WP_UnitTestCase
{
    /** @var int[] */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_page_snapshot_sections');
        foreach ($this->created as $id) {
            wp_delete_post($id, true);
        }
        $this->created = [];
        Gate::set_pro_for_tests(null);
        wp_set_current_user(0);
        parent::tearDown();
    }

    public function test_the_seo_audit_attaches_as_an_overlay_section_when_opted_in(): void
    {
        $id = self::factory()->post->create([
            'post_status'  => 'publish',
            'post_content' => '<h1>Hello</h1><p>Some body copy for the audit to score.</p>',
        ]);
        $this->created[] = $id;

        // Exactly the wiring the pro layer does: one add_filter, no change to
        // any free file.
        add_filter('wpmcp_page_snapshot_sections', static function (array $snapshot, int $post_id, array $requested): array {
            if (! in_array('seo_audit', $requested, true)) {
                return $snapshot;
            }
            $snapshot['seo_audit'] = (new Analyze_Seo())->handle(['post_id' => $post_id]);
            return $snapshot;
        }, 10, 3);

        $without = (new Get_Page_Snapshot())->handle(['post_id' => $id]);
        $this->assertArrayNotHasKey('seo_audit', $without, 'the overlay must stay opt-in');

        $with = (new Get_Page_Snapshot())->handle(['post_id' => $id, 'sections' => ['seo_audit']]);

        $this->assertArrayHasKey('seo_audit', $with);
        $this->assertArrayHasKey('report', $with['seo_audit']);
        $this->assertSame($id, $with['seo_audit']['post_id']);
        // The free core sections are untouched by the overlay.
        $this->assertSame($id, $with['post_id']);
        $this->assertSame(1, $with['seo_lite']['h1_count']);
    }
}
