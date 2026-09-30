<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Memory\Memory_Store;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Content\Delete_Post;
use WPMCP\Tools\Content\Duplicate_Post;
use WPMCP\Tools\Content\Update_Post;
use WPMCP\Tools\Media\Update_Media;
use WPMCP\Tools\ThemeBuilder\Delete_Site_Part;
use WPMCP\Tools\ThemeBuilder\Set_Site_Part_Status;
use WPMCP\Tools\ThemeBuilder\Template_Store;
use WPMCP\Tools\ThemeBuilder\Update_Site_Part;

/**
 * Issue #436: a write that changes a post's status (or one other field)
 * through wp_update_post() re-saves the whole row, and for a user without
 * unfiltered_html kses then rewrites the title, content and excerpt the call
 * never touched. These writes must leave the untouched columns byte for byte,
 * still fire the status transition hooks, and still filter the fields a call
 * does change.
 */
class StatusWriteFilterTest extends \WP_UnitTestCase
{
    use Raw_Post_Columns;

    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Template_Store::ensure_post_type();
        Memory_Store::ensure_post_type();
        Memory_Store::flush_rules_cache();
        $this->assert_kses_would_change_it();
    }

    private function site_part(string $status = 'publish'): int
    {
        $id = self::factory()->post->create([
            'post_type'   => Template_Store::POST_TYPE,
            'post_status' => $status,
            'post_title'  => 'Header',
        ]);
        update_post_meta($id, '_wpmcp_template_type', 'header');
        update_post_meta($id, '_wpmcp_template_conditions', ['include' => [['type' => 'entire_site']]]);
        update_post_meta($id, '_wpmcp_template_priority', 0);
        $this->make_raw($id);
        return (int) $id;
    }

    public function test_set_site_part_status_changes_only_the_status(): void
    {
        $id = $this->site_part();
        $this->act_as($this->author());
        $this->record_transitions();

        $out = (new Set_Site_Part_Status())->handle(['template_id' => $id, 'status' => 'draft']);
        $this->assertSame('draft', $out['status']);
        $this->assertSame('draft', get_post_status($id));
        $this->assert_raw($id);
        $this->assert_transitioned($id, 'publish', 'draft');

        (new Set_Site_Part_Status())->handle(['template_id' => $id, 'status' => 'publish']);
        $this->assertSame('publish', get_post_status($id));
        $this->assert_raw($id);
        $this->assert_transitioned($id, 'draft', 'publish');
        $this->assert_post_save_filters_hooked();
    }

    public function test_save_filters_are_back_when_a_status_write_throws(): void
    {
        $id = $this->site_part();
        $this->act_as($this->author());

        add_filter('wp_insert_post_data', [self::class, 'explode_on_insert'], 99);
        try {
            (new Set_Site_Part_Status())->handle(['template_id' => $id, 'status' => 'draft']);
            $this->fail('The status write should have failed.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('update refused', $e->getMessage() . ($e->getPrevious() ? $e->getPrevious()->getMessage() : ''));
        } finally {
            remove_filter('wp_insert_post_data', [self::class, 'explode_on_insert'], 99);
        }

        $this->assert_post_save_filters_hooked();
        $this->assert_raw($id);
    }

    /**
     * A save_post listener that writes a second post while the status change
     * is in flight gets the normal filters: only the status write's own
     * untouched columns are spared.
     */
    public function test_a_write_nested_in_the_status_change_is_still_filtered(): void
    {
        $id = $this->site_part();
        $this->act_as($this->author());

        $nested = 0;
        $writer = static function () use (&$nested): void {
            if (0 !== $nested) {
                return;
            }
            $nested = -1;
            $nested = (int) wp_insert_post(wp_slash([
                'post_title'   => self::raw_title(),
                'post_content' => self::raw_markup(),
                'post_status'  => 'draft',
            ]));
        };
        add_action('save_post_' . Template_Store::POST_TYPE, $writer);
        (new Set_Site_Part_Status())->handle(['template_id' => $id, 'status' => 'draft']);
        remove_action('save_post_' . Template_Store::POST_TYPE, $writer);

        $this->assertGreaterThan(0, $nested);
        $this->assertNotSame(self::raw_markup(), get_post($nested)->post_content);
        $this->assertNotSame(self::raw_title(), get_post($nested)->post_title);
        $this->assert_raw($id);
    }

    public function test_update_site_part_title_only_leaves_the_markup_alone(): void
    {
        $id = $this->site_part();
        $this->act_as($this->author());

        $out = (new Update_Site_Part())->handle(['template_id' => $id, 'title' => 'New <b>header</b>']);
        $this->assertNotInstanceOf(\WP_Error::class, $out);

        clean_post_cache($id);
        $this->assertSame('New header', get_post($id)->post_title, 'The changed title is still sanitized.');
        $this->assert_raw($id, ['post_content', 'post_excerpt']);
    }

    public function test_delete_site_part_trashes_without_rewriting_the_markup(): void
    {
        $id = $this->site_part();
        $this->act_as($this->author());
        $this->record_transitions();

        (new Delete_Site_Part())->handle(['template_id' => $id]);

        $this->assertSame('trash', get_post_status($id));
        $this->assert_raw($id);
        $this->assert_transitioned($id, 'publish', 'trash');
    }

    public function test_memory_approve_and_unpublish_change_only_the_status(): void
    {
        $id = Memory_Store::propose(['text' => 'Headers are built with the child theme.']);
        $this->assertIsInt($id);
        $this->make_raw($id, ['post_content', 'post_title']);
        $this->act_as($this->admin_without_unfiltered_html());
        $this->record_transitions();

        $this->assertTrue(Memory_Store::approve($id));
        $this->assertSame('publish', get_post_status($id));
        $this->assert_raw($id, ['post_content', 'post_title']);
        $this->assert_transitioned($id, 'pending', 'publish');

        $this->assertTrue(Memory_Store::unpublish($id));
        $this->assertSame('pending', get_post_status($id));
        $this->assert_raw($id, ['post_content', 'post_title']);
        $this->assert_transitioned($id, 'publish', 'pending');
    }

    private function authored_post(int $author, string $status = 'draft'): int
    {
        $id = (int) self::factory()->post->create(['post_author' => $author, 'post_status' => $status]);
        $this->make_raw($id);
        return $id;
    }

    public function test_update_post_status_only_leaves_the_text_alone(): void
    {
        $author = $this->author();
        $id     = $this->authored_post($author, 'draft');
        $this->act_as($author);
        $this->record_transitions();

        (new Update_Post())->handle(['post_id' => $id, 'status' => 'pending']);

        $this->assertSame('pending', get_post_status($id));
        $this->assert_raw($id);
        $this->assert_transitioned($id, 'draft', 'pending');
    }

    public function test_update_post_meta_only_leaves_the_text_alone(): void
    {
        $author = $this->author();
        $id     = $this->authored_post($author);
        $this->act_as($author);

        (new Update_Post())->handle(['post_id' => $id, 'meta' => ['wpmcp_note' => 'x']]);

        $this->assertSame('x', get_post_meta($id, 'wpmcp_note', true));
        $this->assert_raw($id);
    }

    public function test_update_post_content_edit_is_still_filtered(): void
    {
        $author = $this->author();
        $id     = $this->authored_post($author);
        $this->act_as($author);

        (new Update_Post())->handle(['post_id' => $id, 'content' => self::raw_markup()]);

        clean_post_cache($id);
        $content = get_post($id)->post_content;
        $this->assertStringNotContainsString('<iframe', $content);
        $this->assertStringNotContainsString('<script', $content);
        $this->assert_raw($id, ['post_excerpt', 'post_title']);
        $this->assert_post_save_filters_hooked();
    }

    public function test_delete_post_trash_leaves_the_text_alone(): void
    {
        $author = $this->author();
        $id     = $this->authored_post($author, 'publish');
        $this->act_as($author);
        $this->record_transitions();

        (new Delete_Post())->handle(['post_id' => $id]);

        $this->assertSame('trash', get_post_status($id));
        $this->assert_raw($id);
        $this->assert_transitioned($id, 'publish', 'trash');
    }

    public function test_discarding_a_stage_leaves_its_text_alone(): void
    {
        $this->act_as($this->admin_without_unfiltered_html());
        $original = (int) self::factory()->post->create(['post_status' => 'publish']);
        $stage    = (int) (new Duplicate_Post())->handle(['post_id' => $original, 'stage' => true])['post_id'];
        $this->make_raw($stage);

        (new Duplicate_Post())->handle(['discard_stage' => $stage]);

        $this->assertSame('trash', get_post_status($stage));
        $this->assert_raw($stage);
    }

    public function test_publishing_a_stage_trashes_it_without_rewriting_it(): void
    {
        $this->act_as($this->admin_without_unfiltered_html());
        $original = (int) self::factory()->post->create(['post_status' => 'publish']);
        $stage    = (int) (new Duplicate_Post())->handle(['post_id' => $original, 'stage' => true])['post_id'];
        $this->make_raw($stage);

        (new Duplicate_Post())->handle(['publish_stage' => $stage]);

        $this->assertSame('trash', get_post_status($stage));
        $this->assert_raw($stage);
    }

    private function attachment(): int
    {
        $id = (int) self::factory()->attachment->create(['post_mime_type' => 'image/jpeg', 'post_title' => 'Photo']);
        $this->make_raw($id);
        return $id;
    }

    public function test_update_media_alt_or_title_only_leaves_the_rest_alone(): void
    {
        $id = $this->attachment();
        $this->act_as($this->author());

        (new Update_Media())->handle(['media_id' => $id, 'title' => 'Sunset <b>x</b>']);

        clean_post_cache($id);
        $this->assertSame('Sunset x', get_post($id)->post_title, 'The changed title is still sanitized.');
        $this->assert_raw($id, ['post_content', 'post_excerpt']);
    }

    public function test_update_media_description_edit_is_still_filtered(): void
    {
        $id = $this->attachment();
        $this->act_as($this->author());

        (new Update_Media())->handle(['media_id' => $id, 'description' => self::raw_markup()]);

        clean_post_cache($id);
        $this->assertStringNotContainsString('<iframe', get_post($id)->post_content);
        $this->assert_raw($id, ['post_excerpt', 'post_title']);
    }
}
