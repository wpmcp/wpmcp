<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;
use WPMCP\Safety\Gamipress_User_Snapshot;
use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Save_Filters;
use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * GamiPress (issue #833) on the plugin-data pair, paid tier, so it adds no
 * top-level tools. Ops are prefixed gamipress- because the pair is shared.
 *
 * GamiPress 7 keeps its types as posts (points-type, achievement-type,
 * rank-type) registered into GamiPress()->points_types and the like, read
 * here through gamipress_get_points_types() and its two siblings. Each
 * achievement type and rank type is a post type of its own (slug = type),
 * an achievement keeps its settings in _gamipress_* post meta (points,
 * points_type, earned_by, maximum_earnings, hidden) and a rank's priority is
 * its menu_order. A user's balance per points type is the user meta
 * _gamipress_<type>_points, their rank per rank type _gamipress_<type>_rank,
 * and everything they earned is a row of the gamipress_user_earnings table;
 * every change is also a row of gamipress_logs.
 *
 * Reads: list-point-types, list-achievement-types, list-rank-types,
 * list-achievements, list-ranks, get-user-points, get-user-achievements and
 * get-user-rank. They return user ids and GamiPress data only, no names or
 * emails, so nothing needs masking.
 *
 * Writes are off until a site opts in with wpmcp_enable_gamipress_write.
 * Points, achievement and rank writes go through GamiPress's own functions
 * (the ones its bulk award and user profile screens call), so its triggers,
 * logs, points-based achievements and rank changes all run; each is under
 * one gamipress_user snapshot of the target user (Gamipress_User_Snapshot:
 * user meta, earnings rows and new log rows), so rollback-operation puts all
 * of it back. Mail GamiPress sends for an award is not undone.
 * create-achievement inserts an admin-awarded achievement as a draft unless
 * told otherwise (undo trashes it); update-achievement runs under a post
 * snapshot.
 *
 * Presence is filterable (wpmcp_gamipress_active, default: GAMIPRESS_VER is
 * defined). Every op takes GamiPress's manager capability
 * (gamipress_get_manager_capability(), manage_options by default).
 */
final class Gamipress_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const OPT_IN_FILTER = 'wpmcp_enable_gamipress_write';

    public const PLUGIN_FILES = [ 'gamipress/gamipress.php' ];

    public const MAX_PAGE_SIZE = 100;

    public const MAX_POINTS = 1000000000;

    private const STATUSES = [ 'publish', 'draft', 'pending', 'private' ];

    private const EARNED_BY = [ 'triggers', 'points', 'rank', 'admin' ];

    public static function plugin_loaded(): bool
    {
        return (bool) apply_filters('wpmcp_gamipress_active', defined('GAMIPRESS_VER'));
    }

    /** Whether GamiPress is loaded and this install may run the paid tier. */
    public static function active(): bool
    {
        return Registrar::tier_permitted('pro') && self::plugin_loaded();
    }

    /** @return true|array{code: string, message: string, data: array} */
    public static function presence()
    {
        if (self::plugin_loaded()) {
            return true;
        }
        $installed = false;
        foreach (self::PLUGIN_FILES as $file) {
            $installed = $installed || (defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/' . $file));
        }
        $url = admin_url('plugins.php');

        return [
            'code'    => $installed ? 'plugin_inactive' : 'plugin_missing',
            'message' => sprintf(
                'GamiPress is %s on this site. Install and activate GamiPress (%s), then try again.',
                $installed ? 'installed but not active' : 'not installed',
                $url
            ),
            'data'    => [ 'plugin' => 'gamipress', 'plugins_url' => $url ],
        ];
    }

    /** GamiPress's own manager capability (gamipress_get_manager_capability(), manage_options by default). */
    public static function capability(): string
    {
        $cap = function_exists('gamipress_get_manager_capability') ? (string) gamipress_get_manager_capability() : '';
        return '' !== $cap ? $cap : 'manage_options';
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id     = [ 'type' => 'integer', 'minimum' => 1 ];
        $slug   = [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 50 ];
        $page   = [
            'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
            'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE ],
        ];
        $user   = [ 'type' => 'object', 'properties' => [ 'user_id' => $id ], 'required' => [ 'user_id' ] ];
        $points = [
            'type'                 => 'object',
            'properties'           => [
                'user_id'     => $id,
                'points'      => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_POINTS ],
                'points_type' => $slug,
                'reason'      => [ 'type' => 'string', 'maxLength' => 500 ],
            ],
            'required'             => [ 'user_id', 'points', 'points_type' ],
            'additionalProperties' => false,
        ];
        $fields = [
            'title'            => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 500 ],
            'content'          => [ 'type' => 'string', 'maxLength' => 100000 ],
            'status'           => [ 'type' => 'string', 'enum' => self::STATUSES ],
            'points'           => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => self::MAX_POINTS ],
            'points_type'      => $slug,
            'earned_by'        => [ 'type' => 'string', 'enum' => self::EARNED_BY ],
            'maximum_earnings' => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 100000 ],
            'hidden'           => [ 'type' => 'boolean' ],
        ];
        $base   = [
            'tier'       => 'pro',
            'capability' => self::capability(),
            'requires'   => static fn () => self::presence(),
        ];
        $read   = $base + [ 'mode' => 'read' ];
        $write  = $base + [
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_gamipress_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $reader = [ 'user_id' => [ 'type' => 'user', 'access' => 'read' ] ];
        $target = [ 'user_id' => [ 'type' => 'user', 'access' => 'write' ] ];
        $state  = static fn (array $args): array => [ 'object_type' => Gamipress_User_Snapshot::TYPE, 'object_id' => (string) (int) $args['user_id'] ];
        $types  = static fn (string $kind, string $what): array => $read + [
            'description'  => "List GamiPress {$what} types: slug, id, singular and plural names",
            'input_schema' => [ 'type' => 'object' ],
            'validate'     => static fn (): ?array => self::cap_refusal(),
            'handler'      => static fn (): array => [ 'types' => self::types($kind) ],
        ];

        return [
            'gamipress-list-point-types'       => $types('points', 'points'),
            'gamipress-list-achievement-types' => $types('achievement', 'achievement'),
            'gamipress-list-rank-types'        => $types('rank', 'rank'),
            'gamipress-list-achievements'      => $read + [
                'description'  => 'List GamiPress achievements by title: id, title, type, status, points, points_type, earned_by, maximum_earnings, hidden. Filter by type (achievement type slug), status, search; page, per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'type' => $slug, 'status' => [ 'type' => 'string', 'enum' => self::STATUSES ], 'search' => [ 'type' => 'string', 'maxLength' => 200 ] ] + $page,
                ],
                'validate'     => static fn (array $args): ?array => self::cap_refusal() ?? self::type_refusal('achievement', $args['type'] ?? null),
                'handler'      => static fn (array $args): array => self::list_achievements($args),
            ],
            'gamipress-list-ranks'             => $read + [
                'description'  => 'List GamiPress ranks lowest priority first: id, title, type, status, priority. Filter by type (rank type slug); page, per_page (max 100)',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'type' => $slug ] + $page ],
                'validate'     => static fn (array $args): ?array => self::cap_refusal() ?? self::type_refusal('rank', $args['type'] ?? null),
                'handler'      => static fn (array $args): array => self::list_ranks($args),
            ],
            'gamipress-get-user-points'        => $read + [
                'objects'      => $reader,
                'description'  => "A user's GamiPress balance per points type, with totals awarded and deducted. points_type narrows it to one",
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'user_id' => $id, 'points_type' => $slug ], 'required' => [ 'user_id' ] ],
                'validate'     => static fn (array $args): ?array => self::user_refusal((int) $args['user_id']) ?? self::type_refusal('points', $args['points_type'] ?? null),
                'handler'      => static fn (array $args): array => self::user_points((int) $args['user_id'], $args['points_type'] ?? null),
            ],
            'gamipress-get-user-achievements'  => $read + [
                'objects'      => $reader,
                'description'  => "A user's earned GamiPress achievements newest first: earning id, achievement id, title, type, points, date. type narrows to one achievement type; page, per_page (max 100)",
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'user_id' => $id, 'type' => $slug ] + $page, 'required' => [ 'user_id' ] ],
                'validate'     => static fn (array $args): ?array => self::user_refusal((int) $args['user_id']) ?? self::type_refusal('achievement', $args['type'] ?? null),
                'handler'      => static fn (array $args): array => self::user_achievements($args),
            ],
            'gamipress-get-user-rank'          => $read + [
                'objects'      => $reader,
                'description'  => "A user's current GamiPress rank per rank type: rank id, title, priority, earned time. rank_type narrows to one",
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'user_id' => $id, 'rank_type' => $slug ], 'required' => [ 'user_id' ] ],
                'validate'     => static fn (array $args): ?array => self::user_refusal((int) $args['user_id']) ?? self::type_refusal('rank', $args['rank_type'] ?? null),
                'handler'      => static fn (array $args): array => self::user_ranks((int) $args['user_id'], $args['rank_type'] ?? null),
            ],
            'gamipress-create-achievement'     => $write + [
                'self_snapshotting' => true,
                'description'       => 'Create a GamiPress achievement: type (achievement type slug) and title required; status (default draft), content, points and points_type awarded, earned_by (default admin, admin-awarded only), maximum_earnings per user (default 1), hidden. Undo: rollback-session trashes it',
                'input_schema'      => [
                    'type'                 => 'object',
                    'properties'           => [ 'type' => $slug ] + $fields,
                    'required'             => [ 'type', 'title' ],
                    'additionalProperties' => false,
                ],
                'validate'          => static fn (array $args): ?array => self::cap_refusal() ?? self::type_refusal('achievement', (string) $args['type']) ?? self::type_refusal('points', $args['points_type'] ?? null),
                'handler'           => static fn (array $args, array $context): array => self::create($args, $context),
            ],
            'gamipress-update-achievement'     => $write + [
                'objects'      => [ 'id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'write' ] ],
                'description'  => 'Change a GamiPress achievement; only the args given change. Undo: rollback-operation',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [ 'id' => $id ] + $fields,
                    'required'             => [ 'id' ],
                    'minProperties'        => 2,
                    'additionalProperties' => false,
                ],
                'validate'     => static fn (array $args): ?array => self::achievement_refusal((int) $args['id'], false) ?? self::type_refusal('points', $args['points_type'] ?? null),
                'snapshot'     => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['id'] ],
                'handler'      => static fn (array $args): array => self::update($args),
            ],
            'gamipress-award-points'           => $write + [
                'objects'      => $target,
                'description'  => 'Award points of a points type to a user, through GamiPress (logged as awarded by you; points-based achievements and ranks it unlocks follow). Undo: rollback-operation',
                'input_schema' => $points,
                'validate'     => static fn (array $args): ?array => self::user_refusal((int) $args['user_id']) ?? self::type_refusal('points', (string) $args['points_type']),
                'snapshot'     => $state,
                'handler'      => static fn (array $args): array => self::move_points($args, 1),
            ],
            'gamipress-deduct-points'          => $write + [
                'objects'      => $target,
                'description'  => 'Deduct points of a points type from a user, through GamiPress; refused when the balance is lower. Undo: rollback-operation',
                'input_schema' => $points,
                'validate'     => static fn (array $args): ?array => self::deduct_refusal($args),
                'snapshot'     => $state,
                'handler'      => static fn (array $args): array => self::move_points($args, -1),
            ],
            'gamipress-award-achievement'      => $write + [
                'objects'      => $target + [ 'achievement_id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'read' ] ],
                'description'  => 'Award a published GamiPress achievement to a user, through GamiPress (its points and anything it unlocks follow). Undo: rollback-operation',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'user_id' => $id, 'achievement_id' => $id ], 'required' => [ 'user_id', 'achievement_id' ], 'additionalProperties' => false ],
                'validate'     => static fn (array $args): ?array => self::user_refusal((int) $args['user_id']) ?? self::achievement_refusal((int) $args['achievement_id'], true),
                'snapshot'     => $state,
                'handler'      => static fn (array $args): array => self::award((int) $args['user_id'], (int) $args['achievement_id']),
            ],
            'gamipress-revoke-achievement'     => $write + [
                'objects'      => $target + [ 'achievement_id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'read' ] ],
                'description'  => "Revoke one earning of a GamiPress achievement from a user, through GamiPress (the points it gave are deducted). Undo: rollback-operation",
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'user_id' => $id, 'achievement_id' => $id ], 'required' => [ 'user_id', 'achievement_id' ], 'additionalProperties' => false ],
                'validate'     => static fn (array $args): ?array => self::revoke_refusal((int) $args['user_id'], (int) $args['achievement_id']),
                'snapshot'     => $state,
                'handler'      => static fn (array $args): array => self::revoke((int) $args['user_id'], (int) $args['achievement_id']),
            ],
            'gamipress-set-user-rank'          => $write + [
                'objects'      => $target + [ 'rank_id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'read' ] ],
                'description'  => "Set a user's rank in the rank's type to a published GamiPress rank, through GamiPress. Undo: rollback-operation",
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'user_id' => $id, 'rank_id' => $id ], 'required' => [ 'user_id', 'rank_id' ], 'additionalProperties' => false ],
                'validate'     => static fn (array $args): ?array => self::user_refusal((int) $args['user_id']) ?? self::rank_refusal((int) $args['rank_id']),
                'snapshot'     => $state,
                'handler'      => static fn (array $args): array => self::set_rank((int) $args['user_id'], (int) $args['rank_id']),
            ],
        ];
    }

    // ------------------------------------------------------------ refusals

    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    private static function cap_refusal(): ?array
    {
        $cap = self::capability();
        return current_user_can($cap) ? null : self::refusal('operation_denied', sprintf('This needs the %s capability (GamiPress\'s manager capability).', $cap), [ 'reason' => 'capability', 'capability' => $cap ]);
    }

    private static function user_refusal(int $user): ?array
    {
        $refused = self::cap_refusal();
        if (null !== $refused) {
            return $refused;
        }
        return get_userdata($user) instanceof \WP_User ? null : self::refusal('user_not_found', sprintf('%d is not a user on this site.', $user), [ 'user_id' => $user ]);
    }

    /** A null slug passes (the arg was not given). */
    private static function type_refusal(string $kind, $slug): ?array
    {
        if (null === $slug) {
            return null;
        }
        $slug = (string) $slug;
        if (isset(self::type_map($kind)[ $slug ])) {
            return null;
        }
        return self::refusal(
            $kind . '_type_not_found',
            sprintf('%s is not a GamiPress %s type. gamipress-list-%s-types lists them.', $slug, $kind, 'points' === $kind ? 'point' : $kind),
            [ 'type' => $slug, 'types' => array_keys(self::type_map($kind)) ]
        );
    }

    private static function achievement_refusal(int $id, bool $published): ?array
    {
        $refused = self::cap_refusal();
        if (null !== $refused) {
            return $refused;
        }
        $post = get_post($id);
        if (! $post instanceof \WP_Post || ! isset(self::type_map('achievement')[ $post->post_type ]) || 'trash' === $post->post_status) {
            return self::refusal('achievement_not_found', sprintf('%d is not a GamiPress achievement.', $id), [ 'id' => $id ]);
        }
        if ($published && 'publish' !== $post->post_status) {
            return self::refusal('achievement_not_published', sprintf('Achievement %d is %s; only a published achievement can be awarded.', $id, $post->post_status), [ 'id' => $id, 'status' => $post->post_status ]);
        }
        return null;
    }

    private static function rank_refusal(int $id): ?array
    {
        $post = get_post($id);
        if (! $post instanceof \WP_Post || ! isset(self::type_map('rank')[ $post->post_type ]) || 'publish' !== $post->post_status) {
            return self::refusal('rank_not_found', sprintf('%d is not a published GamiPress rank.', $id), [ 'id' => $id ]);
        }
        return null;
    }

    private static function deduct_refusal(array $args): ?array
    {
        $user = (int) $args['user_id'];
        $type = (string) $args['points_type'];
        $refused = self::user_refusal($user) ?? self::type_refusal('points', $type);
        if (null !== $refused) {
            return $refused;
        }
        $balance = self::balance($user, $type);
        if ((int) $args['points'] > $balance) {
            return self::refusal('points_insufficient', sprintf('User %d has %d %s, fewer than the %d to deduct.', $user, $balance, $type, (int) $args['points']), [ 'balance' => $balance ]);
        }
        return null;
    }

    private static function revoke_refusal(int $user, int $achievement): ?array
    {
        $refused = self::user_refusal($user) ?? self::achievement_refusal($achievement, false);
        if (null !== $refused) {
            return $refused;
        }
        if (0 === self::earned_count($user, $achievement)) {
            return self::refusal('not_earned', sprintf('User %d has not earned achievement %d.', $user, $achievement), [ 'user_id' => $user, 'achievement_id' => $achievement ]);
        }
        return null;
    }

    // ------------------------------------------------------------ reads

    /**
     * Registered types of a kind, slug => GamiPress's entry (ID,
     * singular_name, plural_name).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function type_map(string $kind): array
    {
        $fn = [ 'points' => 'gamipress_get_points_types', 'achievement' => 'gamipress_get_achievement_types', 'rank' => 'gamipress_get_rank_types' ][ $kind ] ?? '';
        $types = '' !== $fn && function_exists($fn) ? $fn() : [];
        return is_array($types) ? $types : [];
    }

    /** @return array<int, array<string, mixed>> */
    private static function types(string $kind): array
    {
        $out = [];
        foreach (self::type_map($kind) as $slug => $data) {
            $data  = (array) $data;
            $out[] = [
                'slug'     => (string) $slug,
                'id'       => (int) ($data['ID'] ?? 0),
                'singular' => (string) ($data['singular_name'] ?? ''),
                'plural'   => (string) ($data['plural_name'] ?? ''),
            ];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function view_achievement(\WP_Post $post): array
    {
        $id = (int) $post->ID;
        return [
            'id'               => $id,
            'title'            => (string) $post->post_title,
            'type'             => (string) $post->post_type,
            'status'           => (string) $post->post_status,
            'points'           => (int) get_post_meta($id, '_gamipress_points', true),
            'points_type'      => (string) get_post_meta($id, '_gamipress_points_type', true),
            'earned_by'        => (string) get_post_meta($id, '_gamipress_earned_by', true),
            'maximum_earnings' => (int) get_post_meta($id, '_gamipress_maximum_earnings', true),
            'hidden'           => 'hidden' === get_post_meta($id, '_gamipress_hidden', true),
        ];
    }

    /** @return array<string, mixed> */
    private static function list_achievements(array $args): array
    {
        $types = isset($args['type']) ? [ (string) $args['type'] ] : array_keys(self::type_map('achievement'));
        if ([] === $types) {
            return [ 'achievements' => [], 'total' => 0 ];
        }
        $query = [
            'post_type'      => $types,
            'post_status'    => isset($args['status']) ? [ (string) $args['status'] ] : self::STATUSES,
            'posts_per_page' => (int) ($args['per_page'] ?? 20),
            'paged'          => (int) ($args['page'] ?? 1),
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $query['s'] = (string) $args['search'];
        }
        $q = Readable_Posts::query($query);
        return [ 'achievements' => array_map(static fn (\WP_Post $p): array => self::view_achievement($p), $q->posts), 'total' => (int) $q->found_posts ];
    }

    /** @return array<string, mixed> */
    private static function list_ranks(array $args): array
    {
        $types = isset($args['type']) ? [ (string) $args['type'] ] : array_keys(self::type_map('rank'));
        if ([] === $types) {
            return [ 'ranks' => [], 'total' => 0 ];
        }
        $q = Readable_Posts::query([
            'post_type'      => $types,
            'post_status'    => self::STATUSES,
            'posts_per_page' => (int) ($args['per_page'] ?? 50),
            'paged'          => (int) ($args['page'] ?? 1),
            'orderby'        => [ 'type' => 'ASC', 'menu_order' => 'ASC', 'ID' => 'ASC' ],
        ]);
        $ranks = array_map(static fn (\WP_Post $p): array => [
            'id'       => (int) $p->ID,
            'title'    => (string) $p->post_title,
            'type'     => (string) $p->post_type,
            'status'   => (string) $p->post_status,
            'priority' => (int) $p->menu_order,
        ], $q->posts);
        return [ 'ranks' => $ranks, 'total' => (int) $q->found_posts ];
    }

    private static function balance(int $user, string $type): int
    {
        return function_exists('gamipress_get_user_points') ? (int) gamipress_get_user_points($user, $type) : 0;
    }

    /** @return array<string, mixed> */
    private static function user_points(int $user, ?string $only): array
    {
        $out = [];
        foreach (self::type_map('points') as $slug => $data) {
            $slug = (string) $slug;
            if (null !== $only && $only !== $slug) {
                continue;
            }
            $out[] = [
                'points_type' => $slug,
                'label'       => (string) (((array) $data)['plural_name'] ?? $slug),
                'balance'     => self::balance($user, $slug),
                'awarded'     => function_exists('gamipress_get_user_points_awarded') ? (int) gamipress_get_user_points_awarded($user, $slug) : 0,
                'deducted'    => function_exists('gamipress_get_user_points_deducted') ? (int) gamipress_get_user_points_deducted($user, $slug) : 0,
            ];
        }
        return [ 'user_id' => $user, 'points' => $out ];
    }

    private static function earnings_table(): string
    {
        return Gamipress_User_Snapshot::table('gamipress_user_earnings');
    }

    private static function earned_count(int $user, int $achievement): int
    {
        global $wpdb;
        $table = self::earnings_table();
        if (! Plugin_Table_Rows_Snapshot::table_exists($table)) {
            return 0;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- GamiPress's own earnings table; read live.
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE user_id = %d AND post_id = %d', $table, $user, $achievement));
    }

    /** @return array<string, mixed> */
    private static function user_achievements(array $args): array
    {
        global $wpdb;
        $user  = (int) $args['user_id'];
        $types = isset($args['type']) ? [ (string) $args['type'] ] : array_keys(self::type_map('achievement'));
        $table = self::earnings_table();
        if ([] === $types || ! Plugin_Table_Rows_Snapshot::table_exists($table)) {
            return [ 'user_id' => $user, 'achievements' => [], 'total' => 0 ];
        }
        $per   = (int) ($args['per_page'] ?? 20);
        $skip  = ((int) ($args['page'] ?? 1) - 1) * $per;
        $in    = implode(',', array_fill(0, count($types), '%s'));
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- GamiPress's own earnings table; $in holds only %s placeholders.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE user_id = %d AND post_type IN ($in)", $table, $user, ...$types));
        $rows  = (array) $wpdb->get_results($wpdb->prepare("SELECT user_earning_id, post_id, post_type, title, points, points_type, date FROM %i WHERE user_id = %d AND post_type IN ($in) ORDER BY date DESC, user_earning_id DESC LIMIT %d OFFSET %d", $table, $user, ...array_merge($types, [ $per, $skip ])), ARRAY_A);
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $list = array_map(static fn (array $r): array => [
            'earning_id'     => (int) $r['user_earning_id'],
            'achievement_id' => (int) $r['post_id'],
            'title'          => (string) $r['title'],
            'type'           => (string) $r['post_type'],
            'points'         => (int) $r['points'],
            'points_type'    => (string) $r['points_type'],
            'date'           => (string) $r['date'],
        ], $rows);
        return [ 'user_id' => $user, 'achievements' => $list, 'total' => $total ];
    }

    /** @return array<string, mixed> */
    private static function user_ranks(int $user, ?string $only): array
    {
        $out = [];
        foreach (array_keys(self::type_map('rank')) as $slug) {
            $slug = (string) $slug;
            if (null !== $only && $only !== $slug) {
                continue;
            }
            $id    = function_exists('gamipress_get_user_rank_id') ? (int) gamipress_get_user_rank_id($user, $slug) : 0;
            $post  = $id > 0 ? get_post($id) : null;
            $time  = (int) get_user_meta($user, "_gamipress_{$slug}_rank_earned_time", true);
            $out[] = [
                'rank_type'   => $slug,
                'rank_id'     => $post instanceof \WP_Post ? $id : 0,
                'title'       => $post instanceof \WP_Post ? (string) $post->post_title : '',
                'priority'    => $post instanceof \WP_Post ? (int) $post->menu_order : 0,
                'earned_time' => $time > 0 ? gmdate('Y-m-d H:i:s', $time) : null,
            ];
        }
        return [ 'user_id' => $user, 'ranks' => $out ];
    }

    // ------------------------------------------------------------ writes

    private static function save_meta(int $id, array $args): void
    {
        foreach ([ 'points' => '_gamipress_points', 'maximum_earnings' => '_gamipress_maximum_earnings' ] as $arg => $key) {
            if (array_key_exists($arg, $args)) {
                update_post_meta($id, $key, (string) (int) $args[ $arg ]);
            }
        }
        if (array_key_exists('points_type', $args)) {
            update_post_meta($id, '_gamipress_points_type', sanitize_key((string) $args['points_type']));
        }
        if (array_key_exists('earned_by', $args)) {
            update_post_meta($id, '_gamipress_earned_by', (string) $args['earned_by']);
        }
        if (array_key_exists('hidden', $args)) {
            update_post_meta($id, '_gamipress_hidden', $args['hidden'] ? 'hidden' : 'show');
        }
    }

    /** @return array<string, mixed> */
    private static function create(array $args, array $context): array
    {
        $id = wp_insert_post([
            'post_type'    => (string) $args['type'],
            'post_title'   => wp_slash((string) $args['title']),
            'post_content' => wp_slash((string) ($args['content'] ?? '')),
            'post_status'  => (string) ($args['status'] ?? 'draft'),
            'post_author'  => get_current_user_id(),
        ], true);
        if (is_wp_error($id) || 0 === (int) $id) {
            throw new Operation_Refused('insert_failed', is_wp_error($id) ? $id->get_error_message() : 'GamiPress could not create the achievement.');
        }
        $id = (int) $id;
        $op = Post_Creation_Snapshot::record((string) $context['tool_name'], [ $id ], [ 'operation' => $context['operation'], 'args' => $args ], (string) $context['session_id']);
        self::save_meta($id, $args + [ 'points' => 0, 'earned_by' => 'admin', 'maximum_earnings' => 1, 'hidden' => false ]);
        clean_post_cache($id);

        return [ 'result' => [ 'achievement' => self::view_achievement(get_post($id)) ], 'operation_ids' => [ $op ] ];
    }

    /** @return array<string, mixed> */
    private static function update(array $args): array
    {
        $id     = (int) $args['id'];
        $fields = [ 'ID' => $id ];
        if (array_key_exists('title', $args)) {
            $fields['post_title'] = wp_slash((string) $args['title']);
        }
        if (array_key_exists('content', $args)) {
            $fields['post_content'] = wp_slash((string) $args['content']);
        }
        if (count($fields) > 1) {
            $out = Save_Filters::update_post($fields, true);
            if (is_wp_error($out)) {
                throw new Operation_Refused('update_failed', $out->get_error_message());
            }
        }
        if (isset($args['status']) && get_post_status($id) !== $args['status']) {
            $out = Save_Filters::set_post_status($id, (string) $args['status'], true);
            if (is_wp_error($out)) {
                throw new Operation_Refused('update_failed', $out->get_error_message());
            }
        }
        self::save_meta($id, $args);
        clean_post_cache($id);

        return [ 'achievement' => self::view_achievement(get_post($id)) ];
    }

    private static function require_fn(string $fn): void
    {
        if (! function_exists($fn)) {
            throw new Operation_Refused('gamipress_unavailable', sprintf('GamiPress does not provide %s() on this site.', $fn));
        }
    }

    /**
     * Award or deduct through GamiPress the way its bulk award and revoke
     * tools do: with the admin id set, the amount it takes is the new total.
     *
     * @return array<string, mixed>
     */
    private static function move_points(array $args, int $sign): array
    {
        $user   = (int) $args['user_id'];
        $type   = (string) $args['points_type'];
        $fn     = $sign > 0 ? 'gamipress_award_points_to_user' : 'gamipress_deduct_points_to_user';
        self::require_fn($fn);
        $before = self::balance($user, $type);
        $total  = max(0, $before + $sign * (int) $args['points']);
        $fn($user, $total, $type, [ 'admin_id' => get_current_user_id(), 'reason' => sanitize_text_field((string) ($args['reason'] ?? '')) ]);

        return [ 'user_id' => $user, 'points_type' => $type, 'before' => $before, 'balance' => self::balance($user, $type) ];
    }

    /** @return array<string, mixed> */
    private static function award(int $user, int $achievement): array
    {
        self::require_fn('gamipress_award_achievement_to_user');
        gamipress_award_achievement_to_user($achievement, $user, get_current_user_id());

        return [ 'user_id' => $user, 'achievement_id' => $achievement, 'times_earned' => self::earned_count($user, $achievement) ];
    }

    /** @return array<string, mixed> */
    private static function revoke(int $user, int $achievement): array
    {
        self::require_fn('gamipress_revoke_achievement_to_user');
        gamipress_revoke_achievement_to_user($achievement, $user);

        return [ 'user_id' => $user, 'achievement_id' => $achievement, 'times_earned' => self::earned_count($user, $achievement) ];
    }

    /** @return array<string, mixed> */
    private static function set_rank(int $user, int $rank): array
    {
        self::require_fn('gamipress_update_user_rank');
        $type   = (string) get_post_type($rank);
        $before = function_exists('gamipress_get_user_rank_id') ? (int) gamipress_get_user_rank_id($user, $type) : 0;
        gamipress_update_user_rank($user, $rank, get_current_user_id());

        return [ 'user_id' => $user, 'rank_type' => $type, 'previous_rank_id' => $before, 'rank_id' => function_exists('gamipress_get_user_rank_id') ? (int) gamipress_get_user_rank_id($user, $type) : $rank, 'changed' => $before !== $rank ];
    }
}
