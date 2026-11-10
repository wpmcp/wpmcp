<?php

namespace WPMCP\Maintenance;

use WPMCP\Tools\Backup\Restore_Maintenance;
use WPMCP\Tools\Backup\Restore_Site_Backup;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Front-end enforcement for maintenance mode: when the wpmcp_maintenance
 * option has enabled=true, non-logged-in visitors and logged-in users who
 * lack manage_options are served a 503 response instead of the page.
 *
 * The decision (should_block) is kept separate from the side effect
 * (enforce, which sends headers/body and exits) so it can be unit tested
 * without terminating the test process. Only manage_options users are ever
 * blocked out: this is the same capability gate used to register the
 * maintenance tools themselves, so anyone who can turn maintenance mode on
 * or off can also still reach the site while it is on.
 *
 * A database restore also turns the guard on, through a second signal that
 * does not live in wp_options (issue #813): while the dump drops, recreates
 * and refills the options table, the wpmcp_maintenance row is simply not
 * there, so reading the option alone let visitors through to a half
 * restored site. Restore_Site_Backup::is_running() (its progress file plus
 * its MySQL lock) covers that window.
 */
class Maintenance_Guard
{
    public function should_block(): bool
    {
        $option = get_option('wpmcp_maintenance');
        $on     = is_array($option) && ! empty($option['enabled']);
        if (! $on && ! Restore_Site_Backup::is_running()) {
            return false;
        }

        if (is_user_logged_in() && current_user_can('manage_options')) {
            return false;
        }

        return true;
    }

    /**
     * Hooked to template_redirect. Sends a 503 with the configured message
     * and Retry-After header, then terminates the request so no further
     * template output reaches a blocked visitor.
     */
    public function enforce(): void
    {
        if (! $this->should_block()) {
            return;
        }

        $response    = $this->response();
        $message     = $response['message'];
        $retry_after = $response['retry_after'];

        status_header(503);
        nocache_headers();
        if ($retry_after > 0) {
            header('Retry-After: ' . $retry_after);
        }

        echo esc_html($message);
        exit;
    }

    /**
     * The message and Retry-After for a blocked request: the option's when
     * maintenance mode is on, otherwise (a restore with the options table
     * mid-rebuild) the restore's own.
     *
     * @return array{message: string, retry_after: int}
     */
    public function response(): array
    {
        $option = get_option('wpmcp_maintenance');
        if (is_array($option) && ! empty($option['enabled'])) {
            return [
                'message'     => (string) ($option['message'] ?? ''),
                'retry_after' => (int) ($option['retry_after'] ?? 0),
            ];
        }

        return [
            'message'     => Restore_Maintenance::message(),
            'retry_after' => Restore_Maintenance::RETRY_AFTER,
        ];
    }
}
