<?php

namespace WPMCP\Tests\Free\Performance;

use WPMCP\Tools\Performance\Server_Audit;

class ServerAuditTest extends \WP_UnitTestCase
{
    private Server_Audit $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->audit = new Server_Audit();
    }

    public function test_php_version_bands(): void
    {
        $this->assertSame('pass', $this->audit->evaluate_php_version('8.2.0')['status']);
        $this->assertSame('pass', $this->audit->evaluate_php_version('8.3.1')['status']);
        $this->assertSame('warning', $this->audit->evaluate_php_version('8.1.27')['status']);
        $this->assertSame('critical', $this->audit->evaluate_php_version('7.4.33')['status']);
    }

    public function test_memory_limit_bands(): void
    {
        $this->assertSame('pass', $this->audit->evaluate_memory_limit('256M')['status']);
        $this->assertSame('pass', $this->audit->evaluate_memory_limit('128M')['status']);
        $this->assertSame('warning', $this->audit->evaluate_memory_limit('64M')['status']);
        $this->assertSame('pass', $this->audit->evaluate_memory_limit('-1')['status']);
    }
}
