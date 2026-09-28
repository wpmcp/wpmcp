<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\Governance\Governance;
use WPMCP\Governance\Governance_Audit_Log;
use WPMCP\Identity\Identity_Context;
use WPMCP\Identity\Identity_Store;
use WPMCP\MCP\Tool_Exposure;
use WPMCP\RateLimit\Rate_Limiter;
use WPMCP\Skills\Skill_Library;

/**
 * Shared fixtures for the MCP prompts and resources suites (issue #301).
 *
 * A private skill source is mounted through the documented
 * wpmcp_skill_sources filter so the prompt assertions do not depend on
 * which bundled skills happen to be available in the test registry:
 *
 *  - test-prompt-skill:  free, no requirements, must be served.
 *  - test-locked-skill:  tier pro, must NOT be offered as a prompt on an
 *                        unlicensed site (its body is withheld).
 *  - test-missing-skill: requires an ability that does not exist, must NOT
 *                        be offered (a playbook the agent cannot run).
 */
trait PrimitivesFixtures
{
    private string $skill_dir = '';

    private function set_up_primitives(): void
    {
        Governance::reset_for_tests();
        Identity_Context::set_current_for_tests(null);
        delete_option(Identity_Store::OPTION);
        delete_option(Governance_Audit_Log::OPTION);
        delete_option(Tool_Exposure::OPTION);
        Rate_Limiter::set_clock_override(fn() => 1_790_000_301);
        add_filter('wpmcp_rate_limit', fn() => 100000);

        $this->skill_dir = get_temp_dir() . 'wpmcp-primitives-' . wp_generate_password(8, false);
        $this->write_skill('test-prompt-skill', "name: Test prompt skill\ndescription: A fixture playbook served as an MCP prompt.\nversion: 1.2.3\ntier: free", "# Fixture body\n\nFollow these steps exactly.");
        $this->write_skill('test-locked-skill', "name: Locked skill\ndescription: Premium fixture.\nversion: 1.0.0\ntier: pro", 'Premium body.');
        $this->write_skill('test-missing-skill', "name: Missing skill\ndescription: Needs a tool this site lacks.\nversion: 1.0.0\ntier: free\nrequires:\n  - wpmcp/no-such-ability", 'Unreachable body.');

        $dir = $this->skill_dir;
        add_filter('wpmcp_skill_sources', static function (array $sources) use ($dir): array {
            $sources[] = [ 'id' => 'fixture', 'label' => 'Fixture', 'path' => $dir ];
            return $sources;
        });
        Skill_Library::reset();
    }

    private function tear_down_primitives(): void
    {
        remove_all_filters('wpmcp_rate_limit');
        remove_all_filters('wpmcp_skill_sources');
        Rate_Limiter::set_clock_override(null);
        Identity_Context::set_current_for_tests(null);
        Governance::reset_for_tests();
        delete_option(Identity_Store::OPTION);
        delete_option(Governance_Audit_Log::OPTION);
        delete_option(Tool_Exposure::OPTION);
        Skill_Library::reset();

        if ('' !== $this->skill_dir && is_dir($this->skill_dir)) {
            foreach ((array) glob($this->skill_dir . '/*/SKILL.md') as $file) {
                unlink((string) $file);
            }
            foreach ((array) glob($this->skill_dir . '/*', GLOB_ONLYDIR) as $sub) {
                rmdir((string) $sub);
            }
            rmdir($this->skill_dir);
        }
    }

    private function write_skill(string $slug, string $frontmatter, string $body): void
    {
        wp_mkdir_p($this->skill_dir . '/' . $slug);
        file_put_contents($this->skill_dir . '/' . $slug . '/SKILL.md', "---\n" . $frontmatter . "\n---\n\n" . $body . "\n");
    }

    private function as_role(string $role): int
    {
        $id = self::factory()->user->create(['role' => $role]);
        wp_set_current_user($id);
        return $id;
    }

    /** @return array<int, array<string, mixed>> audit rows for one ability and outcome. */
    private function audit_rows(string $ability, bool $allowed): array
    {
        return array_values(array_filter(
            Governance_Audit_Log::list(),
            static fn(array $e) => $ability === $e['ability'] && $allowed === $e['allowed']
        ));
    }
}
