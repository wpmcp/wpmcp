<?php

namespace WPMCP\Integrations;

use WPMCP\Privacy\Masking;
use WPMCP\Safety\Crm_Rows_Snapshot;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The Newsletter plugin's subscribers, lists and forms (issue #683), part of
 * Mailing_List_Pack.
 *
 * Newsletter (9.x) keeps one row per subscriber in {prefix}newsletter: email,
 * name and surname, a one-letter status (C confirmed, S not confirmed, U
 * unsubscribed, B bounced, P complained, T temporary), a token its
 * unsubscribe links carry, and one list_<n> column per list (1 a member).
 * Its lists are names in the newsletter_lists option (list_<n>, with
 * list_<n>_status 1 for a public list), its forms rows of
 * {prefix}newsletter_customforms plus the older HTML forms in the
 * newsletter_htmlforms option (form_1 to form_10), and its opt-in mode the
 * noconfirmation key of newsletter_subscription.
 *
 * Reads (manage_options, the capability Newsletter's admin takes by
 * default): newsletter-summary, -list-lists, -list-contacts (email and names
 * masked), -get-contact (one in full, never its token) and -list-forms.
 *
 * Writes (off until wpmcp_enable_newsletter_write) go through Newsletter's
 * own save_user() when it is loaded, the call its admin screens make, and
 * each is snapshotted first as a 'crm_rows' image of the one subscriber row
 * (kind newsletter_subscriber), so rollback-operation undoes it:
 *  - create-contact: a new subscriber is created not confirmed (pending)
 *    unless the site allows direct subscribes through
 *    wpmcp_crm_direct_subscribe; an email that already exists is refused.
 *    No confirmation email is sent by this op;
 *  - update-contact: name and surname, and unsubscribe (status
 *    unsubscribed, the only status a write sets). Rollback never sets an
 *    unsubscribed, bounced or complained subscriber back to a mailable
 *    status;
 *  - update-contact-lists: add to and remove from lists; a subscriber who
 *    unsubscribed, bounced or complained is never added.
 */
final class Mailing_List_Newsletter
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads Newsletter's own subscriber table live; it has no cached read API for a filtered list.

    public const CAPABILITY = 'manage_options';

    public const OPT_IN_FILTER = 'wpmcp_enable_newsletter_write';

    public const PLUGIN_FILE = 'newsletter/plugin.php';

    /** Newsletter's highest list number (NEWSLETTER_LIST_MAX). */
    public const LIST_MAX = 40;

    /** Status letter => the word the ops use. */
    private const STATUSES = [
        'C' => 'subscribed',
        'S' => 'pending',
        'U' => 'unsubscribed',
        'B' => 'bounced',
        'P' => 'complained',
        'T' => 'temporary',
    ];

    /** The statuses that stop mail. */
    private const SUPPRESSED = [ 'U', 'B', 'P' ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_newsletter_active', defined('NEWSLETTER_VERSION') || class_exists('Newsletter', false));
    }

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'newsletter';
    }

    /** @return true|array{code: string, message: string, data: array} */
    public static function presence()
    {
        $present = Mailing_List_Pack::presence(self::active(), 'Newsletter', self::PLUGIN_FILE);
        if (true !== $present || Mailing_List_Pack::table_exists(self::table())) {
            return $present;
        }
        return [
            'code'    => 'newsletter_tables_missing',
            'message' => sprintf('Newsletter\'s table %s does not exist on this site. Deactivate and reactivate Newsletter so it creates its tables, then try again.', self::table()),
            'data'    => [ 'plugins_url' => admin_url('plugins.php') ],
        ];
    }

    // ------------------------------------------------------------------
    // Catalog
    // ------------------------------------------------------------------

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $base   = [
            'tier'       => 'pro',
            'capability' => self::CAPABILITY,
            'requires'   => static fn () => self::presence(),
        ];
        $write  = $base + [
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_newsletter_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $id     = [ 'type' => 'integer', 'minimum' => 1 ];
        $lists  = [ 'type' => 'array', 'items' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::LIST_MAX ], 'minItems' => 1, 'maxItems' => self::LIST_MAX, 'uniqueItems' => true ];
        $names  = [
            'first_name' => [ 'type' => 'string', 'maxLength' => 100 ],
            'last_name'  => [ 'type' => 'string', 'maxLength' => 100 ],
        ];
        $target = static fn (int $contact): array => [
            'object_type' => Crm_Rows_Snapshot::TYPE,
            'object_id'   => Crm_Rows_Snapshot::key('newsletter_subscriber', $contact),
        ];

        return [
            'newsletter-summary'              => $base + [
                'mode'         => 'read',
                'description'  => 'Newsletter setup: version, contacts by status, lists, forms, and whether a sign-up is double or single opt-in',
                'input_schema' => [ 'type' => 'object', 'properties' => (object) [] ],
                'handler'      => static fn (): array => self::summary(),
            ],
            'newsletter-list-lists'           => $base + [
                'mode'         => 'read',
                'description'  => 'List Newsletter lists: id, title, public, and how many contacts (and subscribed contacts) each holds',
                'input_schema' => [ 'type' => 'object', 'properties' => (object) [] ],
                'handler'      => static fn (): array => [ 'lists' => self::lists_with_counts() ],
            ],
            'newsletter-list-contacts'        => $base + [
                'mode'         => 'read',
                'description'  => 'List Newsletter contacts newest first, email and names MASKED (newsletter-get-contact returns one in full). search matches email and name; status and list_id filter; page and per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'search'  => [ 'type' => 'string', 'maxLength' => 100 ],
                        'status'  => [ 'type' => 'string', 'enum' => [ 'subscribed', 'pending', 'unsubscribed', 'bounced', 'complained' ] ],
                        'list_id' => $id,
                    ] + Mailing_List_Pack::paging_schema(),
                ],
                'validate'     => static fn (array $args): ?array => isset($args['list_id']) ? self::lists_refusal([ (int) $args['list_id'] ]) : null,
                'handler'      => static fn (array $args): array => self::list_contacts($args),
            ],
            'newsletter-get-contact'          => $base + [
                'mode'         => 'read',
                'description'  => 'One Newsletter contact by id or email, with its status and lists',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'    => $id,
                        'email' => [ 'type' => 'string', 'minLength' => 3, 'maxLength' => 100 ],
                    ],
                ],
                'validate'     => static fn (array $args): ?array => isset($args['id']) || isset($args['email']) ? null : [ 'code' => 'invalid_args', 'message' => 'Name the contact by id or email.', 'data' => [] ],
                'handler'      => static fn (array $args): array => [ 'contact' => self::require_contact(isset($args['id']) ? (int) $args['id'] : self::id_by_email((string) $args['email'])) ],
            ],
            'newsletter-list-forms'           => $base + [
                'mode'         => 'read',
                'description'  => 'List Newsletter forms: custom forms (id, title, lists, opt-in) and the older HTML forms, each with the shortcode that places it',
                'input_schema' => [ 'type' => 'object', 'properties' => (object) [] ],
                'handler'      => static fn (): array => [ 'forms' => self::forms() ],
            ],
            'newsletter-create-contact'       => $write + [
                'description'  => 'Create a Newsletter contact, not confirmed (pending) unless status subscribed and the site allows direct subscribes (wpmcp_crm_direct_subscribe); lists by id. No email is sent. An email that already exists is refused',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'email'  => [ 'type' => 'string', 'format' => 'email', 'minLength' => 3, 'maxLength' => 100 ],
                        'status' => [ 'type' => 'string', 'enum' => [ 'pending', 'subscribed' ] ],
                        'lists'  => $lists,
                    ] + $names,
                    'required'   => [ 'email' ],
                ],
                'validate'     => static fn (array $args): ?array => self::create_refusal($args),
                'snapshot'     => static fn (array $args): array => $target(0),
                'handler'      => static fn (array $args, array $context = []): array => self::create_contact($args, $context),
            ],
            'newsletter-update-contact'       => $write + [
                'description'  => 'Update a Newsletter contact\'s first_name and last_name, or unsubscribe it (status: unsubscribed, the only status a write sets)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'     => $id,
                        'status' => [ 'type' => 'string', 'enum' => [ 'unsubscribed' ] ],
                    ] + $names,
                    'required'   => [ 'id' ],
                ],
                'validate'     => static fn (array $args): ?array => self::update_refusal($args),
                'snapshot'     => static fn (array $args): array => $target((int) $args['id']),
                'handler'      => static fn (array $args, array $context = []): array => self::update_contact($args, $context),
            ],
            'newsletter-update-contact-lists' => $write + [
                'description'  => 'Add a Newsletter contact to lists (add) and remove it from lists (remove), by list id. A contact who unsubscribed, bounced or complained is never added',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'     => $id,
                        'add'    => $lists,
                        'remove' => $lists,
                    ],
                    'required'   => [ 'id' ],
                ],
                'validate'     => static fn (array $args): ?array => self::membership_refusal($args),
                'snapshot'     => static fn (array $args): array => $target((int) $args['id']),
                'handler'      => static fn (array $args, array $context = []): array => self::update_lists($args, $context),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function summary(): array
    {
        global $wpdb;

        $rows   = (array) $wpdb->get_results($wpdb->prepare('SELECT status, COUNT(*) AS n FROM %i GROUP BY status', self::table()), ARRAY_A);
        $counts = array_fill_keys(array_slice(array_values(self::STATUSES), 0, 5), 0);
        $total  = 0;
        foreach ($rows as $row) {
            $word = self::STATUSES[ (string) $row['status'] ] ?? null;
            $total += (int) $row['n'];
            if (null !== $word && isset($counts[ $word ])) {
                $counts[ $word ] = (int) $row['n'];
            }
        }
        $settings = get_option('newsletter_subscription', []);
        $single   = is_array($settings) && array_key_exists('noconfirmation', $settings) ? ! empty($settings['noconfirmation']) : true;

        return [
            'plugin'   => 'Newsletter',
            'version'  => defined('NEWSLETTER_VERSION') ? (string) constant('NEWSLETTER_VERSION') : null,
            'contacts' => [ 'total' => $total, 'by_status' => $counts ],
            'lists'    => count(self::lists()),
            'forms'    => count(self::forms()),
            'optin'    => $single ? 'single' : 'double',
        ];
    }

    /**
     * The lists the site defines: list number => [title, public].
     *
     * @return array<int, array{title: string, public: bool}>
     */
    private static function lists(): array
    {
        $options = get_option('newsletter_lists', []);
        $out     = [];
        for ($i = 1; $i <= self::LIST_MAX; $i++) {
            if (! is_array($options) || empty($options[ 'list_' . $i ])) {
                continue;
            }
            $out[ $i ] = [ 'title' => (string) $options[ 'list_' . $i ], 'public' => ! empty($options[ 'list_' . $i . '_status' ]) ];
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private static function lists_with_counts(): array
    {
        global $wpdb;

        $lists = self::lists();
        if ([] === $lists) {
            return [];
        }
        $select = [];
        $args   = [];
        foreach (array_keys($lists) as $n) {
            $select[] = 'SUM(%i = 1) AS %i, SUM(%i = 1 AND status = %s) AS %i';
            array_push($args, 'list_' . $n, 'all_' . $n, 'list_' . $n, 'C', 'sub_' . $n);
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $select holds only placeholders.
        $row = (array) $wpdb->get_row($wpdb->prepare('SELECT ' . implode(', ', $select) . ' FROM %i', ...array_merge($args, [ self::table() ])), ARRAY_A);
        $out = [];
        foreach ($lists as $n => $list) {
            $out[] = [
                'id'         => $n,
                'title'      => $list['title'],
                'public'     => $list['public'],
                'contacts'   => (int) ($row[ 'all_' . $n ] ?? 0),
                'subscribed' => (int) ($row[ 'sub_' . $n ] ?? 0),
            ];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function list_contacts(array $args): array
    {
        global $wpdb;

        [$limit, $offset, $page, $per_page] = Mailing_List_Pack::paging($args);
        $where  = [ '1 = 1' ];
        $params = [];
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $like     = '%' . $wpdb->esc_like(trim((string) $args['search'])) . '%';
            $where[]  = '(email LIKE %s OR name LIKE %s OR surname LIKE %s)';
            array_push($params, $like, $like, $like);
        }
        if (isset($args['status'])) {
            $where[]  = 'status = %s';
            $params[] = (string) array_search((string) $args['status'], self::STATUSES, true);
        }
        if (isset($args['list_id'])) {
            $where[]  = '%i = 1';
            $params[] = 'list_' . (int) $args['list_id'];
        }
        $sql = implode(' AND ', $where);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $sql holds only placeholders, matched by $params.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE {$sql}", self::table(), ...$params));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $sql holds only placeholders, matched by $params.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE {$sql} ORDER BY id DESC LIMIT %d OFFSET %d", self::table(), ...array_merge($params, [ $limit, $offset ])), ARRAY_A);

        return [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'contacts' => array_map(static fn (array $row): array => Masking::keys(self::view($row), [ 'email', 'first_name', 'last_name' ]), $rows),
            'masked'   => true,
        ];
    }

    /** @return array<string, mixed> one subscriber row as the ops show it, never its token */
    private static function view(array $row): array
    {
        $lists = [];
        foreach (array_keys(self::lists()) as $n) {
            if (1 === (int) ($row[ 'list_' . $n ] ?? 0)) {
                $lists[] = $n;
            }
        }
        return [
            'id'         => (int) $row['id'],
            'email'      => (string) $row['email'],
            'first_name' => (string) $row['name'],
            'last_name'  => (string) $row['surname'],
            'status'     => self::STATUSES[ (string) $row['status'] ] ?? (string) $row['status'],
            'lists'      => $lists,
            'language'   => (string) ($row['language'] ?? ''),
            'source'     => (string) ($row['source'] ?? ''),
            'wp_user_id' => (int) ($row['wp_user_id'] ?? 0),
            'created'    => (string) ($row['created'] ?? ''),
        ];
    }

    private static function row(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE id = %d', self::table(), $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private static function id_by_email(string $email): int
    {
        global $wpdb;
        $email = strtolower(trim($email));
        return '' === $email ? 0 : (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM %i WHERE email = %s ORDER BY id ASC LIMIT 1', self::table(), $email));
    }

    private static function require_contact(int $id): array
    {
        $row = $id > 0 ? self::row($id) : null;
        if (null === $row) {
            throw new Operation_Refused('contact_not_found', 'No such Newsletter contact.');
        }
        return self::view($row);
    }

    /** @return array<int, array<string, mixed>> */
    private static function forms(): array
    {
        global $wpdb;

        $out   = [];
        $table = $wpdb->prefix . 'newsletter_customforms';
        if (Mailing_List_Pack::table_exists($table)) {
            foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT id, name, lists, optin FROM %i ORDER BY id ASC', $table), ARRAY_A) as $row) {
                $out[] = [
                    'id'        => (int) $row['id'],
                    'kind'      => 'custom',
                    'title'     => (string) $row['name'],
                    'lists'     => array_values(array_filter(array_map('intval', wp_parse_list((string) $row['lists'])), static fn (int $n): bool => $n > 0)),
                    'optin'     => '' === (string) $row['optin'] ? 'default' : (string) $row['optin'],
                    'shortcode' => sprintf('[newsletter_form id="%d"]', (int) $row['id']),
                ];
            }
        }
        $html = get_option('newsletter_htmlforms', []);
        for ($i = 1; $i <= 10; $i++) {
            if (is_array($html) && '' !== trim((string) ($html[ 'form_' . $i ] ?? ''))) {
                $out[] = [ 'id' => $i, 'kind' => 'html', 'title' => 'Form ' . $i, 'shortcode' => sprintf('[newsletter_form form="%d"]', $i) ];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Checks
    // ------------------------------------------------------------------

    /** @param int[] $ids */
    private static function lists_refusal(array $ids): ?array
    {
        $missing = array_values(array_diff($ids, array_keys(self::lists())));
        if ([] === $missing) {
            return null;
        }
        return [
            'code'    => 'list_not_found',
            'message' => sprintf('Not a Newsletter list: %s.', implode(', ', $missing)),
            'data'    => [ 'missing' => $missing, 'lists' => array_keys(self::lists()) ],
        ];
    }

    private static function contact_refusal(int $id): ?array
    {
        if (null !== self::row($id)) {
            return null;
        }
        return [ 'code' => 'contact_not_found', 'message' => sprintf('Newsletter contact %d does not exist.', $id), 'data' => [] ];
    }

    private static function create_refusal(array $args): ?array
    {
        $email = sanitize_email((string) $args['email']);
        if (! is_email($email)) {
            return [ 'code' => 'invalid_args', 'message' => 'email is not a valid email address.', 'data' => [] ];
        }
        $existing = self::id_by_email($email);
        if ($existing > 0) {
            return [
                'code'    => 'contact_exists',
                'message' => sprintf('Newsletter already has a contact with this email (%d); update it instead.', $existing),
                'data'    => [ 'id' => $existing ],
            ];
        }
        if ('subscribed' === ($args['status'] ?? null) && ! (bool) apply_filters('wpmcp_crm_direct_subscribe', false, 'newsletter')) {
            return [
                'code'    => 'double_optin_required',
                'message' => 'A new Newsletter contact is created not confirmed and confirms through Newsletter\'s own sign-up; a site allows direct subscribes with the wpmcp_crm_direct_subscribe filter.',
                'data'    => [],
            ];
        }
        return self::lists_refusal(array_map('intval', (array) ($args['lists'] ?? [])));
    }

    private static function update_refusal(array $args): ?array
    {
        $refusal = self::contact_refusal((int) $args['id']);
        if (null !== $refusal) {
            return $refusal;
        }
        if ([] === array_intersect([ 'first_name', 'last_name', 'status' ], array_keys($args))) {
            return [ 'code' => 'nothing_to_update', 'message' => 'Name at least one of first_name, last_name or status.', 'data' => [] ];
        }
        return null;
    }

    private static function membership_refusal(array $args): ?array
    {
        $refusal = self::contact_refusal((int) $args['id']);
        if (null !== $refusal) {
            return $refusal;
        }
        $add    = array_map('intval', (array) ($args['add'] ?? []));
        $remove = array_map('intval', (array) ($args['remove'] ?? []));
        if ([] === $add && [] === $remove) {
            return [ 'code' => 'nothing_to_update', 'message' => 'Name list ids to add or remove.', 'data' => [] ];
        }
        if ([] !== array_intersect($add, $remove)) {
            return [ 'code' => 'invalid_args', 'message' => 'A list cannot be both added and removed.', 'data' => [ 'both' => array_values(array_intersect($add, $remove)) ] ];
        }
        $refusal = self::lists_refusal(array_merge($add, $remove));
        if (null !== $refusal || [] === $add) {
            return $refusal;
        }
        $status = (string) (self::row((int) $args['id'])['status'] ?? '');
        if (in_array($status, self::SUPPRESSED, true)) {
            return [
                'code'    => 'contact_suppressed',
                'message' => sprintf('Newsletter contact %d is %s: it is not added to lists, so it is never mailed again without its own consent.', (int) $args['id'], self::STATUSES[ $status ]),
                'data'    => [ 'status' => self::STATUSES[ $status ] ],
            ];
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    /**
     * Save one subscriber row through Newsletter's own save_user() when it
     * is loaded (the call its admin screens make), else the same insert or
     * update on its table. Returns the row's id.
     *
     * @param array<string, mixed> $row
     */
    private static function save(array $row): int
    {
        global $wpdb;

        if (class_exists('Newsletter', false) && method_exists('Newsletter', 'instance')) {
            $saved = \Newsletter::instance()->save_user($row, ARRAY_A);
            if (! is_array($saved) || empty($saved['id'])) {
                throw new Operation_Refused('write_failed', 'Newsletter could not save the contact: ' . $wpdb->last_error);
            }
            return (int) $saved['id'];
        }
        if (! empty($row['id'])) {
            $id = (int) $row['id'];
            unset($row['id']);
            if (false === $wpdb->update(self::table(), $row, [ 'id' => $id ])) {
                throw new Operation_Refused('write_failed', 'Could not save the Newsletter contact: ' . $wpdb->last_error);
            }
            return $id;
        }
        if (false === $wpdb->insert(self::table(), $row)) {
            throw new Operation_Refused('write_failed', 'Could not save the Newsletter contact: ' . $wpdb->last_error);
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Run one write with the rows it touches recorded into its snapshot.
     *
     * @param callable(): int $write
     */
    private static function tracked(array $context, callable $write): int
    {
        return (int) Crm_Rows_Snapshot::tracked((string) ($context['operation_id'] ?? ''), $write, static fn ($id): int => (int) $id);
    }

    /** @return array<string, string> the name columns an op named */
    private static function names(array $args): array
    {
        $out = [];
        foreach ([ 'first_name' => 'name', 'last_name' => 'surname' ] as $arg => $column) {
            if (isset($args[ $arg ])) {
                $out[ $column ] = sanitize_text_field((string) $args[ $arg ]);
            }
        }
        return $out;
    }

    private static function create_contact(array $args, array $context): array
    {
        $row = [
            'email'   => strtolower(sanitize_email((string) $args['email'])),
            'status'  => 'subscribed' === ($args['status'] ?? null) ? 'C' : 'S',
            'token'   => wp_generate_password(10, false, false),
            'updated' => time(),
            'source'  => 'wpmcp',
        ] + self::names($args);
        foreach (array_map('intval', (array) ($args['lists'] ?? [])) as $n) {
            $row[ 'list_' . $n ] = 1;
        }
        $id = self::tracked($context, static fn (): int => self::save($row));
        return [ 'contact' => self::require_contact($id) ];
    }

    private static function update_contact(array $args, array $context): array
    {
        $id  = (int) $args['id'];
        $row = self::names($args);
        if ('unsubscribed' === ($args['status'] ?? null) && 'U' !== (self::row($id)['status'] ?? null)) {
            $row['status']     = 'U';
            $row['unsub_time'] = time();
        }
        if ([] !== $row) {
            $row['id']      = $id;
            $row['updated'] = time();
            self::tracked($context, static fn (): int => self::save($row));
        }
        return [ 'contact' => self::require_contact($id) ];
    }

    private static function update_lists(array $args, array $context): array
    {
        $id  = (int) $args['id'];
        $row = [ 'id' => $id, 'updated' => time() ];
        foreach (array_map('intval', (array) ($args['remove'] ?? [])) as $n) {
            $row[ 'list_' . $n ] = 0;
        }
        foreach (array_map('intval', (array) ($args['add'] ?? [])) as $n) {
            $row[ 'list_' . $n ] = 1;
        }
        self::tracked($context, static fn (): int => self::save($row));
        return [ 'contact' => self::require_contact($id) ];
    }
}
