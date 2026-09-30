<?php

namespace WPMCP\Tests\Free\Content;

use WPMCP\MCP\Ability;
use WPMCP\Plugin;
use WPMCP\Pro\Gate;
use WPMCP\Tools\Content\Get_Post;
use WPMCP\Tools\Search\Content_Indexer;
use WPMCP\Tools\Search\Search_Content;

/**
 * Password-protected posts (issue #450). A protected post is published, so
 * core's read_post lets everyone read it, but core's editor and REST API
 * only hand its content (and excerpt) to a user who can edit the post or who
 * supplies the password. The content tools take no password, so a caller
 * who cannot edit the post gets no protected content: get-post returns the
 * post with its content and excerpt withheld and `protected` set, the way
 * the REST API does, and every other read that would return the content is
 * refused.
 */
class ProtectedPostContentTest extends \WP_UnitTestCase
{
    private const SECRET = 'marmalade-cipher';

    /** @var array<string,int> role => user id */
    private array $users = [];

    private int $owner;
    private int $protected;

    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        foreach (['contributor', 'author', 'editor', 'administrator'] as $role) {
            $this->users[ $role ] = self::factory()->user->create(['role' => $role]);
        }
        $this->owner     = self::factory()->user->create(['role' => 'author']);
        $this->protected = self::factory()->post->create([
            'post_author'   => $this->owner,
            'post_title'    => 'Members only',
            'post_content'  => '<!-- wp:paragraph --><p>The ' . self::SECRET . ' lives here.</p><!-- /wp:paragraph -->',
            'post_excerpt'  => 'Excerpt with the ' . self::SECRET,
            'post_password' => 'hunter2',
        ]);
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function permits(string $ability, array $input): bool
    {
        $registrar = Plugin::instance()->registrar();
        $a         = $registrar->get($ability);
        $this->assertInstanceOf(Ability::class, $a, $ability . ' is not registered');
        return $registrar->would_permit($a, $input);
    }

    private function revision(): int
    {
        return (int) wp_insert_post([
            'post_type'    => 'revision',
            'post_status'  => 'inherit',
            'post_parent'  => $this->protected,
            'post_author'  => $this->owner,
            'post_title'   => 'earlier',
            'post_content' => 'earlier ' . self::SECRET,
            'post_name'    => $this->protected . '-revision-v1',
        ]);
    }

    /** @return array<string, array<string, mixed>> reads that return the post's content */
    private function content_reads(): array
    {
        return [
            'wpmcp/get-page'       => ['id' => $this->protected],
            'wpmcp/parse-blocks'   => ['id' => $this->protected],
            'wpmcp/get-revision'   => ['revision_id' => $this->revision()],
            'wpmcp/duplicate-post' => ['post_id' => $this->protected],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function low_roles(): array
    {
        return ['contributor' => ['contributor'], 'author' => ['author']];
    }

    /** @return array<string, array{0: string}> */
    public static function high_roles(): array
    {
        return ['editor' => ['editor'], 'administrator' => ['administrator']];
    }

    /**
     * @dataProvider low_roles
     */
    public function test_get_post_withholds_protected_content_from_a_caller_who_cannot_edit_it(string $role): void
    {
        wp_set_current_user($this->users[ $role ]);
        $this->assertTrue($this->permits('wpmcp/get-post', ['post_id' => $this->protected]));

        $out = (new Get_Post())->handle(['post_id' => $this->protected]);
        $this->assertSame('Members only', $out['title']);
        $this->assertTrue($out['protected']);
        $this->assertSame('', $out['content']);
        $this->assertSame('', $out['excerpt']);
        $this->assertStringNotContainsString(self::SECRET, (string) wp_json_encode($out));
    }

    /**
     * @dataProvider low_roles
     */
    public function test_other_reads_of_protected_content_are_refused(string $role): void
    {
        wp_set_current_user($this->users[ $role ]);
        foreach ($this->content_reads() as $ability => $input) {
            $this->assertFalse($this->permits($ability, $input), $ability . ' as ' . $role);
        }
    }

    /**
     * @dataProvider high_roles
     */
    public function test_editors_and_administrators_keep_the_content(string $role): void
    {
        wp_set_current_user($this->users[ $role ]);
        $out = (new Get_Post())->handle(['post_id' => $this->protected]);
        $this->assertTrue($out['protected']);
        $this->assertStringContainsString(self::SECRET, $out['content']);
        $this->assertStringContainsString(self::SECRET, $out['excerpt']);
        foreach ($this->content_reads() as $ability => $input) {
            $this->assertTrue($this->permits($ability, $input), $ability . ' as ' . $role);
        }
    }

    public function test_the_owner_keeps_the_content(): void
    {
        wp_set_current_user($this->owner);
        $out = (new Get_Post())->handle(['post_id' => $this->protected]);
        $this->assertStringContainsString(self::SECRET, $out['content']);
        foreach ($this->content_reads() as $ability => $input) {
            $this->assertTrue($this->permits($ability, $input), $ability . ' as the owner');
        }
    }

    public function test_an_unprotected_post_reports_protected_false(): void
    {
        $open = self::factory()->post->create(['post_author' => $this->owner, 'post_content' => 'open text']);
        wp_set_current_user($this->users['contributor']);
        $out = (new Get_Post())->handle(['post_id' => $open]);
        $this->assertFalse($out['protected']);
        $this->assertSame('open text', $out['content']);
    }

    public function test_search_content_does_not_match_protected_content_for_a_caller_who_cannot_edit_it(): void
    {
        Content_Indexer::index_post($this->protected);

        wp_set_current_user($this->users['contributor']);
        $ids = array_column((new Search_Content())->handle(['query' => self::SECRET])['results'], 'object_id');
        $this->assertNotContains($this->protected, $ids);

        wp_set_current_user($this->users['editor']);
        $ids = array_column((new Search_Content())->handle(['query' => self::SECRET])['results'], 'object_id');
        $this->assertContains($this->protected, $ids);
    }
}
