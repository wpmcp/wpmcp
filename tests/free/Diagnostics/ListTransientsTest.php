<?php

namespace WPMCP\Tests\Free\Diagnostics;

use WPMCP\Tools\Diagnostics\List_Transients;

class ListTransientsTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        delete_transient('wpmcp_diag_alpha');
        delete_transient('wpmcp_diag_beta');
        delete_transient('wpmcp_other_gamma');
        parent::tearDown();
    }

    public function test_lists_seeded_transients_with_name_and_expiry(): void
    {
        set_transient('wpmcp_diag_alpha', 'a', HOUR_IN_SECONDS);
        set_transient('wpmcp_diag_beta', 'b', HOUR_IN_SECONDS);

        $out = (new List_Transients())->handle([]);

        $names = array_column($out['transients'], 'name');
        $this->assertContains('wpmcp_diag_alpha', $names);
        $this->assertContains('wpmcp_diag_beta', $names);

        $alpha = current(array_filter($out['transients'], fn($t) => 'wpmcp_diag_alpha' === $t['name']));
        $this->assertArrayHasKey('expiration', $alpha);
    }

    public function test_search_filters_by_name_substring(): void
    {
        set_transient('wpmcp_diag_alpha', 'a', HOUR_IN_SECONDS);
        set_transient('wpmcp_other_gamma', 'g', HOUR_IN_SECONDS);

        $out = (new List_Transients())->handle(['search' => 'diag_alpha']);

        $names = array_column($out['transients'], 'name');
        $this->assertContains('wpmcp_diag_alpha', $names);
        $this->assertNotContains('wpmcp_other_gamma', $names);
    }

    public function test_cap_limits_the_number_returned(): void
    {
        set_transient('wpmcp_diag_alpha', 'a', HOUR_IN_SECONDS);
        set_transient('wpmcp_diag_beta', 'b', HOUR_IN_SECONDS);

        $out = (new List_Transients())->handle(['limit' => 1]);

        $this->assertCount(1, $out['transients']);
    }
}
