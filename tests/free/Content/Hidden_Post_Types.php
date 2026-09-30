<?php

namespace WPMCP\Tests\Free\Content;

/**
 * Three post types that other plugins use to keep per-user or restricted
 * records (issue #446), registered with the capability arguments those
 * plugins really pass, plus one row of each owned by an administrator.
 *
 * - enrollment: an LMS enrollment record (Tutor LMS style). Not public, no
 *   admin screen of its own, and the default post capabilities, so the
 *   capability map alone would let any edit_posts caller in.
 * - order: a legacy (posts table) shop order. Not public, has an admin
 *   screen, and its own capability type that only administrators and shop
 *   managers are granted.
 * - entry: a form submission store. Not public, has an admin screen, and
 *   maps its capabilities to edit_users through map_meta_cap, the way form
 *   plugins keep entries for administrators only. It is the one type here
 *   that opts into 'any' queries (exclude_from_search false), so the
 *   list-posts 'any' path is exercised against a hidden type too.
 */
trait Hidden_Post_Types
{
    protected const ENROLLMENT = 'acme_enrolled';
    protected const ORDER      = 'acme_order';
    protected const ENTRY      = 'acme_form_entry';

    /** Primitive capabilities the order type declares, granted to administrators the way shop plugins grant theirs. */
    private const ORDER_CAPS = [
        'edit_acme_orders',
        'edit_others_acme_orders',
        'publish_acme_orders',
        'read_private_acme_orders',
        'delete_acme_orders',
        'delete_others_acme_orders',
        'edit_published_acme_orders',
        'edit_private_acme_orders',
        'delete_private_acme_orders',
        'delete_published_acme_orders',
    ];

    /** @var array<string,int> type => row id */
    protected array $hidden_rows = [];

    /** @var array<string,int> role => user id */
    protected array $role_users = [];

    protected function register_hidden_post_types(): void
    {
        register_post_type(self::ENROLLMENT, [
            'public'              => false,
            'show_ui'             => false,
            'exclude_from_search' => true,
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'supports'            => ['title', 'custom-fields'],
        ]);

        register_post_type(self::ORDER, [
            'public'              => false,
            'show_ui'             => true,
            'exclude_from_search' => true,
            'capability_type'     => 'acme_order',
            'map_meta_cap'        => true,
            'supports'            => ['title', 'comments', 'custom-fields', 'revisions'],
        ]);
        $admin = get_role('administrator');
        foreach (self::ORDER_CAPS as $cap) {
            $admin->add_cap($cap);
        }

        register_post_type(self::ENTRY, [
            'public'              => false,
            'show_ui'             => true,
            'exclude_from_search' => false,
            'capability_type'     => 'acme_form_entry',
            'map_meta_cap'        => true,
            'supports'            => ['title', 'custom-fields'],
        ]);
        add_filter('map_meta_cap', [self::class, 'map_entry_caps'], 10, 2);

        foreach (['contributor', 'author', 'editor', 'administrator'] as $role) {
            $this->role_users[ $role ] = self::factory()->user->create(['role' => $role]);
        }

        foreach ([self::ENROLLMENT, self::ORDER, self::ENTRY] as $type) {
            $this->hidden_rows[ $type ] = self::factory()->post->create([
                'post_type'   => $type,
                'post_status' => 'publish',
                'post_title'  => 'private record ' . $type,
                'post_author' => $this->role_users['administrator'],
            ]);
            update_post_meta($this->hidden_rows[ $type ], 'student_email', 'student@example.com');
        }
    }

    protected function unregister_hidden_post_types(): void
    {
        remove_filter('map_meta_cap', [self::class, 'map_entry_caps'], 10);
        $admin = get_role('administrator');
        foreach (self::ORDER_CAPS as $cap) {
            $admin->remove_cap($cap);
        }
        foreach ([self::ENROLLMENT, self::ORDER, self::ENTRY] as $type) {
            unregister_post_type($type);
        }
        wp_set_current_user(0);
    }

    /**
     * Every capability the entry type declares resolves to edit_users.
     *
     * @param string[] $caps
     * @return string[]
     */
    public static function map_entry_caps(array $caps, string $cap): array
    {
        if (false !== strpos($cap, 'acme_form_entr')) {
            return ['edit_users'];
        }
        return $caps;
    }

    /** @return string[] */
    protected static function hidden_types(): array
    {
        return [self::ENROLLMENT, self::ORDER, self::ENTRY];
    }
}
