<?php

namespace WPMCP\Tests\Free\Content;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Compose\Build_Page;
use WPMCP\Tools\Content\Create_Post;
use WPMCP\Tools\Content\Duplicate_Post;
use WPMCP\Tools\Content\Update_Post;

/**
 * Issue #482: the content write tools are gated on edit_posts and the per-post
 * edit_post, which every Contributor holds for their own drafts, but never
 * asked the capabilities WordPress uses for publishing or for creating a
 * given post type. A Contributor could publish straight to the live site and
 * an Author (no edit_pages) could create and publish pages.
 *
 * The rule now matches core's REST posts controller: creating a post of a
 * type needs that type's create_posts, and the statuses publish, future and
 * private need its publish_posts. Drafts and pending posts stay open to
 * Contributors, and Editors and Administrators keep every status.
 */
class PublishCapabilityTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function as_role(string $role): int
    {
        $user = self::factory()->user->create([ 'role' => $role ]);
        wp_set_current_user($user);
        return $user;
    }

    /** @return array<string, array{0: string}> */
    public static function publishing_statuses(): array
    {
        return [
            'publish' => [ 'publish' ],
            'future'  => [ 'future' ],
            'private' => [ 'private' ],
        ];
    }

    /** How many posts of any status carry $title. */
    private function count_titled(string $title): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title = %s", $title));
    }

    private function assert_refused(callable $call, string $needle): void
    {
        try {
            $call();
        } catch (\Throwable $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
            return;
        }
        $this->fail('The call was not refused.');
    }

    // create-post.

    /**
     * @dataProvider publishing_statuses
     */
    public function test_contributor_cannot_create_a_post_in_a_publishing_status(string $status): void
    {
        $this->as_role('contributor');

        $this->assert_refused(
            fn () => (new Create_Post())->handle([ 'title' => 'Contributor live', 'status' => $status, 'content' => 'x' ]),
            'publish_posts'
        );
        $this->assertSame(0, $this->count_titled('Contributor live'), 'nothing was created');
    }

    public function test_contributor_cannot_create_a_page(): void
    {
        $this->as_role('contributor');

        $this->assert_refused(
            fn () => (new Create_Post())->handle([ 'title' => 'Live', 'status' => 'publish', 'post_type' => 'page', 'content' => 'x' ]),
            'edit_pages'
        );
        $this->assert_refused(
            fn () => (new Create_Post())->handle([ 'title' => 'Live', 'status' => 'draft', 'post_type' => 'page' ]),
            'edit_pages'
        );
        $this->assertSame(0, $this->count_titled('Live'));
    }

    public function test_author_cannot_create_a_page_but_can_publish_a_post(): void
    {
        $this->as_role('author');

        $this->assert_refused(
            fn () => (new Create_Post())->handle([ 'title' => 'Author page', 'status' => 'draft', 'post_type' => 'page' ]),
            'edit_pages'
        );
        $this->assertSame(0, $this->count_titled('Author page'));

        $out = (new Create_Post())->handle([ 'title' => 'Author post', 'status' => 'publish' ]);
        $this->assertSame('publish', get_post_status($out['post_id']));
    }

    public function test_contributor_can_still_create_drafts_and_submit_for_review(): void
    {
        $this->as_role('contributor');

        $draft   = (new Create_Post())->handle([ 'title' => 'A draft' ]);
        $pending = (new Create_Post())->handle([ 'title' => 'For review', 'status' => 'pending' ]);

        $this->assertSame('draft', get_post_status($draft['post_id']));
        $this->assertSame('pending', get_post_status($pending['post_id']));
    }

    /** @return array<string, array{0: string}> */
    public static function elevated_roles(): array
    {
        return [
            'editor'        => [ 'editor' ],
            'administrator' => [ 'administrator' ],
        ];
    }

    /**
     * @dataProvider elevated_roles
     */
    public function test_editors_and_administrators_keep_every_status_and_type(string $role): void
    {
        $this->as_role($role);

        foreach ([ 'publish', 'private', 'pending', 'draft' ] as $status) {
            foreach ([ 'post', 'page' ] as $type) {
                $out = (new Create_Post())->handle([ 'title' => "{$type} {$status}", 'status' => $status, 'post_type' => $type ]);
                $this->assertSame($status, get_post_status($out['post_id']), "{$role} {$type} {$status}");
                $this->assertSame($type, get_post_type($out['post_id']));
            }
        }
    }

    // update-post.

    /**
     * @dataProvider publishing_statuses
     */
    public function test_contributor_cannot_move_their_own_draft_into_a_publishing_status(string $status): void
    {
        $caller = $this->as_role('contributor');
        $post   = self::factory()->post->create([ 'post_author' => $caller, 'post_status' => 'draft' ]);

        $this->assert_refused(
            fn () => (new Update_Post())->handle([ 'post_id' => $post, 'status' => $status ]),
            'publish_posts'
        );
        $this->assertSame('draft', get_post_status($post));
    }

    public function test_contributor_can_still_update_a_draft_and_submit_it_for_review(): void
    {
        $caller = $this->as_role('contributor');
        $post   = self::factory()->post->create([ 'post_author' => $caller, 'post_status' => 'draft' ]);

        (new Update_Post())->handle([ 'post_id' => $post, 'title' => 'Edited' ]);
        $this->assertSame('Edited', get_the_title($post));

        (new Update_Post())->handle([ 'post_id' => $post, 'status' => 'pending' ]);
        $this->assertSame('pending', get_post_status($post));
    }

    public function test_author_can_publish_their_own_post(): void
    {
        $caller = $this->as_role('author');
        $post   = self::factory()->post->create([ 'post_author' => $caller, 'post_status' => 'draft' ]);

        (new Update_Post())->handle([ 'post_id' => $post, 'status' => 'publish' ]);
        $this->assertSame('publish', get_post_status($post));
    }

    // duplicate-post.

    /** @return array<string, array{0: string}> */
    public static function duplicate_publishing_statuses(): array
    {
        return [
            'publish' => [ 'publish' ],
            'private' => [ 'private' ],
        ];
    }

    /**
     * @dataProvider duplicate_publishing_statuses
     */
    public function test_contributor_cannot_publish_a_copy(string $status): void
    {
        $owner  = self::factory()->user->create([ 'role' => 'editor' ]);
        $source = self::factory()->post->create([ 'post_author' => $owner, 'post_status' => 'publish', 'post_title' => 'Src' ]);
        $this->as_role('contributor');

        $this->assert_refused(
            fn () => (new Duplicate_Post())->handle([ 'post_id' => $source, 'status' => $status, 'title' => 'Contributor live' ]),
            'publish_posts'
        );
        $this->assertSame(0, $this->count_titled('Contributor live'));
    }

    public function test_author_cannot_copy_a_page(): void
    {
        $owner  = self::factory()->user->create([ 'role' => 'editor' ]);
        $source = self::factory()->post->create([ 'post_type' => 'page', 'post_author' => $owner, 'post_status' => 'publish', 'post_title' => 'Page' ]);
        $this->as_role('author');

        foreach ([ 'publish', 'draft' ] as $status) {
            $this->assert_refused(
                fn () => (new Duplicate_Post())->handle([ 'post_id' => $source, 'status' => $status, 'title' => 'Author copy' ]),
                'edit_pages'
            );
        }
        $this->assertSame(0, $this->count_titled('Author copy'));
    }

    public function test_children_the_caller_cannot_create_refuse_the_whole_copy(): void
    {
        $caller = $this->as_role('author');
        $source = self::factory()->post->create([ 'post_author' => $caller, 'post_status' => 'draft', 'post_title' => 'Parent post' ]);
        self::factory()->post->create([ 'post_type' => 'page', 'post_author' => $caller, 'post_status' => 'draft', 'post_parent' => $source, 'post_title' => 'Child page' ]);

        $this->assert_refused(
            fn () => (new Duplicate_Post())->handle([ 'post_id' => $source, 'include_children' => true, 'title' => 'Parent copy' ]),
            'edit_pages'
        );
        $this->assertSame(0, $this->count_titled('Parent copy'), 'the parent was not copied either');
        $this->assertSame(1, $this->count_titled('Child page'));
    }

    public function test_contributor_can_still_copy_as_draft_or_pending_with_children(): void
    {
        $caller = $this->as_role('contributor');
        $source = self::factory()->post->create([ 'post_author' => $caller, 'post_status' => 'draft' ]);
        self::factory()->post->create([ 'post_author' => $caller, 'post_status' => 'draft', 'post_parent' => $source ]);

        $draft = (new Duplicate_Post())->handle([ 'post_id' => $source ]);
        $this->assertSame('draft', get_post_status($draft['post_id']));

        $pending = (new Duplicate_Post())->handle([ 'post_id' => $source, 'status' => 'pending', 'include_children' => true ]);
        $this->assertSame('pending', get_post_status($pending['post_id']));
        $this->assertCount(1, $pending['children']);
        $this->assertSame('pending', get_post_status($pending['children'][0]));
    }

    public function test_editor_can_still_publish_a_copy_of_a_page(): void
    {
        $source = self::factory()->post->create([ 'post_type' => 'page', 'post_status' => 'publish' ]);
        $this->as_role('editor');

        $out = (new Duplicate_Post())->handle([ 'post_id' => $source, 'status' => 'publish' ]);
        $this->assertSame('page', get_post_type($out['post_id']));
        $this->assertSame('publish', get_post_status($out['post_id']));
    }

    // build-page creates a page.

    public function test_build_page_needs_the_page_capabilities(): void
    {
        $spec = [ 'title' => 'Built page', 'content' => [ [ 'type' => 'paragraph', 'settings' => [ 'text' => 'Hello' ] ] ] ];

        $this->as_role('author');
        $this->assert_refused(fn () => (new Build_Page())->handle([ 'spec' => $spec ]), 'edit_pages');
        $this->assertSame(0, $this->count_titled('Built page'));

        $this->as_role('editor');
        $out = (new Build_Page())->handle([ 'spec' => $spec + [ 'status' => 'publish' ] ]);
        $this->assertSame('publish', get_post_status($out['post_id']));
    }
}
