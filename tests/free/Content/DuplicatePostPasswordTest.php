<?php

namespace WPMCP\Tests\Free\Content;

use WPMCP\Tools\Content\Duplicate_Post;

/**
 * Issue #483: duplicate-post built the copy without post_password, so a
 * copy of a password-protected post was unprotected, and include_children
 * copied another user's published, password-protected child (content and
 * all) into a post the caller owns and can read.
 *
 * A copy now keeps its source's password, and a protected child the caller
 * cannot edit is skipped and listed in skipped_children.
 */
class DuplicatePostPasswordTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    /** @return array<string, array{0: string}> */
    public static function statuses(): array
    {
        return [
            'draft'   => [ 'draft' ],
            'pending' => [ 'pending' ],
            'private' => [ 'private' ],
            'publish' => [ 'publish' ],
        ];
    }

    /**
     * @dataProvider statuses
     */
    public function test_copy_keeps_the_source_password(string $status): void
    {
        $caller = self::factory()->user->create([ 'role' => 'editor' ]);
        $source = self::factory()->post->create([
            'post_author'   => $caller,
            'post_status'   => 'publish',
            'post_password' => 'secret',
            'post_content'  => 'members only',
        ]);

        wp_set_current_user($caller);
        $out = (new Duplicate_Post())->handle([ 'post_id' => $source, 'status' => $status ]);

        $this->assertSame('secret', get_post_field('post_password', $out['post_id']), "copy as {$status} keeps the password");
    }

    public function test_stage_keeps_the_source_password(): void
    {
        $caller = self::factory()->user->create([ 'role' => 'editor' ]);
        $source = self::factory()->post->create([
            'post_author'   => $caller,
            'post_status'   => 'publish',
            'post_password' => 'secret',
        ]);

        wp_set_current_user($caller);
        $out = (new Duplicate_Post())->handle([ 'post_id' => $source, 'stage' => true ]);

        $this->assertSame('secret', get_post_field('post_password', $out['post_id']));
    }

    public function test_contributor_does_not_get_another_users_protected_child(): void
    {
        $owner  = self::factory()->user->create([ 'role' => 'editor' ]);
        $caller = self::factory()->user->create([ 'role' => 'contributor' ]);
        $source = self::factory()->post->create([ 'post_author' => $caller, 'post_status' => 'draft' ]);
        $theirs = self::factory()->post->create([
            'post_author'   => $owner,
            'post_status'   => 'publish',
            'post_parent'   => $source,
            'post_password' => 'secret',
            'post_content'  => 'TOP SECRET',
        ]);
        $open   = self::factory()->post->create([
            'post_author' => $owner,
            'post_status' => 'publish',
            'post_parent' => $source,
            'post_title'  => 'open child',
        ]);
        $mine   = self::factory()->post->create([
            'post_author'   => $caller,
            'post_status'   => 'draft',
            'post_parent'   => $source,
            'post_password' => 'mine',
            'post_title'    => 'my protected child',
        ]);

        wp_set_current_user($caller);
        $out = (new Duplicate_Post())->handle([ 'post_id' => $source, 'include_children' => true ]);

        $this->assertSame([ $theirs ], $out['skipped_children'], 'the protected child is listed as skipped');
        $this->assertCount(2, $out['children']);
        foreach ($out['children'] as $child) {
            $this->assertNotSame('TOP SECRET', get_post_field('post_content', (int) $child), 'protected content not copied');
        }

        $by_title = [];
        foreach ($out['children'] as $child) {
            $by_title[ (string) get_post_field('post_title', (int) $child) ] = (int) $child;
        }
        ksort($by_title);
        $this->assertSame([ 'my protected child', 'open child' ], array_keys($by_title));
        $this->assertSame('mine', get_post_field('post_password', $by_title['my protected child']), 'own child keeps its password');
        $this->assertNotSame(0, $open);
        $this->assertNotSame(0, $mine);
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
    public function test_elevated_roles_copy_every_child_with_its_password(string $role): void
    {
        $owner  = self::factory()->user->create([ 'role' => 'author' ]);
        $caller = self::factory()->user->create([ 'role' => $role ]);
        $source = self::factory()->post->create([ 'post_author' => $caller, 'post_status' => 'draft' ]);
        foreach ([ 'one', 'two' ] as $password) {
            self::factory()->post->create([
                'post_author'   => $owner,
                'post_status'   => 'publish',
                'post_parent'   => $source,
                'post_password' => $password,
                'post_title'    => "child {$password}",
            ]);
        }

        wp_set_current_user($caller);
        $out = (new Duplicate_Post())->handle([ 'post_id' => $source, 'include_children' => true ]);

        $this->assertSame([], $out['skipped_children']);
        $passwords = [];
        foreach ($out['children'] as $child) {
            $passwords[ (string) get_post_field('post_title', (int) $child) ] = (string) get_post_field('post_password', (int) $child);
        }
        ksort($passwords);
        $this->assertSame([ 'child one' => 'one', 'child two' => 'two' ], $passwords, "children copied as {$role}");
    }

    public function test_handler_refuses_a_protected_source_the_caller_cannot_edit(): void
    {
        $owner  = self::factory()->user->create([ 'role' => 'editor' ]);
        $caller = self::factory()->user->create([ 'role' => 'contributor' ]);
        $source = self::factory()->post->create([
            'post_author'   => $owner,
            'post_status'   => 'publish',
            'post_password' => 'secret',
            'post_content'  => 'TOP SECRET',
        ]);

        wp_set_current_user($caller);
        $before = (int) wp_count_posts('post')->draft;
        try {
            (new Duplicate_Post())->handle([ 'post_id' => $source ]);
            $this->fail('A protected source the caller cannot edit was copied.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Post not found.', $e->getMessage());
        }
        wp_cache_flush();
        $this->assertSame($before, (int) wp_count_posts('post')->draft, 'no copy was made');
    }
}
