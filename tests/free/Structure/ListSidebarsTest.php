<?php

namespace WPMCP\Tests\Free\Structure;

use WPMCP\Tools\Structure\List_Sidebars;

class ListSidebarsTest extends \WP_UnitTestCase
{
    public function tearDown(): void
    {
        unregister_sidebar('wpmcp-test-sidebar');
        parent::tearDown();
    }

    public function test_includes_a_registered_sidebar(): void
    {
        register_sidebar([
            'id'          => 'wpmcp-test-sidebar',
            'name'        => 'WPMCP Test Sidebar',
            'description' => 'A sidebar registered for testing.',
        ]);

        $out = (new List_Sidebars())->handle([]);

        $ids = array_column($out['sidebars'], 'id');
        $this->assertContains('wpmcp-test-sidebar', $ids);

        $sidebar = null;
        foreach ($out['sidebars'] as $row) {
            if ('wpmcp-test-sidebar' === $row['id']) {
                $sidebar = $row;
                break;
            }
        }

        $this->assertNotNull($sidebar);
        $this->assertSame('WPMCP Test Sidebar', $sidebar['name']);
        $this->assertSame('A sidebar registered for testing.', $sidebar['description']);
    }
}
