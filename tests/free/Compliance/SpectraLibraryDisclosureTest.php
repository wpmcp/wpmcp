<?php

namespace WPMCP\Tests\Free\Compliance;

/**
 * Issue #364: list-patterns and import-pattern can read Spectra's remote
 * pattern library, so every readme that ships the feature must disclose it
 * under "External services": which ops reach it, that nothing is sent unless
 * it is asked for and Spectra is active, what is sent, that no license key is
 * sent, the user agent, the cache, and the vendor's terms and privacy policy.
 *
 * The feature is paid tier and lives in the block suite files, which the
 * shared wp.org strip removes from both directory builds
 * (scripts/flavors/wporg/policy.php), so only the full plugin readme ships
 * it, and the flavor readmes must not describe a service their build never
 * reaches.
 */
class SpectraLibraryDisclosureTest extends \WP_UnitTestCase
{
    private const MARKER  = 'websitedemos.net';
    private const TERMS   = 'https://store.brainstormforce.com/terms-and-conditions/';
    private const PRIVACY = 'https://store.brainstormforce.com/privacy-policy/';

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

    public function test_every_shipping_readme_discloses_the_spectra_library(): void
    {
        foreach (self::SHIPPING_READMES as $relative) {
            $entry = $this->entry($this->external_services($this->readme($relative)));

            $this->assertStringContainsString('list-patterns', $entry, $relative);
            $this->assertStringContainsString('import-pattern', $entry, $relative);
            $this->assertStringContainsString('Spectra', $entry, $relative);
            $this->assertStringContainsString('WPMCP-Spectra-Library/1.0', $entry, $relative . ' must name the user agent');
            $this->assertStringContainsString('wpmcp_spectra_library_image_hosts', $entry, $relative . ' must name the image host filter');
            $this->assertStringContainsString('Terms: ' . self::TERMS, $entry, $relative);
            $this->assertStringContainsString('Privacy policy: ' . self::PRIVACY, $entry, $relative);
            $this->assertMatchesRegularExpression('/nothing is sent unless/i', $entry, $relative . ' must say the library is only reached on request');
            $this->assertMatchesRegularExpression('/search terms/i', $entry, $relative . ' must say the search terms are sent');
            $this->assertMatchesRegularExpression('/no license key/i', $entry, $relative . ' must say no key is sent');
            $this->assertMatchesRegularExpression('/cached/i', $entry, $relative . ' must say answers are cached');
        }
    }

    public function test_the_feature_is_removed_from_the_builds_whose_readmes_omit_it(): void
    {
        $policy = require $this->repository() . '/scripts/flavors/wporg/policy.php';
        $this->assertContains('src/Integrations/Block_Suite_Spectra_Library.php', $policy['removed_paths']);
        $this->assertFileExists($this->repository() . '/src/Integrations/Block_Suite_Spectra_Library.php');

        foreach (self::STRIPPED_READMES as $relative) {
            $contents = $this->readme($relative);
            $this->assertStringNotContainsString(self::MARKER, $contents, $relative . ' discloses a service its build never reaches');
        }
    }
}
