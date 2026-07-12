<?php

namespace WPMCP\Tests;

class HarnessSmokeTest extends \WP_UnitTestCase {
    public function test_wordpress_is_loaded(): void {
        $this->assertTrue( function_exists( 'wp_insert_post' ) );
    }
}
