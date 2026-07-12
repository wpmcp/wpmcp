<?php

namespace WPMCP\Tests\Free\Performance;

use WPMCP\Tools\Performance\Finding;

class FindingTest extends \WP_UnitTestCase
{
    public function test_make_builds_the_canonical_finding_shape(): void
    {
        $finding = Finding::make(
            'php_version',
            'server',
            'PHP version',
            'warning',
            '8.1.0',
            'PHP 8.1 is approaching end of life.',
            'Upgrade to PHP 8.2 or newer.'
        );

        $this->assertSame('php_version', $finding['id']);
        $this->assertSame('server', $finding['category']);
        $this->assertSame('PHP version', $finding['label']);
        $this->assertSame('warning', $finding['status']);
        $this->assertSame('8.1.0', $finding['value']);
        $this->assertSame('PHP 8.1 is approaching end of life.', $finding['message']);
        $this->assertSame('Upgrade to PHP 8.2 or newer.', $finding['recommendation']);
    }

    public function test_recommendation_defaults_to_empty_string(): void
    {
        $finding = Finding::make('x', 'server', 'X', 'pass', 1, 'Good.');

        $this->assertSame('', $finding['recommendation']);
        $this->assertSame(1, $finding['value']);
    }
}
