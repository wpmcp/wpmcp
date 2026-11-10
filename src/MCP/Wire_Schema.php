<?php

namespace WPMCP\MCP;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A JSON Schema as it must reach the wire (issues #586, #591).
 *
 * PHP has one array type, so a schema written the WordPress way, with
 * `'properties' => []` for a tool that takes no arguments or `'value' => []`
 * for an argument of any type, serializes as a JSON array. JSON Schema
 * needs an object in both places, and clients that validate tool schemas
 * (Claude Code) drop the whole tool: "schema/properties must be object".
 *
 * The ability definitions stay as they are, because the Abilities API
 * validates input against them as PHP arrays. The conversion happens once,
 * where tools/list is produced, and walks the schema to any depth:
 *
 *  - a keyword whose value maps names to schemas (`properties`,
 *    `patternProperties`, `$defs`, ...) becomes a JSON object when empty,
 *    and each member is converted as a schema;
 *  - a schema that is empty (the "accepts anything" schema) becomes `{}`;
 *  - keywords holding one schema or a list of schemas are converted
 *    recursively;
 *  - every other keyword (`enum`, `required`, `default`, `const`,
 *    `examples`, ...) is data, not a schema, and is left exactly as written,
 *    so an empty `required` or `default` stays an empty JSON array.
 */
final class Wire_Schema
{
    /** Keywords whose value is an object mapping names to schemas. */
    private const SCHEMA_MAPS = [ 'properties', 'patternProperties', 'definitions', '$defs', 'dependentSchemas' ];

    /** Keywords whose value is one schema. */
    private const SCHEMA_SINGLES = [
        'items', 'additionalProperties', 'additionalItems', 'not', 'if', 'then', 'else',
        'contains', 'propertyNames', 'unevaluatedProperties', 'unevaluatedItems', 'contentSchema',
    ];

    /** Keywords whose value is a list of schemas. */
    private const SCHEMA_LISTS = [ 'allOf', 'anyOf', 'oneOf', 'prefixItems' ];

    /**
     * A tool's inputSchema for the wire. MCP requires an object schema of
     * type "object" here, so an empty or missing one becomes exactly that.
     *
     * @param mixed $schema The ability's input schema.
     * @return array<string,mixed>
     */
    public static function input($schema): array
    {
        $schema = self::to_array($schema);
        if ([] === $schema) {
            return [ 'type' => 'object' ];
        }

        $converted = self::schema($schema);

        return is_array($converted) ? $converted : [ 'type' => 'object' ];
    }

    /**
     * Any schema for the wire: an empty one becomes `{}`, booleans pass
     * through, and a non-empty one has its schema-valued keywords converted.
     *
     * @param mixed $schema A JSON Schema as a PHP value.
     * @return mixed
     */
    public static function schema($schema)
    {
        if (is_bool($schema)) {
            return $schema;
        }
        if ($schema instanceof \stdClass) {
            $schema = (array) $schema;
        }
        if (! is_array($schema)) {
            // Not a schema at all; leave it for the client to reject
            // rather than invent a meaning for it.
            return $schema;
        }
        if ([] === $schema) {
            return new \stdClass();
        }
        if (array_is_list($schema)) {
            return $schema;
        }

        foreach (self::SCHEMA_MAPS as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                $schema[ $keyword ] = self::schema_map($schema[ $keyword ]);
            }
        }

        foreach (self::SCHEMA_SINGLES as $keyword) {
            if (! array_key_exists($keyword, $schema)) {
                continue;
            }
            $value = $schema[ $keyword ];
            if ('items' === $keyword && is_array($value) && [] !== $value && array_is_list($value)) {
                // Draft-04 tuple form: a list of schemas.
                $schema[ $keyword ] = array_map([ self::class, 'schema' ], $value);
                continue;
            }
            $schema[ $keyword ] = self::schema($value);
        }

        foreach (self::SCHEMA_LISTS as $keyword) {
            if (array_key_exists($keyword, $schema) && is_array($schema[ $keyword ])) {
                $schema[ $keyword ] = array_map([ self::class, 'schema' ], array_values($schema[ $keyword ]));
            }
        }

        return $schema;
    }

    /**
     * A name-to-schema map: always a JSON object, each member a schema.
     *
     * @param mixed $map The keyword's value.
     * @return mixed
     */
    private static function schema_map($map)
    {
        if ($map instanceof \stdClass) {
            $map = (array) $map;
        }
        if (! is_array($map)) {
            return $map;
        }
        if ([] === $map) {
            return new \stdClass();
        }

        foreach ($map as $name => $member) {
            $map[ $name ] = self::schema($member);
        }

        return $map;
    }

    /**
     * @param mixed $schema
     * @return array<string,mixed>
     */
    private static function to_array($schema): array
    {
        if ($schema instanceof \stdClass) {
            return (array) $schema;
        }

        return is_array($schema) ? $schema : [];
    }
}
