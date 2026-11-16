<?php

namespace WPMCP\Tests\Free\Packages;

use WPMCP\Tools\Packages\Switch_Theme;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Rollback_Operation;
use WPMCP\Tools\Rollback_Session;
use WPMCP\Safety\Rollback_Service;

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

        // One switch is one undo (issue #869): either id puts both options back.
        $restored = (new Rollback_Operation())->handle(['operation_id' => $out['operation_ids'][0]]);
        $this->assertTrue($restored['restored']);

        $this->assertSame($original_stylesheet, get_option('stylesheet'));
        $this->assertSame($original_template, get_option('template'));
    }

    /** @var string[] Theme directories this test created. */
    private array $made = [];

    protected function tearDown(): void
    {
        foreach ($this->made as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
        $this->made = [];
        wp_clean_themes_cache();
        parent::tearDown();
    }

    private function make_theme(string $slug, string $parent = ''): void
    {
        $dir = get_theme_root() . '/' . $slug;
        mkdir($dir);
        $header = "/*\nTheme Name: {$slug}\n" . ('' !== $parent ? "Template: {$parent}\n" : '') . "*/\n";
        file_put_contents($dir . '/style.css', $header);
        file_put_contents($dir . '/index.php', "<?php\n");
        $this->made[] = $dir;
        wp_clean_themes_cache();
    }

    /** Starts on a standalone theme "wpmcp869-base", then switches to the child "wpmcp869-child" of "wpmcp869-parent". */
    private function switch_to_child(): array
    {
        $this->make_theme('wpmcp869-base');
        $this->make_theme('wpmcp869-parent');
        $this->make_theme('wpmcp869-child', 'wpmcp869-parent');
        add_filter('allowed_themes', static fn ($allowed) => array_merge((array) $allowed, ['wpmcp869-base' => true, 'wpmcp869-parent' => true, 'wpmcp869-child' => true]));
        switch_theme('wpmcp869-base');

        $out = (new Switch_Theme())->handle(['stylesheet' => 'wpmcp869-child', 'session_id' => 's869']);
        $this->assertSame('wpmcp869-parent', get_option('template'));
        $this->assertSame('wpmcp869-child', get_option('stylesheet'));
        return $out['operation_ids'];
    }

    private function assert_back_on_base(): void
    {
        $this->assertSame('wpmcp869-base', get_option('template'));
        $this->assertSame('wpmcp869-base', get_option('stylesheet'));
        $this->assertTrue(wp_get_theme(get_option('stylesheet'))->exists());
    }

    public function test_undoing_the_first_operation_alone_restores_both_options(): void
    {
        $ids = $this->switch_to_child();
        $this->assertTrue((new Rollback_Operation())->handle(['operation_id' => $ids[0]])['restored']);
        $this->assert_back_on_base();
    }

    public function test_undoing_the_second_operation_alone_restores_both_options(): void
    {
        $ids = $this->switch_to_child();
        $this->assertTrue((new Rollback_Operation())->handle(['operation_id' => $ids[1]])['restored']);
        $this->assert_back_on_base();
    }

    public function test_the_partner_is_recorded_as_undone_and_not_applied_again(): void
    {
        $ids = $this->switch_to_child();
        (new Rollback_Operation())->handle(['operation_id' => $ids[1]]);
        switch_theme('wpmcp869-parent');

        $this->assertFalse(Rollback_Service::restore_operation($ids[0]));
        $this->assertSame('wpmcp869-parent', get_option('stylesheet'), 'A second undo must not overwrite newer work.');
    }

    public function test_rollback_session_still_undoes_the_whole_switch(): void
    {
        $this->switch_to_child();
        (new Rollback_Session())->handle(['session_id' => 's869']);
        $this->assert_back_on_base();
    }

    public function test_an_unlinked_undo_that_would_break_the_pair_is_refused(): void
    {
        $ids = $this->switch_to_child();
        // An undo point written before the link existed: strip it.
        foreach ($ids as $id) {
            $row = Snapshot_Store::get_by_operation($id);
            unset($row['snapshot']['data']['theme_switch_partner']);
            Snapshot_Store::replace_snapshot((int) $row['id'], $row['snapshot']);
        }

        $this->assertFalse(Rollback_Service::restore_operation($ids[1]));
        $this->assertSame('wpmcp869-parent', get_option('template'));
        $this->assertSame('wpmcp869-child', get_option('stylesheet'));
        $this->assertStringContainsString('breaks the whole site', implode(' ', Rollback_Service::take_warnings()));
    }

    public function test_an_undo_to_a_theme_that_was_deleted_is_refused(): void
    {
        $ids = $this->switch_to_child();
        $base = get_theme_root() . '/wpmcp869-base';
        rename($base, $base . '-gone');
        $this->made[] = $base . '-gone';
        clearstatcache(true);
        wp_cache_flush();
        wp_clean_themes_cache(true);

        $this->assertFalse(Rollback_Service::restore_operation($ids[0]));
        $this->assertSame('wpmcp869-child', get_option('stylesheet'));
        $this->assertStringContainsString('not installed any more', implode(' ', Rollback_Service::take_warnings()));
    }

    private function remove_base_theme(): void
    {
        $base = get_theme_root() . '/wpmcp869-base';
        rename($base, $base . '-gone');
        $this->made[] = $base . '-gone';
        clearstatcache(true);
        wp_cache_flush();
        wp_clean_themes_cache(true);
    }

    public function test_a_failed_partner_claim_refuses_and_writes_nothing(): void
    {
        global $wpdb;
        $ids     = $this->switch_to_child();
        $partner = Snapshot_Store::get_by_operation($ids[0]);
        $fired   = false;
        // Another request takes the partner between the lookup and the claim.
        add_filter('query', static function ($sql) use (&$fired, $wpdb, $partner) {
            if (! $fired && false !== strpos($sql, 'SET rolled_back_at') && false !== strpos($sql, 'WHERE id IN')) {
                $fired = true;
                $wpdb->query($wpdb->prepare('UPDATE %i SET rolled_back_at = %s WHERE id = %d', Snapshot_Store::table_name(), '2000-01-01 00:00:00', (int) $partner['id']));
            }
            return $sql;
        });

        $this->assertFalse(Rollback_Service::restore_operation($ids[1]));
        remove_all_filters('query');
        $this->assertSame('wpmcp869-parent', get_option('template'));
        $this->assertSame('wpmcp869-child', get_option('stylesheet'));
        $this->assertStringContainsString('Nothing was changed', implode(' ', Rollback_Service::take_warnings()));
        $this->assertNull(Snapshot_Store::rolled_back_at(Snapshot_Store::get_by_operation($ids[1])), 'The claimed half is released again.');
    }

    public function test_a_throw_on_the_second_half_puts_the_first_back(): void
    {
        $ids   = $this->switch_to_child();
        $calls = 0;
        add_filter('wpmcp_rollback_option_snapshot', static function ($snapshot) use (&$calls) {
            if (2 === ++$calls) {
                throw new \RuntimeException('second half failed');
            }
            return $snapshot;
        });

        try {
            Rollback_Service::restore_operation($ids[0]);
            $this->fail('Expected the second half to throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('second half failed', $e->getMessage());
        }
        $this->assertSame('wpmcp869-parent', get_option('template'));
        $this->assertSame('wpmcp869-child', get_option('stylesheet'));
        $this->assertNull(Snapshot_Store::rolled_back_at(Snapshot_Store::get_by_operation($ids[0])));
        $this->assertNull(Snapshot_Store::rolled_back_at(Snapshot_Store::get_by_operation($ids[1])));
    }

    public function test_a_session_restore_that_would_break_the_pair_is_refused(): void
    {
        $this->switch_to_child();
        $this->remove_base_theme();

        try {
            Rollback_Service::restore_session('s869');
            $this->fail('Expected the session restore to be refused.');
        } catch (\WPMCP\Safety\Mutation_Failed $e) {
            $this->assertStringContainsString('not installed any more', $e->getMessage());
        }
        $this->assertSame('wpmcp869-parent', get_option('template'));
        $this->assertSame('wpmcp869-child', get_option('stylesheet'));
    }
}
