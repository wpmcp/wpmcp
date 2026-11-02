<?php

namespace WPMCP\Integrations;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce Memberships integration (wpmcp/wc-memberships-read / -write pair).
 *
 * Membership plans are wc_membership_plan posts and user memberships are
 * wc_user_membership posts (post_parent = plan, post_status = wcm-{status}),
 * so every membership write snapshots the membership post and rolls back
 * through the ordinary post restore. Reads go through the host plugin's
 * public functions (wc_memberships_get_membership_plans,
 * wc_memberships_get_user_memberships, wc_memberships_get_user_membership)
 * and writes through WC_Memberships_User_Membership::pause() / activate() /
 * cancel() / set_end_date(), all behind function_exists guards.
 *
 * The plugin API is reached through a small adapter so the test suite, which
 * runs without the host plugin, can inject a fake via use_api().
 */
class WC_Memberships_Integration extends Integration_Dispatcher
{
    private const STATUSES = [ 'active', 'free_trial', 'delayed', 'complimentary', 'pending', 'paused', 'expired', 'cancelled' ];

    private static ?object $api_override = null;

    /** Test seam: inject a fake API (null restores live resolution). */
    public static function use_api(?object $api): void
    {
        self::$api_override = $api;
    }

    public function integration(): string
    {
        return 'wc-memberships';
    }

    public function is_available(): bool
    {
        return null !== self::$api_override || (
            function_exists('wc_memberships_get_membership_plans')
            && function_exists('wc_memberships_get_user_memberships')
            && function_exists('wc_memberships_get_user_membership')
        );
    }

    protected function summary(): string
    {
        return 'WooCommerce Memberships (plans with access rules and member counts, members by status, a user\'s memberships; grant, pause, resume, cancel and extend memberships)';
    }

    private static function api(): object
    {
        if (null !== self::$api_override) {
            return self::$api_override;
        }
        return new class {
            public function plans(): array
            {
                return function_exists('wc_memberships_get_membership_plans') ? (array) wc_memberships_get_membership_plans() : [];
            }

            public function plan_members(int $plan_id, ?string $status): array
            {
                $ids = get_posts([
                    'post_type'      => 'wc_user_membership',
                    'post_parent'    => $plan_id,
                    'post_status'    => null === $status ? 'any' : 'wcm-' . $status,
                    'posts_per_page' => -1,
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                ]);
                $out = [];
                foreach ((array) $ids as $id) {
                    $m = $this->membership((int) $id);
                    if (null !== $m) {
                        $out[] = $m;
                    }
                }
                return $out;
            }

            public function user_memberships(int $user_id): array
            {
                return function_exists('wc_memberships_get_user_memberships') ? (array) wc_memberships_get_user_memberships($user_id) : [];
            }

            public function membership(int $id): ?object
            {
                if (! function_exists('wc_memberships_get_user_membership')) {
                    return null;
                }
                $m = wc_memberships_get_user_membership($id);
                return is_object($m) ? $m : null;
            }

            /** @return object|\WP_Error */
            public function create(int $user_id, int $plan_id)
            {
                if (! function_exists('wc_memberships_create_user_membership')) {
                    return new \WP_Error('unavailable', 'wc_memberships_create_user_membership() is not defined');
                }
                try {
                    $m = wc_memberships_create_user_membership([ 'plan_id' => $plan_id, 'user_id' => $user_id ]);
                } catch (\Throwable $e) {
                    return new \WP_Error('create_failed', $e->getMessage());
                }
                return is_object($m) ? $m : new \WP_Error('create_failed', 'the plugin returned no membership');
            }
        };
    }

    private static function call(object $o, string $method, $default = null)
    {
        return method_exists($o, $method) ? $o->$method() : $default;
    }

    private static function rules(object $plan, string $getter): array
    {
        $out = [];
        foreach ((array) self::call($plan, $getter, []) as $rule) {
            if (! is_object($rule)) {
                continue;
            }
            $out[] = [
                'content_type'      => (string) self::call($rule, 'get_content_type', ''),
                'content_type_name' => (string) self::call($rule, 'get_content_type_name', ''),
                'object_ids'        => array_map('intval', (array) self::call($rule, 'get_object_ids', [])),
            ];
        }
        return $out;
    }

    private static function plan_row(object $plan): array
    {
        $id = (int) self::call($plan, 'get_id', 0);
        return [
            'id'                         => $id,
            'name'                       => (string) self::call($plan, 'get_name', ''),
            'slug'                       => (string) self::call($plan, 'get_slug', ''),
            'access_method'              => (string) self::call($plan, 'get_access_method', ''),
            'access_length'              => (string) self::call($plan, 'get_access_length', ''),
            'members'                    => count(self::api()->plan_members($id, null)),
            'active_members'             => count(self::api()->plan_members($id, 'active')),
            'content_restriction_rules'  => self::rules($plan, 'get_content_restriction_rules'),
            'product_restriction_rules'  => self::rules($plan, 'get_product_restriction_rules'),
            'purchasing_discount_rules'  => self::rules($plan, 'get_purchasing_discount_rules'),
        ];
    }

    private static function membership_row(object $m): array
    {
        return [
            'id'         => (int) self::call($m, 'get_id', 0),
            'user_id'    => (int) self::call($m, 'get_user_id', 0),
            'plan_id'    => (int) self::call($m, 'get_plan_id', 0),
            'status'     => (string) self::call($m, 'get_status', ''),
            'start_date' => self::call($m, 'get_start_date'),
            'end_date'   => self::call($m, 'get_end_date'),
        ];
    }

    private static function membership_schema(array $extra = []): array
    {
        return [
            'type'       => 'object',
            'properties' => [ 'membership_id' => [ 'type' => 'integer', 'minimum' => 1 ] ] + $extra,
            'required'   => array_merge([ 'membership_id' ], array_keys($extra)),
        ];
    }

    private static function must_exist(array $args): ?array
    {
        if (null === self::api()->membership((int) $args['membership_id'])) {
            return [
                'code'    => 'not_found',
                'message' => sprintf('No user membership with id %d.', (int) $args['membership_id']),
                'data'    => [ 'membership_id' => (int) $args['membership_id'] ],
            ];
        }
        return null;
    }

    private static function snapshot_target(array $args): array
    {
        return [ 'object_type' => 'post', 'object_id' => (int) $args['membership_id'] ];
    }

    private function membership_write(string $description, string $method, array $extra = [], ?callable $arg = null): array
    {
        return [
            'mode'         => 'write',
            'description'  => $description,
            'input_schema' => self::membership_schema($extra),
            'validate'     => fn (array $args) => self::must_exist($args),
            'snapshot'     => fn (array $args) => self::snapshot_target($args),
            'handler'      => function (array $args) use ($method, $arg): array {
                $m = self::api()->membership((int) $args['membership_id']);
                if (null === $m || ! method_exists($m, $method)) {
                    throw new Operation_Refused('not_supported', sprintf('The installed plugin does not expose %s() on user memberships.', $method));
                }
                null === $arg ? $m->$method() : $m->$method($arg($args));
                return [ 'membership' => self::membership_row($m) ];
            },
        ];
    }

    protected function operations(): array
    {
        $status = [ 'type' => 'string', 'enum' => self::STATUSES ];
        return [
            'list-plans'           => [
                'mode'         => 'read',
                'description'  => 'List membership plans with id, name, slug, access method and length, member counts (all and active) and their content, product and discount rules',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => function (): array {
                    $plans = array_map([ self::class, 'plan_row' ], array_values(array_filter(self::api()->plans(), 'is_object')));
                    return [ 'plans' => $plans, 'total' => count($plans) ];
                },
            ],
            'list-members'         => [
                'mode'         => 'read',
                'description'  => 'List the user memberships of one plan, optionally narrowed to one status (active, paused, expired, cancelled, ...)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'plan_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'status' => $status ],
                    'required'   => [ 'plan_id' ],
                ],
                'handler'      => function (array $args): array {
                    $rows = array_map([ self::class, 'membership_row' ], self::api()->plan_members((int) $args['plan_id'], $args['status'] ?? null));
                    return [ 'members' => $rows, 'total' => count($rows) ];
                },
            ],
            'get-user-memberships' => [
                'mode'         => 'read',
                'description'  => 'Read every membership a user holds, with plan, status, start and end date',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'user_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'user_id' ],
                ],
                'handler'      => function (array $args): array {
                    $rows = array_map([ self::class, 'membership_row' ], array_values(array_filter(self::api()->user_memberships((int) $args['user_id']), 'is_object')));
                    return [ 'memberships' => $rows, 'total' => count($rows) ];
                },
            ],
            'grant-membership'     => [
                'mode'         => 'write',
                'description'  => 'Grant a user a membership in a plan (creates a new user membership; not snapshotted, cancel it to undo)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'user_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'plan_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    ],
                    'required'   => [ 'user_id', 'plan_id' ],
                ],
                'handler'      => function (array $args): array {
                    $m = self::api()->create((int) $args['user_id'], (int) $args['plan_id']);
                    if ($m instanceof \WP_Error) {
                        throw new Operation_Refused('create_failed', $m->get_error_message());
                    }
                    return [ 'membership' => self::membership_row($m) ];
                },
            ],
            'pause-membership'     => $this->membership_write('Pause an active membership (snapshotted)', 'pause'),
            'resume-membership'    => $this->membership_write('Resume (re-activate) a paused membership (snapshotted)', 'activate'),
            'extend-membership'    => $this->membership_write(
                'Set a membership\'s end date (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS, site timezone); snapshotted',
                'set_end_date',
                [ 'end_date' => [ 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$' ] ],
                static fn (array $args): string => (string) $args['end_date']
            ),
            'cancel-membership'    => [
                'mode'            => 'destructive',
                'description'     => 'Cancel a membership (snapshotted; requires confirm:true)',
                'input_schema'    => self::membership_schema(),
                'validate'        => fn (array $args) => self::must_exist($args),
                'confirm_preview' => function (array $args): array {
                    $m = self::api()->membership((int) $args['membership_id']);
                    return [ 'would_cancel' => null === $m ? null : self::membership_row($m) ];
                },
                'snapshot'        => fn (array $args) => self::snapshot_target($args),
                'handler'         => function (array $args): array {
                    $m = self::api()->membership((int) $args['membership_id']);
                    if (null === $m || ! method_exists($m, 'cancel')) {
                        throw new Operation_Refused('not_supported', 'The installed plugin does not expose cancel() on user memberships.');
                    }
                    $m->cancel();
                    return [ 'membership' => self::membership_row($m) ];
                },
            ],
        ];
    }
}
