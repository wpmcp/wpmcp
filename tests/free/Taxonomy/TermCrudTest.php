<?php

namespace WPMCP\Tests\Free\Taxonomy;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot;
use WPMCP\Tools\Terms\Create_Term;
use WPMCP\Tools\Terms\Delete_Term;
use WPMCP\Tools\Terms\Get_Term;
use WPMCP\Tools\Terms\List_Terms;
use WPMCP\Tools\Terms\Set_Term_Meta;
use WPMCP\Tools\Terms\Update_Term;

/**
 * Taxonomy term CRUD, and the part nothing else in the free MCP field has:
 * every term write is snapshot-first and reversible, including creation.
 *
 * The rollback tests are the ones that matter. A term that comes back from
 * an undo with a fresh term_id looks correct in wp-admin while being
 * silently detached from every post that was filed under it, so restoring
 * the original term_id and term_taxonomy_id is asserted directly rather
 * than inferred from the term existing again.
 */
class TermCrudTest extends \WP_UnitTestCase
{
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin);
    }

    public function test_creates_a_term_and_derives_its_slug_from_the_name(): void
    {
        $out = (new Create_Term())->handle(['taxonomy' => 'category', 'name' => 'Winter Boots']);

        $this->assertSame('Winter Boots', $out['term']['name']);
        $this->assertSame('winter-boots', $out['term']['slug']);
        $this->assertSame('category', $out['term']['taxonomy']);
        $this->assertNotEmpty($out['operation_id'], 'A term write must record an operation so it can be rolled back.');
    }

    public function test_rolling_back_a_creation_removes_the_term(): void
    {
        // The whole reason the term snapshot is keyed by (taxonomy, slug):
        // the term id does not exist before the write, so a creation could
        // not otherwise be captured or undone.
        $out = (new Create_Term())->handle(['taxonomy' => 'category', 'name' => 'Ephemeral']);
        $id  = $out['term']['term_id'];

        Rollback_Service::restore_operation((string) $out['operation_id']);

        $this->assertNull(get_term($id, 'category'), 'Rolling back a create-term must delete the term it created.');
    }

    public function test_refuses_a_duplicate_slug_and_points_at_update_term(): void
    {
        (new Create_Term())->handle(['taxonomy' => 'category', 'name' => 'Sale']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists');

        (new Create_Term())->handle(['taxonomy' => 'category', 'name' => 'Sale']);
    }

    public function test_unknown_taxonomy_names_the_registered_ones(): void
    {
        // An agent told "Invalid taxonomy" usually cannot self-correct; one
        // told which taxonomies exist can, in a single turn.
        try {
            (new Create_Term())->handle(['taxonomy' => 'nonsense', 'name' => 'X']);
            $this->fail('An unregistered taxonomy must be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Unknown taxonomy', $e->getMessage());
            $this->assertStringContainsString('category', $e->getMessage());
        }
    }

    public function test_updates_a_term_and_rollback_restores_the_previous_values(): void
    {
        $created = (new Create_Term())->handle([
            'taxonomy'    => 'category',
            'name'        => 'Original',
            'description' => 'first',
        ]);
        $id = $created['term']['term_id'];

        $out = (new Update_Term())->handle([
            'taxonomy'    => 'category',
            'term_id'     => $id,
            'name'        => 'Renamed',
            'description' => 'second',
        ]);

        $this->assertSame('Renamed', $out['term']['name']);

        Rollback_Service::restore_operation((string) $out['operation_id']);

        $restored = get_term($id, 'category');
        $this->assertSame('Original', $restored->name);
        $this->assertSame('first', $restored->description);
    }

    public function test_rename_snapshots_under_the_old_slug_not_the_new_one(): void
    {
        // Capturing under the incoming slug would record "nothing owned
        // this", turning the undo into a delete of the renamed term.
        $created = (new Create_Term())->handle(['taxonomy' => 'category', 'name' => 'Before']);
        $id      = $created['term']['term_id'];

        $out = (new Update_Term())->handle([
            'taxonomy' => 'category',
            'term_id'  => $id,
            'slug'     => 'after',
        ]);

        Rollback_Service::restore_operation((string) $out['operation_id']);

        $restored = get_term($id, 'category');
        $this->assertInstanceOf(\WP_Term::class, $restored, 'The renamed term must survive the rollback, not be deleted.');
        $this->assertSame('before', $restored->slug);
    }

    public function test_refuses_a_parent_cycle(): void
    {
        $parent = (new Create_Term())->handle(['taxonomy' => 'category', 'name' => 'Parent']);
        $child  = (new Update_Term());

        $childTerm = (new Create_Term())->handle([
            'taxonomy' => 'category',
            'name'     => 'Child',
            'parent'   => $parent['term']['term_id'],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cycle');

        $child->handle([
            'taxonomy' => 'category',
            'term_id'  => $parent['term']['term_id'],
            'parent'   => $childTerm['term']['term_id'],
        ]);
    }

    public function test_refuses_a_term_as_its_own_parent(): void
    {
        $term = (new Create_Term())->handle(['taxonomy' => 'category', 'name' => 'Self']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('its own parent');

        (new Update_Term())->handle([
            'taxonomy' => 'category',
            'term_id'  => $term['term']['term_id'],
            'parent'   => $term['term']['term_id'],
        ]);
    }

    public function test_deleting_a_term_and_rolling_back_reattaches_its_posts(): void
    {
        // The assertion that actually proves the restore is correct: a term
        // resurrected with a new term_taxonomy_id would exist but hold no
        // posts, which looks fine in wp-admin and is silently wrong.
        $created = (new Create_Term())->handle(['taxonomy' => 'category', 'name' => 'Filed Under']);
        $term_id = $created['term']['term_id'];

        $post_id = self::factory()->post->create();
        wp_set_object_terms($post_id, [$term_id], 'category');

        $out = (new Delete_Term())->handle(['taxonomy' => 'category', 'term_id' => $term_id]);
        $this->assertTrue($out['deleted']);
        $this->assertNull(get_term($term_id, 'category'));

        Rollback_Service::restore_operation((string) $out['operation_id']);

        $restored = get_term($term_id, 'category');
        $this->assertInstanceOf(\WP_Term::class, $restored, 'The deleted term must come back at its original id.');
        $this->assertSame($term_id, (int) $restored->term_id);

        $terms = wp_get_object_terms($post_id, 'category', ['fields' => 'ids']);
        $this->assertContains($term_id, array_map('intval', (array) $terms), 'The post must be filed under the restored term again.');
    }

    public function test_refuses_to_delete_the_default_term(): void
    {
        $default = (int) get_option('default_category');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('default term');

        (new Delete_Term())->handle(['taxonomy' => 'category', 'term_id' => $default]);
    }

    public function test_term_meta_writes_and_rolls_back(): void
    {
        $created = (new Create_Term())->handle(['taxonomy' => 'category', 'name' => 'Meta Holder']);
        $id      = $created['term']['term_id'];

        (new Set_Term_Meta())->handle(['taxonomy' => 'category', 'term_id' => $id, 'key' => 'colour', 'value' => 'red']);
        $out = (new Set_Term_Meta())->handle(['taxonomy' => 'category', 'term_id' => $id, 'key' => 'colour', 'value' => 'blue']);

        $this->assertSame('blue', get_term_meta($id, 'colour', true));

        Rollback_Service::restore_operation((string) $out['operation_id']);

        $this->assertSame('red', get_term_meta($id, 'colour', true));
    }

    public function test_term_meta_null_deletes_the_key(): void
    {
        $created = (new Create_Term())->handle(['taxonomy' => 'category', 'name' => 'Meta Delete']);
        $id      = $created['term']['term_id'];

        (new Set_Term_Meta())->handle(['taxonomy' => 'category', 'term_id' => $id, 'key' => 'gone', 'value' => 'x']);
        $out = (new Set_Term_Meta())->handle(['taxonomy' => 'category', 'term_id' => $id, 'key' => 'gone', 'value' => null]);

        $this->assertTrue($out['deleted']);
        $this->assertSame('', get_term_meta($id, 'gone', true));
    }

    public function test_protected_meta_keys_are_refused(): void
    {
        $created = (new Create_Term())->handle(['taxonomy' => 'category', 'name' => 'Protected']);

        $this->expectException(\InvalidArgumentException::class);

        (new Set_Term_Meta())->handle([
            'taxonomy' => 'category',
            'term_id'  => $created['term']['term_id'],
            'key'      => '_hidden',
            'value'    => 'x',
        ]);
    }

    public function test_list_includes_empty_terms_by_default(): void
    {
        // WordPress's get_terms() hides empty terms by default; ours must
        // not, or an agent is led to create a duplicate of a term that is
        // already there.
        (new Create_Term())->handle(['taxonomy' => 'category', 'name' => 'Never Used']);

        $out   = (new List_Terms())->handle(['taxonomy' => 'category']);
        $slugs = array_column($out['terms'], 'slug');

        $this->assertContains('never-used', $slugs);
        $this->assertIsInt($out['total']);
    }

    public function test_list_paginates_and_reports_a_total_beyond_the_page(): void
    {
        for ($i = 0; $i < 5; $i++) {
            (new Create_Term())->handle(['taxonomy' => 'post_tag', 'name' => 'Tag ' . $i]);
        }

        $out = (new List_Terms())->handle(['taxonomy' => 'post_tag', 'per_page' => 2, 'page' => 1]);

        $this->assertCount(2, $out['terms']);
        $this->assertGreaterThanOrEqual(5, $out['total'], 'The total must count every match, not just the page.');
    }

    public function test_get_term_returns_the_ancestor_path(): void
    {
        $top = (new Create_Term())->handle(['taxonomy' => 'category', 'name' => 'Men']);
        $mid = (new Create_Term())->handle([
            'taxonomy' => 'category',
            'name'     => 'Footwear',
            'parent'   => $top['term']['term_id'],
        ]);
        $leaf = (new Create_Term())->handle([
            'taxonomy' => 'category',
            'name'     => 'Shoes',
            'slug'     => 'mens-shoes',
            'parent'   => $mid['term']['term_id'],
        ]);

        $out = (new Get_Term())->handle(['taxonomy' => 'category', 'term_id' => $leaf['term']['term_id']]);

        $this->assertSame(['Men', 'Footwear'], array_column($out['term']['ancestors'], 'name'));
    }

    public function test_get_term_resolves_by_slug(): void
    {
        (new Create_Term())->handle(['taxonomy' => 'category', 'name' => 'By Slug']);

        $out = (new Get_Term())->handle(['taxonomy' => 'category', 'slug' => 'by-slug']);

        $this->assertSame('By Slug', $out['term']['name']);
    }

    public function test_missing_identifier_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Provide either term_id or slug');

        (new Get_Term())->handle(['taxonomy' => 'category']);
    }

    public function test_term_snapshot_key_splits_on_the_first_colon_only(): void
    {
        // A slug can contain a colon before sanitisation; a taxonomy cannot.
        // Splitting on the last colon would mis-key those terms.
        $this->assertSame(['category', 'a:b'], Snapshot::split_term_key('category:a:b'));
        $this->assertSame(['', ''], Snapshot::split_term_key('no-colon'));
    }
}
