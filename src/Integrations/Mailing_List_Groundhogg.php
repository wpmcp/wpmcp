<?php

namespace WPMCP\Integrations;

use WPMCP\Privacy\Masking;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Groundhogg's contacts, tags and forms (issue #683), read only, part of
 * Mailing_List_Pack.
 *
 * Groundhogg (4.x) keeps contacts in {prefix}gh_contacts (ID, email, names,
 * optin_status 1 unconfirmed, 2 confirmed, 3 unsubscribed, 4 weekly, 5
 * monthly, 6 hard bounce, 7 spam, 8 complained), tags in {prefix}gh_tags and
 * who holds which tag in {prefix}gh_tag_relationships. It has no lists: tags
 * are how it segments contacts, so groundhogg-list-lists lists the tags. A
 * form is a form_fill step of a funnel ({prefix}gh_steps, {prefix}gh_funnels),
 * placed with [gh_form id="<step id>"].
 *
 * Every op takes manage_options. Contact writes are left for a follow-up:
 * a contact's tags live in a table keyed by contact and tag with no row id,
 * which the 'crm_rows' snapshot cannot undo yet.
 */
final class Mailing_List_Groundhogg
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads Groundhogg's own tables live; it has no cached read API for a filtered list.

    public const CAPABILITY = 'manage_options';

    public const PLUGIN_FILE = 'groundhogg/groundhogg.php';

    /** optin_status => the word the ops use. */
    private const STATUSES = [
        1 => 'pending',
        2 => 'confirmed',
        3 => 'unsubscribed',
        4 => 'weekly',
        5 => 'monthly',
        6 => 'bounced',
        7 => 'spam',
        8 => 'complained',
    ];

    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_groundhogg_active', defined('GROUNDHOGG_VERSION'));
    }

    public static function table(string $name): string
    {
        global $wpdb;
        return $wpdb->prefix . 'gh_' . $name;
    }

    /** @return true|array{code: string, message: string, data: array} */
    public static function presence()
    {
        $present = Mailing_List_Pack::presence(self::active(), 'Groundhogg', self::PLUGIN_FILE);
        if (true !== $present) {
            return $present;
        }
        foreach ([ 'contacts', 'tags', 'tag_relationships' ] as $name) {
            if (! Mailing_List_Pack::table_exists(self::table($name))) {
                return [
                    'code'    => 'groundhogg_tables_missing',
                    'message' => sprintf('Groundhogg\'s table %s does not exist on this site. Deactivate and reactivate Groundhogg so it creates its tables, then try again.', self::table($name)),
                    'data'    => [ 'plugins_url' => admin_url('plugins.php') ],
                ];
            }
        }
        return true;
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $base = [
            'tier'       => 'pro',
            'mode'       => 'read',
            'capability' => self::CAPABILITY,
            'requires'   => static fn () => self::presence(),
        ];

        return [
            'groundhogg-summary'       => $base + [
                'description'  => 'Groundhogg setup: version, contacts by opt-in status, tags and form steps',
                'input_schema' => [ 'type' => 'object', 'properties' => (object) [] ],
                'handler'      => static fn (): array => self::summary(),
            ],
            'groundhogg-list-lists'    => $base + [
                'description'  => 'List Groundhogg tags, its lists: id, title, slug, description and how many contacts hold each',
                'input_schema' => [ 'type' => 'object', 'properties' => (object) [] ],
                'handler'      => static fn (): array => [ 'lists' => self::tags() ],
            ],
            'groundhogg-list-contacts' => $base + [
                'description'  => 'List Groundhogg contacts newest first with their tag ids, email and names MASKED. search matches email and name; status and tag_id filter; page and per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'search' => [ 'type' => 'string', 'maxLength' => 100 ],
                        'status' => [ 'type' => 'string', 'enum' => array_values(self::STATUSES) ],
                        'tag_id' => [ 'type' => 'integer', 'minimum' => 1 ],
                    ] + Mailing_List_Pack::paging_schema(),
                ],
                'handler'      => static fn (array $args): array => self::list_contacts($args),
            ],
            'groundhogg-list-forms'    => $base + [
                'description'  => 'List Groundhogg forms (form steps of funnels): id, title, funnel and its status, step status and the shortcode that places it',
                'input_schema' => [ 'type' => 'object', 'properties' => (object) [] ],
                'handler'      => static fn (): array => [ 'forms' => self::forms() ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function summary(): array
    {
        global $wpdb;

        $counts = array_fill_keys(array_values(self::STATUSES), 0);
        $total  = 0;
        foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT optin_status, COUNT(*) AS n FROM %i GROUP BY optin_status', self::table('contacts')), ARRAY_A) as $row) {
            $total += (int) $row['n'];
            $word   = self::STATUSES[ (int) $row['optin_status'] ] ?? null;
            if (null !== $word) {
                $counts[ $word ] = (int) $row['n'];
            }
        }
        return [
            'plugin'   => 'Groundhogg',
            'version'  => defined('GROUNDHOGG_VERSION') ? (string) constant('GROUNDHOGG_VERSION') : null,
            'contacts' => [ 'total' => $total, 'by_status' => $counts ],
            'tags'     => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', self::table('tags'))),
            'forms'    => count(self::forms()),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function tags(): array
    {
        global $wpdb;

        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                'SELECT t.tag_id, t.tag_name, t.tag_slug, t.tag_description, COUNT(r.contact_id) AS n FROM %i t LEFT JOIN %i r ON r.tag_id = t.tag_id GROUP BY t.tag_id, t.tag_name, t.tag_slug, t.tag_description ORDER BY t.tag_id ASC',
                self::table('tags'),
                self::table('tag_relationships')
            ),
            ARRAY_A
        );
        return array_map(
            static fn (array $row): array => [
                'id'          => (int) $row['tag_id'],
                'title'       => (string) $row['tag_name'],
                'slug'        => (string) $row['tag_slug'],
                'description' => (string) $row['tag_description'],
                'contacts'    => (int) $row['n'],
            ],
            $rows
        );
    }

    /** @return array<string, mixed> */
    private static function list_contacts(array $args): array
    {
        global $wpdb;

        [$limit, $offset, $page, $per_page] = Mailing_List_Pack::paging($args);
        $where  = [ '1 = 1' ];
        $params = [];
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $like    = '%' . $wpdb->esc_like(trim((string) $args['search'])) . '%';
            $where[] = '(c.email LIKE %s OR c.first_name LIKE %s OR c.last_name LIKE %s)';
            array_push($params, $like, $like, $like);
        }
        if (isset($args['status'])) {
            $where[]  = 'c.optin_status = %d';
            $params[] = (int) array_search((string) $args['status'], self::STATUSES, true);
        }
        if (isset($args['tag_id'])) {
            $where[]  = 'EXISTS (SELECT 1 FROM %i r WHERE r.contact_id = c.ID AND r.tag_id = %d)';
            $params[] = self::table('tag_relationships');
            $params[] = (int) $args['tag_id'];
        }
        $sql = implode(' AND ', $where);

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $sql holds only placeholders, matched by $params.
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i c WHERE {$sql}", self::table('contacts'), ...$params));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $sql holds only placeholders, matched by $params.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT c.ID, c.email, c.first_name, c.last_name, c.optin_status, c.user_id, c.date_created FROM %i c WHERE {$sql} ORDER BY c.ID DESC LIMIT %d OFFSET %d", self::table('contacts'), ...array_merge($params, [ $limit, $offset ])), ARRAY_A);

        $tags = [];
        $ids  = array_map(static fn (array $row): int => (int) $row['ID'], $rows);
        if ([] !== $ids) {
            $in = implode(',', array_fill(0, count($ids), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds only %d placeholders.
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT contact_id, tag_id FROM %i WHERE contact_id IN ($in) ORDER BY tag_id ASC", self::table('tag_relationships'), ...$ids), ARRAY_A) as $rel) {
                $tags[ (int) $rel['contact_id'] ][] = (int) $rel['tag_id'];
            }
        }

        $contacts = [];
        foreach ($rows as $row) {
            $contacts[] = Masking::keys(
                [
                    'id'         => (int) $row['ID'],
                    'email'      => (string) $row['email'],
                    'first_name' => (string) $row['first_name'],
                    'last_name'  => (string) $row['last_name'],
                    'status'     => self::STATUSES[ (int) $row['optin_status'] ] ?? (string) $row['optin_status'],
                    'tags'       => $tags[ (int) $row['ID'] ] ?? [],
                    'user_id'    => (int) $row['user_id'],
                    'created'    => (string) $row['date_created'],
                ],
                [ 'email', 'first_name', 'last_name' ]
            );
        }

        return [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'contacts' => $contacts,
            'masked'   => true,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function forms(): array
    {
        global $wpdb;

        if (! Mailing_List_Pack::table_exists(self::table('steps'))) {
            return [];
        }
        $funnels = Mailing_List_Pack::table_exists(self::table('funnels'));
        $rows    = $funnels
            ? (array) $wpdb->get_results($wpdb->prepare('SELECT s.ID, s.step_title, s.step_status, s.funnel_id, f.title AS funnel, f.status AS funnel_status FROM %i s LEFT JOIN %i f ON f.ID = s.funnel_id WHERE s.step_type = %s ORDER BY s.ID ASC', self::table('steps'), self::table('funnels'), 'form_fill'), ARRAY_A)
            : (array) $wpdb->get_results($wpdb->prepare('SELECT ID, step_title, step_status, funnel_id FROM %i WHERE step_type = %s ORDER BY ID ASC', self::table('steps'), 'form_fill'), ARRAY_A);

        return array_map(
            static fn (array $row): array => [
                'id'            => (int) $row['ID'],
                'title'         => (string) $row['step_title'],
                'status'        => (string) $row['step_status'],
                'funnel_id'     => (int) $row['funnel_id'],
                'funnel'        => isset($row['funnel']) ? (string) $row['funnel'] : null,
                'funnel_status' => isset($row['funnel_status']) ? (string) $row['funnel_status'] : null,
                'shortcode'     => sprintf('[gh_form id="%d"]', (int) $row['ID']),
            ],
            $rows
        );
    }
}
