<?php

namespace WPMCP\Tests\Free\Platform;

/**
 * Token-cost guard for the advertised tool surface (issue #59).
 *
 * The whole point of serving the widget catalog as DATA behind a handful of
 * generic abilities is that adding widgets must not grow the tools/list
 * payload every MCP client pays for on connect. This test renders the
 * tools/list-shaped payload (name, description, inputSchema, annotations)
 * for every registered ability and pins its JSON size against a checked-in
 * byte budget, so any change that bloats the advertised surface — a
 * per-widget tool, a runaway description — fails CI with a number attached.
 *
 * The budget is a ceiling, not a target: raise it deliberately (with review)
 * when the surface legitimately grows, exactly like the ability manifest.
 */
class ToolsListBudgetTest extends \WP_UnitTestCase
{
    /** Max JSON bytes for the full tools/list payload of every registered ability. */
    private const TOOLS_LIST_BYTE_BUDGET = 96000;

    /** @return array<int, array<string, mixed>> tools/list-shaped entries. */
    private static function payload(): array
    {
        $tools = [];
        foreach (RegisteredAbilities::all() as $ability) {
            $tools[] = [
                'name'        => $ability->name,
                'description' => $ability->description,
                'inputSchema' => $ability->input_schema,
                'annotations' => [
                    'readOnlyHint'    => $ability->read_only_hint,
                    'destructiveHint' => $ability->destructive_hint,
                    'idempotentHint'  => $ability->idempotent_hint,
                ],
            ];
        }
        return $tools;
    }

    public function test_tools_list_payload_stays_within_byte_budget(): void
    {
        $payload = self::payload();
        $bytes   = strlen((string) wp_json_encode($payload));

        $this->assertGreaterThan(0, $bytes);
        $this->assertLessThanOrEqual(
            self::TOOLS_LIST_BYTE_BUDGET,
            $bytes,
            sprintf(
                'tools/list payload is %d bytes for %d tools, over the %d-byte budget. '
                . 'Trim descriptions/schemas, or raise the budget deliberately in review.',
                $bytes,
                count($payload),
                self::TOOLS_LIST_BYTE_BUDGET
            )
        );
    }

    public function test_widget_catalog_growth_cannot_grow_the_tool_surface(): void
    {
        // The catalog is consumed by a fixed set of generic abilities; the
        // number of registered elementor-domain tools must not scale with the
        // number of cataloged widgets.
        $elementor = array_filter(
            RegisteredAbilities::all(),
            static fn ($ability) => 'elementor' === $ability->domain
        );

        $this->assertLessThanOrEqual(
            25,
            count($elementor),
            'The Elementor tool surface must stay a small, fixed set of generic tools; '
            . 'widgets belong in the catalog data, not in new per-widget abilities.'
        );
    }
}
