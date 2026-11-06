<?php

namespace WPMCP\Tests\Free\ThemeBuilder;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\List_Operations;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;
use WPMCP\Tools\ThemeBuilder\Create_Site_Part;
use WPMCP\Tools\ThemeBuilder\Template_Resolver;
use WPMCP\Tools\ThemeBuilder\Template_Store;

/**
 * Issue #725: create-site-part returned no operation_id, so a part that went
 * live at once could not be taken back by rollback-operation or
 * rollback-session.
 */
class CreateSitePartRollbackTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
        Template_Store::ensure_post_type();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'administrator' ]));
    }

    private function create(string $session): array
    {
        $out = (new Create_Site_Part())->handle([
            'part_type'  => '404',
            'title'      => 'H5 404 part',
            'content'    => "<!-- wp:paragraph -->\n<p>H5 custom 404 text</p>\n<!-- /wp:paragraph -->",
            'conditions' => [ 'include' => [ [ 'type' => 'error_404' ] ] ],
            'session_id' => $session,
        ]);
        $this->assertIsArray($out);
        return $out;
    }

    public function test_create_returns_an_operation_id_that_is_listed(): void
    {
        $out = $this->create('sess-725-a');

        $this->assertArrayHasKey('operation_id', $out);
        $this->assertSame($out['template_id'], Template_Resolver::resolve('404', [ 'is_404' => true ])['winner']['template_id']);

        $ops = (new List_Operations())->handle([ 'session_id' => 'sess-725-a' ])['operations'];
        $this->assertCount(1, $ops);
        $this->assertSame($out['operation_id'], $ops[0]['operation_id']);
        $this->assertSame('create-site-part', $ops[0]['tool_name']);
        $this->assertSame($out['template_id'], $ops[0]['object_id']);
        $this->assertTrue($ops[0]['rollback_available']);
    }

    public function test_rollback_operation_takes_the_part_off_the_site(): void
    {
        $out = $this->create('sess-725-b');

        $restored = (new Rollback_Operation())->handle([ 'operation_id' => $out['operation_id'] ]);

        $this->assertTrue($restored['restored']);
        $this->assertSame('trash', get_post_status($out['template_id']));
        $this->assertNull(Template_Resolver::resolve('404', [ 'is_404' => true ])['winner']);
    }

    public function test_rollback_session_takes_the_part_off_the_site(): void
    {
        $out = $this->create('sess-725-c');

        (new Rollback_Session())->handle([ 'session_id' => 'sess-725-c' ]);

        $this->assertSame('trash', get_post_status($out['template_id']));
        $this->assertNull(Template_Resolver::resolve('404', [ 'is_404' => true ])['winner']);
    }
}
