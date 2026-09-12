<?php

namespace WPMCP\Tests\Pro\Cloud;

use WPMCP\Cloud\Cloud_Config;
use WPMCP\Cloud\Cloud_Credentials;
use WPMCP\Cloud\Token_Refresher;
use WPMCP\Tools\Cloud\Cloud_Status;

/**
 * Issue #141 phase 1: the token_status field cloud-status reports.
 *
 * It is the only MCP-client-visible change in this phase, and each of its
 * four values stands for a different operator action, so each gets a case.
 */
class CloudStatusTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cloud_Credentials::clear();
    }

    protected function tearDown(): void
    {
        Cloud_Credentials::clear();
        parent::tearDown();
    }

    public function test_an_api_key_connection_reports_ok(): void
    {
        Cloud_Config::set('https://cloud.example', 'sk-1');

        $out = (new Cloud_Status())->handle([]);

        $this->assertTrue($out['connected']);
        $this->assertSame('ok', $out['token_status']);
    }

    public function test_an_un_raced_rejection_reports_rejected(): void
    {
        Cloud_Config::set('https://cloud.example', 'sk-1');
        update_option(Token_Refresher::HEALTH_OPTION, ['rejected_at' => time()], false);

        $this->assertSame('rejected', (new Cloud_Status())->handle([])['token_status']);
    }

    public function test_a_site_that_was_never_connected_reports_none(): void
    {
        $out = (new Cloud_Status())->handle([]);

        $this->assertFalse($out['connected']);
        $this->assertSame('none', $out['token_status'], 'never connected is not the same as connected and fine');
    }

    public function test_a_vault_that_no_longer_decrypts_reports_unreadable(): void
    {
        // What a wp_salt('auth') rotation looks like from here: a sealed blob
        // that is present but no longer opens. Reporting that as an ordinary
        // disconnect leaves the operator with no diagnostic at all.
        update_option(Cloud_Credentials::OPTION, base64_encode(random_bytes(80)), false);

        $out = (new Cloud_Status())->handle([]);

        $this->assertFalse($out['connected']);
        $this->assertSame('unreadable', $out['token_status']);
    }
}
