<?php

namespace WPMCP\Tests\Free\Integrations;

/**
 * Issue #702: the Action Scheduler ops against the standalone Action
 * Scheduler plugin from wordpress.org, free to install.
 *
 * It runs only in the local gate's live Action Scheduler leg
 * (bin/test-local.sh --live-action-scheduler), which sets
 * WPMCP_LIVE_ACTION_SCHEDULER=1, installs the plugin from wordpress.org onto
 * a separate WordPress install (without WooCommerce, which bundles its own
 * copy) and loads it in tests/bootstrap.php. It runs every test of
 * ActionSchedulerPackTest, so the list, get, cancel with its undo and run-now
 * ops agree with the standalone plugin's own store, logger and queue runner.
 *
 * Everywhere else the test is skipped; the live leg runs with
 * --fail-on-skipped so it cannot pass vacuously.
 *
 * @group action-scheduler-live
 */
class ActionSchedulerLiveTest extends ActionSchedulerPackTest
{
    protected function setUp(): void
    {
        if (! getenv('WPMCP_LIVE_ACTION_SCHEDULER')) {
            $this->markTestSkipped('Needs the standalone Action Scheduler (bin/test-local.sh --live-action-scheduler).');
        }
        if (class_exists('WooCommerce')) {
            $this->fail('The live leg must run the standalone Action Scheduler, not the copy WooCommerce bundles.');
        }
        $file = (new \ReflectionClass('ActionScheduler'))->getFileName();
        $this->assertStringContainsString('/plugins/action-scheduler/', (string) $file, 'the standalone plugin is the copy that loaded');
        parent::setUp();
    }
}
