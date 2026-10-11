<?php

namespace WPMCP\Tests\Free\MultisiteSecurity;

/**
 * For tests of the code execution tools that are about something other than
 * who may call them (issue #562). On multisite those tools need a network
 * administrator, so such a test acts as one there; on single site it changes
 * nothing. SubSiteAdminCodeExecTest covers the refusal itself.
 *
 * Call act_as_network_exec_caller() in setUp() and
 * release_network_exec_caller() in tearDown().
 */
trait Network_Exec_Caller
{
    private int $network_exec_caller = 0;

    private function act_as_network_exec_caller(): void
    {
        if (! is_multisite()) {
            return;
        }

        $this->network_exec_caller = (int) self::factory()->user->create(['role' => 'administrator']);
        grant_super_admin($this->network_exec_caller);
        wp_set_current_user($this->network_exec_caller);
    }

    private function release_network_exec_caller(): void
    {
        if ($this->network_exec_caller > 0) {
            revoke_super_admin($this->network_exec_caller);
            $this->network_exec_caller = 0;
        }
    }

    /**
     * The global arguments WP-CLI is given to address the current site:
     * none on single site, --url with the blog's domain and path on
     * multisite.
     *
     * @return string[]
     */
    private function expected_site_args(): array
    {
        if (! is_multisite()) {
            return [];
        }

        $site = get_site(get_current_blog_id());

        return ['--url=' . $site->domain . $site->path];
    }
}
