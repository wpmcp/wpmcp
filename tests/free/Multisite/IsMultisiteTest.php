<?php

namespace WPMCP\Tests\Free\Multisite;

use WPMCP\Tools\Multisite\Is_Multisite;

class IsMultisiteTest extends \WP_UnitTestCase
{
    public function test_reports_false_in_this_single_site_harness(): void
    {
        if (is_multisite()) {
            $this->markTestSkipped('Single-site by nature: asserts the answer outside a network; the multisite leg (issue #530) runs this on one.');
        }
        $out = (new Is_Multisite())->handle([]);

        $this->assertIsArray($out);
        $this->assertFalse($out['is_multisite']);
    }

    public function test_output_shape_is_a_plain_array_with_only_the_expected_key(): void
    {
        $out = (new Is_Multisite())->handle([]);

        $this->assertSame(['is_multisite'], array_keys($out));
    }

    public function test_reports_true_on_a_network(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Needs a network: runs in the multisite leg (bin/test-local.sh --multisite, issue #530).');
        }

        $this->assertSame(['is_multisite' => true], (new Is_Multisite())->handle([]));
    }
}
