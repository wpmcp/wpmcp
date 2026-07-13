<?php

namespace WPMCP\Tests\Free\Analytics;

use WPMCP\Tools\Analytics\Get_Analytics_Connection_Status;
use WPMCP\Tools\Analytics\Get_Analytics_Summary;
use WPMCP\Tools\Analytics\Get_Top_Pages;
use WPMCP\Tools\Analytics\Get_Search_Console_Summary;

/**
 * Thin argument-handling tests for the Analytics tool classes. The adapter's
 * own logic (provider detection, date validation, limit clamping,
 * normalizers) is covered in AnalyticsAdapterTest; these tests only prove
 * each tool class wires its args to the adapter correctly (coercion,
 * defaulting, and passing through WP_Error results), the same scope as
 * Multisite's tool-class tests.
 */
class AnalyticsToolsTest extends \WP_UnitTestCase
{
    public function tearDown(): void
    {
        delete_option('wpmcp_analytics_config');
        parent::tearDown();
    }

    public function test_get_analytics_connection_status_returns_the_adapter_status_array(): void
    {
        $tool   = new Get_Analytics_Connection_Status();
        $result = $tool->handle([]);

        $this->assertIsArray($result);
        $this->assertSame('none', $result['provider']);
        $this->assertFalse($result['connected']);
    }

    public function test_get_analytics_summary_returns_not_connected_error_when_nothing_is_connected(): void
    {
        $tool   = new Get_Analytics_Summary();
        $result = $tool->handle(['start_date' => '2026-01-01', 'end_date' => '2026-01-28']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wpmcp_analytics_not_connected', $result->get_error_code());
    }

    public function test_get_analytics_summary_returns_invalid_date_range_error_for_malformed_dates(): void
    {
        $tool   = new Get_Analytics_Summary();
        $result = $tool->handle(['start_date' => 'not-a-date', 'end_date' => '2026-01-28']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wpmcp_invalid_date_range', $result->get_error_code());
    }

    public function test_get_top_pages_returns_not_connected_error_when_nothing_is_connected(): void
    {
        $tool   = new Get_Top_Pages();
        $result = $tool->handle(['start_date' => '2026-01-01', 'end_date' => '2026-01-28']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wpmcp_analytics_not_connected', $result->get_error_code());
    }

    public function test_get_top_pages_coerces_a_non_numeric_limit_to_the_default_rather_than_throwing(): void
    {
        // With no provider connected the not-connected error still fires,
        // but this proves handle() never fatals on a malformed limit before
        // reaching that check: it degrades to the adapter default instead
        // of throwing, matching List_Network_Sites's to_int_or_null idiom.
        $tool   = new Get_Top_Pages();
        $result = $tool->handle(['start_date' => '2026-01-01', 'end_date' => '2026-01-28', 'limit' => 'not-a-number']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wpmcp_analytics_not_connected', $result->get_error_code());
    }

    public function test_get_search_console_summary_returns_not_connected_error_when_nothing_is_connected(): void
    {
        $tool   = new Get_Search_Console_Summary();
        $result = $tool->handle(['start_date' => '2026-01-01', 'end_date' => '2026-01-28']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wpmcp_analytics_not_connected', $result->get_error_code());
    }

    public function test_get_search_console_summary_returns_invalid_date_range_error_for_malformed_dates(): void
    {
        $tool   = new Get_Search_Console_Summary();
        $result = $tool->handle(['start_date' => '2026-01-01', 'end_date' => 'not-a-date']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('wpmcp_invalid_date_range', $result->get_error_code());
    }
}
