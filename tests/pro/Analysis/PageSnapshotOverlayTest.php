<?php

namespace WPMCP\Tests\Pro\Analysis;

use WPMCP\MCP\Registrar;
use WPMCP\Plugin;
use WPMCP\Tools\Context\Get_Page_Snapshot;
use WPMCP\Pro\Gate;

/**
 * The pro side of the issue #81 seam: an audit overlay attaches its section
 * to the free digest through wpmcp_page_snapshot_sections without the free
 * tool knowing anything about it.
 *
 * The wiring below is the pattern the pro layer is documented to use, and
 * the important half of it is the permission re-check. get-page-snapshot
 * clears its OWN gate (edit_posts) before the filter runs; the overlay's
 * ability has its own capability, its own governance switch and its own
 * memory guardrails, none of which this call has passed. Calling a pro
 * handler straight from the filter would run it with the snapshot's gate,
 * which is a privilege escalation dressed as a convenience.
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

    /** The overlay wiring, exactly as the pro layer is meant to do it. */
    private function attach_seo_overlay(): void
    {
        add_filter('wpmcp_page_snapshot_sections', static function (array $snapshot, int $post_id, array $requested): array {
            if (! in_array('seo_audit', $requested, true)) {
                return $snapshot;
            }

            // The overlay runs another ability's handler, so it re-checks
            // that ability's own gates first: tier, capability, governance
            // and the memory guardrails, all of which live in is_permitted().
            $registrar = new Registrar();
            Plugin::instance()->register_abilities_into($registrar);
            $ability = $registrar->get('wpmcp/analyze-seo');
            if (null === $ability || ! $registrar->is_permitted($ability, ['post_id' => $post_id])) {
                return $snapshot;
            }

            $snapshot['seo_audit'] = call_user_func($ability->handler, ['post_id' => $post_id]);
            return $snapshot;
        }, 10, 3);
    }

    private function post(array $args): int
    {
        $id = self::factory()->post->create($args + ['post_status' => 'publish']);
        $this->created[] = (int) $id;
        return (int) $id;
    }

    public function test_the_seo_audit_attaches_as_an_overlay_section_when_opted_in(): void
    {
        $id = $this->post(['post_content' => '<h1>Hello</h1><p>Some body copy for the audit to score.</p>']);
        $this->attach_seo_overlay();

        $without = (new Get_Page_Snapshot())->handle(['post_id' => $id]);
        $this->assertArrayNotHasKey('seo_audit', $without, 'the overlay must stay opt-in');

        $with = (new Get_Page_Snapshot())->handle(['post_id' => $id, 'sections' => ['seo_audit']]);

        $this->assertArrayHasKey('seo_audit', $with);
        $this->assertArrayHasKey('report', $with['seo_audit']);
        $this->assertSame($id, $with['seo_audit']['post_id']);
        $this->assertSame(['seo_audit'], $with['sections']['rendered']);
        // The free core sections are untouched by the overlay.
        $this->assertSame($id, $with['post_id']);
        $this->assertSame(1, $with['seo_lite']['h1_count']);
    }

    public function test_the_overlay_cannot_reinstate_a_body_the_core_withheld(): void
    {
        $author = self::factory()->user->create(['role' => 'author']);
        $id     = $this->post([
            'post_author'   => $author,
            'post_password' => 'hunter2',
            'post_content'  => '<h1>Members only</h1><p>Paid subscriber copy.</p>',
        ]);
        $this->attach_seo_overlay();

        // A caller who holds edit_posts but cannot edit THIS post: the core
        // withholds the body, so the seam must not run an audit that would
        // read it back out of the database.
        wp_set_current_user(self::factory()->user->create(['role' => 'contributor']));

        $snap = (new Get_Page_Snapshot())->handle(['post_id' => $id, 'sections' => ['seo_audit']]);

        $this->assertArrayNotHasKey('seo_audit', $snap);
        $this->assertSame(['seo_audit'], $snap['sections']['withheld']);
        $this->assertSame('none', $snap['content_coverage']['source']);
    }

    public function test_the_overlay_declines_when_the_caller_fails_the_pro_abilitys_own_gate(): void
    {
        $id = $this->post(['post_content' => '<h1>Hello</h1><p>Body copy.</p>']);
        $this->attach_seo_overlay();

        // analyze-seo is a pro ability; without the licence its gate closes,
        // and the overlay must decline rather than run the handler anyway.
        Gate::set_pro_for_tests(false);

        $snap = (new Get_Page_Snapshot())->handle(['post_id' => $id, 'sections' => ['seo_audit']]);

        $this->assertArrayNotHasKey('seo_audit', $snap);
        $this->assertSame(['seo_audit'], $snap['sections']['unknown']);
        $this->assertSame($id, $snap['post_id']);
    }
}
