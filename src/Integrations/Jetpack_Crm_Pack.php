<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Registrar;
use WPMCP\Privacy\Masking;
use WPMCP\Safety\Plugin_Table_Rows_Snapshot;
use WPMCP\Safety\Snapshot_Store;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Jetpack CRM contacts and forms (issue #684) on the plugin-data pair,
 * paid-tier, so it adds no top-level tools. Ops are prefixed jetpackcrm-
 * because the pair is shared.
 *
 * Jetpack CRM 6.x keeps a contact as one row of its {prefix}zbs_contacts
 * table (ID, zbsc_email, zbsc_status, zbsc_fname, zbsc_lname, addresses,
 * phones, zbsc_created and zbsc_lastupdated as Unix times), its tags in
 * zbs_tags and zbs_tags_links (object type 1 = contact), what is linked to
 * it (quotes, invoices, transactions, tasks) in zbs_object_links, and its
 * forms as rows of zbs_forms, each placed with [jetpackcrm_form id="N"].
 * Its capabilities, granted to administrators on activation:
 * admin_zerobs_view_customers to see contacts, admin_zerobs_customers to
 * change them, admin_zerobs_forms for forms. manage_options passes too.
 *
 * Reads: jetpackcrm-list-contacts (names, emails and phones masked,
 * Privacy\Masking), jetpackcrm-get-contact (by id or email, full values for
 * a manage_options user and masked below that) and jetpackcrm-list-forms.
 *
 * Writes go through the plugin's own contacts API ($zbs->DAL->contacts), so
 * its hooks, logs and automations run, and are off until the site opts in
 * with wpmcp_enable_jetpack_crm_write since contacts are personal data:
 *  - create-contact: addUpdateContact() with the given fields; an email
 *    another contact holds (or holds as an alias) is refused rather than
 *    merged. The snapshot is the plugin_table_rows image of the new row,
 *    re-keyed on the id the plugin assigned; the undo removes the contact
 *    through deleteContact(), and is refused once quotes, invoices,
 *    transactions or tasks have been linked to it since.
 *  - update-contact: addUpdateContact() with limitedFields, so only the
 *    given columns change, under a plugin_table_rows image of the row that
 *    the undo puts back as it was.
 * Custom fields, tags and companies are left out of the writes: they live
 * in other tables a one-row snapshot does not cover.
 *
 * Presence is filterable (wpmcp_jetpack_crm_active, default: JPCRM_VERSION
 * or ZBS_ROOTFILE is defined).
 */
final class Jetpack_Crm_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads Jetpack CRM's own tables live; its DAL has no read that returns these filtered pages.

    public const OPT_IN_FILTER = 'wpmcp_enable_jetpack_crm_write';

    public const VIEW_CAP = 'admin_zerobs_view_customers';

    public const EDIT_CAP = 'admin_zerobs_customers';

    public const FORMS_CAP = 'admin_zerobs_forms';

    public const MAX_PER_PAGE = 100;

    /** Jetpack CRM's object type of a contact. */
    public const TYPE_CONTACT = 1;

    private const PLUGIN_FILE = 'zero-bs-crm/ZeroBSCRM.php';

    /** arg => the addUpdateContact() data key (the column is zbsc_<key>). */
    private const FIELDS = [
        'email'        => 'email',
        'status'       => 'status',
        'prefix'       => 'prefix',
        'first_name'   => 'fname',
        'last_name'    => 'lname',
        'addr1'        => 'addr1',
        'addr2'        => 'addr2',
        'city'         => 'city',
        'county'       => 'county',
        'country'      => 'country',
        'postcode'     => 'postcode',
        'home_phone'   => 'hometel',
        'work_phone'   => 'worktel',
        'mobile_phone' => 'mobtel',
    ];

    /** Column max lengths, from the plugin's table definition. */
    private const LENGTHS = [
        'email'   => 200,
        'status'  => 100,
        'prefix'  => 30,
        'fname'   => 100,
        'lname'   => 100,
        'postcode' => 50,
        'hometel' => 40,
        'worktel' => 40,
        'mobtel'  => 40,
    ];

    /** Fields a list shows masked. */
    private const PERSONAL = [ 'email', 'first_name', 'last_name', 'prefix', 'addr1', 'addr2', 'city', 'county', 'postcode', 'home_phone', 'work_phone', 'mobile_phone' ];

    public static function active(): bool
    {
        return Registrar::tier_permitted('pro') && self::loaded();
    }

    private static function loaded(): bool
    {
        return (bool) apply_filters('wpmcp_jetpack_crm_active', defined('JPCRM_VERSION') || defined('ZBS_ROOTFILE'));
    }

    /** @return true|array{code: string, message: string, data: array} */
    public static function presence()
    {
        if (self::loaded()) {
            return true;
        }
        $refusal = Plugin_Refusal::make('plugin_missing', 'Jetpack CRM', self::PLUGIN_FILE);
        if ('inactive' === ($refusal['data']['state'] ?? '')) {
            $refusal['code'] = 'plugin_inactive';
        }
        return $refusal;
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $id     = [ 'type' => 'integer', 'minimum' => 1 ];
        $fields = [];
        foreach (self::FIELDS as $arg => $key) {
            $fields[ $arg ] = [ 'type' => 'string', 'maxLength' => self::LENGTHS[ $key ] ?? 200 ];
        }
        $fields['email'] = [ 'type' => 'string', 'format' => 'email', 'minLength' => 3, 'maxLength' => 200 ];
        $base  = [
            'tier'     => 'pro',
            'requires' => static fn () => self::presence(),
        ];
        $write = $base + [
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_jetpack_crm_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $names = implode(', ', array_keys(self::FIELDS));

        return [
            'jetpackcrm-list-contacts'  => $base + [
                'mode'         => 'read',
                'description'  => 'List Jetpack CRM contacts newest first: id, email, name, status, city, created, updated (personal data masked). search matches email and name; status filters; page and per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'search'   => [ 'type' => 'string', 'maxLength' => 200 ],
                        'status'   => [ 'type' => 'string', 'maxLength' => 100 ],
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
                    ],
                ],
                'validate'     => static fn (): ?array => self::cap_refusal(self::VIEW_CAP),
                'handler'      => static fn (array $args): array => self::list_contacts($args),
            ],
            'jetpackcrm-get-contact'    => $base + [
                'mode'         => 'read',
                'description'  => 'One Jetpack CRM contact by id or email: name, email, status, addresses, phones, tags, created and updated (masked unless the caller has manage_options)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'    => $id,
                        'email' => [ 'type' => 'string', 'minLength' => 3, 'maxLength' => 200 ],
                    ],
                ],
                'validate'     => static fn (array $args): ?array => self::cap_refusal(self::VIEW_CAP) ?? self::identity_refusal($args),
                'handler'      => static fn (array $args): array => self::get_contact($args),
            ],
            'jetpackcrm-list-forms'     => $base + [
                'mode'         => 'read',
                'description'  => 'List Jetpack CRM forms: id, title, style, views, conversions, the shortcode that places it, created',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
                    ],
                ],
                'validate'     => static fn (): ?array => self::cap_refusal(self::FORMS_CAP),
                'handler'      => static fn (array $args): array => self::list_forms($args),
            ],
            'jetpackcrm-create-contact' => $write + [
                'description'  => "Create a Jetpack CRM contact through Jetpack CRM, so its hooks and automations run. email required; also {$names}. An email another contact holds is refused. Undo: rollback-operation removes it",
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => $fields,
                    'required'             => [ 'email' ],
                    'additionalProperties' => false,
                ],
                'validate'     => static fn (array $args): ?array => self::cap_refusal(self::EDIT_CAP) ?? self::fields_refusal($args, 0),
                'snapshot'     => static fn (): array => [
                    'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
                    'object_id'   => Plugin_Table_Rows_Snapshot::key('jpcrm', 'contacts', [ self::next_id() ]),
                ],
                'handler'      => static fn (array $args, array $context): array => self::create($args, $context),
            ],
            'jetpackcrm-update-contact' => $write + [
                'description'  => "Change a Jetpack CRM contact through Jetpack CRM; only the fields given change ({$names}). An email another contact holds is refused. Undo: rollback-operation",
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [ 'id' => $id ] + $fields,
                    'required'             => [ 'id' ],
                    'minProperties'        => 2,
                    'additionalProperties' => false,
                ],
                'validate'     => static fn (array $args): ?array => self::cap_refusal(self::EDIT_CAP) ?? self::update_refusal($args),
                'snapshot'     => static fn (array $args): array => [
                    'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
                    'object_id'   => Plugin_Table_Rows_Snapshot::key('jpcrm', 'contacts', [ (int) $args['id'] ]),
                ],
                'handler'      => static fn (array $args): array => self::update($args),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    public static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'zbs_' . $name;
    }

    /** Whether the current user holds a Jetpack CRM capability (manage_options passes too). */
    public static function can(string $cap): bool
    {
        return current_user_can($cap) || current_user_can('manage_options');
    }

    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    private static function cap_refusal(string $cap): ?array
    {
        return self::can($cap) ? null : self::refusal('operation_denied', sprintf('This needs the %s capability (Jetpack CRM grants it to administrators).', $cap), [ 'reason' => 'capability', 'capability' => $cap ]);
    }

    private static function identity_refusal(array $args): ?array
    {
        if (empty($args['id']) && '' === trim((string) ($args['email'] ?? ''))) {
            return self::refusal('invalid_args', 'Give the contact\'s id or email.');
        }
        return null;
    }

    /** @return array<string, mixed>|null */
    private static function row(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE ID = %d', self::table('contacts'), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** The id of the contact holding an email, as its address or an alias, other than $except; 0 when none. */
    private static function email_holder(string $email, int $except): int
    {
        global $wpdb;
        $id = (int) $wpdb->get_var($wpdb->prepare('SELECT ID FROM %i WHERE zbsc_email = %s AND ID <> %d LIMIT 1', self::table('contacts'), $email, $except));
        if ($id > 0 || ! Plugin_Table_Rows_Snapshot::table_exists(self::table('aka'))) {
            return $id;
        }
        return (int) $wpdb->get_var($wpdb->prepare('SELECT aka_id FROM %i WHERE aka_type = %d AND aka_alias = %s AND aka_id <> %d LIMIT 1', self::table('aka'), self::TYPE_CONTACT, $email, $except));
    }

    private static function fields_refusal(array $args, int $id): ?array
    {
        if (isset($args['email'])) {
            $email = trim((string) $args['email']);
            if (! is_email($email)) {
                return self::refusal('invalid_email', sprintf('"%s" is not a valid email address.', $email));
            }
            $holder = self::email_holder($email, $id);
            if ($holder > 0) {
                return self::refusal('contact_exists', sprintf('Jetpack CRM contact %d already has the email %s. Update that contact instead.', $holder, $email), [ 'contact_id' => $holder ]);
            }
        }
        if (isset($args['status'])) {
            $statuses = self::statuses();
            if ([] !== $statuses && ! in_array((string) $args['status'], $statuses, true)) {
                return self::refusal('invalid_status', sprintf('Jetpack CRM has no contact status "%s". It has: %s.', (string) $args['status'], implode(', ', $statuses)), [ 'statuses' => $statuses ]);
            }
        }
        return null;
    }

    private static function update_refusal(array $args): ?array
    {
        $id = (int) $args['id'];
        if (null === self::row($id)) {
            return self::refusal('contact_not_found', sprintf('Jetpack CRM has no contact %d.', $id), [ 'id' => $id ]);
        }
        return self::fields_refusal($args, $id);
    }

    /** The contact statuses the site configured, or [] when the plugin's helper is not loaded. @return string[] */
    private static function statuses(): array
    {
        if (! function_exists('zeroBSCRM_getCustomerStatuses')) {
            return [];
        }
        return array_values(array_map('strval', (array) \zeroBSCRM_getCustomerStatuses(true)));
    }

    /** @return object|null Jetpack CRM's contacts API */
    private static function api()
    {
        $zbs = $GLOBALS['zbs'] ?? null;
        $api = is_object($zbs) && isset($zbs->DAL) && is_object($zbs->DAL) && isset($zbs->DAL->contacts) ? $zbs->DAL->contacts : null;
        return is_object($api) ? $api : null;
    }

    private static function next_id(): int
    {
        global $wpdb;
        $max    = (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(ID) FROM %i', self::table('contacts')));
        $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', self::table('contacts')), ARRAY_A);
        $auto   = is_array($status) ? (int) ($status['Auto_increment'] ?? 0) : 0;
        return max($max + 1, $auto);
    }

    private static function date(int $time): ?string
    {
        return $time > 0 ? gmdate('Y-m-d H:i:s', $time) : null;
    }

    /** @return array<string, mixed> */
    private static function view(array $row, bool $full): array
    {
        $out = [ 'id' => (int) $row['ID'] ];
        foreach (self::FIELDS as $arg => $key) {
            if (! $full && in_array($arg, [ 'prefix', 'addr1', 'addr2', 'county', 'postcode', 'home_phone', 'work_phone', 'mobile_phone' ], true)) {
                continue;
            }
            $out[ $arg ] = (string) ($row[ 'zbsc_' . $key ] ?? '');
        }
        $out['wp_user_id']   = (int) ($row['zbsc_wpid'] ?? 0) > 0 ? (int) $row['zbsc_wpid'] : null;
        $out['created']      = self::date((int) ($row['zbsc_created'] ?? 0));
        $out['last_updated'] = self::date((int) ($row['zbsc_lastupdated'] ?? 0));
        return $out;
    }

    /** @return array<string, mixed> */
    private static function mask(array $contact): array
    {
        return Masking::keys($contact, self::PERSONAL);
    }

    /** @return string[] */
    private static function tags(int $id): array
    {
        global $wpdb;
        if (! Plugin_Table_Rows_Snapshot::table_exists(self::table('tags_links')) || ! Plugin_Table_Rows_Snapshot::table_exists(self::table('tags'))) {
            return [];
        }
        return array_map('strval', (array) $wpdb->get_col($wpdb->prepare(
            'SELECT t.zbstag_name FROM %i l JOIN %i t ON t.ID = l.zbstl_tagid WHERE l.zbstl_objtype = %d AND l.zbstl_objid = %d ORDER BY t.zbstag_name',
            self::table('tags_links'),
            self::table('tags'),
            self::TYPE_CONTACT,
            $id
        )));
    }

    /** @return array<string, mixed> */
    private static function list_contacts(array $args): array
    {
        global $wpdb;
        $page     = max(1, (int) ($args['page'] ?? 1));
        $per_page = min(self::MAX_PER_PAGE, max(1, (int) ($args['per_page'] ?? 20)));
        $where    = [ '1=1' ];
        $params   = [];
        if (isset($args['status']) && '' !== (string) $args['status']) {
            $where[]  = 'zbsc_status = %s';
            $params[] = (string) $args['status'];
        }
        $search = trim((string) ($args['search'] ?? ''));
        if ('' !== $search) {
            $like     = '%' . $wpdb->esc_like($search) . '%';
            $where[]  = '(zbsc_email LIKE %s OR zbsc_fname LIKE %s OR zbsc_lname LIKE %s OR CONCAT(zbsc_fname, \' \', zbsc_lname) LIKE %s)';
            $params   = array_merge($params, [ $like, $like, $like, $like ]);
        }
        $sql_where = implode(' AND ', $where);
        $table     = self::table('contacts');

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $sql_where holds only fixed clauses with %s placeholders, matched by $params.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE {$sql_where}", $table, ...$params));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $sql_where holds only fixed clauses with %s placeholders, matched by $params.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE {$sql_where} ORDER BY ID DESC LIMIT %d OFFSET %d", $table, ...array_merge($params, [ $per_page, ($page - 1) * $per_page ])), ARRAY_A);

        return [
            'contacts' => array_map(static fn (array $row): array => self::mask(self::view($row, false)), $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'masked'   => true,
        ];
    }

    /** @return array<string, mixed> */
    private static function get_contact(array $args): array
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            $id = self::email_holder(trim((string) $args['email']), 0);
        }
        $row = $id > 0 ? self::row($id) : null;
        if (null === $row) {
            throw new Operation_Refused('contact_not_found', isset($args['id']) ? sprintf('Jetpack CRM has no contact %d.', (int) $args['id']) : 'Jetpack CRM has no contact with that email.');
        }
        $contact         = self::view($row, true);
        $contact['tags'] = self::tags($id);
        if (current_user_can('manage_options')) {
            return [ 'contact' => $contact ];
        }
        return [ 'contact' => self::mask($contact), 'masked' => true ];
    }

    /** @return array<string, mixed> */
    private static function list_forms(array $args): array
    {
        global $wpdb;
        $page     = max(1, (int) ($args['page'] ?? 1));
        $per_page = min(self::MAX_PER_PAGE, max(1, (int) ($args['per_page'] ?? 20)));
        $table    = self::table('forms');
        $total    = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $table));
        $rows     = (array) $wpdb->get_results($wpdb->prepare('SELECT ID, zbsf_title, zbsf_style, zbsf_views, zbsf_conversions, zbsf_created FROM %i ORDER BY ID DESC LIMIT %d OFFSET %d', $table, $per_page, ($page - 1) * $per_page), ARRAY_A);

        return [
            'forms'    => array_map(static fn (array $row): array => [
                'id'          => (int) $row['ID'],
                'title'       => (string) $row['zbsf_title'],
                'style'       => (string) $row['zbsf_style'],
                'views'       => (int) $row['zbsf_views'],
                'conversions' => (int) $row['zbsf_conversions'],
                'shortcode'   => sprintf('[jetpackcrm_form id="%d"]', (int) $row['ID']),
                'created'     => self::date((int) $row['zbsf_created']),
            ], $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }

    /** @return array<string, string> data key => value, from the given args */
    private static function data_from(array $args): array
    {
        $data = [];
        foreach (self::FIELDS as $arg => $key) {
            if (array_key_exists($arg, $args)) {
                $data[ $key ] = sanitize_text_field((string) $args[ $arg ]);
            }
        }
        return $data;
    }

    /** @return array<string, mixed> */
    private static function create(array $args, array $context): array
    {
        $api       = self::api();
        $operation = (string) ($context['operation_id'] ?? '');
        $reserved  = (int) (Plugin_Table_Rows_Snapshot::parse((string) ($context['target']['object_id'] ?? ''))['ids'][0] ?? 0);
        if (null === $api || ! method_exists($api, 'addUpdateContact')) {
            if ('' !== $operation) {
                Snapshot_Store::delete_operation($operation);
            }
            throw new Operation_Refused('plugin_api_missing', 'Jetpack CRM\'s contacts API is not loaded, so nothing was created.');
        }

        $id = (int) $api->addUpdateContact([ 'data' => self::data_from($args) ]);
        if ($id <= 0) {
            if ('' !== $operation) {
                Snapshot_Store::delete_operation($operation);
            }
            throw new Operation_Refused('create_failed', 'Jetpack CRM did not create the contact (it refused the fields or another contact holds the email).');
        }
        if ($id !== $reserved && '' !== $operation) {
            // Another writer took the id this snapshot reserved: the undo
            // point names the row the plugin really inserted.
            Snapshot_Store::update_snapshot($operation, [
                'object_type' => Plugin_Table_Rows_Snapshot::TYPE,
                'object_id'   => Plugin_Table_Rows_Snapshot::key('jpcrm', 'contacts', [ $id ]),
                'data'        => [
                    'kind'         => 'jpcrm',
                    'suffix'       => 'contacts',
                    'ids'          => [ $id ],
                    'table_exists' => true,
                    'rows'         => [],
                ],
            ]);
        }

        $out = [ 'contact' => self::view((array) self::row($id), true) ];
        if (function_exists('zeroBSCRM_getSetting') && (int) \zeroBSCRM_getSetting('portalusers') > 0) {
            $out['warning'] = 'Jetpack CRM is set to create a client portal user for new contacts; undoing this create does not remove that WordPress user.';
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function update(array $args): array
    {
        $id  = (int) $args['id'];
        $api = self::api();
        if (null === $api || ! method_exists($api, 'addUpdateContact')) {
            throw new Operation_Refused('plugin_api_missing', 'Jetpack CRM\'s contacts API is not loaded, so nothing was changed.');
        }
        $limited = [];
        foreach (self::data_from($args) as $key => $value) {
            $limited[] = [ 'key' => 'zbsc_' . $key, 'val' => $value, 'type' => '%s' ];
        }
        if (false === $api->addUpdateContact([ 'id' => $id, 'limitedFields' => $limited ])) {
            throw new Operation_Refused('update_failed', sprintf('Jetpack CRM did not update contact %d.', $id));
        }
        return [ 'contact' => self::view((array) self::row($id), true) ];
    }
}
