<?php

namespace WPMCP\Tests\Pro\Code;

use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\MCP\{Ability, Registrar};
use WPMCP\Pro\Gate;
use WPMCP\Tools\Code\Activate_Php_Snippet;
use WPMCP\Tools\Code\Create_Php_Snippet;
use WPMCP\Tools\Code\Php_Snippet_Guard;
use WPMCP\Tools\Code\Php_Snippet_Store;
use WPMCP\Tools\Code\Update_Php_Snippet;

/**
 * activate-php-snippet (issue #85) is PRO and exec-gated: marking a stored
 * snippet active is one step from running it, so it clears the SAME chain
 * run-php-snippet clears (Php_Snippet_Guard::assert_execution_allowed()),
 * and every attempt lands in the governance audit trail for the same reason
 * run-php-snippet's does.
 *
 * The "shared, not duplicated" claim is tested, not asserted in prose: both
 * surfaces refuse under the same two conditions, so a third gate added to
 * that chain cannot apply to one and not the other.
 */
class ActivatePhpSnippetTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Php_Snippet_Store::OPTION_NAME);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        Php_Snippet_Guard::set_environment_override('development');
    }

    protected function tearDown(): void
    {
        Php_Snippet_Guard::set_environment_override(null);
        remove_all_filters('wpmcp_allow_php_exec');
        remove_all_filters('wpmcp_allow_php_exec_on_production');
        delete_option(Php_Snippet_Store::OPTION_NAME);
        Gate::set_pro_for_tests(null);
        parent::tearDown();
    }

    private function enable_exec(): void
    {
        add_filter('wpmcp_allow_php_exec', '__return_true');
    }

    private function stored_id(string $code = '<?php return 1;'): string
    {
        return (new Create_Php_Snippet())->handle(['name' => 'candidate', 'code' => $code])['snippet']['id'];
    }

    public function test_activation_refuses_while_php_execution_is_disabled(): void
    {
        $id = $this->stored_id();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PHP execution is disabled');

        (new Activate_Php_Snippet())->handle(['id' => $id]);
    }

    public function test_activation_refuses_on_a_production_environment(): void
    {
        $this->enable_exec();
        Php_Snippet_Guard::set_environment_override('production');
        $id = $this->stored_id();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('refused on this environment');

        (new Activate_Php_Snippet())->handle(['id' => $id]);
    }

    public function test_a_refused_activation_leaves_the_snippet_inactive(): void
    {
        $id = $this->stored_id();

        try {
            (new Activate_Php_Snippet())->handle(['id' => $id]);
            $this->fail('Activation must refuse while the execution gate is closed.');
        } catch (\RuntimeException $e) {
            $this->assertSame(Php_Snippet_Store::STATUS_INACTIVE, Php_Snippet_Store::get($id)['status']);
        }
    }

    public function test_activation_flips_the_status_once_the_gates_pass(): void
    {
        $this->enable_exec();
        $id = $this->stored_id();

        $out = (new Activate_Php_Snippet())->handle(['id' => $id]);

        $this->assertSame(Php_Snippet_Store::STATUS_ACTIVE, $out['snippet']['status']);
        $this->assertSame(Php_Snippet_Store::STATUS_ACTIVE, Php_Snippet_Store::get($id)['status']);
        $this->assertNotEmpty($out['operation_id']);
    }

    public function test_activation_does_not_resurrect_the_pre_update_code(): void
    {
        // The stale-record bug this guards: reading the whole record before
        // the snapshot and writing it back afterwards silently reverts any
        // field another operation changed in between.
        $this->enable_exec();
        $id = $this->stored_id('<?php return 1;');

        (new Update_Php_Snippet())->handle(['id' => $id, 'code' => '<?php return 2;']);
        (new Activate_Php_Snippet())->handle(['id' => $id]);

        $this->assertSame('<?php return 2;', Php_Snippet_Store::get($id)['code']);
    }

    public function test_activation_of_a_snippet_deleted_since_the_read_does_not_recreate_it(): void
    {
        $this->enable_exec();
        $id = $this->stored_id();
        Php_Snippet_Store::delete($id);

        $this->expectException(\RuntimeException::class);

        (new Activate_Php_Snippet())->handle(['id' => $id]);
    }

    /**
     * The window the closure-side re-read opens if nothing closes it: the
     * code is validated OUTSIDE the Safe_Mutation closure and re-read INSIDE
     * it, so an update landing in between would mark ACTIVE a body nothing
     * ever validated, stamped with a report describing the code it replaced.
     * That is precisely what Update_Php_Snippet forces status back to
     * inactive to prevent, so activation must refuse rather than paper over
     * it.
     *
     * Driven by swapping the stored code out from under the tool on every
     * read after the first, which is the only deterministic way to place a
     * concurrent writer inside a single-process test.
     */
    public function test_activation_refuses_when_the_code_moved_after_it_was_validated(): void
    {
        $this->enable_exec();
        $id = $this->stored_id('<?php return 1;');

        $reads = 0;
        $swap  = function ($snippets) use (&$reads, $id) {
            $reads++;
            if ($reads > 1 && is_array($snippets) && isset($snippets[$id])) {
                $snippets[$id]['code'] = '<?php return 999;';
            }
            return $snippets;
        };
        add_filter('option_' . Php_Snippet_Store::OPTION_NAME, $swap);

        try {
            (new Activate_Php_Snippet())->handle(['id' => $id]);
            $this->fail('Activating code that changed after validation must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('changed after it was validated', $e->getMessage());
        } finally {
            remove_filter('option_' . Php_Snippet_Store::OPTION_NAME, $swap);
        }

        $this->assertSame(
            Php_Snippet_Store::STATUS_INACTIVE,
            Php_Snippet_Store::get($id)['status'],
            'The refused activation must leave the snippet inactive.'
        );
    }

    /**
     * "Every attempt, allowed or refused, is written to the governance audit
     * trail" is what the shipped ability description tells an agent. A
     * failure inside the Safe_Mutation closure (here: the record vanished
     * between the read and the write) used to escape the try/catch entirely,
     * so the one attempt an admin would most want to see left no entry.
     */
    public function test_an_activation_that_fails_inside_the_mutation_is_still_audited(): void
    {
        $this->enable_exec();
        $id = $this->stored_id();

        // The record is there when handle() reads and validates it, and gone
        // by the time the Safe_Mutation closure runs. That throw happens
        // OUTSIDE the old try/catch, so the one attempt an admin would most
        // want in the trail left no entry at all.
        $reads = 0;
        $vanish = function ($snippets) use (&$reads, $id) {
            $reads++;
            if ($reads > 2 && is_array($snippets)) {
                unset($snippets[$id]);
            }
            return $snippets;
        };
        add_filter('option_' . Php_Snippet_Store::OPTION_NAME, $vanish);

        $before = count(Governance_Audit_Log::list(200));

        try {
            (new Activate_Php_Snippet())->handle(['id' => $id]);
            $this->fail('Activating a snippet that vanished mid-operation must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('removed since this operation started', $e->getMessage());
        } finally {
            remove_filter('option_' . Php_Snippet_Store::OPTION_NAME, $vanish);
        }

        $entries = Governance_Audit_Log::list(200);
        $this->assertGreaterThan($before, count($entries), 'A failure inside the mutation must still be audited.');
        $this->assertSame('wpmcp/activate-php-snippet', $entries[0]['ability']);
        $this->assertFalse((bool) $entries[0]['allowed']);
    }

    public function test_an_activation_refused_for_a_missing_id_is_audited_too(): void
    {
        $this->enable_exec();
        $before = count(Governance_Audit_Log::list(200));

        try {
            (new Activate_Php_Snippet())->handle([]);
            $this->fail('An empty id must be refused.');
        } catch (\InvalidArgumentException $e) {
            // expected: thrown before any gate, and still audited.
        }

        $entries = Governance_Audit_Log::list(200);
        $this->assertGreaterThan($before, count($entries));
        $this->assertSame('wpmcp/activate-php-snippet', $entries[0]['ability']);
        $this->assertFalse((bool) $entries[0]['allowed']);
    }

    public function test_a_successful_activation_is_audited_as_an_allow(): void
    {
        $this->enable_exec();
        $id = $this->stored_id();

        (new Activate_Php_Snippet())->handle(['id' => $id]);

        $entries = Governance_Audit_Log::list(200);
        $this->assertSame('wpmcp/activate-php-snippet', $entries[0]['ability']);
        $this->assertTrue((bool) $entries[0]['allowed']);
    }

    public function test_every_activation_attempt_is_audited(): void
    {
        $before = count(Governance_Audit_Log::list(200));

        $id = $this->stored_id();
        try {
            (new Activate_Php_Snippet())->handle(['id' => $id]);
        } catch (\RuntimeException $e) {
            // expected: the gate is closed
        }

        $entries = Governance_Audit_Log::list(200);
        $this->assertGreaterThan($before, count($entries), 'A refused activation must reach the governance trail.');

        $names = array_map(fn ($e) => $e['ability'] ?? '', $entries);
        $this->assertContains('wpmcp/activate-php-snippet', $names);
    }

    // -----------------------------------------------------------------
    // registration
    // -----------------------------------------------------------------

    private function make_activate_ability(): Ability
    {
        return new Ability(
            'wpmcp/activate-php-snippet',
            'pro',
            'Activate a stored PHP snippet.',
            [
                'type'       => 'object',
                'properties' => ['id' => ['type' => 'string']],
                'required'   => ['id'],
            ],
            [new Activate_Php_Snippet(), 'handle'],
            'manage_options',
            'code',
            'update'
        );
    }

    public function test_registrar_skips_activation_when_free(): void
    {
        Gate::set_pro_for_tests(false);

        $registrar = new Registrar();
        $registrar->register($this->make_activate_ability());

        $this->assertCount(0, $registrar->all());
    }

    public function test_registrar_keeps_activation_when_pro(): void
    {
        Gate::set_pro_for_tests(true);

        $registrar = new Registrar();
        $registrar->register($this->make_activate_ability());

        $names = array_map(fn ($a) => $a->name, $registrar->all());
        $this->assertContains('wpmcp/activate-php-snippet', $names);
    }
}
