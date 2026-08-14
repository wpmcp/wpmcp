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
    /** Max JSON bytes for the full tools/list payload of every registered ability.
     *  Raised 100000 -> 110000 in review for the forms integration cluster
     *  (Gravity Forms, Formidable, Contact Form 7, WPForms); raised 110000 ->
     *  135000 in review for the EMCP Elementor parity expansion (global Kit,
     *  templates, theme builder, atomic elements, popups, dynamic tags);
     *  raised 135000 -> 140000 in review for the forms breadth cluster
     *  (Forminator, SureForms, MetForm), which put the payload at 135470
     *  bytes over 266 tools; raised 140000 -> 150000 in review for the
     *  accessibility and SEO auto-fixers (fix-color-contrast,
     *  add-alt-text-from-context, fix-link-text), which puts the payload at
     *  139656 bytes over 273 tools; raised 150000 -> 155000 in review for the
     *  Elementor v4 global class write suite
     *  (create/update/delete/reorder-global-class), whose two authoring tools
     *  advertise the full friendly style-key list so an agent can style a
     *  class without a second schema round trip, which puts the payload at
     *  148066 bytes over 281 tools; raised 155000 -> 160000 in review for the
     *  agent project memory tools (memory-recall, memory-propose,
     *  memory-save-summary), which puts the payload at 156353 bytes over 293
     *  tools; raised 160000 -> 165000 in review for the foundation parity
     *  cluster (taxonomy term CRUD plus duplicate-post, diff-revisions and
     *  count-content), which puts the payload at 161467 bytes over 302 tools.
     *  That last raise was taken only after trimming the new descriptions:
     *  they still carry the refusal rules (duplicate slug, parent cycle,
     *  default term) because an agent that learns those from the description
     *  avoids a failed call, which costs more than the bytes do.
     *  Compact tool mode keeps clients with tool caps at ~2.8KB regardless. */
    private const TOOLS_LIST_BYTE_BUDGET = 165000;

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

        // Ceiling raised from 25 -> 60 in review for the EMCP Elementor parity
        // expansion (global Kit, templates, theme builder, atomic elements,
        // popups, dynamic tags). The invariant this protects is unchanged: the
        // 44-widget catalog is consumed by a FIXED generic set, so adding a
        // cataloged widget must never add a tool. New tools here are per-feature,
        // never per-widget, and stay well under the catalog size.
        $this->assertLessThanOrEqual(
            60,
            count($elementor),
            'The Elementor tool surface must stay a fixed set of generic, per-feature tools; '
            . 'widgets belong in the catalog data, not in new per-widget abilities.'
        );
    }
}
