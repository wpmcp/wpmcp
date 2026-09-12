<?php

namespace WPMCP\Tests\Pro\Compose;

use WPMCP\Tools\Compose\Page_Spec;

/**
 * Structural validation of the BUILDER (Elementor) dialect of the build-page
 * spec (issue #57).
 *
 * These live under tests/pro because the dialect is full-build surface: the
 * wp.org directory cut removes it outright (issue #162), so a suite named
 * "free" asserting that Page_Spec accepts `dialect => 'elementor'` would be
 * describing an artifact the directory never receives. The complementary
 * assertions on the stripped build are in
 * tests/free/Flavors/WporgStripBuildPageTest.php.
 *
 * Validation itself is pure and dialect-agnostic about licensing: there is no
 * Gate call in Page_Spec, so nothing here needs a pro fixture. What makes
 * these pro-tier is which build ships the code under test.
 */
class PageSpecBuilderTest extends \WP_UnitTestCase
{
    public function test_elementor_dialect_validates_builder_nodes(): void
    {
        $spec = Page_Spec::validate([
            'title'   => 'Builder Page',
            'dialect' => 'elementor',
            'content' => [
                ['type' => 'container', 'settings' => ['flex_direction' => 'row'], 'children' => [
                    ['type' => 'container', 'children' => [
                        ['type' => 'widget', 'settings' => ['widget' => 'heading', 'widget_settings' => ['title' => 'Hi']]],
                    ]],
                ]],
            ],
        ]);

        $this->assertSame('elementor', $spec['dialect']);
    }

    public function test_elementor_dialect_rejects_gutenberg_nodes_with_path(): void
    {
        $this->assertRejected(
            [
                'title'   => 'Builder Page',
                'dialect' => 'elementor',
                'content' => [
                    ['type' => 'container', 'children' => [
                        ['type' => 'paragraph', 'settings' => ['text' => 'x']],
                    ]],
                ],
            ],
            'content[0].children[0]'
        );
    }

    public function test_elementor_widget_requires_widget_name(): void
    {
        $this->assertRejected(
            [
                'title'   => 'Builder Page',
                'dialect' => 'elementor',
                'content' => [['type' => 'widget', 'settings' => []]],
            ],
            'widget'
        );
    }

    private function assertRejected(array $spec, string $message_fragment): void
    {
        try {
            Page_Spec::validate($spec);
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString(
                $message_fragment,
                $e->getMessage(),
                'Rejection reason should mention: ' . $message_fragment
            );
            return;
        }
        $this->fail('Expected the spec to be rejected (' . $message_fragment . ').');
    }
}
