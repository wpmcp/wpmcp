<?php

namespace WPMCP\Tests\Free\MCP;

use WPMCP\MCP\Handshake_Instructions;

/**
 * The wiring half of issue #80: wpmcp hooks the MCP Adapter's documented
 * mcp_adapter_initialize_response filter and swaps the initialize result's
 * `instructions` for Handshake_Instructions::build(). The adapter is not
 * installable in this harness (its InitializeResult DTO ships with the
 * mcp-adapter plugin), so the filter callback is duck-typed against the
 * DTO's documented toArray()/fromArray() contract and exercised here with a
 * stand-in implementing that exact contract.
 */
class HandshakeInitializeFilterTest extends \WP_UnitTestCase
{
    public static function wpSetUpBeforeClass(): void
    {
        if (0 === did_action('wp_abilities_api_init')) {
            do_action('wp_abilities_api_init');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(Handshake_Instructions::OPTION);
        $editor = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($editor);
    }

    protected function tearDown(): void
    {
        delete_option(Handshake_Instructions::OPTION);
        parent::tearDown();
    }

    public function test_the_initialize_response_filter_is_hooked_at_boot(): void
    {
        $this->assertNotFalse(
            has_filter('mcp_adapter_initialize_response'),
            'Plugin::boot() must hook mcp_adapter_initialize_response so every initialize handshake carries the instructions.'
        );
    }

    public function test_filter_replaces_instructions_and_preserves_the_rest_of_the_result(): void
    {
        update_option(Handshake_Instructions::OPTION, 'Save drafts only.');

        $result = Fake_Initialize_Result::fromArray([
            'protocolVersion' => '2025-06-18',
            'capabilities'    => ['tools' => ['listChanged' => false]],
            'serverInfo'      => ['name' => 'wpmcp-server', 'version' => '1.0'],
            'instructions'    => 'Adapter default description.',
        ]);

        $filtered = (new Handshake_Instructions())->filter_initialize($result, null);

        $this->assertInstanceOf(Fake_Initialize_Result::class, $filtered);

        $data = $filtered->toArray();
        $this->assertSame((new Handshake_Instructions())->build(), $data['instructions']);
        $this->assertStringContainsString('Save drafts only.', $data['instructions']);
        $this->assertSame('2025-06-18', $data['protocolVersion']);
        $this->assertSame(['name' => 'wpmcp-server', 'version' => '1.0'], $data['serverInfo']);
    }

    public function test_filtered_instructions_come_from_the_applied_filter_end_to_end(): void
    {
        $result = Fake_Initialize_Result::fromArray(['instructions' => 'stock']);

        $filtered = apply_filters('mcp_adapter_initialize_response', $result, null);

        $this->assertSame((new Handshake_Instructions())->build(), $filtered->toArray()['instructions']);
    }

    public function test_a_value_without_the_dto_contract_passes_through_unchanged(): void
    {
        $plain_array = ['instructions' => 'untouched'];
        $this->assertSame($plain_array, (new Handshake_Instructions())->filter_initialize($plain_array, null));

        $foreign = new \stdClass();
        $this->assertSame($foreign, (new Handshake_Instructions())->filter_initialize($foreign, null));
    }

    /**
     * Adapter 0.7.0 (issue #386) passes an immutable schema record plus the
     * selected schema as a third argument, and documents the round trip as
     * jsonSerialize() then $schema->fromArray(). Without handling it the
     * 0.7.0 handshake silently loses the instructions.
     */
    public function test_the_filter_accepts_the_selected_schema_argument(): void
    {
        global $wp_filter;

        $accepted = null;
        foreach ($wp_filter['mcp_adapter_initialize_response']->callbacks as $callbacks) {
            foreach ($callbacks as $callback) {
                if (is_array($callback['function']) && $callback['function'][0] instanceof Handshake_Instructions) {
                    $accepted = $callback['accepted_args'];
                }
            }
        }

        $this->assertSame(3, $accepted);
    }

    public function test_filter_rebuilds_a_schema_record_through_the_selected_schema(): void
    {
        update_option(Handshake_Instructions::OPTION, 'Save drafts only.');

        $record = new Fake_Schema_Record([
            'protocolVersion' => '2025-11-25',
            'capabilities'    => ['tools' => ['listChanged' => false]],
            'serverInfo'      => ['name' => 'wpmcp', 'version' => '1.0'],
            'instructions'    => 'Adapter default description.',
        ]);
        $schema = new Fake_Schema();

        $filtered = (new Handshake_Instructions())->filter_initialize($record, null, $schema);

        $this->assertInstanceOf(Fake_Schema_Record::class, $filtered);
        $this->assertSame(Fake_Schema_Record::class, $schema->built_class);
        $data = json_decode((string) wp_json_encode($filtered->jsonSerialize()), true);
        $this->assertSame((new Handshake_Instructions())->build(), $data['instructions']);
        $this->assertStringContainsString('Save drafts only.', $data['instructions']);
        $this->assertSame('2025-11-25', $data['protocolVersion']);
        $this->assertSame(['name' => 'wpmcp', 'version' => '1.0'], $data['serverInfo']);
    }

    /** A record whose schema cannot rebuild it is returned untouched, never fatal. */
    public function test_a_record_without_a_usable_schema_passes_through(): void
    {
        $record = new Fake_Schema_Record(['instructions' => 'stock']);

        $this->assertSame($record, (new Handshake_Instructions())->filter_initialize($record, null));
        $this->assertSame($record, (new Handshake_Instructions())->filter_initialize($record, null, new \stdClass()));
        $this->assertSame($record, (new Handshake_Instructions())->filter_initialize($record, null, new Fake_Schema(true)));
    }
}

/**
 * Stand-in for a \WP\McpSchema\Record as adapter 0.7.0 hands it over:
 * immutable, serializes to a stdClass, built only through a Schema.
 */
class Fake_Schema_Record implements \JsonSerializable
{
    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function jsonSerialize(): \stdClass
    {
        return json_decode((string) wp_json_encode($this->data));
    }
}

/** Stand-in for \WP\McpSchema\Schema::fromArray(). */
class Fake_Schema
{
    public ?string $built_class = null;

    public function __construct(private bool $throws = false)
    {
    }

    public function fromArray(string $class, array $data): object
    {
        if ($this->throws) {
            throw new \InvalidArgumentException('invalid record');
        }
        $this->built_class = $class;
        return new $class($data);
    }
}

/**
 * Stand-in for \WP\McpSchema\Common\Protocol\DTO\InitializeResult, matching
 * the toArray()/fromArray() round-trip contract the adapter's own filter
 * docblock instructs integrators to use.
 */
class Fake_Initialize_Result
{
    private array $data;

    private function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
