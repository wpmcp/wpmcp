<?php

namespace WPMCP\Tests\Free\Compliance;

/**
 * Issue #304: the SEO data provider lookups call a third-party API, so every
 * readme that ships the feature must disclose it under "External services"
 * with what is sent, when, and the provider's terms and privacy links.
 *
 * The feature is paid tier and lives under src/Tools/Analysis, which the
 * shared wp.org strip removes from both directory builds
 * (scripts/flavors/wporg/policy.php), so only the full plugin readme ships
 * it. The flavor readmes must then not name its abilities either: gate 3h of
 * the release build fails a shipped document naming an ability that build
 * does not register.
 */
class SeoDataDisclosureTest extends \WP_UnitTestCase
{
    private const HOST = 'api.dataforseo.com';

    private const TERMS   = 'https://dataforseo.com/terms-of-service';
    private const PRIVACY = 'https://dataforseo.com/privacy-policy';

    /** Readmes that ship the feature. */
    private const SHIPPING_READMES = ['readme.txt'];

    /** Readmes of builds the strip removes the feature from. */
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
            if (false !== strpos($chunk, self::HOST)) {
                $found[] = $chunk;
            }
        }
        $this->assertCount(1, $found, 'expected exactly one External services entry naming ' . self::HOST);
        return (string) preg_replace('/\s+/', ' ', $found[0]);
    }

    public function test_every_shipping_readme_discloses_the_provider(): void
    {
        foreach (self::SHIPPING_READMES as $relative) {
            $entry = $this->entry($this->external_services($this->readme($relative)));

            $this->assertStringContainsString('analyze-seo', $entry, $relative);
            $this->assertStringContainsString('set-seo-data-key', $entry, $relative);
            $this->assertStringContainsString('Terms: ' . self::TERMS, $entry, $relative);
            $this->assertStringContainsString('Privacy policy: ' . self::PRIVACY, $entry, $relative);
            $this->assertMatchesRegularExpression('/nothing is sent until/i', $entry, $relative . ' must say the lookup is opt-in');
            $this->assertMatchesRegularExpression('/keywords/i', $entry, $relative . ' must say the keywords are sent');
            $this->assertMatchesRegularExpression('/domain or URL/i', $entry, $relative . ' must say the backlink target is sent');
            $this->assertMatchesRegularExpression('/Authorization header/i', $entry, $relative . ' must say the credentials are sent');
        }
    }

    public function test_the_feature_is_removed_from_the_builds_whose_readmes_omit_it(): void
    {
        $policy = require $this->repository() . '/scripts/flavors/wporg/policy.php';
        $this->assertContains('src/Tools/Analysis', $policy['removed_paths']);
        $this->assertFileExists($this->repository() . '/src/Tools/Analysis/SeoData/Dataforseo_Provider.php');

        foreach (self::STRIPPED_READMES as $relative) {
            $contents = $this->readme($relative);
            $this->assertStringNotContainsString('set-seo-data-key', $contents, $relative . ' names an ability its build does not ship');
            $this->assertStringNotContainsString(self::HOST, $contents, $relative . ' discloses a host its build never reaches');
        }
    }
}
