<?php

namespace WPMCP\Tests\Free\Multisite;

use WPMCP\Tools\Multisite\Multisite_Adapter;

/**
 * Multisite_Adapter's live-network methods (network_info(), list_sites(),
 * site_details()) call get_network(), get_sites(), get_site(), etc., which
 * are only meaningful on an actual WordPress network; the multisite leg
 * (bin/test-local.sh --multisite, issue #530) runs them through the tool
 * tests next to this one.
 *
 * What is testable without a network is the pure normalization logic:
 * given already-fetched WP_Site-shaped data, does the adapter map it to the
 * plain-array neutral shape correctly. Those normalizers are exercised below
 * with hand-built stdClass fixtures so the mapping is covered independently
 * of get_sites()/get_site() actually running against a network.
 */
class MultisiteAdapterTest extends \WP_UnitTestCase
{
    public function test_is_network_reports_false_in_this_single_site_harness(): void
    {
        if (is_multisite()) {
            $this->markTestSkipped('Single-site by nature: asserts the answer outside a network; the multisite leg (issue #530) runs this on one.');
        }
        $this->assertFalse(Multisite_Adapter::is_network());
    }

    public function test_normalize_site_maps_a_wp_site_like_object_to_the_neutral_shape(): void
    {
        $site = (object) [
            'blog_id'      => '3',
            'domain'       => 'example.org',
            'path'         => '/sub/',
            'site_id'      => '1',
            'last_updated' => '2026-01-02 03:04:05',
        ];

        $normalized = Multisite_Adapter::normalize_site($site);

        $this->assertSame([
            'blog_id'      => 3,
            'url'          => 'example.org/sub/',
            'name'         => '',
            'last_updated' => '2026-01-02 03:04:05',
        ], $normalized);
    }

    public function test_normalize_site_uses_the_given_name_when_provided(): void
    {
        $site = (object) [
            'blog_id'      => '7',
            'domain'       => 'example.org',
            'path'         => '/',
            'last_updated' => '2026-01-02 03:04:05',
        ];

        $normalized = Multisite_Adapter::normalize_site($site, 'My Site');

        $this->assertSame('My Site', $normalized['name']);
    }

    public function test_normalize_sites_maps_a_list_of_site_objects(): void
    {
        $sites = [
            (object) [
                'blog_id'      => '1',
                'domain'       => 'a.example.org',
                'path'         => '/',
                'last_updated' => '2026-01-01 00:00:00',
            ],
            (object) [
                'blog_id'      => '2',
                'domain'       => 'b.example.org',
                'path'         => '/',
                'last_updated' => '2026-01-01 00:00:01',
            ],
        ];

        $normalized = Multisite_Adapter::normalize_sites($sites);

        $this->assertCount(2, $normalized);
        $this->assertSame(1, $normalized[0]['blog_id']);
        $this->assertSame(2, $normalized[1]['blog_id']);
    }

    public function test_clamp_limit_defaults_when_not_given(): void
    {
        $this->assertSame(Multisite_Adapter::DEFAULT_LIMIT, Multisite_Adapter::clamp_limit(null));
    }

    public function test_clamp_limit_floors_below_one(): void
    {
        $this->assertSame(1, Multisite_Adapter::clamp_limit(0));
        $this->assertSame(1, Multisite_Adapter::clamp_limit(-5));
    }

    public function test_clamp_limit_caps_at_max_limit(): void
    {
        $this->assertSame(Multisite_Adapter::MAX_LIMIT, Multisite_Adapter::clamp_limit(Multisite_Adapter::MAX_LIMIT + 500));
    }

    public function test_clamp_offset_floors_at_zero(): void
    {
        $this->assertSame(0, Multisite_Adapter::clamp_offset(null));
        $this->assertSame(0, Multisite_Adapter::clamp_offset(-10));
    }

    public function test_clamp_offset_passes_through_a_valid_value(): void
    {
        $this->assertSame(25, Multisite_Adapter::clamp_offset(25));
    }
}
