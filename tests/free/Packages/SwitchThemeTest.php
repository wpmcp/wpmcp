<?php

namespace WPMCP\Tests\Free\Packages;

use WPMCP\Tools\Packages\Switch_Theme;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;

class SwitchThemeTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Snapshot_Store::install();
    }

    public function test_requires_stylesheet_argument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Switch_Theme())->handle([]);
    }

    public function test_unknown_theme_errors(): void
    {
        $this->expectException(\RuntimeException::class);
        (new Switch_Theme())->handle(['stylesheet' => 'ghost-theme']);
    }

    public function test_switches_theme_and_is_snapshotted(): void
    {
        $themes = wp_get_themes();
        $target = null;
        foreach (array_keys($themes) as $slug) {
            if ($slug !== get_stylesheet()) {
                $target = $slug;
                break;
            }
        }
        if (null === $target) {
            $this->markTestSkipped('Only one theme installed in this environment.');
        }
        // On a network only an enabled theme may be activated (#529).
        add_filter('allowed_themes', static fn ($allowed) => array_merge((array) $allowed, [$target => true]));

        $original_stylesheet = get_option('stylesheet');
        $original_template   = get_option('template');

        $out = (new Switch_Theme())->handle(['stylesheet' => $target]);

        $this->assertSame($target, get_stylesheet());
        $this->assertArrayHasKey('operation_ids', $out);
        $this->assertNotEmpty($out['operation_ids']);

        foreach ($out['operation_ids'] as $operation_id) {
            $this->assertNotNull(Snapshot_Store::get_by_operation($operation_id));
        }

        foreach ($out['operation_ids'] as $operation_id) {
            $restored = (new Rollback_Operation())->handle(['operation_id' => $operation_id]);
            $this->assertTrue($restored['restored']);
        }

        $this->assertSame($original_stylesheet, get_option('stylesheet'));
        $this->assertSame($original_template, get_option('template'));
    }
}
