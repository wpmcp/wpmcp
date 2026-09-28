<?php

namespace WPMCP\Tests\Free\Compliance;

/**
 * Issue #364: list-patterns and import-pattern can read the WordPress.org
 * Pattern Directory, so every readme that ships the feature must disclose it
 * under "External services": which ops reach it, what is sent, that nothing
 * is sent unless the directory is asked for, the image host directory
 * imports may fetch from, and the privacy policy.
 *
 * The feature is paid tier and lives in the block suite files, which the
 * shared wp.org strip removes from both directory builds
 * (scripts/flavors/wporg/policy.php), so only the full plugin readme ships
 * it, and the flavor readmes must not describe a service their build never
 * reaches.
 */
class PatternDirectoryDisclosureTest extends \WP_UnitTestCase
{
    private const MARKER  = 'api.wordpress.org/patterns';
    private const PRIVACY = 'https://wordpress.org/about/privacy/';

    private const SHIPPING_READMES = ['readme.txt'];

    private const STRIPPED_READMES = [
        'scripts/flavors/wporg/readme.txt',
        'scripts/flavors/woocommerce/readme.txt',
    ];

    private function repository(): string
    {
        return dirname(__DIR__, 3);
    }

    private function readme(string $relative): string
    {
        $path = $this->repository() . '/' . $relative;
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    private function external_services(string $contents): string
    {
        $start = strpos($contents, '== External services ==');
        $this->assertNotFalse($start);
        $rest = substr($contents, $start + strlen('== External services =='));
        $end  = preg_match('/^== /m', $rest, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : strlen($rest);
        return substr($rest, 0, $end);
    }

    private function entry(string $section): string
    {
        $found = [];
        foreach (preg_split('/^(?=\* |= )/m', $section) as $chunk) {
            if (false !== strpos($chunk, self::MARKER)) {
                $found[] = $chunk;
            }
        }
        $this->assertCount(1, $found, 'expected exactly one External services entry naming ' . self::MARKER);
        return (string) preg_replace('/\s+/', ' ', $found[0]);
    }

    public function test_every_shipping_readme_discloses_the_pattern_directory(): void
    {
        foreach (self::SHIPPING_READMES as $relative) {
            $entry = $this->entry($this->external_services($this->readme($relative)));

            $this->assertStringContainsString('list-patterns', $entry, $relative);
            $this->assertStringContainsString('import-pattern', $entry, $relative);
            $this->assertStringContainsString('wordpress.org/patterns/wp-json', $entry, $relative . ' must name the category lookup');
            $this->assertStringContainsString('pd.w.org', $entry, $relative . ' must name the image host directory imports fetch from');
            $this->assertStringContainsString('WPMCP-Pattern-Directory/1.0', $entry, $relative . ' must name the user agent');
            $this->assertStringContainsString('Privacy policy: ' . self::PRIVACY, $entry, $relative);
            $this->assertMatchesRegularExpression('/nothing is sent unless/i', $entry, $relative . ' must say the directory is only reached on request');
            $this->assertMatchesRegularExpression('/search terms/i', $entry, $relative . ' must say the search terms are sent');
            $this->assertMatchesRegularExpression('/locale/i', $entry, $relative . ' must say the locale is sent');
        }
    }

    public function test_the_feature_is_removed_from_the_builds_whose_readmes_omit_it(): void
    {
        $policy = require $this->repository() . '/scripts/flavors/wporg/policy.php';
        $this->assertContains('src/Integrations/Block_Suite_Pattern_Directory.php', $policy['removed_paths']);
        $this->assertFileExists($this->repository() . '/src/Integrations/Block_Suite_Pattern_Directory.php');

        foreach (self::STRIPPED_READMES as $relative) {
            $contents = $this->readme($relative);
            $this->assertStringNotContainsString(self::MARKER, $contents, $relative . ' discloses a service its build never reaches');
            $this->assertStringNotContainsString('pd.w.org', $contents, $relative);
        }
    }
}
