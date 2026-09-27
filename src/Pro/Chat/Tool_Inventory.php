<?php

namespace WPMCP\Pro\Chat;

use WPMCP\MCP\Ability;
use WPMCP\MCP\Registrar;
use WPMCP\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The tool inventory the chat advertises to the model (issue #73).
 *
 * The inventory is derived, never listed: it is every ability in the live
 * Registrar that Registrar::would_permit() allows for the current user under
 * the chat identity, which is the same capability + tier + governance +
 * identity-scope + project-memory predicate execution enforces. Flipping any
 * governance toggle therefore changes what the model is told about and what
 * it can run in the same step, and the parity test in
 * tests/pro/Chat/ToolInventoryTest.php pins that the two sets are equal.
 *
 * Callers must already be inside Chat_Identity::run(); this class does not
 * set the identity itself so a caller cannot accidentally compute the
 * inventory under one identity and execute under another.
 *
 * Provider tool names cannot contain "/", so an ability name maps to a tool
 * name by replacing it with "__" (wpmcp/get-post -> wpmcp__get-post). The
 * reverse direction is a lookup in the advertised map, never string surgery,
 * so a model-supplied name can only ever resolve to an advertised ability.
 */
final class Tool_Inventory
{
    /** The meta-tool that loads full schemas for one or more domains. */
    public const LOAD_TOOLS = 'load_tools';

    private const TOOL_NAME_PATTERN = '/^[a-zA-Z0-9_-]{1,64}$/';

    public function __construct(private ?Registrar $registrar = null)
    {
    }

    private function registrar(): Registrar
    {
        if (null === $this->registrar) {
            // The Abilities registry initialises lazily: the first read fires
            // wp_abilities_api_init, which is what fills the Registrar on a
            // REST request that never touched an ability before.
            if (function_exists('wp_get_abilities')) {
                wp_get_abilities();
            }
            $this->registrar = Plugin::instance()->registrar();
        }
        return $this->registrar;
    }

    public static function tool_name(string $ability_name): string
    {
        return str_replace('/', '__', $ability_name);
    }

    /**
     * Every ability the current caller may run, keyed by provider tool name.
     *
     * @return array<string, Ability>
     */
    public function advertised(): array
    {
        $out = [];
        foreach ($this->registrar()->all() as $ability) {
            if (! $this->registrar()->would_permit($ability)) {
                continue;
            }
            if (function_exists('wp_get_ability') && null === wp_get_ability($ability->name)) {
                // Declared to the Registrar but not in the Abilities registry,
                // so not executable through the governed path at all.
                continue;
            }
            $tool = self::tool_name($ability->name);
            if (1 !== preg_match(self::TOOL_NAME_PATTERN, $tool) || self::LOAD_TOOLS === $tool) {
                continue;
            }
            $out[ $tool ] = $ability;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** Resolves a model-supplied tool name to an advertised ability, or null. */
    public function resolve(string $tool_name): ?Ability
    {
        return $this->advertised()[ $tool_name ] ?? null;
    }

    /** Resolves an ability name to an advertised ability, or null. */
    public function resolve_ability(string $ability_name): ?Ability
    {
        return $this->resolve(self::tool_name($ability_name));
    }

    /**
     * Advertised tool names grouped by domain, for the server-authored system
     * prompt.
     *
     * @param array<string, Ability> $advertised
     * @return array<string, string[]>
     */
    public static function by_domain(array $advertised): array
    {
        $groups = [];
        foreach ($advertised as $tool => $ability) {
            $groups[ $ability->domain ][] = $tool;
        }
        ksort($groups, SORT_STRING);
        return $groups;
    }

    /**
     * Provider tool definitions: the load_tools meta-tool plus the full
     * schema of every advertised ability in a loaded domain. Sending all
     * few-hundred schemas on every turn would cost tens of thousands of input
     * tokens before the user has asked anything, so schemas are loaded per
     * domain on demand while the names stay visible in the system prompt.
     *
     * @param array<string, Ability> $advertised
     * @param string[]               $loaded_domains
     * @return array<int, array<string, mixed>>
     */
    public static function definitions(array $advertised, array $loaded_domains): array
    {
        $domains = array_keys(self::by_domain($advertised));

        $tools = [[
            'name'         => self::LOAD_TOOLS,
            'description'  => 'Load the full schemas of the tools in one or more domains so you can call them. '
                . 'The system prompt lists every tool you are allowed to use, grouped by domain.',
            'input_schema' => [
                'type'       => 'object',
                'properties' => [
                    'domains' => [
                        'type'  => 'array',
                        'items' => ['type' => 'string', 'enum' => $domains],
                    ],
                ],
                'required'   => ['domains'],
            ],
        ]];

        foreach ($advertised as $tool => $ability) {
            if (! in_array($ability->domain, $loaded_domains, true)) {
                continue;
            }
            $tools[] = [
                'name'         => $tool,
                'description'  => self::describe($ability),
                'input_schema' => self::provider_schema($ability->input_schema),
            ];
        }

        return $tools;
    }

    private static function describe(Ability $ability): string
    {
        $description = $ability->description;
        if (Tool_Executor::requires_approval($ability)) {
            $description .= ' (Changes the site: the administrator must approve each call.)';
        }
        return $description;
    }

    /**
     * Provider tools need a JSON-schema object at the top level, and an empty
     * PHP array encodes as a JSON list, which the provider rejects where an
     * object is expected. Both are normalised here.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function provider_schema(array $schema): array
    {
        if (! isset($schema['type'])) {
            $schema['type'] = 'object';
        }
        if ('object' === $schema['type'] && empty($schema['properties'])) {
            $schema['properties'] = new \stdClass();
        }
        return self::objectify_properties($schema);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private static function objectify_properties(array $node): array
    {
        foreach ($node as $key => $value) {
            if ('properties' === $key && is_array($value)) {
                if ([] === $value) {
                    $node[ $key ] = new \stdClass();
                    continue;
                }
                foreach ($value as $prop => $sub) {
                    if (is_array($sub)) {
                        $value[ $prop ] = self::objectify_properties($sub);
                    }
                }
                $node[ $key ] = $value;
            } elseif (is_array($value) && in_array($key, ['items', 'additionalProperties'], true)) {
                $node[ $key ] = self::objectify_properties($value);
            }
        }
        return $node;
    }
}
