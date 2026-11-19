<?php

namespace WPMCP\Integrations;

use WPMCP\Privacy\Masking;
use WPMCP\Tools\Content\Readable_Posts;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Ultimate Member (issue #669) on the plugin-data pair, free tier, so it adds
 * no top-level tools. Ops are prefixed um- because the pair is shared.
 *
 * Ultimate Member 2.14.2 keeps:
 *  - its custom roles as WordPress roles named um_<key>, the keys in the
 *    um_roles option and each role's settings in um_role_<key>_meta
 *    (_um_priority, _um_status the registration status,
 *    _um_can_access_wpadmin and more); a member holds one UM role beside
 *    the WordPress role, and its status in the account_status user meta
 *    (approved, awaiting_admin_review, awaiting_email_confirmation,
 *    inactive, rejected);
 *  - its forms as um_form posts (_um_mode register, login or profile,
 *    _um_core for a core form, _um_custom_fields the field map, and
 *    _um_<mode>_role when _um_<mode>_use_custom_settings is on), placed
 *    with [ultimatemember form_id="<id>"];
 *  - its member directories as um_directory posts, settings in _um_* meta
 *    (roles, search, filters, sorting, profiles per page), placed with the
 *    same shortcode.
 *
 * Reads: um-list-roles, um-list-members (name and email masked,
 * src/Privacy/Masking.php), um-list-forms, um-list-member-directories and
 * um-get-member-directory (every _um_ setting in full, behind
 * manage_options, the capability Ultimate Member puts its form and
 * directory screens behind).
 *
 * Write: um-update-member-role (off until wpmcp_enable_ultimate_member_write,
 * and confirm:true after a preview, since a role decides what a member can
 * reach) replaces the member's UM role the way UM's own set_role() does:
 * the UM roles it holds are removed and the new one added, the WordPress
 * role kept. Only the roles Ultimate Member itself lets a form assign are
 * allowed (subscriber and its custom roles), and a role holding any
 * administrative capability is refused, as are site administrators, user
 * managers and the caller's own account. It needs promote_users and is
 * snapshotted as a 'user' snapshot (the user's meta, roles included), so
 * rollback-operation puts the roles back. Presence is filterable
 * (wpmcp_ultimate_member_active).
 */
final class Ultimate_Member_Pack
{
    public const CAPABILITY = 'manage_options';

    public const OPT_IN_FILTER = 'wpmcp_enable_ultimate_member_write';

    public const MAX_PER_PAGE = 100;

    public const PLUGIN_FILES = [ 'ultimate-member/ultimate-member.php' ];

    private const ACCOUNT_STATUSES = [ 'approved', 'awaiting_admin_review', 'awaiting_email_confirmation', 'inactive', 'rejected' ];

    private const FORM_MODES = [ 'register', 'login', 'profile' ];

    /**
     * Capabilities a role this pack assigns may not hold: Ultimate Member's
     * own banned list for registration roles plus the user, plugin, theme
     * and code capabilities that would let a member take over the site.
     */
    private const BANNED_CAPABILITIES = [
        'manage_options', 'promote_users', 'edit_users', 'create_users', 'delete_users', 'list_users', 'remove_users', 'add_users',
        'activate_plugins', 'install_plugins', 'update_plugins', 'delete_plugins', 'edit_plugins', 'edit_themes', 'install_themes',
        'update_themes', 'delete_themes', 'switch_themes', 'edit_theme_options', 'customize', 'edit_files', 'update_core',
        'unfiltered_html', 'unfiltered_upload', 'import', 'export', 'edit_dashboard', 'delete_site', 'manage_network',
        'manage_sites', 'manage_network_users', 'manage_network_plugins', 'manage_network_themes', 'manage_network_options',
        'create_sites', 'delete_sites', 'upgrade_network', 'setup_network', 'edit_others_posts', 'edit_others_pages',
        'delete_others_posts', 'delete_others_pages', 'manage_categories', 'moderate_comments',
    ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_ultimate_member_active', defined('UM_VERSION'));
    }

    /** @return true|array{code: string, message: string, data: array} */
    public static function presence()
    {
        if (self::active()) {
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
                'Ultimate Member is %s on this site. Install and activate Ultimate Member (%s), then try again.',
                $installed ? 'installed but not active' : 'not installed',
                $url
            ),
            'data'    => [ 'plugin' => 'ultimate-member', 'plugins_url' => $url ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $base   = [
            'mode'       => 'read',
            'capability' => self::CAPABILITY,
            'requires'   => static fn () => self::presence(),
        ];
        $paging = [
            'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
            'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
        ];

        return [
            'um-list-roles'              => $base + [
                'description'  => 'List the site\'s roles as Ultimate Member sees them: role id, name, custom (a UM role), user count, priority, registration status, wp-admin access, and assignable (whether um-update-member-role may set it)',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => static fn (): array => self::list_roles(),
            ],
            'um-list-members'            => $base + [
                'description'  => 'List Ultimate Member members newest first: user id, name and email MASKED, roles, UM role, account status, registered. Filter by role and account_status (approved, awaiting_admin_review, awaiting_email_confirmation, inactive, rejected). page, per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'role'           => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 100 ],
                        'account_status' => [ 'type' => 'string', 'enum' => self::ACCOUNT_STATUSES ],
                    ] + $paging,
                ],
                'handler'      => static fn (array $args): array => self::list_members($args),
            ],
            'um-list-forms'              => $base + [
                'description'  => 'List Ultimate Member forms: id, title, status, mode (register, login, profile), core form, field count, the role a register form assigns when it overrides the default, shortcode. Filter by mode',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'mode' => [ 'type' => 'string', 'enum' => self::FORM_MODES ] ],
                ],
                'handler'      => static fn (array $args): array => self::list_forms($args),
            ],
            'um-list-member-directories' => $base + [
                'description'  => 'List Ultimate Member member directories: id, title, status, roles shown, search on, profiles per page, shortcode',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'handler'      => static fn (): array => [ 'directories' => array_map(static fn (\WP_Post $post): array => self::directory_summary($post), self::posts('um_directory')) ],
            ],
            'um-get-member-directory'    => $base + [
                'description'  => 'Read one Ultimate Member member directory with every setting (_um_ meta, prefix dropped): roles, who is shown, search and filter fields, sorting, card fields, paging',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'directory_id' => [ 'type' => 'integer', 'minimum' => 1 ] ],
                    'required'   => [ 'directory_id' ],
                ],
                'objects'      => [ 'directory_id' => [ 'type' => 'post', 'own_type' => true, 'access' => 'read' ] ],
                'validate'     => static fn (array $args): ?array => self::directory_refusal((int) $args['directory_id']),
                'handler'      => static fn (array $args): array => [ 'directory' => self::directory((int) $args['directory_id']) ],
            ],
            'um-update-member-role'      => [
                'mode'               => 'destructive',
                'capability'         => 'promote_users',
                'requires'           => static fn () => self::presence(),
                'enabled_by_default' => (bool) apply_filters('wpmcp_enable_ultimate_member_write', false),
                'opt_in_filter'      => self::OPT_IN_FILTER,
                'description'        => 'Set a member\'s Ultimate Member role: the UM roles the user holds are replaced by role (subscriber or a UM custom role, see um-list-roles assignable), the WordPress role kept. Roles with admin capabilities, administrators, user managers and your own account are refused. Needs promote_users and confirm:true after a preview. Off unless ' . self::OPT_IN_FILTER . '. Undo: rollback-operation',
                'input_schema'       => [
                    'type'       => 'object',
                    'properties' => [
                        'user_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                        'role'    => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 100 ],
                    ],
                    'required'   => [ 'user_id', 'role' ],
                ],
                'objects'            => [ 'user_id' => [ 'type' => 'user' ] ],
                'validate'           => static fn (array $args): ?array => self::role_refusal((int) $args['user_id'], (string) $args['role']),
                'confirm_preview'    => static fn (array $args): array => self::role_preview((int) $args['user_id'], (string) $args['role']),
                'snapshot'           => static fn (array $args): array => [ 'object_type' => 'user', 'object_id' => (int) $args['user_id'] ],
                'handler'            => static fn (array $args): array => self::set_role((int) $args['user_id'], (string) $args['role']),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Roles
    // ------------------------------------------------------------------

    /** The UM custom role ids (um_<key>). @return string[] */
    private static function custom_roles(): array
    {
        $keys = get_option('um_roles', []);
        if (! is_array($keys)) {
            return [];
        }
        return array_values(array_map(static fn ($key): string => 'um_' . (string) $key, $keys));
    }

    /** A role's UM settings (um_role_<key>_meta), keys without the _um_ prefix. */
    private static function role_meta(string $role): array
    {
        $key  = str_starts_with($role, 'um_') && in_array($role, self::custom_roles(), true) ? substr($role, 3) : $role;
        $meta = get_option('um_role_' . $key . '_meta', []);
        $out  = [];
        foreach (is_array($meta) ? $meta : [] as $name => $value) {
            $out[ (string) preg_replace('/^_um_/', '', (string) $name) ] = $value;
        }
        return $out;
    }

    /** Whether the pack may assign a role: subscriber or a UM custom role, holding no banned capability. */
    public static function assignable(string $role): bool
    {
        if ('subscriber' !== $role && ! in_array($role, self::custom_roles(), true)) {
            return false;
        }
        $object = get_role($role);
        if (! $object instanceof \WP_Role) {
            return false;
        }
        foreach (self::BANNED_CAPABILITIES as $cap) {
            if ($object->has_cap($cap)) {
                return false;
            }
        }
        return true;
    }

    private static function list_roles(): array
    {
        $counts = count_users();
        $custom = self::custom_roles();
        $roles  = [];
        foreach (wp_roles()->role_names as $role => $name) {
            $meta    = self::role_meta((string) $role);
            $roles[] = [
                'role'                => (string) $role,
                'name'                => translate_user_role((string) $name),
                'custom'              => in_array($role, $custom, true),
                'users'               => (int) ($counts['avail_roles'][ $role ] ?? 0),
                'priority'            => isset($meta['priority']) ? (int) $meta['priority'] : 0,
                'registration_status' => isset($meta['status']) ? (string) $meta['status'] : null,
                'can_access_wpadmin'  => isset($meta['can_access_wpadmin']) ? (bool) $meta['can_access_wpadmin'] : null,
                'assignable'          => self::assignable((string) $role),
            ];
        }
        return [ 'roles' => $roles, 'total' => count($roles) ];
    }

    /** The UM custom roles a user holds. @return string[] */
    private static function user_um_roles(\WP_User $user): array
    {
        return array_values(array_intersect(array_values($user->roles), self::custom_roles()));
    }

    /** @return array{code: string, message: string, data: array} */
    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    private static function role_refusal(int $user_id, string $role): ?array
    {
        $user = get_userdata($user_id);
        if (! $user instanceof \WP_User) {
            return self::refusal('user_not_found', sprintf('There is no user %d.', $user_id), [ 'user_id' => $user_id ]);
        }
        if (! self::assignable($role)) {
            return self::refusal('role_not_assignable', sprintf('Role "%s" cannot be set here: only subscriber and Ultimate Member custom roles without administrative capabilities can (see um-list-roles, assignable). Change other roles from the Users screen.', $role), [ 'role' => $role ]);
        }
        if ($user_id === get_current_user_id() || user_can($user, 'manage_options') || user_can($user, 'promote_users') || user_can($user, 'edit_users')) {
            return self::refusal('user_protected', 'This account\'s roles cannot be changed here: it is your own account, or one that manages the site or its users. Change it from the Users screen.', [ 'user_id' => $user_id ]);
        }
        $current = self::user_um_roles($user);
        if ($current === [ $role ] || ('subscriber' === $role && [] === $current && in_array('subscriber', $user->roles, true))) {
            return self::refusal('role_unchanged', sprintf('User %d already has role "%s".', $user_id, $role), [ 'user_id' => $user_id, 'role' => $role ]);
        }
        return null;
    }

    private static function role_preview(int $user_id, string $role): array
    {
        $user    = get_userdata($user_id);
        $current = $user instanceof \WP_User ? self::user_um_roles($user) : [];
        return [
            'message' => sprintf(
                'User %d will have Ultimate Member role "%s"%s. What the member can see and do on the site follows the new role. Undo: rollback-operation. Call again with confirm:true to change it.',
                $user_id,
                $role,
                [] === $current ? '' : ' instead of "' . implode('", "', $current) . '"'
            ),
            'data'    => [
                'user_id'          => $user_id,
                'role'             => $role,
                'current_um_roles' => $current,
                'roles'            => $user instanceof \WP_User ? array_values($user->roles) : [],
            ],
        ];
    }

    private static function set_role(int $user_id, string $role): array
    {
        $user     = new \WP_User($user_id);
        $previous = self::user_um_roles($user);
        foreach ($previous as $old) {
            $user->remove_role($old);
        }
        $user->add_role($role);
        if (function_exists('UM')) {
            $um = UM();
            if (is_object($um) && method_exists($um, 'user') && is_object($um->user()) && method_exists($um->user(), 'remove_cache')) {
                $um->user()->remove_cache($user_id);
            }
        }
        clean_user_cache($user_id);
        $roles = array_values((new \WP_User($user_id))->roles);
        sort($roles);

        return [ 'user_id' => $user_id, 'roles' => $roles, 'previous_um_roles' => $previous ];
    }

    // ------------------------------------------------------------------
    // Members
    // ------------------------------------------------------------------

    private static function list_members(array $args): array
    {
        $per_page = min(self::MAX_PER_PAGE, max(1, (int) ($args['per_page'] ?? 20)));
        $page     = max(1, (int) ($args['page'] ?? 1));
        $query    = [
            'number'  => $per_page,
            'paged'   => $page,
            'orderby' => 'registered',
            'order'   => 'DESC',
            'fields'  => 'all',
        ];
        if (isset($args['role'])) {
            $query['role'] = (string) $args['role'];
        }
        if (isset($args['account_status'])) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the plugin keeps member status only in user meta.
            $query['meta_query'] = [ [ 'key' => 'account_status', 'value' => (string) $args['account_status'] ] ];
        }
        $found   = new \WP_User_Query($query);
        $members = [];
        foreach ((array) $found->get_results() as $user) {
            if (! $user instanceof \WP_User) {
                continue;
            }
            $um        = self::user_um_roles($user);
            $members[] = Masking::keys([
                'user_id'        => (int) $user->ID,
                'name'           => (string) $user->display_name,
                'email'          => (string) $user->user_email,
                'roles'          => array_values($user->roles),
                'um_role'        => $um[0] ?? null,
                'account_status' => (string) get_user_meta((int) $user->ID, 'account_status', true),
                'registered'     => (string) $user->user_registered,
            ], [ 'name', 'email' ]);
        }
        return [ 'members' => $members, 'total' => (int) $found->get_total(), 'page' => $page, 'per_page' => $per_page, 'masked' => true ];
    }

    // ------------------------------------------------------------------
    // Forms and directories
    // ------------------------------------------------------------------

    /** @return \WP_Post[] */
    private static function posts(string $type, array $extra = []): array
    {
        return Readable_Posts::get_posts($extra + [
            'post_type'      => $type,
            'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'future' ],
            'posts_per_page' => 500,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ]);
    }

    private static function shortcode(int $id): string
    {
        return '[ultimatemember form_id="' . $id . '"]';
    }

    private static function list_forms(array $args): array
    {
        $extra = [];
        if (isset($args['mode'])) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- the plugin keeps a form's mode only in post meta.
            $extra = [ 'meta_key' => '_um_mode', 'meta_value' => (string) $args['mode'] ];
        }
        $forms = [];
        foreach (self::posts('um_form', $extra) as $post) {
            $id     = (int) $post->ID;
            $mode   = (string) get_post_meta($id, '_um_mode', true);
            $fields = get_post_meta($id, '_um_custom_fields', true);
            $custom = '' !== $mode && (bool) get_post_meta($id, '_um_' . $mode . '_use_custom_settings', true);
            $role   = $custom ? (string) get_post_meta($id, '_um_' . $mode . '_role', true) : '';
            $forms[] = [
                'id'          => $id,
                'title'       => (string) $post->post_title,
                'status'      => (string) $post->post_status,
                'mode'        => $mode,
                'core'        => '' !== (string) get_post_meta($id, '_um_core', true),
                'field_count' => is_array($fields) ? count($fields) : 0,
                'role'        => '' === $role ? null : $role,
                'shortcode'   => self::shortcode($id),
            ];
        }
        return [ 'forms' => $forms, 'total' => count($forms) ];
    }

    private static function directory_summary(\WP_Post $post): array
    {
        $id    = (int) $post->ID;
        $roles = get_post_meta($id, '_um_roles', true);
        return [
            'id'                => $id,
            'title'             => (string) $post->post_title,
            'status'            => (string) $post->post_status,
            'roles'             => is_array($roles) ? array_values($roles) : [],
            'search'            => (bool) get_post_meta($id, '_um_search', true),
            'profiles_per_page' => (int) get_post_meta($id, '_um_profiles_per_page', true),
            'shortcode'         => self::shortcode($id),
        ];
    }

    private static function directory_refusal(int $id): ?array
    {
        $post = get_post($id);
        return $post instanceof \WP_Post && 'um_directory' === $post->post_type
            ? null
            : self::refusal('directory_not_found', sprintf('Ultimate Member has no member directory %d.', $id), [ 'directory_id' => $id ]);
    }

    private static function directory(int $id): array
    {
        $post     = get_post($id);
        $settings = [];
        foreach ((array) get_post_meta($id) as $key => $values) {
            if (! str_starts_with((string) $key, '_um_')) {
                continue;
            }
            $settings[ substr((string) $key, 4) ] = maybe_unserialize($values[0] ?? '');
        }
        ksort($settings);

        return self::directory_summary($post instanceof \WP_Post ? $post : new \WP_Post((object) [ 'ID' => $id ])) + [ 'settings' => $settings ];
    }
}
