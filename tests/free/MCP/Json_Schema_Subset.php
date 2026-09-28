<?php

namespace WPMCP\Tests\Free\MCP;

/**
 * A small JSON Schema (draft 2020-12) validator covering exactly the
 * keywords the vendored discovery schemas use: type, required, properties,
 * additionalProperties (schema form), items, enum, const, pattern,
 * minLength, maxLength, format "uri" and local "#/$defs/..." references.
 *
 * The suite has no JSON Schema library and the release builds ship no dev
 * dependencies, so pulling one in for two documents is not worth it. Any
 * keyword this class does not implement fails loudly instead of being
 * skipped, so a schema update that starts relying on one cannot pass
 * silently.
 */
final class Json_Schema_Subset
{
    private const KNOWN = [
        '$schema', '$id', '$comment', '$defs', '$ref', 'description', 'type', 'required',
        'properties', 'additionalProperties', 'items', 'enum', 'const', 'pattern',
        'minLength', 'maxLength', 'format',
    ];

    /** @var array<string, mixed> */
    private array $root;

    /** @param array<string, mixed> $root */
    private function __construct(array $root)
    {
        $this->root = $root;
    }

    /**
     * Validate $data against the schema at $pointer (e.g. '#/$defs/ServerCard').
     *
     * @return string[] Human-readable violations; empty means valid.
     */
    public static function validate_file(string $schema_file, $data, string $pointer = '#'): array
    {
        $root = json_decode((string) file_get_contents($schema_file), true);
        if (! is_array($root)) {
            return ['schema file is not valid JSON: ' . $schema_file];
        }

        $self = new self($root);
        $errors = [];
        $self->check($self->resolve($pointer), $data, '$', $errors);

        return $errors;
    }

    /** @return array<string, mixed> */
    private function resolve(string $pointer): array
    {
        $node = $this->root;
        foreach (array_filter(explode('/', ltrim(substr($pointer, 1), '/')), 'strlen') as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                throw new \RuntimeException('Unresolvable $ref ' . $pointer);
            }
            $node = $node[ $segment ];
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $schema
     * @param mixed                $data
     * @param string[]             $errors
     */
    private function check(array $schema, $data, string $path, array &$errors): void
    {
        foreach (array_keys($schema) as $keyword) {
            if (! in_array($keyword, self::KNOWN, true)) {
                throw new \RuntimeException("Unsupported schema keyword '$keyword' at $path");
            }
        }

        if (isset($schema['$ref'])) {
            $this->check($this->resolve($schema['$ref']), $data, $path, $errors);
        }

        if (isset($schema['type']) && ! $this->is_type($data, $schema['type'])) {
            $errors[] = "$path: expected {$schema['type']}";
            return;
        }

        if (array_key_exists('const', $schema) && $schema['const'] !== $data) {
            $errors[] = "$path: must equal " . json_encode($schema['const']);
        }
        if (isset($schema['enum']) && ! in_array($data, $schema['enum'], true)) {
            $errors[] = "$path: not one of " . json_encode($schema['enum']);
        }

        if (is_string($data)) {
            $length = mb_strlen($data);
            if (isset($schema['minLength']) && $length < $schema['minLength']) {
                $errors[] = "$path: shorter than {$schema['minLength']}";
            }
            if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
                $errors[] = "$path: longer than {$schema['maxLength']}";
            }
            if (isset($schema['pattern']) && ! preg_match('/' . str_replace('/', '\/', $schema['pattern']) . '/u', $data)) {
                $errors[] = "$path: does not match {$schema['pattern']}";
            }
            if ('uri' === ($schema['format'] ?? null) && ! preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:[^\s]*$#', $data)) {
                $errors[] = "$path: not a URI";
            }
        }

        if (is_array($data) && array_is_list($data) && isset($schema['items'])) {
            foreach ($data as $i => $item) {
                $this->check($schema['items'], $item, "{$path}[{$i}]", $errors);
            }
        }

        if (is_array($data) && ! array_is_list($data) || (is_array($data) && [] === $data && 'object' === ($schema['type'] ?? null))) {
            foreach ((array) ($schema['required'] ?? []) as $required) {
                if (! array_key_exists($required, $data)) {
                    $errors[] = "$path: missing required '$required'";
                }
            }
            foreach ($data as $key => $value) {
                if (isset($schema['properties'][ $key ])) {
                    $this->check($schema['properties'][ $key ], $value, "$path.$key", $errors);
                } elseif (isset($schema['additionalProperties'])) {
                    if (false === $schema['additionalProperties']) {
                        $errors[] = "$path: unexpected member '$key'";
                    } elseif (is_array($schema['additionalProperties'])) {
                        $this->check($schema['additionalProperties'], $value, "$path.$key", $errors);
                    }
                }
            }
        }
    }

    /** @param mixed $data */
    private function is_type($data, string $type): bool
    {
        switch ($type) {
            case 'object':
                return is_array($data) && ([] === $data || ! array_is_list($data));
            case 'array':
                return is_array($data) && array_is_list($data);
            case 'string':
                return is_string($data);
            case 'boolean':
                return is_bool($data);
            case 'number':
                return is_int($data) || is_float($data);
            case 'integer':
                return is_int($data);
            default:
                throw new \RuntimeException("Unsupported type '$type'");
        }
    }
}
