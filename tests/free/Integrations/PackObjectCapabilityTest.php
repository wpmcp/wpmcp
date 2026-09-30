<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\ACF_Integration;
use WPMCP\Integrations\Integration_Dispatcher;
use WPMCP\MCP\Ability;
use WPMCP\MCP\Registrar;
use WPMCP\Pro\Gate;

/**
 * Integration pack operations name WordPress objects inside their `args`,
 * where the content tools' per-post check never looked (issue #450). Each op
 * now declares which args keys hold which kind of object ('objects' in its
 * definition), and the permission decision enforces the matching per-object
 * capability for every one of them, the same way it does for the content
 * tools' own post ids:
 *
 * - post: read_post for a read (and no password-protected content without
 *   edit_post), edit_post for a write, delete_post for a delete;
 * - term: edit_term / delete_term for writes;
 * - user: list_users to read another user, edit_user / delete_user to change one;
 * - comment: edit_comment unless it is an approved comment being read;
 * - entry: the capability the op declares for its host plugin's entries.
 *
 * Checked here as Contributor, Author, Editor and Administrator through a real
 * pack (ACF, whose field ops had no per-post check before) and a fixture pack
 * declaring one op per object kind.
 */
class PackObjectCapabilityTest extends \WP_UnitTestCase
{
    /** @var array<string,int> role => user id */
    private array $users = [];

    private int $owner;
    private int $published;
    private int $draft;
    private int $protected;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        foreach (['contributor', 'author', 'editor', 'administrator'] as $role) {
            $this->users[ $role ] = self::factory()->user->create(['role' => $role]);
        }
        $this->owner     = self::factory()->user->create(['role' => 'author']);
        $this->published = self::factory()->post->create(['post_author' => $this->owner]);
        $this->draft     = self::factory()->post->create(['post_author' => $this->owner, 'post_status' => 'draft']);
        $this->protected = self::factory()->post->create(['post_author' => $this->owner, 'post_password' => 'pw']);
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        wp_set_current_user(0);
        parent::tearDown();
    }

    /** @return array{0: Ability, 1: Ability} */
    private static function pair(Integration_Dispatcher $integration): array
    {
        return $integration->abilities();
    }

    private function permits(Integration_Dispatcher $integration, string $op, array $args): bool
    {
        [$read, $write] = self::pair($integration);
        $channel        = 'read' === ($integration->declared_objects()[ $op ]['mode'] ?? '') ? $read : $write;
        return (new Registrar())->would_permit($channel, ['operation' => $op, 'args' => $args]);
    }

    /** @return array<string, array{0: string, 1: bool}> role => [role, may change another user's post] */
    public static function roles(): array
    {
        return [
            'contributor'   => ['contributor', false],
            'author'        => ['author', false],
            'editor'        => ['editor', true],
            'administrator' => ['administrator', true],
        ];
    }

    /**
     * @dataProvider roles
     */
    public function test_acf_field_ops_enforce_the_per_post_capability(string $role, bool $elevated): void
    {
        $acf = new ACF_Integration();
        wp_set_current_user($this->users[ $role ]);

        $this->assertTrue($this->permits($acf, 'get-fields', ['post_id' => $this->published]), 'read a published post');
        $this->assertSame($elevated, $this->permits($acf, 'get-fields', ['post_id' => $this->draft]), 'read another user\'s draft');
        $this->assertSame($elevated, $this->permits($acf, 'get-fields', ['post_id' => $this->protected]), 'read a protected post');
        $this->assertSame($elevated, $this->permits($acf, 'validate-fields', ['post_id' => $this->draft, 'fields' => ['a' => 1]]));
        $this->assertSame($elevated, $this->permits($acf, 'update-fields', ['post_id' => $this->published, 'fields' => ['a' => 1]]), 'write another user\'s post');
        $this->assertSame($elevated, $this->permits($acf, 'batch-update-fields', ['updates' => [['post_id' => $this->published, 'fields' => ['a' => 1]]]]), 'batch write another user\'s post');

        $own = self::factory()->post->create(['post_author' => $this->users[ $role ], 'post_status' => 'draft']);
        $this->assertTrue($this->permits($acf, 'get-fields', ['post_id' => $own]), 'read own draft');
        $this->assertTrue($this->permits($acf, 'update-fields', ['post_id' => $own, 'fields' => ['a' => 1]]), 'write own draft');
        $this->assertTrue($this->permits($acf, 'batch-update-fields', ['updates' => [['post_id' => $own, 'fields' => ['a' => 1]]]]), 'batch write own draft');
    }

    public function test_the_owner_keeps_access_to_their_own_published_post(): void
    {
        $acf = new ACF_Integration();
        wp_set_current_user($this->owner);
        $this->assertTrue($this->permits($acf, 'update-fields', ['post_id' => $this->published, 'fields' => ['a' => 1]]));
        $this->assertTrue($this->permits($acf, 'get-fields', ['post_id' => $this->protected]));
    }

    /**
     * @dataProvider roles
     */
    public function test_every_object_kind_is_enforced(string $role, bool $elevated): void
    {
        $pack    = new Object_Kinds_Fixture_Pack();
        $admin   = 'administrator' === $role;
        $term    = self::factory()->category->create();
        $comment = self::factory()->comment->create(['comment_post_ID' => $this->published, 'user_id' => $this->owner, 'comment_approved' => '0']);
        wp_set_current_user($this->users[ $role ]);

        // Terms: core's edit_term needs manage_categories (Editor and up).
        $this->assertSame($elevated, $this->permits($pack, 'rename-term', ['term_id' => $term]), 'term write');
        $this->assertTrue($this->permits($pack, 'read-term', ['term_id' => $term]), 'term read of a public taxonomy');
        // Users: another user needs list_users to read and edit_user to change.
        $this->assertSame($admin, $this->permits($pack, 'read-user', ['user_id' => $this->owner]), 'read another user');
        $this->assertSame($admin, $this->permits($pack, 'edit-user', ['user_id' => $this->owner]), 'change another user');
        $this->assertTrue($this->permits($pack, 'read-user', ['user_id' => $this->users[ $role ]]), 'read yourself');
        // Comments: an unapproved comment on another user's post.
        $this->assertSame($elevated, $this->permits($pack, 'read-comment', ['comment_id' => $comment]), 'read a held comment');
        $this->assertSame($elevated, $this->permits($pack, 'edit-comment', ['comment_id' => $comment]), 'edit a comment');
        // Entries: the op's own entry capability, here manage_options.
        $this->assertSame($admin, $this->permits($pack, 'read-entry', ['entry_id' => 7]), 'read an entry');
        // Ids that name nothing are left to the op to answer "not found".
        $this->assertTrue($this->permits($pack, 'edit-comment', ['comment_id' => 999999]));
    }

    public function test_a_refused_invocation_never_reaches_the_handler(): void
    {
        wp_set_current_user($this->users['contributor']);
        $pack = new Object_Kinds_Fixture_Pack();
        [, $write] = self::pair($pack);
        $this->assertFalse((new Registrar())->is_permitted($write, ['operation' => 'edit-post', 'args' => ['post_id' => $this->published]]));
        $this->assertTrue((new Registrar())->is_permitted($write, ['operation' => 'edit-post', 'args' => ['post_id' => self::factory()->post->create(['post_author' => $this->users['contributor'], 'post_status' => 'draft'])]]));
    }
}

/**
 * A pack with one op per object kind, so the rule for each kind is exercised
 * without depending on which host plugins the test site has loaded.
 */
final class Object_Kinds_Fixture_Pack extends Integration_Dispatcher
{
    public function integration(): string
    {
        return 'object-kinds-fixture';
    }

    public function is_available(): bool
    {
        return true;
    }

    protected function operations(): array
    {
        $noop = static fn (): array => [];
        $id   = static fn (string $key): array => ['type' => 'object', 'properties' => [$key => ['type' => 'integer']]];
        return [
            'read-term'    => ['mode' => 'read', 'handler' => $noop, 'input_schema' => $id('term_id'), 'objects' => ['term_id' => 'term']],
            'rename-term'  => ['mode' => 'write', 'handler' => $noop, 'input_schema' => $id('term_id'), 'objects' => ['term_id' => 'term']],
            'read-user'    => ['mode' => 'read', 'handler' => $noop, 'input_schema' => $id('user_id'), 'objects' => ['user_id' => 'user']],
            'edit-user'    => ['mode' => 'write', 'handler' => $noop, 'input_schema' => $id('user_id'), 'objects' => ['user_id' => 'user']],
            'read-comment' => ['mode' => 'read', 'handler' => $noop, 'input_schema' => $id('comment_id'), 'objects' => ['comment_id' => 'comment']],
            'edit-comment' => ['mode' => 'write', 'handler' => $noop, 'input_schema' => $id('comment_id'), 'objects' => ['comment_id' => 'comment']],
            'read-entry'   => ['mode' => 'read', 'handler' => $noop, 'input_schema' => $id('entry_id'), 'capability' => 'manage_options', 'objects' => ['entry_id' => 'entry']],
            'edit-post'    => ['mode' => 'write', 'handler' => $noop, 'input_schema' => $id('post_id'), 'objects' => ['post_id' => 'post']],
        ];
    }
}
