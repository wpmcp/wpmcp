<?php

namespace WPMCP\Tests\Free\Platform;

use WPMCP\Integrations\Integration_Dispatcher;
use WPMCP\MCP\Ability;
use WPMCP\MCP\Registrar;
use WPMCP\Pro\Gate;

/**
 * Registry-wide companion to PackObjectCapabilityTest (issue #450).
 *
 * Walks every operation of every integration pack, free and pro, and finds
 * each args key that names an object by id (a key named id, ids or ending
 * in _id/_ids, nested ones included). Every such key must either be declared
 * in the op's 'objects' map, so the permission decision checks the matching
 * per-object capability, or be listed below as naming something that is not
 * a WordPress object with a per-object capability (a row in the host plugin's
 * own table, behind the op's own capability). Adding a key to that list is a
 * visible decision.
 *
 * Every declared key is then aimed, as a Contributor holding the pair's own
 * capability, at another user's object: a draft post for a post key (and
 * another user's published post when the op writes), another user for a
 * user key, a held comment for a comment key, and any entry for an entry
 * key. Each must be refused. A post key aimed at the Contributor's own draft
 * must not be.
 */
class PackObjectCapabilityRegistryTest extends \WP_UnitTestCase
{
    /**
     * integration:op:key => what the id names. Each of these is a row in the
     * host plugin's own table rather than a post, term, user, comment, order
     * or entry, and the op either requires manage_options (or the plugin's
     * own management capability) or applies the plugin's own visibility.
     */
    private const NOT_WP_OBJECTS = [
        'gravitytables:get-table:table_id'                     => 'a Gravity Tables table row',
        'give:list-donations:donor_id'                        => 'a GiveWP donor row in its own give_donors table, behind manage_options',
        'give:list-donations:campaign_id'                     => 'a GiveWP campaign row in its own give_campaigns table, behind manage_options',
        'give:get-donor:donor_id'                             => 'a GiveWP donor row in its own give_donors table, behind manage_options',
        'give:donation-summary:campaign_id'                   => 'a GiveWP campaign row in its own give_campaigns table, behind manage_options',
        'plugin-data:elementor-get-submission:id' => 'an Elementor Pro submission row, behind manage_options (Elementor Pro\'s own submissions capability)',
        'plugin-data:elementor-export-submissions:ids' => 'an Elementor Pro submission row, behind manage_options (Elementor Pro\'s own submissions capability)',
        'plugin-data:elementor-update-submission-status:id' => 'an Elementor Pro submission row, behind manage_options (Elementor Pro\'s own submissions capability)',
        'plugin-data:elementor-delete-submission:id' => 'an Elementor Pro submission row, behind manage_options (Elementor Pro\'s own submissions capability)',
        'plugin-data:code-snippets-get-snippet:id' => 'a Code Snippets snippet row in its own table, behind manage_options (Code Snippets\' own capability)',
        'plugin-data:code-snippets-update-snippet:id' => 'a Code Snippets snippet row in its own table, behind manage_options (Code Snippets\' own capability)',
        'plugin-data:code-snippets-activate-snippet:id' => 'a Code Snippets snippet row in its own table, behind manage_options (Code Snippets\' own capability)',
        'plugin-data:code-snippets-deactivate-snippet:id' => 'a Code Snippets snippet row in its own table, behind manage_options (Code Snippets\' own capability)',
        'theme:get-privacy-request:request_id' => 'a core personal data request (a user_request post, never a content post), behind manage_options plus export_others_personal_data or erase_others_personal_data for its type',
        'theme:mark-request-complete:request_id' => 'a core personal data request (a user_request post, never a content post), behind manage_options plus export_others_personal_data or erase_others_personal_data for its type',
        'theme:ignore-wordfence-scan-issue:issue_id' => 'a Wordfence scan issue row in its own table, behind manage_options (manage_network on a network), Wordfence\'s own capability',
        'theme:list-backwpup-backups:job_id' => 'a BackWPup job in its own backwpup_jobs site option, behind manage_options (manage_network_options on a network)',
        'theme:run-backwpup-backup:job_id' => 'a BackWPup job in its own backwpup_jobs site option, behind manage_options (manage_network_options on a network)',
        'plugin-data:actionscheduler-get-action:action_id' => 'an Action Scheduler queue action in its own store (database or posts), behind manage_options (the capability the Action Scheduler admin screen takes)',
        'plugin-data:actionscheduler-cancel-action:action_id' => 'an Action Scheduler queue action in its own store (database or posts), behind manage_options (the capability the Action Scheduler admin screen takes)',
        'plugin-data:actionscheduler-run-action:action_id' => 'an Action Scheduler queue action in its own store (database or posts), behind manage_options (the capability the Action Scheduler admin screen takes)',
        'plugin-data:prettylinks-get-link:link_id' => 'a Pretty Links link row in its own table, behind manage_options (Pretty Links gates its admin behind it)',
        'plugin-data:prettylinks-get-link-clicks:link_id' => 'a Pretty Links link row in its own table, behind manage_options (Pretty Links gates its admin behind it)',
        'plugin-data:prettylinks-update-link:link_id' => 'a Pretty Links link row in its own table, behind manage_options (Pretty Links gates its admin behind it)',
        'plugin-data:prettylinks-delete-link:link_id' => 'a Pretty Links link row in its own table, behind manage_options (Pretty Links gates its admin behind it)',
        'plugin-data:activitylog-list-activity:user_id' => 'an activity log event row (or a user or post id used as a filter) in the plugin\'s own table, behind manage_options (the logs hold personal data, masked in lists)',
        'plugin-data:activitylog-list-activity:object_id' => 'an activity log event row (or a user or post id used as a filter) in the plugin\'s own table, behind manage_options (the logs hold personal data, masked in lists)',
        'plugin-data:activitylog-get-activity:id' => 'an activity log event row (or a user or post id used as a filter) in the plugin\'s own table, behind manage_options (the logs hold personal data, masked in lists)',
        'plugin-data:sitereviews-list-reviews:assigned_post_id' => 'a post id used only as a filter over Site Reviews\' own assignment table; the reviews listed are kept to the ones the caller may read',
        'plugin-data:surecart-get-product:product_id' => 'a SureCart product in SureCart\'s cloud store, behind manage_options (SureCart\'s own product screens take it)',
        'plugin-data:surecart-update-product:product_id' => 'a SureCart product in SureCart\'s cloud store, behind manage_options (SureCart\'s own product screens take it)',
        'plugin-data:rcp-list-members:level_id' => 'a Restrict Content membership level row used only as a filter over its own memberships table, behind manage_options (the plugin grants its member screens to administrators)',
        'plugin-data:rcp-update-membership-level:level_id' => 'a Restrict Content membership level row in its own table, behind manage_options (the plugin grants its level screens to administrators)',
        'plugin-data:edd-list-orders:customer_id' => 'an Easy Digital Downloads customer row used only as a filter over its own orders table, behind manage_options (buyer emails are masked in lists)',
        'plugin-data:edd-get-order:order_id' => 'an Easy Digital Downloads order row in its own table, behind manage_options (the store\'s orders screen is for shop managers and administrators)',
        'plugin-data:edd-update-discount:discount_id' => 'an Easy Digital Downloads discount row in its own adjustments table, behind manage_options (EDD\'s manage_shop_discounts is an administrator capability)',
        'plugin-data:pms-list-members:plan_id' => 'a Paid Member Subscriptions plan id used only as a filter over its own member subscriptions table, behind manage_options (the plugin\'s own member screens take it)',
        'plugin-data:sitereviews-review-summary:assigned_post_id' => 'a post id used only as a filter over Site Reviews\' own assignment table; the summary holds counts only',
        'plugin-data:emaillog-get-email:id' => 'an email log row (or an id used as a filter) in the logging plugin\'s own table, behind manage_options (recipients and bodies are personal data, masked in lists)',
        'plugin-data:wsform-get-form:form_id' => 'a WS Form form row, behind manage_options (WS Form grants its own form capabilities to administrators only)',
        'plugin-data:wsform-list-fields:form_id' => 'a WS Form form row, behind manage_options (WS Form grants its own form capabilities to administrators only)',
        'plugin-data:wsform-list-actions:form_id' => 'a WS Form form row, behind manage_options (WS Form grants its own form capabilities to administrators only)',
        'plugin-data:wsform-list-notifications:form_id' => 'a WS Form form row, behind manage_options (WS Form grants its own form capabilities to administrators only)',
        'plugin-data:wsform-list-entries:form_id' => 'a WS Form form row, behind manage_options (WS Form grants its own form capabilities to administrators only)',
        'plugin-data:wsform-get-entry:entry_id' => 'a WS Form submission row, behind manage_options (WS Form grants its own submission capabilities to administrators only)',
        'plugin-data:wsform-update-entry-status:entry_id' => 'a WS Form submission row, behind manage_options (WS Form grants its own submission capabilities to administrators only)',
        'plugin-data:wsform-delete-entry:entry_id' => 'a WS Form submission row, behind manage_options (WS Form grants its own submission capabilities to administrators only)',
        'plugin-data:tablepress-get-table:table_id' => 'a TablePress table id, behind TablePress\'s own table capabilities',
        'plugin-data:tablepress-export-table:table_id' => 'a TablePress table id, behind TablePress\'s own table capabilities',
        'plugin-data:tablepress-render-table:table_id' => 'a TablePress table id, behind TablePress\'s own table capabilities',
        'plugin-data:tablepress-find-usage:table_id' => 'a TablePress table id, behind TablePress\'s own table capabilities',
        'plugin-data:tablepress-update-table:table_id' => 'a TablePress table id, behind TablePress\'s own table capabilities',
        'plugin-data:tablepress-set-cells:table_id' => 'a TablePress table id, behind TablePress\'s own table capabilities',
        'plugin-data:tablepress-insert-rows:table_id' => 'a TablePress table id, behind TablePress\'s own table capabilities',
        'plugin-data:tablepress-delete-rows:table_id' => 'a TablePress table id, behind TablePress\'s own table capabilities',
        'plugin-data:tablepress-insert-columns:table_id' => 'a TablePress table id, behind TablePress\'s own table capabilities',
        'plugin-data:tablepress-delete-columns:table_id' => 'a TablePress table id, behind TablePress\'s own table capabilities',
        'plugin-data:tablepress-copy-table:table_id' => 'a TablePress table id, behind TablePress\'s own table capabilities',
        'plugin-data:tablepress-change-table-id:table_id' => 'a TablePress table id, behind TablePress\'s own table capabilities',
        'plugin-data:tablepress-delete-table:table_id' => 'a TablePress table id, behind TablePress\'s own table capabilities',
        'plugin-data:tablepress-import-table:table_id' => 'a TablePress table id, behind TablePress\'s own table capabilities',
        'plugin-data:tablepress-place-table:table_id' => 'a TablePress table id, behind TablePress\'s own table capabilities',
        'plugin-data:tablepress-change-table-id:new_id' => 'the TablePress table id to move to, behind tablepress_edit_table_id',
        'pmpro:get-level:level_id'                             => 'a membership level, site configuration',
        'pmpro:list-members:level_id' => 'a Paid Memberships Pro level row in its own table, behind manage_options (PMPro\'s own admin capability)',
        'pmpro:list-orders:level_id' => 'a Paid Memberships Pro level row in its own table, behind manage_options (PMPro\'s own admin capability)',
        'pmpro:update-level:level_id' => 'a Paid Memberships Pro level row in its own table, behind manage_options (PMPro\'s own admin capability)',
        'pmpro:change-member-level:level_id' => 'a Paid Memberships Pro level row in its own table, behind manage_options (PMPro\'s own admin capability)',
        'pmpro:create-discount-code:levels.*.level_id' => 'a Paid Memberships Pro level row in its own table, behind manage_options (PMPro\'s own admin capability)',
        'pmpro:create-level:group_id' => 'a Paid Memberships Pro level group row in its own table, behind manage_options (PMPro\'s own admin capability)',
        'plugin-data:translatepress-update-strings:translations.*.id' => 'a TranslatePress dictionary row, manage_options',
        'plugin-data:buddypress-get-group:id'                  => 'a BuddyPress group; hidden groups are filtered by the pack',
        'plugin-data:buddypress-list-group-members:group_id'   => 'a BuddyPress group; hidden groups are filtered by the pack',
        'plugin-data:buddypress-list-activity:user_id'         => 'filters the public activity stream by member',
        'plugin-data:buddypress-update-group:id'               => 'a BuddyPress group row, manage_options',
        'plugin-data:buddypress-update-profile-field:id'       => 'a BuddyPress profile field definition, manage_options',
        'plugin-data:buddypress-hide-activity:id'              => 'a BuddyPress activity row, manage_options',
        'plugin-data:buddypress-delete-activity:id'            => 'a BuddyPress activity row, manage_options',
        'plugin-data:fluent-support-get-ticket:ticket_id' => 'a Fluent Support ticket row in its own table, behind its agent permissions and ticket visibility',
        'plugin-data:fluent-support-reply-to-ticket:ticket_id' => 'a Fluent Support ticket row in its own table, behind its agent permissions and ticket visibility',
        'plugin-data:fluent-support-add-ticket-note:ticket_id' => 'a Fluent Support ticket row in its own table, behind its agent permissions and ticket visibility',
        'plugin-data:fluent-support-set-ticket-status:ticket_id' => 'a Fluent Support ticket row in its own table, behind its agent permissions and ticket visibility',
        'plugin-data:fluentcrm-list-contacts:list_id'            => 'a FluentCRM list row, the FluentCRM permission',
        'plugin-data:fluentcrm-list-contacts:tag_id'             => 'a FluentCRM tag row, the FluentCRM permission',
        'plugin-data:fluentcrm-get-contact:id' => 'a FluentCRM contact row, the FluentCRM permission',
        'plugin-data:fluentcrm-update-contact:id' => 'a FluentCRM contact row, the FluentCRM permission',
        'plugin-data:fluentcrm-delete-contact:id' => 'a FluentCRM contact row, the FluentCRM permission',
        'plugin-data:fluentcrm-update-contact-lists:id' => 'a FluentCRM contact row, the FluentCRM permission',
        'plugin-data:fluentcrm-update-contact-tags:id' => 'a FluentCRM contact row, the FluentCRM permission',
        'plugin-data:fluentcrm-update-list:id' => 'a FluentCRM list row, the FluentCRM permission',
        'plugin-data:fluentcrm-delete-list:id' => 'a FluentCRM list row, the FluentCRM permission',
        'plugin-data:fluentcrm-update-tag:id' => 'a FluentCRM tag row, the FluentCRM permission',
        'plugin-data:fluentcrm-delete-tag:id' => 'a FluentCRM tag row, the FluentCRM permission',
        'plugin-data:mailpoet-list-contacts:list_id'            => 'a MailPoet list row, the MailPoet capability',
        'plugin-data:mailpoet-list-contacts:tag_id'             => 'a MailPoet tag row, the MailPoet capability',
        'plugin-data:mailpoet-get-contact:id' => 'a MailPoet contact row, the MailPoet capability',
        'plugin-data:mailpoet-update-contact:id' => 'a MailPoet contact row, the MailPoet capability',
        'plugin-data:mailpoet-delete-contact:id' => 'a MailPoet contact row, the MailPoet capability',
        'plugin-data:mailpoet-update-contact-lists:id' => 'a MailPoet contact row, the MailPoet capability',
        'plugin-data:mailpoet-update-contact-tags:id' => 'a MailPoet contact row, the MailPoet capability',
        'plugin-data:mailpoet-update-list:id' => 'a MailPoet list row, the MailPoet capability',
        'plugin-data:mailpoet-delete-list:id' => 'a MailPoet list row, the MailPoet capability',
        'plugin-data:mailpoet-update-tag:id' => 'a MailPoet tag row, the MailPoet capability',
        'plugin-data:mailpoet-delete-tag:id' => 'a MailPoet tag row, the MailPoet capability',
        'plugin-data:fluentcrm-get-campaign:id' => 'a FluentCRM campaign row, the FluentCRM permission',
        'plugin-data:fluentcrm-update-campaign:id' => 'a FluentCRM campaign row, the FluentCRM permission',
        'plugin-data:fluentcrm-duplicate-campaign:id' => 'a FluentCRM campaign row, the FluentCRM permission',
        'plugin-data:fluentcrm-send-test-campaign:id' => 'a FluentCRM campaign row, the FluentCRM permission',
        'plugin-data:fluentcrm-send-campaign:id' => 'a FluentCRM campaign row, the FluentCRM permission',
        'plugin-data:fluentcrm-set-automation-status:id' => 'a FluentCRM automation row, the FluentCRM permission',
        'plugin-data:mailpoet-get-campaign:id' => 'a MailPoet campaign row, the MailPoet capability',
        'plugin-data:mailpoet-update-campaign:id' => 'a MailPoet campaign row, the MailPoet capability',
        'plugin-data:mailpoet-duplicate-campaign:id' => 'a MailPoet campaign row, the MailPoet capability',
        'plugin-data:mailpoet-send-test-campaign:id' => 'a MailPoet campaign row, the MailPoet capability',
        'plugin-data:mailpoet-send-campaign:id' => 'a MailPoet campaign row, the MailPoet capability',
        'plugin-data:mailpoet-set-automation-status:id' => 'a MailPoet automation row, the MailPoet capability',
        'plugin-data:amelia-list-services:category_id' => 'an Amelia category row, behind Amelia\'s own capabilities',
        'plugin-data:amelia-list-appointments:employee_id' => 'an Amelia employee row, behind Amelia\'s own capabilities',
        'plugin-data:amelia-list-appointments:service_id' => 'an Amelia service row, behind Amelia\'s own capabilities',
        'plugin-data:amelia-list-appointments:customer_id' => 'an Amelia customer row, behind Amelia\'s own capabilities',
        'plugin-data:amelia-get-slots:service_id' => 'an Amelia service row, behind Amelia\'s own capabilities',
        'plugin-data:amelia-get-slots:employee_id' => 'an Amelia employee row, behind Amelia\'s own capabilities',
        'plugin-data:amelia-list-event-attendees:event_id' => 'an Amelia event row, behind Amelia\'s own capabilities',
        'plugin-data:amelia-update-category:category_id' => 'an Amelia category row, behind Amelia\'s own capabilities',
        'plugin-data:amelia-create-service:category_id' => 'an Amelia category row, behind Amelia\'s own capabilities',
        'plugin-data:amelia-create-service:employee_ids' => 'Amelia employee rows, behind Amelia\'s own capabilities',
        'plugin-data:amelia-update-service:service_id' => 'an Amelia service row, behind Amelia\'s own capabilities',
        'plugin-data:amelia-update-service:category_id' => 'an Amelia category row, behind Amelia\'s own capabilities',
        'plugin-data:amelia-update-service:employee_ids' => 'Amelia employee rows, behind Amelia\'s own capabilities',
        'plugin-data:amelia-update-customer:customer_id' => 'an Amelia customer row, behind Amelia\'s own capabilities',
        'plugin-data:amelia-book-appointment:service_id' => 'an Amelia service row, behind Amelia\'s own capabilities',
        'plugin-data:amelia-book-appointment:employee_id' => 'an Amelia employee row, behind Amelia\'s own capabilities',
        'plugin-data:amelia-book-appointment:customer_id' => 'an Amelia customer row, behind Amelia\'s own capabilities',
        'plugin-data:amelia-reschedule-appointment:appointment_id' => 'an Amelia appointment row, behind Amelia\'s own capabilities',
        'plugin-data:amelia-set-appointment-status:appointment_id' => 'an Amelia appointment row, behind Amelia\'s own capabilities',
        'plugin-data:amelia-update-employee:employee_id' => 'an Amelia employee row, behind Amelia\'s own capabilities',
        'plugin-data:amelia-create-event:employee_ids' => 'Amelia employee rows, behind Amelia\'s own capabilities',
        'plugin-data:amelia-update-event:event_id' => 'an Amelia event row, behind Amelia\'s own capabilities',
        'plugin-data:amelia-update-event:employee_ids' => 'Amelia employee rows, behind Amelia\'s own capabilities',
        'plugin-data:amelia-set-event-status:event_id' => 'an Amelia event row, behind Amelia\'s own capabilities',
        'plugin-data:amelia-add-event-attendee:event_id' => 'an Amelia event row, behind Amelia\'s own capabilities',
        'plugin-data:amelia-add-event-attendee:customer_id' => 'an Amelia customer row, behind Amelia\'s own capabilities',
        'plugin-data:amelia-delete-category:category_id' => 'an Amelia category row, behind Amelia\'s own capabilities',
        'plugin-data:amelia-delete-service:service_id' => 'an Amelia service row, behind Amelia\'s own capabilities',
        'plugin-data:amelia-delete-employee:employee_id' => 'an Amelia employee row, behind Amelia\'s own capabilities',
        'plugin-data:amelia-delete-customer:customer_id' => 'an Amelia customer row, behind Amelia\'s own capabilities',
        'theme:list-redirection-redirects:group_id'            => 'a Redirection group row, manage_options',
        'theme:get-redirection-redirect:id'                    => 'a Redirection row, manage_options',
        'theme:create-redirection-redirect:group_id'           => 'a Redirection group row, manage_options',
        'theme:update-redirection-redirect:id'                 => 'a Redirection row, manage_options',
        'theme:update-redirection-redirect:group_id'           => 'a Redirection group row, manage_options',
        'theme:enable-redirection-redirect:id'                 => 'a Redirection row, manage_options',
        'theme:disable-redirection-redirect:id'                => 'a Redirection row, manage_options',
        'theme:delete-redirection-redirect:id'                 => 'a Redirection row, manage_options',
        'theme:rankmath-get-redirection:id'                    => 'a Rank Math redirection row, manage_options',
        'theme:rankmath-update-redirection:id'                 => 'a Rank Math redirection row, manage_options',
        'theme:rankmath-delete-redirection:id'                 => 'a Rank Math redirection row, manage_options',
        'theme:get-funnelkit-funnel:id'                        => 'a FunnelKit funnel row, manage_woocommerce',
        'theme:get-funnelkit-checkout-products:step_id' => 'a FunnelKit step page, gated by FunnelKit\'s own funnel access rather than per post, manage_woocommerce',
        'theme:update-funnelkit-funnel:id' => 'a FunnelKit funnel row, manage_woocommerce and FunnelKit\'s own funnel access',
        'theme:delete-funnelkit-funnel:id' => 'a FunnelKit funnel row, manage_woocommerce and FunnelKit\'s own funnel access',
        'theme:add-funnelkit-step:funnel_id' => 'a FunnelKit funnel row, manage_woocommerce and FunnelKit\'s own funnel access',
        'theme:reorder-funnelkit-steps:funnel_id' => 'a FunnelKit funnel row, manage_woocommerce and FunnelKit\'s own funnel access',
        'theme:reorder-funnelkit-steps:step_ids' => 'a FunnelKit step page, gated by FunnelKit\'s own funnel access rather than per post, manage_woocommerce',
        'theme:set-funnelkit-step-status:funnel_id' => 'a FunnelKit funnel row, manage_woocommerce and FunnelKit\'s own funnel access',
        'theme:set-funnelkit-step-status:step_id' => 'a FunnelKit step page, gated by FunnelKit\'s own funnel access rather than per post, manage_woocommerce',
        'theme:delete-funnelkit-step:funnel_id' => 'a FunnelKit funnel row, manage_woocommerce and FunnelKit\'s own funnel access',
        'theme:delete-funnelkit-step:step_id' => 'a FunnelKit step page, gated by FunnelKit\'s own funnel access rather than per post, manage_woocommerce',
        'theme:publish-funnelkit-steps:funnel_id' => 'a FunnelKit funnel row, manage_woocommerce and FunnelKit\'s own funnel access',
        'theme:set-funnelkit-checkout-products:step_id' => 'a FunnelKit step page, gated by FunnelKit\'s own funnel access rather than per post, manage_woocommerce',
        'theme:set-funnelkit-checkout-products:products.*.product_id' => 'WooCommerce products placed on a checkout (read, not edited), manage_woocommerce',
        'theme:remove-funnelkit-checkout-products:step_id' => 'a FunnelKit step page, gated by FunnelKit\'s own funnel access rather than per post, manage_woocommerce',
        'theme:remove-funnelkit-checkout-products:product_ids' => 'WooCommerce products placed on a checkout (read, not edited), manage_woocommerce',
        'theme:list-funnelkit-order-bumps:step_id' => 'a FunnelKit step page, gated by FunnelKit\'s own funnel access rather than per post, manage_woocommerce',
        'theme:get-funnelkit-order-bump:bump_id' => 'a FunnelKit order bump (a substep post of a checkout), gated by FunnelKit\'s own funnel access rather than per post, manage_woocommerce',
        'theme:create-funnelkit-order-bump:step_id' => 'a FunnelKit step page, gated by FunnelKit\'s own funnel access rather than per post, manage_woocommerce',
        'theme:update-funnelkit-order-bump:bump_id' => 'a FunnelKit order bump (a substep post of a checkout), gated by FunnelKit\'s own funnel access rather than per post, manage_woocommerce',
        'theme:set-funnelkit-order-bump-products:bump_id' => 'a FunnelKit order bump (a substep post of a checkout), gated by FunnelKit\'s own funnel access rather than per post, manage_woocommerce',
        'theme:set-funnelkit-order-bump-products:products.*.product_id' => 'WooCommerce products placed on an order bump (read, not edited), manage_woocommerce',
        'theme:remove-funnelkit-order-bump-products:bump_id' => 'a FunnelKit order bump (a substep post of a checkout), gated by FunnelKit\'s own funnel access rather than per post, manage_woocommerce',
        'theme:remove-funnelkit-order-bump-products:product_ids' => 'WooCommerce products placed on an order bump (read, not edited), manage_woocommerce',
        'theme:delete-funnelkit-order-bump:bump_id' => 'a FunnelKit order bump (a substep post of a checkout), gated by FunnelKit\'s own funnel access rather than per post, manage_woocommerce',
        'gravityforms:get-form:form_id'                        => 'a Gravity Forms form row',
        'gravityforms:list-fields:form_id'                     => 'a Gravity Forms form row',
        'gravityforms:list-notifications:form_id'              => 'a Gravity Forms form row',
        'gravityforms:list-entries:form_id'                    => 'a Gravity Forms form row',
        'gravityforms:list-confirmations:form_id' => 'a Gravity Forms form row',
        'gravityforms:update-notification:form_id' => 'a Gravity Forms form row in its own tables, behind manage_options and undone through a gf_form_meta row snapshot',
        'gravityforms:update-notification:notification_id' => 'the id of a notification inside one Gravity Forms form\'s meta, not a WordPress object',
        'gravityforms:set-notification-recipients:form_id' => 'a Gravity Forms form row in its own tables, behind manage_options and undone through a gf_form_meta row snapshot',
        'gravityforms:set-notification-recipients:notification_id' => 'the id of a notification inside one Gravity Forms form\'s meta, not a WordPress object',
        'gravityforms:update-confirmation:form_id' => 'a Gravity Forms form row in its own tables, behind manage_options and undone through a gf_form_meta row snapshot',
        'gravityforms:update-confirmation:confirmation_id' => 'the id of a confirmation inside one Gravity Forms form\'s meta, not a WordPress object',
        'formidable:get-form:form_id'                          => 'a Formidable form row',
        'formidable:list-fields:form_id'                       => 'a Formidable form row',
        'formidable:list-notifications:form_id'                => 'a Formidable form row',
        'formidable:list-entries:form_id'                      => 'a Formidable form row',
        'formidable:list-confirmations:form_id' => 'a Formidable form row in its own table, behind manage_options',
        'formidable:update-notification:form_id' => 'a Formidable form row in its own table, behind manage_options',
        'formidable:update-notification:notification_id' => 'a Formidable form action (a frm_form_actions post that must belong to the form, checked before the write), behind manage_options',
        'formidable:set-notification-recipients:form_id' => 'a Formidable form row in its own table, behind manage_options',
        'formidable:set-notification-recipients:notification_id' => 'a Formidable form action (a frm_form_actions post that must belong to the form, checked before the write), behind manage_options',
        'formidable:update-confirmation:form_id' => 'a Formidable form row in its own table, behind manage_options',
        'formidable:update-confirmation:confirmation_id' => 'a Formidable form action (a frm_form_actions post that must belong to the form, checked before the write), behind manage_options',
        'wpforms:update-notification:form_id' => 'a WPForms form: its post type registers map_meta_cap off, so core\'s edit_post is false even for an administrator; the write sits behind manage_options, WPForms\' own bar, and is undone through a posts-row snapshot',
        'wpforms:update-notification:notification_id' => 'the id of a notification or confirmation inside one WPForms form\'s settings, not a WordPress object',
        'wpforms:set-notification-recipients:form_id' => 'a WPForms form: its post type registers map_meta_cap off, so core\'s edit_post is false even for an administrator; the write sits behind manage_options, WPForms\' own bar, and is undone through a posts-row snapshot',
        'wpforms:set-notification-recipients:notification_id' => 'the id of a notification or confirmation inside one WPForms form\'s settings, not a WordPress object',
        'wpforms:update-confirmation:form_id' => 'a WPForms form: its post type registers map_meta_cap off, so core\'s edit_post is false even for an administrator; the write sits behind manage_options, WPForms\' own bar, and is undone through a posts-row snapshot',
        'wpforms:update-confirmation:confirmation_id' => 'the id of a notification or confirmation inside one WPForms form\'s settings, not a WordPress object',
        'contactform7:update-notification:notification_id' => 'the mail template key (mail or mail_2) inside one Contact Form 7 form, not a WordPress object',
        'contactform7:set-notification-recipients:notification_id' => 'the mail template key (mail or mail_2) inside one Contact Form 7 form, not a WordPress object',
        'ninjaforms:get-form:form_id'                          => 'a Ninja Forms form row',
        'ninjaforms:list-fields:form_id'                       => 'a Ninja Forms form row',
        'ninjaforms:list-notifications:form_id'                => 'a Ninja Forms form row',
        'ninjaforms:list-entries:form_id'                      => 'a Ninja Forms form row',
        'fluentforms:get-form:form_id'                         => 'a Fluent Forms form row',
        'fluentforms:list-fields:form_id'                      => 'a Fluent Forms form row',
        'fluentforms:list-notifications:form_id'               => 'a Fluent Forms form row',
        'fluentforms:list-entries:form_id'                     => 'a Fluent Forms form row',
    ];

    private string $granted = '';

    protected function setUp(): void
    {
        parent::setUp();
        Gate::set_pro_for_tests(true);
        add_filter('user_has_cap', [$this, 'grant_pair_capability']);
    }

    protected function tearDown(): void
    {
        remove_filter('user_has_cap', [$this, 'grant_pair_capability']);
        Gate::set_pro_for_tests(null);
        wp_set_current_user(0);
        parent::tearDown();
    }

    /** @param array<string,bool> $allcaps */
    public function grant_pair_capability(array $allcaps): array
    {
        if ('' !== $this->granted) {
            $allcaps[ $this->granted ] = true;
        }
        return $allcaps;
    }

    /** @return array<string, Integration_Dispatcher> integration slug => pack */
    private static function packs(): array
    {
        $packs = [];
        foreach (RegisteredAbilities::all() as $a) {
            $handler = $a->handler;
            if (is_array($handler) && $handler[0] instanceof Integration_Dispatcher) {
                $packs[ $handler[0]->integration() ] = $handler[0];
            }
        }
        return $packs;
    }

    /**
     * Every args key path in a schema that names an id: 'post_id', or
     * 'updates.*.post_id' for one inside an array of objects.
     *
     * @return string[]
     */
    private static function id_paths(array $schema, string $prefix = ''): array
    {
        $paths = [];
        foreach ((array) ($schema['properties'] ?? []) as $key => $sub) {
            $path = $prefix . $key;
            if (1 === preg_match('/(^|_)ids?$/', (string) $key)) {
                $paths[] = $path;
            }
            $sub = (array) $sub;
            if (isset($sub['properties'])) {
                $paths = array_merge($paths, self::id_paths($sub, $path . '.'));
            }
            if (isset($sub['items']['properties'])) {
                $paths = array_merge($paths, self::id_paths((array) $sub['items'], $path . '.*.'));
            }
        }
        return $paths;
    }

    /** The args that put $value at $path. */
    private static function args_at(string $path, int $value): array
    {
        $parts = explode('.', $path);
        $leaf  = array_pop($parts);
        $args  = [$leaf => $value];
        while ([] !== $parts) {
            $part = array_pop($parts);
            $args = '*' === $part ? [$args] : [$part => $args];
        }
        return $args;
    }

    public function test_every_pack_operation_that_names_an_id_declares_it(): void
    {
        $packs = self::packs();
        $this->assertGreaterThanOrEqual(19, count($packs), 'Too few packs: ' . implode(', ', array_keys($packs)));

        $undeclared = [];
        $stale      = self::NOT_WP_OBJECTS;
        foreach ($packs as $slug => $pack) {
            $declared = $pack->declared_objects();
            foreach ($pack->catalog()['operations'] as $op) {
                foreach (self::id_paths((array) $op['input_schema']) as $path) {
                    $label = $slug . ':' . $op['name'] . ':' . $path;
                    unset($stale[ $label ]);
                    if (! isset($declared[ $op['name'] ]['objects'][ $path ]) && ! isset(self::NOT_WP_OBJECTS[ $label ])) {
                        $undeclared[] = $label;
                    }
                }
            }
        }
        $this->assertSame([], $undeclared, 'Declare these in the op\'s objects map');
        $this->assertSame([], array_keys($stale), 'No longer an op key; drop it from NOT_WP_OBJECTS');
    }

    public function test_every_declared_object_is_refused_to_a_contributor_on_another_users_object(): void
    {
        $owner       = self::factory()->user->create(['role' => 'editor']);
        $contributor = self::factory()->user->create(['role' => 'contributor']);
        $others_live = self::factory()->post->create(['post_author' => $owner]);
        $others_dft  = self::factory()->post->create(['post_author' => $owner, 'post_status' => 'draft']);
        $own_draft   = self::factory()->post->create(['post_author' => $contributor, 'post_status' => 'draft']);
        $held        = self::factory()->comment->create(['comment_post_ID' => $others_live, 'user_id' => $owner, 'comment_approved' => '0']);
        $term        = self::factory()->category->create();

        $packs = self::packs();
        wp_set_current_user($contributor);
        $registrar = new Registrar();
        $walked    = [];
        $failures  = [];

        foreach ($packs as $slug => $pack) {
            [$read, $write] = $pack->abilities();
            foreach ($pack->declared_objects() as $op => $def) {
                $ability       = 'read' === $def['mode'] ? $read : $write;
                $this->granted = $ability->capability;
                foreach ($def['objects'] as $path => $spec) {
                    $walked[] = $slug . ':' . $op . ':' . $path;
                    $cases    = match ($spec['type']) {
                        'post'    => array_filter([
                            'another user\'s draft'          => [$others_dft, true],
                            'another user\'s published post' => 'read' === $spec['access'] ? null : [$others_live, true],
                            'their own draft'                => [$own_draft, false],
                        ]),
                        'user'    => ['another user' => [$owner, true]],
                        'comment' => ['a held comment on another user\'s post' => [$held, true]],
                        'term'    => 'read' === $spec['access'] ? [] : ['a category' => [$term, true]],
                        'entry'   => ['an entry' => [1, true]],
                        default   => ['unknown kind ' . $spec['type'] => [0, true]],
                    };
                    foreach ($cases as $label => [$id, $refused]) {
                        $allowed = $registrar->would_permit($ability, ['operation' => $op, 'args' => self::args_at($path, $id)]);
                        if ($allowed === $refused) {
                            $failures[] = sprintf('%s:%s %s=%s: %s', $slug, $op, $path, $label, $allowed ? 'allowed' : 'refused');
                        }
                    }
                }
            }
        }
        $this->granted = '';

        $this->assertGreaterThan(50, count($walked), 'Too few pack keys walked: ' . implode(', ', $walked));
        foreach ([
            'acf:get-fields:post_id',
            'acf:update-fields:post_id',
            'acf:batch-update-fields:updates.*.post_id',
            'metabox:update-fields:post_id',
            'plugin-data:jetengine-update-fields:post_id',
            'plugin-data:pods-get-fields:post_id',
            'plugin-data:tutor-update-item:id',
            'plugin-data:lifterlms-move-item:parent_id',
            'block-suites:update-block:id',
            'mec:get-event:event_id',
            'contactform7:get-entry:entry_id',
            'forminator:delete-entry:entry_id',
        ] as $label) {
            $this->assertContains($label, $walked);
        }
        $this->assertSame([], $failures);
    }
}
