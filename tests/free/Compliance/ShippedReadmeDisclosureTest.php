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
     * Guard the guard: the host list above is only worth anything while the
     * files that reach those hosts are still in the shipped tree.
     */
    public function test_the_disclosed_call_sites_still_exist_in_the_source_tree(): void
    {
        foreach (
            [
                'src/Tools/Security/Software_Audit.php',
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
}
