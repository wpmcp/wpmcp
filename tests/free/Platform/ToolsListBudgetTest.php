<?php

namespace WPMCP\Tests\Free\Platform;

/**
 * Token-cost guard for the advertised tool surface (issue #59).
 *
 * The whole point of serving the widget catalog as DATA behind a handful of
 * generic abilities is that adding widgets must not grow the tools/list
 * payload every MCP client pays for on connect. This test renders the
 * tools/list-shaped payload (name, description, inputSchema, annotations)
 * for every registered ability and pins its JSON size against a checked-in
 * byte budget, so any change that bloats the advertised surface - a
 * per-widget tool, a runaway description - fails CI with a number attached.
 *
 * The budget is a ceiling, not a target: raise it deliberately (with review)
 * when the surface legitimately grows, exactly like the ability manifest.
 */
class ToolsListBudgetTest extends \WP_UnitTestCase
{
    /** Max JSON bytes for the full tools/list payload of every registered ability.
     *  Raised 100000 -> 110000 in review for the forms integration cluster
     *  (Gravity Forms, Formidable, Contact Form 7, WPForms); raised 110000 ->
     *  135000 in review for the Elementor parity expansion (global Kit,
     *  templates, theme builder, atomic elements, popups, dynamic tags);
     *  raised 135000 -> 140000 in review for the forms breadth cluster
     *  (Forminator, SureForms, MetForm), which put the payload at 135470
     *  bytes over 266 tools; raised 140000 -> 150000 in review for the
     *  accessibility and SEO auto-fixers (fix-color-contrast,
     *  add-alt-text-from-context, fix-link-text), which puts the payload at
     *  139656 bytes over 273 tools; raised 150000 -> 155000 in review for the
     *  Elementor v4 global class write suite
     *  (create/update/delete/reorder-global-class), whose two authoring tools
     *  advertise the full friendly style-key list so an agent can style a
     *  class without a second schema round trip, which puts the payload at
     *  148066 bytes over 281 tools; raised 155000 -> 160000 in review for the
     *  agent project memory tools (memory-recall, memory-propose,
     *  memory-save-summary), which puts the payload at 156353 bytes over 293
     *  tools; raised 160000 -> 165000 in review for the foundation parity
     *  cluster (taxonomy term CRUD plus duplicate-post, diff-revisions and
     *  count-content), which puts the payload at 161467 bytes over 302 tools.
     *  That last raise was taken only after trimming the new descriptions:
     *  they still carry the refusal rules (duplicate slug, parent cycle,
     *  default term) because an agent that learns those from the description
     *  avoids a failed call, which costs more than the bytes do. The
     *  WooCommerce variation and stock tools (#195: list-variations,
     *  update-variation, list-low-stock-products) added roughly 1.9KB with no
     *  raise; a free-tier-only run then measures 112912 bytes over 216 tools.
     *  Raised 165000 -> 170000 in review for restore-site-backup (#190), the
     *  one tool that can overwrite every table: its description is kept
     *  short but still names the refusal rules (scope, prefix, multisite,
     *  format_version, include_files) and the fact that this build only
     *  produces the dry_run report, because an agent that reads that plans a
     *  dry run instead of a failed restore. That puts the payload at 165928
     *  bytes over 308 tools, after trimming. Raised 170000 -> 175000 in
     *  review for the local-live sync export pair (#192: build-change-set,
     *  get-change-set): main had reached 169146 bytes over 312 tools, so no
     *  new tool fit; the two descriptions were trimmed from 1051 to 420
     *  characters first, which puts the payload at 170104 bytes over 314
     *  tools. Raised 175000 -> 180000 when the merge train landed #60, #61
     *  and #62 together (176529 bytes over 319 tools). The PHP snippet
     *  lifecycle store (#85) adds seven tools whose descriptions were trimmed
     *  to the store-vs-Elementor-custom-code disambiguation, the
     *  created-inactive rule and the execution-gate refusal. Raised 180000 ->
     *  185000 for cloud settings sync and marketplace (#135: sync-, push- and
     *  apply-settings, marketplace-browse, marketplace-install): main had
     *  reached 179878 bytes over 326 tools, so no new tool fit; the five
     *  descriptions were trimmed first (182841 -> 182175 bytes) but still
     *  name what never syncs, the merge-not-replace and never-disable
     *  rollback rules, and the inactive-draft install, since an agent that
     *  misses those makes a failed or unsafe call.
     *  The same 185000 also covers main being merged into the theme
     *  dispatcher branches (#144, #69): main itself measured 180308 bytes over 326 tools once #85 and #262
     *  had both landed, and the theme-read/theme-write pair adds about 820
     *  bytes that are almost all the shared dispatcher text, so no
     *  theme-specific trim could make it fit. Raised 185000 -> 186000 when
     *  the theme-builder site parts (#70: create/list/resolve/update/
     *  set-status/delete-site-part) met that main: 185484 bytes over 334
     *  tools. The six site-part descriptions were trimmed first and the
     *  include/exclude semantics stated once instead of on both conditions
     *  schemas, which brought it to 185172; the rest is the rule schema
     *  (type enum plus value) that create and update both need so an agent
     *  can build a valid conditions object from tools/list alone. The gateway
     *  credential lifecycle (#142: gateway-provision, gateway-status,
     *  gateway-revoke) trimmed its three descriptions from 612 to 425
     *  characters but still names the rotation kill, the once-only secrets
     *  and the confirm gate, since an agent that misses those can cut a live
     *  proxy off; it still measured 186736 bytes over 339 tools, so the
     *  long descriptions no open branch touches (rewrite-site-urls,
     *  trigger-backup, restore-site-backup, run-wp-cli, get-backup-manifest,
     *  search-content, get-global-settings) were reworded without dropping a
     *  rule. build-page was left alone: the wporg strip rewrites its text.
     *  Raised 186000 -> 195000 for the WooCommerce depth cluster (#195:
     *  variation create and delete, bulk-update-products, six coupon tools,
     *  four tax-rate tools): 194455 bytes over 349 tools after trimming their
     *  descriptions, about 8.5KB for the 13 tools (the raise approved in
     *  review, re-applied on the newer main). The coupon write
     *  schemas are most of it: they list every writable field so an agent
     *  can set limits and restrictions without a schema round trip. Raised
     *  195000 -> 196000 when regenerate-elementor-css (#272) met
     *  upload-media on main: 195107 bytes over 352 tools, with the new
     *  description already cut to 40 characters. Raised
     *  196000 -> 198000 when the five cloud settings sync and marketplace
     *  tools (#135) met that main: 197816 bytes over 366 tools, after their
     *  descriptions were trimmed twice without dropping the never-syncs,
     *  merge, never-disable-rollback or inactive-install rules. The widget
     *  compiler (#72) adds compile-custom-widget, trimmed from 569 characters
     *  (it keeps the opt-in filter and the edit_files and DISALLOW_FILE_EDIT
     *  refusals), with the other widget builder descriptions tightened to
     *  pay for it. Raised 198000 -> 199000 when get-rendered-html met that
     *  main: 198264 bytes over 368 tools, with its description already cut
     *  to 72 characters and get-page-snapshot's tightened alongside to pay
     *  for part of it; the rest is its six-property input schema. Raised
     *  199000 -> 201000 for the WooCommerce operations catalog (#68: woo-ops,
     *  woo-read, woo-write) when it met that main: 200344 bytes over 371
     *  tools. The three catalog descriptions were already trimmed twice to
     *  the op model, the gates and the rollback contract; what remains is
     *  the destructive-op opt-in, confirm, refund and batch rollback rules an
     *  agent must see before it writes to a store. Compact tool mode keeps
     *  clients with tool caps at ~2.8KB regardless. Raised 201000 -> 203000
     *  for the Elementor v4 global variable suite
     *  (list/create/update/delete-global-variable) when it met that main:
     *  202840 bytes over 376 tools. The four descriptions were trimmed
     *  first; the create description keeps the per-type value rules because
     *  an agent that reads them does not burn a call on a refusal. Raised
     *  203000 -> 205000 for the two-step product import
     *  (plan-product-import, apply-product-import) when it met that main:
     *  main measured 202972 bytes over 378 tools, 28 bytes under the
     *  budget, and the pair adds 1149 bytes (204121 over 380 tools). Both
     *  row schemas are already a plain object array and both descriptions
     *  were trimmed twice to the row fields, the plan_hash handshake, the
     *  confirm rule and the rollback-session undo. Raised 205000 -> 206000
     *  for the multi-site gateway (#130: cloud-gateway-provision,
     *  cloud-gateway-status, cloud-connect's gateway_consent), which adds
     *  1026 bytes after its three descriptions were cut from 1082 to 446
     *  characters. What remains is the consent and replace gates, the
     *  MCP-only and identity-only reach, and the once-only secrets, since
     *  an agent that misses those can upload a credential without consent
     *  or kill a live one. Raised 206000 -> 208000 for the classic sidebar
     *  widget writes (#285: create/update/move/delete-sidebar-widget) when
     *  they met that main: main measured about 206000 bytes, and the four
     *  add 1641 bytes (207639 over 388 tools) after their descriptions were
     *  cut to one line each and their property descriptions dropped. What
     *  remains is the widget id shape, the 0-based position, the update()
     *  sanitizing and that each write is undoable. Raised 208000 -> 209000
     *  for portable bundles (#297: export-bundle, import-bundle): main
     *  measured about 207560 bytes over 391 tools, and the pair adds 833
     *  bytes as two abilities because export is read-only and import is a
     *  write, which governance and the MCP annotations key on. Both
     *  descriptions were cut to one line and validate-php-snippet's was
     *  trimmed by 120 characters to pay for part of it, which puts the
     *  payload at 208275 bytes over 393 tools. What remains is the
     *  created-inactive rule, the on_conflict rename opt-in and the
     *  rollback-session undo. Core updates and auto-update settings (#389)
     *  added one tool (manage-updates) and an updates mode on list-plugins
     *  with no raise: the shared integration dispatcher read/write
     *  descriptions and a few long free descriptions (restore-site-backup,
     *  rewrite-site-urls, update-rows, delete-rows, call-rest and others)
     *  were reworded without dropping a rule, which puts the payload at
     *  208815 bytes over 405 tools. Builder design systems, templates and
     *  element catalogs (#391) added no tool: they are a scope, builder and
     *  element argument on get-builder-content (post_id no longer required),
     *  which puts the payload at 208977 bytes over 405 tools. The mail
     *  delivery check (#415) added no tool either: it is a mail_test and
     *  confirm argument on get-site-health, paid for by rewording the
     *  update-block, add-block, remove-block, insert-pattern, memory-propose,
     *  call-rest, rewrite-site-urls, run-php-snippet, update-page-settings,
     *  add-custom-css, get-widget-schema, add-container and find-element
     *  descriptions without dropping a rule: 208975 -> 208982 bytes over 406
     *  tools. Identity IP allowlists (#416) added no tool: they are an
     *  allowed_ips argument on create-identity, paid for by rewording that
     *  description and the query, search-content, import-stock-image,
     *  run-wp-cli, export-content, get-page-snapshot, resolve-theme-template
     *  and bulk-update-products descriptions and dropping serial commas
     *  from nineteen list-style descriptions, without dropping a rule:
     *  208982 -> 208994 bytes over 406 tools. Honest annotations (#420)
     *  flipped readOnlyHint and idempotentHint to false on get-site-health,
     *  export-content and find-broken-links (six bytes), paid for by
     *  dropping the "Read-only" claims those descriptions no longer earn:
     *  208994 -> 208981 bytes over 406 tools. Database cleanup (#414) added
     *  no tool: it is a cleanup list with keep, days, dry_run, confirm and
     *  cursor on delete-transient (name no longer required), paid for by
     *  rewording "Undoable via rollback-operation" to "Undo:
     *  rollback-operation", "Requires expected_hash" and "requires
     *  confirm:true" to "Needs ..." and the integration dispatcher read and
     *  write templates, without dropping a rule: 208981 -> 208962 bytes over
     *  406 tools. Known-vulnerability lookups (#413) added no tool: they are
     *  a vulnerabilities argument on scan-security, paid for by rewording the
     *  update-variation, replace-system-typography, dispatch-cli-job,
     *  trigger-backup, analyze-performance, set-dynamic-tag,
     *  reorder-global-classes, delete-global-class, add-block, woo-write,
     *  restore-site-backup, edit-file and a few other descriptions without
     *  dropping a rule: 208962 -> 208959 bytes over 406 tools. Staged
     *  edits (#417) added no tool: they are stage, publish_stage,
     *  discard_stage and force arguments on duplicate-post (post_id no
     *  longer required), paid for by rewording the integration dispatcher
     *  read and write templates and dropping cosmetic quotes around
     *  argument names in the atomic widget and block tool descriptions,
     *  without dropping a rule: 208959 -> 208958 bytes over 406 tools.
     *  Site-wide governance (#412) added no tool: it is a site_wide enum on
     *  update-governance-settings and a source argument on
     *  list-governance-audit-log (232 bytes), paid for by dropping the
     *  trailing "Read-only" from 28 descriptions whose readOnlyHint already
     *  says it (list-php-snippets through get-analytics-connection-status)
     *  and shortening the filter names in get-governance-settings, without
     *  dropping a rule: 208958 -> 208893 bytes over 406 tools. Image
     *  optimization (#380) adds one pro tool, optimize-media (quality,
     *  max_edge, formats, dry_run, force and a cursor, 705 bytes), paid for
     *  by dropping the trailing "Read-only" from the integration dispatcher
     *  read template and 46 more descriptions whose readOnlyHint already
     *  says it, without dropping a rule: 208893 -> 208883 bytes over 407
     *  tools. Image optimization follow-up (#432) added no tool: it is
     *  background, job_id and cancel on optimize-media plus a pointer to
     *  its two settings, paid for by rewording "so it can be rolled back"
     *  to "(undoable)", "Refuses with an error if" to "Errors if" and the
     *  render-shortcode, update-settings and switch-theme descriptions,
     *  without dropping a rule: 208883 -> 208872 bytes over 407 tools. LMS
     *  course structure (#394) added no tool: its Tutor LMS and LifterLMS
     *  ops sit on the plugin-data pair, whose summary names them (66 bytes
     *  over both halves), paid for by dropping implementation names from
     *  delete-rows and call-rest and the redundant operation example from
     *  update-governance-settings, without dropping a rule: 208872 ->
     *  208868 bytes over 407 tools. Safe PHP file edits (#453) added no
     *  tool: an unchecked flag on edit-file and write-file plus the PHP
     *  parse, fatal-revert and loopback-override rules, paid for by
     *  tightening the three file-write descriptions and read-file, and by
     *  "input schema" to "schema" and "where possible" to "if possible" in
     *  the integration dispatcher templates, without dropping a rule:
     *  main measured 208881 bytes over 407 tools before it and after it.
     *  Image generation (#456) added no tool: prompt and ratio on
     *  sideload-image (url no longer required, 103 bytes with its new
     *  description), paid for by rewording import-stock-image,
     *  insert-stock-image and the five "Not snapshotted (a create destroys
     *  nothing)" create descriptions without dropping a rule: 208881 ->
     *  208841 bytes over 407 tools. */
    private const TOOLS_LIST_BYTE_BUDGET = 209000;

    /** @return array<int, array<string, mixed>> tools/list-shaped entries. */
    private static function payload(): array
    {
        $tools = [];
        foreach (RegisteredAbilities::all() as $ability) {
            $tools[] = [
                'name'        => $ability->name,
                'description' => $ability->description,
                'inputSchema' => $ability->input_schema,
                'annotations' => [
                    'readOnlyHint'    => $ability->read_only_hint,
                    'destructiveHint' => $ability->destructive_hint,
                    'idempotentHint'  => $ability->idempotent_hint,
                ],
            ];
        }
        return $tools;
    }

    public function test_tools_list_payload_stays_within_byte_budget(): void
    {
        $payload = self::payload();
        $bytes   = strlen((string) wp_json_encode($payload));

        $this->assertGreaterThan(0, $bytes);
        $this->assertLessThanOrEqual(
            self::TOOLS_LIST_BYTE_BUDGET,
            $bytes,
            sprintf(
                'tools/list payload is %d bytes for %d tools, over the %d-byte budget. '
                . 'Trim descriptions/schemas, or raise the budget deliberately in review.',
                $bytes,
                count($payload),
                self::TOOLS_LIST_BYTE_BUDGET
            )
        );
    }

    public function test_widget_catalog_growth_cannot_grow_the_tool_surface(): void
    {
        // The catalog is consumed by a fixed set of generic abilities; the
        // number of registered elementor-domain tools must not scale with the
        // number of cataloged widgets.
        $elementor = array_filter(
            RegisteredAbilities::all(),
            static fn ($ability) => 'elementor' === $ability->domain
        );

        // Ceiling raised from 25 -> 60 in review for the Elementor parity
        // expansion (global Kit, templates, theme builder, atomic elements,
        // popups, dynamic tags); raised 60 -> 62 in review for the atomic
        // system-slot replace tools (replace-system-colors,
        // replace-system-typography, issue #60), then 62 -> 64 for issue #61's
        // two read tools (export-template, resolve-theme-template). The
        // invariant this protects is unchanged: the 44-widget catalog is
        // consumed by a FIXED generic set, so adding a cataloged widget must
        // never add a tool. New tools here are per-feature, never per-widget,
        // and stay well under the catalog size. Raised 64 -> 65 for
        // regenerate-elementor-css, a per-feature cache tool, and 65 -> 66
        // for compile-custom-widget (issue #72: one ability compiles ANY
        // stored spec, so the count stays independent of how many widgets
        // exist, cataloged or custom). Raised 66 -> 70 for the v4 global
        // variable suite (list/create/update/delete-global-variable), one
        // per-feature CRUD set like the global class suite.
        $this->assertLessThanOrEqual(
            70,
            count($elementor),
            'The Elementor tool surface must stay a fixed set of generic, per-feature tools; '
            . 'widgets belong in the catalog data, not in new per-widget abilities.'
        );
    }
}
