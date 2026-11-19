<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Custom field and translation plugins beyond ACF, Meta Box and Polylang
 * (issue #299) behind one free dispatcher pair, wpmcp/plugin-data-read and
 * wpmcp/plugin-data-write, so several plugins cost the tools/list surface
 * one pair rather than one each:
 *  - JetEngine: field definitions per post type and post field values
 *    (JetEngine_Pack);
 *  - Pods: field definitions per post type pod and post field values, in
 *    post meta or in the pod's own table when it uses table storage
 *    (Pods_Pack);
 *  - TranslatePress: languages and string translations in its dictionary
 *    tables (TranslatePress_Pack);
 *  - BuddyPress (issue #354): groups, group members, activity and extended
 *    profile field definitions, with group writes, profile field updates
 *    and activity moderation (BuddyPress_Pack).
 *  - Code Snippets (issue #576): PHP snippets listed, read, created
 *    inactive and edited behind the static check, and deactivated, with
 *    'code_snippet' row snapshots (Code_Snippets_Pack, presence through
 *    wpmcp_code_snippets_active).
 *  - Issue #676 adds the WS Form ops (WS_Form_Pack): forms, fields,
 *    actions and email notifications, submissions listed with every value
 *    masked and read one at a time in full, and status changes and deletes
 *    (off until a site opts in), each under a db_rows before-image.
 *  - Issue #682 adds the link management ops: Pretty Links links
 *    (Pretty_Links_Pack, rows of its prli_links table) and ThirstyAffiliates
 *    links (Thirsty_Affiliates_Pack, thirstylink posts), read with click
 *    counts and masked visitor IPs, and created, changed and trashed (off
 *    until wpmcp_enable_pretty_links_write or
 *    wpmcp_enable_thirstyaffiliates_write), each write undoable.
 *  - Issue #688 adds the Advanced Ads ops (Advanced_Ads_Pack): ads and
 *    placements listed and read, ads created as drafts, changed, enabled
 *    and disabled, and placements pointed at an ad or group (off until
 *    wpmcp_enable_advanced_ads_write), each write under a post snapshot.
 *  - Issue #687 adds the Site Reviews ops (Site_Reviews_Pack): reviews
 *    listed (reviewer emails and IPs masked), read one at a time and
 *    summarised, and approved, unapproved, trashed or replied to (off until
 *    wpmcp_enable_site_reviews_write), each write under a post snapshot.
 *  - Issue #394 adds the paid-tier LMS ops (LMS_Pack): Tutor LMS,
 *    LifterLMS and LearnDash course, section, lesson and quiz trees with
 *    snapshotted writes, and read-only enrollments (LearnDash also enrolls,
 *    unenrolls and resets progress, each snapshotted).
 *  - Issue #701 adds the paid-tier WP Offload Media ops
 *    (Offload_Media_Pack): offload status, bucket and counts, and per
 *    attachment whether it is in the bucket and the URL the site serves.
 *  - Issue #697 adds the paid-tier WPCode ops (WPCode_Pack): snippets and
 *    the global header and footer scripts read, snippets created inactive,
 *    changed, activated behind the PHP execution or code injection gate and
 *    deactivated, and the global scripts replaced, each write off until
 *    wpmcp_enable_wpcode_write and undoable.
 *  - Issue #481 adds the paid-tier TablePress ops (TablePress_Pack): tables
 *    read, edited cell by cell, imported, exported and placed through
 *    TablePress's own table model, with snapshotted writes.
 *  - Issue #477 adds the paid-tier CRM ops (CRM_Pack): FluentCRM and
 *    MailPoet contacts, lists, tags, campaigns and automations, with writes
 *    through each plugin's own API, snapshotted as 'crm_rows' images.
 *  - Issue #499 adds the paid-tier Amelia ops (Amelia_Pack): services,
 *    employees, customers, appointments and events, with writes through
 *    Amelia's own application layer, snapshotted as 'amelia_rows' images.
 *  - Issue #498 adds the paid-tier page translation ops (I18n_Pack): for
 *    Polylang and WPML, find untranslated posts, create a linked
 *    translation, and read and write a page's text as segments.
 *  - bbPress (BbPress_Pack): forums, topics and replies, posting topics
 *    and replies, and topic moderation (close, stick, spam) through
 *    bbPress's own API, snapshotted as 'bbpress_posts' images.
 *  - Issue #668 adds the paid-tier MemberPress ops (MemberPress_Pack):
 *    memberships, rules and their access conditions, read and written as
 *    MemberPress stores them, plus read-only members and transactions.
 *  - Issue #654 adds the paid-tier Elementor Pro ops
 *    (Elementor_Pro_Forms_Pack): forms found in pages, and form submissions
 *    read, exported, marked read or trashed, and deleted, each write under
 *    a db_rows before-image.
 *  - Issue #698 adds the read-only activity log ops (Activity_Log_Pack):
 *    WP Activity Log and Simple History events listed, with IPs and emails
 *    masked, and read one at a time in full.
 *  - Issue #905 adds the read-only email log ops (Email_Log_Pack): WP Mail
 *    Logging, Check and Log Email and Email Log emails listed with
 *    recipients masked and no bodies, and read one at a time with the body
 *    cut and one-time links removed. Resend and delete are not part of it.
 *  - Issue #708 adds the paid-tier knowledge base and support desk ops:
 *    BetterDocs docs read, created and edited (BetterDocs_Pack), and
 *    Fluent Support and Awesome Support tickets read with customer emails
 *    masked in lists, replied to, noted and set to a status, each ticket
 *    write off until wpmcp_enable_helpdesk_write and snapshotted as a
 *    'helpdesk_ticket' image (Helpdesk_Pack).
 *  - Issue #690 adds the paid-tier translation settings ops
 *    (Translation_Settings_Pack): Weglot languages and exclusions read, and
 *    GTranslate languages read and set (off until
 *    wpmcp_enable_translation_settings_write), the write snapshotted on the
 *    GTranslate option.
 *  - Issue #660 adds the paid-tier Checkout Field Editor for WooCommerce
 *    ops (Checkout_Field_Editor_Pack): billing, shipping and additional
 *    checkout fields listed, and existing fields changed (off until
 *    wpmcp_enable_checkout_field_editor_write), the write snapshotted on the
 *    section's option.
 *  - Issue #665 adds the paid-tier SureCart ops (SureCart_Pack): products,
 *    orders and customers (masked) and coupons read from SureCart's cloud,
 *    and product edits and coupon creates (off until
 *    wpmcp_enable_surecart_write), each snapshotted as a 'surecart_object'
 *    image read from SureCart's API.
 *  - Issue #702 adds the Action Scheduler ops (Action_Scheduler_Pack): queue
 *    actions listed with args masked and read one at a time with their log,
 *    and pending actions canceled (undoable, as an 'action_scheduler'
 *    snapshot) or run now (not undoable, confirm:true), both off until
 *    wpmcp_enable_action_scheduler_write.
 *  - Issue #666 adds read-only WP Simple Pay payment forms (Simple_Pay_Pack:
 *    status, test or live mode, display type, prices) and Paid Member
 *    Subscriptions plans and members (Paid_Member_Subscriptions_Pack,
 *    member names and emails masked).
 *  - Issue #704 adds the read-only WPGraphQL ops (WPGraphQL_Pack): a
 *    GraphQL query run as the current user with mutations and
 *    subscriptions refused and personal values masked, and a schema
 *    summary without the mutation root.
 *  - Issue #669 adds the Restrict Content ops (Restrict_Content_Pack:
 *    membership levels with active member counts, memberships with member
 *    names and emails masked, and level changes off until
 *    wpmcp_enable_restrict_content_write, snapshotted as a
 *    'plugin_table_rows' image) and the Ultimate Member ops
 *    (Ultimate_Member_Pack: roles, members masked, forms and member
 *    directories, and a member's UM role set behind confirm:true, off until
 *    wpmcp_enable_ultimate_member_write, snapshotted as a 'user' snapshot).
 *  - Issue #664 adds Easy Digital Downloads (EDD_Pack): downloads, orders,
 *    customers (masked in lists), discounts and a sales summary, and
 *    download and discount writes (undoable, off until
 *    wpmcp_enable_edd_write).
 *
 * The pair registers only while at least one of them is loaded (the LMS
 * and TablePress ops only on an install that may run the paid tier), and
 * every op declares a 'requires' check, so a plugin that is not active is
 * skipped cleanly: its ops stay documented in list-operations
 * (dependency_met:false) and answer <plugin>_inactive without touching
 * anything. Presence is filterable per plugin (wpmcp_jetengine_active,
 * wpmcp_pods_active, wpmcp_translatepress_active, wpmcp_buddypress_active,
 * wpmcp_bbpress_active).
 *
 * Every write is snapshotted: field values in post meta as a post snapshot,
 * Pods table rows and TranslatePress dictionary rows as a
 * 'plugin_table_rows' row image, BuddyPress rows as a 'buddypress_rows'
 * image, so rollback-operation restores exactly.
 * Field value writes follow the ACF and Meta Box posture and are off until a
 * site opts in (wpmcp_enable_jetengine_write, wpmcp_enable_pods_write).
 */
final class Plugin_Data_Integration extends Integration_Dispatcher
{
    public function integration(): string
    {
        return 'plugin-data';
    }

    public function is_available(): bool
    {
        return BbPress_Pack::active() || JetEngine_Pack::active() || Pods_Pack::active() || TranslatePress_Pack::active() || Code_Snippets_Pack::active() || WS_Form_Pack::active() || Activity_Log_Pack::active() || Action_Scheduler_Pack::active() || Email_Log_Pack::active() || Pretty_Links_Pack::active() || Thirsty_Affiliates_Pack::active() || Advanced_Ads_Pack::active() || Site_Reviews_Pack::active() || WPGraphQL_Pack::active() || Simple_Pay_Pack::active() || Paid_Member_Subscriptions_Pack::active() || Restrict_Content_Pack::active() || Ultimate_Member_Pack::active() || EDD_Pack::active() || BuddyPress_Pack::active()
            || LMS_Pack::active()
            || Offload_Media_Pack::active()
            || WPCode_Pack::active()
            || TablePress_Pack::active()
            || CRM_Pack::active()
            || Amelia_Pack::active()
            || I18n_Pack::active()
            || MemberPress_Pack::active()
            || Elementor_Pro_Forms_Pack::active()
            || BetterDocs_Pack::active()
            || Helpdesk_Pack::active()
            || Translation_Settings_Pack::active()
            || Checkout_Field_Editor_Pack::active()
            || SureCart_Pack::active();
    }

    public function registers_only_when_available(): bool
    {
        return true;
    }

    protected function summary(): string
    {
        return 'JetEngine and Pods fields, TranslatePress strings, Code Snippets, BuddyPress and BuddyBoss Platform community data, bbPress forums, WS Form forms, WP Activity Log and Simple History logs, Pretty Links and ThirstyAffiliates links, Advanced Ads ads and placements, Action Scheduler queues, Site Reviews reviews, WP Mail Logging, Check and Log Email and Email Log emails, WPGraphQL queries, WP Simple Pay forms, Paid Member Subscriptions plans, Restrict Content and Restrict Content Pro memberships, Ultimate Member roles and forms, Easy Digital Downloads stores'
            . ', Tutor LMS, LifterLMS and LearnDash courses, TablePress tables'
            . ', WP Offload Media status'
            . ', WPCode snippets'
            . ', FluentCRM and MailPoet'
            . ', Amelia bookings, Polylang and WPML translations, MemberPress memberships, Elementor Pro forms'
            . ', BetterDocs docs, Fluent Support and Awesome Support tickets'
            . ', Weglot and GTranslate language settings, Loco Translate .po strings'
            . ', Checkout Field Editor fields'
            . ', SureCart store data';
    }

    /**
     * The plugin each op needs (issue #815), so a refusal while none of
     * them is active names the one plugin the asked op works with: every
     * pack operations() merges, a pack with adapters by each adapter's own
     * label, and ops either of two plugins serves by both. Ops this
     * install's tier may not run are left out, since activating the plugin
     * alone would not bring them back.
     */
    public function operation_hosts(): array
    {
        $groups = [
            [ 'bbPress, BuddyBoss Platform', true, BbPress_Pack::operations() ],
            [ 'JetEngine', false, JetEngine_Pack::operations() ],
            [ 'Pods', false, Pods_Pack::operations() ],
            [ 'TranslatePress', false, TranslatePress_Pack::operations() ],
            [ 'Code Snippets', false, Code_Snippets_Pack::operations() ],
            [ 'BuddyPress, BuddyBoss Platform', true, BuddyPress_Pack::operations() ],
            [ 'WS Form', false, WS_Form_Pack::operations() ],
            [ 'WP Activity Log, Simple History', true, Activity_Log_Pack::operations() ],
            [ 'Action Scheduler', false, Action_Scheduler_Pack::operations() ],
            [ 'WP Mail Logging, Check and Log Email, Email Log', true, Email_Log_Pack::operations() ],
            [ 'Pretty Links', false, Pretty_Links_Pack::operations() ],
            [ 'ThirstyAffiliates', false, Thirsty_Affiliates_Pack::operations() ],
            [ 'Advanced Ads', false, Advanced_Ads_Pack::operations() ],
            [ 'Site Reviews', false, Site_Reviews_Pack::operations() ],
            [ 'WP Simple Pay', false, Simple_Pay_Pack::operations() ],
            [ 'Paid Member Subscriptions', false, Paid_Member_Subscriptions_Pack::operations() ],
            [ 'WPGraphQL', false, WPGraphQL_Pack::operations() ],
            [ 'Restrict Content', false, Restrict_Content_Pack::operations() ],
            [ 'Ultimate Member', false, Ultimate_Member_Pack::operations() ],
            [ 'Easy Digital Downloads', false, EDD_Pack::operations() ],
        ];
        foreach (LMS_Pack::adapters() as $adapter) {
            $groups[] = [ $adapter->label(), false, $adapter->operations() ];
        }
        $groups[] = [ 'WP Offload Media', false, Offload_Media_Pack::operations() ];
        $groups[] = [ 'WPCode', false, WPCode_Pack::operations() ];
        $groups[] = [ 'TablePress', false, TablePress_Pack::operations() ];
        foreach (CRM_Pack::adapters() as $adapter) {
            $groups[] = [ $adapter->label(), false, $adapter->operations() ];
        }
        $groups[] = [ 'Amelia', false, Amelia_Pack::operations() ];
        $groups[] = [ 'Polylang, WPML', true, I18n_Pack::operations() ];
        $groups[] = [ 'MemberPress', false, MemberPress_Pack::operations() ];
        $groups[] = [ 'Elementor Pro', false, Elementor_Pro_Forms_Pack::operations() ];
        $groups[] = [ 'BetterDocs', false, BetterDocs_Pack::operations() ];
        foreach (Helpdesk_Pack::adapters() as $adapter) {
            $groups[] = [ $adapter->label(), false, $adapter->operations() ];
        }
        $groups[] = [ 'Weglot', false, Translation_Settings_Pack::weglot_operations() ];
        $groups[] = [ 'GTranslate', false, Translation_Settings_Pack::gtranslate_operations() ];
        $groups[] = [ 'Loco Translate', false, Loco_Translate_Pack::operations() ];
        $groups[] = [ 'Checkout Field Editor', false, Checkout_Field_Editor_Pack::operations() ];
        $groups[] = [ 'SureCart', false, SureCart_Pack::operations() ];

        $hosts = [];
        foreach ($groups as [ $plugin, $several, $ops ]) {
            foreach ($ops as $op => $def) {
                if (Registrar::tier_permitted((string) ($def['tier'] ?? 'free'))) {
                    $hosts[ (string) $op ] = [ 'name' => $plugin, 'several' => $several ];
                }
            }
        }
        return $hosts;
    }

    protected function operations(): array
    {
        return array_merge(
            BbPress_Pack::operations(),
            JetEngine_Pack::operations(),
            Pods_Pack::operations(),
            TranslatePress_Pack::operations(),
            Code_Snippets_Pack::operations(),
            WS_Form_Pack::operations(),
            Activity_Log_Pack::operations(),
            Action_Scheduler_Pack::operations(),
            Email_Log_Pack::operations(),
            Pretty_Links_Pack::operations(),
            Thirsty_Affiliates_Pack::operations(),
            Advanced_Ads_Pack::operations(),
            Site_Reviews_Pack::operations(),
            Simple_Pay_Pack::operations(),
            Paid_Member_Subscriptions_Pack::operations(),
            WPGraphQL_Pack::operations(),
            Restrict_Content_Pack::operations(),
            Ultimate_Member_Pack::operations(),
            EDD_Pack::operations(),
            BuddyPress_Pack::operations(),
            LMS_Pack::operations(),
            Offload_Media_Pack::operations(),
            WPCode_Pack::operations(),
            TablePress_Pack::operations(),
            CRM_Pack::operations(),
            Amelia_Pack::operations(),
            I18n_Pack::operations(),
            MemberPress_Pack::operations(),
            Elementor_Pro_Forms_Pack::operations(),
            BetterDocs_Pack::operations(),
            Helpdesk_Pack::operations(),
            Translation_Settings_Pack::operations(),
            Checkout_Field_Editor_Pack::operations(),
            SureCart_Pack::operations()
        );
    }

    /** Input schema shared by the list-fields ops. */
    public static function post_type_schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [ 'post_type' => [ 'type' => 'string', 'minLength' => 1 ] ],
            'required'   => [ 'post_type' ],
        ];
    }

    /** Input schema shared by the get-fields ops. */
    public static function get_fields_schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                'keys'    => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
            ],
            'required'   => [ 'post_id' ],
        ];
    }

    /** Input schema shared by the update-fields ops. */
    public static function update_fields_schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'post_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                'fields'  => [ 'type' => 'object', 'minProperties' => 1 ],
            ],
            'required'   => [ 'post_id', 'fields' ],
        ];
    }

    /**
     * Refusal for a post the current user may not act on, or null. $write
     * asks for edit_post, otherwise read_post.
     *
     * @return array{code: string, message: string, data: array}|null
     */
    public static function post_refusal(int $post_id, bool $write): ?array
    {
        $post = get_post($post_id);
        if (! $post instanceof \WP_Post) {
            return [ 'code' => 'post_not_found', 'message' => sprintf('Post %d does not exist.', $post_id), 'data' => [] ];
        }
        if (! current_user_can($write ? 'edit_post' : 'read_post', $post_id)) {
            return [
                'code'    => 'operation_denied',
                'message' => sprintf('You are not allowed to %s post %d.', $write ? 'edit' : 'read', $post_id),
                'data'    => [ 'reason' => 'capability' ],
            ];
        }
        return null;
    }

    /**
     * Refusal naming the keys that are not fields of the post's type, or null.
     *
     * @param array<string, mixed> $defined name => definition
     * @param string[]             $keys
     * @return array{code: string, message: string, data: array}|null
     */
    public static function unknown_keys_refusal(array $defined, array $keys, string $post_type, string $plugin): ?array
    {
        $unknown = array_values(array_diff($keys, array_keys($defined)));
        if ([] === $unknown) {
            return null;
        }
        return [
            'code'    => 'unknown_field',
            'message' => sprintf('Not a %s field of post type "%s": %s.', $plugin, $post_type, implode(', ', $unknown)),
            'data'    => [ 'unknown' => $unknown, 'fields' => array_keys($defined) ],
        ];
    }

    /** The current value of one post meta key, or null when it is not set. */
    public static function meta_value(int $post_id, string $key)
    {
        return metadata_exists('post', $post_id, $key) ? get_post_meta($post_id, $key, true) : null;
    }
}
