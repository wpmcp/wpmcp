<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\MCP\Ability;
use WPMCP\MCP\Tool_Exposure;
use WPMCP\Plugin;

/**
 * The checked-in tools/list byte budget (issue #79).
 *
 * Approximates the adapter's tools/list serialization (name, description,
 * inputSchema, annotations per tool — the token-relevant payload) for both
 * exposure modes and pins byte ceilings. The compact ceiling is absolute:
 * the core surface is static, so it must stay small no matter how many
 * long-tail tools the plugin grows. The full-mode assertion pins the
 * RATIO, which is what compact mode exists to cut; the measured numbers
 * are printed so each release can document them.
 */
class ToolsListBudgetTest extends \WP_UnitTestCase
{
    /** Absolute byte ceiling for the compact-mode tools/list payload. */
    private const COMPACT_BUDGET_BYTES = 12000;

    /** Compact must be at most this fraction of the full payload. */
    private const MAX_COMPACT_TO_FULL_RATIO = 0.10;

    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function tearDown(): void
    {
        delete_option(Tool_Exposure::OPTION);
        parent::tearDown();
    }

    /** Serialize one ability the way the adapter's Tool DTO reaches the wire. */
    private function tool_entry(Ability $a): array
    {
        return [
            'name'        => str_replace('/', '-', $a->name),
            'description' => $a->description,
            'inputSchema' => empty($a->input_schema) ? new \stdClass() : $a->input_schema,
            'annotations' => [
                'readOnlyHint'    => $a->read_only_hint,
                'destructiveHint' => $a->destructive_hint,
                'idempotentHint'  => $a->idempotent_hint,
            ],
        ];
    }

    private function payload_bytes(string $mode): int
    {
        update_option(Tool_Exposure::OPTION, $mode);

        $exposure = new Tool_Exposure();
        $tools    = array_map([$this, 'tool_entry'], Plugin::instance()->registrar()->all());
        $tools    = $exposure->filter_tools_list($tools);

        return strlen((string) wp_json_encode(['tools' => array_values($tools)]));
    }

    public function test_compact_and_full_payload_sizes_stay_within_the_checked_in_budget(): void
    {
        $full    = $this->payload_bytes(Tool_Exposure::MODE_FULL);
        $compact = $this->payload_bytes(Tool_Exposure::MODE_COMPACT);
        $count   = count(Plugin::instance()->registrar()->all());

        // The measured numbers, surfaced in the test output so each release
        // can copy them into the release notes / README.
        fwrite(STDOUT, sprintf(
            "\n[tools/list budget] full: %d bytes (%d tools), compact: %d bytes, reduction: %.1f%%\n",
            $full,
            $count,
            $compact,
            100 * (1 - $compact / $full)
        ));

        $this->assertLessThanOrEqual(
            self::COMPACT_BUDGET_BYTES,
            $compact,
            'Compact tools/list payload blew its byte budget; the curated core surface must stay small.'
        );

        $this->assertLessThanOrEqual(
            self::MAX_COMPACT_TO_FULL_RATIO,
            $compact / $full,
            'Compact mode must cut the tools/list payload to a small fraction of the full surface.'
        );
    }

    public function test_full_mode_payload_actually_contains_the_whole_surface(): void
    {
        $full_bytes = $this->payload_bytes(Tool_Exposure::MODE_FULL);
        $tool_count = count(Plugin::instance()->registrar()->all());

        $this->assertGreaterThan(100, $tool_count, 'Sanity: the full surface is the 100+ tool problem this feature solves.');
        $this->assertGreaterThan(self::COMPACT_BUDGET_BYTES, $full_bytes);
    }
}
