<?php

namespace WPMCP\Integrations;

use WPMCP\MCP\Opt_In_Required;
use WPMCP\Safety\Post_Creation_Snapshot;
use WPMCP\Safety\Save_Filters;
use WPMCP\Safety\Snapshot;
use WPMCP\Safety\Snapshot_Store;
use WPMCP\Tools\Code\Php_Snippet_Guard;
use WPMCP\Tools\Code\Php_Snippet_Store;
use WPMCP\Tools\Code\Php_Snippet_Validator;
use WPMCP\Tools\CustomCode\Custom_Js_Guard;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * WPCode (Insert Headers and Footers, issue #697) on the plugin-data pair,
 * next to Code Snippets, so it adds no top-level tools. Paid tier.
 *
 * WPCode 2.x keeps each snippet as a 'wpcode' post: the code in
 * post_content, active as post_status publish (inactive is draft), the code
 * type, auto-insert location and tags as terms of wpcode_type,
 * wpcode_location and wpcode_tags, and its settings in _wpcode_* post meta.
 * The global header, body and footer scripts are the ihaf_insert_header,
 * ihaf_insert_body and ihaf_insert_footer options. Reads come straight from
 * those; every snippet write goes through WPCode's own WPCode_Snippet
 * save(), so its capability checks, hooks and cache of active snippets
 * follow.
 *
 * The safety posture is the plugin's own stored snippets' (issue #85) and
 * the Code Snippets ops' (issue #576):
 *  - every write is off until the site opts in (wpmcp_enable_wpcode_write);
 *  - create-snippet stores a snippet INACTIVE; PHP code failing
 *    Php_Snippet_Validator (advisory, not a security boundary) is refused
 *    before anything is written, and HTML, CSS, JS and text code needs
 *    unfiltered_html;
 *  - update-snippet changes inactive snippets only (an active one is
 *    refused: deactivate it first), with the same code checks;
 *  - activate-snippet on a PHP snippet clears the gates activate-php-snippet
 *    clears (PHP execution enabled, an environment that permits it, the
 *    stored code re-checked, every attempt audited). WPCode itself test-runs
 *    PHP code once while activating it and refuses code that errors. An
 *    HTML, CSS, JS or text snippet prints raw code into pages, so activating
 *    one needs the custom code injection gate (wpmcp_allow_js_injection)
 *    and unfiltered_html, as does set-header-footer;
 *  - universal, blocks and SCSS snippets are read and can be deactivated,
 *    but are not created, changed or activated here.
 *
 * Every snippet write is a post snapshot (a create is a post_create row,
 * whose undo trashes the snippet), set-header-footer an option_set snapshot
 * of the three options. An undo never leaves a WPCode snippet active
 * (after_post_restored): activation always goes back through the gated op.
 * WPCode's cache of active snippets is rebuilt after every write and undo.
 *
 * Presence is filterable (wpmcp_wpcode_active, default: WPCODE_VERSION is
 * defined); while WPCode is not loaded the ops stay documented in
 * list-operations (dependency_met:false) and answer wpcode_inactive.
 */
final class WPCode_Pack
{
    // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- refusals are JSON tool errors surfaced by Integration_Dispatcher, never rendered as HTML.

    public const POST_TYPE = 'wpcode';

    public const OPT_IN_FILTER = 'wpmcp_enable_wpcode_write';

    /** The name the governance trail records PHP activation attempts under. */
    public const AUDIT_NAME = 'wpmcp/plugin-data-write:wpcode-activate-snippet';

    /** Code types these ops create, change and activate. */
    public const WRITABLE_TYPES = [ 'php', 'html', 'css', 'js', 'text' ];

    /** The global scripts: area => option. */
    public const HEADER_FOOTER = [
        'header' => 'ihaf_insert_header',
        'body'   => 'ihaf_insert_body',
        'footer' => 'ihaf_insert_footer',
    ];

    private const TYPE_TAX = 'wpcode_type';

    private const LOCATION_TAX = 'wpcode_location';

    private const TAGS_TAX = 'wpcode_tags';

    private const MAX_PER_PAGE = 100;

    private const MAX_CODE = 200000;

    /** Whether WPCode is loaded (filterable). */
    public static function active(): bool
    {
        return (bool) apply_filters('wpmcp_wpcode_active', defined('WPCODE_VERSION'));
    }

    /** @return true|array{code: string, message: string} */
    public static function requirement()
    {
        if (! self::active()) {
            return Plugin_Refusal::make('wpcode_inactive', 'WPCode');
        }
        if (! function_exists('wpcode') || ! class_exists('WPCode_Snippet')) {
            return [ 'code' => 'wpcode_unsupported', 'message' => 'This WPCode version lacks the API these operations use (WPCode 2.0 or newer).' ];
        }
        return true;
    }

    /** @return array<string, array<string, mixed>> */
    public static function operations(): array
    {
        $base  = [
            'capability' => 'manage_options',
            'tier'       => 'pro',
            'requires'   => static fn () => self::requirement(),
        ];
        $write = $base + [
            'mode'               => 'write',
            'enabled_by_default' => (bool) apply_filters('wpmcp_enable_wpcode_write', false),
            'opt_in_filter'      => self::OPT_IN_FILTER,
        ];
        $id    = [ 'type' => 'integer', 'minimum' => 1 ];
        $one   = [ 'type' => 'object', 'properties' => [ 'id' => $id ], 'required' => [ 'id' ] ];
        $obj   = static fn (string $access): array => [ 'id' => [ 'type' => 'post', 'own_type' => true, 'access' => $access ] ];
        $post  = static fn (array $args): array => [ 'object_type' => 'post', 'object_id' => (int) $args['id'] ];
        $edit  = [
            'title'       => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200 ],
            'code'        => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_CODE ],
            'location'    => [ 'type' => 'string', 'pattern' => '^[a-z0-9_]{1,64}$' ],
            'auto_insert' => [ 'type' => 'boolean' ],
            'priority'    => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 32767 ],
            'tags'        => [ 'type' => 'array', 'maxItems' => 50, 'items' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 100 ] ],
            'note'        => [ 'type' => 'string', 'maxLength' => 5000 ],
        ];

        return [
            'wpcode-list-snippets'      => $base + [
                'mode'         => 'read',
                'description'  => 'List WPCode snippets (id, title, code_type, location, status, auto_insert, priority, tags, shortcode; no code). Filter by code_type, status, location, tag, search; page, per_page (max 100)',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'code_type' => [ 'type' => 'string', 'pattern' => '^[a-z0-9_-]{1,20}$' ],
                        'status'    => [ 'type' => 'string', 'enum' => [ 'active', 'inactive', 'trashed' ] ],
                        'location'  => [ 'type' => 'string', 'pattern' => '^[a-z0-9_]{1,64}$' ],
                        'tag'       => [ 'type' => 'string', 'maxLength' => 100 ],
                        'search'    => [ 'type' => 'string', 'maxLength' => 200 ],
                        'page'      => [ 'type' => 'integer', 'minimum' => 1 ],
                        'per_page'  => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE ],
                    ],
                ],
                'validate'     => static fn (): ?array => self::cap_refusal('wpcode_edit_snippets'),
                'handler'      => static fn (array $args): array => self::list_snippets($args),
            ],
            'wpcode-get-snippet'        => $base + [
                'mode'         => 'read',
                'objects'      => $obj('read'),
                'description'  => 'Read one WPCode snippet with its code, settings and last error; PHP snippets add a static check report (advisory)',
                'input_schema' => $one,
                'validate'     => static fn (array $args): ?array => self::cap_refusal('wpcode_edit_snippets') ?? self::missing_refusal((int) $args['id']),
                'handler'      => static fn (array $args): array => self::get((int) $args['id']),
            ],
            'wpcode-get-header-footer'  => $base + [
                'mode'         => 'read',
                'description'  => 'Read the WPCode global header, body and footer scripts (Code Snippets > Header & Footer)',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
                'validate'     => static fn (): ?array => self::cap_refusal('wpcode_edit_snippets'),
                'handler'      => static fn (): array => [ 'header_footer' => self::header_footer() ],
            ],
            'wpcode-create-snippet'     => $write + [
                'self_snapshotting' => true,
                'description'       => 'Create a WPCode snippet, always inactive: title, code, code_type (php, html, css, js, text); location (WPCode auto-insert slug), auto_insert, priority, tags, note. PHP failing the static check is refused; other types need unfiltered_html. Undo: rollback-session trashes it',
                'input_schema'      => [
                    'type'                 => 'object',
                    'properties'           => [ 'code_type' => [ 'type' => 'string', 'enum' => self::WRITABLE_TYPES ] ] + $edit,
                    'required'             => [ 'title', 'code', 'code_type' ],
                    'additionalProperties' => false,
                ],
                'validate'          => static fn (array $args): ?array => self::create_refusal($args),
                'handler'           => static fn (array $args, array $context): array => self::create($args, $context),
            ],
            'wpcode-update-snippet'     => $write + [
                'objects'      => $obj('write'),
                'description'  => 'Change an inactive WPCode snippet: title, code, location, auto_insert, priority, tags, note (only the args given). Active snippets are refused (deactivate first); new PHP code must pass the static check. Undo: rollback-operation',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [ 'id' => $id ] + $edit,
                    'required'             => [ 'id' ],
                    'minProperties'        => 2,
                    'additionalProperties' => false,
                ],
                'validate'     => static fn (array $args): ?array => self::update_refusal($args),
                'snapshot'     => $post,
                'handler'      => static fn (array $args, array $context): array => self::update($args, (string) ($context['operation_id'] ?? '')),
            ],
            'wpcode-activate-snippet'   => $write + [
                'objects'      => $obj('write'),
                'description'  => 'Activate a WPCode snippet. PHP needs PHP execution enabled (WPMCP_ALLOW_PHP_EXEC, default off) and a permitting environment, the stored code is re-checked and WPCode test-runs it; every attempt audited. HTML, CSS, JS and text need wpmcp_allow_js_injection and unfiltered_html. Undo: rollback-operation deactivates',
                'input_schema' => $one,
                'validate'     => static fn (array $args): ?array => self::activate_refusal((int) $args['id']),
                'snapshot'     => $post,
                'handler'      => static fn (array $args, array $context): array => self::activate((int) $args['id'], (string) ($context['operation_id'] ?? '')),
            ],
            'wpcode-deactivate-snippet' => $write + [
                'objects'      => $obj('write'),
                'description'  => 'Deactivate a WPCode snippet of any type. Undo: rollback-operation, which leaves it inactive (activate again through activate-snippet)',
                'input_schema' => $one,
                'validate'     => static fn (array $args): ?array => self::cap_refusal('wpcode_activate_snippets') ?? self::missing_refusal((int) $args['id']) ?? self::state_refusal((int) $args['id'], true),
                'snapshot'     => $post,
                'handler'      => static fn (array $args, array $context): array => self::deactivate((int) $args['id'], (string) ($context['operation_id'] ?? '')),
            ],
            'wpcode-set-header-footer'  => $write + [
                'description'  => 'Replace the WPCode global header, body or footer script (only the areas given; "" clears one). Prints raw code on every page: needs wpmcp_allow_js_injection and unfiltered_html. Undo: rollback-operation',
                'input_schema' => [
                    'type'                 => 'object',
                    'properties'           => [
                        'header' => [ 'type' => 'string', 'maxLength' => self::MAX_CODE ],
                        'body'   => [ 'type' => 'string', 'maxLength' => self::MAX_CODE ],
                        'footer' => [ 'type' => 'string', 'maxLength' => self::MAX_CODE ],
                    ],
                    'minProperties'        => 1,
                    'additionalProperties' => false,
                ],
                'validate'     => static fn (): ?array => self::header_footer_refusal(),
                'snapshot'     => static fn (): array => [ 'object_type' => 'option_set', 'object_id' => Snapshot::option_set_id(array_values(self::HEADER_FOOTER)) ],
                'handler'      => static fn (array $args): array => self::set_header_footer($args),
            ],
        ];
    }

    // -----------------------------------------------------------------
    // Reads
    // -----------------------------------------------------------------

    private static function list_snippets(array $args): array
    {
        $statuses = [ 'active' => 'publish', 'inactive' => 'draft', 'trashed' => 'trash' ];
        $per      = (int) ($args['per_page'] ?? 50);
        $page     = (int) ($args['page'] ?? 1);
        $tax      = [];
        foreach ([ 'code_type' => self::TYPE_TAX, 'location' => self::LOCATION_TAX, 'tag' => self::TAGS_TAX ] as $key => $taxonomy) {
            if (isset($args[ $key ])) {
                $tax[] = [ 'taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => [ sanitize_title((string) $args[ $key ]) ] ];
            }
        }
        $query_args = [
            'post_type'        => self::POST_TYPE,
            'post_status'      => isset($args['status']) ? $statuses[ (string) $args['status'] ] : [ 'publish', 'draft' ],
            'posts_per_page'   => $per,
            'paged'            => $page,
            'orderby'          => 'ID',
            'order'            => 'ASC',
        ];
        if ([] !== $tax) {
            $query_args['tax_query'] = $tax; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- the filters are WPCode's own taxonomies.
        }
        if (isset($args['search'])) {
            $query_args['s'] = (string) $args['search'];
        }
        $query = new \WP_Query($query_args);
        $rows  = [];
        foreach ($query->posts as $post) {
            $rows[] = self::view($post);
        }
        return [
            'snippets' => $rows,
            'total'    => (int) $query->found_posts,
            'page'     => $page,
            'per_page' => $per,
        ];
    }

    private static function get(int $id): array
    {
        $post  = self::post($id);
        $error = get_post_meta($id, '_wpcode_last_error', true);
        $out   = [
            'snippet' => self::view($post) + [
                'code'                      => (string) $post->post_content,
                'note'                      => (string) get_post_meta($id, '_wpcode_note', true),
                'insert_number'             => (int) get_post_meta($id, '_wpcode_auto_insert_number', true),
                'conditional_logic_enabled' => (bool) get_post_meta($id, '_wpcode_conditional_logic_enabled', true),
                'last_error'                => is_array($error) && isset($error['message']) ? (string) $error['message'] : null,
            ],
        ];
        if ('php' === self::code_type($id)) {
            $out['validation'] = Php_Snippet_Validator::validate((string) $post->post_content);
        }
        return $out;
    }

    /** A snippet as listed: no code. */
    public static function view(\WP_Post $post): array
    {
        $id       = (int) $post->ID;
        $status   = [ 'publish' => 'active', 'trash' => 'trashed' ][ $post->post_status ] ?? 'inactive';
        $priority = get_post_meta($id, '_wpcode_priority', true);
        return [
            'id'          => $id,
            'title'       => (string) $post->post_title,
            'code_type'   => self::code_type($id),
            'location'    => self::single_term($id, self::LOCATION_TAX),
            'status'      => $status,
            'auto_insert' => 1 === (int) get_post_meta($id, '_wpcode_auto_insert', true),
            'priority'    => '' === $priority ? 10 : (int) $priority,
            'tags'        => self::term_slugs($id, self::TAGS_TAX),
            'shortcode'   => sprintf('[wpcode id="%d"]', $id),
            'modified'    => (string) $post->post_modified_gmt,
        ];
    }

    /** @return array<string, string> */
    private static function header_footer(): array
    {
        $out = [];
        foreach (self::HEADER_FOOTER as $area => $option) {
            $out[ $area ] = (string) get_option($option, '');
        }
        return $out;
    }

    // -----------------------------------------------------------------
    // Writes
    // -----------------------------------------------------------------

    /** @return array<string, mixed> */
    private static function create(array $args, array $context): array
    {
        $type = (string) $args['code_type'];
        $data = [
            // save() hands these to wp_insert_post() and update_post_meta(),
            // which unslash them, so they go in slashed as WPCode's own
            // importers pass them.
            'title'       => wp_slash(sanitize_text_field((string) $args['title'])),
            'code'        => wp_slash((string) $args['code']),
            'code_type'   => $type,
            'auto_insert' => array_key_exists('auto_insert', $args) ? (int) (bool) $args['auto_insert'] : 1,
            'location'    => (string) ($args['location'] ?? ('php' === $type ? 'everywhere' : 'site_wide_header')),
            'priority'    => (int) ($args['priority'] ?? 10),
            'tags'        => self::clean_tags((array) ($args['tags'] ?? [])),
            'note'        => wp_slash(sanitize_textarea_field((string) ($args['note'] ?? ''))),
            'active'      => false,
        ];
        $snippet = new \WPCode_Snippet($data);
        $id      = (int) $snippet->save();
        if ($id <= 0 || ! self::is_snippet(get_post($id))) {
            throw new Operation_Refused('write_failed', 'WPCode did not save the snippet.' . self::wpcode_error());
        }
        $op = Post_Creation_Snapshot::record((string) $context['tool_name'], [ $id ], [ 'operation' => $context['operation'], 'args' => array_diff_key($args, [ 'code' => true ]) ], (string) $context['session_id']);
        clean_post_cache($id);

        return [ 'result' => self::written($id), 'operation_ids' => [ $op ] ];
    }

    private static function update(array $args, string $operation): array
    {
        $id   = (int) $args['id'];
        $post = self::post($id);
        // Re-checked at write time: WPCode would run new code of an active
        // snippet on the next request, and one may have been switched on
        // since validate().
        if ('publish' === $post->post_status) {
            self::void($operation);
            throw new Operation_Refused('snippet_active', 'The snippet was activated meanwhile; deactivate it before changing it.');
        }
        $data = [ 'id' => $id ] + self::kept_flags($id);
        if (isset($args['title'])) {
            $data['title'] = wp_slash(sanitize_text_field((string) $args['title']));
        }
        if (isset($args['code'])) {
            $data['code'] = wp_slash((string) $args['code']);
        }
        if (isset($args['priority'])) {
            $data['priority'] = (int) $args['priority'];
        }
        if (isset($args['tags'])) {
            $data['tags'] = self::clean_tags((array) $args['tags']);
        }
        if (isset($args['note'])) {
            $data['note'] = wp_slash(sanitize_textarea_field((string) $args['note']));
        }
        if (isset($args['location']) || isset($args['auto_insert'])) {
            // WPCode stores a location only alongside auto_insert = 1.
            $data['auto_insert'] = isset($args['auto_insert']) ? (int) (bool) $args['auto_insert'] : (int) get_post_meta($id, '_wpcode_auto_insert', true);
            $data['location']    = (string) ($args['location'] ?? self::single_term($id, self::LOCATION_TAX));
        }
        $saved = (new \WPCode_Snippet($data))->save();
        if ((int) $saved !== $id) {
            self::void($operation);
            throw new Operation_Refused('write_failed', 'WPCode did not save the snippet.' . self::wpcode_error());
        }
        return self::written($id);
    }

    private static function activate(int $id, string $operation): array
    {
        $post    = self::post($id);
        $is_php  = 'php' === self::code_type($id);
        $code    = (string) $post->post_content;
        $written = false;
        try {
            if ('publish' === $post->post_status || ($is_php && null !== self::code_refusal($code, 'activate the snippet'))) {
                throw new Operation_Refused('code_moved', 'The snippet changed after it was checked; read it again and activate again.');
            }
            // WPCode test-runs PHP code here and leaves the snippet inactive
            // when the run reports an error.
            $written = true;
            $saved   = (new \WPCode_Snippet([ 'id' => $id, 'active' => true ] + self::kept_flags($id)))->save();
            clean_post_cache($id);
            $after = self::post($id);
            if ((int) $saved !== $id || 'publish' !== $after->post_status) {
                throw new Operation_Refused('activation_failed', 'WPCode did not activate the snippet.' . self::wpcode_error());
            }
            if (! hash_equals(hash('sha256', $code), hash('sha256', (string) $after->post_content))) {
                (new \WPCode_Snippet([ 'id' => $id, 'active' => false ] + self::kept_flags($id)))->save();
                throw new Operation_Refused('code_moved', 'The snippet\'s code changed while it was being activated; it was switched off again.');
            }
        } catch (\Throwable $e) {
            // Nothing written: the undo point is voided. Written: it stays,
            // so the recorded change can still be undone.
            if (! $written) {
                self::void($operation);
            }
            if ($is_php) {
                Php_Snippet_Guard::audit(self::AUDIT_NAME, false, $e instanceof Operation_Refused ? $e->error_code() : 'error');
            }
            throw $e;
        }
        if ($is_php) {
            Php_Snippet_Guard::audit(self::AUDIT_NAME, true);
        }
        return self::written($id);
    }

    private static function deactivate(int $id, string $operation): array
    {
        $saved = (new \WPCode_Snippet([ 'id' => $id, 'active' => false ] + self::kept_flags($id)))->save();
        clean_post_cache($id);
        if ((int) $saved !== $id || 'publish' === get_post_status($id)) {
            self::void($operation);
            throw new Operation_Refused('write_failed', 'WPCode did not deactivate the snippet.' . self::wpcode_error());
        }
        return self::written($id);
    }

    private static function set_header_footer(array $args): array
    {
        // Named one by one, as WPCode's own Header & Footer screen saves them.
        if (array_key_exists('header', $args)) {
            update_option('ihaf_insert_header', (string) $args['header']);
        }
        if (array_key_exists('body', $args)) {
            update_option('ihaf_insert_body', (string) $args['body']);
        }
        if (array_key_exists('footer', $args)) {
            update_option('ihaf_insert_footer', (string) $args['footer']);
        }
        self::clear_page_cache();
        return [ 'header_footer' => self::header_footer() ];
    }

    /** The snippet as written, read back fresh. */
    private static function written(int $id): array
    {
        clean_post_cache($id);
        $out = [ 'snippet' => self::view(self::post($id)) ];
        if ('php' === self::code_type($id)) {
            $out['validation'] = Php_Snippet_Validator::validate((string) get_post_field('post_content', $id));
        }
        return $out;
    }

    /**
     * Settings WPCode's save() drops unless they are passed again: it deletes
     * _wpcode_compress_output whenever the save does not carry it.
     */
    private static function kept_flags(int $id): array
    {
        return get_post_meta($id, '_wpcode_compress_output', true) ? [ 'compress_output' => true ] : [];
    }

    // -----------------------------------------------------------------
    // Rollback
    // -----------------------------------------------------------------

    /**
     * After a rollback restored a post (wpmcp_rollback_post_restored): a
     * WPCode snippet never comes back active, since an undo does not pass
     * the execution and injection gates, and WPCode's cache of active
     * snippets is rebuilt from what is now stored.
     */
    public static function after_post_restored(int $post_id): void
    {
        if (self::POST_TYPE !== get_post_type($post_id)) {
            return;
        }
        if ('publish' === get_post_status($post_id)) {
            Save_Filters::set_post_status($post_id, 'draft');
            clean_post_cache($post_id);
        }
        self::rebuild_cache();
    }

    /** After a snippet was trashed (the undo of a create): drop it from WPCode's cache. */
    public static function after_trash(int $post_id): void
    {
        if (self::POST_TYPE === get_post_type($post_id)) {
            self::rebuild_cache();
        }
    }

    /** After an undo of set-header-footer put the scripts back: clear page caches as WPCode's own save does. */
    public static function after_options_restored(array $names): void
    {
        if ([] !== array_intersect($names, array_values(self::HEADER_FOOTER))) {
            self::clear_page_cache();
        }
    }

    private static function rebuild_cache(): void
    {
        if (! function_exists('wpcode')) {
            return;
        }
        $wpcode = wpcode();
        if (isset($wpcode->cache) && is_object($wpcode->cache) && method_exists($wpcode->cache, 'cache_all_loaded_snippets')) {
            $wpcode->cache->cache_all_loaded_snippets();
        }
    }

    private static function clear_page_cache(): void
    {
        if (function_exists('wpcode_clear_all_plugins_page_cache')) {
            wpcode_clear_all_plugins_page_cache('global');
        }
    }

    // -----------------------------------------------------------------
    // Checks
    // -----------------------------------------------------------------

    private static function refusal(string $code, string $message, array $data = []): array
    {
        return [ 'code' => $code, 'message' => $message, 'data' => $data ];
    }

    /** Refusal when the caller lacks one of WPCode's own capabilities, or null. */
    public static function cap_refusal(string $cap): ?array
    {
        return current_user_can($cap) ? null : self::refusal('operation_denied', sprintf('This needs WPCode\'s %s capability (WPCode grants it to administrators).', $cap), [ 'reason' => 'capability', 'capability' => $cap ]);
    }

    private static function is_snippet($post): bool
    {
        return $post instanceof \WP_Post && self::POST_TYPE === $post->post_type;
    }

    private static function post(int $id): \WP_Post
    {
        $post = get_post($id);
        if (! self::is_snippet($post)) {
            throw new Operation_Refused('snippet_not_found', sprintf('No WPCode snippet with id %d.', $id), [ 'id' => $id ]);
        }
        return $post;
    }

    public static function missing_refusal(int $id): ?array
    {
        return self::is_snippet(get_post($id)) ? null : self::refusal('snippet_not_found', sprintf('No WPCode snippet with id %d.', $id), [ 'id' => $id ]);
    }

    /** Refusal for a trashed snippet, or one not active ($want_active) or already active, or null. */
    private static function state_refusal(int $id, bool $want_active): ?array
    {
        $status = (string) get_post_status($id);
        if ('trash' === $status) {
            return self::refusal('snippet_trashed', sprintf('Snippet %d is in the trash.', $id), [ 'id' => $id ]);
        }
        if ($want_active && 'publish' !== $status) {
            return self::refusal('snippet_inactive', sprintf('Snippet %d is not active.', $id), [ 'id' => $id ]);
        }
        if (! $want_active && 'publish' === $status) {
            return self::refusal('snippet_active', sprintf('Snippet %d is active; deactivate it first.', $id), [ 'id' => $id ]);
        }
        return null;
    }

    private static function type_refusal(int $id): ?array
    {
        $type = self::code_type($id);
        return in_array($type, self::WRITABLE_TYPES, true) ? null : self::refusal('unsupported_snippet_type', sprintf('Snippet %d has code type "%s"; only %s snippets are changed or activated here.', $id, $type, implode(', ', self::WRITABLE_TYPES)), [ 'id' => $id, 'code_type' => $type ]);
    }

    /** Refusal when PHP code is too large or fails the static check, or null. */
    private static function code_refusal(string $code, string $what): ?array
    {
        if (strlen($code) > Php_Snippet_Store::max_code_bytes()) {
            return self::refusal('code_too_large', sprintf('Refusing to %s: the code is larger than %d bytes.', $what, Php_Snippet_Store::max_code_bytes()));
        }
        $validation = Php_Snippet_Validator::validate($code);
        if ($validation['syntax_valid'] && $validation['safe']) {
            return null;
        }
        return self::refusal('validation_failed', sprintf('Refusing to %s: the code does not pass static validation (advisory speed-bump, not a security boundary).', $what), [ 'validation' => $validation ]);
    }

    /** Refusal for code of this type, or null: PHP is checked, raw output needs unfiltered_html. */
    private static function content_refusal(string $type, ?string $code, string $what): ?array
    {
        if ('php' === $type) {
            return null === $code ? null : self::code_refusal($code, $what);
        }
        return current_user_can('unfiltered_html') ? null : self::refusal('operation_denied', sprintf('Refusing to %s: %s code is printed raw into pages, which needs the unfiltered_html capability.', $what, strtoupper($type)), [ 'reason' => 'capability', 'capability' => 'unfiltered_html' ]);
    }

    /** Refusal for a location WPCode does not offer, or null. */
    private static function location_refusal(?string $location): ?array
    {
        if (null === $location) {
            return null;
        }
        $known = self::locations();
        if ([] === $known || in_array($location, $known, true)) {
            return null;
        }
        return self::refusal('unknown_location', sprintf('WPCode has no auto-insert location "%s".', $location), [ 'location' => $location, 'locations' => $known ]);
    }

    /** @return string[] Every auto-insert location WPCode offers, or [] when it cannot say. */
    private static function locations(): array
    {
        $wpcode = function_exists('wpcode') ? wpcode() : null;
        if (! is_object($wpcode) || ! isset($wpcode->auto_insert) || ! is_object($wpcode->auto_insert) || ! method_exists($wpcode->auto_insert, 'get_types')) {
            return [];
        }
        $out = [];
        foreach ((array) $wpcode->auto_insert->get_types() as $type) {
            if (is_object($type) && method_exists($type, 'get_locations')) {
                $out = array_merge($out, array_map('strval', array_keys((array) $type->get_locations())));
            }
        }
        return array_values(array_unique($out));
    }

    private static function create_refusal(array $args): ?array
    {
        return self::cap_refusal('wpcode_edit_snippets')
            ?? self::location_refusal(isset($args['location']) ? (string) $args['location'] : null)
            ?? self::content_refusal((string) $args['code_type'], (string) $args['code'], 'create the snippet');
    }

    private static function update_refusal(array $args): ?array
    {
        $id = (int) $args['id'];
        return self::cap_refusal('wpcode_edit_snippets')
            ?? self::missing_refusal($id)
            ?? self::state_refusal($id, false)
            ?? self::type_refusal($id)
            ?? self::location_refusal(isset($args['location']) ? (string) $args['location'] : null)
            ?? self::content_refusal(self::code_type($id), isset($args['code']) ? (string) $args['code'] : null, 'update the snippet');
    }

    /** Every activation gate, in order; PHP attempts are audited. */
    private static function activate_refusal(int $id): ?array
    {
        $refusal = self::cap_refusal('wpcode_activate_snippets')
            ?? self::missing_refusal($id)
            ?? self::type_refusal($id)
            ?? self::state_refusal($id, false);
        if (null !== $refusal) {
            return $refusal;
        }
        if ('php' !== self::code_type($id)) {
            return self::injection_refusal('activate this snippet');
        }
        try {
            Php_Snippet_Guard::assert_execution_allowed();
        } catch (\RuntimeException $e) {
            Php_Snippet_Guard::audit(self::AUDIT_NAME, false, Php_Snippet_Guard::refusal_class($e));
            return self::refusal($e instanceof Opt_In_Required ? Opt_In_Required::CODE : 'php_execution_disabled', $e->getMessage());
        }
        $refusal = self::code_refusal((string) get_post_field('post_content', $id), 'activate the snippet');
        if (null !== $refusal) {
            Php_Snippet_Guard::audit(self::AUDIT_NAME, false, (string) $refusal['code']);
        }
        return $refusal;
    }

    /** Refusal unless raw code may be printed into pages, or null. */
    private static function injection_refusal(string $what): ?array
    {
        if (! Custom_Js_Guard::is_enabled()) {
            return self::refusal(Opt_In_Required::CODE, Opt_In_Required::message(sprintf('Custom code injection is disabled, so WP MCP will not %s.', $what), 'wpmcp_allow_js_injection', 'WPMCP_ALLOW_JS_INJECTION'), [ 'filter' => 'wpmcp_allow_js_injection' ]);
        }
        if (! Custom_Js_Guard::current_user_can_inject()) {
            return self::refusal('operation_denied', sprintf('Refusing to %s: printing raw code needs the unfiltered_html capability.', $what), [ 'reason' => 'capability', 'capability' => 'unfiltered_html' ]);
        }
        return null;
    }

    private static function header_footer_refusal(): ?array
    {
        $refusal = self::cap_refusal('wpcode_activate_snippets') ?? self::injection_refusal('change the global header and footer scripts');
        if (null === $refusal && function_exists('wpcode_current_user_can_edit_header_footer') && ! wpcode_current_user_can_edit_header_footer()) {
            return self::refusal('operation_denied', 'WPCode does not let you edit the global header and footer scripts.', [ 'reason' => 'capability' ]);
        }
        return $refusal;
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private static function code_type(int $id): string
    {
        return self::single_term($id, self::TYPE_TAX);
    }

    private static function single_term(int $id, string $taxonomy): string
    {
        $slugs = self::term_slugs($id, $taxonomy);
        return $slugs[0] ?? '';
    }

    /** @return string[] */
    private static function term_slugs(int $id, string $taxonomy): array
    {
        $terms = wp_get_object_terms($id, $taxonomy, [ 'fields' => 'slugs' ]);
        return is_array($terms) ? array_values(array_map('strval', $terms)) : [];
    }

    /** @return string[] */
    private static function clean_tags(array $tags): array
    {
        return array_values(array_filter(array_map(static fn ($t): string => sanitize_text_field((string) $t), $tags), 'strlen'));
    }

    /** WPCode's last reported error, as a sentence to append, or ''. */
    private static function wpcode_error(): string
    {
        $wpcode = function_exists('wpcode') ? wpcode() : null;
        if (is_object($wpcode) && isset($wpcode->error) && is_object($wpcode->error) && method_exists($wpcode->error, 'get_last_error_message')) {
            $message = trim(wp_strip_all_tags((string) $wpcode->error->get_last_error_message()));
            return '' === $message ? '' : ' WPCode said: ' . $message;
        }
        return '';
    }

    /** Void this operation's undo point: the write it guarded did not happen. */
    private static function void(string $operation): void
    {
        if ('' !== $operation) {
            Snapshot_Store::delete_operation($operation);
        }
    }
}
