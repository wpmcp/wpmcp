<?php

namespace WPMCP\Tests\Free\I18n;

use WPMCP\Tools\I18n\I18n_Adapter;

/**
 * Proves the adapter's plugin detection: which multilingual plugin (if any)
 * is active, reported the same way regardless of whether Polylang or WPML is
 * running. Gated on wpmcp_i18n_plugin() so it skips cleanly when neither is
 * installed.
 */
class I18nAdapterTest extends \WP_UnitTestCase
{
    public function test_detects_the_active_plugin(): void
    {
        $active = wpmcp_i18n_plugin();
        if ('' === $active) {
            $this->markTestSkipped('No i18n plugin active');
        }

        $this->assertSame($active, I18n_Adapter::active_plugin());
    }

    public function test_reports_no_plugin_when_neither_is_active(): void
    {
        if ('' !== wpmcp_i18n_plugin()) {
            $this->markTestSkipped('An i18n plugin is active in this test environment.');
        }

        $this->assertSame('', I18n_Adapter::active_plugin());
    }
}
