<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;
use WPMCP\MCP\Tool_Exposure;

/**
 * Exposure-mode resolution for the compact tool surface (issue #79).
 *
 * The mode is an EXPOSURE choice only: it never changes which abilities are
 * registered, only which tools are advertised in tools/list. Resolution
 * order: site option -> per-identity override -> wpmcp_tool_exposure_mode
 * filter, and anything invalid degrades to 'full' (the default, per the
 * issue: compact is opt-in).
 */
class ToolExposureModeTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Tool_Exposure::OPTION);
        delete_option(Identity_Store::OPTION);
    }

    protected function tearDown(): void
    {
        Identity_Context::set_current_for_tests(null);
        delete_option(Tool_Exposure::OPTION);
        delete_option(Identity_Store::OPTION);
        remove_all_filters('wpmcp_tool_exposure_mode');
        parent::tearDown();
    }

    public function test_default_mode_is_full(): void
    {
        $this->assertSame(Tool_Exposure::MODE_FULL, (new Tool_Exposure())->mode());
        $this->assertFalse((new Tool_Exposure())->is_compact());
    }

    public function test_site_option_switches_to_compact(): void
    {
        update_option(Tool_Exposure::OPTION, Tool_Exposure::MODE_COMPACT);

        $this->assertSame(Tool_Exposure::MODE_COMPACT, (new Tool_Exposure())->mode());
        $this->assertTrue((new Tool_Exposure())->is_compact());
    }

    public function test_invalid_option_value_degrades_to_full(): void
    {
        update_option(Tool_Exposure::OPTION, 'banana');

        $this->assertSame(Tool_Exposure::MODE_FULL, (new Tool_Exposure())->mode());
    }

    public function test_filter_overrides_the_site_option(): void
    {
        update_option(Tool_Exposure::OPTION, Tool_Exposure::MODE_FULL);
        add_filter('wpmcp_tool_exposure_mode', fn() => Tool_Exposure::MODE_COMPACT);

        $this->assertSame(Tool_Exposure::MODE_COMPACT, (new Tool_Exposure())->mode());
    }

    public function test_invalid_filter_value_degrades_to_full(): void
    {
        update_option(Tool_Exposure::OPTION, Tool_Exposure::MODE_COMPACT);
        add_filter('wpmcp_tool_exposure_mode', fn() => 'nonsense');

        $this->assertSame(Tool_Exposure::MODE_FULL, (new Tool_Exposure())->mode());
    }

    public function test_identity_exposure_overrides_site_option_to_compact(): void
    {
        update_option(Tool_Exposure::OPTION, Tool_Exposure::MODE_FULL);
        Identity_Store::create('compact-bot', ['exposure' => Tool_Exposure::MODE_COMPACT]);
        Identity_Context::set_current_for_tests('compact-bot');

        $this->assertSame(Tool_Exposure::MODE_COMPACT, (new Tool_Exposure())->mode());
    }

    public function test_identity_exposure_overrides_site_option_to_full(): void
    {
        update_option(Tool_Exposure::OPTION, Tool_Exposure::MODE_COMPACT);
        Identity_Store::create('full-bot', ['exposure' => Tool_Exposure::MODE_FULL]);
        Identity_Context::set_current_for_tests('full-bot');

        $this->assertSame(Tool_Exposure::MODE_FULL, (new Tool_Exposure())->mode());
    }

    public function test_identity_without_exposure_inherits_the_site_mode(): void
    {
        update_option(Tool_Exposure::OPTION, Tool_Exposure::MODE_COMPACT);
        Identity_Store::create('legacy-bot', ['domains' => ['content']]);
        Identity_Context::set_current_for_tests('legacy-bot');

        $this->assertSame(Tool_Exposure::MODE_COMPACT, (new Tool_Exposure())->mode());
    }

    public function test_unknown_identity_inherits_the_site_mode(): void
    {
        update_option(Tool_Exposure::OPTION, Tool_Exposure::MODE_COMPACT);
        Identity_Context::set_current_for_tests('never-created');

        $this->assertSame(Tool_Exposure::MODE_COMPACT, (new Tool_Exposure())->mode());
    }

    public function test_identity_store_persists_a_valid_exposure_and_normalizes_invalid_values(): void
    {
        Identity_Store::create('a', ['exposure' => 'compact']);
        Identity_Store::create('b', ['exposure' => 'full']);
        Identity_Store::create('c', ['exposure' => 'garbage']);
        Identity_Store::create('d', []);

        $this->assertSame('compact', Identity_Store::get('a')['exposure']);
        $this->assertSame('full', Identity_Store::get('b')['exposure']);
        $this->assertSame('', Identity_Store::get('c')['exposure'], 'Invalid exposure must normalize to inherit.');
        $this->assertSame('', Identity_Store::get('d')['exposure'], 'Missing exposure must default to inherit.');
    }
}
