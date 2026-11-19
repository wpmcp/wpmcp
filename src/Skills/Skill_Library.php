<?php

namespace WPMCP\Skills;

use WPMCP\Plugin;
use WPMCP\Pro\Gate;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The agent-skill catalog (issue #74): versioned markdown playbooks, each a
 * SKILL.md with a validated frontmatter header, discovered from a bundled
 * library plus any site-custom source, and served read-only over MCP by the
 * list-skills / get-skill tools.
 *
 * Design notes, in the order they matter:
 *
 *  - VALIDATION IS A GATE, NOT A HINT. A SKILL.md missing a name, a
 *    description, or a `version`, or exceeding the size/length bounds, is
 *    NOT served: it is dropped from the catalog and recorded in invalid()
 *    for the admin screen. An agent therefore never receives a half-parsed
 *    document, and `version` is guaranteed present on everything served, so
 *    a client can cache a skill body and re-fetch only when the version
 *    moves.
 *
 *  - NO USER INPUT EVER REACHES THE FILESYSTEM. get() resolves a slug
 *    through the index built by scanning disk; it never concatenates a
 *    caller-supplied slug into a path. Traversal is not blocked by a
 *    denylist or a charset regex, it is structurally impossible. Discovery
 *    additionally requires every candidate file to realpath() inside its
 *    declared source root, so a symlink pointing out of the skills
 *    directory is skipped rather than read.
 *
 *  - SITE-AWARE AVAILABILITY. A skill may declare `requires` (ability
 *    names). A skill whose required abilities are not registered on THIS
 *    site (a vertical build, a governance-disabled domain, an absent
 *    third-party plugin) is marked unavailable and hidden from the default
 *    listing, so an agent is never handed a playbook whose tools it cannot
 *    call. Availability is read from the live Abilities registry, i.e. the
 *    same source of truth tools/list is built from.
 *
 *  - ANY-OF AVAILABILITY FOR INTEGRATIONS (issue #850). A skill may also
 *    declare `requires_any`: entries that are an ability name or an
 *    operation of a dispatcher pair written `<ability>#<operation>`. The
 *    skill is available when at least one entry is met, so one LMS skill
 *    shows on a site running any of Tutor LMS, LifterLMS or LearnDash and
 *    stays hidden on a site running none. An operation entry is met when
 *    the pair is registered and its catalog lists the operation with its
 *    own plugin active (dependency_met). The pair is read duck-typed through
 *    its handler's catalog(), so this file names no integration class.
 *
 *  - TIERING IS PER SKILL, NOT PER SURFACE. The tools and the starter
 *    library are free. A skill declaring `tier: pro` is still listed (an
 *    agent can see it exists) but its body is withheld through Pro\Gate
 *    until the site is licensed, which is what lets a premium skill library
 *    ship into the same directory structure later.
 *
 * Read-only and stateless: no options, no database, no writes. The parsed
 * index is memoized per request and can be dropped with reset().
 */
class Skill_Library
{
    /** Hard cap on a single SKILL.md, so get-skill can promise exact content. */
    public const MAX_FILE_BYTES = 65536;

    /** Bounds on the two frontmatter fields that land in tools/list-sized payloads. */
    public const MAX_NAME_LENGTH        = 80;
    public const MAX_DESCRIPTION_LENGTH = 500;

    /** Upper bound on catalog size, so a runaway custom directory cannot blow up a listing. */
    public const MAX_SKILLS = 250;

    /** Directory name under wp-content scanned as the default site-custom source. */
    public const CUSTOM_DIRNAME = 'wpmcp-skills';

    /** @var array<string, array<string, mixed>>|null slug => parsed record. */
    private static ?array $index = null;

    /** @var array<int, array{path: string, errors: string[]}> */
    private static array $invalid = [];

    /** @var array<string, array<string, bool>|null> ability => op => dependency met, per index build. */
    private static array $operation_cache = [];

    /** Shape of one `requires_any` entry: an ability, optionally `#operation`. */
    public const REQUIREMENT_PATTERN = '~^[a-z0-9-]+/[a-z0-9-]+(?:#[a-z0-9-]+)?$~';

    /** Drop the memoized index. Used by tests and by the admin screen after a change. */
    public static function reset(): void
    {
        self::$index           = null;
        self::$invalid         = [];
        self::$operation_cache = [];
    }

    /** The bundled starter library shipped inside the plugin. */
    public static function bundled_dir(): string
    {
        return __DIR__ . '/library';
    }

    /**
     * The directories scanned for SKILL.md files, least- to most-specific:
     * the bundled library, then wp-content/wpmcp-skills when it exists.
     * A later source wins a slug collision, which is what makes the custom
     * directory an override mechanism and not just an append.
     *
     * @return array<int, array{id: string, label: string, path: string}>
     */
    public static function sources(): array
    {
        $sources = [
            [
                'id'    => 'bundled',
                'label' => 'Bundled with wpmcp',
                'path'  => self::bundled_dir(),
            ],
        ];

        if (defined('WP_CONTENT_DIR')) {
            $custom = rtrim((string) WP_CONTENT_DIR, '/\\') . '/' . self::CUSTOM_DIRNAME;
            if (is_dir($custom)) {
                $sources[] = [
                    'id'    => 'site',
                    'label' => 'Site custom (wp-content/' . self::CUSTOM_DIRNAME . ')',
                    'path'  => $custom,
                ];
            }
        }

        /**
         * Filters the skill sources scanned for SKILL.md documents. Third-party
         * code appends (or replaces) entries shaped
         * ['id' => string, 'label' => string, 'path' => absolute directory].
         * Malformed entries are discarded; later entries override earlier ones
         * on a slug collision.
         *
         * @param array<int, array{id: string, label: string, path: string}> $sources
         */
        $filtered = apply_filters('wpmcp_skill_sources', $sources);

        return is_array($filtered) ? self::valid_sources($filtered) : $sources;
    }

    /**
     * The parsed catalog, slug => record. Records carry their body; the
     * listing view strips it.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function index(): array
    {
        if (null !== self::$index) {
            return self::$index;
        }

        $index         = [];
        self::$invalid = [];

        foreach (self::sources() as $source) {
            foreach (self::skill_files($source['path']) as $file) {
                if (count($index) >= self::MAX_SKILLS) {
                    break 2;
                }

                $slug = self::slug_for($source['path'], $file);
                if (null === $slug) {
                    continue;
                }

                $record = self::read($file, $slug, $source);
                if (null === $record) {
                    continue;
                }

                $index[ $slug ] = $record;
            }
        }

        ksort($index);
        self::$index = $index;

        return self::$index;
    }

    /**
     * Catalog entries without bodies, in slug order: what list-skills serves.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        $entries = [];
        foreach (self::index() as $record) {
            $entries[] = self::summarize($record);
        }
        return $entries;
    }

    /**
     * One full record (body included) or null when the slug is unknown. The
     * slug is a lookup key into the scanned index, never a path fragment.
     *
     * @return array<string, mixed>|null
     */
    public static function get(string $slug): ?array
    {
        $record = self::index()[ $slug ] ?? null;
        if (null === $record) {
            return null;
        }

        $view           = self::summarize($record);
        $view['body']   = $record['body'];
        $view['locked'] = self::is_locked($record);

        return $view;
    }

    /**
     * Documents that were found but refused, with the reasons. Surfaced on
     * the admin screen so a broken custom skill is visible to a human
     * instead of silently missing from an agent's catalog.
     *
     * @return array<int, array{path: string, errors: string[]}>
     */
    public static function invalid(): array
    {
        self::index();
        return self::$invalid;
    }

    /** Whether a record's body is withheld pending a pro license. */
    public static function is_locked(array $record): bool
    {
        return 'pro' === ($record['tier'] ?? 'free') && ! Gate::is_pro();
    }

    /**
     * Split a raw SKILL.md into its frontmatter map and body, or null when
     * the document has no frontmatter block at all.
     *
     * @return array{frontmatter: array<string, mixed>, body: string}|null
     */
    public static function parse(string $raw): ?array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $raw);

        // The closing delimiter must be a '---' line of its own, so a body
        // containing a horizontal rule cannot be mistaken for the end of
        // the header block.
        if (! preg_match('/^---[ \t]*\n(.*?)\n---[ \t]*(?:\n|$)(.*)$/s', $normalized, $m)) {
            return null;
        }

        $front = $m[1];
        $body  = $m[2];

        $frontmatter = self::parse_frontmatter($front);
        if (null === $frontmatter) {
            return null;
        }

        return [
            'frontmatter' => $frontmatter,
            'body'        => trim($body),
        ];
    }

    /**
     * Validate a parsed document. Returns the list of error codes; an empty
     * list means the skill is servable.
     *
     * @param array<string, mixed> $frontmatter
     * @return string[]
     */
    public static function validate(array $frontmatter, string $body): array
    {
        $errors = [];

        foreach (['name', 'description', 'version'] as $required) {
            $value = $frontmatter[ $required ] ?? '';
            if (! is_string($value) || '' === trim($value)) {
                $errors[] = 'missing_' . $required;
            }
        }

        $name = is_string($frontmatter['name'] ?? null) ? $frontmatter['name'] : '';
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $errors[] = 'name_too_long';
        }

        $description = is_string($frontmatter['description'] ?? null) ? $frontmatter['description'] : '';
        if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            $errors[] = 'description_too_long';
        }

        $version = is_string($frontmatter['version'] ?? null) ? trim($frontmatter['version']) : '';
        if ('' !== $version && ! preg_match('/^\d+\.\d+(\.\d+)?$/', $version)) {
            $errors[] = 'invalid_version';
        }

        $tier = $frontmatter['tier'] ?? 'free';
        if (! is_string($tier) || ! in_array($tier, ['free', 'pro'], true)) {
            $errors[] = 'invalid_tier';
        }

        foreach (['tags', 'requires', 'requires_any'] as $list_key) {
            if (! array_key_exists($list_key, $frontmatter)) {
                continue;
            }
            $list = $frontmatter[ $list_key ];
            if (! is_array($list) || $list !== array_filter($list, 'is_string')) {
                $errors[] = 'invalid_' . $list_key;
            }
        }

        if (is_array($frontmatter['requires_any'] ?? null) && ! in_array('invalid_requires_any', $errors, true)) {
            foreach ($frontmatter['requires_any'] as $entry) {
                if (! is_string($entry) || ! preg_match(self::REQUIREMENT_PATTERN, trim($entry))) {
                    $errors[] = 'invalid_requires_any';
                    break;
                }
            }
        }

        if ('' === trim($body)) {
            $errors[] = 'empty_body';
        }

        return $errors;
    }

    /**
     * The list-view projection of a record: everything except the body,
     * plus the two computed flags an agent needs to decide what to load.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private static function summarize(array $record): array
    {
        $entry = [
            'slug'        => $record['slug'],
            'name'        => $record['name'],
            'description' => $record['description'],
            'version'     => $record['version'],
            'tier'        => $record['tier'],
            'tags'        => $record['tags'],
            'source'      => $record['source'],
            'available'   => [] === $record['missing_abilities'] && [] === $record['missing_any'],
        ];

        if ([] !== $record['missing_abilities']) {
            $entry['missing_abilities'] = $record['missing_abilities'];
        }
        if ([] !== $record['missing_any']) {
            $entry['missing_any'] = $record['missing_any'];
        }
        if (self::is_locked($record)) {
            $entry['locked'] = true;
        }

        return $entry;
    }

    /**
     * Read, parse, validate and normalize one SKILL.md. Returns null (and
     * records the reasons) when the document must not be served.
     *
     * @param array{id: string, label: string, path: string} $source
     * @return array<string, mixed>|null
     */
    private static function read(string $file, string $slug, array $source): ?array
    {
        $size = filesize($file);
        if (false === $size || $size > self::MAX_FILE_BYTES) {
            self::$invalid[] = ['path' => $file, 'errors' => ['file_too_large']];
            return null;
        }

        $raw    = (string) file_get_contents($file);
        $parsed = self::parse($raw);
        if (null === $parsed) {
            self::$invalid[] = ['path' => $file, 'errors' => ['missing_frontmatter']];
            return null;
        }

        $errors = self::validate($parsed['frontmatter'], $parsed['body']);
        if ([] !== $errors) {
            self::$invalid[] = ['path' => $file, 'errors' => $errors];
            return null;
        }

        $front    = $parsed['frontmatter'];
        $requires = self::string_list($front['requires'] ?? []);
        $any      = self::string_list($front['requires_any'] ?? []);

        return [
            'slug'              => $slug,
            'name'              => trim((string) $front['name']),
            'description'       => trim((string) $front['description']),
            'version'           => trim((string) $front['version']),
            'tier'              => is_string($front['tier'] ?? null) ? $front['tier'] : 'free',
            'tags'              => self::string_list($front['tags'] ?? []),
            'requires'          => $requires,
            'missing_abilities' => self::missing_abilities($requires),
            'requires_any'      => $any,
            'missing_any'       => self::missing_any($any),
            'source'            => $source['id'],
            'body'              => $parsed['body'],
        ];
    }

    /**
     * Which of a skill's required abilities are absent from the live
     * Abilities registry on this site. When the Abilities API is not
     * loaded there is nothing to compare against, so nothing is reported
     * missing (fail-open: a listing must not empty itself on a harness that
     * lacks the API).
     *
     * @param string[] $requires
     * @return string[]
     */
    private static function missing_abilities(array $requires): array
    {
        if ([] === $requires || ! function_exists('wp_has_ability')) {
            return [];
        }

        $missing = [];
        foreach ($requires as $name) {
            if (! wp_has_ability($name)) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * The `requires_any` entries of a skill when none of them is met on this
     * site, else an empty list (one met entry is enough).
     *
     * @param string[] $entries
     * @return string[]
     */
    private static function missing_any(array $entries): array
    {
        foreach ($entries as $entry) {
            if (self::requirement_met($entry)) {
                return [];
            }
        }

        return $entries;
    }

    /** Whether one `requires_any` entry (ability, or ability#operation) is met here. */
    private static function requirement_met(string $entry): bool
    {
        if (false === strpos($entry, '#')) {
            return [] === self::missing_abilities([ $entry ]);
        }

        [ $ability, $operation ] = explode('#', $entry, 2);
        $operations              = self::operations_of($ability);

        return true === ($operations[ $operation ] ?? false);
    }

    /**
     * Operation => dependency met for one registered dispatcher pair, read
     * from its handler's catalog(); empty when the ability is not registered
     * on this site or has no operations.
     *
     * @return array<string, bool>
     */
    private static function operations_of(string $ability): array
    {
        if (array_key_exists($ability, self::$operation_cache)) {
            return self::$operation_cache[ $ability ] ?? [];
        }

        $operations = [];
        $registered = Plugin::instance()->registrar()->get($ability);
        $handler    = null === $registered ? null : $registered->handler;
        if (is_array($handler) && isset($handler[0]) && is_object($handler[0]) && method_exists($handler[0], 'catalog')) {
            try {
                $catalog = $handler[0]->catalog();
            } catch (\Throwable $e) {
                $catalog = [];
            }
            foreach ((array) ($catalog['operations'] ?? []) as $op) {
                if (is_array($op) && isset($op['name'])) {
                    $operations[ (string) $op['name'] ] = true === ($op['dependency_met'] ?? false);
                }
            }
        }

        self::$operation_cache[ $ability ] = $operations;

        return $operations;
    }

    /**
     * SKILL.md files under a source root: top level plus one nesting level,
     * so a domain folder can hold sub-skills (e.g. builders/elementor).
     *
     * @return string[] absolute paths, each verified to live inside $dir.
     */
    private static function skill_files(string $dir): array
    {
        $root = realpath($dir);
        if (false === $root || ! is_dir($root)) {
            return [];
        }

        $found = array_merge(
            (array) glob($root . '/*/SKILL.md'),
            (array) glob($root . '/*/*/SKILL.md')
        );

        $files = [];
        foreach ($found as $file) {
            $real = realpath((string) $file);
            // Containment check: a symlinked SKILL.md (or a symlinked skill
            // directory) that resolves outside the source root is skipped,
            // so a source cannot be used to read arbitrary files.
            if (false === $real || 0 !== strpos($real, $root . DIRECTORY_SEPARATOR)) {
                continue;
            }
            if (is_readable($real)) {
                $files[] = $real;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The catalog slug for a discovered file: its directory path relative to
     * the source root, e.g. 'wpmcp-safe-writes' or 'builders/elementor'.
     * Segments outside [a-z0-9-] are rejected rather than sanitized, so a
     * slug served to an agent is always a literal directory name.
     */
    private static function slug_for(string $root, string $file): ?string
    {
        $base = realpath($root);
        if (false === $base) {
            return null;
        }

        $relative = ltrim(str_replace($base, '', dirname($file)), '/\\');
        $slug     = str_replace('\\', '/', $relative);

        return preg_match('#^[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)?$#', $slug) ? $slug : null;
    }

    /**
     * Keep only well-formed source descriptors with a real directory.
     *
     * @param array<int, mixed> $sources
     * @return array<int, array{id: string, label: string, path: string}>
     */
    private static function valid_sources(array $sources): array
    {
        $valid = [];
        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }
            $id    = $source['id'] ?? '';
            $path  = $source['path'] ?? '';
            $label = $source['label'] ?? '';
            if (! is_string($id) || '' === $id || ! is_string($path) || ! is_dir($path)) {
                continue;
            }
            $valid[] = [
                'id'    => $id,
                'label' => is_string($label) && '' !== $label ? $label : $id,
                'path'  => $path,
            ];
        }

        return $valid;
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private static function string_list($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $item) {
            if (is_string($item) && '' !== trim($item)) {
                $list[] = trim($item);
            }
        }

        return $list;
    }

    /**
     * Parse the frontmatter block: a deliberately small YAML subset of
     * `key: scalar`, `key: [a, b]`, and `key:` followed by `- item` lines.
     * Anything else fails the whole document rather than being guessed at,
     * which is how a malformed header becomes a validation error an admin
     * can see instead of a silently empty field.
     *
     * @return array<string, mixed>|null
     */
    private static function parse_frontmatter(string $front): ?array
    {
        $lines  = explode("\n", $front);
        $count  = count($lines);
        $result = [];

        for ($i = 0; $i < $count; $i++) {
            $line = rtrim($lines[ $i ]);
            if ('' === trim($line) || 0 === strpos(ltrim($line), '#')) {
                continue;
            }

            if (! preg_match('/^([A-Za-z][A-Za-z0-9_-]*):[ \t]*(.*)$/', $line, $m)) {
                return null;
            }

            $key   = $m[1];
            $value = trim($m[2]);

            if ('' === $value) {
                $items = [];
                while ($i + 1 < $count && preg_match('/^[ \t]*-[ \t]+(.+)$/', $lines[ $i + 1 ], $item)) {
                    $items[] = self::unquote(trim($item[1]));
                    $i++;
                }
                $result[ $key ] = $items;
                continue;
            }

            if (0 === strpos($value, '[') && substr($value, -1) === ']') {
                $inner          = trim(substr($value, 1, -1));
                $result[ $key ] = '' === $inner
                    ? []
                    : array_map(
                        static fn ($part) => self::unquote(trim($part)),
                        explode(',', $inner)
                    );
                continue;
            }

            $result[ $key ] = self::unquote($value);
        }

        return $result;
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last  = substr($value, -1);
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }
}
