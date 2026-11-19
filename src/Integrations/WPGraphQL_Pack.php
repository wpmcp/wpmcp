<?php

namespace WPMCP\Integrations;

use WPMCP\Privacy\Masking;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WPGraphQL ops on the plugin-data pair (issue #704), read only:
 *  - wpgraphql-query runs a GraphQL query through WPGraphQL's own graphql()
 *    as the current user, so WPGraphQL's resolvers and their permission
 *    checks decide what comes back, exactly as for the site's headless
 *    front end. Mutations and subscriptions are refused before WPGraphQL
 *    runs: the document is scanned on its own (comments and strings
 *    skipped) and, when WPGraphQL's bundled parser is loaded, parsed too,
 *    and any operation that is not a query refuses the whole call.
 *  - wpgraphql-get-schema summarises the schema through introspection: the
 *    root query fields and the type names by kind, or one type's fields,
 *    input fields, enum values and interfaces. The mutation and
 *    subscription roots are left out.
 *
 * Both ops take edit_posts, the gate call-rest uses for the same reason:
 * WPGraphQL's own resolvers decide per field what the current user sees.
 *
 * Results follow the list masking rule (Masking): a GraphQL result can hold
 * many people's data in one call, so emails, phone numbers and IP addresses
 * are masked wherever they appear and the response says masked:true.
 * WPGraphQL's debug extensions (query logs, stack traces) are not returned.
 */
final class WPGraphQL_Pack
{
    /** Largest JSON result returned, in bytes; a bigger one asks for a narrower query. */
    private const MAX_RESULT_BYTES = 100000;

    /** Longest description kept in a schema summary. */
    private const MAX_DESCRIPTION = 200;

    /** Result keys whose values are personal data. */
    private const PERSONAL_KEYS = '/(e-?mail|phone|^ip$|ip_?address|author_?ip|user_?agent|author_?agent)/i';

    /** @var callable|null */
    private static $runner = null;

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_wpgraphql_active', defined('WPGRAPHQL_VERSION') && function_exists('graphql'));
    }

    /** @return true|array{code: string, message: string} */
    public static function requirement()
    {
        return self::active() ? true : Plugin_Refusal::make('wpgraphql_inactive', 'WPGraphQL');
    }

    /** Replace WPGraphQL's graphql() in tests, which run without it. Null restores it. */
    public static function use_runner_for_tests(?callable $runner): void
    {
        if (! defined('WPMCP_TESTING') || ! WPMCP_TESTING) {
            return;
        }
        self::$runner = $runner;
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $requires = static fn () => self::requirement();

        return [
            'wpgraphql-query'      => [
                'mode'         => 'read',
                'capability'   => 'edit_posts',
                'description'  => 'Run a read-only WPGraphQL query as the current user (mutations and subscriptions refused) and return data and errors, with emails, phones and IPs masked',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'query'          => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 20000 ],
                        'variables'      => [ 'type' => 'object' ],
                        'operation_name' => [ 'type' => 'string' ],
                    ],
                    'required'   => [ 'query' ],
                ],
                'requires'     => $requires,
                'validate'     => static fn (array $args): ?array => self::write_refusal((string) $args['query']),
                'handler'      => static function (array $args): array {
                    $request = [ 'query' => (string) $args['query'] ];
                    if (isset($args['variables'])) {
                        $request['variables'] = (array) $args['variables'];
                    }
                    if (isset($args['operation_name']) && '' !== (string) $args['operation_name']) {
                        // graphql() hands its array to graphql-php's
                        // OperationParams, which reads operationName.
                        $request['operationName'] = (string) $args['operation_name'];
                    }
                    return self::query_result(self::run($request));
                },
            ],
            'wpgraphql-get-schema' => [
                'mode'         => 'read',
                'capability'   => 'edit_posts',
                'description'  => 'Summarise the WPGraphQL schema: root query fields and type names by kind, or one type\'s fields, input fields, enum values and interfaces (mutations left out)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'type' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ] ],
                ],
                'requires'     => $requires,
                'handler'      => static function (array $args): array {
                    return isset($args['type']) ? self::describe_type((string) $args['type']) : self::schema_summary();
                },
            ],
        ];
    }

    /**
     * The refusal for a document holding any operation that is not a query,
     * or null.
     *
     * @return array{code: string, message: string, data: array}|null
     */
    public static function write_refusal(string $document): ?array
    {
        $kinds = self::operation_kinds($document);
        if (class_exists('\GraphQL\Language\Parser')) {
            try {
                $ast = \GraphQL\Language\Parser::parse($document);
                foreach ($ast->definitions as $definition) {
                    if ($definition instanceof \GraphQL\Language\AST\OperationDefinitionNode) {
                        $kinds[] = (string) $definition->operation;
                    }
                }
            } catch (\Throwable $e) {
                // A syntax error: WPGraphQL reports it in its own words.
                unset($e);
            }
        }

        $writes = array_values(array_unique(array_diff($kinds, [ 'query' ])));
        if ([] === $writes) {
            return null;
        }
        return [
            'code'    => 'mutation_refused',
            'message' => sprintf('wpgraphql-query runs queries only; this document holds an operation of type "%s". Use the matching WP MCP write tools, which are snapshotted and undoable.', implode('" and "', $writes)),
            'data'    => [ 'operations' => $writes ],
        ];
    }

    /**
     * The kind of every operation in a document, read from the first word of
     * each top-level definition: 'query' for the shorthand { ... } form,
     * the keyword otherwise. Comments and strings are skipped, and names
     * inside selections, arguments and variable lists are not keywords.
     * A fragment definition is not an operation and is left out.
     *
     * @return string[]
     */
    public static function operation_kinds(string $document): array
    {
        $kinds  = [];
        $length = strlen($document);
        $braces = 0;
        $parens = 0;
        $expect = true;
        $i      = 0;
        while ($i < $length) {
            $char = $document[ $i ];
            if ('#' === $char) {
                $end = strpos($document, "\n", $i);
                $i   = false === $end ? $length : $end + 1;
                continue;
            }
            if ('"' === $char) {
                $i = self::skip_string($document, $i);
                continue;
            }
            if ('{' === $char) {
                if (0 === $braces && 0 === $parens && $expect) {
                    $kinds[] = 'query';
                    $expect  = false;
                }
                ++$braces;
                ++$i;
                continue;
            }
            if ('}' === $char) {
                $braces = max(0, $braces - 1);
                if (0 === $braces && 0 === $parens) {
                    $expect = true;
                }
                ++$i;
                continue;
            }
            if ('(' === $char) {
                ++$parens;
                ++$i;
                continue;
            }
            if (')' === $char) {
                $parens = max(0, $parens - 1);
                ++$i;
                continue;
            }
            if (1 === preg_match('/\G[_A-Za-z][_0-9A-Za-z]*/', $document, $m, 0, $i)) {
                $before = $i > 0 ? $document[ $i - 1 ] : '';
                if (0 === $braces && 0 === $parens && $expect && '$' !== $before && '@' !== $before) {
                    if (in_array($m[0], [ 'query', 'mutation', 'subscription' ], true)) {
                        $kinds[] = $m[0];
                    }
                    $expect = false;
                }
                $i += strlen($m[0]);
                continue;
            }
            ++$i;
        }
        return $kinds;
    }

    /** The offset just past the string or block string that starts at $i. */
    private static function skip_string(string $document, int $i): int
    {
        $length = strlen($document);
        if ('"""' === substr($document, $i, 3)) {
            $j = $i + 3;
            while ($j < $length) {
                if ('\\"""' === substr($document, $j, 4)) {
                    $j += 4;
                    continue;
                }
                if ('"""' === substr($document, $j, 3)) {
                    return $j + 3;
                }
                ++$j;
            }
            return $length;
        }
        $j = $i + 1;
        while ($j < $length) {
            if ('\\' === $document[ $j ]) {
                $j += 2;
                continue;
            }
            if ('"' === $document[ $j ] || "\n" === $document[ $j ]) {
                return $j + 1;
            }
            ++$j;
        }
        return $length;
    }

    /**
     * WPGraphQL's answer to one request, as an array.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private static function run(array $request): array
    {
        if (null !== self::$runner) {
            $out = (self::$runner)($request);
        } elseif (function_exists('graphql')) {
            try {
                $out = graphql($request);
            } catch (\Throwable $e) {
                throw new Operation_Refused('graphql_failed', esc_html(sprintf('WPGraphQL could not run the request: %s', $e->getMessage())));
            }
        } else {
            throw new Operation_Refused('wpgraphql_inactive', 'WPGraphQL is not loaded.');
        }
        if (is_object($out) && method_exists($out, 'toArray')) {
            $out = $out->toArray();
        }
        return is_array($out) ? $out : [];
    }

    /**
     * The data and errors of a query, masked and size checked; debug
     * extensions are dropped.
     *
     * @param array<string, mixed> $out
     * @return array<string, mixed>
     */
    private static function query_result(array $out): array
    {
        $result = [];
        $masked = false;
        if (array_key_exists('data', $out)) {
            $result['data'] = self::mask($out['data'], '', $masked);
        }
        if (! empty($out['errors']) && is_array($out['errors'])) {
            $result['errors'] = self::errors($out['errors']);
        }
        $result['masked'] = $masked;

        $bytes = strlen((string) wp_json_encode($result));
        if ($bytes > self::MAX_RESULT_BYTES) {
            throw new Operation_Refused('response_too_large', sprintf(
                'The result is %d bytes, over the %d byte limit. Ask for fewer items (first: 10) or fewer fields.',
                (int) $bytes,
                (int) self::MAX_RESULT_BYTES
            ), [ 'bytes' => (int) $bytes, 'limit' => (int) self::MAX_RESULT_BYTES ]);
        }
        return $result;
    }

    /**
     * GraphQL errors with their message, locations and path only.
     *
     * @param array<int, mixed> $errors
     * @return array<int, array<string, mixed>>
     */
    private static function errors(array $errors): array
    {
        $out = [];
        foreach ($errors as $error) {
            $error = (array) $error;
            $item  = [ 'message' => (string) ($error['message'] ?? '') ];
            foreach ([ 'locations', 'path' ] as $key) {
                if (isset($error[ $key ])) {
                    $item[ $key ] = $error[ $key ];
                }
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * A result with personal values masked: every value under a personal
     * key, and every email address wherever it appears.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function mask($value, string $key, bool &$masked)
    {
        if (is_array($value)) {
            foreach ($value as $child_key => $child) {
                $value[ $child_key ] = self::mask($child, is_string($child_key) ? $child_key : $key, $masked);
            }
            return $value;
        }
        if (! is_string($value) || '' === $value) {
            return $value;
        }
        if ('' !== $key && 1 === preg_match(self::PERSONAL_KEYS, $key)) {
            $masked = true;
            return Masking::value($value);
        }
        $replaced = preg_replace_callback(
            '/[^@\s<>"\'(),;:]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/',
            static fn (array $m): string => Masking::email($m[0]),
            $value
        );
        if (is_string($replaced) && $replaced !== $value) {
            $masked = true;
            return $replaced;
        }
        return $value;
    }

    /**
     * Root query fields and type names by kind, the mutation and
     * subscription roots left out.
     *
     * @return array<string, mixed>
     */
    private static function schema_summary(): array
    {
        $out = self::run([
            'query' => 'query WpmcpSchemaSummary { __schema { queryType { name fields { name description args { name type { ...WpmcpTypeRef } } type { ...WpmcpTypeRef } } } mutationType { name } subscriptionType { name } types { name kind } } } ' . self::TYPE_REF,
        ]);
        $schema = (array) ($out['data']['__schema'] ?? []);
        if ([] === $schema) {
            $errors = self::errors((array) ($out['errors'] ?? []));
            throw new Operation_Refused('schema_unavailable', 'WPGraphQL did not return its schema.', [ 'errors' => map_deep($errors, 'esc_html') ]);
        }

        $hidden = self::write_roots($schema);
        $types  = [];
        foreach ((array) ($schema['types'] ?? []) as $type) {
            $name = (string) ($type['name'] ?? '');
            if ('' === $name || 0 === strpos($name, '__') || in_array($name, $hidden, true)) {
                continue;
            }
            $types[ (string) ($type['kind'] ?? '') ][] = $name;
        }

        return [
            'query_type'   => (string) ($schema['queryType']['name'] ?? ''),
            'query_fields' => self::fields((array) ($schema['queryType']['fields'] ?? [])),
            'types'        => $types,
        ];
    }

    /**
     * One type's fields, input fields, enum values, interfaces and possible
     * types. The mutation and subscription roots are refused as unknown.
     *
     * @return array<string, mixed>
     */
    private static function describe_type(string $name): array
    {
        $out = self::run([
            'query'     => 'query WpmcpType($name: String!) { __schema { mutationType { name } subscriptionType { name } } __type(name: $name) { name kind description fields(includeDeprecated: false) { name description args { name type { ...WpmcpTypeRef } } type { ...WpmcpTypeRef } } inputFields { name description type { ...WpmcpTypeRef } } enumValues(includeDeprecated: false) { name } interfaces { name } possibleTypes { name } } } ' . self::TYPE_REF,
            'variables' => [ 'name' => $name ],
        ]);
        $type = $out['data']['__type'] ?? null;
        if (! is_array($type) || in_array($name, self::write_roots((array) ($out['data']['__schema'] ?? [])), true)) {
            throw new Operation_Refused('unknown_type', esc_html(sprintf('WPGraphQL has no query type named "%s". wpgraphql-get-schema without a type lists them.', $name)), [ 'type' => esc_html($name) ]);
        }

        $result = [
            'name'        => (string) ($type['name'] ?? $name),
            'kind'        => (string) ($type['kind'] ?? ''),
            'description' => self::description($type['description'] ?? ''),
        ];
        if (is_array($type['fields'] ?? null)) {
            $result['fields'] = self::fields($type['fields']);
        }
        if (is_array($type['inputFields'] ?? null)) {
            $result['input_fields'] = self::fields($type['inputFields']);
        }
        foreach ([ 'enumValues' => 'enum_values', 'interfaces' => 'interfaces', 'possibleTypes' => 'possible_types' ] as $from => $to) {
            if (is_array($type[ $from ] ?? null)) {
                $result[ $to ] = array_values(array_map(static fn ($item): string => (string) ((array) $item)['name'], $type[ $from ]));
            }
        }
        return $result;
    }

    /** Introspection fragment for a type reference three wrappers deep. */
    private const TYPE_REF = 'fragment WpmcpTypeRef on __Type { kind name ofType { kind name ofType { kind name ofType { kind name } } } }';

    /** @return string[] the names of the mutation and subscription root types */
    private static function write_roots(array $schema): array
    {
        $names = [];
        foreach ([ 'mutationType', 'subscriptionType' ] as $root) {
            $name = (string) ($schema[ $root ]['name'] ?? '');
            if ('' !== $name) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * Introspected fields as name, type (SDL notation), args and a short
     * description.
     *
     * @param array<int, mixed> $fields
     * @return array<int, array<string, mixed>>
     */
    private static function fields(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            $field = (array) $field;
            $args  = [];
            foreach ((array) ($field['args'] ?? []) as $arg) {
                $arg    = (array) $arg;
                $args[] = (string) ($arg['name'] ?? '') . ': ' . self::type_name((array) ($arg['type'] ?? []));
            }
            $out[] = [
                'name'        => (string) ($field['name'] ?? ''),
                'type'        => self::type_name((array) ($field['type'] ?? [])),
                'args'        => $args,
                'description' => self::description($field['description'] ?? ''),
            ];
        }
        return $out;
    }

    /** An introspected type reference in SDL notation ([Post!]!). */
    private static function type_name(array $type): string
    {
        $kind = (string) ($type['kind'] ?? '');
        if ('NON_NULL' === $kind) {
            return self::type_name((array) ($type['ofType'] ?? [])) . '!';
        }
        if ('LIST' === $kind) {
            return '[' . self::type_name((array) ($type['ofType'] ?? [])) . ']';
        }
        return (string) ($type['name'] ?? '');
    }

    /** @param mixed $description */
    private static function description($description): string
    {
        $text = trim((string) $description);
        return mb_strlen($text) > self::MAX_DESCRIPTION ? mb_substr($text, 0, self::MAX_DESCRIPTION - 3) . '...' : $text;
    }
}
