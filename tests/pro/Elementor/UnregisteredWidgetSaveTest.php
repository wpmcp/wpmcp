<?php

namespace WPMCP\Tests\Pro\Elementor;

use WPMCP\Tests\Support\ElementorConditional\Conditional_Widget;
use WPMCP\Tools\Elementor\Batch_Update;
use WPMCP\Tools\Rollback_Operation;

/**
 * Issue #392: Elementor's Document::save() silently drops any element whose
 * type is not registered in the current request. A widget type registered
 * conditionally (only on the requests that render it) is therefore lost on
 * every structural edit made from a request that does not load it. The
 * engine's verify step catches the loss, so the page is safe, but the edit
 * used to fail. The engine now retries through the raw meta path (with
 * cache invalidation) when the only difference is unregistered elements,
 * verifies again, and still restores the page if that retry fails too.
 */
class UnregisteredWidgetSaveTest extends Structural_Harness
{
    private const PROBE_ID = 'probe01';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../support/elementor-conditional-widget.php';
    }

    protected function tearDown(): void
    {
        $this->widgets()->unregister(Conditional_Widget::NAME);
        parent::tearDown();
    }

    private function widgets()
    {
        return \Elementor\Plugin::instance()->widgets_manager;
    }

    private function probe(): array
    {
        return [
            'id'         => self::PROBE_ID,
            'elType'     => 'widget',
            'settings'   => ['probe_label' => 'Checkout', 'custom_css' => '.x{color:red}'],
            'elements'   => [],
            'widgetType' => Conditional_Widget::NAME,
        ];
    }

    /**
     * A page built while the widget type was registered, then the type is
     * unregistered: the state of a request that does not load it.
     */
    private function make_page_with_conditional_widget(): int
    {
        $this->widgets()->register(new Conditional_Widget());
        $this->assertNotNull(
            $this->widgets()->get_widget_types(Conditional_Widget::NAME),
            'Precondition: the conditional widget type registers.'
        );

        $tree = $this->default_tree();
        $tree[0]['elements'][] = $this->probe();
        $post_id = $this->make_page($tree);

        $this->widgets()->unregister(Conditional_Widget::NAME);
        $this->assertNull(
            $this->widgets()->get_widget_types(Conditional_Widget::NAME),
            'Precondition: the widget type is not registered in this request.'
        );

        return $post_id;
    }

    private function edit_heading(int $post_id)
    {
        return (new Batch_Update())->handle([
            'post_id'       => $post_id,
            'expected_hash' => $this->data_hash($post_id),
            'updates'       => [
                ['element_id' => 'wid0001', 'settings' => ['title' => 'Edited']],
            ],
        ]);
    }

    public function test_edit_succeeds_on_a_page_with_an_unregistered_widget(): void
    {
        $post_id = $this->make_page_with_conditional_widget();

        $out = $this->edit_heading($post_id);

        $this->assertIsArray($out, 'The edit must succeed, not roll back: ' . (is_wp_error($out) ? $out->get_error_message() : ''));
        $heading = $this->find_in($this->tree($post_id), 'wid0001');
        $this->assertSame('Edited', $heading['settings']['title']);
        $this->assertSame($this->data_hash($post_id), $out['data_hash'], 'The returned hash must describe the stored tree.');
    }

    public function test_unregistered_widget_survives_the_edit_intact(): void
    {
        $post_id = $this->make_page_with_conditional_widget();
        update_post_meta($post_id, '_elementor_css', ['status' => 'stale-probe']);

        $out = $this->edit_heading($post_id);

        $this->assertIsArray($out);
        $tree = $this->tree($post_id);
        $this->assertSame($this->probe(), $this->find_in($tree, self::PROBE_ID), 'The unregistered widget must be stored exactly as it was.');
        $this->assertSame(
            ['cont001', 'wid0001', 'wid0002', self::PROBE_ID, 'cont002', 'wid0003'],
            $this->all_ids($tree),
            'Every element, including the unregistered one, must keep its place.'
        );
        $this->assertEmpty(
            get_post_meta($post_id, '_elementor_css', true),
            'The retried write must still invalidate the generated CSS.'
        );

        // The edit is still an ordinary undoable operation.
        $result = (new Rollback_Operation())->handle(['operation_id' => $out['operation_id']]);
        $this->assertTrue($result['restored']);
        $this->assertSame('Hello', $this->find_in($this->tree($post_id), 'wid0001')['settings']['title']);
        $this->assertSame($this->probe(), $this->find_in($this->tree($post_id), self::PROBE_ID));
    }

    public function test_failure_after_the_retry_still_restores_the_page(): void
    {
        $post_id = $this->make_page_with_conditional_widget();
        $before  = $this->raw($post_id);

        // Swallow the retry: the first _elementor_data write that still
        // carries the unregistered widget (the Document::save() write has
        // already dropped it) is reported as done but never stored. The
        // restore that follows must go through untouched.
        $armed  = true;
        $filter = function ($check, $object_id, $meta_key, $meta_value) use ($post_id, &$armed) {
            if ($armed && (int) $object_id === $post_id && '_elementor_data' === $meta_key
                && is_string($meta_value) && false !== strpos($meta_value, self::PROBE_ID)) {
                $armed = false;
                return true;
            }
            return $check;
        };
        add_filter('update_post_metadata', $filter, 10, 4);

        $out = $this->edit_heading($post_id);

        remove_filter('update_post_metadata', $filter, 10);

        $this->assertFalse($armed, 'The raw retry must have been attempted.');
        $this->assertInstanceOf(\WP_Error::class, $out);
        $this->assertSame('mutation_failed', $out->get_error_code());
        $this->assertSame($before, $this->raw($post_id), 'A failed retry must leave the page byte-identical.');
    }
}
