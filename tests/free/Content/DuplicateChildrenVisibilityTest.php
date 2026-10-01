<?php

namespace WPMCP\Tests\Free\Content;

use WPMCP\Tools\Content\Duplicate_Post;

/**
 * Issue #465: duplicate-post with include_children read the children through
 * get_children(), whose default status is 'any', so another user's draft
 * or private child was copied, content and meta, into a post the caller
 * owns. Children are now read with the per-row read filter: only the
 * children the caller may read are copied.
 *
 * Checked as Contributor, Author, Editor and Administrator.
 */
class DuplicateChildrenVisibilityTest extends \WP_UnitTestCase
{
    /** @return array<string, array{0: string, 1: bool}> role => [role, may read others' drafts and private posts] */
    public static function roles(): array
    {
        return [
            'contributor'   => [ 'contributor', false ],
            'author'        => [ 'author', false ],
            'editor'        => [ 'editor', true ],
            'administrator' => [ 'administrator', true ],
        ];
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    /**
     * @dataProvider roles
     */
    public function test_only_children_the_caller_may_read_are_copied(string $role, bool $elevated): void
    {
        $owner  = self::factory()->user->create([ 'role' => 'editor' ]);
        $caller = self::factory()->user->create([ 'role' => $role ]);
        $source = self::factory()->post->create([ 'post_author' => $caller, 'post_status' => 'draft', 'post_title' => 'Source' ]);

        $titles = [];
        foreach ([ 'publish', 'draft', 'private', 'pending' ] as $status) {
            $title = "others {$status} child";
            self::factory()->post->create([ 'post_author' => $owner, 'post_status' => $status, 'post_parent' => $source, 'post_title' => $title ]);
            $titles[ $status ] = $title;
        }
        self::factory()->post->create([ 'post_author' => $caller, 'post_status' => 'draft', 'post_parent' => $source, 'post_title' => 'my child' ]);

        wp_set_current_user($caller);
        $out = (new Duplicate_Post())->handle([ 'post_id' => $source, 'include_children' => true ]);

        $copied = array_map(static fn ($id): string => (string) get_post_field('post_title', (int) $id), $out['children']);
        sort($copied);
        $expected = $elevated ? array_merge(array_values($titles), [ 'my child' ]) : [ $titles['publish'], 'my child' ];
        sort($expected);
        $this->assertSame($expected, $copied, "children copied as {$role}");
    }
}
