<?php

namespace WPMCP\Tests\Free\Sync;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Sync\Change_Set_Applier;
use WPMCP\Tools\Sync\Change_Set_Builder;
use WPMCP\Tools\Sync\Change_Set_Format;

/**
 * Phase 2 of local-live sync (issue #192): applying a change set to a
 * target.
 *
 * The test site plays both roles. A "build session" edits objects through
 * Safe_Mutation exactly as the agent tools do, the change set is built, and
 * the site is then put into the state the LIVE site would be in (the
 * pre-session content, or a live-side edit) before the change set is
 * applied to it.
 *
 * The questions every test here is asking: does a sync ever write to
 * something that was not selected, does it ever overwrite a live-side edit
 * without being told to, and can every write it makes be undone?
 */
class ChangeSetApplierTest extends \WP_UnitTestCase
{
    /** A 1x1 transparent PNG, so attachment tests carry real bytes. */
    private const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    /** Edit an object the way an agent tool does: snapshot first, in a session. */
    private function session_edit(int $post_id, array $fields, string $session = 'build'): void
    {
        Safe_Mutation::run(
            [
                'object_type' => 'post',
                'object_id'   => $post_id,
                'session_id'  => $session,
                'tool_name'   => 'update-post',
                'args'        => $fields,
            ],
            static fn () => wp_update_post(wp_slash(array_merge(['ID' => $post_id], $fields)))
        );
    }

    private function apply(array $set, array $opts = []): array
    {
        return (new Change_Set_Applier())->apply($set, array_merge(['dry_run' => false, 'session_id' => 'sync-1'], $opts));
    }

    /** Re-seal an artifact a test has deliberately edited. */
    private function reseal(array $set): array
    {
        $set['checksum'] = Change_Set_Format::checksum($set);
        return $set;
    }

    private function outcome(array $report, string $key): array
    {
        foreach ($report['objects'] as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }
        $this->fail("No outcome reported for {$key}");
    }

    private function png_attachment(string $name = 'sync-pixel.png'): int
    {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- test fixture bytes.
        $upload = wp_upload_bits($name, null, base64_decode(self::PNG_B64));
        $this->assertEmpty($upload['error']);
        $id = wp_insert_attachment(
            ['post_mime_type' => 'image/png', 'post_title' => 'Pixel', 'post_status' => 'inherit'],
            $upload['file']
        );
        return (int) $id;
    }

    public function test_an_object_outside_the_selection_is_never_touched(): void
    {
        $selected  = self::factory()->post->create(['post_title' => 'Selected', 'post_content' => 'base']);
        $bystander = self::factory()->post->create(['post_title' => 'Bystander', 'post_content' => 'live only']);
        update_post_meta($bystander, 'live_counter', '41');

        $this->session_edit($selected, ['post_content' => 'built locally']);
        $set = (new Change_Set_Builder())->build(['session_id' => 'build']);

        // The live site: the selected page still holds its pre-session
        // content, and the bystander has moved on in ways local never saw.
        wp_update_post(['ID' => $selected, 'post_content' => 'base']);
        wp_update_post(['ID' => $bystander, 'post_content' => 'edited on live']);
        update_post_meta($bystander, 'live_counter', '42');
        $before_row  = get_post($bystander, ARRAY_A);
        $before_meta = get_post_meta($bystander);
        $ledger      = Snapshot_Store::row_count();

        $report = $this->apply($set);

        $this->assertSame('applied', $this->outcome($report, 'post:' . $selected)['outcome']);
        $this->assertSame('built locally', get_post_field('post_content', $selected));

        $this->assertSame($before_row, get_post($bystander, ARRAY_A), 'A sync must never write to an object outside the selection');
        $this->assertSame($before_meta, get_post_meta($bystander));
        $this->assertSame($ledger + 1, Snapshot_Store::row_count(), 'Exactly one undo point: the selected object');
        foreach ($report['objects'] as $row) {
            $this->assertNotSame('post:' . $bystander, $row['key']);
        }
    }

    public function test_both_sides_changed_is_reported_as_a_conflict_and_nothing_is_written(): void
    {
        $post = self::factory()->post->create(['post_content' => 'base']);
        $this->session_edit($post, ['post_content' => 'local version']);
        $set = (new Change_Set_Builder())->build(['session_id' => 'build']);

        // Live edited the same object after the base the change set knows.
        wp_update_post(['ID' => $post, 'post_content' => 'live version']);
        $ledger = Snapshot_Store::row_count();

        $report = $this->apply($set);
        $row    = $this->outcome($report, 'post:' . $post);

        $this->assertSame('conflicted', $row['outcome']);
        $this->assertStringContainsString('modified on the target', $row['reason']);
        $this->assertSame('live version', get_post_field('post_content', $post), 'Never a silent last-writer-wins');
        $this->assertSame($ledger, Snapshot_Store::row_count(), 'A refused object leaves no ledger row either');
        $this->assertSame(1, $report['summary']['conflicted']);
    }

    public function test_force_applies_a_conflicted_object_snapshot_first_so_rollback_restores_the_live_edit(): void
    {
        $post = self::factory()->post->create(['post_content' => 'base']);
        $this->session_edit($post, ['post_content' => 'local version']);
        $set = (new Change_Set_Builder())->build(['session_id' => 'build']);
        wp_update_post(['ID' => $post, 'post_content' => 'live version']);

        $report = $this->apply($set, ['force' => ['post:' . $post]]);
        $row    = $this->outcome($report, 'post:' . $post);

        $this->assertSame('applied', $row['outcome']);
        $this->assertNotEmpty($row['operation_id']);
        $this->assertSame('local version', get_post_field('post_content', $post));

        $this->assertTrue(Rollback_Service::restore_operation($row['operation_id']));
        $this->assertSame('live version', get_post_field('post_content', $post), 'The forced overwrite is undoable');
    }

    public function test_an_unmodified_target_is_updated_and_the_whole_sync_rolls_back_as_one_session(): void
    {
        $a = self::factory()->post->create(['post_content' => 'a base']);
        $b = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'B base', 'post_content' => 'b base']);
        $this->session_edit($a, ['post_content' => 'a new']);
        $this->session_edit($b, ['post_content' => 'b new', 'post_title' => 'B new']);
        $set = (new Change_Set_Builder())->build(['session_id' => 'build']);

        wp_update_post(['ID' => $a, 'post_content' => 'a base']);
        wp_update_post(['ID' => $b, 'post_content' => 'b base', 'post_title' => 'B base']);

        $report = $this->apply($set);

        $this->assertSame(2, $report['summary']['applied']);
        $this->assertSame('sync-1', $report['session_id']);
        $this->assertSame('a new', get_post_field('post_content', $a));
        $this->assertSame('b new', get_post_field('post_content', $b));

        Rollback_Service::restore_session('sync-1');
        $this->assertSame('a base', get_post_field('post_content', $a));
        $this->assertSame('b base', get_post_field('post_content', $b));
    }

    public function test_an_object_missing_on_the_target_is_created_and_rollback_deletes_it(): void
    {
        $post = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'New page', 'post_content' => 'base']);
        $this->session_edit($post, ['post_content' => 'fresh content']);
        $set = (new Change_Set_Builder())->build(['session_id' => 'build']);

        wp_delete_post($post, true); // The live site never had this page.

        $report = $this->apply($set);
        $row    = $this->outcome($report, 'post:' . $post);

        $this->assertSame('applied', $row['outcome']);
        $this->assertSame('create', $row['action']);
        $created = get_post((int) $row['target_id']);
        $this->assertSame('fresh content', $created->post_content);
        $this->assertSame('page', $created->post_type);

        Rollback_Service::restore_session('sync-1');
        $this->assertNull(get_post((int) $row['target_id']), 'Undoing a sync removes what it created');
    }

    public function test_a_local_deletion_is_reported_and_never_applied(): void
    {
        $post = self::factory()->post->create(['post_content' => 'keep me on live']);
        $this->session_edit($post, ['post_content' => 'edited']);
        wp_trash_post($post);
        $set = (new Change_Set_Builder())->build(['session_id' => 'build']);

        wp_untrash_post($post);
        wp_update_post(['ID' => $post, 'post_status' => 'publish', 'post_content' => 'keep me on live']);

        $report = $this->apply($set);
        $row    = $this->outcome($report, 'post:' . $post);

        $this->assertSame('skipped', $row['outcome']);
        $this->assertStringContainsString('deletion', $row['reason']);
        $this->assertSame('publish', get_post_status($post), 'Deletions are reported, never applied');
        $this->assertSame('keep me on live', get_post_field('post_content', $post));
    }

    public function test_dry_run_lists_the_plan_and_writes_nothing(): void
    {
        $post = self::factory()->post->create(['post_content' => 'base']);
        $this->session_edit($post, ['post_content' => 'planned']);
        $set = (new Change_Set_Builder())->build(['session_id' => 'build']);
        wp_update_post(['ID' => $post, 'post_content' => 'base']);
        $ledger = Snapshot_Store::row_count();

        $report = (new Change_Set_Applier())->apply($set, ['dry_run' => true]);
        $row    = $this->outcome($report, 'post:' . $post);

        $this->assertTrue($report['dry_run']);
        $this->assertSame('would_apply', $row['outcome']);
        $this->assertSame('update', $row['action']);
        $this->assertSame('base', get_post_field('post_content', $post));
        $this->assertSame($ledger, Snapshot_Store::row_count());
    }

    public function test_a_tampered_artifact_is_refused_before_anything_is_written(): void
    {
        $post = self::factory()->post->create(['post_content' => 'base']);
        $this->session_edit($post, ['post_content' => 'local']);
        $set = (new Change_Set_Builder())->build(['session_id' => 'build']);
        wp_update_post(['ID' => $post, 'post_content' => 'base']);

        $set['objects'][0]['data']['post_content'] = 'injected';

        try {
            $this->apply($set);
            $this->fail('An artifact that does not match its checksum must be refused');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('checksum', $e->getMessage());
        }
        $this->assertSame('base', get_post_field('post_content', $post));
    }

    public function test_an_occupied_attachment_id_is_never_overwritten_and_references_are_remapped(): void
    {
        $image = $this->png_attachment();
        $page  = self::factory()->post->create([
            'post_type'    => 'page',
            'post_content' => '<img class="wp-image-' . $image . '" src="x.png"> base',
        ]);
        update_post_meta($page, '_thumbnail_id', (string) $image);

        $this->session_edit($page, ['post_content' => '<img class="wp-image-' . $image . '" src="x.png"> built']);
        $set = (new Change_Set_Builder())->build(['session_id' => 'build']);
        $this->assertSame([$image], wp_list_pluck($set['dependencies']['attachments'], 'object_id'));
        $this->assertNotEmpty($set['dependencies']['attachments'][0]['bytes'], 'Media travels as bytes');

        // The live site: the page is at its base, and the attachment id is
        // held by an unrelated live post.
        wp_update_post(['ID' => $page, 'post_content' => '<img class="wp-image-' . $image . '" src="x.png"> base']);
        wp_delete_attachment($image, true);
        $occupant = wp_insert_post(['import_id' => $image, 'post_title' => 'Live post', 'post_content' => 'live', 'post_status' => 'publish']);
        $this->assertSame($image, $occupant);
        // Deleting the attachment dropped the page's _thumbnail_id; the live
        // page at its base still points at the origin id.
        update_post_meta($page, '_thumbnail_id', (string) $image);
        $occupant_row = get_post($occupant, ARRAY_A);

        $report = $this->apply($set);

        $this->assertSame($occupant_row, get_post($occupant, ARRAY_A), 'The live post holding the id is untouched');

        $this->assertSame('applied', $this->outcome($report, 'post:' . $page)['outcome']);
        $new_id = (int) get_post_meta($page, '_thumbnail_id', true);
        $this->assertNotSame($image, $new_id);
        $this->assertSame('attachment', get_post_type($new_id));
        $this->assertStringContainsString('wp-image-' . $new_id, get_post_field('post_content', $page));
        $this->assertStringNotContainsString('wp-image-' . $image . '"', get_post_field('post_content', $page));

        Rollback_Service::restore_session('sync-1');
        $this->assertNull(get_post($new_id), 'The imported attachment is removed by the rollback');
        $this->assertSame($occupant_row, get_post($occupant, ARRAY_A));
    }

    public function test_an_object_whose_media_cannot_be_placed_is_skipped_not_pushed_broken(): void
    {
        $image = $this->png_attachment();
        $page  = self::factory()->post->create(['post_content' => 'base']);
        $this->session_edit($page, ['post_content' => '<img class="wp-image-' . $image . '"> built']);
        $set = (new Change_Set_Builder())->build(['session_id' => 'build']);

        $set['dependencies']['attachments'][0]['bytes'] = null;
        $set['dependencies']['attachments'][0]['bytes_omitted'] = 'test';
        $set = $this->reseal($set);

        wp_update_post(['ID' => $page, 'post_content' => 'base']);
        wp_delete_attachment($image, true);

        $report = $this->apply($set);
        $row    = $this->outcome($report, 'post:' . $page);

        $this->assertSame('skipped', $row['outcome']);
        $this->assertStringContainsString('attachment:' . $image, $row['reason']);
        $this->assertSame('base', get_post_field('post_content', $page));
    }

    public function test_origin_urls_are_rewritten_to_the_target(): void
    {
        $post = self::factory()->post->create(['post_content' => 'base']);
        $this->session_edit($post, ['post_content' => 'see ' . home_url('/about/')]);
        $set = (new Change_Set_Builder())->build(['session_id' => 'build']);
        wp_update_post(['ID' => $post, 'post_content' => 'base']);

        // Pretend the change set was built on http://site.local.
        $set['objects'][0]['data']['post_content'] = 'see http://site.local/about/';
        $set['origin']['home_url'] = 'http://site.local';
        $set['origin']['site_url'] = 'http://site.local';
        $set = $this->reseal($set);

        $this->apply($set, ['force' => ['post:' . $post]]);

        $this->assertSame('see ' . home_url('/about/'), get_post_field('post_content', $post));
    }

    public function test_an_object_reverted_locally_is_not_pushed_over_the_target(): void
    {
        $post = self::factory()->post->create(['post_content' => 'base']);
        $this->session_edit($post, ['post_content' => 'tried something']);
        $this->session_edit($post, ['post_content' => 'base']);
        $set = (new Change_Set_Builder())->build(['session_id' => 'build']);

        $this->assertTrue($set['objects'][0]['unchanged']);

        wp_update_post(['ID' => $post, 'post_content' => 'live moved on']);
        $report = $this->apply($set);

        $this->assertSame('skipped', $this->outcome($report, 'post:' . $post)['outcome']);
        $this->assertSame('live moved on', get_post_field('post_content', $post));
    }

    public function test_a_target_already_holding_the_change_is_skipped_as_in_sync(): void
    {
        $post = self::factory()->post->create(['post_content' => 'base']);
        $this->session_edit($post, ['post_content' => 'same everywhere']);
        $set    = (new Change_Set_Builder())->build(['session_id' => 'build']);
        $ledger = Snapshot_Store::row_count();

        $row = $this->outcome($this->apply($set), 'post:' . $post);

        $this->assertSame('skipped', $row['outcome']);
        $this->assertStringContainsString('already', $row['reason']);
        $this->assertSame($ledger, Snapshot_Store::row_count());
    }

    public function test_missing_terms_are_created_and_existing_terms_are_never_modified(): void
    {
        $post     = self::factory()->post->create(['post_content' => 'base']);
        $fresh    = self::factory()->category->create(['slug' => 'fresh-term', 'name' => 'Fresh']);
        $existing = self::factory()->category->create(['slug' => 'shared-term', 'name' => 'Shared', 'description' => 'local desc']);

        Safe_Mutation::run(
            ['object_type' => 'post', 'object_id' => $post, 'session_id' => 'build', 'tool_name' => 'set-post-terms', 'args' => []],
            static fn () => wp_set_object_terms($post, [$fresh, $existing], 'category')
        );
        $set = (new Change_Set_Builder())->build(['session_id' => 'build']);

        // Live: no fresh-term at all, shared-term with its own description,
        // and the post still filed where it was before the session.
        wp_delete_term($fresh, 'category');
        wp_update_term($existing, 'category', ['description' => 'live desc']);
        wp_set_object_terms($post, [(int) get_option('default_category')], 'category');

        $report = $this->apply($set);

        $this->assertSame('applied', $this->outcome($report, 'post:' . $post)['outcome']);
        $created = get_term_by('slug', 'fresh-term', 'category');
        $this->assertInstanceOf(\WP_Term::class, $created);
        $this->assertSame('live desc', get_term($existing, 'category')->description, 'An existing live term is never rewritten');
        $slugs = wp_get_object_terms($post, 'category', ['fields' => 'slugs']);
        sort($slugs);
        $this->assertSame(['fresh-term', 'shared-term'], $slugs);

        Rollback_Service::restore_session('sync-1');
        $this->assertFalse(get_term_by('slug', 'fresh-term', 'category'), 'The created term is undone with the sync');
    }

    public function test_theme_mods_merge_per_key_and_a_key_changed_on_both_sides_conflicts(): void
    {
        $option = 'theme_mods_' . get_stylesheet();
        update_option($option, ['accent' => 'blue', 'layout' => 'wide']);

        Safe_Mutation::run(
            ['object_type' => 'option', 'object_id' => $option, 'session_id' => 'build', 'tool_name' => 'update-option', 'args' => []],
            static fn () => update_option($option, ['accent' => 'red', 'layout' => 'wide'])
        );
        $set = (new Change_Set_Builder())->build(['session_id' => 'build']);
        $key = 'option:' . $option;

        // Live changed a DIFFERENT key and added one of its own: merge.
        update_option($option, ['accent' => 'blue', 'layout' => 'boxed', 'live_only' => 'x']);
        $row = $this->outcome($this->apply($set), $key);
        $this->assertSame('applied', $row['outcome']);
        $this->assertSame(['accent' => 'red', 'layout' => 'boxed', 'live_only' => 'x'], get_option($option));

        // Live changed the SAME key: conflict, nothing written.
        update_option($option, ['accent' => 'green', 'layout' => 'boxed']);
        $row = $this->outcome($this->apply($set, ['session_id' => 'sync-2']), $key);
        $this->assertSame('conflicted', $row['outcome']);
        $this->assertStringContainsString('accent', $row['reason']);
        $this->assertSame(['accent' => 'green', 'layout' => 'boxed'], get_option($option));
    }

    public function test_a_serialized_object_in_artifact_meta_is_never_unserialized(): void
    {
        $post = self::factory()->post->create(['post_content' => 'base']);
        $this->session_edit($post, ['post_content' => 'local']);
        $set = (new Change_Set_Builder())->build(['session_id' => 'build']);
        wp_update_post(['ID' => $post, 'post_content' => 'base']);

        $set['objects'][0]['meta']['gadget'] = ['O:8:"stdClass":1:{s:1:"a";s:1:"b";}'];
        $set = $this->reseal($set);

        $report = $this->apply($set);

        $this->assertSame('applied', $this->outcome($report, 'post:' . $post)['outcome']);
        $this->assertSame('', get_post_meta($post, 'gadget', true), 'An object payload is refused, not written');
        $this->assertNotEmpty($report['warnings']);
    }

    public function test_a_live_side_post_type_is_refused_even_when_the_artifact_carries_it(): void
    {
        $post = self::factory()->post->create(['post_content' => 'base']);
        $this->session_edit($post, ['post_content' => 'local']);
        $set = (new Change_Set_Builder())->build(['session_id' => 'build']);
        wp_delete_post($post, true);

        $set['objects'][0]['post_type']         = 'shop_order';
        $set['objects'][0]['data']['post_type'] = 'shop_order';
        $set = $this->reseal($set);

        $report = $this->apply($set);
        $row    = $this->outcome($report, 'post:' . $post);

        $this->assertSame('skipped', $row['outcome']);
        $this->assertStringContainsString('never synced', $row['reason']);
        $this->assertNull(get_post($post));
    }
}
