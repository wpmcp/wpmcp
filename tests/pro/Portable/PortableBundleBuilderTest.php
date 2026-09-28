<?php

namespace WPMCP\Tests\Pro\Portable;

use WPMCP\Pro\Gate;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\BlockBuilder\Block_Bundle_Kind;
use WPMCP\Tools\BlockBuilder\Block_Spec_Store;
use WPMCP\Tools\Code\Php_Snippet_Bundle_Kind;
use WPMCP\Tools\Code\Php_Snippet_Store;
use WPMCP\Tools\Portable\Bundle;
use WPMCP\Tools\Portable\Bundle_Kinds;
use WPMCP\Tools\Portable\Export_Bundle;
use WPMCP\Tools\Portable\Import_Bundle;
use WPMCP\Tools\Rollback_Session;
use WPMCP\Tools\WidgetBuilder\Widget_Bundle_Kind;
use WPMCP\Tools\WidgetBuilder\Widget_Spec_Store;

/**
 * Portable bundles (issue #297) across the custom block and widget builder
 * stores. Imported specs land as drafts, the stores' own "inactive", and a
 * rollback of the import session moves them to the trash: the import brought
 * them into existence, so undoing it takes them back out rather than leaving
 * inactive copies behind.
 */
class PortableBundleBuilderTest extends \WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        Snapshot_Store::install();
        delete_option(Php_Snippet_Store::OPTION_NAME);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        Gate::set_pro_for_tests(null);
        delete_option(Php_Snippet_Store::OPTION_NAME);
        parent::tearDown();
    }

    private function kinds(): array
    {
        return [new Php_Snippet_Bundle_Kind(), new Block_Bundle_Kind(), new Widget_Bundle_Kind()];
    }

    private function block_spec(string $name = 'callout'): array
    {
        return [
            'name'       => $name,
            'title'      => 'Callout',
            'attributes' => [
                ['name' => 'heading', 'type' => 'string', 'label' => 'Heading', 'default' => 'Note'],
            ],
            'template'   => '<div class="callout"><h4>{{heading}}</h4></div>',
        ];
    }

    private function widget_spec(string $name = 'promo-box'): array
    {
        return [
            'name'     => $name,
            'title'    => 'Promo Box',
            'controls' => [
                ['name' => 'heading', 'type' => 'text', 'label' => 'Heading', 'default' => 'Hi'],
            ],
            'template' => '<div class="promo"><h3>{{heading}}</h3></div>',
        ];
    }

    private function trash_all(string $post_type): void
    {
        foreach (get_posts(['post_type' => $post_type, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1]) as $id) {
            wp_delete_post((int) $id, true);
        }
    }

    public function test_round_trip_across_all_three_stores_lands_everything_inactive(): void
    {
        $block  = Block_Spec_Store::create($this->block_spec());
        $widget = Widget_Spec_Store::create($this->widget_spec());
        (new \WPMCP\Tools\Code\Create_Php_Snippet())->handle(['name' => 'hello', 'code' => '<?php return 1;']);
        $this->assertSame('publish', get_post_status($block));

        $export = (new Export_Bundle($this->kinds()))->handle([]);
        $this->assertSame(['snippet' => 1, 'block' => 1, 'widget' => 1], $export['counts']);

        $this->trash_all(Block_Spec_Store::POST_TYPE);
        $this->trash_all(Widget_Spec_Store::POST_TYPE);
        delete_option(Php_Snippet_Store::OPTION_NAME);

        $out = (new Import_Bundle($this->kinds()))->handle(['bundle' => $export['bundle']]);

        $this->assertCount(3, $out['imported']);
        $this->assertSame([], $out['skipped']);

        $blocks = Block_Spec_Store::all();
        $this->assertCount(1, $blocks);
        $this->assertSame('wpmcp/callout', $blocks[0]['name']);
        $this->assertSame('draft', $blocks[0]['status'], 'An imported block lands inactive');
        $this->assertSame($this->block_spec()['template'], Block_Spec_Store::get($blocks[0]['block_id'])['template']);

        $widgets = Widget_Spec_Store::all();
        $this->assertCount(1, $widgets);
        $this->assertSame('promo-box', $widgets[0]['name']);
        $this->assertSame('draft', $widgets[0]['status'], 'An imported widget lands inactive');

        foreach (Php_Snippet_Store::all() as $snippet) {
            $this->assertSame(Php_Snippet_Store::STATUS_INACTIVE, $snippet['status']);
        }
    }

    public function test_block_and_widget_items_reuse_the_cloud_asset_shape(): void
    {
        Block_Spec_Store::create($this->block_spec());

        $items = (new Export_Bundle($this->kinds()))->handle(['types' => ['block']])['bundle']['items'];

        $this->assertSame(['type', 'name', 'title', 'spec'], array_keys($items[0]));
        $this->assertSame('block', $items[0]['type']);
        $this->assertSame('wpmcp/callout', $items[0]['name']);
    }

    public function test_export_selects_builder_items_by_id(): void
    {
        $keep = Block_Spec_Store::create($this->block_spec('keep'));
        Block_Spec_Store::create($this->block_spec('drop'));

        $items = (new Export_Bundle($this->kinds()))->handle(['ids' => [$keep]])['bundle']['items'];

        $this->assertSame(['wpmcp/keep'], wp_list_pluck($items, 'name'));
    }

    public function test_an_invalid_spec_is_refused_by_its_own_store_validator(): void
    {
        $bad = $this->widget_spec('broken');
        unset($bad['controls']);

        $out = (new Import_Bundle($this->kinds()))->handle(['bundle' => Bundle::build([
            ['type' => 'widget', 'name' => 'broken', 'title' => 'Broken', 'spec' => $bad],
            ['type' => 'block', 'name' => 'nospec', 'title' => 'No spec'],
        ])]);

        $this->assertSame([], $out['imported']);
        $this->assertCount(2, $out['skipped']);
        $this->assertStringContainsString('control', $out['skipped'][0]['reason']);
        $this->assertSame([], Widget_Spec_Store::all());
    }

    public function test_builder_collisions_are_refused_or_renamed(): void
    {
        Block_Spec_Store::create($this->block_spec());
        Widget_Spec_Store::create($this->widget_spec());
        $bundle = (new Export_Bundle($this->kinds()))->handle(['types' => ['block', 'widget']])['bundle'];

        $refused = (new Import_Bundle($this->kinds()))->handle(['bundle' => $bundle]);
        $this->assertSame([], $refused['imported']);
        $this->assertCount(2, $refused['skipped']);

        $renamed = (new Import_Bundle($this->kinds()))->handle(['bundle' => $bundle, 'on_conflict' => 'rename']);
        $names   = wp_list_pluck($renamed['imported'], 'name');
        sort($names);
        $this->assertSame(['promo-box-2', 'wpmcp/callout-2'], $names);
        $this->assertNotNull(Block_Spec_Store::find_by_name('wpmcp/callout-2'));
        $this->assertNotNull(Widget_Spec_Store::find_by_name('promo-box-2'));
    }

    public function test_rollback_session_takes_the_imported_specs_back_out(): void
    {
        $existing = Block_Spec_Store::create($this->block_spec('existing'));
        $bundle   = Bundle::build([
            ['type' => 'block', 'name' => 'wpmcp/callout', 'title' => 'Callout', 'spec' => $this->block_spec()],
            ['type' => 'widget', 'name' => 'promo-box', 'title' => 'Promo Box', 'spec' => $this->widget_spec()],
            ['type' => 'snippet', 'name' => 'hello', 'code' => '<?php return 1;'],
        ]);

        $out = (new Import_Bundle($this->kinds()))->handle(['bundle' => $bundle]);
        $ids = [];
        foreach ($out['imported'] as $row) {
            $ids[ $row['type'] ] = $row['id'];
        }
        $this->assertCount(3, Snapshot_Store::list_by_session($out['session_id']));

        // Activating an imported spec afterwards does not keep it out of the undo.
        wp_update_post(['ID' => $ids['block'], 'post_status' => 'publish']);

        (new Rollback_Session())->handle(['session_id' => $out['session_id']]);

        $this->assertSame('trash', get_post_status($ids['block']));
        $this->assertSame('trash', get_post_status($ids['widget']));
        $this->assertSame([], Php_Snippet_Store::all());
        $this->assertSame('publish', get_post_status($existing), 'Only what the import created is touched');
    }

    public function test_builder_items_are_refused_without_the_pro_tier(): void
    {
        Gate::set_pro_for_tests(false);

        $out = (new Import_Bundle($this->kinds()))->handle(['bundle' => Bundle::build([
            ['type' => 'block', 'name' => 'wpmcp/callout', 'title' => 'Callout', 'spec' => $this->block_spec()],
            ['type' => 'snippet', 'name' => 'hello', 'code' => '<?php return 1;'],
        ])]);

        $this->assertSame(['snippet'], wp_list_pluck($out['imported'], 'type'));
        $this->assertStringContainsString('not available', $out['skipped'][0]['reason']);
        $this->assertSame([], Block_Spec_Store::all());

        $export = (new Export_Bundle($this->kinds()))->handle([]);
        $this->assertArrayNotHasKey('block', $export['counts']);
    }

    public function test_the_builder_kinds_join_the_registry_with_their_groups(): void
    {
        wp_get_abilities();
        $kinds = Bundle_Kinds::all();

        $this->assertInstanceOf(Block_Bundle_Kind::class, $kinds['block']);
        $this->assertInstanceOf(Widget_Bundle_Kind::class, $kinds['widget']);
    }
}
