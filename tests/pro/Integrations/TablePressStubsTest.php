<?php

namespace WPMCP\Tests\Pro\Integrations;

require_once __DIR__ . '/../../support/tablepress-stubs.php';

/**
 * The TablePress stand-ins stay free of PHP deprecations (issue #534):
 * str_getcsv() wants its $escape argument spelled out since PHP 8.4.
 */
class TablePressStubsTest extends \WP_UnitTestCase
{
    public function test_csv_import_parsing_raises_no_deprecation(): void
    {
        if (! class_exists('WPMCP_Test_TablePress_Import', false)) {
            $this->markTestSkipped('The real TablePress is loaded, so its stand-ins are not.');
        }

        $deprecations = [];
        set_error_handler(static function (int $errno, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;
            return true;
        }, E_DEPRECATED | E_USER_DEPRECATED);
        try {
            $parse = new \ReflectionMethod(\WPMCP_Test_TablePress_Import::class, 'parse');
            $rows  = $parse->invoke(new \WPMCP_Test_TablePress_Import(), "a,\"b,c\"\nd,\"e \\\"f\\\"\"");
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $deprecations);
        $this->assertSame([ [ 'a', 'b,c' ], [ 'd', 'e \\"f\\"' ] ], $rows);
    }
}
