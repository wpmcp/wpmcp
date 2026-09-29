<?php

namespace WPMCP\Tests\Free\Platform;

/**
 * Every registered ability must gate on a capability some user can actually
 * hold (issue #409).
 *
 * Registrar::is_permitted() calls current_user_can( $ability->capability ).
 * A misspelled or invented capability name (edit-comment and delete-comment
 * once asked for "edit_comments", which WordPress does not define) is not an
 * error anywhere: it is simply false for every user, administrators included,
 * so the tool is silently denied to everyone. This guard fails for any
 * ability whose capability is none of:
 *
 *  - a primitive capability one of the five core roles is given by
 *    populate_roles() (CORE_ROLE_CAPS, mirrored from wp-admin/includes/schema.php),
 *  - a capability core resolves itself: a map_meta_cap() case or a capability
 *    granted through core's own user_has_cap filter (CORE_META_CAPS), or
 *  - an entry of the explicit, documented allowlist below (PLUGIN_CAPS):
 *    capabilities a third-party plugin adds to its own roles, and any custom
 *    wpmcp capability. Add to it only with a comment saying who grants it.
 *
 * The core lists are pinned rather than read from wp_roles() on purpose:
 * active plugins (WooCommerce, in this suite) add their own capabilities to
 * the administrator role, which would hide exactly the kind of name this
 * test exists to catch.
 */
class AbilityCapabilityRegistryTest extends \WP_UnitTestCase
{
    /** Primitive capabilities granted by populate_roles() to a core role. */
    private const CORE_ROLE_CAPS = [
        'activate_plugins', 'create_users', 'delete_others_pages', 'delete_others_posts',
        'delete_pages', 'delete_plugins', 'delete_posts', 'delete_private_pages',
        'delete_private_posts', 'delete_published_pages', 'delete_published_posts',
        'delete_themes', 'delete_users', 'edit_dashboard', 'edit_files',
        'edit_others_pages', 'edit_others_posts', 'edit_pages', 'edit_plugins',
        'edit_posts', 'edit_private_pages', 'edit_private_posts', 'edit_published_pages',
        'edit_published_posts', 'edit_theme_options', 'edit_themes', 'edit_users',
        'export', 'import', 'install_plugins', 'install_themes', 'list_users',
        'manage_categories', 'manage_links', 'manage_options', 'moderate_comments',
        'promote_users', 'publish_pages', 'publish_posts', 'read', 'read_private_pages',
        'read_private_posts', 'remove_users', 'switch_themes', 'unfiltered_html',
        'unfiltered_upload', 'update_core', 'update_plugins', 'update_themes', 'upload_files',
    ];

    /**
     * Capabilities core resolves without a role granting them by name:
     * map_meta_cap() cases (including the network capabilities only a super
     * admin passes), plus view_site_health_checks, which core grants through
     * its own user_has_cap filter to anyone holding install_plugins.
     */
    private const CORE_META_CAPS = [
        'activate_plugin', 'add_comment_meta', 'add_post_meta', 'add_term_meta',
        'add_user_meta', 'add_users', 'assign_categories', 'assign_post_tags',
        'assign_term', 'create_app_password', 'create_sites', 'customize',
        'deactivate_plugin', 'deactivate_plugins', 'delete_app_password',
        'delete_app_passwords', 'delete_categories', 'delete_comment_meta',
        'delete_page', 'delete_post', 'delete_post_meta', 'delete_post_tags',
        'delete_site', 'delete_sites', 'delete_term', 'delete_term_meta', 'delete_user',
        'delete_user_meta', 'edit_app_password', 'edit_block_binding', 'edit_categories',
        'edit_comment', 'edit_comment_meta', 'edit_css', 'edit_page', 'edit_post',
        'edit_post_meta', 'edit_post_tags', 'edit_term', 'edit_term_meta', 'edit_user',
        'edit_user_meta', 'erase_others_personal_data', 'export_others_personal_data',
        'install_languages', 'list_app_passwords', 'manage_network',
        'manage_network_options', 'manage_network_plugins', 'manage_network_themes',
        'manage_network_users', 'manage_post_tags', 'manage_privacy_options',
        'manage_sites', 'promote_user', 'publish_post', 'read_app_password', 'read_page',
        'read_post', 'remove_user', 'resume_plugin', 'resume_theme', 'setup_network',
        'update_https', 'update_languages', 'update_php', 'upgrade_network',
        'upload_plugins', 'upload_themes', 'view_site_health_checks',
    ];

    /**
     * Capabilities a plugin other than core defines. Each entry names who
     * grants it. wpmcp itself defines no custom capability today; one added
     * later belongs here with the code that grants it.
     */
    private const PLUGIN_CAPS = [
        // WooCommerce: granted to administrator and shop_manager on install.
        'manage_woocommerce',
        // WooCommerce: the shop_order post type's edit capability, granted to
        // administrator and shop_manager on install.
        'edit_shop_orders',
    ];

    public function test_every_ability_capability_is_one_a_user_can_hold(): void
    {
        $known = array_flip(array_merge(self::CORE_ROLE_CAPS, self::CORE_META_CAPS, self::PLUGIN_CAPS));

        $unknown = [];
        foreach (RegisteredAbilities::all() as $ability) {
            if (! isset($known[ $ability->capability ])) {
                $unknown[ $ability->name ] = $ability->capability;
            }
        }
        ksort($unknown);

        $this->assertSame(
            [],
            $unknown,
            'These abilities gate on a capability no core role grants, core does not map, '
            . 'and the documented plugin allowlist does not name, so current_user_can() is false for everyone.'
        );
    }

    public function test_guard_rejects_an_unknown_capability_name(): void
    {
        $known = array_merge(self::CORE_ROLE_CAPS, self::CORE_META_CAPS, self::PLUGIN_CAPS);

        $this->assertNotContains('edit_comments', $known, 'edit_comments is not a WordPress capability');
        $this->assertContains('moderate_comments', $known);
        $this->assertContains('edit_comment', $known);
    }
}
