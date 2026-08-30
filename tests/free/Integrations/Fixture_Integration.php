<?php

namespace WPMCP\Tests\Free\Integrations;

use WPMCP\Integrations\Integration_Dispatcher;

/**
 * Test-only concrete integration exercising every framework surface: read,
 * write, destructive, default-off, per-op capability override, snapshotless
 * write, and switchable availability. The abstract base is unit-tested
 * entirely through this fixture, independent of any real integration.
 */
class Fixture_Integration extends Integration_Dispatcher
{
    /** Toggle for availability-detection tests. */
    public static bool $available = true;

    /** Toggle for the per-op 'requires' dependency hook. */
    public static bool $dependency_present = true;

    /** Every op handler invocation lands here so tests can assert "no side effects". */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$available          = true;
        self::$dependency_present = true;
        self::$calls              = [];
    }

    public function integration(): string
    {
        return 'testint';
    }

    public function is_available(): bool
    {
        return self::$available;
    }

    protected function operations(): array
    {
        return [
            'ping'           => [
                'mode'         => 'read',
                'description'  => 'Echo a value back',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'value' => [ 'type' => 'string' ] ],
                    'required'   => [ 'value' ],
                ],
                'handler'      => function (array $args) {
                    self::$calls[] = [ 'ping', $args ];
                    return [ 'pong' => $args['value'] ];
                },
            ],
            'set-content'    => [
                'mode'         => 'write',
                'description'  => 'Set a post\'s content',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'content' => [ 'type' => 'string' ],
                    ],
                    'required'   => [ 'post_id', 'content' ],
                ],
                'handler'      => function (array $args) {
                    self::$calls[] = [ 'set-content', $args ];
                    wp_update_post([ 'ID' => (int) $args['post_id'], 'post_content' => $args['content'] ]);
                    return [ 'post_id' => (int) $args['post_id'] ];
                },
                'snapshot'     => fn (array $args) => [
                    'object_type' => 'post',
                    'object_id'   => (int) $args['post_id'],
                ],
            ],
            'no-target-write' => [
                'mode'         => 'write',
                'description'  => 'A write with no snapshotable target',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => function (array $args) {
                    self::$calls[] = [ 'no-target-write', $args ];
                    return [ 'done' => true ];
                },
            ],
            'guarded-op'     => [
                'mode'         => 'write',
                'description'  => 'A write only admins may run',
                'capability'   => 'manage_options',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => function (array $args) {
                    self::$calls[] = [ 'guarded-op', $args ];
                    return [ 'done' => true ];
                },
            ],
            'default-off-op' => [
                'mode'               => 'write',
                'description'        => 'A write that sites must opt into',
                'enabled_by_default' => false,
                'input_schema'       => [ 'type' => 'object', 'properties' => [] ],
                'handler'            => function (array $args) {
                    self::$calls[] = [ 'default-off-op', $args ];
                    return [ 'done' => true ];
                },
            ],
            'needs-dependency' => [
                'mode'         => 'read',
                'description'  => 'A read that needs a companion plugin',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'requires'     => static fn () => self::$dependency_present ? true : [
                    'code'    => 'companion_unavailable',
                    'message' => 'The companion plugin is not active on this site.',
                ],
                'handler'      => function (array $args) {
                    self::$calls[] = [ 'needs-dependency', $args ];
                    return [ 'done' => true ];
                },
            ],
            'nuke'           => [
                'mode'         => 'destructive',
                'description'  => 'Destroy something irreversibly',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => function (array $args) {
                    self::$calls[] = [ 'nuke', $args ];
                    return [ 'nuked' => true ];
                },
            ],
        ];
    }
}
