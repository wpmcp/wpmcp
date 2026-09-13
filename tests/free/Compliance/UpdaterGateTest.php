<?php

namespace WPMCP\Tests\Free\Compliance;

use WPMCP\Compliance\Rules\Updater_Rule;

/**
 * scripts/lib/updater-gate.sh, the build-time half of WPORG-08-UPDATER.
 *
 * The gate exists because Plugin_Updater_Check is a file-content regex with
 * no vendor carve-out and the wp.org run does not exclude vendor/, so the
 * directory build has to prove that no error-level marker survives anywhere
 * in the tree it is about to zip. It reads its pattern list from
 * Updater_Rule::UPDATER_PATTERNS rather than carrying a second copy, so these
 * tests also pin the two together: a pattern that only the rule knows about
 * must still fail the build.
 */
class UpdaterGateTest extends Compliance_Test_Case
{
    private function repository_root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @param array<string,string> $files
     * @return array{status:int,output:string}
     */
    private function gate(array $files): array
    {
        $tree = $this->make_plugin($files);
        $command = sprintf(
            'bash %s %s 2>&1',
            escapeshellarg($this->repository_root() . '/scripts/lib/updater-gate.sh'),
            escapeshellarg($tree)
        );
        $lines = [];
        $status = 0;
        exec($command, $lines, $status);
        return ['status' => $status, 'output' => implode("\n", $lines)];
    }

    public function test_a_tree_with_no_updater_marker_passes(): void
    {
        $result = $this->gate([
            'example-toolkit.php' => $this->main_file(),
            'src/Plain.php' => "<?php\nclass Plain {}\n",
            'vendor/acme/library/src/Client.php' => "<?php\nclass Client {}\n",
        ]);

        $this->assertSame(0, $result['status'], $result['output']);
    }

    public function test_a_vendored_updater_fails_the_gate(): void
    {
        $result = $this->gate([
            'example-toolkit.php' => $this->main_file(),
            'vendor/acme/sdk/updates.php' => "<?php\nadd_filter( 'site_transient_update_plugins', 'acme_inject' );\n",
        ]);

        $this->assertSame(1, $result['status'], $result['output']);
        $this->assertStringContainsString('site_transient_update_plugins', $result['output']);
    }

    /**
     * Updater_Rule compiles the same list with /i, and so does Plugin Check,
     * so a case variant that the gate let through would still be an error at
     * wordpress.org.
     */
    public function test_the_gate_matches_case_insensitively(): void
    {
        $result = $this->gate([
            'example-toolkit.php' => $this->main_file(),
            'vendor/acme/sdk/updates.php' => "<?php\n// SITE_TRANSIENT_UPDATE_PLUGINS\nclass Wp_GitHub_Updater {}\n",
        ]);

        $this->assertSame(1, $result['status'], $result['output']);
    }

    /**
     * The rule's host pattern is updater\.\w+\.\w{2,5}: \w includes digits,
     * so a host like updater.example.co2 matches. A hand-copied
     * [A-Za-z]{2,5} in the gate would not, which is exactly the drift the
     * shared pattern list is there to prevent.
     */
    public function test_the_gate_uses_the_rules_own_host_pattern(): void
    {
        $result = $this->gate([
            'example-toolkit.php' => $this->main_file(),
            'src/Feed.php' => "<?php\n\$endpoint = 'https://updater.example.co2/check';\n",
        ]);

        $this->assertSame(1, $result['status'], $result['output']);
    }

    /**
     * A filename match, which no content grep can catch.
     */
    public function test_a_vendored_plugin_update_checker_file_fails_the_gate(): void
    {
        $result = $this->gate([
            'example-toolkit.php' => $this->main_file(),
            'vendor/acme/puc/plugin-update-checker.php' => "<?php\nclass Checker {}\n",
        ]);

        $this->assertSame(1, $result['status'], $result['output']);
        $this->assertStringContainsString('plugin-update-checker.php', $result['output']);
    }

    public function test_the_gate_reports_a_usage_error_for_a_missing_tree(): void
    {
        $command = sprintf(
            'bash %s %s 2>&1',
            escapeshellarg($this->repository_root() . '/scripts/lib/updater-gate.sh'),
            escapeshellarg(sys_get_temp_dir() . '/wpmcp-no-such-tree-' . uniqid('', true))
        );
        $lines = [];
        $status = 0;
        exec($command, $lines, $status);

        $this->assertSame(2, $status, implode("\n", $lines));
    }

    public function test_the_wporg_build_script_delegates_to_the_shared_gate(): void
    {
        $script = (string) file_get_contents($this->repository_root() . '/scripts/build-wporg-release.sh');

        $this->assertStringContainsString('scripts/lib/updater-gate.sh', $script);
        $this->assertStringNotContainsString(
            'site_transient_update_plugins',
            $script,
            'the build script must not carry a second copy of Updater_Rule::UPDATER_PATTERNS'
        );
    }

    public function test_every_rule_pattern_is_exercisable_by_the_gate(): void
    {
        $this->assertNotEmpty(Updater_Rule::UPDATER_PATTERNS);
        foreach (Updater_Rule::UPDATER_PATTERNS as $pattern) {
            $this->assertIsString($pattern);
            $this->assertNotSame('', trim($pattern));
        }
    }
}
