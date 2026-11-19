<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Plugin_Data_Integration;
use WPMCP\Integrations\WPGraphQL_Pack;

/**
 * WPGraphQL (issue #704): read-only GraphQL queries and a schema summary as
 * free-tier ops on the plugin-data dispatcher pair, so no top-level tool is
 * added.
 *
 * WPGraphQL is not loaded in the shared test core. Presence is driven
 * through wpmcp_wpgraphql_active, and graphql() is replaced through
 * WPGraphQL_Pack::use_runner_for_tests(), which records every request the
 * pack would hand WPGraphQL.
 */
class WPGraphQLPackTest extends \WP_UnitTestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $requests = [];

    /** @var callable */
    private $answer;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create([ 'role' => 'editor' ]));
        add_filter('wpmcp_wpgraphql_active', '__return_true');
        $this->requests = [];
        $this->answer   = static fn (array $request): array => [ 'data' => [ 'posts' => [ 'nodes' => [] ] ] ];
        WPGraphQL_Pack::use_runner_for_tests(function (array $request): array {
            $this->requests[] = $request;
            return ($this->answer)($request);
        });
    }

    protected function tearDown(): void
    {
        WPGraphQL_Pack::use_runner_for_tests(null);
        remove_all_filters('wpmcp_wpgraphql_active');
        parent::tearDown();
    }

    private function read(string $op, array $args = []): array
    {
        return (new Plugin_Data_Integration())->handle_read([ 'operation' => $op, 'args' => $args ]);
    }

    private function ok(array $out): array
    {
        $this->assertArrayNotHasKey('error', $out, (string) wp_json_encode($out));
        return $out['result'] ?? $out;
    }

    private function code(array $out): ?string
    {
        return $out['error']['code'] ?? $out['code'] ?? null;
    }

    public function test_the_ops_are_free_tier_reads(): void
    {
        $ops = WPGraphQL_Pack::operations();
        $this->assertSame([ 'wpgraphql-query', 'wpgraphql-get-schema' ], array_keys($ops));
        foreach ($ops as $def) {
            $this->assertSame('read', $def['mode']);
            $this->assertSame('edit_posts', $def['capability']);
            $this->assertArrayNotHasKey('tier', $def);
        }
    }

    public function test_the_ops_are_on_the_read_half_only(): void
    {
        $out = (new Plugin_Data_Integration())->handle_write([ 'operation' => 'wpgraphql-query', 'args' => [ 'query' => '{ posts { nodes { id } } }' ] ]);
        $this->assertNotNull($this->code($out));
        $this->assertSame([], $this->requests);
    }

    public function test_a_query_runs_through_wpgraphql_with_its_variables_and_operation_name(): void
    {
        $this->answer = static fn (array $request): array => [
            'data'       => [ 'posts' => [ 'nodes' => [ [ 'title' => 'Hello' ] ] ] ],
            'extensions' => [ 'debug' => [ 'SELECT * FROM wp_posts' ] ],
        ];

        $result = $this->ok($this->read('wpgraphql-query', [
            'query'          => 'query Recent($n: Int) { posts(first: $n) { nodes { title } } }',
            'variables'      => [ 'n' => 3 ],
            'operation_name' => 'Recent',
        ]));

        $this->assertSame([ [ 'query' => 'query Recent($n: Int) { posts(first: $n) { nodes { title } } }', 'variables' => [ 'n' => 3 ], 'operationName' => 'Recent' ] ], $this->requests);
        $this->assertSame([ 'posts' => [ 'nodes' => [ [ 'title' => 'Hello' ] ] ] ], $result['data']);
        $this->assertArrayNotHasKey('extensions', $result, 'debug extensions (SQL query logs) are not returned');
    }

    public function test_a_query_runs_as_the_current_user(): void
    {
        $user         = get_current_user_id();
        $seen         = null;
        $this->answer = static function () use (&$seen): array {
            $seen = get_current_user_id();
            return [ 'data' => [] ];
        };
        $this->ok($this->read('wpgraphql-query', [ 'query' => '{ viewer { id } }' ]));
        $this->assertSame($user, $seen);
    }

    /**
     * @dataProvider writing_documents
     */
    public function test_mutations_and_subscriptions_are_refused_before_wpgraphql_runs(string $document): void
    {
        $out = $this->read('wpgraphql-query', [ 'query' => $document ]);
        $this->assertSame('mutation_refused', $this->code($out), (string) wp_json_encode($out));
        $this->assertSame([], $this->requests);
    }

    /** @return array<string, array{string}> */
    public static function writing_documents(): array
    {
        return [
            'mutation'                      => [ 'mutation { deletePost(input: {id: "1"}) { deletedId } }' ],
            'named mutation'                => [ 'mutation Drop($id: ID!) { deletePost(input: {id: $id}) { deletedId } }' ],
            'leading comment and spaces'    => [ "# just a read\n   mutation{deletePost(input:{id:\"1\"}){deletedId}}" ],
            'after a query'                 => [ "query A { posts { nodes { id } } }\nmutation B { deletePost(input: {id: \"1\"}) { deletedId } }" ],
            'second of two operations'      => [ 'query A { viewer { id } } mutation B { updateSettings(input: {}) { clientMutationId } }' ],
            'after a fragment'              => [ 'fragment F on Post { id } mutation { createPost(input: {title: "x"}) { post { ...F } } }' ],
            'subscription'                  => [ 'subscription { postUpdated { id } }' ],
            'comma separated'               => [ ',,mutation { deletePost(input: {id: "1"}) { deletedId } }' ],
        ];
    }

    /**
     * @dataProvider reading_documents
     */
    public function test_read_documents_that_mention_mutation_still_run(string $document): void
    {
        $this->ok($this->read('wpgraphql-query', [ 'query' => $document ]));
        $this->assertCount(1, $this->requests);
    }

    /** @return array<string, array{string}> */
    public static function reading_documents(): array
    {
        return [
            'shorthand query'          => [ '{ posts { nodes { id } } }' ],
            'word in a string'         => [ '{ posts(where: {search: "mutation { deletePost }"}) { nodes { id } } }' ],
            'word in a block string'   => [ "{ posts(where: {search: \"\"\"\nmutation { x }\n\"\"\"}) { nodes { id } } }" ],
            'word in a comment'        => [ "# mutation { deletePost }\n{ viewer { id } }" ],
            'field named mutation'     => [ 'query { mutation: viewer { id } }' ],
            'variable named mutation'  => [ 'query Q($mutation: String) { posts(where: {search: $mutation}) { nodes { id } } }' ],
            'type named subscription'  => [ 'query { __type(name: "subscription") { name } }' ],
        ];
    }

    public function test_an_empty_query_is_refused_by_the_schema(): void
    {
        $this->assertNotNull($this->code($this->read('wpgraphql-query', [ 'query' => '' ])));
        $this->assertSame([], $this->requests);
    }

    public function test_graphql_errors_come_back_without_debug_data(): void
    {
        $this->answer = static fn (): array => [
            'errors' => [ [
                'message'    => 'Cannot query field "nope" on type "RootQuery".',
                'locations'  => [ [ 'line' => 1, 'column' => 3 ] ],
                'extensions' => [ 'category' => 'graphql', 'debugMessage' => 'stack trace /var/www/wp-content/plugins' ],
            ] ],
        ];
        $result = $this->ok($this->read('wpgraphql-query', [ 'query' => '{ nope }' ]));
        $this->assertSame([ [ 'message' => 'Cannot query field "nope" on type "RootQuery".', 'locations' => [ [ 'line' => 1, 'column' => 3 ] ] ] ], $result['errors']);
        $this->assertArrayNotHasKey('data', $result);
    }

    public function test_emails_ips_and_phones_in_the_result_are_masked(): void
    {
        $this->answer = static fn (): array => [ 'data' => [ 'users' => [ 'nodes' => [
            [ 'name' => 'Ada', 'email' => 'ada@example.com', 'description' => 'reach me at ada@example.com' ],
            [ 'name' => 'Bob', 'email' => 'bob@example.org' ],
        ] ], 'comments' => [ 'nodes' => [ [ 'authorIp' => '203.0.113.9', 'content' => 'hi' ] ] ], 'contact' => 'grace@example.net', 'billingPhone' => '+1 555 0100' ] ];

        $result = $this->ok($this->read('wpgraphql-query', [ 'query' => '{ users { nodes { name email description } } }' ]));
        $json   = (string) wp_json_encode($result);

        $this->assertTrue($result['masked']);
        $this->assertSame('a***@example.com', $result['data']['users']['nodes'][0]['email']);
        $this->assertSame('b***@example.org', $result['data']['users']['nodes'][1]['email']);
        $this->assertSame('Ada', $result['data']['users']['nodes'][0]['name']);
        $this->assertSame('2***', $result['data']['comments']['nodes'][0]['authorIp']);
        $this->assertSame('hi', $result['data']['comments']['nodes'][0]['content']);
        $this->assertSame('g***@example.net', $result['data']['contact']);
        $this->assertSame('+***', $result['data']['billingPhone']);
        foreach ([ 'ada@example.com', 'bob@example.org', '203.0.113.9', 'grace@example.net', '555 0100' ] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_a_result_without_personal_data_is_not_flagged_masked(): void
    {
        $result = $this->ok($this->read('wpgraphql-query', [ 'query' => '{ posts { nodes { id } } }' ]));
        $this->assertFalse($result['masked']);
    }

    public function test_an_oversized_result_is_refused_with_a_hint(): void
    {
        $this->answer = static fn (): array => [ 'data' => [ 'posts' => [ 'nodes' => array_fill(0, 2000, [ 'content' => str_repeat('x', 200) ]) ] ] ];
        $out = $this->read('wpgraphql-query', [ 'query' => '{ posts(first: 100) { nodes { content } } }' ]);
        $this->assertSame('response_too_large', $this->code($out));
        $this->assertStringContainsString('first', (string) ($out['error']['message'] ?? ''));
    }

    public function test_without_wpgraphql_the_ops_refuse_naming_it(): void
    {
        remove_all_filters('wpmcp_wpgraphql_active');
        add_filter('wpmcp_wpgraphql_active', '__return_false');
        add_filter('wpmcp_jetengine_active', '__return_true');
        try {
            foreach ([ 'wpgraphql-query' => [ 'query' => '{ viewer { id } }' ], 'wpgraphql-get-schema' => [] ] as $op => $args) {
                $out = $this->read($op, $args);
                $this->assertSame('wpgraphql_inactive', $this->code($out));
                $this->assertStringContainsString('WPGraphQL', (string) $out['error']['message']);
            }
        } finally {
            remove_all_filters('wpmcp_jetengine_active');
        }
        $this->assertSame([], $this->requests);
    }

    public function test_the_schema_summary_lists_root_query_fields_and_types_without_mutations(): void
    {
        $this->answer = static fn (): array => [ 'data' => [ '__schema' => [
            'queryType' => [ 'name' => 'RootQuery', 'fields' => [
                [
                    'name'        => 'posts',
                    'description' => 'Connection between the RootQuery type and the post type',
                    'args'        => [ [ 'name' => 'first', 'type' => [ 'kind' => 'SCALAR', 'name' => 'Int', 'ofType' => null ] ] ],
                    'type'        => [ 'kind' => 'OBJECT', 'name' => 'RootQueryToPostConnection', 'ofType' => null ],
                ],
                [
                    'name'        => 'post',
                    'description' => null,
                    'args'        => [ [ 'name' => 'id', 'type' => [ 'kind' => 'NON_NULL', 'name' => null, 'ofType' => [ 'kind' => 'SCALAR', 'name' => 'ID', 'ofType' => null ] ] ] ],
                    'type'        => [ 'kind' => 'OBJECT', 'name' => 'Post', 'ofType' => null ],
                ],
            ] ],
            'mutationType' => [ 'name' => 'RootMutation' ],
            'types'        => [
                [ 'name' => 'Post', 'kind' => 'OBJECT' ],
                [ 'name' => 'PostStatusEnum', 'kind' => 'ENUM' ],
                [ 'name' => '__Schema', 'kind' => 'OBJECT' ],
                [ 'name' => 'RootMutation', 'kind' => 'OBJECT' ],
            ],
        ] ] ];

        $result = $this->ok($this->read('wpgraphql-get-schema'));

        $this->assertSame('RootQuery', $result['query_type']);
        $this->assertSame([
            [ 'name' => 'posts', 'type' => 'RootQueryToPostConnection', 'args' => [ 'first: Int' ], 'description' => 'Connection between the RootQuery type and the post type' ],
            [ 'name' => 'post', 'type' => 'Post', 'args' => [ 'id: ID!' ], 'description' => '' ],
        ], $result['query_fields']);
        $this->assertSame([ 'OBJECT' => [ 'Post' ], 'ENUM' => [ 'PostStatusEnum' ] ], $result['types']);
        $this->assertStringNotContainsString('RootMutation', (string) wp_json_encode($result));
    }

    public function test_the_schema_of_one_type_lists_its_fields(): void
    {
        $this->answer = static fn (array $request): array => [ 'data' => [ '__type' => [
            'name'          => 'Post',
            'kind'          => 'OBJECT',
            'description'   => 'The post type',
            'fields'        => [
                [ 'name' => 'title', 'description' => 'The title', 'args' => [], 'type' => [ 'kind' => 'SCALAR', 'name' => 'String', 'ofType' => null ] ],
                [ 'name' => 'tags', 'description' => '', 'args' => [], 'type' => [ 'kind' => 'NON_NULL', 'name' => null, 'ofType' => [ 'kind' => 'LIST', 'name' => null, 'ofType' => [ 'kind' => 'OBJECT', 'name' => 'Tag', 'ofType' => null ] ] ] ],
            ],
            'inputFields'   => null,
            'enumValues'    => null,
            'interfaces'    => [ [ 'name' => 'Node' ] ],
            'possibleTypes' => null,
        ] ] ];

        $result = $this->ok($this->read('wpgraphql-get-schema', [ 'type' => 'Post' ]));

        $this->assertSame([ 'name' => 'Post' ], $this->requests[0]['variables']);
        $this->assertSame('Post', $result['name']);
        $this->assertSame('OBJECT', $result['kind']);
        $this->assertSame([ 'Node' ], $result['interfaces']);
        $this->assertSame([
            [ 'name' => 'title', 'type' => 'String', 'args' => [], 'description' => 'The title' ],
            [ 'name' => 'tags', 'type' => '[Tag]!', 'args' => [], 'description' => '' ],
        ], $result['fields']);
    }

    public function test_an_unknown_type_is_refused(): void
    {
        $this->answer = static fn (): array => [ 'data' => [ '__type' => null ] ];
        $this->assertSame('unknown_type', $this->code($this->read('wpgraphql-get-schema', [ 'type' => 'Nope' ])));
    }

    public function test_the_root_mutation_type_is_not_described(): void
    {
        $this->answer = static fn (): array => [ 'data' => [ '__schema' => [ 'mutationType' => [ 'name' => 'RootMutation' ] ], '__type' => [ 'name' => 'RootMutation', 'kind' => 'OBJECT', 'fields' => [] ] ] ];
        $this->assertSame('unknown_type', $this->code($this->read('wpgraphql-get-schema', [ 'type' => 'RootMutation' ])));
    }

    public function test_a_subscriber_is_refused(): void
    {
        wp_set_current_user(self::factory()->user->create([ 'role' => 'subscriber' ]));
        $this->assertSame('operation_denied', $this->code($this->read('wpgraphql-query', [ 'query' => '{ viewer { id } }' ])));
        $this->assertSame([], $this->requests);
    }
}
