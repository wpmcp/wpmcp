<?php
namespace WPMCP\Tests;
use WPMCP\Plugin;
use PHPUnit\Framework\TestCase;

class PluginBootstrapTest extends TestCase {
    public function test_instance_is_singleton(): void {
        $this->assertSame( Plugin::instance(), Plugin::instance() );
    }
}
