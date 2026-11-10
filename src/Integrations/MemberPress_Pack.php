<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;
use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Safe_Mutation;
use WPMCP\Safety\Save_Filters;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * MemberPress (issue #668) on the plugin-data pair, paid-tier, so it adds no
 * top-level tools.
 *
 * MemberPress 1.14.3 keeps a membership as a `memberpressproduct` post with
 * its settings in `_mepr_*` post meta (MeprProduct), and a rule as a
 * `memberpressrule` post with `_mepr_rules_*` meta (MeprRule) whose access
 * conditions ("membership is 12", "role is editor") are rows of
 * {prefix}mepr_rule_access_conditions keyed by the rule. Members and
 * transactions live in {prefix}mepr_members and {prefix}mepr_transactions.
 * MeprProduct::store() and MeprRule::store() are wp_insert_post() plus one
 * update_post_meta() per setting, so the writes here store the same keys
 * the same way (a PHP false as '', a price through number_format with two
 * decimals) and MemberPress reads them back unchanged.
 *
 * Reads: list-memberships, get-membership, list-members, list-transactions
 * and list-rules. A transaction's gateway response is never returned.
 *
 * Writes (off until a site opts in with wpmcp_enable_memberpress_write):
 *  - create-membership and create-rule create a post recorded for rollback
 *    (Post_Creation_Snapshot: the undo moves it to the trash); a rule's
 *    access rows are a 'plugin_table_rows' image keyed by the rule, taken
 *    before they are written, so the undo removes them too.
 *  - update-membership and update-rule run under a post snapshot (post
 *    fields and every meta key), and a rule whose access is replaced under
 *    the same rows image, one session per call, so rollback-session undoes
 *    the whole call.
 * Nothing here moves money: there is no refund, charge, or transaction or
 * subscription write. Nothing is deleted: a membership or rule can be taken
 * to draft, and a created one's undo is the trash.
 *
 * Every op takes MemberPress's own admin capability (the mepr_admin_capability
 * filter, remove_users by default), the one its admin screens for
 * memberships, rules, members and transactions require.
 *
 * Presence is filterable (wpmcp_memberpress_active, default: MEPR_VERSION is
 * defined); while MemberPress is not loaded the ops stay documented in
 * list-operations (dependency_met:false) and answer memberpress_inactive.
 */
final class MemberPress_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads and writes MemberPress's own tables live; there is no MemberPress read API to cache behind.

    public const MEMBERSHIP_TYPE = 'memberpressproduct';

    public const RULE_TYPE = 'memberpressrule';

    public const MAX_PER_PAGE = 100;

    public const ENABLE_HINT = 'A site enables MemberPress writes with the wpmcp_enable_memberpress_write filter.';

    /** MemberPress's "never expires" datetime (MeprUtils::db_lifetime()). */
    private const LIFETIME = '0000-00-00 00:00:00';

    /** Transaction statuses that grant access (MeprTransaction::$complete_str, $confirmed_str). */
    private const GRANTING = [ 'complete', 'confirmed' ];

    private const TRANSACTION_STATUSES = [ 'pending', 'failed', 'complete', 'confirmed', 'refunded' ];

    private const POST_STATUSES = [ 'publish', 'draft', 'pending', 'private' ];

    private const UNITS = [ 'days', 'weeks', 'months', 'years' ];

    /**
     * Membership settings: arg => [meta key, MemberPress default, kind].
     * Keys and defaults from MeprProduct (the *_str statics and the
     * constructor's attribute list); kind says how a value is stored.
     */
    private const MEMBERSHIP_FIELDS = [
        'price'                      => [ '_mepr_product_price', '0.00', 'price' ],
        'period'                     => [ '_mepr_product_period', 1, 'int' ],
        'period_type'                => [ '_mepr_product_period_type', 'lifetime', 'string' ],
        'signup_button_text'         => [ '_mepr_product_signup_button_text', 'Sign Up', 'string' ],
        'limit_cycles'               => [ '_mepr_product_limit_cycles', false, 'bool' ],
        'limit_cycles_num'           => [ '_mepr_product_limit_cycles_num', 2, 'int' ],
        'limit_cycles_action'        => [ '_mepr_product_limit_cycles_action', 'expire', 'string' ],
        'limit_cycles_expires_after' => [ '_mepr_product_limit_cycles_expires_after', 1, 'int' ],
        'limit_cycles_expires_type'  => [ '_mepr_product_limit_cycles_expires_type', 'days', 'string' ],
        'trial'                      => [ '_mepr_product_trial', false, 'bool' ],
        'trial_days'                 => [ '_mepr_product_trial_days', 1, 'int' ],
        'trial_amount'               => [ '_mepr_product_trial_amount', '0.00', 'price' ],
        'trial_once'                 => [ '_mepr_product_trial_once', true, 'bool' ],
        'is_highlighted'             => [ '_mepr_product_is_highlighted', false, 'bool' ],
        'pricing_title'              => [ '_mepr_product_pricing_title', '', 'string' ],
        'pricing_display'            => [ '_mepr_product_pricing_display', 'auto', 'string' ],
        'custom_price'               => [ '_mepr_product_custom_price', '', 'string' ],
        'pricing_heading_txt'        => [ '_mepr_product_pricing_heading_text', '', 'string' ],
        'pricing_footer_txt'         => [ '_mepr_product_pricing_footer_text', '', 'string' ],
        'pricing_button_txt'         => [ '_mepr_product_pricing_button_text', '', 'string' ],
        'pricing_badge_txt'          => [ '_mepr_product_pricing_badge_text', '', 'string' ],
        'pricing_benefits'           => [ '_mepr_product_pricing_benefits', [], 'list' ],
        'thank_you_page_enabled'     => [ '_mepr_thank_you_page_enabled', false, 'bool' ],
        'thank_you_message'          => [ '_mepr_product_thank_you_message', '', 'string' ],
        'expire_type'                => [ '_mepr_expire_type', 'none', 'string' ],
        'expire_after'               => [ '_mepr_expire_after', 1, 'int' ],
        'expire_unit'                => [ '_mepr_expire_unit', 'days', 'string' ],
        'expire_fixed'               => [ '_mepr_expire_fixed', '', 'string' ],
        'allow_renewal'              => [ '_mepr_allow_renewal', false, 'bool' ],
        'access_url'                 => [ '_mepr_access_url', '', 'string' ],
        'cannot_purchase_message'    => [ '_mepr_cannot_purchase_message', 'You don\'t have access to purchase this item.', 'string' ],
    ];

    /**
     * Other membership settings MeprProduct::store_meta() writes, stored with
     * their defaults on create so MemberPress finds a complete membership,
     * and returned by get-membership: arg => [meta key, default, kind].
     */
    private const MEMBERSHIP_STORED = [
        'group_id'                   => [ '_mepr_group_id', 0, 'int' ],
        'group_order'                => [ '_mepr_group_order', 0, 'int' ],
        'who_can_purchase'           => [ '_mepr_product_who_can_purchase', [], 'list' ],
        'custom_price_term'          => [ '_mepr_product_custom_price_term', '', 'string' ],
        'pricing_button_position'    => [ '_mepr_product_pricing_button_position', 'footer', 'string' ],
        'register_price_action'      => [ '_mepr_register_price_action', 'default', 'string' ],
        'register_price'             => [ '_mepr_register_price', '', 'string' ],
        'thank_you_page_type'        => [ '_mepr_thank_you_page_type', '', 'string' ],
        'thank_you_page_id'          => [ '_mepr_product_thank_you_page_id', 0, 'int' ],
        'custom_login_urls_enabled'  => [ '_mepr_custom_login_urls_enabled', false, 'bool' ],
        'custom_login_urls_default'  => [ '_mepr_custom_login_urls_default', '', 'string' ],
        'custom_login_urls'          => [ '_mepr_custom_login_urls', [], 'list' ],
        'tax_exempt'                 => [ '_mepr_tax_exempt', false, 'bool' ],
        'tax_class'                  => [ '_mepr_tax_class', 'standard', 'string' ],
        'emails'                     => [ '_mepr_emails', [], 'list' ],
        'disable_address_fields'     => [ '_mepr_disable_address_fields', false, 'bool' ],
        'simultaneous_subscriptions' => [ '_mepr_allow_simultaneous_subscriptions', false, 'bool' ],
        'use_custom_template'        => [ '_mepr_use_custom_template', false, 'bool' ],
        'custom_template'            => [ '_mepr_custom_template', '', 'string' ],
        'customize_payment_methods'  => [ '_mepr_customize_payment_methods', false, 'bool' ],
        'customize_profile_fields'   => [ '_mepr_customize_profile_fields', false, 'bool' ],
        'plan_code'                  => [ '_mepr_plan_code', '', 'string' ],
    ];

    /** Rule settings: arg => [meta key, MemberPress default, kind] (MeprRule). */
    private const RULE_FIELDS = [
        'is_regexp'           => [ '_is_mepr_rules_content_regexp', false, 'bool' ],
        'drip_enabled'        => [ '_mepr_rules_drip_enabled', false, 'bool' ],
        'drip_amount'         => [ '_mepr_rules_drip_amount', 0, 'int' ],
        'drip_unit'           => [ '_mepr_rules_drip_unit', 'days', 'string' ],
        'drip_after'          => [ '_mepr_rules_drip_after', 'registers', 'string' ],
        'drip_after_fixed'    => [ '_mepr_rules_drip_after_fixed', '', 'string' ],
        'expires_enabled'     => [ '_mepr_rules_expires_enabled', false, 'bool' ],
        'expires_amount'      => [ '_mepr_rules_expires_amount', 0, 'int' ],
        'expires_unit'        => [ '_mepr_rules_expires_unit', 'days', 'string' ],
        'expires_after'       => [ '_mepr_rules_expires_after', 'registers', 'string' ],
        'expires_after_fixed' => [ '_mepr_rules_expires_after_fixed', '', 'string' ],
        'unauth_excerpt_type' => [ '_mepr_rules_unauth_excerpt_type', 'default', 'string' ],
        'unauth_excerpt_size' => [ '_mepr_rules_unauth_excerpt_size', 100, 'int' ],
        'unauth_message_type' => [ '_mepr_rules_unauth_message_type', 'default', 'string' ],
        'unauth_message'      => [ '_mepr_rules_unath_message', '', 'string' ],
        'unauth_login'        => [ '_mepr_rules_unath_login', 'default', 'string' ],
    ];

    private const RULE_TYPE_KEY = '_mepr_rules_type';

    private const RULE_CONTENT_KEY = '_mepr_rules_content';

    /** Stored with its default on create (MeprRule::store_meta()). */
    private const RULE_STORED = [
        'unauth_modern_paywall' => [ '_mepr_rules_unath_modern_paywall', false, 'bool' ],
        'auto_gen_title'        => [ '_mepr_auto_gen_title', true, 'bool' ],
    ];

    /** Rule types that are not tied to one post type (MeprRule::get_types()). */
    private const BASE_RULE_TYPES = [ 'all', 'all_posts', 'single_post', 'category', 'tag', 'all_pages', 'single_page', 'parent_page', 'partial', 'custom' ];

    /** Access condition types (MeprRule::mepr_access_types()). */
    private const ACCESS_TYPES = [ 'membership', 'member', 'role', 'login_state', 'capability' ];

    /** Whether MemberPress is loaded (filterable) and this install may run the paid tier. */
    public static function active(): bool
    {
        return Registrar::tier_permitted('pro')
            && (bool) apply_filters('wpmcp_memberpress_active', defined('MEPR_VERSION'));
    }

    /** MemberPress's own admin capability (MeprUtils::get_mepr_admin_capability()). */
    public static function capability(): string
    {
        return (string) apply_filters('mepr_admin_capability', 'remove_users'); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- MemberPress's own filter, read so the ops follow the capability the site set for MemberPress.
    }

    private static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'mepr_' . $name;
    }

    // ------------------------------------------------------------------
    // Catalog
    // ------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id      = [ 'type' => 'integer', 'minimum' => 1 ];
        $paging  = [
            'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
            'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
        ];
        $status  = [ 'type' => 'string', 'enum' => self::POST_STATUSES ];
        $writes  = (bool) apply_filters('wpmcp_enable_memberpress_write', false);
        $base    = [
            'tier'       => 'pro',
            'capability' => self::capability(),
            'requires'   => static fn () => Ops_Status_Packs::presence(self::active(), 'memberpress_inactive', 'MemberPress'),
        ];
        $write   = $base + [ 'mode' => 'write', 'self_snapshotting' => true, 'enabled_by_default' => $writes, 'enable_hint' => self::ENABLE_HINT ];
        $ms      = self::membership_schema();
        $rule    = self::rule_schema();
        $own     = static fn (string $key): array => [ $key => [ 'type' => 'post', 'own_type' => true ] ];

        return [
            'memberpress-list-memberships'  => $base + [
                'mode'         => 'read',
                'description'  => 'List MemberPress memberships newest first: id, title, status, price, billing period, trial and active member count. search matches the title; status filters; page and per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'search' => [ 'type' => 'string' ], 'status' => $status ] + $paging,
                ],
                'handler'      => static fn (array $args): array => self::list_memberships($args),
            ],
            'memberpress-get-membership'    => $base + [
                'mode'         => 'read',
                'objects'      => $own('id'),
                'description'  => 'One MemberPress membership with every setting (price, period, trial, cycles, expiry, pricing box, thank-you and access URL), MemberPress defaults for unset ones, and its active member count',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ],
                'validate'     => static fn (array $args): ?array => self::post_refusal((int) $args['id'], self::MEMBERSHIP_TYPE, false),
                'handler'      => static fn (array $args): array => [ 'membership' => self::membership((int) $args['id']) ],
            ],
            'memberpress-list-members'      => $base + [
                'mode'         => 'read',
                'objects'      => $own('membership_id'),
                'description'  => 'List MemberPress members newest first: user id, login, display name, email, active and inactive membership ids, transaction and subscription counts, total spent. status: active, inactive, expired, none or all (default); membership_id filters; search matches login, email or name. Personal data. Read-only',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'membership_id' => $id,
                        'status'        => [ 'type' => 'string', 'enum' => [ 'all', 'active', 'inactive', 'expired', 'none' ] ],
                        'search'        => [ 'type' => 'string' ],
                    ] + $paging,
                ],
                'handler'      => static fn (array $args): array => self::list_members($args),
            ],
            'memberpress-list-transactions' => $base + [
                'mode'         => 'read',
                'objects'      => $own('membership_id') + [ 'user_id' => [ 'type' => 'user' ] ],
                'description'  => 'List MemberPress transactions newest first: id, user, membership, amount, tax, total, status, type, gateway, transaction number, subscription and dates. Filters: membership_id, user_id, status (pending, failed, complete, confirmed, refunded). Read-only: no refund or charge',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'membership_id' => $id,
                        'user_id'       => $id,
                        'status'        => [ 'type' => 'string', 'enum' => self::TRANSACTION_STATUSES ],
                    ] + $paging,
                ],
                'handler'      => static fn (array $args): array => self::list_transactions($args),
            ],
            'memberpress-list-rules'        => $base + [
                'mode'         => 'read',
                'objects'      => $own('membership_id'),
                'description'  => 'List MemberPress access rules: id, title, status, type (all, single_page, category, custom, ...), content, access conditions (type, operator, condition), drip and expiry. membership_id lists the rules that grant it; page and per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [ 'membership_id' => $id, 'status' => $status ] + $paging,
                ],
                'handler'      => static fn (array $args): array => self::list_rules($args),
            ],
            'memberpress-create-membership' => $write + [
                'description'  => 'Create a MemberPress membership (status defaults to draft) with title, content and any settings; unset ones take MemberPress defaults. Undo: rollback-session trashes it',
                'input_schema' => [ 'type' => 'object', 'properties' => $ms, 'required' => [ 'title' ] ],
                'validate'     => static fn (array $args): ?array => self::create_refusal(self::MEMBERSHIP_TYPE, (string) ($args['status'] ?? 'draft')) ?? self::membership_refusal($args, []),
                'handler'      => static fn (array $args, array $context): array => self::create_membership($args, $context),
            ],
            'memberpress-update-membership' => [
                'self_snapshotting' => false,
                'objects'           => $own('id'),
                'description'       => 'Change a MemberPress membership\'s title, content, status or settings; only the args given change. Prices change for new signups only. Undo: rollback-operation',
                'input_schema'      => [ 'type' => 'object', 'properties' => [ 'id' => $id ] + $ms, 'required' => [ 'id' ] ],
                'validate'          => static fn (array $args): ?array => self::update_membership_refusal($args),
                'snapshot'          => static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['id'] ],
                'handler'           => static fn (array $args): array => self::update_membership($args),
            ] + $write,
            'memberpress-create-rule'       => $write + [
                'description'  => 'Create a MemberPress access rule: type and content say what it protects, access lists who may see it (membership id, member login, role, login_state logged_in or guest, capability), plus drip, expiry and unauthorized message. Status defaults to draft (not enforced). Undo: rollback-session',
                'input_schema' => [ 'type' => 'object', 'properties' => $rule, 'required' => [ 'type', 'access' ] ],
                'validate'     => static fn (array $args): ?array => self::create_refusal(self::RULE_TYPE, (string) ($args['status'] ?? 'draft')) ?? self::rule_refusal($args, []),
                'handler'      => static fn (array $args, array $context): array => self::create_rule($args, $context),
            ],
            'memberpress-update-rule'       => $write + [
                'objects'      => $own('id'),
                'description'  => 'Change a MemberPress access rule; only the args given change, and access, when given, replaces every access condition. Undo: rollback-session',
                'input_schema' => [ 'type' => 'object', 'properties' => [ 'id' => $id ] + $rule, 'required' => [ 'id' ] ],
                'validate'     => static fn (array $args): ?array => self::update_rule_refusal($args),
                'handler'      => static fn (array $args, array $context): array => self::update_rule($args, $context),
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function membership_schema(): array
    {
        $text  = [ 'type' => 'string' ];
        $flag  = [ 'type' => 'boolean' ];
        $money = [ 'type' => 'number', 'minimum' => 0 ];
        $unit  = [ 'type' => 'string', 'enum' => self::UNITS ];

        return [
            'title'                      => [ 'type' => 'string', 'minLength' => 1 ],
            'content'                    => $text,
            'status'                     => [ 'type' => 'string', 'enum' => self::POST_STATUSES ],
            'price'                      => $money,
            'period'                     => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 12 ],
            'period_type'                => [ 'type' => 'string', 'enum' => [ 'weeks', 'months', 'years', 'lifetime' ] ],
            'signup_button_text'         => [ 'type' => 'string', 'minLength' => 1 ],
            'limit_cycles'               => $flag,
            'limit_cycles_num'           => [ 'type' => 'integer', 'minimum' => 1 ],
            'limit_cycles_action'        => [ 'type' => 'string', 'enum' => [ 'expire', 'lifetime', 'expires_after' ] ],
            'limit_cycles_expires_after' => [ 'type' => 'integer', 'minimum' => 1 ],
            'limit_cycles_expires_type'  => $unit,
            'trial'                      => $flag,
            'trial_days'                 => [ 'type' => 'integer', 'minimum' => 0 ],
            'trial_amount'               => $money,
            'trial_once'                 => $flag,
            'is_highlighted'             => $flag,
            'pricing_title'              => $text,
            'pricing_display'            => [ 'type' => 'string', 'enum' => [ 'auto', 'custom', 'none' ] ],
            'custom_price'               => $text,
            'pricing_heading_txt'        => $text,
            'pricing_footer_txt'         => $text,
            'pricing_button_txt'         => $text,
            'pricing_badge_txt'          => $text,
            'pricing_benefits'           => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'maxItems' => 50 ],
            'thank_you_page_enabled'     => $flag,
            'thank_you_message'          => $text,
            'expire_type'                => [ 'type' => 'string', 'enum' => [ 'none', 'delay', 'fixed' ] ],
            'expire_after'               => [ 'type' => 'integer', 'minimum' => 1 ],
            'expire_unit'                => $unit,
            'expire_fixed'               => [ 'type' => 'string', 'pattern' => '^(\d{4}-\d{2}-\d{2})?$' ],
            'allow_renewal'              => $flag,
            'access_url'                 => [ 'type' => 'string', 'maxLength' => 2048 ],
            'cannot_purchase_message'    => $text,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function rule_schema(): array
    {
        $after = [ 'type' => 'string', 'enum' => [ 'registers', 'fixed', 'rule-products' ] ];
        $unit  = [ 'type' => 'string', 'enum' => self::UNITS ];
        $date  = [ 'type' => 'string', 'pattern' => '^(\d{4}-\d{2}-\d{2})?$' ];

        return [
            'title'               => [ 'type' => 'string', 'minLength' => 1 ],
            'status'              => [ 'type' => 'string', 'enum' => self::POST_STATUSES ],
            'type'                => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 191 ],
            'content'             => [ 'type' => 'string', 'maxLength' => 2048 ],
            'is_regexp'           => [ 'type' => 'boolean' ],
            'access'              => [
                'type'     => 'array',
                'minItems' => 1,
                'maxItems' => 50,
                'items'    => [
                    'type'                 => 'object',
                    'properties'           => [
                        'type'      => [ 'type' => 'string', 'enum' => self::ACCESS_TYPES ],
                        'condition' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 60 ],
                    ],
                    'required'             => [ 'type', 'condition' ],
                    'additionalProperties' => false,
                ],
            ],
            'drip_enabled'        => [ 'type' => 'boolean' ],
            'drip_amount'         => [ 'type' => 'integer', 'minimum' => 0 ],
            'drip_unit'           => $unit,
            'drip_after'          => $after,
            'drip_after_fixed'    => $date,
            'expires_enabled'     => [ 'type' => 'boolean' ],
            'expires_amount'      => [ 'type' => 'integer', 'minimum' => 0 ],
            'expires_unit'        => $unit,
            'expires_after'       => $after,
            'expires_after_fixed' => $date,
            'unauth_excerpt_type' => [ 'type' => 'string', 'enum' => [ 'default', 'hide', 'more', 'excerpt', 'custom' ] ],
            'unauth_excerpt_size' => [ 'type' => 'integer', 'minimum' => 0 ],
            'unauth_message_type' => [ 'type' => 'string', 'enum' => [ 'default', 'hide', 'custom' ] ],
            'unauth_message'      => [ 'type' => 'string' ],
            'unauth_login'        => [ 'type' => 'string', 'enum' => [ 'default', 'show', 'hide' ] ],
        ];
    }

    // ------------------------------------------------------------------
    // Stored values
    // ------------------------------------------------------------------

    /**
     * A setting as MemberPress loads it (MeprCptModel::load_meta()): the
     * default when the key is not set, false for a stored '' of a boolean,
     * otherwise the stored value, typed for the caller.
     *
     * @param array{0: string, 1: mixed, 2: string} $field
     * @return mixed
     */
    private static function stored(int $post_id, array $field)
    {
        [$key, $default, $kind] = $field;
        if (! metadata_exists('post', $post_id, $key)) {
            return $default;
        }
        $value = get_post_meta($post_id, $key, true);
        switch ($kind) {
            case 'bool':
                return '' !== $value && false !== $value && '0' !== $value;
            case 'int':
                return (int) $value;
            case 'price':
                return number_format((float) $value, 2, '.', '');
            case 'list':
                return is_array($value) ? array_values($value) : [];
            default:
                return is_scalar($value) ? (string) $value : $value;
        }
    }

    /**
     * A value the way MeprProduct::store_meta() hands it to update_post_meta():
     * a price through MeprUtils::format_float(), a boolean as PHP true or
     * false (stored '1' and ''), the rest as given.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function storable($value, string $kind)
    {
        switch ($kind) {
            case 'bool':
                return (bool) $value;
            case 'int':
                return (int) $value;
            case 'price':
                return number_format((float) $value, 2, '.', '');
            case 'list':
                return array_values(array_map('sanitize_text_field', (array) $value));
            default:
                return (string) $value;
        }
    }

    /** One membership with every setting and its active member count. */
    private static function membership(int $id): array
    {
        $post = get_post($id);
        $out  = [
            'id'      => $id,
            'title'   => (string) $post->post_title,
            'status'  => (string) $post->post_status,
            'content' => (string) $post->post_content,
            'url'     => (string) get_permalink($id),
        ];
        foreach (self::MEMBERSHIP_FIELDS + self::MEMBERSHIP_STORED as $arg => $field) {
            $out[ $arg ] = self::stored($id, $field);
        }
        $out['active_members'] = self::active_members([ $id ])[ $id ] ?? 0;
        return $out;
    }

    /**
     * Distinct users with a granting, unexpired transaction per membership,
     * the test MemberPress applies to decide who is an active member.
     *
     * @param int[] $ids
     * @return array<int, int> membership id => active members
     */
    private static function active_members(array $ids): array
    {
        global $wpdb;
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ([] === $ids || ! Plugin_Table_Rows_Snapshot::table_exists(self::table('transactions'))) {
            return [];
        }
        $in   = implode(',', array_fill(0, count($ids), '%d'));
        $sql  = "SELECT product_id, COUNT(DISTINCT user_id) AS members FROM %i WHERE product_id IN ($in) AND status IN (%s, %s) AND (expires_at IS NULL OR expires_at = %s OR expires_at >= %s) GROUP BY product_id";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d placeholders.
        $rows = $wpdb->get_results($wpdb->prepare($sql, self::table('transactions'), ...array_merge($ids, self::GRANTING, [ self::LIFETIME, gmdate('Y-m-d H:i:s') ])), ARRAY_A);
        $out  = [];
        foreach ((array) $rows as $row) {
            $out[ (int) $row['product_id'] ] = (int) $row['members'];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    private static function paging(array $args): array
    {
        return [ max(1, (int) ($args['page'] ?? 1)), max(1, min(self::MAX_PER_PAGE, (int) ($args['per_page'] ?? 20))) ];
    }

    private static function list_memberships(array $args): array
    {
        [$page, $per_page] = self::paging($args);
        $query             = new \WP_Query([
            'post_type'      => self::MEMBERSHIP_TYPE,
            'post_status'    => isset($args['status']) ? (string) $args['status'] : self::POST_STATUSES,
            's'              => (string) ($args['search'] ?? ''),
            'orderby'        => [ 'date' => 'DESC', 'ID' => 'DESC' ],
            'posts_per_page' => $per_page,
            'paged'          => $page,
        ]);
        $ids               = array_map(static fn (\WP_Post $p): int => (int) $p->ID, $query->posts);
        $members           = self::active_members($ids);
        $rows              = [];
        foreach ($query->posts as $post) {
            $id     = (int) $post->ID;
            $rows[] = [
                'id'             => $id,
                'title'          => (string) $post->post_title,
                'status'         => (string) $post->post_status,
                'price'          => self::stored($id, self::MEMBERSHIP_FIELDS['price']),
                'period'         => self::stored($id, self::MEMBERSHIP_FIELDS['period']),
                'period_type'    => self::stored($id, self::MEMBERSHIP_FIELDS['period_type']),
                'trial'          => self::stored($id, self::MEMBERSHIP_FIELDS['trial']),
                'trial_days'     => self::stored($id, self::MEMBERSHIP_FIELDS['trial_days']),
                'trial_amount'   => self::stored($id, self::MEMBERSHIP_FIELDS['trial_amount']),
                'active_members' => $members[ $id ] ?? 0,
            ];
        }
        return [ 'memberships' => $rows, 'total' => (int) $query->found_posts, 'page' => $page, 'per_page' => $per_page ];
    }

    /** @return int[] a comma-separated id list as ints. */
    private static function id_list(?string $list): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string) $list))));
    }

    private static function list_members(array $args): array
    {
        global $wpdb;
        [$page, $per_page] = self::paging($args);
        $table             = self::table('members');
        if (! Plugin_Table_Rows_Snapshot::table_exists($table)) {
            return [ 'members' => [], 'total' => 0, 'page' => $page, 'per_page' => $per_page ];
        }

        // The filters of MemberPress's own Members screen (MeprUser::list_table()).
        $where  = [ '1=1' ];
        $params = [];
        $status = (string) ($args['status'] ?? 'all');
        if ('active' === $status) {
            $where[] = '(m.active_txn_count > 0 OR m.trial_txn_count > 0)';
        } elseif ('inactive' === $status) {
            $where[] = 'm.active_txn_count <= 0 AND m.expired_txn_count > 0 AND m.trial_txn_count <= 0';
        } elseif ('expired' === $status) {
            $where[] = "m.inactive_memberships <> ''";
        } elseif ('none' === $status) {
            $where[] = 'm.active_txn_count <= 0 AND m.expired_txn_count <= 0 AND m.trial_txn_count <= 0';
        }
        if (isset($args['membership_id'])) {
            $pattern = sprintf('(^|,)%d(,|$)', (int) $args['membership_id']);
            if ('active' === $status) {
                $where[]  = 'm.memberships RLIKE %s';
                $params[] = $pattern;
            } elseif (in_array($status, [ 'inactive', 'expired' ], true)) {
                $where[]  = 'm.inactive_memberships RLIKE %s';
                $params[] = $pattern;
            } else {
                $where[]  = '(m.memberships RLIKE %s OR m.inactive_memberships RLIKE %s)';
                $params[] = $pattern;
                $params[] = $pattern;
            }
        }
        if ('' !== (string) ($args['search'] ?? '')) {
            $like     = '%' . $wpdb->esc_like((string) $args['search']) . '%';
            $where[]  = '(u.user_login LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s)';
            $params   = array_merge($params, [ $like, $like, $like ]);
        }
        $from = 'FROM %i m JOIN %i u ON u.ID = m.user_id WHERE ' . implode(' AND ', $where);
        $head = [ $table, $wpdb->users ];

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $from is built from literals and placeholders only.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) {$from}", ...array_merge($head, $params)));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $from is built from literals and placeholders only.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT m.*, u.user_login, u.user_email, u.display_name {$from} ORDER BY m.created_at DESC, m.id DESC LIMIT %d OFFSET %d", ...array_merge($head, $params, [ $per_page, ($page - 1) * $per_page ])), ARRAY_A);

        $members = [];
        foreach ((array) $rows as $row) {
            $members[] = [
                'user_id'              => (int) $row['user_id'],
                'user_login'           => (string) $row['user_login'],
                'display_name'         => (string) $row['display_name'],
                'email'                => (string) $row['user_email'],
                'active_memberships'   => self::id_list($row['memberships'] ?? ''),
                'inactive_memberships' => self::id_list($row['inactive_memberships'] ?? ''),
                'transactions'         => (int) ($row['txn_count'] ?? 0),
                'active_subscriptions' => (int) ($row['active_sub_count'] ?? 0),
                'total_spent'          => number_format((float) ($row['total_spent'] ?? 0), 2, '.', ''),
                'member_since'         => $row['created_at'] ?? null,
            ];
        }
        return [ 'members' => $members, 'total' => $total, 'page' => $page, 'per_page' => $per_page ];
    }

    /** The transaction columns a caller sees; the gateway's raw response is never one of them. */
    private const TRANSACTION_COLUMNS = [ 'id', 'user_id', 'product_id', 'amount', 'tax_amount', 'total', 'currency', 'status', 'txn_type', 'gateway', 'trans_num', 'subscription_id', 'coupon_id', 'created_at', 'expires_at', 'refunded_at' ];

    private static function list_transactions(array $args): array
    {
        global $wpdb;
        [$page, $per_page] = self::paging($args);
        $table             = self::table('transactions');
        if (! Plugin_Table_Rows_Snapshot::table_exists($table)) {
            return [ 'transactions' => [], 'total' => 0, 'page' => $page, 'per_page' => $per_page ];
        }
        $live    = Plugin_Table_Rows_Snapshot::columns($table);
        $columns = array_values(array_intersect(self::TRANSACTION_COLUMNS, $live));

        $where  = [ '1=1' ];
        $params = [];
        foreach ([ 'membership_id' => 'product_id', 'user_id' => 'user_id' ] as $arg => $column) {
            if (isset($args[ $arg ])) {
                $where[]  = "{$column} = %d";
                $params[] = (int) $args[ $arg ];
            }
        }
        if (isset($args['status'])) {
            $where[]  = 'status = %s';
            $params[] = (string) $args['status'];
        }
        $cond   = implode(' AND ', $where);
        $select = implode(', ', array_fill(0, count($columns), '%i'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $cond is built from literals and placeholders only.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE {$cond}", $table, ...$params));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $select and $cond are built from literals and placeholders only.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT {$select} FROM %i WHERE {$cond} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", ...array_merge($columns, [ $table ], $params, [ $per_page, ($page - 1) * $per_page ])), ARRAY_A);

        $out = [];
        foreach ((array) $rows as $row) {
            $txn = [];
            foreach ($row as $column => $value) {
                $name         = 'product_id' === $column ? 'membership_id' : (string) $column;
                $txn[ $name ] = in_array($column, [ 'id', 'user_id', 'product_id', 'subscription_id', 'coupon_id' ], true) ? (null === $value ? null : (int) $value) : $value;
            }
            $out[] = $txn;
        }
        return [ 'transactions' => $out, 'total' => $total, 'page' => $page, 'per_page' => $per_page ];
    }

    /** @return array<int, array{type: string, operator: string, condition: string}> */
    private static function access(int $rule): array
    {
        global $wpdb;
        $table = self::access_table();
        if (! Plugin_Table_Rows_Snapshot::table_exists($table)) {
            return [];
        }
        $rows = $wpdb->get_results($wpdb->prepare('SELECT access_type, access_operator, access_condition FROM %i WHERE rule_id = %d ORDER BY id ASC', $table, $rule), ARRAY_A);
        return array_map(
            static fn (array $r): array => [ 'type' => (string) $r['access_type'], 'operator' => (string) $r['access_operator'], 'condition' => (string) $r['access_condition'] ],
            (array) $rows
        );
    }

    private static function access_table(): string
    {
        return (string) Plugin_Table_Rows_Snapshot::table('mepr_rule_access', 'conditions');
    }

    private static function rule(int $id): array
    {
        $post = get_post($id);
        $out  = [
            'id'      => $id,
            'title'   => (string) $post->post_title,
            'status'  => (string) $post->post_status,
            'type'    => (string) get_post_meta($id, self::RULE_TYPE_KEY, true),
            'content' => (string) get_post_meta($id, self::RULE_CONTENT_KEY, true),
            'access'  => self::access($id),
        ];
        foreach (self::RULE_FIELDS as $arg => $field) {
            $out[ $arg ] = self::stored($id, $field);
        }
        return $out;
    }

    private static function list_rules(array $args): array
    {
        global $wpdb;
        [$page, $per_page] = self::paging($args);
        $query_args        = [
            'post_type'      => self::RULE_TYPE,
            'post_status'    => isset($args['status']) ? (string) $args['status'] : self::POST_STATUSES,
            'orderby'        => [ 'date' => 'DESC', 'ID' => 'DESC' ],
            'posts_per_page' => $per_page,
            'paged'          => $page,
        ];
        if (isset($args['membership_id'])) {
            $table = self::access_table();
            $ids   = Plugin_Table_Rows_Snapshot::table_exists($table)
                ? array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT rule_id FROM %i WHERE access_type = 'membership' AND access_condition = %s", $table, (string) (int) $args['membership_id'])))
                : [];
            $query_args['post__in'] = [] === $ids ? [ 0 ] : $ids;
        }
        $query = new \WP_Query($query_args);
        $rules = array_map(static fn (\WP_Post $p): array => self::rule((int) $p->ID), $query->posts);
        return [ 'rules' => $rules, 'total' => (int) $query->found_posts, 'page' => $page, 'per_page' => $per_page ];
    }

    // ------------------------------------------------------------------
    // Refusals (run before any snapshot)
    // ------------------------------------------------------------------

    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    /** Null when $id is a live post of $type the user may read (or, with $write, edit). */
    private static function post_refusal(int $id, string $type, bool $write): ?array
    {
        $post = get_post($id);
        if (! $post instanceof \WP_Post || $type !== $post->post_type || 'trash' === $post->post_status) {
            $noun = self::MEMBERSHIP_TYPE === $type ? 'membership' : 'rule';
            return self::refusal("{$noun}_not_found", sprintf('%d is not a MemberPress %s.', $id, $noun), [ 'id' => $id ]);
        }
        if (! current_user_can($write ? 'edit_post' : 'read_post', $id)) {
            return self::refusal('operation_denied', sprintf('You are not allowed to %s post %d.', $write ? 'edit' : 'read', $id), [ 'reason' => 'capability', 'id' => $id ]);
        }
        return null;
    }

    /** Null when the user may create a post of $type with $status. */
    private static function create_refusal(string $type, string $status): ?array
    {
        $object = get_post_type_object($type);
        if (! $object instanceof \WP_Post_Type) {
            return self::refusal('post_type_missing', sprintf('MemberPress has not registered its %s post type.', $type));
        }
        $caps = [ $object->cap->create_posts ];
        if (in_array($status, [ 'publish', 'private' ], true)) {
            $caps[] = $object->cap->publish_posts;
        }
        foreach ($caps as $cap) {
            if (! current_user_can($cap)) {
                return self::refusal('operation_denied', sprintf('This needs the "%s" capability.', $cap), [ 'reason' => 'capability' ]);
            }
        }
        return null;
    }

    /** Publishing an existing post needs its type's publish_posts. */
    private static function publish_refusal(int $id, array $args): ?array
    {
        if (! in_array($args['status'] ?? '', [ 'publish', 'private' ], true)) {
            return null;
        }
        $object = get_post_type_object((string) get_post_type($id));
        if ($object instanceof \WP_Post_Type && ! current_user_can($object->cap->publish_posts)) {
            return self::refusal('operation_denied', sprintf('Publishing needs the "%s" capability.', $object->cap->publish_posts), [ 'reason' => 'capability' ]);
        }
        return null;
    }

    /**
     * The checks MeprProduct::validate() makes that a schema cannot, on the
     * settings the membership will have after the write ($current for an
     * update).
     */
    private static function membership_refusal(array $args, array $current): ?array
    {
        $merged = $args + $current;
        if ('fixed' === ($merged['expire_type'] ?? 'none') && 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($merged['expire_fixed'] ?? ''))) {
            return self::refusal('invalid_setting', 'A fixed expiry (expire_type fixed) needs expire_fixed as YYYY-MM-DD.', [ 'field' => 'expire_fixed' ]);
        }
        if ('' !== (string) ($args['access_url'] ?? '') && false === wp_http_validate_url((string) $args['access_url'])) {
            return self::refusal('invalid_setting', 'access_url must be a URL.', [ 'field' => 'access_url' ]);
        }
        return null;
    }

    private static function update_membership_refusal(array $args): ?array
    {
        $id = (int) $args['id'];
        if ([] === array_diff(array_keys($args), [ 'id' ])) {
            return self::refusal('nothing_to_update', 'Pass at least one field to change.');
        }
        $current = [];
        if (null === self::post_refusal($id, self::MEMBERSHIP_TYPE, true)) {
            foreach ([ 'expire_type', 'expire_fixed' ] as $arg) {
                $current[ $arg ] = self::stored($id, self::MEMBERSHIP_FIELDS[ $arg ]);
            }
        }
        return self::post_refusal($id, self::MEMBERSHIP_TYPE, true)
            ?? self::publish_refusal($id, $args)
            ?? self::membership_refusal($args, $current);
    }

    /** The rule types this site accepts: MemberPress's own list when it is loaded. */
    private static function rule_type_refusal(string $type): ?array
    {
        if (class_exists('MeprRule') && method_exists('MeprRule', 'get_types')) {
            $known = array_map('strval', array_keys((array) \MeprRule::get_types()));
            $ok    = in_array($type, $known, true);
        } else {
            $ok = in_array($type, self::BASE_RULE_TYPES, true)
                || 1 === preg_match('/^(all|single|parent)_[a-z0-9_-]+$/', $type)
                || 1 === preg_match('/^all_tax_[a-z0-9_-]+$/', $type)
                || 1 === preg_match('/^tax_[a-z0-9_-]+\|\|cpt_[a-z0-9_-]+$/', $type);
        }
        return $ok ? null : self::refusal('invalid_rule_type', sprintf('"%s" is not a MemberPress rule type on this site.', $type), [ 'type' => $type ]);
    }

    /** Whether a rule type protects named content and so needs content (MeprRulesCtrl::validate_rule_content()). */
    private static function needs_content(string $type): bool
    {
        return 'all' !== $type && 'partial' !== $type && (! str_starts_with($type, 'all_') || str_starts_with($type, 'all_tax_'));
    }

    /** Null when the condition names something that exists for its type. */
    private static function condition_refusal(string $type, string $condition): ?array
    {
        $ok = true;
        switch ($type) {
            case 'membership':
                $post = ctype_digit($condition) ? get_post((int) $condition) : null;
                $ok   = $post instanceof \WP_Post && self::MEMBERSHIP_TYPE === $post->post_type && 'trash' !== $post->post_status;
                break;
            case 'member':
                $ok = false !== get_user_by('login', $condition);
                break;
            case 'role':
                $ok = null !== get_role($condition);
                break;
            case 'login_state':
                $ok = in_array($condition, [ 'logged_in', 'guest' ], true);
                break;
            case 'capability':
                $ok = 1 === preg_match('/^[a-z0-9_-]+$/i', $condition);
                break;
        }
        return $ok ? null : self::refusal('invalid_condition', sprintf('"%s" is not a valid %s for a MemberPress access condition.', $condition, $type), [ 'type' => $type, 'condition' => $condition ]);
    }

    /** The checks MeprRule::validate() and the rule screen make, on the rule after the write. */
    private static function rule_refusal(array $args, array $current): ?array
    {
        $merged = $args + $current;
        $type   = (string) ($merged['type'] ?? 'all');
        $out    = self::rule_type_refusal($type);
        if (null !== $out) {
            return $out;
        }
        if (self::needs_content($type) && '' === trim((string) ($merged['content'] ?? ''))) {
            return self::refusal('content_required', sprintf('A "%s" rule needs content: what it protects (a post id, term, URI or pattern).', $type), [ 'type' => $type ]);
        }
        foreach ([ 'drip', 'expires' ] as $what) {
            if ('fixed' === ($merged[ "{$what}_after" ] ?? '') && 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($merged[ "{$what}_after_fixed" ] ?? ''))) {
                return self::refusal('invalid_setting', sprintf('%1$s_after fixed needs %1$s_after_fixed as YYYY-MM-DD.', $what), [ 'field' => "{$what}_after_fixed" ]);
            }
        }
        if ($merged['is_regexp'] ?? false) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- testing whether the caller's pattern compiles.
            if (false === @preg_match('#' . str_replace('#', '\#', (string) ($merged['content'] ?? '')) . '#', '')) {
                return self::refusal('invalid_setting', 'content is not a valid regular expression.', [ 'field' => 'content' ]);
            }
        }
        foreach ((array) ($args['access'] ?? []) as $condition) {
            $out = self::condition_refusal((string) $condition['type'], (string) $condition['condition']);
            if (null !== $out) {
                return $out;
            }
        }
        return null;
    }

    private static function update_rule_refusal(array $args): ?array
    {
        $id = (int) $args['id'];
        if ([] === array_diff(array_keys($args), [ 'id' ])) {
            return self::refusal('nothing_to_update', 'Pass at least one field to change.');
        }
        $refusal = self::post_refusal($id, self::RULE_TYPE, true) ?? self::publish_refusal($id, $args);
        if (null !== $refusal) {
            return $refusal;
        }
        $current = [
            'type'    => (string) get_post_meta($id, self::RULE_TYPE_KEY, true),
            'content' => (string) get_post_meta($id, self::RULE_CONTENT_KEY, true),
        ];
        foreach ([ 'is_regexp', 'drip_after', 'drip_after_fixed', 'expires_after', 'expires_after_fixed' ] as $arg) {
            $current[ $arg ] = self::stored($id, self::RULE_FIELDS[ $arg ]);
        }
        return self::rule_refusal($args, $current);
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /** Run $write under a snapshot of one object; returns the operation id. */
    private static function snapshotted(string $type, $object_id, array $context, array $args, callable $write): string
    {
        $out = Safe_Mutation::run(
            [
                'object_type' => $type,
                'object_id'   => $object_id,
                'session_id'  => (string) $context['session_id'],
                'tool_name'   => (string) $context['tool_name'],
                'args'        => [ 'operation' => (string) $context['operation'], 'args' => $args ],
            ],
            static function () use ($write): bool {
                $write();
                return true;
            }
        );
        return (string) $out['operation_id'];
    }

    /** Insert a post of $type and record it for rollback; returns [id, operation id]. */
    private static function insert(string $type, array $args, array $context, string $title, string $content): array
    {
        $id = wp_insert_post([
            'post_type'    => $type,
            'post_title'   => wp_slash($title),
            'post_content' => wp_slash($content),
            'post_status'  => (string) ($args['status'] ?? 'draft'),
            'post_author'  => get_current_user_id(),
        ], true);
        if (is_wp_error($id) || 0 === (int) $id) {
            throw new Operation_Refused('insert_failed', 'MemberPress could not create the post.');
        }
        $id = (int) $id;
        return [ $id, Post_Creation_Snapshot::record((string) $context['tool_name'], [ $id ], [ 'operation' => $context['operation'], 'args' => $args ], (string) $context['session_id']) ];
    }

    /** Edit a post's own fields from title, content and status in $args. */
    private static function update_post_fields(int $id, array $args): void
    {
        $fields = [ 'ID' => $id ];
        foreach ([ 'title' => 'post_title', 'content' => 'post_content', 'status' => 'post_status' ] as $arg => $column) {
            if (array_key_exists($arg, $args)) {
                $fields[ $column ] = wp_slash((string) $args[ $arg ]);
            }
        }
        if (1 === count($fields)) {
            return;
        }
        $out = Save_Filters::update_post($fields, true);
        if (is_wp_error($out)) {
            throw new Operation_Refused('update_failed', $out->get_error_message());
        }
        clean_post_cache($id);
    }

    /**
     * Write settings from $args to post meta; with $defaults every field of
     * $fields is written (the given value or MemberPress's default), as
     * store_meta() does for a new post.
     *
     * @param array<string, array{0: string, 1: mixed, 2: string}> $fields
     */
    private static function write_meta(int $id, array $fields, array $args, bool $defaults): void
    {
        foreach ($fields as $arg => [$key, $default, $kind]) {
            if (array_key_exists($arg, $args)) {
                $value = $args[ $arg ];
            } elseif ($defaults) {
                $value = $default;
            } else {
                continue;
            }
            update_post_meta($id, $key, wp_slash(self::storable($value, $kind)));
        }
    }

    private static function create_membership(array $args, array $context): array
    {
        [$id, $op] = self::insert(self::MEMBERSHIP_TYPE, $args, $context, (string) $args['title'], (string) ($args['content'] ?? ''));
        self::write_meta($id, self::MEMBERSHIP_FIELDS + self::MEMBERSHIP_STORED, $args, true);
        return [ 'result' => [ 'membership' => self::membership($id) ], 'operation_ids' => [ $op ] ];
    }

    /** Edit one membership (the dispatcher snapshots the post, meta included, first). */
    private static function update_membership(array $args): array
    {
        $id = (int) $args['id'];
        self::update_post_fields($id, $args);
        self::write_meta($id, self::MEMBERSHIP_FIELDS, $args, false);
        return [ 'membership' => self::membership($id) ];
    }

    /** Replace every access condition of a rule, the way the rule screen saves them. */
    private static function store_access(int $rule, array $access): void
    {
        global $wpdb;
        $table = self::access_table();
        if (! Plugin_Table_Rows_Snapshot::table_exists($table)) {
            throw new Operation_Refused('memberpress_table_missing', 'MemberPress\'s access conditions table is missing; deactivate and reactivate MemberPress to rebuild it.');
        }
        if (false === $wpdb->delete($table, [ 'rule_id' => $rule ], [ '%d' ])) {
            throw new Operation_Refused('write_failed', 'Could not clear the rule\'s access conditions.');
        }
        $seen = [];
        foreach ($access as $condition) {
            $row = [ 'rule_id' => $rule, 'access_type' => (string) $condition['type'], 'access_operator' => 'is', 'access_condition' => (string) $condition['condition'] ];
            $key = $row['access_type'] . "\0" . $row['access_condition'];
            if (isset($seen[ $key ])) {
                continue; // MeprRuleAccessCondition::create() never stores a duplicate.
            }
            $seen[ $key ] = true;
            if (false === $wpdb->insert($table, $row)) {
                throw new Operation_Refused('write_failed', 'Could not store an access condition: ' . $wpdb->last_error);
            }
        }
    }

    /** A title for a rule created without one, as the rule screen would show it. */
    private static function rule_title(array $args): string
    {
        $type    = (string) $args['type'];
        $label   = $type;
        if (class_exists('MeprRule') && method_exists('MeprRule', 'get_types')) {
            $label = (string) (\MeprRule::get_types()[ $type ] ?? $type);
        }
        $content = trim((string) ($args['content'] ?? ''));
        return '' === $content ? $label : sprintf('%s: %s', $label, $content);
    }

    private static function create_rule(array $args, array $context): array
    {
        $title     = (string) ($args['title'] ?? self::rule_title($args));
        [$id, $op] = self::insert(self::RULE_TYPE, $args, $context, $title, '');
        $ops       = [ $op ];
        $stored    = [ 'auto_gen_title' => [ '_mepr_auto_gen_title', ! isset($args['title']), 'bool' ] ] + self::RULE_STORED;
        update_post_meta($id, self::RULE_TYPE_KEY, wp_slash((string) $args['type']));
        update_post_meta($id, self::RULE_CONTENT_KEY, wp_slash('partial' === $args['type'] ? '' : (string) ($args['content'] ?? '')));
        self::write_meta($id, self::RULE_FIELDS + $stored, $args, true);
        $ops[] = self::snapshotted(Plugin_Table_Rows_Snapshot::TYPE, Plugin_Table_Rows_Snapshot::key('mepr_rule_access', 'conditions', [ $id ]), $context, $args, static fn () => self::store_access($id, (array) $args['access']));
        return [ 'result' => [ 'rule' => self::rule($id) ], 'operation_ids' => $ops ];
    }

    private static function update_rule(array $args, array $context): array
    {
        $id  = (int) $args['id'];
        $ops = [
            self::snapshotted('post', $id, $context, $args, static function () use ($id, $args): void {
                self::update_post_fields($id, $args);
                if (array_key_exists('type', $args)) {
                    update_post_meta($id, self::RULE_TYPE_KEY, wp_slash((string) $args['type']));
                }
                if (array_key_exists('content', $args)) {
                    update_post_meta($id, self::RULE_CONTENT_KEY, wp_slash((string) $args['content']));
                }
                if (isset($args['title'])) {
                    update_post_meta($id, '_mepr_auto_gen_title', false);
                }
                self::write_meta($id, self::RULE_FIELDS, $args, false);
            }),
        ];
        if (array_key_exists('access', $args)) {
            $ops[] = self::snapshotted(Plugin_Table_Rows_Snapshot::TYPE, Plugin_Table_Rows_Snapshot::key('mepr_rule_access', 'conditions', [ $id ]), $context, $args, static fn () => self::store_access($id, (array) $args['access']));
        }
        return [ 'result' => [ 'rule' => self::rule($id) ], 'operation_ids' => $ops ];
    }
    // phpcs:enable
}
