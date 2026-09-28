<?php

namespace WPMCP\Tests\Free\Safety;

use WPMCP\Safety\Rollback_Service;
use WPMCP\Safety\Snapshot;

/**
 * The 'yoast_term_seo' snapshot type (issue #67): one term's row inside
 * Yoast's shared wpseo_taxonomy_meta option, captured and restored without
 * touching any other term's row.
 */
class YoastTermSeoSnapshotTest extends \WP_UnitTestCase
{
    protected function tearDown(): void
    {
        delete_option(Snapshot::YOAST_TAXONOMY_META_OPTION);
        parent::tearDown();
    }

    public function test_restore_puts_back_only_the_captured_row(): void
    {
        $a = self::factory()->category->create();
        $b = self::factory()->category->create();

        update_option(Snapshot::YOAST_TAXONOMY_META_OPTION, [
            'category' => [
                $a => ['wpseo_title' => 'A before'],
                $b => ['wpseo_title' => 'B before'],
            ],
        ]);

        $snapshot = Snapshot::capture('yoast_term_seo', 'category:' . $a);
        $this->assertTrue($snapshot['data']['existed']);
        $this->assertSame(['wpseo_title' => 'A before'], $snapshot['data']['row']);

        $option                   = get_option(Snapshot::YOAST_TAXONOMY_META_OPTION);
        $option['category'][$a]   = ['wpseo_title' => 'A after'];
        $option['category'][$b]   = ['wpseo_title' => 'B later'];
        update_option(Snapshot::YOAST_TAXONOMY_META_OPTION, $option);

        Rollback_Service::apply_snapshot($snapshot);

        $restored = get_option(Snapshot::YOAST_TAXONOMY_META_OPTION);
        $this->assertSame('A before', $restored['category'][$a]['wpseo_title']);
        $this->assertSame('B later', $restored['category'][$b]['wpseo_title']);
    }

    public function test_restore_removes_a_row_that_did_not_exist(): void
    {
        $a = self::factory()->category->create();

        $snapshot = Snapshot::capture('yoast_term_seo', 'category:' . $a);
        $this->assertFalse($snapshot['data']['existed']);

        update_option(Snapshot::YOAST_TAXONOMY_META_OPTION, ['category' => [$a => ['wpseo_title' => 'New']]]);

        Rollback_Service::apply_snapshot($snapshot);

        $restored = get_option(Snapshot::YOAST_TAXONOMY_META_OPTION, []);
        $this->assertFalse(isset($restored['category'][$a]));
    }
}
