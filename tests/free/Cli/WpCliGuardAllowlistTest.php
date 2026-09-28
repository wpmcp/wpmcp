<?php

namespace WPMCP\Tests\Free\Cli;

use WPMCP\Tools\Cli\Wp_Cli_Guard;

/**
 * Only explicitly allowlisted wp subcommands may run. Deny-by-default: an
 * argv whose leading subcommand words are not in the allowlist is rejected.
 * (Any trailing flag also independently has to clear
 * Wp_Cli_Guard::validate_flags()'s safe-flag allowlist; see
 * WpCliGuardFlagAllowlistTest.) The default set is conservative and
 * read-only-ish (core version, plugin list, theme list, cache flush, cron
 * event list); sites may extend it via the wpmcp_wp_cli_allowlist filter,
 * but the default set is always the conservative one unless a filter
 * changes it.
 *
 * Issue #44 security review, M1: `option get` and `user list` used to be on
 * this default set, but `option get` can read arbitrary options (API keys,
 * SMTP creds, payment secrets, ...) and `user list` can enumerate user data,
 * so both were removed from the default. Operators who accept that
 * confidentiality risk can re-add either via the wpmcp_wp_cli_allowlist
 * filter.
 */
class WpCliGuardAllowlistTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        remove_all_filters('wpmcp_wp_cli_allowlist');
        parent::tearDown();
    }

    /** @dataProvider default_allowed_subcommands */
    public function test_default_allowlist_accepts_expected_subcommands(array $argv): void
    {
        $this->assertTrue(Wp_Cli_Guard::is_allowed_subcommand($argv));
    }

    public function default_allowed_subcommands(): array
    {
        return [
            'core version'                    => [['core', 'version']],
            'plugin list'                      => [['plugin', 'list']],
            'theme list'                       => [['theme', 'list']],
            'cache flush'                      => [['cache', 'flush']],
            'cron event list'                  => [['cron', 'event', 'list']],
            'plugin list w/ allowlisted flag'  => [['plugin', 'list', '--format=json']],
        ];
    }

    /**
     * Issue #44 security review, C1: is_allowed_subcommand() only matches
     * the LEADING positional words of an argv, by design, so on its own it
     * WOULD say "yes" to an allowlisted prefix plus an arbitrary trailing
     * flag (this is exactly the allowlist-bypass-to-RCE the review found).
     * This is no longer exploitable because Run_Wp_Cli::guard() also calls
     * Wp_Cli_Guard::validate_flags() over the full argv, which denies any
     * non-allowlisted flag before the executor ever runs (see
     * WpCliGuardFlagAllowlistTest for the exploit-shaped coverage of that
     * second, decisive check). This test documents/pins the narrow scope of
     * is_allowed_subcommand() itself so a future reader does not mistake it
     * for the only or the last guard in the chain. This replaces the
     * previous "plugin list w/ flags" case above, which asserted that
     * arbitrary trailing flags (e.g. --status=active) were allowed through
     * is_allowed_subcommand() alone - that assertion encoded the
     * vulnerability and is why validate_flags() now exists as a mandatory,
     * separate check.
     */
    public function test_leading_word_match_alone_does_not_imply_the_full_command_is_safe(): void
    {
        $argv = ['core', 'version', '--require=/tmp/evil.php'];

        $this->assertTrue(Wp_Cli_Guard::is_allowed_subcommand($argv));
        $this->assertInstanceOf(\WP_Error::class, Wp_Cli_Guard::validate_flags($argv));
    }

    public function test_rejects_subcommand_not_on_allowlist(): void
    {
        $this->assertFalse(Wp_Cli_Guard::is_allowed_subcommand(['plugin', 'delete', 'akismet']));
    }

    public function test_rejects_db_query(): void
    {
        $this->assertFalse(Wp_Cli_Guard::is_allowed_subcommand(['db', 'query', 'DROP TABLE wp_users']));
    }

    /**
     * Issue #44 security review, M1: option get / user list are
     * info-disclosure risks (arbitrary option read, user enumeration) and
     * were removed from the default allowlist. They remain rejected unless
     * an operator explicitly re-adds them via the wpmcp_wp_cli_allowlist
     * filter, at which point they behave like any other filtered-in entry.
     */
    public function test_option_get_is_rejected_by_default(): void
    {
        $this->assertFalse(Wp_Cli_Guard::is_allowed_subcommand(['option', 'get', 'siteurl']));
    }

    public function test_user_list_is_rejected_by_default(): void
    {
        $this->assertFalse(Wp_Cli_Guard::is_allowed_subcommand(['user', 'list']));
    }

    public function test_option_get_can_be_re_added_via_filter(): void
    {
        add_filter('wpmcp_wp_cli_allowlist', function (array $allowlist): array {
            $allowlist[] = 'option get';
            return $allowlist;
        });

        $this->assertTrue(Wp_Cli_Guard::is_allowed_subcommand(['option', 'get', 'siteurl']));
    }

    public function test_rejects_empty_argv(): void
    {
        $this->assertFalse(Wp_Cli_Guard::is_allowed_subcommand([]));
    }

    public function test_rejects_a_prefix_of_an_allowed_entry_only(): void
    {
        // "cron" alone is not "cron event list"; a shorter argv must not
        // match a longer allowlist entry.
        $this->assertFalse(Wp_Cli_Guard::is_allowed_subcommand(['cron']));
        $this->assertFalse(Wp_Cli_Guard::is_allowed_subcommand(['cron', 'event']));
    }

    public function test_allowlist_is_filterable(): void
    {
        add_filter('wpmcp_wp_cli_allowlist', function (array $allowlist): array {
            $allowlist[] = 'plugin delete';
            return $allowlist;
        });

        $this->assertTrue(Wp_Cli_Guard::is_allowed_subcommand(['plugin', 'delete', 'akismet']));
    }

    public function test_filter_can_narrow_the_default_allowlist(): void
    {
        add_filter('wpmcp_wp_cli_allowlist', function (): array {
            return ['core version'];
        });

        $this->assertTrue(Wp_Cli_Guard::is_allowed_subcommand(['core', 'version']));
        $this->assertFalse(Wp_Cli_Guard::is_allowed_subcommand(['plugin', 'list']));
    }

    public function test_default_allowlist_contents(): void
    {
        $this->assertSame(
            [
                'core version',
                'plugin list',
                'theme list',
                'cache flush',
                'cron event list',
            ],
            Wp_Cli_Guard::default_allowlist()
        );
    }

    public function test_default_allowlist_does_not_include_option_get_or_user_list(): void
    {
        $defaults = Wp_Cli_Guard::default_allowlist();

        $this->assertNotContains('option get', $defaults);
        $this->assertNotContains('user list', $defaults);
    }
}
