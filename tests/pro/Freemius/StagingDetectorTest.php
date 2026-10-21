<?php

namespace WPMCP\Tests\Pro\Freemius;

use WPMCP\Freemius\Staging_Detector;

/**
 * The staging rule: a whole host label or path segment that reads "staging"
 * or "stage" (optionally numbered), in either WordPress address setting.
 */
class StagingDetectorTest extends \WP_UnitTestCase
{
    /**
     * @return array<string, array{0:string}>
     */
    public static function staging_urls(): array
    {
        return [
            'staging subdomain'         => ['https://staging.example.com'],
            'stage subdomain'           => ['https://stage.example.com'],
            'nested staging subdomain'  => ['https://www.staging.example.com/'],
            'uppercase host'            => ['https://STAGING.Example.COM'],
            'host with port'            => ['http://staging.example.com:8080'],
            'numbered with hyphen'      => ['https://staging-2.example.com'],
            'numbered without hyphen'   => ['https://staging2.example.com'],
            'numbered stage'            => ['https://stage-3.example.com'],
            'staging path segment'      => ['https://example.com/staging'],
            'staging path with id'      => ['https://example.com/staging/7380/'],
            'stage path segment'        => ['https://example.com/stage/'],
            'uppercase path segment'    => ['https://example.com/Staging/'],
            'deep path segment'         => ['https://example.com/sites/staging/wp'],
            'numbered path segment'     => ['https://example.com/staging_1/'],
        ];
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function non_staging_urls(): array
    {
        return [
            'plain site'                     => ['https://example.com'],
            'label containing stage'         => ['https://backstage.com'],
            'subdomain of backstage'         => ['https://www.backstage.com'],
            'label starting with stage'      => ['https://stagecoach.example.com'],
            'label ending with staging'      => ['https://mystaging.example.com'],
            'hyphenated word after staging'  => ['https://staging-old.example.com'],
            'segment starting with stage'    => ['https://example.com/stagecoach/'],
            'segment containing stage'       => ['https://example.com/backstage/'],
            'registrable domain is stage'    => ['https://stage.com'],
            'registrable domain is staging'  => ['https://www.staging.com'],
            'query string only'              => ['https://example.com/?env=staging'],
            'fragment only'                  => ['https://example.com/#staging'],
            'userinfo is not the host'       => ['https://staging@example.com/'],
            'single label host'              => ['http://localhost'],
            'empty'                          => [''],
            'garbage'                        => ['not a url'],
        ];
    }

    /**
     * @dataProvider staging_urls
     */
    public function test_staging_urls_are_detected(string $url): void
    {
        $this->assertTrue(Staging_Detector::is_staging_url($url), $url);
    }

    /**
     * @dataProvider non_staging_urls
     */
    public function test_non_staging_urls_are_not_detected(string $url): void
    {
        $this->assertFalse(Staging_Detector::is_staging_url($url), $url);
    }

    public function test_either_address_setting_is_enough(): void
    {
        $this->assertTrue(Staging_Detector::is_staging('https://staging.example.com', 'https://example.com'));
        $this->assertTrue(Staging_Detector::is_staging('https://example.com', 'https://example.com/staging/7380'));
    }

    public function test_neither_address_setting_staging_is_not_staging(): void
    {
        $this->assertFalse(Staging_Detector::is_staging('https://example.com', 'https://example.com/wp'));
        $this->assertFalse(Staging_Detector::is_staging('', ''));
    }
}
