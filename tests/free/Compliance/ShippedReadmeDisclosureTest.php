<?php

namespace WPMCP\Tests\Free\Compliance;

/**
 * Issue #166: the readmes we actually ship must describe the real outbound
 * behaviour of the build they ship with.
 *
 * The compliance engine tests cover the rules against synthetic fixtures.
 * This one points the same expectations at the three readmes that leave this
 * repository (the full plugin, the wp.org directory build, and the
 * WooCommerce vertical build), because a rule that is never run against our
 * own listing copy is how a false privacy claim survived to begin with.
 *
 * The host and ability lists below are the outbound call sites that survive
 * every build's prune list (scripts/build-wporg-release.sh and
 * scripts/build-woo-release.sh only remove domains registered from the
 * flavor-gated $groups map in Plugin::register_abilities(); everything
 * registered before that map, media, packages, security and performance
 * included, ships in all three).
 */
class ShippedReadmeDisclosureTest extends \WP_UnitTestCase
{
    /** Readmes that are published to users, keyed by their repo-relative path. */
    private const SHIPPED_READMES = [
        'readme.txt',
        'scripts/flavors/wporg/readme.txt',
        'scripts/flavors/woocommerce/readme.txt',
    ];

    /** Hosts reached from src/ in every build, whatever the flavor. */
    private const REQUIRED_HOSTS = [
        'api.wordpress.org',
        'downloads.wordpress.org',
        'api.openverse.org',
        'api.pexels.com',
        'api.unsplash.com',
        'www.wpvulnerability.net',
    ];

    /** Abilities that can put a request on the wire, named so a user can find them. */
    private const REQUIRED_ABILITIES = [
        'scan-security',
        'search-stock-images',
        'import-stock-image',
        'upload-svg',
        'sideload-image',
        'search-plugins',
        'install-plugin',
        'analyze-performance',
    ];

    /**
     * Claims that were false when this test was written. Absolutes about
     * outbound traffic and scheduling are the ones a reviewer checks first.
     */
    private const FALSE_CLAIMS = [
        '/\bmakes no calls home\b/i',
        '/\bno calls home\b/i',
        '/\bno telemetry\b/i',
        '/\bhas no scheduled jobs\b/i',
        '/\bno site URL\b/i',
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

    /** The body of the "== External services ==" section, up to the next section. */
    private function external_services(string $contents): string
    {
        $start = strpos($contents, '== External services ==');
        $this->assertNotFalse($start, 'the readme must carry an "== External services ==" section');
        $rest = substr($contents, $start + strlen('== External services =='));
        $end  = preg_match('/^== /m', $rest, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : strlen($rest);
        return substr($rest, 0, $end);
    }

    public function test_no_shipped_readme_makes_an_absolute_no_outbound_traffic_claim(): void
    {
        foreach (self::SHIPPED_READMES as $relative) {
            $contents = $this->readme($relative);
            foreach (self::FALSE_CLAIMS as $pattern) {
                $this->assertSame(
                    0,
                    preg_match($pattern, $contents),
                    $relative . ' still carries a privacy claim the build contradicts: ' . $pattern
                );
            }
        }
    }

    public function test_every_shipped_readme_discloses_every_host_the_build_reaches(): void
    {
        foreach (self::SHIPPED_READMES as $relative) {
            $section = $this->external_services($this->readme($relative));
            foreach (self::REQUIRED_HOSTS as $host) {
                $this->assertStringContainsString(
                    $host,
                    $section,
                    $relative . ' does not disclose ' . $host . ' under "External services"'
                );
            }
        }
    }

    public function test_every_shipped_readme_names_the_abilities_that_make_requests(): void
    {
        foreach (self::SHIPPED_READMES as $relative) {
            $section = $this->external_services($this->readme($relative));
            foreach (self::REQUIRED_ABILITIES as $ability) {
                $this->assertStringContainsString(
                    $ability,
                    $section,
                    $relative . ' does not name the ' . $ability . ' ability under "External services"'
                );
            }
        }
    }

    public function test_every_shipped_readme_discloses_the_daily_oauth_cleanup(): void
    {
        foreach (self::SHIPPED_READMES as $relative) {
            $contents = $this->readme($relative);
            $this->assertMatchesRegularExpression(
                '/scheduled task/i',
                $contents,
                $relative . ' must describe the daily OAuth token cleanup rather than deny scheduling'
            );
        }
    }

    public function test_the_stock_image_allowlist_is_described_as_a_filterable_default(): void
    {
        foreach (self::SHIPPED_READMES as $relative) {
            $section = $this->external_services($this->readme($relative));
            $this->assertStringContainsString(
                'wpmcp_remote_media_allowed_hosts',
                $section,
                $relative . ' presents the media allowlist as fixed, but it runs through a filter'
            );
        }
    }

    /**
     * Third-party providers whose entry must link both a terms page and a
     * privacy page (issue #187), keyed by a string found only in that entry.
     * api.wordpress.org is deliberately absent: WordPress.org publishes a
     * privacy policy but no terms for its APIs, so its entries carry only the
     * privacy link.
     */
    private const PROVIDER_POLICIES = [
        'api.openverse.org'       => ['https://openverse.org/terms', 'https://openverse.org/privacy'],
        'api.pexels.com'          => ['https://www.pexels.com/terms-of-service/', 'https://www.pexels.com/privacy-policy/'],
        'api.unsplash.com'        => ['https://unsplash.com/terms', 'https://unsplash.com/privacy'],
        'www.wpvulnerability.net' => ['https://www.wpvulnerability.com/license/', 'https://www.wpvulnerability.com/privacy/'],
        'upload.wikimedia.org'    => ['https://foundation.wikimedia.org/wiki/Policy:Terms_of_Use', 'https://foundation.wikimedia.org/wiki/Policy:Privacy_policy'],
        'staticflickr.com'        => ['https://www.flickr.com/help/terms', 'https://www.flickr.com/help/privacy'],
        'WP MCP Cloud ('          => ['https://wpmcp-pro.com/terms.html', 'https://wpmcp-pro.com/privacy.html'],
    ];

    /**
     * Shipped readmes that carry no WP MCP Cloud entry, with the reason.
     * Empty on purpose: every build ships src/Admin/Announcements.php, which
     * Plugin boots unconditionally and which fetches /announcements through
     * Cloud_Client whenever a cloud URL and key are saved. The directory cut
     * keeps src/Cloud for that reason (scripts/flavors/wporg/policy.php), and
     * the WooCommerce cut keeps it too since issue #257 (without it the
     * vertical fatals on load), so all three readmes must disclose it.
     */
    private const CLOUD_ENTRY_EXCLUSIONS = [];

    /**
     * One "External services" entry: the bullet (full plugin readme, one
     * line per service) or the "= Heading =" block (flavor readmes) that
     * contains $needle. Entries are split on their own boundaries, so the
     * order of services in the readme does not matter.
     */
    private function entry(string $section, string $needle): string
    {
        $found = [];
        foreach (preg_split('/^(?=\* |= )/m', $section) as $chunk) {
            if (false !== strpos($chunk, $needle)) {
                $found[] = $chunk;
            }
        }
        $this->assertCount(1, $found, 'expected exactly one External services entry containing "' . $needle . '"');
        return $found[0];
    }

    /**
     * Issue #187: Openverse needs no key, so nothing resembling consent
     * happens before the request. search-stock-images falls back to it when
     * no provider is named, and the readme has to say so rather than imply
     * the user picked it.
     */
    public function test_openverse_is_documented_as_the_unconditional_default(): void
    {
        foreach (self::SHIPPED_READMES as $relative) {
            $entry = preg_replace('/\s+/', ' ', $this->entry($this->external_services($this->readme($relative)), 'api.openverse.org'));
            $this->assertMatchesRegularExpression('/\bdefault provider\b/i', $entry, $relative . ' does not say that Openverse is the default stock provider');
            $this->assertMatchesRegularExpression('/\bnot opt-in\b/i', $entry, $relative . ' does not say that Openverse is not opt-in');
        }
    }

    /** Issue #187: every third-party provider entry links both its terms and its privacy policy. */
    public function test_every_provider_entry_links_its_terms_and_privacy_policy(): void
    {
        foreach (self::SHIPPED_READMES as $relative) {
            $section = $this->external_services($this->readme($relative));
            foreach (self::PROVIDER_POLICIES as $needle => [$terms, $privacy]) {
                if ('WP MCP Cloud (' === $needle && in_array($relative, self::CLOUD_ENTRY_EXCLUSIONS, true)) {
                    continue;
                }
                $entry = preg_replace('/\s+/', ' ', $this->entry($section, $needle));
                $this->assertStringContainsString($terms, $entry, $relative . ': the "' . $needle . '" entry lacks its terms link');
                $this->assertStringContainsString($privacy, $entry, $relative . ': the "' . $needle . '" entry lacks its privacy link');
            }
        }
    }

    /**
     * Issue #187: the announcements feed reaches the cloud on admin page
     * loads, not only from the cloud abilities, so the Cloud entry must say
     * so and say what is sent.
     */
    public function test_the_cloud_entry_discloses_the_announcements_request(): void
    {
        foreach (array_diff(self::SHIPPED_READMES, self::CLOUD_ENTRY_EXCLUSIONS) as $relative) {
            $entry = preg_replace('/\s+/', ' ', $this->entry($this->external_services($this->readme($relative)), 'WP MCP Cloud ('));
            $this->assertStringContainsString('/announcements', $entry, $relative . ': the Cloud entry does not disclose the announcements fetch');
            $this->assertMatchesRegularExpression('/API key in the Authorization header/i', $entry, $relative . ': the Cloud entry does not say the API key is sent');
            $this->assertMatchesRegularExpression('/admin screen/i', $entry, $relative . ': the Cloud entry does not say when the announcements fetch fires');
        }
    }

    /**
     * Issue #187: a link labelled as terms must point at a terms page. The
     * label family is Terms, Terms of use, Terms of service and the combined
     * "Terms and privacy policy"; the URL fails when its last path segment
     * names a privacy page, or, for the combined label, when it is a bare
     * home page standing in for both documents.
     */
    public function test_no_terms_label_points_at_a_privacy_page(): void
    {
        foreach (self::SHIPPED_READMES as $relative) {
            $section = preg_replace('/\s+/', ' ', $this->external_services($this->readme($relative)));
            preg_match_all('/\b(Terms(?: of (?:use|service))?( and privacy policy)?): (https?:\/\/\S+)/i', $section, $matches, PREG_SET_ORDER);
            foreach ($matches as [, $label, $combined, $url]) {
                $path     = (string) parse_url(rtrim($url, '.,;)'), PHP_URL_PATH);
                $segments = array_values(array_filter(explode('/', $path), 'strlen'));
                $last     = (string) end($segments);
                $this->assertDoesNotMatchRegularExpression(
                    '/privacy/i',
                    $last,
                    $relative . ' labels a privacy page as "' . $label . '": ' . $url
                );
                if ('' !== $combined) {
                    $this->assertNotSame('', $last, $relative . ' points "' . $label . '" at a home page: ' . $url);
                }
            }
        }
    }

    /**
     * Guard the guard: the host list above is only worth anything while the
     * files that reach those hosts are still in the shipped tree.
     */
    public function test_the_disclosed_call_sites_still_exist_in_the_source_tree(): void
    {
        foreach (
            [
                'src/Tools/Security/Software_Audit.php',
                'src/Tools/Security/Vulnerability_Lookup.php',
                'src/Tools/Packages/Install_Plugin.php',
                'src/Tools/Media/Sideload_Image.php',
                'src/Tools/Media/Upload_Svg.php',
                'src/Tools/Security/Hardening_Audit.php',
                'src/Auth/Oauth_Gc.php',
            ] as $relative
        ) {
            $this->assertFileExists($this->repository() . '/' . $relative);
        }
    }

    /**
     * Issue #413: the vulnerability lookup is opt-in and sends only slugs and
     * versions, and every shipped readme has to say both.
     */
    public function test_the_vulnerability_entry_says_it_is_opt_in_and_what_it_sends(): void
    {
        foreach (self::SHIPPED_READMES as $relative) {
            $entry = preg_replace('/\s+/', ' ', $this->entry($this->external_services($this->readme($relative)), 'www.wpvulnerability.net'));
            $this->assertStringContainsString('vulnerabilities', $entry, $relative . ': the entry does not name the vulnerabilities option');
            $this->assertMatchesRegularExpression('/\bslug/i', $entry, $relative . ': the entry does not say slugs are sent');
            $this->assertMatchesRegularExpression('/\bno (API )?key\b/i', $entry, $relative . ': the entry does not say no key is needed');
            $this->assertMatchesRegularExpression('/cached/i', $entry, $relative . ': the entry does not say answers are cached');
        }
    }
}
