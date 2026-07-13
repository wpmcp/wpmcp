<?php

namespace WPMCP\Tests\Free\Analytics;

use WPMCP\Tools\Analytics\Analytics_Adapter;

/**
 * Analytics_Adapter maps a neutral analytics/Search Console field set onto
 * whichever provider is active: Google Site Kit, or an explicitly configured
 * set of credentials stored in the 'wpmcp_analytics_config' option.
 *
 * Neither provider can be exercised end to end in this harness: Google Site
 * Kit is not installed here (it is not one of the plugins bundled into this
 * test environment, unlike, say, Polylang), and even a configured-credentials
 * setup would still need a real outbound call to Google's GA4/Search Console
 * APIs to return real data, which this harness has no network access or
 * credentials for. That mirrors how MultisiteAdapterTest and I18nAdapterTest
 * treat their own live-network / live-plugin paths as production-only.
 *
 * What IS testable here: the pure detection logic (active_provider(),
 * connection_status()) against option-based configuration and a filter-based
 * seam for simulating Site Kit's presence, plus the pure normalization
 * helpers against hand-built fixtures (covered in separate test files).
 *
 * Site Kit detection seam: rather than defining a real GOOGLESITEKIT_VERSION
 * constant or declaring a throwaway class in the Google\Site_Kit namespace
 * (either of which could leak into other tests since PHP constants/classes
 * cannot be undefined once declared), active_provider() runs its Site Kit
 * check through
 * apply_filters('wpmcp_analytics_site_kit_active', defined(...) || class_exists(...)).
 * A test can flip this with add_filter() and must remove_filter() in
 * tearDown(), keeping the simulation fully isolated per test.
 */
class AnalyticsAdapterTest extends \WP_UnitTestCase
{
    public function tearDown(): void
    {
        delete_option('wpmcp_analytics_config');
        remove_all_filters('wpmcp_analytics_site_kit_active');
        parent::tearDown();
    }

    public function test_active_provider_is_none_when_nothing_is_configured(): void
    {
        $this->assertSame('', Analytics_Adapter::active_provider());
    }

    public function test_active_provider_is_configured_when_valid_option_is_set(): void
    {
        update_option('wpmcp_analytics_config', [
            'property_id' => '123456789',
            'site_url'    => 'https://example.org',
        ]);

        $this->assertSame('configured', Analytics_Adapter::active_provider());
    }

    public function test_active_provider_is_none_when_configured_option_is_missing_required_keys(): void
    {
        update_option('wpmcp_analytics_config', [
            'property_id' => '',
        ]);

        $this->assertSame('', Analytics_Adapter::active_provider());
    }

    public function test_active_provider_is_site_kit_when_the_detection_filter_reports_active(): void
    {
        add_filter('wpmcp_analytics_site_kit_active', '__return_true');

        $this->assertSame('site-kit', Analytics_Adapter::active_provider());
    }

    public function test_active_provider_prefers_site_kit_over_configured(): void
    {
        update_option('wpmcp_analytics_config', [
            'property_id' => '123456789',
            'site_url'    => 'https://example.org',
        ]);
        add_filter('wpmcp_analytics_site_kit_active', '__return_true');

        $this->assertSame('site-kit', Analytics_Adapter::active_provider());
    }

    public function test_connection_status_reports_none_when_nothing_is_configured(): void
    {
        $status = Analytics_Adapter::connection_status();

        $this->assertSame('none', $status['provider']);
        $this->assertFalse($status['connected']);
        $this->assertIsString($status['detail']);
    }

    public function test_connection_status_reports_configured_and_connected_when_option_is_valid(): void
    {
        update_option('wpmcp_analytics_config', [
            'property_id' => '123456789',
            'site_url'    => 'https://example.org',
        ]);

        $status = Analytics_Adapter::connection_status();

        $this->assertSame('configured', $status['provider']);
        $this->assertTrue($status['connected']);
    }

    public function test_validate_date_range_passes_through_a_valid_range(): void
    {
        $result = Analytics_Adapter::validate_date_range('2026-01-01', '2026-01-31');

        $this->assertSame(['start_date' => '2026-01-01', 'end_date' => '2026-01-31'], $result);
    }

    public function test_validate_date_range_defaults_to_trailing_28_days_ending_yesterday_when_both_are_null(): void
    {
        $result = Analytics_Adapter::validate_date_range(null, null);

        $yesterday = gmdate('Y-m-d', strtotime('-1 day'));
        $start     = gmdate('Y-m-d', strtotime('-28 days'));

        $this->assertSame($start, $result['start_date']);
        $this->assertSame($yesterday, $result['end_date']);
    }

    public function test_validate_date_range_defaults_when_both_are_empty_strings(): void
    {
        $result = Analytics_Adapter::validate_date_range('', '');

        $this->assertArrayHasKey('start_date', $result);
        $this->assertArrayHasKey('end_date', $result);
    }

    public function test_validate_date_range_rejects_a_malformed_start_date(): void
    {
        $result = Analytics_Adapter::validate_date_range('01/01/2026', '2026-01-31');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wpmcp_invalid_date_range', $result->get_error_code());
    }

    public function test_validate_date_range_rejects_a_malformed_end_date(): void
    {
        $result = Analytics_Adapter::validate_date_range('2026-01-01', 'not-a-date');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wpmcp_invalid_date_range', $result->get_error_code());
    }

    public function test_validate_date_range_rejects_start_after_end(): void
    {
        $result = Analytics_Adapter::validate_date_range('2026-02-01', '2026-01-01');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wpmcp_invalid_date_range', $result->get_error_code());
    }

    public function test_validate_date_range_allows_start_equal_to_end(): void
    {
        $result = Analytics_Adapter::validate_date_range('2026-01-15', '2026-01-15');

        $this->assertSame(['start_date' => '2026-01-15', 'end_date' => '2026-01-15'], $result);
    }

    public function test_validate_date_range_clamps_a_future_end_date_to_today(): void
    {
        $future = gmdate('Y-m-d', strtotime('+10 days'));
        $today  = gmdate('Y-m-d');

        $result = Analytics_Adapter::validate_date_range('2026-01-01', $future);

        $this->assertSame($today, $result['end_date']);
    }

    public function test_clamp_limit_defaults_when_not_given(): void
    {
        $this->assertSame(Analytics_Adapter::DEFAULT_LIMIT, Analytics_Adapter::clamp_limit(null));
    }

    public function test_clamp_limit_floors_below_one(): void
    {
        $this->assertSame(1, Analytics_Adapter::clamp_limit(0));
        $this->assertSame(1, Analytics_Adapter::clamp_limit(-5));
    }

    public function test_clamp_limit_caps_at_max_limit(): void
    {
        $this->assertSame(Analytics_Adapter::MAX_LIMIT, Analytics_Adapter::clamp_limit(Analytics_Adapter::MAX_LIMIT + 50));
    }

    public function test_clamp_limit_passes_through_a_valid_value(): void
    {
        $this->assertSame(25, Analytics_Adapter::clamp_limit(25));
    }

    /**
     * Fixture shape approximates the GA4 Data API's runReport response
     * (a "rows" array, each row carrying "metricValues", each an object with
     * a string "value") based on the public API reference. It is NOT
     * verified against a live GA4 response: this test only proves the
     * adapter's normalizer maps a payload of this shape correctly, not that
     * Google actually returns exactly this shape.
     */
    public function test_normalize_ga4_summary_maps_a_fixture_run_report_response(): void
    {
        $raw = [
            'rows' => [
                [
                    'metricValues' => [
                        ['value' => '1234'],
                        ['value' => '987'],
                        ['value' => '4321'],
                    ],
                ],
            ],
        ];

        $result = Analytics_Adapter::normalize_ga4_summary($raw, '2026-01-01', '2026-01-28');

        $this->assertSame([
            'start_date' => '2026-01-01',
            'end_date'   => '2026-01-28',
            'sessions'   => 1234,
            'users'      => 987,
            'pageviews'  => 4321,
        ], $result);
    }

    public function test_normalize_ga4_summary_defaults_to_zero_when_rows_are_missing(): void
    {
        $result = Analytics_Adapter::normalize_ga4_summary([], '2026-01-01', '2026-01-28');

        $this->assertSame(0, $result['sessions']);
        $this->assertSame(0, $result['users']);
        $this->assertSame(0, $result['pageviews']);
    }

    /**
     * Same honesty caveat as above: this fixture approximates the GA4
     * runReport shape for a report with one dimension (page path) and one
     * metric (screenPageViews), not a verified live response.
     */
    public function test_normalize_ga4_top_pages_maps_a_fixture_run_report_response(): void
    {
        $raw = [
            'rows' => [
                [
                    'dimensionValues' => [['value' => '/']],
                    'metricValues'    => [['value' => '500']],
                ],
                [
                    'dimensionValues' => [['value' => '/about']],
                    'metricValues'    => [['value' => '120']],
                ],
            ],
        ];

        $result = Analytics_Adapter::normalize_ga4_top_pages($raw);

        $this->assertSame([
            ['path' => '/', 'pageviews' => 500],
            ['path' => '/about', 'pageviews' => 120],
        ], $result);
    }

    public function test_normalize_ga4_top_pages_returns_an_empty_array_when_rows_are_missing(): void
    {
        $this->assertSame([], Analytics_Adapter::normalize_ga4_top_pages([]));
    }

    /**
     * Fixture shape approximates the Search Console searchanalytics.query
     * response (a "rows" array, each row carrying numeric "clicks",
     * "impressions", "ctr", and "position" fields directly, no nested
     * value-wrapper unlike GA4) based on the public API reference. Not
     * verified against a live Search Console response.
     */
    public function test_normalize_gsc_summary_maps_a_fixture_search_analytics_response(): void
    {
        $raw = [
            'rows' => [
                [
                    'clicks'      => 42,
                    'impressions' => 1000,
                    'ctr'         => 0.042,
                    'position'    => 8.5,
                ],
            ],
        ];

        $result = Analytics_Adapter::normalize_gsc_summary($raw, '2026-01-01', '2026-01-28');

        $this->assertSame([
            'start_date'   => '2026-01-01',
            'end_date'     => '2026-01-28',
            'clicks'       => 42,
            'impressions'  => 1000,
            'ctr'          => 0.042,
            'position'     => 8.5,
        ], $result);
    }

    public function test_normalize_gsc_summary_defaults_to_zero_when_rows_are_missing(): void
    {
        $result = Analytics_Adapter::normalize_gsc_summary([], '2026-01-01', '2026-01-28');

        $this->assertSame(0, $result['clicks']);
        $this->assertSame(0, $result['impressions']);
        $this->assertSame(0.0, $result['ctr']);
        $this->assertSame(0.0, $result['position']);
    }

    /**
     * Same honesty caveat: this fixture approximates a Search Console
     * searchanalytics.query response with dimensions=['query'], each row's
     * "keys" holding the query string per the public API reference. Not
     * verified against a live response.
     */
    public function test_normalize_gsc_queries_maps_a_fixture_search_analytics_response(): void
    {
        $raw = [
            'rows' => [
                [
                    'keys'        => ['grand rapids itinerary'],
                    'clicks'      => 30,
                    'impressions' => 400,
                ],
                [
                    'keys'        => ['visit grand rapids'],
                    'clicks'      => 12,
                    'impressions' => 200,
                ],
            ],
        ];

        $result = Analytics_Adapter::normalize_gsc_queries($raw);

        $this->assertSame([
            ['query' => 'grand rapids itinerary', 'clicks' => 30, 'impressions' => 400],
            ['query' => 'visit grand rapids', 'clicks' => 12, 'impressions' => 200],
        ], $result);
    }

    public function test_normalize_gsc_queries_returns_an_empty_array_when_rows_are_missing(): void
    {
        $this->assertSame([], Analytics_Adapter::normalize_gsc_queries([]));
    }
}
