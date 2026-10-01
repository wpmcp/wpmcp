<?php

namespace WPMCP\Tests\Free\Platform;

use WPMCP\Auth\Bearer_Auth;
use WPMCP\Auth\Client_Access;
use WPMCP\Auth\Mcp_Resource;
use WPMCP\Auth\Token_Store;
use WPMCP\MCP\Registrar;
use WPMCP\Pro\Gate;

/**
 * Registry-wide companion to OAuthScopesTest (issue #454).
 *
 * Walks every registered ability, free and pro, as an administrator holding
 * every capability, authenticated by a read-only (`mcp:read`) OAuth token.
 * The rule comes from each ability's registered operation alone: a `read`
 * ability is decided exactly as it would be for a full token, and every
 * other operation is refused with `insufficient_scope`, whatever the user
 * could otherwise do. No ability is exempt, so an ability added later with
 * a write operation is covered without touching this test.
 */
class ReadOnlyScopeRegistryTest extends \WP_UnitTestCase
{
    private $original_request_uri;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        add_filter('wpmcp_oauth_enabled', '__return_true');
        add_filter('user_has_cap', [$this, 'grant_everything']);
        delete_option(Token_Store::OPTION);
        delete_option(Client_Access::OPTION);
        $this->original_request_uri = $_SERVER['REQUEST_URI'] ?? null;
    }

    protected function tearDown(): void
    {
        remove_filter('user_has_cap', [$this, 'grant_everything']);
        remove_all_filters('wpmcp_oauth_enabled');
        Gate::set_pro_for_tests(null);
        Bearer_Auth::reset_for_tests();
        unset($_SERVER['HTTP_AUTHORIZATION']);
        if (null === $this->original_request_uri) {
            unset($_SERVER['REQUEST_URI']);
        } else {
            $_SERVER['REQUEST_URI'] = $this->original_request_uri;
        }
        delete_option(Token_Store::OPTION);
        delete_option(Client_Access::OPTION);
        wp_set_current_user(0);
        parent::tearDown();
    }

    /** @param array<string,bool> $allcaps */
    public function grant_everything(array $allcaps, array $caps = []): array
    {
        foreach ($caps as $cap) {
            $allcaps[ $cap ] = true;
        }
        return $allcaps;
    }

    private function authenticate(int $user, string $scope): void
    {
        Bearer_Auth::reset_for_tests();
        $_SERVER['REQUEST_URI']        = Mcp_Resource::relative_path();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . Token_Store::issue('client_registry', $user, $scope);
        $this->assertSame($user, Bearer_Auth::resolve(0));
        wp_set_current_user($user);
    }

    public function test_a_read_only_token_is_refused_every_write_ability_and_decided_normally_on_reads(): void
    {
        $admin     = self::factory()->user->create(['role' => 'administrator']);
        $registrar = new Registrar();
        $abilities = RegisteredAbilities::all();

        // What a full token may do, ability by ability.
        $this->authenticate($admin, 'mcp');
        $full = [];
        foreach ($abilities as $a) {
            $full[ $a->name ] = $registrar->permission($a, []);
        }

        $this->authenticate($admin, 'mcp:read');
        $writes   = 0;
        $reads    = 0;
        $failures = [];
        foreach ($abilities as $a) {
            $result = $registrar->permission($a, []);
            if ('read' === $a->operation) {
                ++$reads;
                if ($result !== $full[ $a->name ]) {
                    $failures[] = sprintf('%s (read): decided differently from a full token', $a->name);
                }
                continue;
            }

            ++$writes;
            if (! is_wp_error($result) || 'insufficient_scope' !== $result->get_error_code()) {
                $failures[] = sprintf('%s (%s): %s', $a->name, $a->operation, is_wp_error($result) ? $result->get_error_code() : var_export($result, true));
            }
            if ($registrar->would_permit($a, [])) {
                $failures[] = sprintf('%s (%s): would_permit allowed it', $a->name, $a->operation);
            }
        }

        $this->assertGreaterThan(100, $writes, 'Too few write abilities walked.');
        $this->assertGreaterThan(100, $reads, 'Too few read abilities walked.');
        $this->assertSame([], $failures);
    }

    public function test_a_full_token_is_not_refused_for_scope(): void
    {
        $admin     = self::factory()->user->create(['role' => 'administrator']);
        $registrar = new Registrar();
        $this->authenticate($admin, 'mcp');

        $failures = [];
        foreach (RegisteredAbilities::all() as $a) {
            $result = $registrar->permission($a, []);
            if (is_wp_error($result) && 'insufficient_scope' === $result->get_error_code()) {
                $failures[] = $a->name;
            }
        }

        $this->assertSame([], $failures);
    }
}
