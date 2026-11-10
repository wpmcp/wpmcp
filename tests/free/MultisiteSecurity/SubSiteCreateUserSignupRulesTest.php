<?php

namespace WPMCP\Tests\Free\MultisiteSecurity;

use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Users\Create_User;

/**
 * Issue #561: create-user called wp_insert_user() directly, so on multisite
 * it skipped the network's signup rules that core's user-new.php applies
 * through wpmu_validate_user_signup(): banned email domains, the limited
 * email domains allow-list, illegal names and the username format. It also
 * has to honour the network's "Add New Users" setting (add_new_users),
 * which core enforces through the create_users meta capability.
 *
 * Runs under WP_MULTISITE=1 and skips on single site, where create-user is
 * unchanged (CreateUserTest covers that).
 */
class SubSiteCreateUserSignupRulesTest extends \WP_UnitTestCase
{
    private int $blog = 0;
    private int $sub_admin = 0;
    /** Unique per test, so a user that outlives a test cannot collide. */
    private string $tag = '';

    protected function setUp(): void
    {
        parent::setUp();
        if (! is_multisite()) {
            $this->markTestSkipped('Multisite only: run with WP_MULTISITE=1.');
        }
        $this->setExpectedDeprecated('wpmu_new_blog');
        $this->tag = strtolower(wp_generate_password(8, false));

        $this->network_option('add_new_users', 1);

        $this->blog      = (int) self::factory()->blog->create();
        $this->sub_admin = (int) self::factory()->user->create(['role' => 'subscriber']);
        add_user_to_blog($this->blog, $this->sub_admin, 'administrator');

        switch_to_blog($this->blog);
        // create-user records an undo point (#598) in the site's own table.
        Snapshot_Store::install();
        wp_set_current_user($this->sub_admin);
        $this->assertFalse(is_super_admin($this->sub_admin));
        $this->assertTrue(current_user_can('create_users'), 'Fixture: with add_new_users on, the sub-site admin may create users');
    }

    protected function tearDown(): void
    {
        if (is_multisite()) {
            if (ms_is_switched()) {
                restore_current_blog();
            }
        }
        parent::tearDown();
    }

    /**
     * Set a network option through its pre_site_option filter, which the
     * harness removes after each test. A database write would not do: the
     * user_register hooks create tables, the DDL commits the test's
     * transaction, and the option would outlive the test.
     *
     * @param mixed $value
     */
    private function network_option(string $option, $value): void
    {
        add_filter("pre_site_option_{$option}", static fn () => $value);
    }

    /**
     * Run create-user and return [created user id or null, refusal message or null].
     *
     * @return array{0: ?int, 1: ?string}
     */
    private function attempt(string $username, string $email): array
    {
        try {
            $out = (new Create_User())->handle(['username' => $username, 'email' => $email]);
            return [(int) $out['id'], null];
        } catch (\RuntimeException $e) {
            return [null, $e->getMessage()];
        }
    }

    private function assert_refused(string $username, string $email, string $why): void
    {
        [$id, $refused] = $this->attempt($username, $email);

        $this->assertNull($id, "create-user made a user the network's signup rules forbid: {$why}");
        $this->assertNotNull($refused);
        $this->assertFalse(get_user_by('email', $email), 'No account may exist after a refusal');
    }

    public function test_a_banned_email_domain_is_refused(): void
    {
        $this->network_option('banned_email_domains', ['banned.example']);

        $this->assert_refused("banned{$this->tag}", "x{$this->tag}@banned.example", 'banned email domain');
    }

    public function test_an_email_outside_the_limited_domains_is_refused(): void
    {
        $this->network_option('limited_email_domains', ['allowed.example']);

        $this->assert_refused("limited{$this->tag}", "x{$this->tag}@elsewhere.example", 'limited email domains');
    }

    public function test_an_illegal_name_is_refused(): void
    {
        $name = "reserved{$this->tag}";
        $this->network_option('illegal_names', [$name]);

        $this->assert_refused($name, "{$name}@example.test", 'illegal name');
    }

    public function test_a_username_core_refuses_on_multisite_is_refused(): void
    {
        // Multisite usernames are lowercase letters and digits only.
        $this->assert_refused("bad_name.{$this->tag}", "fmt{$this->tag}@example.test", 'username format');
    }

    public function test_a_valid_signup_is_still_created(): void
    {
        $this->network_option('limited_email_domains', ['allowed.example']);

        [$id, $refused] = $this->attempt("okay{$this->tag}", "okay{$this->tag}@allowed.example");

        $this->assertNull($refused);
        $this->assertGreaterThan(0, $id);
        $this->assertTrue(is_user_member_of_blog((int) $id, $this->blog), 'The new user joins the caller\'s site');
    }

    public function test_with_add_new_users_off_a_sub_site_admin_cannot_create_users(): void
    {
        $this->network_option('add_new_users', 0);
        $email = "off{$this->tag}@example.test";

        $ability = wp_get_abilities()['wpmcp/create-user'] ?? null;
        $this->assertNotNull($ability);
        $this->assertNotTrue(
            $ability->check_permissions(['username' => "off{$this->tag}", 'email' => $email]),
            'create-user must be closed to a sub-site admin when the network does not let site admins add users'
        );

        $this->assert_refused("off{$this->tag}", $email, 'add_new_users is off');
    }
}
