<?php

namespace WPMCP\MCP;

use WPMCP\Skills\Skill_Library;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * MCP prompts and resources (issue #301): the skills library served as
 * prompts, and read-only site context served as resources, on both the HTTP
 * and the stdio transport.
 *
 * NOTHING HERE IS A NEW PERMISSION MODEL. Every primitive is backed by a
 * live wpmcp ability that already serves the same data as a tool:
 *
 *   prompts/get <slug>         -> wpmcp/get-skill { slug }
 *   resources/read site        -> wpmcp/get-site-context
 *   resources/read skills      -> wpmcp/list-skills
 *
 * The permission check is that ability's check_permissions(), which is
 * Registrar::is_permitted() (tier, capability, Governance, identity scope,
 * project memory, audited under the ability's own name), and the content
 * comes from that ability's execute(), which runs the Registrar's wrapped
 * callback (the shared rate-limit budget and the request log). A primitive
 * is therefore exactly as reachable as the tool behind it, and when that
 * tool is not registered at all (governance-disabled, skills module off,
 * master exposure switch off) the primitive is simply absent.
 *
 * Every backing ability is a registered 'read' operation, so no primitive
 * can reach a write path. Transports translate the WP_Error codes below into
 * their own JSON-RPC errors; this class owns no protocol framing.
 */
class Context_Primitives
{
    public const SITE_CONTEXT_URI = 'wpmcp://site/context';
    public const SKILLS_URI       = 'wpmcp://skills';

    /** The ability that serves a skill body, and so every prompt. */
    public const PROMPT_ABILITY = 'wpmcp/get-skill';

    /** Error codes the transports map onto JSON-RPC errors. */
    public const ERROR_NOT_FOUND = 'wpmcp_primitive_not_found';
    public const ERROR_DENIED    = 'wpmcp_primitive_denied';

    /**
     * uri => descriptor. The ability key is internal and never serialized.
     *
     * @return array<string, array{ability: string, name: string, title: string, description: string, mimeType: string}>
     */
    private static function resource_catalog(): array
    {
        return [
            self::SITE_CONTEXT_URI => [
                'ability'     => 'wpmcp/get-site-context',
                'name'        => 'site-context',
                'title'       => 'Site context',
                'description' => 'Orientation for this WordPress site: identity, versions, theme, active plugins, content model and active integrations. Same payload as the get-site-context tool.',
                'mimeType'    => 'application/json',
            ],
            self::SKILLS_URI => [
                'ability'     => 'wpmcp/list-skills',
                'name'        => 'skills',
                'title'       => 'Agent skill catalog',
                'description' => 'The installed agent skills available on this site (slug, name, description, version, tags). Each one is also served as a prompt.',
                'mimeType'    => 'application/json',
            ],
        ];
    }

    /**
     * Every ability a primitive can reach, for audits and tests.
     *
     * @return string[]
     */
    public static function backing_abilities(): array
    {
        $names = array_column(self::resource_catalog(), 'ability');
        $names[] = self::PROMPT_ABILITY;
        return array_values(array_unique($names));
    }

    /**
     * Prompt descriptors in MCP shape (name, title, description). A skill is
     * offered only when its body can actually be served: it is available on
     * this site (its required tools are registered) and its body is not
     * withheld here (get-skill would refuse a locked skill anyway).
     *
     * @return array<int, array{name: string, title: string, description: string}>
     */
    public static function prompts(): array
    {
        if (null === self::live_ability(self::PROMPT_ABILITY)) {
            return [];
        }

        $prompts = [];
        foreach (Skill_Library::all() as $skill) {
            if (true !== ($skill['available'] ?? false) || true === ($skill['locked'] ?? false)) {
                continue;
            }
            $prompts[] = [
                'name'        => (string) $skill['slug'],
                'title'       => (string) $skill['name'],
                'description' => (string) $skill['description'],
            ];
        }

        return $prompts;
    }

    /** Whether a prompt name is one prompts() currently offers. */
    public static function has_prompt(string $name): bool
    {
        return '' !== $name && in_array($name, array_column(self::prompts(), 'name'), true);
    }

    /**
     * The backing ability's permission decision for one prompt.
     *
     * @return true|\WP_Error
     */
    public static function prompt_permission(string $name)
    {
        return self::permission(self::PROMPT_ABILITY, [ 'slug' => $name ]);
    }

    /**
     * Render one prompt: the skill body verbatim as a single user message.
     *
     * @return array{description: string, messages: array<int, array<string, mixed>>}|\WP_Error
     */
    public static function render_prompt(string $name)
    {
        if (! self::has_prompt($name)) {
            return self::not_found(sprintf('Unknown prompt: %s', $name));
        }

        $skill = self::execute(self::PROMPT_ABILITY, [ 'slug' => $name ]);
        if (is_wp_error($skill)) {
            return $skill;
        }

        return [
            'description' => (string) ($skill['description'] ?? ''),
            'messages'    => [
                [
                    'role'    => 'user',
                    'content' => [
                        'type' => 'text',
                        'text' => (string) ($skill['body'] ?? ''),
                    ],
                ],
            ],
        ];
    }

    /**
     * Resource descriptors in MCP shape, only for resources whose backing
     * ability is registered.
     *
     * @return array<int, array{uri: string, name: string, title: string, description: string, mimeType: string}>
     */
    public static function resources(): array
    {
        $resources = [];
        foreach (self::resource_catalog() as $uri => $entry) {
            if (null === self::live_ability($entry['ability'])) {
                continue;
            }
            unset($entry['ability']);
            $resources[] = [ 'uri' => $uri ] + $entry;
        }

        return $resources;
    }

    /** The ability backing one resource URI, or null for an unknown URI. */
    public static function resource_ability(string $uri): ?string
    {
        return self::resource_catalog()[ $uri ]['ability'] ?? null;
    }

    /** Whether a URI is one resources() currently offers. */
    public static function has_resource(string $uri): bool
    {
        return in_array($uri, array_column(self::resources(), 'uri'), true);
    }

    /**
     * @return true|\WP_Error
     */
    public static function resource_permission(string $uri)
    {
        $entry = self::resource_catalog()[ $uri ] ?? null;
        if (null === $entry) {
            return self::not_found(sprintf('Resource not found: %s', $uri));
        }

        return self::permission($entry['ability'], []);
    }

    /**
     * Read one resource as a single JSON text content item.
     *
     * @return array<int, array{uri: string, mimeType: string, text: string}>|\WP_Error
     */
    public static function read_resource(string $uri)
    {
        $entry = self::resource_catalog()[ $uri ] ?? null;
        if (null === $entry || ! self::has_resource($uri)) {
            return self::not_found(sprintf('Resource not found: %s', $uri));
        }

        $payload = self::execute($entry['ability'], []);
        if (is_wp_error($payload)) {
            return $payload;
        }

        $text = wp_json_encode($payload);
        if (false === $text) {
            return new \WP_Error('wpmcp_primitive_encode_failed', 'Resource could not be serialized as JSON.');
        }

        return [
            [
                'uri'      => $uri,
                'mimeType' => $entry['mimeType'],
                'text'     => $text,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return true|\WP_Error
     */
    private static function permission(string $ability_name, array $input)
    {
        $ability = self::live_ability($ability_name);
        if (null === $ability) {
            return self::not_found(sprintf('Not available: %s', $ability_name));
        }

        $allowed = $ability->check_permissions($input);
        if (true === $allowed) {
            return true;
        }

        return new \WP_Error(
            self::ERROR_DENIED,
            is_wp_error($allowed)
                ? $allowed->get_error_message()
                : sprintf('Access denied by the permissions of %s.', $ability_name)
        );
    }

    /**
     * Run the backing ability through its own execute(), so the permission
     * chain, rate limit and request log are the tool's own.
     *
     * @param array<string, mixed> $input
     * @return mixed|\WP_Error
     */
    private static function execute(string $ability_name, array $input)
    {
        $ability = self::live_ability($ability_name);
        if (null === $ability) {
            return self::not_found(sprintf('Not available: %s', $ability_name));
        }

        $result = $ability->execute($input);
        if (is_wp_error($result) && 'ability_invalid_permissions' === $result->get_error_code()) {
            return new \WP_Error(self::ERROR_DENIED, $result->get_error_message());
        }

        return $result;
    }

    /** The live registry entry, or null. Never trips _doing_it_wrong. */
    private static function live_ability(string $name): ?\WP_Ability
    {
        if (! function_exists('wp_has_ability') || ! wp_has_ability($name)) {
            return null;
        }

        $ability = wp_get_ability($name);
        return $ability instanceof \WP_Ability ? $ability : null;
    }

    private static function not_found(string $message): \WP_Error
    {
        return new \WP_Error(self::ERROR_NOT_FOUND, $message);
    }
}
