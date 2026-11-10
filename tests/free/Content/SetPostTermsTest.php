<?php

namespace WPMCP\Tests\Free\Content;

use WPMCP\Tools\Content\Set_Post_Terms;
use WPMCP\Tools\Content\Update_Post;
use WPMCP\Safety\{Snapshot_Store, Rollback_Service};

class SetPostTermsTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    public function test_replace_mode_overwrites_existing_terms(): void
    {
        $id       = self::factory()->post->create();
        $original = self::factory()->category->create();
        $term_a   = self::factory()->category->create();
        $term_b   = self::factory()->category->create();
        wp_set_object_terms($id, [$original], 'category');

        $out = (new Set_Post_Terms())->handle([
            'post_id'    => $id,
            'taxonomy'   => 'category',
            'terms'      => [$term_a, $term_b],
            'mode'       => 'replace',
            'session_id' => 's1',
        ]);

        $this->assertSame('category', $out['taxonomy']);
        $assigned = wp_get_post_terms($id, 'category', ['fields' => 'ids']);
        sort($assigned);
        $expected = [$term_a, $term_b];
        sort($expected);
        $this->assertSame($expected, $assigned);
    }

    public function test_append_mode_keeps_existing_terms(): void
    {
        $id       = self::factory()->post->create();
        $existing = self::factory()->tag->create();
        $new_term = self::factory()->tag->create();
        wp_set_object_terms($id, [$existing], 'post_tag');

        (new Set_Post_Terms())->handle([
            'post_id'  => $id,
            'taxonomy' => 'post_tag',
            'terms'    => [$new_term],
            'mode'     => 'append',
        ]);

        $assigned = wp_get_post_terms($id, 'post_tag', ['fields' => 'ids']);
        sort($assigned);
        $expected = [$existing, $new_term];
        sort($expected);
        $this->assertSame($expected, $assigned);
    }

    public function test_not_found_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Set_Post_Terms())->handle(['post_id' => 999999, 'taxonomy' => 'category', 'terms' => [1]]);
    }

    public function test_write_is_safe_wrapped_and_rollback_restores_original_terms(): void
    {
        $id       = self::factory()->post->create();
        $original = self::factory()->category->create();
        $new_term = self::factory()->category->create();
        wp_set_object_terms($id, [$original], 'category');

        $out = (new Set_Post_Terms())->handle([
            'post_id'    => $id,
            'taxonomy'   => 'category',
            'terms'      => [$new_term],
            'mode'       => 'replace',
            'session_id' => 's1',
        ]);

        $this->assertNotNull(Snapshot_Store::get_by_operation($out['operation_id']));
        $this->assertSame([$new_term], wp_get_post_terms($id, 'category', ['fields' => 'ids']));

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame([$original], wp_get_post_terms($id, 'category', ['fields' => 'ids']));
    }

    public function test_rollback_removes_a_term_the_call_created_and_keeps_existing_ones(): void
    {
        // Issue #588: wp_set_object_terms() creates a term for a name that
        // does not exist yet. The undo must remove that term again, while a
        // term that existed before the call stays.
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $id       = self::factory()->post->create();
        $existing = self::factory()->category->create(['name' => 'Already Here']);
        wp_set_object_terms($id, [$existing], 'category');

        $out = (new Set_Post_Terms())->handle([
            'post_id'  => $id,
            'taxonomy' => 'category',
            'terms'    => ['Already Here', 'Brand New News'],
            'mode'     => 'append',
        ]);
        $created = get_term_by('name', 'Brand New News', 'category');
        $this->assertInstanceOf(\WP_Term::class, $created);

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));

        $this->assertNull(get_term((int) $created->term_id, 'category'), 'The term the call created must be gone after the undo.');
        $this->assertInstanceOf(\WP_Term::class, get_term($existing, 'category'), 'A term that existed before the call is kept.');
        $this->assertSame([$existing], array_map('intval', wp_get_post_terms($id, 'category', ['fields' => 'ids'])));
        $this->assertSame([], Rollback_Service::take_warnings());
    }

    public function test_rollback_keeps_a_created_term_another_post_now_uses_and_warns(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $first  = self::factory()->post->create();
        $second = self::factory()->post->create();

        $out = (new Set_Post_Terms())->handle([
            'post_id'  => $first,
            'taxonomy' => 'post_tag',
            'terms'    => ['shared-new-tag'],
            'mode'     => 'append',
        ]);
        $tag = get_term_by('name', 'shared-new-tag', 'post_tag');
        wp_set_object_terms($second, [(int) $tag->term_id], 'post_tag');

        Rollback_Service::restore_operation($out['operation_id']);

        $this->assertInstanceOf(\WP_Term::class, get_term((int) $tag->term_id, 'post_tag'), 'A created term another post uses is kept.');
        $this->assertSame([], wp_get_post_terms($first, 'post_tag', ['fields' => 'ids']));
        $warnings = implode(' ', Rollback_Service::take_warnings());
        $this->assertStringContainsString((string) $tag->term_id, $warnings);
        $this->assertStringContainsString('kept', $warnings);
    }

    public function test_session_rollback_removes_a_created_term_the_session_also_used_elsewhere(): void
    {
        // rollback-session undoes both posts, so the term is unused once the
        // whole session is unwound, and goes.
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $first  = self::factory()->post->create();
        $second = self::factory()->post->create();

        (new Set_Post_Terms())->handle(['post_id' => $first, 'taxonomy' => 'category', 'terms' => ['Session News'], 'mode' => 'append', 'session_id' => 'terms-588']);
        $news = get_term_by('name', 'Session News', 'category');
        (new Set_Post_Terms())->handle(['post_id' => $second, 'taxonomy' => 'category', 'terms' => ['Session News'], 'mode' => 'replace', 'session_id' => 'terms-588']);
        (new Set_Post_Terms())->handle(['post_id' => $first, 'taxonomy' => 'post_tag', 'terms' => ['later-tag'], 'mode' => 'append', 'session_id' => 'terms-588']);
        $later = get_term_by('name', 'later-tag', 'post_tag');

        Rollback_Service::restore_session('terms-588');

        $this->assertNull(get_term((int) $news->term_id, 'category'), 'The session created the term and nothing uses it after the unwind.');
        $this->assertNull(get_term((int) $later->term_id, 'post_tag'), 'A term created by a later write to an already-restored post goes too.');
        $this->assertSame([], Rollback_Service::take_warnings());
    }

    public function test_update_post_rollback_removes_a_term_it_created(): void
    {
        // The same gap through another caller of wp_set_object_terms().
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $id = self::factory()->post->create();

        $out = (new Update_Post())->handle(['post_id' => $id, 'terms' => ['post_tag' => ['update-made-tag']]]);
        $tag = get_term_by('name', 'update-made-tag', 'post_tag');
        $this->assertInstanceOf(\WP_Term::class, $tag);

        Rollback_Service::restore_operation($out['operation_id']);

        $this->assertNull(get_term((int) $tag->term_id, 'post_tag'));
    }

    public function test_a_repeated_undo_does_not_touch_the_terms_again(): void
    {
        // With #593 an undone operation is never applied twice; the term the
        // first undo removed stays gone, and a same-named term made since is
        // not removed by a second undo.
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $id = self::factory()->post->create();

        $out     = (new Set_Post_Terms())->handle(['post_id' => $id, 'taxonomy' => 'category', 'terms' => ['Twice News'], 'mode' => 'append', 'session_id' => 'terms-588-twice']);
        $created = get_term_by('name', 'Twice News', 'category');

        $this->assertTrue(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertNull(get_term((int) $created->term_id, 'category'));

        $again = self::factory()->category->create(['name' => 'Twice News']);
        $this->assertFalse(Rollback_Service::restore_operation($out['operation_id']));
        $this->assertSame(0, Rollback_Service::restore_session('terms-588-twice'));
        $this->assertInstanceOf(\WP_Term::class, get_term($again, 'category'), 'A term made after the undo is not removed by a repeated undo.');
    }
}
