<?php

namespace WPMCP\Tests\Pro\SEO;

use WPMCP\Pro\Gate;
use WPMCP\Tests\Free\Platform\RegisteredAbilities;
use WPMCP\Tools\SEO\Generate_Schema_Markup;

/**
 * Tool-layer coverage for wpmcp/generate-schema-markup (issue #67): the
 * encoded payload, the script-safety of that encoding, the per-post read
 * check, and the pro tiering of the registration.
 */
class GenerateSchemaMarkupTest extends \WP_UnitTestCase
{
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            wp_delete_post($id, true);
        }
        $this->created = [];
        wp_set_current_user(0);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function post(array $args = []): int
    {
        $id = $this->factory()->post->create($args);
        $this->created[] = $id;
        return $id;
    }

    public function test_returns_decodable_json_ld(): void
    {
        $id  = $this->post(['post_title' => 'Encodable']);
        $out = (new Generate_Schema_Markup())->handle(['post_id' => $id, 'schema_type' => 'Article']);

        $this->assertSame($id, $out['post_id']);
        $this->assertSame('Article', $out['schema_type']);
        $this->assertIsString($out['json_ld']);

        $decoded = json_decode($out['json_ld'], true);
        $this->assertIsArray($decoded);
        $this->assertSame('Article', $decoded['@type']);
        $this->assertSame('Encodable', $decoded['headline']);
    }

    /**
     * The payload exists to be pasted inside <script type="application/ld+json">.
     * An author-controlled title containing a closing script tag must not be
     * able to end that block.
     */
    public function test_a_closing_script_tag_in_the_title_cannot_break_out(): void
    {
        $id  = $this->post(['post_title' => 'Breakout </script><script>alert(1)</script>']);
        $out = (new Generate_Schema_Markup())->handle(['post_id' => $id]);

        $this->assertStringNotContainsString('</script', $out['json_ld']);
        $this->assertStringNotContainsString('<script', $out['json_ld']);

        $decoded = json_decode($out['json_ld'], true);
        $this->assertIsArray($decoded);
        $this->assertStringContainsString('Breakout', $decoded['headline']);
    }

    public function test_a_missing_post_id_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Generate_Schema_Markup())->handle([]);
    }

    /**
     * A contributor holding edit_posts must not be able to read another
     * author's draft through the schema proposal, matching the read_post
     * check the content search already applies.
     */
    public function test_unreadable_draft_is_refused(): void
    {
        $author = $this->factory()->user->create(['role' => 'author']);
        $id     = $this->post(['post_status' => 'draft', 'post_author' => $author]);

        $other = $this->factory()->user->create(['role' => 'contributor']);
        wp_set_current_user($other);

        $this->expectException(\RuntimeException::class);
        (new Generate_Schema_Markup())->handle(['post_id' => $id]);
    }

    public function test_a_readable_draft_is_allowed_for_an_editor(): void
    {
        $id     = $this->post(['post_status' => 'draft', 'post_title' => 'Pre-publish']);
        $editor = $this->factory()->user->create(['role' => 'editor']);
        wp_set_current_user($editor);

        $out = (new Generate_Schema_Markup())->handle(['post_id' => $id]);

        $decoded = json_decode($out['json_ld'], true);
        $this->assertSame('Pre-publish', $decoded['headline']);
    }

    /**
     * A password-protected post is published, so a status-only check lets it
     * through. The generated graph carries the raw post_excerpt and the SEO
     * meta description, neither of which goes through the blanking
     * post_password_required() normally applies, so the password would be
     * bypassed for any edit_posts holder.
     */
    public function test_a_password_protected_post_is_refused(): void
    {
        $owner = $this->factory()->user->create(['role' => 'author']);
        $id    = $this->post([
            'post_password' => 'hunter2',
            'post_excerpt'  => 'The secret excerpt.',
            'post_author'   => $owner,
        ]);

        wp_set_current_user($this->factory()->user->create(['role' => 'author']));

        $this->expectException(\RuntimeException::class);
        (new Generate_Schema_Markup())->handle(['post_id' => $id]);
    }

    /** An editor, who can read it in wp-admin anyway, still gets the graph. */
    public function test_an_editor_may_generate_for_a_protected_post(): void
    {
        $id = $this->post(['post_password' => 'hunter2', 'post_title' => 'Members only']);

        wp_set_current_user($this->factory()->user->create(['role' => 'editor']));

        $out = (new Generate_Schema_Markup())->handle(['post_id' => $id]);

        $decoded = json_decode($out['json_ld'], true);
        $this->assertSame('Members only', $decoded['headline']);
    }

    public function test_registered_pro_with_a_stable_name(): void
    {
        $map = RegisteredAbilities::manifest_map();

        $this->assertArrayHasKey('wpmcp/generate-schema-markup', $map);
        $this->assertSame('pro', $map['wpmcp/generate-schema-markup']);
        $this->assertArrayHasKey('wpmcp/get-social-meta', $map);
        $this->assertSame('pro', $map['wpmcp/get-social-meta']);
    }
}
