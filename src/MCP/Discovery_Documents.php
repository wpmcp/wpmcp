<?php

namespace WPMCP\MCP;

use WPMCP\Auth\Mcp_Resource;
use WPMCP\Auth\OAuth_Config;
use WPMCP\Governance\Governance;
use WPMCP\Plugin;
use WPMCP\Skills\Skill_Library;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The public agent discovery documents (issue #302), built here and served
 * by Discovery_Endpoints:
 *
 *  - an MCP Server Card, the pre-connection description of the HTTP
 *    endpoint defined by the MCP Server Card extension (SEP-2127,
 *    io.modelcontextprotocol/server-card), hosted at the reserved
 *    <streamable-http-url>/server-card location;
 *  - an AI Catalog at /.well-known/ai-catalog.json, the domain-level entry
 *    point that SEP-2127 uses to find cards, linking the card and each
 *    published skill;
 *  - an Agent Skills discovery index (schema 0.2.0) at
 *    /.well-known/agent-skills/index.json, with one skill-md artifact per
 *    published skill.
 *
 * EVERYTHING HERE IS PUBLIC AND UNAUTHENTICATED, so the rules are narrow:
 *
 *  - No user or session data. Nothing is read from the current user; the
 *    documents are identical for every caller.
 *  - Nothing disabled is advertised. A tool counts only when it is
 *    registered AND the live governance walk and tier gate still allow it
 *    (tool_is_public()), which is the same pair Registrar applies at
 *    registration. A skill is published only when every tool it requires
 *    passes that check, so no disabled tool name can leak through a
 *    skill's requirements.
 *  - Paid and site-custom skills stay private. Only free, bundled skills
 *    are published, since their text already ships in the public plugin
 *    zip. A site can opt a custom skill in through the
 *    wpmcp_discovery_publish_skill filter.
 *  - The card never lists tools, prompts or resources by name. SEP-2127
 *    deliberately keeps primitives out of cards because they vary per
 *    caller; the vendor _meta block carries only whether each primitive
 *    kind is available at all.
 */
class Discovery_Documents
{
    /*
     * The two $schema values are identifiers the specifications require
     * verbatim in the documents; nothing here ever fetches or loads them.
     * The '.json' suffix is a separate literal only so the asset-offloading
     * scan (which reads a URL ending in .json as a remotely hosted asset)
     * does not mistake an identifier for one.
     */
    public const SERVER_CARD_SCHEMA  = 'https://static.modelcontextprotocol.io/schemas/v1/server-card.schema' . '.json';
    public const SERVER_CARD_TYPE    = 'application/mcp-server-card+json';
    public const SKILLS_INDEX_SCHEMA = 'https://schemas.agentskills.io/discovery/0.2.0/schema' . '.json';
    public const SKILL_ENTRY_TYPE    = 'application/agent-skills+md';
    public const CATALOG_SPEC        = '1.0';

    /** Reverse-DNS namespace for this plugin's vendor metadata. */
    public const META_KEY = 'com.wpmcp-pro/discovery';

    public const CATALOG_PATH      = '/.well-known/ai-catalog.json';
    public const SKILLS_BASE_PATH  = '/.well-known/agent-skills/';
    public const SKILLS_INDEX_PATH = '/.well-known/agent-skills/index.json';
    public const SERVER_CARD_SUFFIX = '/server-card';

    /** Agent Skills name rule: 1-64 chars of [a-z0-9-], no leading, trailing or doubled hyphen. */
    private const SKILL_NAME_PATTERN = '/^(?=.{1,64}$)[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** SEP-2127 caps title and description at 100 characters. */
    private const CARD_TEXT_MAX = 100;

    /** Whether the discovery documents are served at all. Default on. */
    public static function is_enabled(): bool
    {
        /**
         * Filters whether the public agent discovery documents (server card,
         * AI catalog, skills index) are served.
         *
         * @param bool $enabled Default true.
         */
        return (bool) apply_filters('wpmcp_discovery_documents_enabled', true);
    }

    public static function server_card_url(): string
    {
        return Mcp_Resource::canonical() . self::SERVER_CARD_SUFFIX;
    }

    public static function skills_index_url(): string
    {
        return home_url(self::SKILLS_INDEX_PATH);
    }

    public static function skill_url(string $slug): string
    {
        return home_url(self::SKILLS_BASE_PATH . $slug . '/SKILL.md');
    }

    /**
     * The Server Card, or null when no MCP endpoint is mounted (Server does
     * not mount one without at least one tool).
     *
     * @return array<string, mixed>|null
     */
    public static function server_card(): ?array
    {
        if (! self::has_public_tools()) {
            return null;
        }

        $remote = [
            'type' => 'streamable-http',
            'url'  => Mcp_Resource::canonical(),
        ];
        // Every revision the endpoint answers, whichever adapter copy
        // mounts it: 2026-07-28 is served by WP MCP when the adapter
        // predates it (issue #386).
        $remote['supportedProtocolVersions'] = Protocol_Revision::advertised_versions();

        $oauth = OAuth_Config::is_enabled();
        if ($oauth) {
            // The client learns everything it needs from the RFC 9728
            // document, so no header is pre-declared for the user to fill.
            $authorization = [
                'type'                      => 'oauth2',
                'protectedResourceMetadata' => Mcp_Resource::metadata_url(),
            ];
        } else {
            $authorization    = [ 'type' => 'http-basic' ];
            $remote['headers'] = [
                [
                    'name'        => 'Authorization',
                    'description' => 'HTTP Basic credentials for a WordPress user, using an application password.',
                    'isRequired'  => true,
                    'isSecret'    => true,
                    'placeholder' => 'Basic <base64 of username:application-password>',
                ],
            ];
        }

        $meta = [
            'authorization' => $authorization,
            'primitives'    => [
                'tools'     => true,
                'prompts'   => self::prompts_available(),
                'resources' => self::resources_available(),
            ],
        ];
        if (null !== self::skills_index()) {
            $meta['skillsIndex'] = self::skills_index_url();
        }

        return [
            '$schema'     => self::SERVER_CARD_SCHEMA,
            'name'        => self::card_name(),
            'title'       => self::clip(self::site_name()),
            'description' => self::clip(__('AI builds and edits your WordPress site, and physically cannot wreck it.', 'wpmcp')),
            'version'     => defined('WPMCP_VERSION') ? WPMCP_VERSION : '0.0.0',
            'websiteUrl'  => home_url('/'),
            'remotes'     => [ $remote ],
            '_meta'       => [ self::META_KEY => $meta ],
        ];
    }

    /**
     * The AI Catalog, or null when it would have no entries.
     *
     * @return array<string, mixed>|null
     */
    public static function ai_catalog(): ?array
    {
        $publisher = self::host();
        $entries   = [];

        if (null !== self::server_card()) {
            $entries[] = [
                'identifier' => 'urn:air:' . $publisher . ':mcp:' . Server::SERVER_NAME,
                'type'       => self::SERVER_CARD_TYPE,
                'url'        => self::server_card_url(),
            ];
        }

        foreach (self::published_skills() as $slug => $record) {
            $entries[] = [
                'identifier'  => 'urn:air:' . $publisher . ':skill:' . $slug,
                'displayName' => $record['name'],
                'type'        => self::SKILL_ENTRY_TYPE,
                'description' => $record['description'],
                'url'         => self::skill_url($slug),
            ];
        }

        if ([] === $entries) {
            return null;
        }

        return [
            'specVersion' => self::CATALOG_SPEC,
            'host'        => [
                'displayName' => self::site_name(),
                'identifier'  => $publisher,
            ],
            'entries'     => $entries,
        ];
    }

    /**
     * The Agent Skills discovery index, or null when the skills surface is
     * not reachable on this site.
     *
     * @return array<string, mixed>|null
     */
    public static function skills_index(): ?array
    {
        if (! self::skills_surface_public()) {
            return null;
        }

        $skills = [];
        foreach (self::published_skills() as $slug => $record) {
            $skills[] = [
                'name'          => $slug,
                'type'          => 'skill-md',
                'description'   => $record['description'],
                'url'           => self::skill_url($slug),
                'digest'        => 'sha256:' . hash('sha256', self::render_skill($record)),
                self::META_KEY  => [
                    'title'         => $record['name'],
                    'version'       => $record['version'],
                    'requiredTools' => $record['requires'],
                    'prompt'        => $slug,
                ],
            ];
        }

        return [
            '$schema' => self::SKILLS_INDEX_SCHEMA,
            'skills'  => $skills,
        ];
    }

    /**
     * One published skill as an Agent Skills SKILL.md, or null when that
     * slug is not published. The slug is only ever a lookup key.
     */
    public static function skill_markdown(string $slug): ?string
    {
        $record = self::published_skills()[ $slug ] ?? null;

        return null === $record ? null : self::render_skill($record);
    }

    /**
     * The skills that may appear in public documents, slug => record.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function published_skills(): array
    {
        if (! self::skills_surface_public()) {
            return [];
        }

        $published = [];
        foreach (Skill_Library::index() as $slug => $record) {
            $slug = (string) $slug;
            if (! preg_match(self::SKILL_NAME_PATTERN, $slug)) {
                continue;
            }
            if ('free' !== ($record['tier'] ?? 'free') || Skill_Library::is_locked($record)) {
                continue;
            }

            $requires = array_values((array) ($record['requires'] ?? []));
            foreach ($requires as $tool) {
                if (! self::tool_is_public((string) $tool)) {
                    continue 2;
                }
            }

            /**
             * Filters whether one skill is published in the public discovery
             * documents. Only free skills whose required tools are all
             * enabled reach this filter, so it can add a site-custom skill
             * but never expose a paid or disabled one.
             *
             * @param bool                 $publish Default: true for bundled skills.
             * @param array<string, mixed> $record  The parsed catalog record.
             */
            if (! apply_filters('wpmcp_discovery_publish_skill', 'bundled' === ($record['source'] ?? ''), $record)) {
                continue;
            }

            $published[ $slug ] = $record;
        }

        return $published;
    }

    /**
     * Whether a tool may be named or implied in a public document: it is
     * registered, and the tier gate and the live governance walk still allow
     * it (a toggle flipped this request counts, not only at registration).
     *
     * @param array<string, Ability>|null $declared A declared-ability map to
     *                                               reuse across many calls.
     */
    public static function tool_is_public(string $name, ?array $declared = null): bool
    {
        if (! function_exists('wp_has_ability') || ! wp_has_ability($name)) {
            return false;
        }

        $ability = ($declared ?? self::declared())[ $name ] ?? null;

        return null !== $ability
            && Registrar::tier_permitted($ability->tier)
            && Governance::is_ability_enabled($ability);
    }

    private static function has_public_tools(): bool
    {
        if (! function_exists('wp_get_abilities')) {
            return false;
        }

        $declared = self::declared();
        foreach (array_keys($declared) as $name) {
            if (self::tool_is_public((string) $name, $declared)) {
                return true;
            }
        }

        return false;
    }

    private static function skills_surface_public(): bool
    {
        return self::tool_is_public('wpmcp/list-skills') && self::tool_is_public(Context_Primitives::PROMPT_ABILITY);
    }

    private static function prompts_available(): bool
    {
        return self::tool_is_public(Context_Primitives::PROMPT_ABILITY) && [] !== Context_Primitives::prompts();
    }

    private static function resources_available(): bool
    {
        foreach (Context_Primitives::resources() as $resource) {
            $ability = Context_Primitives::resource_ability((string) $resource['uri']);
            if (null !== $ability && self::tool_is_public($ability)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, Ability> */
    private static function declared(): array
    {
        $map = [];
        foreach (Plugin::instance()->declared_abilities() as $ability) {
            $map[ $ability->name ] = $ability;
        }

        return $map;
    }

    /**
     * An Agent Skills SKILL.md: `name` is the slug (the format requires the
     * directory-style name), everything else rides in `metadata`. Scalars
     * are JSON-encoded, which is valid YAML double-quoted syntax, so no
     * value can break out of its line.
     *
     * @param array<string, mixed> $record
     */
    private static function render_skill(array $record): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $lines = [
            '---',
            'name: ' . $record['slug'],
            'description: ' . wp_json_encode((string) $record['description'], $flags),
            'metadata:',
            '  title: ' . wp_json_encode((string) $record['name'], $flags),
            '  version: ' . wp_json_encode((string) $record['version'], $flags),
        ];
        if ([] !== $record['requires']) {
            $lines[] = '  required-tools: ' . wp_json_encode(implode(' ', $record['requires']), $flags);
        }
        $lines[] = '---';

        return implode("\n", $lines) . "\n\n" . trim((string) $record['body']) . "\n";
    }

    /** Reverse-DNS server name: org.example/wpmcp, or org.example/wpmcp.blog for a subdirectory install. */
    private static function card_name(): string
    {
        $labels    = array_reverse(explode('.', self::host()));
        $namespace = (string) preg_replace('/[^a-zA-Z0-9.-]/', '-', implode('.', $labels));

        $path = trim((string) wp_parse_url(home_url(), PHP_URL_PATH), '/');
        $name = Server::SERVER_NAME;
        if ('' !== $path) {
            $name .= '.' . (string) preg_replace('/[^a-zA-Z0-9._-]/', '-', str_replace('/', '.', $path));
        }

        return $namespace . '/' . $name;
    }

    /** The site host, lowercased, without port or IPv6 brackets. */
    private static function host(): string
    {
        $host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $host = (string) preg_replace('/[^a-z0-9.-]/', '-', trim($host, '[]'));

        return '' === $host ? 'localhost' : $host;
    }

    private static function site_name(): string
    {
        $name = trim(wp_strip_all_tags((string) get_bloginfo('name')));

        return '' === $name ? self::host() : html_entity_decode($name, ENT_QUOTES, 'UTF-8');
    }

    private static function clip(string $text): string
    {
        return mb_strlen($text) > self::CARD_TEXT_MAX ? rtrim(mb_substr($text, 0, self::CARD_TEXT_MAX - 1)) . "\u{2026}" : $text;
    }
}
