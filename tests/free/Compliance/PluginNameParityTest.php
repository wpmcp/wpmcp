<?php

namespace WPMCP\Tests\Free\Compliance;

use WPMCP\Compliance\Severity;
use WPMCP\Compliance\Rules\Trademark_Rule;

/**
 * The shipped display names, checked against the files that actually ship
 * them rather than against a fixture.
 *
 * Three artifacts are built out of this tree and each one carries its own
 * header/readme pair: the root pair goes into the paid self-hosted zip
 * (scripts/build-release.sh), scripts/flavors/wporg/* into the directory zip
 * (scripts/build-wporg-release.sh) and scripts/flavors/woocommerce/* into the
 * vertical zip (scripts/build-woo-release.sh). Plugin Check reads the header
 * and the readme title of whichever one it is handed, so every pair has to
 * agree with itself, and the two zips that carry the slug "wpmcp" have to
 * agree with each other or the same install renames itself depending on where
 * it came from. Nothing outside build-wporg-release.sh gated that before, so
 * a mismatch could sit in a flavor indefinitely.
 */
class PluginNameParityTest extends Compliance_Test_Case
{
    /**
     * @return array<string,array{0:string,1:string}> label => [main file, readme]
     */
    public static function artifact_pairs(): array
    {
        $root = dirname(__DIR__, 3);

        return [
            'root (pro zip)' => [$root . '/wpmcp.php', $root . '/readme.txt'],
            'wporg flavor' => [
                $root . '/scripts/flavors/wporg/wpmcp.php',
                $root . '/scripts/flavors/wporg/readme.txt',
            ],
            'woocommerce flavor' => [
                $root . '/scripts/flavors/woocommerce/wpmcp-for-woocommerce.php',
                $root . '/scripts/flavors/woocommerce/readme.txt',
            ],
        ];
    }

    /**
     * @dataProvider artifact_pairs
     */
    public function test_header_name_and_readme_title_are_byte_identical(string $main_file, string $readme): void
    {
        $header = $this->header_name($main_file);
        $title = $this->readme_title($readme);

        $this->assertSame(
            $header,
            $title,
            sprintf(
                "Plugin Check reports mismatched_plugin_name when these differ:\n  %s\n  %s",
                $main_file,
                $readme
            )
        );
    }

    /**
     * The leading "WP" is deliberate and tolerated: Plugin Check warns on it
     * and the engine grades it best-practice, which WPORG-SUBMISSION.md
     * records as an accepted cost. Anything above that severity, "wordpress"
     * included, is a hard Plugin Check failure and must not ship.
     *
     * @dataProvider artifact_pairs
     */
    public function test_no_shipped_display_name_carries_a_restricted_term(string $main_file, string $readme): void
    {
        $name = $this->header_name($main_file);

        $findings = $this->findings(new Trademark_Rule(), [
            'example-toolkit.php' => $this->main_file(['Plugin Name' => $name]),
            'readme.txt' => $this->readme(['title' => $name]),
        ]);

        $hard = array_values(array_filter(
            $findings,
            static fn ($finding) => Severity::BEST_PRACTICE !== $finding->severity_override()
        ));

        $this->assertSame([], $this->messages($hard));
        $this->assertStringNotContainsStringIgnoringCase('wordpress', $name);
    }

    public function test_the_two_wpmcp_slug_artifacts_share_one_display_name(): void
    {
        $pairs = self::artifact_pairs();

        $this->assertSame(
            $this->header_name($pairs['wporg flavor'][0]),
            $this->header_name($pairs['root (pro zip)'][0]),
            'the directory zip and the self-hosted zip install into the same wpmcp/ directory under the same '
            . 'text domain, so a divergent Plugin Name renames the plugin on update'
        );
    }

    public function test_the_documented_submission_name_is_the_name_that_ships(): void
    {
        $root = dirname(__DIR__, 3);
        $documented = $this->documented_submission_name($root . '/WPORG-SUBMISSION.md');

        $this->assertNotSame('', $documented, 'WPORG-SUBMISSION.md no longer pins a plugin name');
        $this->assertSame(
            $documented,
            $this->header_name($root . '/scripts/flavors/wporg/wpmcp.php'),
            'the name pasted into the submission form has to be the name in the submitted zip'
        );
    }

    private function header_name(string $path): string
    {
        $this->assertFileExists($path);
        $matched = preg_match('/^[\s*#]*Plugin Name:\s*(.+?)\s*$/mi', (string) file_get_contents($path), $m);
        $this->assertSame(1, $matched, sprintf('no Plugin Name header in %s', $path));

        return $m[1];
    }

    private function readme_title(string $path): string
    {
        $this->assertFileExists($path);
        $matched = preg_match('/^===\s*(.+?)\s*===\s*$/m', (string) file_get_contents($path), $m);
        $this->assertSame(1, $matched, sprintf('no === title === line in %s', $path));

        return $m[1];
    }

    private function documented_submission_name(string $path): string
    {
        $this->assertFileExists($path);
        $matched = preg_match(
            '/\*\*Plugin name\*\*.*?```\s*\n(.+?)\n```/s',
            (string) file_get_contents($path),
            $m
        );

        return 1 === $matched ? trim($m[1]) : '';
    }
}
