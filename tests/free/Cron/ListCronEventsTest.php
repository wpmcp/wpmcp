<?php

namespace WPMCP\Tests\Free\Cron;

use WPMCP\Tools\Cron\List_Cron_Events;

class ListCronEventsTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        wp_clear_scheduled_hook('wpmcp_cron_test_event');
        parent::tearDown();
    }

    public function test_lists_a_scheduled_event(): void
    {
        $timestamp = time() + HOUR_IN_SECONDS;
        wp_schedule_event($timestamp, 'hourly', 'wpmcp_cron_test_event');

        $out = (new List_Cron_Events())->handle([]);

        $this->assertArrayHasKey('events', $out);
        $hooks = array_column($out['events'], 'hook');
        $this->assertContains('wpmcp_cron_test_event', $hooks);

        $event = null;
        foreach ($out['events'] as $candidate) {
            if ('wpmcp_cron_test_event' === $candidate['hook']) {
                $event = $candidate;
                break;
            }
        }
        $this->assertNotNull($event);
        $this->assertSame($timestamp, $event['timestamp']);
        $this->assertSame('hourly', $event['schedule']);
    }

    public function test_includes_available_schedules(): void
    {
        $out = (new List_Cron_Events())->handle([]);

        $this->assertArrayHasKey('schedules', $out);
        $this->assertArrayHasKey('hourly', $out['schedules']);
        $this->assertArrayHasKey('interval', $out['schedules']['hourly']);
    }

    public function test_filters_by_hook(): void
    {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'wpmcp_cron_test_event');

        $out = (new List_Cron_Events())->handle(['hook' => 'wpmcp_cron_test_event']);

        $this->assertNotEmpty($out['events']);
        foreach ($out['events'] as $event) {
            $this->assertSame('wpmcp_cron_test_event', $event['hook']);
        }
    }
}
